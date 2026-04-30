<?php
/**
 * Plugin bootstrap：把所有 sub-controller 串起来。
 */

namespace GKHubs\R2;

defined('ABSPATH') || exit;

class Plugin {
    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        (new Settings())->register();
        (new Uploader())->register();
        (new Rewriter())->register();
        (new Migrator())->register();
    }
}
