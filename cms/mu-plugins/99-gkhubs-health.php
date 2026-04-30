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

    // 临时 debug：读插件入口文件 + autoload 状况
    register_rest_route('gkhubs/v1', '/debug-entry', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => function () {
            $entry = WP_PLUGIN_DIR . '/ilab-media-tools/ilab-media-tools.php';
            $body = file_exists($entry) ? file_get_contents($entry) : null;
            $vendor = WP_PLUGIN_DIR . '/ilab-media-tools/vendor';
            $vendor_exists = is_dir($vendor);
            $autoload = WP_PLUGIN_DIR . '/ilab-media-tools/vendor/autoload.php';
            $autoload_exists = file_exists($autoload);
            // 找入口里所有 require/include + version checks
            $matches = [];
            if ($body) {
                preg_match_all('/(require|include).*?(\'|").*?(\'|")/', $body, $req);
                $matches['requires'] = $req[0] ?? [];
                preg_match_all('/version_compare.*?[\'"][\d.]+[\'"]/', $body, $vc);
                $matches['version_checks'] = $vc[0] ?? [];
                preg_match_all('/define\([\'"](MEDIA_CLOUD|ILAB_TOOLS_DIR|ILAB_PUB_DIR)[\'"][^)]+\)/', $body, $def);
                $matches['defines'] = $def[0] ?? [];
                preg_match_all('/(if|return).{0,80}\bphp_sapi_name\(\)/', $body, $sapi);
                $matches['sapi_checks'] = $sapi[0] ?? [];
            }
            return [
                'entry_path' => $entry,
                'entry_size' => $body ? strlen($body) : 0,
                'vendor_dir_exists' => $vendor_exists,
                'autoload_exists' => $autoload_exists,
                'first_500_chars' => $body ? substr($body, 0, 500) : null,
                'last_500_chars' => $body ? substr($body, -500) : null,
                'parsed' => $matches,
            ];
        },
    ]);

    // 临时 debug：直接探测 wp_handle_upload 等核心 filter 是否被 Media Cloud 注册
    register_rest_route('gkhubs/v1', '/debug-hooks', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => function () {
            global $wp_filter;
            $hooks = ['wp_handle_upload', 'wp_handle_upload_prefilter', 'wp_get_attachment_url', 'wp_update_attachment_metadata', 'add_attachment'];
            $out = [];
            foreach ($hooks as $h) {
                $callbacks = [];
                if (isset($wp_filter[$h])) {
                    foreach ($wp_filter[$h]->callbacks as $prio => $cbs) {
                        foreach ($cbs as $cb) {
                            $fn = $cb['function'];
                            if (is_array($fn)) {
                                $callbacks[] = "$prio: " . (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . "::" . $fn[1];
                            } elseif (is_string($fn)) {
                                $callbacks[] = "$prio: $fn";
                            } elseif ($fn instanceof Closure) {
                                $callbacks[] = "$prio: <Closure>";
                            }
                        }
                    }
                }
                $out[$h] = $callbacks;
            }
            // mediacloud_storage_tool helper
            $helpers = [];
            foreach (['mediacloud_storage_tool', 'mediacloud_image_tool', 'mediacloud'] as $h) {
                $helpers[$h] = function_exists($h);
            }
            // 看 plugin 入口
            $entry = WP_PLUGIN_DIR . '/ilab-media-tools/ilab-media-tools.php';
            $entry_size = file_exists($entry) ? filesize($entry) : 0;
            return [
                'hooks' => $out,
                'helpers_exist' => $helpers,
                'entry_file_size' => $entry_size,
                'wp_plugin_dir' => WP_PLUGIN_DIR,
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
