<?php
/**
 * Plugin Name: gkhubs R2 Offload Pro
 * Plugin URI:  https://gkhubs.com/r2-offload
 * Description: 把 WordPress 媒体卸载到 Cloudflare R2（S3 兼容）。Tool 框架 + 异步队列 + license。仿 WP Offload Media 架构，零外部依赖（手写 SigV4）。
 * Version:     1.0.0-pro
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author:      gkhubs.com
 * License:     Proprietary
 * Text Domain: gkhubs-r2-pro
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/version.php';

define('GKHUBS_R2_PRO_FILE', __FILE__);
define('GKHUBS_R2_PRO_DIR', plugin_dir_path(__FILE__));
define('GKHUBS_R2_PRO_URL', plugin_dir_url(__FILE__));
define('GKHUBS_R2_PRO_BASENAME', plugin_basename(__FILE__));
define('GKHUBS_R2_OPTION_KEY', 'gkhubs_r2_settings');
define('GKHUBS_R2_LICENSE_OPTION', 'gkhubs_r2_pro_license');
define('GKHUBS_R2_META_KEY', '_gkhubs_r2_key');

require_once GKHUBS_R2_PRO_DIR . 'includes/class-client.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-license.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-async-request.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-background-process.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/abstract-tool.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/abstract-background-tool.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/tool-bulk-migrate.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/tool-remove-local.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/tool-analyze-repair.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/tools/tool-downloader.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-tools-manager.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-uploader.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-rewriter.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/handlers/class-remove-handler.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-rest-api.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-settings.php';
require_once GKHUBS_R2_PRO_DIR . 'includes/class-plugin.php';

\GKHubs\R2\Pro\Plugin::instance()->boot();

register_activation_hook(__FILE__, function () {
    if (!get_option(GKHUBS_R2_OPTION_KEY)) {
        $defaults = \GKHubs\R2\Pro\Settings::defaults();
        $defaults['endpoint']    = getenv('R2_ENDPOINT') ?: '';
        $defaults['bucket']      = getenv('R2_BUCKET') ?: '';
        $defaults['access_key']  = getenv('R2_ACCESS_KEY') ?: '';
        $defaults['secret']      = getenv('R2_SECRET') ?: '';
        $defaults['public_url']  = getenv('R2_PUBLIC_URL') ?: '';
        add_option(GKHUBS_R2_OPTION_KEY, $defaults);
    }
});
