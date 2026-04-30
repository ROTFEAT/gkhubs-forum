<?php
/**
 * Admin 设置页：Settings → R2 Offload Pro
 * 三个 tab：general / tools / license
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class Settings {
    const PAGE_SLUG = 'gkhubs-r2-pro';

    public static function defaults(): array {
        return [
            'endpoint' => '', 'bucket' => '', 'access_key' => '', 'secret' => '',
            'public_url' => '', 'prefix' => '', 'rewrite_content' => true, 'region' => 'auto',
        ];
    }
    public static function get(): array { return array_merge(self::defaults(), (array) get_option(GKHUBS_R2_OPTION_KEY, [])); }
    public static function set(array $opts): void { update_option(GKHUBS_R2_OPTION_KEY, array_merge(self::get(), $opts)); }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_gkhubs_r2_pro_test', [$this, 'handle_test']);
        add_action('admin_post_gkhubs_r2_pro_license', [$this, 'handle_license']);
    }
    public function add_menu(): void {
        add_options_page('gkhubs R2 Offload Pro', 'R2 Offload Pro', 'manage_options', self::PAGE_SLUG, [$this, 'render_page']);
    }
    public function register_settings(): void {
        register_setting('gkhubs_r2_pro_group', GKHUBS_R2_OPTION_KEY, [$this, 'sanitize']);
    }
    public function sanitize($input): array {
        $input = (array) $input;
        $clean = self::defaults();
        $clean['endpoint']        = esc_url_raw(trim($input['endpoint'] ?? ''));
        $clean['bucket']          = sanitize_text_field($input['bucket'] ?? '');
        $clean['access_key']      = sanitize_text_field($input['access_key'] ?? '');
        $clean['secret']          = (string) ($input['secret'] ?? '');
        $clean['public_url']      = esc_url_raw(trim($input['public_url'] ?? ''));
        $clean['prefix']          = trim(sanitize_text_field($input['prefix'] ?? ''), '/');
        $clean['rewrite_content'] = !empty($input['rewrite_content']);
        $clean['region']          = sanitize_text_field($input['region'] ?? 'auto');
        if ($clean['secret'] === '') $clean['secret'] = self::get()['secret'];
        return $clean;
    }
    public function handle_test(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_pro_test');
        $r = (new Client(self::get()))->test_connection();
        $msg = $r['ok'] ? 'OK：HEAD bucket 成功' : 'FAIL：' . ($r['error'] ?? 'unknown') . ' (' . ($r['status'] ?? '?') . ')';
        set_transient('gkhubs_r2_pro_msg', $msg, 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }
    public function handle_license(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_pro_license');
        $type = sanitize_key($_POST['action_type'] ?? '');
        if ($type === 'activate') {
            $r = License::activate(sanitize_text_field($_POST['license_key'] ?? ''));
            $msg = $r['ok'] ? 'OK: License 已激活' : 'FAIL: ' . ($r['error'] ?? 'unknown');
        } elseif ($type === 'deactivate') {
            License::deactivate();
            $msg = 'License 已停用';
        } else $msg = 'unknown';
        set_transient('gkhubs_r2_pro_msg', $msg, 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=license'));
        exit;
    }
    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $msg = get_transient('gkhubs_r2_pro_msg');
        if ($msg) delete_transient('gkhubs_r2_pro_msg');
        ?>
        <div class="wrap">
            <h1>gkhubs R2 Offload Pro <span style="font-size:13px;color:#888">v<?php echo GKHUBS_R2_PRO_VERSION; ?></span></h1>
            <?php if ($msg): ?>
                <div class="notice <?php echo strpos($msg, 'OK') === 0 ? 'notice-success' : (strpos($msg, 'FAIL') === 0 ? 'notice-error' : 'notice-info'); ?> is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>
            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=general" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>">通用设置</a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=tools" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>">Pro Tools</a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=license" class="nav-tab <?php echo $tab === 'license' ? 'nav-tab-active' : ''; ?>">License</a>
            </h2>
            <?php if ($tab === 'general') $this->render_general(); ?>
            <?php if ($tab === 'tools') $this->render_tools(); ?>
            <?php if ($tab === 'license') $this->render_license(); ?>
        </div>
        <?php
    }
    private function render_general(): void {
        $cfg = self::get();
        ?>
        <form action="options.php" method="post">
            <?php settings_fields('gkhubs_r2_pro_group'); ?>
            <table class="form-table">
                <tr><th><label for="endpoint">R2 Endpoint URL</label></th>
                    <td><input id="endpoint" type="text" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[endpoint]" value="<?php echo esc_attr($cfg['endpoint']); ?>" placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com"></td></tr>
                <tr><th><label for="bucket">Bucket Name</label></th>
                    <td><input id="bucket" type="text" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[bucket]" value="<?php echo esc_attr($cfg['bucket']); ?>"></td></tr>
                <tr><th><label for="access_key">Access Key ID</label></th>
                    <td><input id="access_key" type="text" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[access_key]" value="<?php echo esc_attr($cfg['access_key']); ?>"></td></tr>
                <tr><th><label for="secret">Secret Access Key</label></th>
                    <td><input id="secret" type="password" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[secret]" value="" placeholder="<?php echo $cfg['secret'] ? '已设置（留空则不变）' : ''; ?>"></td></tr>
                <tr><th><label for="public_url">Public URL Base</label></th>
                    <td><input id="public_url" type="text" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[public_url]" value="<?php echo esc_attr($cfg['public_url']); ?>" placeholder="https://media.gkhubs.com 或 https://pub-xxxx.r2.dev"><p class="description">读端公网基地址。R2 桶**默认拒绝匿名读**，需 R2 控制台开 Public Access 或绑自定义域名。</p></td></tr>
                <tr><th><label for="prefix">Path Prefix</label></th>
                    <td><input id="prefix" type="text" class="regular-text" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[prefix]" value="<?php echo esc_attr($cfg['prefix']); ?>" placeholder="可选"></td></tr>
                <tr><th>选项</th>
                    <td><label><input type="checkbox" name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[rewrite_content]" <?php checked(!empty($cfg['rewrite_content'])); ?>> 改写文章正文里旧上传链接为 R2 URL</label></td></tr>
            </table>
            <?php submit_button('保存'); ?>
        </form>
        <hr><h2>测试连接</h2>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline">
            <input type="hidden" name="action" value="gkhubs_r2_pro_test">
            <?php wp_nonce_field('gkhubs_r2_pro_test'); ?>
            <button type="submit" class="button">HEAD bucket（验证凭证 + 网络）</button>
        </form>
        <?php
    }
    private function render_tools(): void {
        $license_active = License::is_active();
        $manager = Tools_Manager::get_instance();
        $running = $manager->get_running_tool();
        ?>
        <h2>Pro Tools</h2>
        <?php if ($running): ?>
            <p>当前运行中：<strong><?php echo esc_html($running->get_title_text()); ?></strong> ——
                状态 <?php echo esc_html($running->get_status_summary()); ?>，
                进度 <?php echo (int) $running->get_progress(); ?>%
            </p>
        <?php endif; ?>
        <?php if (!$license_active): ?>
            <div class="notice notice-warning"><p>Pro license 未激活。<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=license">前往 License 标签激活</a>。</p></div>
        <?php endif; ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th style="width:18%">Tool</th><th>说明</th><th style="width:25%">状态</th><th style="width:24%">操作</th></tr></thead>
            <tbody>
                <?php foreach ($manager->get_tools() as $id => $tool):
                    if (!$tool->should_render()) continue;
                    $is_run = $running && $running->get_tool_key() === $id;
                    $can_start = $license_active && !$tool->is_active() && (!$running || $is_run);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($tool->get_title_text()); ?></strong><br><code><?php echo esc_html($id); ?></code></td>
                    <td><?php echo esc_html($tool->get_description()); ?></td>
                    <td><?php echo esc_html($tool->get_status_summary()); ?></td>
                    <td>
                        <?php if ($can_start) $this->action_btn($id, 'start', $tool->get_button_text(), 'button-primary'); ?>
                        <?php if ($tool->is_active() && $tool->is_queued() && !$tool->is_cancelled()): ?>
                            <?php $this->action_btn($id, 'pause_resume', $tool->is_paused() ? '继续' : '暂停', ''); ?>
                            <?php $this->action_btn($id, 'cancel', '取消', 'button-link-delete'); ?>
                        <?php endif; ?>
                        <?php if (!$license_active): ?><button class="button" disabled>需 license</button><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    private function action_btn(string $key, string $action, string $label, string $class): void {
        ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin:0 4px 0 0">
            <input type="hidden" name="action" value="gkhubs_r2_pro_tool_action">
            <input type="hidden" name="tool_key" value="<?php echo esc_attr($key); ?>">
            <input type="hidden" name="tool_action" value="<?php echo esc_attr($action); ?>">
            <?php wp_nonce_field('gkhubs_r2_pro_tool_action'); ?>
            <button type="submit" class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }
    private function render_license(): void {
        $s = License::status();
        ?>
        <h2>License 状态</h2>
        <table class="form-table">
            <tr><th>激活状态</th><td>
                <?php if ($s['active']): ?><span style="color:green;font-weight:bold">✓ 已激活</span> （tier: <?php echo esc_html($s['tier']); ?>，来源: <?php echo esc_html($s['source']); ?>）
                <?php else: ?><span style="color:#a00;font-weight:bold">✗ 未激活</span><?php endif; ?>
            </td></tr>
            <?php if ($s['key_masked']): ?><tr><th>License Key</th><td><code><?php echo esc_html($s['key_masked']); ?></code></td></tr><?php endif; ?>
            <tr><th>版本</th><td><?php echo esc_html(GKHUBS_R2_PRO_VERSION); ?></td></tr>
        </table>
        <hr>
        <?php if (!$s['active']): ?>
            <h3>激活 Pro</h3>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="gkhubs_r2_pro_license">
                <input type="hidden" name="action_type" value="activate">
                <?php wp_nonce_field('gkhubs_r2_pro_license'); ?>
                <p><label>License Key（≥24 字符 字母数字+连字符）：<br><input type="text" class="regular-text" name="license_key" placeholder="GKHUBS-R2-PRO-XXXX-XXXX-XXXX-XXXX"></label></p>
                <p><button type="submit" class="button button-primary">激活</button></p>
            </form>
            <p class="description">stub 校验：合法格式即激活。后期接入真 license server。</p>
        <?php else: ?>
            <h3>停用</h3>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="gkhubs_r2_pro_license">
                <input type="hidden" name="action_type" value="deactivate">
                <?php wp_nonce_field('gkhubs_r2_pro_license'); ?>
                <button type="submit" class="button">停用 License</button>
            </form>
        <?php endif; ?>
        <?php
    }
}
