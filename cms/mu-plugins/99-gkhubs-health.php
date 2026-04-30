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

    // 临时 debug：列出 Media Cloud 支持的 driver 名 + StorageTool 状态
    register_rest_route('gkhubs/v1', '/debug-drivers', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => function () {
            $base = WP_PLUGIN_DIR . '/ilab-media-tools/classes/Tools/Storage/Driver';
            $driver_dirs = [];
            if (is_dir($base)) {
                foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
                    $driver_dirs[] = basename($dir);
                }
            }

            $info = ['driver_dirs' => $driver_dirs];

            // 直接读 CloudflareStorage.php 抓 driver name + option key 关键行
            $cfsrc = $base . '/Cloudflare/CloudflareStorage.php';
            if (file_exists($cfsrc)) {
                $src = file_get_contents($cfsrc);
                preg_match_all('/(public static function (?:identifier|name|defaultRegion|defaultEndpoint|optionsPrefix)\(\)[^{]*\{[^}]+\})/s', $src, $m);
                $info['cloudflare_static_methods'] = $m[1] ?? [];
                preg_match_all('/[\'"](mcloud-storage-[a-z0-9-]+)[\'"]/', $src, $m2);
                $info['cloudflare_referenced_options'] = array_values(array_unique($m2[1] ?? []));
                preg_match_all('/Environment::Option\([\'"]([a-zA-Z0-9_-]+)[\'"]/', $src, $m3);
                $info['cloudflare_env_options'] = array_values(array_unique($m3[1] ?? []));
            }
            $cfsetsrc = $base . '/Cloudflare/CloudflareStorageSettings.php';
            if (file_exists($cfsetsrc)) {
                $src = file_get_contents($cfsetsrc);
                preg_match_all('/[\'"](mcloud-storage-[a-z0-9-]+)[\'"]/', $src, $m);
                $info['cloudflareSettings_options'] = array_values(array_unique($m[1] ?? []));
                preg_match_all('/static \$preset[^=]*=[^;]+;/s', $src, $mp);
                $info['cloudflareSettings_preset_blob'] = $mp[0][0] ?? null;
            }

            // 读取 Cloudflare driver 配置元数据
            $cf_driver_dir = $base . '/Cloudflare';
            if (is_dir($cf_driver_dir)) {
                $files = [];
                foreach (glob($cf_driver_dir . '/*.php') as $f) {
                    $files[] = basename($f);
                }
                $info['cloudflare_files'] = $files;

                // 找 driver-info 配置文件
                foreach (glob($cf_driver_dir . '/configs/*.config.php') as $cfg) {
                    $info['cloudflare_config_file'] = basename($cfg);
                    $cfgData = include $cfg;
                    if (is_array($cfgData)) {
                        $info['cloudflare_config_keys'] = array_keys($cfgData);
                        if (isset($cfgData['name'])) $info['cloudflare_driver_name'] = $cfgData['name'];
                        if (isset($cfgData['settings']['groups'])) {
                            foreach ($cfgData['settings']['groups'] as $g) {
                                if (!empty($g['options'])) {
                                    $info['cloudflare_options'] = array_merge($info['cloudflare_options'] ?? [], array_keys($g['options']));
                                }
                            }
                        }
                    }
                }
            }

            // 尝试找 StorageManager 类
            $candidates = [
                '\\MediaCloud\\Plugin\\Tools\\Storage\\StorageManager',
                '\\ILAB\\MediaCloud\\Tools\\Storage\\StorageManager',
            ];
            foreach ($candidates as $cls) {
                if (class_exists($cls)) {
                    $info['manager_class'] = $cls;
                    try {
                        if (method_exists($cls, 'driverConfigs')) {
                            $info['driverConfigs_keys'] = array_keys($cls::driverConfigs());
                        }
                        if (method_exists($cls, 'drivers')) {
                            $info['drivers_keys'] = array_keys($cls::drivers());
                        }
                    } catch (Throwable $e) {
                        $info['manager_err'] = $e->getMessage();
                    }
                    break;
                }
            }

            // StorageTool 状态
            $tool_candidates = [
                '\\MediaCloud\\Plugin\\Tools\\Storage\\StorageTool',
                '\\ILAB\\MediaCloud\\Tools\\Storage\\StorageTool',
            ];
            foreach ($tool_candidates as $cls) {
                if (class_exists($cls)) {
                    $info['tool_class'] = $cls;
                    if (function_exists('mediacloud_storage_tool')) {
                        try {
                            $tool = mediacloud_storage_tool();
                            if ($tool && method_exists($tool, 'enabled')) {
                                $info['tool_enabled'] = $tool->enabled();
                            }
                            if ($tool && method_exists($tool, 'envEnabled')) {
                                $info['tool_envEnabled'] = $tool->envEnabled();
                            }
                            if ($tool && method_exists($tool, 'client') && $tool->client()) {
                                $info['client_class'] = get_class($tool->client());
                            }
                        } catch (Throwable $e) {
                            $info['tool_err'] = $e->getMessage();
                        }
                    } else {
                        $info['tool_helper_missing'] = true;
                    }
                    break;
                }
            }

            return $info;
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
