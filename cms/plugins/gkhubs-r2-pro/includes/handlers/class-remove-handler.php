<?php
/**
 * Handler: 删 attachment 时同步删 R2 对象（含子尺寸）。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class Remove_Handler {
    public function register(): void {
        add_action('delete_attachment', [$this, 'on_delete']);
    }
    public function on_delete($attachment_id): void {
        if (!License::is_active()) return;
        $client = new Client(Settings::get());
        if (!$client->ready()) return;
        $main_key = get_post_meta($attachment_id, GKHUBS_R2_META_KEY, true);
        if (!$main_key) return;
        $client->delete($main_key);
        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['sizes'])) return;
        $local_main = get_attached_file($attachment_id);
        if (!$local_main) return;
        $dir = trailingslashit(dirname($local_main));
        foreach ($meta['sizes'] as $size) {
            if (empty($size['file'])) continue;
            $key = Uploader::r2_key_for($dir . $size['file']);
            $client->delete($key);
        }
    }
}
