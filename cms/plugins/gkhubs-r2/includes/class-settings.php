<?php
/**
 * Admin 设置页：Settings → R2 Offload
 */

namespace GKHubs\R2;

defined('ABSPATH') || exit;

class Settings {
    const PAGE_SLUG = 'gkhubs-r2';

    public static function defaults(): array {
        return [
            'endpoint'        => '',
            'bucket'          => '',
            'access_key'      => '',
            'secret'          => '',
            'public_url'      => '',
            'prefix'          => '',
            'delete_local'    => false,
            'rewrite_content' => true,
            'region'          => 'auto',
        ];
    }

    public static function get(): array {
        $opts = (array) get_option(GKHUBS_R2_OPTION_KEY, []);
        return array_merge(self::defaults(), $opts);
    }

    public static function set(array $opts): void {
        $merged = array_merge(self::get(), $opts);
        update_option(GKHUBS_R2_OPTION_KEY, $merged);
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_gkhubs_r2_test', [$this, 'handle_test_connection']);
    }

    public function add_menu(): void {
        add_options_page(
            'gkhubs R2 Offload',
            'R2 Offload',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting('gkhubs_r2_group', GKHUBS_R2_OPTION_KEY, [$this, 'sanitize']);
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
        $clean['delete_local']    = !empty($input['delete_local']);
        $clean['rewrite_content'] = !empty($input['rewrite_content']);
        $clean['region']          = sanitize_text_field($input['region'] ?? 'auto');
        // 不允许把 secret 清成空（保留旧值）
        if ($clean['secret'] === '') {
            $existing = self::get();
            $clean['secret'] = $existing['secret'];
        }
        return $clean;
    }

    public function handle_test_connection(): void {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('gkhubs_r2_test');
        $cfg = self::get();
        $client = new Client($cfg);
        $r = $client->test_connection();
        $msg = $r['ok']
            ? 'OK：能连到 bucket [' . $cfg['bucket'] . ']'
            : 'FAIL：' . ($r['error'] ?? 'unknown') . ' (' . ($r['status'] ?? '?') . ')';
        set_transient('gkhubs_r2_test_result', $msg, 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $cfg = self::get();
        $test_result = get_transient('gkhubs_r2_test_result');
        if ($test_result) delete_transient('gkhubs_r2_test_result');
        ?>
        <div class="wrap">
            <h1>gkhubs R2 Offload</h1>

            <?php if ($test_result): ?>
                <div class="notice <?php echo strpos($test_result, 'OK') === 0 ? 'notice-success' : 'notice-error'; ?>">
                    <p><?php echo esc_html($test_result); ?></p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('gkhubs_r2_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="endpoint">R2 Endpoint URL</label></th>
                        <td>
                            <input id="endpoint" type="text" class="regular-text"
                                name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[endpoint]"
                                value="<?php echo esc_attr($cfg['endpoint']); ?>"
                                placeholder="https://&lt;account&gt;.r2.cloudflarestorage.com">
                            <p class="description">Cloudflare R2 控制台 → bucket 设置 → "S3 Endpoint"。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="bucket">Bucket Name</label></th>
                        <td><input id="bucket" type="text" class="regular-text"
                            name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[bucket]"
                            value="<?php echo esc_attr($cfg['bucket']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="access_key">Access Key ID</label></th>
                        <td><input id="access_key" type="text" class="regular-text"
                            name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[access_key]"
                            value="<?php echo esc_attr($cfg['access_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="secret">Secret Access Key</label></th>
                        <td>
                            <input id="secret" type="password" class="regular-text"
                                name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[secret]"
                                value="" placeholder="<?php echo $cfg['secret'] ? '已设置（留空则不变）' : ''; ?>">
                            <p class="description">出于安全考虑，已设置的密钥不回显。仅在更换时填写。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="public_url">Public URL Base</label></th>
                        <td>
                            <input id="public_url" type="text" class="regular-text"
                                name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[public_url]"
                                value="<?php echo esc_attr($cfg['public_url']); ?>"
                                placeholder="https://media.gkhubs.com 或 https://pub-xxxx.r2.dev">
                            <p class="description">读端公网基地址。要么是 R2 桶开 Public Access 给的 <code>pub-*.r2.dev</code>，要么是自定义 CDN 域名。留空则只能拿 endpoint URL（默认 R2 不允许匿名读，需要桶开公开访问或绑域名）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prefix">Path Prefix</label></th>
                        <td>
                            <input id="prefix" type="text" class="regular-text"
                                name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[prefix]"
                                value="<?php echo esc_attr($cfg['prefix']); ?>"
                                placeholder="可选，例如 wp-uploads">
                            <p class="description">所有上传到 R2 的对象会加这个前缀。留空则按 WP 默认路径（YYYY/MM/...）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>选项</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[delete_local]"
                                    <?php checked(!empty($cfg['delete_local'])); ?>>
                                推到 R2 后删除本地文件
                            </label>
                            <p class="description">省盘但放弃本地回退。**只在 R2 公网读 OK 后再开。**</p>
                            <br>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo GKHUBS_R2_OPTION_KEY; ?>[rewrite_content]"
                                    <?php checked(!empty($cfg['rewrite_content'])); ?>>
                                改写文章正文里的旧上传链接为 R2 URL（the_content filter）
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('保存'); ?>
            </form>

            <hr>

            <h2>测试连接</h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline">
                <input type="hidden" name="action" value="gkhubs_r2_test">
                <?php wp_nonce_field('gkhubs_r2_test'); ?>
                <button type="submit" class="button">HEAD bucket（验证凭证 + 网络）</button>
            </form>

            <hr>

            <h2>批量迁移现有媒体到 R2</h2>
            <p>把数据库里所有已存在的 attachment 推到 R2（每批 20 个，跑后台 cron）。</p>
            <p>
                <?php $stats = Migrator::stats(); ?>
                总附件 <strong><?php echo (int) $stats['total']; ?></strong>，
                已迁移 <strong><?php echo (int) $stats['migrated']; ?></strong>，
                未迁移 <strong><?php echo (int) $stats['pending']; ?></strong>。
            </p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline">
                <input type="hidden" name="action" value="gkhubs_r2_migrate_kick">
                <?php wp_nonce_field('gkhubs_r2_migrate'); ?>
                <button type="submit" class="button button-primary">启动批量迁移</button>
            </form>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline; margin-left:8px">
                <input type="hidden" name="action" value="gkhubs_r2_migrate_now">
                <?php wp_nonce_field('gkhubs_r2_migrate'); ?>
                <button type="submit" class="button">立即迁移一批（测试）</button>
            </form>
        </div>
        <?php
    }
}
