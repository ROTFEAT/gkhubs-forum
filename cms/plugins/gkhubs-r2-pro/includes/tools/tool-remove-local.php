<?php
namespace GKHubs\R2\Pro;
defined('ABSPATH') || exit;

class Tool_Remove_Local extends Background_Tool {
    protected $tool_key = 'remove_local';
    public function get_title_text(): string { return '删本地（已迁移）'; }
    public function get_button_text(): string { return '启动清理'; }
    public function get_description(): string {
        return '已成功迁到 R2 的 attachment 删除本地副本（含子尺寸）。';
    }
    protected function get_background_process_class(): string { return Remove_Local_Process::class; }

    protected function create_session(): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            GKHUBS_R2_META_KEY
        ));
        return array_map('intval', $ids);
    }
}

class Remove_Local_Process extends Background_Process {
    protected $action = 'remove_local_process';

    protected function task($item) {
        $id = (int) $item;
        if (!$id) return false;
        if (!get_post_meta($id, GKHUBS_R2_META_KEY, true)) return false;
        $local = get_attached_file($id);
        if ($local && file_exists($local)) @unlink($local);
        $meta = wp_get_attachment_metadata($id);
        if (!empty($meta['sizes']) && $local) {
            $dir = trailingslashit(dirname($local));
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $p = $dir . $size['file'];
                if (file_exists($p)) @unlink($p);
            }
        }
        update_post_meta($id, '_gkhubs_r2_local_deleted_at', time());
        return false;
    }
    protected function cancelled(): void {}
    protected function paused(): void {}
    protected function resumed(): void {}
    protected function completed(): void {}
}
