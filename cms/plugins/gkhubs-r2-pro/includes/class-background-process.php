<?php
/**
 * Background_Process：基于 admin-ajax + WP options 队列 + transient 锁，仿 AS3CF_Background_Process。
 * 队列项存为 wp_options 行（key 形如 `{identifier}_batch_{md5}`）。
 * handle() 循环取 batch → 逐个 task → 时间/内存超限就停 → re-dispatch 自己。cron healthcheck 兜底。
 * 子类实现：task()、cancelled()、paused()、resumed()、completed()
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

abstract class Background_Process extends Async_Request {
    protected $action = 'background_process';
    protected $start_time = 0;

    const STATUS_CANCELLED = 1;
    const STATUS_PAUSED    = 2;

    public function __construct() {
        parent::__construct();
        add_action($this->identifier . '_cron', [$this, 'handle_cron_healthcheck']);
    }

    public function dispatch() {
        $this->schedule_cron_healthcheck();
        return parent::dispatch();
    }

    public function push_to_queue($item): self { $this->data[] = $item; return $this; }

    public function save(): self {
        $key = $this->generate_key('batch');
        if (!empty($this->data)) update_option($key, $this->data, false);
        $this->data = [];
        return $this;
    }

    public function update(string $key, array $data): self {
        if (!empty($data)) update_option($key, $data, false);
        return $this;
    }

    public function delete(string $key): self { delete_option($key); return $this; }

    public function delete_all(): void {
        foreach ($this->get_batches() as $batch) $this->delete($batch->key);
        delete_option($this->get_status_key());
        $this->cancelled();
        do_action($this->identifier . '_cancelled');
    }

    public function cancel(): void {
        update_option($this->get_status_key(), self::STATUS_CANCELLED, false);
        $this->dispatch();
    }
    public function is_cancelled(): bool {
        return absint(get_option($this->get_status_key(), 0)) === self::STATUS_CANCELLED;
    }
    public function pause(): void {
        update_option($this->get_status_key(), self::STATUS_PAUSED, false);
    }
    public function is_paused(): bool {
        return absint(get_option($this->get_status_key(), 0)) === self::STATUS_PAUSED;
    }
    public function resume(): void {
        delete_option($this->get_status_key());
        $this->schedule_cron_healthcheck();
        $this->dispatch();
        $this->resumed();
        do_action($this->identifier . '_resumed');
    }

    abstract protected function cancelled(): void;
    abstract protected function paused(): void;
    abstract protected function resumed(): void;
    abstract protected function completed(): void;

    protected function generate_key(string $key = '', int $length = 64): string {
        $unique = md5(microtime() . random_int(0, PHP_INT_MAX));
        return substr($this->identifier . '_' . $key . '_' . $unique, 0, $length);
    }

    protected function get_status_key(): string { return $this->identifier . '_status'; }

    public function maybe_handle(): void {
        session_write_close();
        if ($this->is_process_running()) wp_die();
        if ($this->is_cancelled()) {
            $this->clear_cron_healthcheck();
            $this->delete_all();
            wp_die();
        }
        if ($this->is_paused()) {
            $this->clear_cron_healthcheck();
            $this->paused();
            do_action($this->identifier . '_paused');
            wp_die();
        }
        if ($this->is_queue_empty()) wp_die();
        check_ajax_referer($this->identifier, 'nonce');
        $this->handle();
        wp_die();
    }

    protected function is_queue_empty(): bool {
        global $wpdb;
        $key = $this->identifier . '_batch_%';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $key
        )) === 0;
    }

    public function is_process_running(): bool {
        return (bool) get_transient($this->identifier . '_process_lock');
    }

    protected function lock_process(): void {
        $this->start_time = time();
        $lock = apply_filters($this->identifier . '_queue_lock_time', 60);
        set_transient($this->identifier . '_process_lock', microtime(), $lock);
    }
    protected function unlock_process(): self {
        delete_transient($this->identifier . '_process_lock');
        return $this;
    }

    public function get_batches(int $limit = 0): array {
        global $wpdb;
        $key = $this->identifier . '_batch_%';
        $sql = "SELECT * FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC";
        if ($limit > 0) $sql .= " LIMIT $limit";
        $items = $wpdb->get_results($wpdb->prepare($sql, $key));
        return array_map(function ($item) {
            $b = new \stdClass();
            $b->key = $item->option_name;
            $b->data = maybe_unserialize($item->option_value);
            return $b;
        }, $items ?: []);
    }

    protected function get_batch() {
        return array_reduce($this->get_batches(1), fn($c, $b) => $b, null);
    }

    protected function handle(): void {
        $this->lock_process();
        $throttle = apply_filters($this->identifier . '_seconds_between_batches', 0);
        do {
            $batch = $this->get_batch();
            if (!$batch) break;
            foreach ($batch->data as $key => $value) {
                if ($this->time_exceeded() || $this->memory_exceeded()) break;
                $this->update($batch->key, $batch->data);
                $task = $this->task($value);
                if ($task !== false) {
                    $batch->data[$key] = $task;
                } else {
                    unset($batch->data[$key]);
                }
                if ($throttle > 0) sleep((int) $throttle);
            }
            if (!empty($batch->data)) {
                $this->update($batch->key, $batch->data);
            } else {
                $this->delete($batch->key);
            }
        } while (!$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty());
        $this->unlock_process();
        if (!$this->is_queue_empty()) $this->dispatch();
        else $this->complete();
    }

    protected function memory_exceeded(): bool {
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit') ?: '256M');
        return $limit > 0 && memory_get_usage(true) > 0.9 * $limit;
    }
    protected function time_exceeded(): bool {
        return time() >= $this->start_time + apply_filters($this->identifier . '_default_time_limit', 20);
    }

    protected function complete(): void {
        delete_option($this->get_status_key());
        $this->clear_cron_healthcheck();
        $this->completed();
        do_action($this->identifier . '_completed');
    }

    protected function schedule_cron_healthcheck(): void {
        if (!wp_next_scheduled($this->identifier . '_cron')) {
            wp_schedule_event(time() + 60, 'gkhubs_r2_pro_minute', $this->identifier . '_cron');
        }
    }
    protected function clear_cron_healthcheck(): void {
        $ts = wp_next_scheduled($this->identifier . '_cron');
        if ($ts) wp_unschedule_event($ts, $this->identifier . '_cron');
    }
    public function handle_cron_healthcheck(): void {
        if ($this->is_process_running()) return;
        if ($this->is_queue_empty()) {
            $this->clear_cron_healthcheck();
            return;
        }
        $this->dispatch();
    }

    abstract protected function task($item);
}
