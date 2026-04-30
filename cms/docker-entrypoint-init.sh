#!/usr/bin/env bash
# cms/docker-entrypoint-init.sh —— 幂等的首次启动 bootstrap。
# Apache 起来后运行；每次容器重启都跑一次也安全。
set -euo pipefail

WP_PATH=/var/www/html
BOT_USER="${GKHUBS_BOT_USER:-crawler-bot}"
BOT_EMAIL="${GKHUBS_BOT_EMAIL:-crawler@gkhubs.local}"

cd "$WP_PATH"

# 1. WP 还没装 → 直接退出；首次访问 /wp-admin/install.php 会触发安装
if ! wp --allow-root core is-installed 2>/dev/null; then
    echo "[gkhubs-init] WP 未安装，跳过 bootstrap"
    exit 0
fi

# 2. 弃用 Media Cloud（Freemius 许可门拦着）；启用自写的 gkhubs-r2 插件
wp --allow-root plugin deactivate ilab-media-tools 2>/dev/null || true
wp --allow-root plugin activate gkhubs-r2 2>/dev/null || true

# 3. 确保 bot 用户存在（role=editor —— 能编辑文章但管不了站点设置）
if ! wp --allow-root user get "$BOT_USER" >/dev/null 2>&1; then
    echo "[gkhubs-init] 创建 bot 用户 $BOT_USER"
    wp --allow-root user create "$BOT_USER" "$BOT_EMAIL" \
        --role=editor \
        --user_pass="$(openssl rand -hex 32)" \
        --display_name="Crawler Bot"
fi

# 3b. 给 bot 创建 App Password（仅首次；密码打到日志一次，之后改为重置）
if ! wp --allow-root option get gkhubs_bot_apppwd_v1 >/dev/null 2>&1; then
    echo "[gkhubs-init] 给 $BOT_USER 创建 App Password"
    PWD=$(wp --allow-root user application-password create "$BOT_USER" crawler --porcelain 2>/dev/null || echo "")
    if [ -n "$PWD" ]; then
        echo "[gkhubs-init] APP_PASSWORD_FOR_${BOT_USER}=${PWD}"
        wp --allow-root option update gkhubs_bot_apppwd_v1 1 >/dev/null
    fi
fi

# 4. 用 wp option update 配 Media Cloud → R2（比 filter hook 可靠 ——
#    hook 名跨版本会变。所有 update 都是幂等的。）
if [ -n "${R2_ENDPOINT:-}" ] && [ -n "${R2_BUCKET:-}" ] && [ -n "${R2_ACCESS_KEY:-}" ] && [ -n "${R2_SECRET:-}" ]; then
    echo "[gkhubs-init] R2 凭证齐备 —— 由 03-gkhubs-r2-upload.php 接管上传"
    # 清理 Media Cloud 留的 option（保持 wp_options 表干净）
    for k in mcloud-storage-driver mcloud-storage-provider mcloud-storage-s3-access-key \
             mcloud-storage-s3-access-key-id mcloud-storage-s3-secret \
             mcloud-storage-s3-access-secret mcloud-storage-s3-bucket \
             mcloud-storage-s3-region mcloud-storage-s3-endpoint \
             mcloud-storage-s3-use-path-style-endpoint mcloud_show_wizard \
             mcloud-tool-enabled-storage mcloud-storage-test-passed \
             mcloud-pinned-tools mcloud-has-run; do
        wp --allow-root option delete "$k" 2>/dev/null || true
    done
fi

echo "[gkhubs-init] done"
