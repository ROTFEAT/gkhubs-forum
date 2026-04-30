<?php
/**
 * Plugin Name: gkhubs Health
 * Description: 含 WP + DB + plugin 检查的 REST 健康端点
 */
defined('ABSPATH') || exit;

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
});
