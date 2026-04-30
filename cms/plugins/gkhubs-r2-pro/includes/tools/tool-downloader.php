<?php
namespace GKHubs\R2\Pro;
defined('ABSPATH') || exit;

class Tool_Downloader extends Background_Tool {
    protected $tool_key = 'downloader';
    public function get_title_text(): string { return '从 R2 拉回本地'; }
    public function get_button_text(): string { return '启动下载'; }
    public function get_description(): string {
        return 'GET R2 对象写到本地 wp-content/uploads/ 对应路径（不动 _gkhubs_r2_key meta）。';
    }
    protected function get_background_process_class(): string { return Downloader_Process::class; }

    protected function create_session(): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            GKHUBS_R2_META_KEY
        ));
        return array_map('intval', $ids);
    }
}

class Downloader_Process extends Background_Process {
    protected $action = 'downloader_process';

    protected function task($item) {
        $id = (int) $item;
        $key = get_post_meta($id, GKHUBS_R2_META_KEY, true);
        if (!$key) return false;
        $client = new Client(Settings::get());
        if (!$client->ready()) return $item;
        $local_main = get_attached_file($id);
        if (!$local_main) return false;
        wp_mkdir_p(dirname($local_main));
        $r = $client->_raw_get_for_downloader($key);
        if (!$r['ok']) {
            error_log('[gkhubs-r2-pro] downloader GET failed #' . $id . ': ' . ($r['error'] ?? '?'));
            return $item;
        }
        file_put_contents($local_main, $r['body']);

        $meta = wp_get_attachment_metadata($id);
        if (!empty($meta['sizes'])) {
            $dir = trailingslashit(dirname($local_main));
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $local_size = $dir . $size['file'];
                $size_key = Uploader::r2_key_for($local_size);
                $sr = $client->_raw_get_for_downloader($size_key);
                if ($sr['ok']) file_put_contents($local_size, $sr['body']);
            }
        }
        update_post_meta($id, '_gkhubs_r2_downloaded_at', time());
        return false;
    }
    protected function cancelled(): void {}
    protected function paused(): void {}
    protected function resumed(): void {}
    protected function completed(): void {}
}
