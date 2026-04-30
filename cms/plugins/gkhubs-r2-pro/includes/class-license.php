<?php
/**
 * License 校验（stub）。统一接口便于后期换成真 license server。
 * 当前：本地 option 里有合法格式的 key（≥24 字符 字母数字+连字符）即激活。
 * 后期换远程时只动 activate() 与 status() 的实现。
 */

namespace GKHubs\R2\Pro;

defined('ABSPATH') || exit;

class License {
    public static function is_active(): bool {
        if (defined('GKHUBS_R2_PRO_INTERNAL_LICENSE') && GKHUBS_R2_PRO_INTERNAL_LICENSE) return true;
        return self::is_valid_format(self::get_key());
    }

    public static function status(): array {
        $stored = (array) get_option(GKHUBS_R2_LICENSE_OPTION, []);
        $is_active = self::is_active();
        $key = self::get_key();
        return [
            'active'     => $is_active,
            'expires_at' => $stored['expires_at'] ?? null,
            'tier'       => $stored['tier'] ?? ($is_active ? 'pro' : 'free'),
            'key_masked' => $key ? self::mask($key) : '',
            'source'     => defined('GKHUBS_R2_PRO_INTERNAL_LICENSE') && GKHUBS_R2_PRO_INTERNAL_LICENSE ? 'internal' : 'option',
        ];
    }

    public static function activate(string $key): array {
        $key = trim($key);
        if (!self::is_valid_format($key)) {
            return ['ok' => false, 'error' => 'license key 格式无效（≥24 字符，仅字母数字与连字符）'];
        }
        // TODO: 远程校验
        update_option(GKHUBS_R2_LICENSE_OPTION, [
            'key' => $key, 'tier' => 'pro',
            'activated_at' => time(), 'expires_at' => null,
        ]);
        return ['ok' => true];
    }

    public static function deactivate(): void {
        delete_option(GKHUBS_R2_LICENSE_OPTION);
    }

    public static function get_key(): string {
        return ((array) get_option(GKHUBS_R2_LICENSE_OPTION, []))['key'] ?? '';
    }

    public static function is_valid_format(string $key): bool {
        return $key !== '' && preg_match('/^[A-Za-z0-9-]{24,}$/', $key) === 1;
    }

    public static function mask(string $key): string {
        if (strlen($key) < 8) return str_repeat('*', strlen($key));
        return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4);
    }
}
