<?php
namespace GKHubs\R2\Pro;
defined('ABSPATH') || exit;

class Tool_Bulk_Migrate extends Background_Tool {
    protected $tool_key = 'bulk_migrate';
    public function get_title_text(): string { return '批量迁移到 R2'; }
    public function get_button_text(): string { return '启动迁移'; }
    public function get_description(): string {
        return '把数据库里所有未迁移的 attachment（含子尺寸）推到 R2，并写入 _gkhubs_r2_key meta。';
    }
    protected function get_background_process_class(): string { return Bulk_Migrate_Process::class; }

    protected function create_session(): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND m.meta_id IS NULL
             ORDER BY p.ID ASC",
            GKHUBS_R2_META_KEY
        ));
        return array_map('intval', $ids);
    }

    public function get_status_summary(): string {
        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
        $done = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            GKHUBS_R2_META_KEY
        ));
        return parent::get_status_summary() . "（总 $total，已迁 $done，待迁 " . max(0, $total - $done) . "）";
    }
}

class Bulk_Migrate_Process extends Background_Process {
    protected $action = 'bulk_migrate_process';

    protected function task($item) {
        $id = (int) $item;
        if (!$id) return false;
        if (get_post_meta($id, GKHUBS_R2_META_KEY, true)) return false;
        $local = get_attached_file($id);
        if (!$local || !is_readable($local)) return false;

        $client = new Client(Settings::get());
        if (!$client->ready()) return $item;
        $mime = get_post_mime_type($id) ?: 'application/octet-stream';
        $key  = Uploader::r2_key_for($local);
        $r = $client->put_file($key, $local, $mime);
        if (!$r['ok']) {
            error_log('[gkhubs-r2-pro] migrate PUT failed #' . $id . ': ' . ($r['error'] ?? '?'));
            return $item;
        }
        update_post_meta($id, GKHUBS_R2_META_KEY, $key);

        $meta = wp_get_attachment_metadata($id);
        if (!empty($meta['sizes'])) {
            $dir = trailingslashit(dirname($local));
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $local_size = $dir . $size['file'];
                if (!is_readable($local_size)) continue;
                $key_size = Uploader::r2_key_for($local_size);
                $client->put_file($key_size, $local_size, $size['mime-type'] ?? $mime);
            }
        }
        return false;
    }

    protected function cancelled(): void {}
    protected function paused(): void {}
    protected function resumed(): void {}
    protected function completed(): void {}
}
