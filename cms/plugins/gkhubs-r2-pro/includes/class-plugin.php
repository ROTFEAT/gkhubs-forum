<?php
namespace GKHubs\R2\Pro;
defined('ABSPATH') || exit;

class Plugin {
    private static $instance;
    public static function instance(): self { return self::$instance ??= new self(); }
    public function boot(): void {
        (new Settings())->register();
        (new Uploader())->register();
        (new Rewriter())->register();
        (new Remove_Handler())->register();
        Tools_Manager::get_instance()->register();
        (new Rest_Api())->register();
    }
}
