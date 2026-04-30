<?php
/**
 * Background_Tool: 用 Background_Process 做后台执行的 tool 基类（仿 AS3CF Background_Tool）。
 * 子类必须实现 get_background_process_class()、create_session()、get_title_text()、get_button_text()。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

abstract class Background_Tool extends Tool {
    protected $type = 'background-tool';
    /** @var Background_Process */
    protected $background_process;
    private $batch_cache;

    public function init(): void {
        parent::init();
        $cls = $this->get_background_process_class();
        $this->background_process = new $cls();
    }

    abstract protected function get_background_process_class(): string;
    abstract protected function create_session(): array;

    public function handle_start(): void {
        if ($this->is_queued()) return;
        $this->clear_errors();
        $items = $this->create_session();
        if (empty($items)) return;
        foreach ($items as $item) $this->background_process->push_to_queue($item);
        $this->background_process->save()->dispatch();
        do_action($this->prefix . '_' . $this->tool_key . '_started');
    }

    public function handle_cancel(): void {
        if (!$this->is_queued()) return;
        $this->background_process->cancel();
        $this->batch_cache = null;
    }

    public function handle_pause_resume(): void {
        if (!$this->is_queued() || $this->is_cancelled()) return;
        if ($this->is_paused()) $this->background_process->resume();
        else $this->background_process->pause();
    }

    public function is_queued(): bool { return !empty($this->get_first_batch()); }
    public function is_paused(): bool { return $this->background_process->is_paused(); }
    public function is_cancelled(): bool { return $this->background_process->is_cancelled(); }
    public function is_processing(): bool { return $this->background_process->is_process_running(); }
    public function is_active(): bool {
        return $this->is_queued() || $this->is_processing() || $this->is_paused() || $this->is_cancelled();
    }

    protected function get_first_batch() {
        if ($this->batch_cache === null) {
            $batches = $this->background_process->get_batches(1);
            $this->batch_cache = empty($batches) ? null : array_shift($batches);
        }
        return $this->batch_cache;
    }

    public function get_progress(): int {
        $batch = $this->get_first_batch();
        if (!$batch) return 0;
        $remaining = count((array) $batch->data);
        if ($remaining === 0) return 100;
        return 0; // 简化：没有 total 元数据时不算 progress
    }

    public function get_queue_counts(): array {
        $batch = $this->get_first_batch();
        if (!$batch) return ['total' => 0, 'processed' => 0];
        $remaining = count((array) $batch->data);
        return ['total' => $remaining, 'processed' => 0];
    }
}
