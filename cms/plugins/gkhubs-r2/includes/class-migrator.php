<?php
/**
 * Migrator：把已存在的 attachment 批量推到 R2。
 * 通过 WP cron 每分钟跑一批；admin 也能手动触发一批。
 */

namespace GKHubs\R2;

defined('ABSPATH') || exit;

class Migrator {
    const CRON_HOOK = 'gkhubs_r2_migrate_batch';
    const BATCH_SIZE = 20;

    public function register(): void {
        add_action(self::CRON_HOOK, [$this, 'run_batch']);
        add_action('admin_post_gkhubs_r2_migrate_kick', [$this, 'kick']);
        add_action('admin_post_gkhubs_r2_migrate_now', [$this, 'run_now']);
    }

    public static function stats(): array {
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $migrated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            GKHUBS_R2_META_KEY
        ));
        return [
            'total'    => $total,
            'migrated' => $migrated,
            'pending'  => max(0, $total - $migrated),
        ];
    }

    /** 启动 cron：第一批立即跑，后续每分钟一批 */
    public function kick(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_migrate');
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'gkhubs_minute', self::CRON_HOOK);
        }
        set_transient('gkhubs_r2_migrate_msg', '已排程：每分钟一批，自动跑完为止', 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::PAGE_SLUG));
        exit;
    }

    /** 立即跑一批（测试） */
    public function run_now(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_migrate');
        $r = $this->run_batch();
        set_transient('gkhubs_r2_migrate_msg', sprintf('完成一批：成功 %d，失败 %d', $r['ok'], $r['fail']), 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::PAGE_SLUG));
        exit;
    }

    /** 每次跑一批；没剩了就反排程 */
    public function run_batch(): array {
        $client = new Client(Settings::get());
        if (!$client->ready()) return ['ok' => 0, 'fail' => 0];

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = %s
             WHERE p.post_type = 'attachment' AND m.meta_id IS NULL
             ORDER BY p.ID ASC LIMIT %d",
            GKHUBS_R2_META_KEY, self::BATCH_SIZE
        ));

        if (empty($ids)) {
            $ts = wp_next_scheduled(self::CRON_HOOK);
            if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
            return ['ok' => 0, 'fail' => 0];
        }

        $ok = 0; $fail = 0;
        foreach ($ids as $id) {
            $local = get_attached_file($id);
            if (!$local || !is_readable($local)) { $fail++; continue; }
            $mime = get_post_mime_type($id) ?: 'application/octet-stream';
            $key = Uploader::r2_key_for($local);
            $r = $client->put_file($key, $local, $mime);
            if (!$r['ok']) { $fail++; continue; }
            update_post_meta($id, GKHUBS_R2_META_KEY, $key);

            // 子尺寸
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
            $ok++;
        }
        return ['ok' => $ok, 'fail' => $fail];
    }
}

// 自定义 cron 间隔：1 分钟
add_filter('cron_schedules', function ($schedules) {
    $schedules['gkhubs_minute'] = ['interval' => 60, 'display' => 'Every Minute (gkhubs)'];
    return $schedules;
});
