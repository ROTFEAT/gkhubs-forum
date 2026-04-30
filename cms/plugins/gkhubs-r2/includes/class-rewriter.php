<?php
/**
 * URL 改写：guid / source_url / srcset / 文章正文。
 */

namespace GKHubs\R2;

defined('ABSPATH') || exit;

class Rewriter {
    public function register(): void {
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 99, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 99);
        add_filter('the_content', [$this, 'rewrite_content'], 99);
    }

    /** 把本地 wp-content/uploads/... URL 替换为 public R2 URL */
    private function localize_to_r2(string $url): string {
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'] ?? '';
        if (!$base_url) return $url;

        // 处理 http vs https 与是否有 base_url 前缀
        $variants = [$base_url, str_replace('https://', 'http://', $base_url), str_replace('http://', 'https://', $base_url)];
        foreach ($variants as $b) {
            if ($b && strpos($url, $b) === 0) {
                $relpath = ltrim(substr($url, strlen($b)), '/');
                $cfg = Settings::get();
                $prefix = trim($cfg['prefix'] ?? '', '/');
                $key = $prefix === '' ? $relpath : $prefix . '/' . $relpath;
                return Uploader::public_url_for($key);
            }
        }
        return $url;
    }

    public function rewrite_attachment_url($url, $post_id) {
        if (!$url) return $url;
        $key = get_post_meta($post_id, GKHUBS_R2_META_KEY, true);
        if ($key) {
            return Uploader::public_url_for($key);
        }
        // 没有 meta 也尝试按本地路径换算（覆盖未迁移的旧附件，前提是已经在 R2）
        return $this->localize_to_r2($url);
    }

    public function rewrite_srcset($sources) {
        if (!is_array($sources)) return $sources;
        foreach ($sources as &$s) {
            if (!empty($s['url'])) {
                $s['url'] = $this->localize_to_r2($s['url']);
            }
        }
        return $sources;
    }

    public function rewrite_content($content) {
        $cfg = Settings::get();
        if (empty($cfg['rewrite_content'])) return $content;
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'] ?? '';
        if (!$base_url) return $content;
        $public = $cfg['public_url'] ?: ($cfg['endpoint'] . '/' . $cfg['bucket']);
        $public = rtrim($public, '/');
        $prefix = trim($cfg['prefix'] ?? '', '/');
        $public_with_prefix = $prefix === '' ? $public : $public . '/' . $prefix;
        return str_replace($base_url, $public_with_prefix, $content);
    }
}
