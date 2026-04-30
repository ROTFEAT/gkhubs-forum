<?php
/**
 * REST API: /wp-json/gkhubs-r2-pro/v1/{license,tools,test,state}
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class Rest_Api {
    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    public function register_routes(): void {
        register_rest_route('gkhubs-r2-pro/v1', '/license', [
            'methods' => 'GET',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => fn() => License::status(),
        ]);
        register_rest_route('gkhubs-r2-pro/v1', '/license/activate', [
            'methods' => 'POST',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => fn(\WP_REST_Request $r) => License::activate((string) $r->get_param('key')),
        ]);
        register_rest_route('gkhubs-r2-pro/v1', '/test', [
            'methods' => 'GET',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => fn() => (new Client(Settings::get()))->test_connection(),
        ]);
        register_rest_route('gkhubs-r2-pro/v1', '/tools', [
            'methods' => 'GET',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => fn() => Tools_Manager::get_instance()->get_tools_info(),
        ]);
        register_rest_route('gkhubs-r2-pro/v1', '/tools/(?P<id>[a-z_]+)/(?P<action>[a-z_]+)', [
            'methods' => 'POST',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => function (\WP_REST_Request $r) {
                if (!License::is_active()) return new \WP_Error('license_inactive', 'Pro 未激活', ['status' => 403]);
                return ['ok' => Tools_Manager::get_instance()->perform_action($r->get_param('id'), $r->get_param('action'))];
            },
        ]);
        register_rest_route('gkhubs-r2-pro/v1', '/state', [
            'methods' => 'GET',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => function () {
                $running = Tools_Manager::get_instance()->get_running_tool();
                return [
                    'license' => License::status(),
                    'running_tool' => $running ? $running->get_info() : null,
                    'tools' => Tools_Manager::get_instance()->get_tools_info(),
                ];
            },
        ]);
    }
}
