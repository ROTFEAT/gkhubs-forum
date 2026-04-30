<?php
/**
 * Tools_Manager（仿 AS3CF Tools_Manager）：单例、注册 tools、路由 perform_action、限制同时只跑一个 tool。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class Tools_Manager {
    private static $instance;
    private $tools = [];

    public static function get_instance(): self { return self::$instance ??= new self(); }

    public function register(): void {
        add_filter('cron_schedules', function ($s) {
            if (!isset($s['gkhubs_r2_pro_minute'])) {
                $s['gkhubs_r2_pro_minute'] = ['interval' => 60, 'display' => 'gkhubs-r2-pro 每分钟'];
            }
            return $s;
        });
        $this->register_tool(new Tool_Bulk_Migrate());
        $this->register_tool(new Tool_Remove_Local());
        $this->register_tool(new Tool_Analyze_Repair());
        $this->register_tool(new Tool_Downloader());
        do_action('gkhubs_r2_pro_register_tools', $this);
        add_action('admin_post_gkhubs_r2_pro_tool_action', [$this, 'handle_admin_post']);
    }

    public function register_tool(Tool $tool): bool {
        $key = $tool->get_tool_key();
        if (isset($this->tools[$key])) return false;
        $this->tools[$key] = $tool;
        $tool->init();
        return true;
    }

    public function get_tool(string $key): ?Tool { return $this->tools[$key] ?? null; }
    public function get_tools(): array { return $this->tools; }
    public function get_tools_info(): array {
        $info = [];
        foreach ($this->tools as $key => $t) {
            if (!$t->should_render()) continue;
            $info[$key] = $t->get_info();
        }
        return $info;
    }

    public function get_running_tool(): ?Tool {
        foreach ($this->tools as $t) if ($t->is_active()) return $t;
        return null;
    }

    public function perform_action(string $tool_key, string $action): bool {
        $tool = $this->get_tool($tool_key);
        if (!$tool) return false;
        $method = 'handle_' . $action;
        if (!method_exists($tool, $method)) return false;
        $running = $this->get_running_tool();
        if ($running && $running->get_tool_key() !== $tool->get_tool_key()) return false;
        call_user_func([$tool, $method]);
        return true;
    }

    public function handle_admin_post(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_pro_tool_action');
        if (!License::is_active()) {
            set_transient('gkhubs_r2_pro_msg', 'FAIL: Pro license 未激活', 60);
            wp_safe_redirect(admin_url('options-general.php?page=' . Settings::PAGE_SLUG . '&tab=tools'));
            exit;
        }
        $tool_key = sanitize_key($_POST['tool_key'] ?? '');
        $action   = sanitize_key($_POST['tool_action'] ?? '');
        $ok = $this->perform_action($tool_key, $action);
        $msg = $ok ? sprintf('Tool [%s] 执行 [%s] 成功', $tool_key, $action)
                   : sprintf('FAIL: tool [%s] / action [%s] 不可用（可能已有别的 tool 在跑）', $tool_key, $action);
        set_transient('gkhubs_r2_pro_msg', $msg, 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::PAGE_SLUG . '&tab=tools'));
        exit;
    }
}
