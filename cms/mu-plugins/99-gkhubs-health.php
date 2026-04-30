<?php
/**
 * Plugin Name: gkhubs Health
 * Description: 含 WP + DB + plugin 检查的 REST 健康端点
 */
defined('ABSPATH') || exit;

// 允许 HTTP 下用 App Password（Dokploy 内网 + 流量经 Cloudflare/Traefik，TLS 在边缘终止）。
// Phase 5 接 Cloudflare HTTPS 后此 filter 仍无害。
add_filter('wp_is_application_passwords_available', '__return_true');

add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/health', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            global $wpdb;
            $db_ok = (bool) $wpdb->get_var('SELECT 1');
            $cpts_ok = post_type_exists('gk_figure') && post_type_exists('gk_listing');
            $r2_configured = (bool) (getenv('R2_BUCKET'));
            return [
                'ok' => $db_ok && $cpts_ok,
                'wp_version' => get_bloginfo('version'),
                'db' => $db_ok,
                'cpts' => $cpts_ok,
                'r2_configured' => $r2_configured,
            ];
        },
    ]);

    // 临时 debug：dump 当前 Media Cloud / ILAB 相关 option（不含 secret）
    register_rest_route('gkhubs/v1', '/debug-mcloud', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function () {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_name LIKE 'mcloud%'
                    OR option_name LIKE 'ilab%'
                    OR option_name LIKE '%media-tools%'
                    OR option_name LIKE 'storage%'
                 ORDER BY option_name",
                ARRAY_A
            );
            // 脱敏 secret/access-key
            foreach ($rows as &$r) {
                if (strpos($r['option_name'], 'secret') !== false
                    || strpos($r['option_name'], 'access') !== false) {
                    $v = $r['option_value'];
                    $r['option_value'] = strlen($v) > 8 ? substr($v, 0, 4) . '...(' . strlen($v) . ')' : '***';
                }
            }
            $plugins_active = get_option('active_plugins', []);
            return [
                'options' => $rows,
                'media_cloud_active' => in_array('ilab-media-tools/ilab-media-tools.php', $plugins_active),
                'all_active_plugins' => $plugins_active,
            ];
        },
    ]);
});
