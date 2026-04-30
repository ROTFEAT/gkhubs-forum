<?php
/**
 * Uploader：拦 wp_handle_upload + 子尺寸生成，把文件 PUT 到 R2，
 * 在 attachment 上挂 R2 key meta，可选删本地。
 */

namespace GKHubs\R2;

defined('ABSPATH') || exit;

class Uploader {
    public function register(): void {
        add_filter('wp_handle_upload', [$this, 'on_upload'], 99);
        add_filter('wp_generate_attachment_metadata', [$this, 'on_thumbnails'], 99, 2);
        add_action('add_attachment', [$this, 'mark_attachment_key']);
        add_action('delete_attachment', [$this, 'on_delete']);
    }

    private function client(): Client {
        return new Client(Settings::get());
    }

    /** 计算 R2 key：<prefix>/<relpath-from-uploads-base> */
    public static function r2_key_for(string $local_path): string {
        $upload_dir = wp_upload_dir();
        $base = trailingslashit($upload_dir['basedir']);
        $rel = ltrim(str_replace($base, '', $local_path), '/');
        $cfg = Settings::get();
        $prefix = trim($cfg['prefix'] ?? '', '/');
        return $prefix === '' ? $rel : $prefix . '/' . $rel;
    }

    public function on_upload(array $upload): array {
        if (empty($upload['file']) || empty($upload['url'])) return $upload;
        $client = $this->client();
        if (!$client->ready()) return $upload;

        $key = self::r2_key_for($upload['file']);
        $r = $client->put_file($key, $upload['file'], $upload['type'] ?? 'application/octet-stream');
        if (!$r['ok']) {
            error_log('[gkhubs-r2] upload PUT failed: ' . ($r['error'] ?? '?') . ' status=' . ($r['status'] ?? '?'));
            return $upload;
        }

        // 改写返回 URL（如果设了 public_url）
        $upload['url'] = self::public_url_for($key);
        // 临时存一下，给 mark_attachment_key 用
        update_option('_gkhubs_r2_last_key', $key, false);

        $cfg = Settings::get();
        if (!empty($cfg['delete_local'])) {
            @unlink($upload['file']);
        }
        return $upload;
    }

    public function on_thumbnails($metadata, $attachment_id) {
        if (empty($metadata['sizes'])) return $metadata;
        $client = $this->client();
        if (!$client->ready()) return $metadata;

        $local_main = get_attached_file($attachment_id);
        if (!$local_main) return $metadata;
        $dir = trailingslashit(dirname($local_main));

        $cfg = Settings::get();
        foreach ($metadata['sizes'] as $size) {
            if (empty($size['file'])) continue;
            $local = $dir . $size['file'];
            if (!is_readable($local)) continue;
            $key = self::r2_key_for($local);
            $r = $client->put_file($key, $local, $size['mime-type'] ?? 'image/jpeg');
            if ($r['ok'] && !empty($cfg['delete_local'])) {
                @unlink($local);
            }
        }
        return $metadata;
    }

    public function mark_attachment_key($attachment_id): void {
        $key = get_option('_gkhubs_r2_last_key');
        if (!$key) return;
        update_post_meta($attachment_id, GKHUBS_R2_META_KEY, $key);
        delete_option('_gkhubs_r2_last_key');
    }

    /** attachment 删除时同步删 R2 对象（含子尺寸） */
    public function on_delete($attachment_id): void {
        $client = $this->client();
        if (!$client->ready()) return;

        $main_key = get_post_meta($attachment_id, GKHUBS_R2_META_KEY, true);
        if (!$main_key) return;
        $client->delete($main_key);

        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['sizes'])) return;
        $local_main = get_attached_file($attachment_id);
        $dir = trailingslashit(dirname($local_main));
        foreach ($meta['sizes'] as $size) {
            if (empty($size['file'])) continue;
            $key = self::r2_key_for($dir . $size['file']);
            $client->delete($key);
        }
    }

    /** 给定 R2 key，构造对外公网 URL */
    public static function public_url_for(string $key): string {
        $cfg = Settings::get();
        $base = $cfg['public_url'] ?: ($cfg['endpoint'] . '/' . $cfg['bucket']);
        return rtrim($base, '/') . '/' . ltrim($key, '/');
    }
}
