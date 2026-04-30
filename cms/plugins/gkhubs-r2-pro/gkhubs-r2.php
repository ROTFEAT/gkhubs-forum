<?php
/**
 * Plugin Name: gkhubs R2 Offload
 * Description: 把 WordPress 媒体卸载到 Cloudflare R2（S3 兼容），含批量迁移、URL 改写、admin 设置页。零外部依赖（手写 SigV4）。
 * Version: 1.0.0
 * Author: gkhubs.com
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License: MIT
 * Text Domain: gkhubs-r2
 */

defined('ABSPATH') || exit;

define('GKHUBS_R2_FILE', __FILE__);
define('GKHUBS_R2_DIR', plugin_dir_path(__FILE__));
define('GKHUBS_R2_URL', plugin_dir_url(__FILE__));
define('GKHUBS_R2_VERSION', '1.0.0');
define('GKHUBS_R2_OPTION_KEY', 'gkhubs_r2_settings');
define('GKHUBS_R2_META_KEY', '_gkhubs_r2_key');

require_once GKHUBS_R2_DIR . 'includes/class-client.php';
require_once GKHUBS_R2_DIR . 'includes/class-settings.php';
require_once GKHUBS_R2_DIR . 'includes/class-uploader.php';
require_once GKHUBS_R2_DIR . 'includes/class-rewriter.php';
require_once GKHUBS_R2_DIR . 'includes/class-migrator.php';
require_once GKHUBS_R2_DIR . 'includes/class-plugin.php';

\GKHubs\R2\Plugin::instance()->boot();

register_activation_hook(__FILE__, function () {
    // 写入默认设置（不覆盖已有值）
    if (!get_option(GKHUBS_R2_OPTION_KEY)) {
        $defaults = [
            'endpoint'    => getenv('R2_ENDPOINT') ?: '',
            'bucket'      => getenv('R2_BUCKET') ?: '',
            'access_key'  => getenv('R2_ACCESS_KEY') ?: '',
            'secret'      => getenv('R2_SECRET') ?: '',
            'public_url'  => getenv('R2_PUBLIC_URL') ?: '',
            'prefix'      => '',
            'delete_local' => false,
            'rewrite_content' => true,
        ];
        add_option(GKHUBS_R2_OPTION_KEY, $defaults);
    }
});
