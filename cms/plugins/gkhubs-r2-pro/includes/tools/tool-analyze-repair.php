<?php
namespace GKHubs\R2\Pro;
defined('ABSPATH') || exit;

class Tool_Analyze_Repair extends Background_Tool {
    protected $tool_key = 'analyze_repair';
    public function get_title_text(): string { return '校验 + 修复 R2 状态'; }
    public function get_button_text(): string { return '启动校验'; }
    public function get_description(): string {
        return 'HEAD 每个 attachment 对应的 R2 对象。已标记但 R2 找不到的 → 清 meta，方便 bulk_migrate 重推。';
    }
    protected function get_background_process_class(): string { return Analyze_Repair_Process::class; }

    protected function create_session(): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            GKHUBS_R2_META_KEY
        ));
        return array_map('intval', $ids);
    }
}

class Analyze_Repair_Process extends Background_Process {
    protected $action = 'analyze_repair_process';

    protected function task($item) {
        $id = (int) $item;
        $key = get_post_meta($id, GKHUBS_R2_META_KEY, true);
        if (!$key) return false;
        $client = new Client(Settings::get());
        if (!$client->ready()) return $item;
        $r = $client->head($key);
        if (!$r['ok']) {
            delete_post_meta($id, GKHUBS_R2_META_KEY);
            update_post_meta($id, '_gkhubs_r2_repair_missing_at', time());
        } else {
            update_post_meta($id, '_gkhubs_r2_verified_at', time());
        }
        return false;
    }
    protected function cancelled(): void {}
    protected function paused(): void {}
    protected function resumed(): void {}
    protected function completed(): void {}
}
