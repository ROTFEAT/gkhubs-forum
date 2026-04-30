<?php
/**
 * Tool 抽象基类（仿 AS3CF Tool）。
 * 子类必须定义 $tool_key、get_title_text()、get_button_text()。
 * Tools_Manager 通过 perform_action($key, $action) 路由到 handle_$action 方法。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

abstract class Tool {
    protected $prefix = 'gkhubs_r2_pro';
    protected $tab    = 'tools';
    protected $type   = 'tool';
    protected $tool_key;
    protected $tool_slug;
    protected $errors_key;

    public function __construct() {
        $this->tool_slug   = str_replace(['_', ' '], '-', $this->tool_key);
        $this->errors_key  = $this->prefix . '_tool_errors_' . $this->tool_key;
    }

    public function init(): void {}

    public function get_tool_key(): string { return $this->tool_key; }
    public function get_tab(): string { return $this->tab; }
    public function get_type(): string { return $this->type; }
    public function get_slug(): string { return $this->tool_slug; }

    public function should_render(): bool { return License::is_active(); }
    public function is_active(): bool { return false; }
    public function is_processing(): bool { return false; }
    public function is_queued(): bool { return false; }
    public function is_paused(): bool { return false; }
    public function is_cancelled(): bool { return false; }

    abstract public function get_title_text(): string;
    abstract public function get_button_text(): string;
    public function get_description(): string { return ''; }
    public function get_progress(): int { return 0; }
    public function get_queue_counts(): array { return ['total' => 0, 'processed' => 0]; }
    public function get_status_summary(): string {
        if ($this->is_cancelled()) return '正在停止…';
        if ($this->is_paused())    return '已暂停';
        if ($this->is_processing()) return '运行中';
        if ($this->is_queued())    return '已排队';
        return '就绪';
    }

    public function get_errors(): array { return (array) get_option($this->errors_key, []); }
    public function update_errors(array $errors): void { update_option($this->errors_key, $errors, false); }
    public function clear_errors(): void { delete_option($this->errors_key); }

    public function get_info(): array {
        return [
            'id' => $this->tool_key, 'tab' => $this->tab, 'type' => $this->type, 'slug' => $this->tool_slug,
            'render' => $this->should_render(), 'is_active' => $this->is_active(),
            'is_queued' => $this->is_queued(), 'is_paused' => $this->is_paused(),
            'is_cancelled' => $this->is_cancelled(), 'is_processing' => $this->is_processing(),
            'progress' => $this->get_progress(), 'queue' => $this->get_queue_counts(),
            'status' => $this->get_status_summary(),
            'title' => $this->get_title_text(), 'button' => $this->get_button_text(),
            'description' => $this->get_description(),
        ];
    }
}
