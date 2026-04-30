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

# 2. 激活内置插件（幂等 —— 已激活时 wp-cli 是 no-op）
wp --allow-root plugin activate ilab-media-tools 2>/dev/null || true

# 3. 确保 bot 用户存在（role=editor —— 能编辑文章但管不了站点设置）
if ! wp --allow-root user get "$BOT_USER" >/dev/null 2>&1; then
    echo "[gkhubs-init] 创建 bot 用户 $BOT_USER"
    wp --allow-root user create "$BOT_USER" "$BOT_EMAIL" \
        --role=editor \
        --user_pass="$(openssl rand -hex 32)" \
        --display_name="Crawler Bot"
fi

# 4. 用 wp option update 配 Media Cloud → R2（比 filter hook 可靠 ——
#    hook 名跨版本会变。所有 update 都是幂等的。）
if [ -n "${R2_ENDPOINT:-}" ] && [ -n "${R2_BUCKET:-}" ] && [ -n "${R2_ACCESS_KEY:-}" ] && [ -n "${R2_SECRET:-}" ]; then
    echo "[gkhubs-init] 配置 R2 offload"
    # 首次部署后请验证 option key 前缀对得上 Media Cloud 实际版本：
    #   wp --allow-root option list --search='mcloud-storage*' --format=table
    # 若 4.6.4 用的是其他 key，这里要相应调整。
    wp --allow-root option update mcloud-storage-driver "s3"
    wp --allow-root option update mcloud-storage-s3-access-key-id "$R2_ACCESS_KEY"
    wp --allow-root option update mcloud-storage-s3-access-secret "$R2_SECRET"
    wp --allow-root option update mcloud-storage-s3-bucket "$R2_BUCKET"
    wp --allow-root option update mcloud-storage-s3-region "auto"
    wp --allow-root option update mcloud-storage-s3-endpoint "$R2_ENDPOINT"
    wp --allow-root option update mcloud-storage-s3-use-path-style-endpoint "1"
fi

echo "[gkhubs-init] done"
