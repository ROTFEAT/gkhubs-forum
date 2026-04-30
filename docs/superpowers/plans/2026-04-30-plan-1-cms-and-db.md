# Plan 1：CMS + DB —— 实施计划

> **执行者须知：** 必须使用 superpowers:subagent-driven-development（如果可用子代理）或 superpowers:executing-plans 来执行本计划。每一步用 `- [ ]` 复选框标记进度。

**目标：** 在 Dokploy（152.53.53.84）部署一套 Headless WordPress + MySQL，包含两个自定义文章类型（`gk_figure`、`gk_listing`）、四个自定义 REST 端点、R2 媒体卸载，以及一个专用 bot 用户——具备接收爬虫写入的能力。

**架构：** 单容器 WP 镜像（基于 `wordpress:6.5-apache`）+ 独立 `mysql:8.0` 容器。CPT 通过 mu-plugins 注册，不依赖任何 UI 插件。自定义 REST 端点写在 mu-plugin 里。首次启动通过幂等 bootstrap 脚本创建 bot 用户并激活插件。测试嵌入 Dockerfile 多阶段构建（静态检查），加上一份用 docker network 串起来的 `smoke.sh` 集成测试。

**技术栈：** WordPress 6.5、PHP 8.2、Apache、MySQL 8.0、Media Cloud (ILAB) 插件、Cloudflare R2（S3 协议）、bash + curl + jq 跑测试。

**Spec 引用：** [`docs/superpowers/specs/2026-04-30-gkhubs-aggregation-mvp-design.md`](../specs/2026-04-30-gkhubs-aggregation-mvp-design.md) —— 第 3、4.2、4.3、6 节。

**服务器与凭据：**
- Dokploy 主机：`152.53.53.84`
- Dokploy API key：用户已在会话中提供（不复读，从会话记忆里取，或问用户）
- R2 桶：待创建——`gkhubs-media`
- DNS：`cms.gkhubs.com` 在 Phase 5 才会指过来

---

## 文件结构

Plan 1 完成后，仓库布局：

```
GKHUBS-forum/
├── README.md
├── .gitignore
├── Makefile                          # 便捷目标（test、build）
├── cms/
│   ├── Dockerfile                    # 多阶段：builder → test → runtime
│   ├── apache-vhost.conf             # 为 headless 调过的 vhost
│   ├── docker-entrypoint-init.sh     # 幂等的首次启动 bootstrap
│   ├── healthz.php                   # 不依赖 WP 的健康检查端点
│   ├── mu-plugins/
│   │   ├── 00-gkhubs-cpt.php         # 注册 gk_figure、gk_listing
│   │   ├── 01-gkhubs-rest.php        # 自定义 REST 端点
│   │   └── 99-gkhubs-health.php      # /wp-json/gkhubs/v1/health
│   ├── tests/
│   │   ├── smoke.sh                  # 完整集成测试（mysql + wp + curl）
│   │   ├── static-check.sh           # 在 Dockerfile test 阶段调用
│   │   └── fixtures/
│   │       ├── figure-sample.json
│   │       └── listing-sample.json
│   └── plugins-vendor/
│       └── .gitignore                # 构建时下载，不入库
├── docs/
│   ├── superpowers/
│   │   ├── specs/  （已存在）
│   │   └── plans/  （本文件位于此）
└── （其他服务目录在后续 plan 中加入）
```

**边界注解：**
- `mu-plugins/*.php` —— 每个文件 ≤ 200 行，职责单一
- `tests/smoke.sh` —— **不依赖**宿主机 WP/DB；所有依赖都用 docker 起
- `docker-entrypoint-init.sh` —— 首次启动时跑一次，幂等（重建后再跑无副作用）

---

## 预检清单（用户执行一次）

- [ ] **P-1**：确认 Dokploy 可达
  ```bash
  curl -sf -H "x-api-key: $DOKPLOY_API_KEY" https://152.53.53.84:3000/api/trpc/admin.getOne | jq .
  ```
  预期：返回 200 并附带 admin 信息 JSON。401 → key 错。超时 → 检查服务器。

- [ ] **P-2**：在 Cloudflare 控制台创建 R2 桶 `gkhubs-media`
  - Settings → R2 → Create bucket → 名称 `gkhubs-media`，位置 auto
  - 记录：account ID、access key ID、secret access key、S3 API endpoint URL
  - 公开访问：**关闭**（之后通过 Cloudflare CDN + 自定义域名映射对外服务）

- [ ] **P-3**：定一个 cms admin 密码（≥16 位，存进密码管理器）

- [ ] **P-4**：确认 Docker BuildKit 已启用
  ```bash
  docker buildx version
  ```
  预期：打印版本号。如果缺失，安装 Docker Desktop ≥ 20.10，或每次构建前 `export DOCKER_BUILDKIT=1`。本计划用了 heredoc `COPY <<'EOF'`，必须 BuildKit。

任一预检失败，**停下处理后再继续**。

> **关于密码管理器**：本计划里凡是写"密码管理器"的位置，请替换成你实际用的工具（Bitwarden、Apple 钥匙串、`~/.gkhubs-secrets.txt` 都行）—— 唯一硬要求是密码不要进 git。

---

## 任务 1：仓库骨架与根级文件

**文件：**
- 创建：`README.md`
- 创建：`.gitignore`
- 创建：`Makefile`
- 创建：`cms/.gitkeep`

- [ ] **步骤 1：初始化 git**

```bash
cd /Users/wyx/Documents/GitHub/GKHUBS-forum
git init
git branch -m main
```

- [ ] **步骤 2：写 `.gitignore`**

```gitignore
# OS / IDE
.DS_Store
.idea/
.vscode/

# Vendor / build artifacts
node_modules/
__pycache__/
*.pyc
.next/
dist/
build/

# 构建时下载的插件
cms/plugins-vendor/*
!cms/plugins-vendor/.gitignore

# 本地环境
.env
.env.local
*.local

# 日志
*.log
```

- [ ] **步骤 3：写 `README.md`**

```markdown
# gkhubs.com 聚合站

GK 手办信息聚合站。设计文档见 `docs/superpowers/specs/`，实施计划见 `docs/superpowers/plans/`。

## 服务
- `cms/` —— Headless WordPress
- `web/` —— Next.js 前端（Plan 4）
- `crawler/` —— Python CLI（Plan 3）
- `watermark/` —— IOPaint 服务（Plan 2）

## 快速开始
看 `docs/superpowers/plans/` 里的当前 plan。
```

- [ ] **步骤 4：写 `Makefile`**

```makefile
.PHONY: cms-build cms-test cms-smoke

cms-build:
	docker build --target test -t gkhubs-cms:test cms
	docker build --target runtime -t gkhubs-cms:dev cms

cms-test: cms-build
	@echo "static checks passed in build stage"

cms-smoke: cms-build
	bash cms/tests/smoke.sh
```

- [ ] **步骤 5：验证并提交**

```bash
git add README.md .gitignore Makefile cms/.gitkeep
git commit -m "chore: 初始化仓库骨架"
```

预期：提交干净，`git status` 显示工作树为空。

---

## 任务 2：Apache vhost 与 healthz

**文件：**
- 创建：`cms/apache-vhost.conf`
- 创建：`cms/healthz.php`

- [ ] **步骤 1：写 `cms/apache-vhost.conf`**

```apache
<VirtualHost *:80>
    ServerName cms.gkhubs.local
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # 关闭 uploads 目录列表（反正图片都在 R2）
    <Directory /var/www/html/wp-content/uploads>
        Options -Indexes
    </Directory>

    # 健康端点 —— 完全绕过 WP，保证低延迟
    Alias /healthz /var/www/html/healthz.php
    <Location /healthz>
        Require all granted
    </Location>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

- [ ] **步骤 2：写 `cms/healthz.php`**

```php
<?php
// 独立运行 —— 不引导 WP，用于 Dokploy 健康检查。
// 即使 WP 慢，也必须 100ms 内返回。
header('Content-Type: application/json');
$db_ok = false;
$error = null;
try {
    $host = getenv('WORDPRESS_DB_HOST') ?: 'gkhubs-cms-db';
    $name = getenv('WORDPRESS_DB_NAME') ?: 'wordpress';
    $user = getenv('WORDPRESS_DB_USER') ?: 'wordpress';
    $pass = getenv('WORDPRESS_DB_PASSWORD') ?: '';
    $pdo = new PDO("mysql:host=$host;dbname=$name", $user, $pass, [
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->query('SELECT 1');
    $db_ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
http_response_code($db_ok ? 200 : 503);
echo json_encode(['ok' => $db_ok, 'error' => $error]);
```

- [ ] **步骤 3：提交**

```bash
git add cms/apache-vhost.conf cms/healthz.php
git commit -m "feat(cms): apache vhost 与独立 healthz 端点"
```

---

## 任务 3：静态检查脚本（在 Dockerfile test 阶段调用）

**文件：**
- 创建：`cms/tests/static-check.sh`

- [ ] **步骤 1：先写检查脚本**

```bash
#!/usr/bin/env bash
# cms/tests/static-check.sh —— 在 Docker test 阶段内运行，无网络/无 DB 依赖。
set -euo pipefail

echo "==> PHP 语法检查"
find mu-plugins healthz.php -type f -name "*.php" -print0 \
  | xargs -0 -n1 php -l

echo "==> Bash 语法检查"
find tests -type f -name "*.sh" -print0 \
  | xargs -0 -n1 bash -n

echo "==> 至少要有一个 mu-plugin"
test -n "$(find mu-plugins -maxdepth 1 -name '*.php' -print -quit)" \
  || { echo "FAIL: mu-plugins/ 没有 PHP 文件"; exit 1; }

echo "==> JSON fixture 验证"
for f in tests/fixtures/*.json; do
    [ -f "$f" ] || continue
    python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$f"
done

echo "==> 静态检查全部通过"
```

- [ ] **步骤 2：加可执行权限**

```bash
chmod +x cms/tests/static-check.sh
```

- [ ] **步骤 3：本地运行验证（应当失败 —— 还没 mu-plugins）**

```bash
cd cms && bash tests/static-check.sh; cd ..
```

预期：因为 `mu-plugins/` 还没建/还没 PHP 文件而失败。**这就证明检查有效** —— 任务 5+ 创建 mu-plugins 后会重跑。

- [ ] **步骤 4：提交**

```bash
git add cms/tests/static-check.sh
git commit -m "test(cms): Docker test 阶段用的静态检查脚本"
```

---

## 任务 4：多阶段 Dockerfile（骨架）

**文件：**
- 创建：`cms/Dockerfile`
- 创建：`cms/mu-plugins/.gitkeep`
- 创建：`cms/tests/fixtures/.gitkeep`
- 创建：`cms/plugins-vendor/.gitignore`

- [ ] **步骤 1：写多阶段 Dockerfile**

```dockerfile
# syntax=docker/dockerfile:1.6

# ---- builder：下载外部插件 ----
FROM alpine:3.19 AS builder
RUN apk add --no-cache curl unzip ca-certificates
WORKDIR /vendor
ARG MEDIA_CLOUD_VERSION=4.6.4
RUN curl -fsSL -o ilab-media-tools.zip \
      "https://downloads.wordpress.org/plugin/ilab-media-tools.${MEDIA_CLOUD_VERSION}.zip" \
 && unzip -q ilab-media-tools.zip \
 && rm ilab-media-tools.zip

# ---- test：静态检查（任何一处错就 fail build） ----
FROM wordpress:6.5-apache AS test
RUN apt-get update && apt-get install -y --no-install-recommends \
      python3 bash file \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /work
COPY mu-plugins ./mu-plugins
COPY healthz.php ./healthz.php
COPY tests ./tests
RUN chmod +x tests/*.sh && bash tests/static-check.sh

# ---- runtime：实际部署的镜像 ----
FROM wordpress:6.5-apache AS runtime

# 装 wp-cli（gkhubs-init.sh 与 smoke 测试都依赖它）
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates less mariadb-client \
 && curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY mu-plugins/ /var/www/html/wp-content/mu-plugins/
COPY --from=builder /vendor/ilab-media-tools /var/www/html/wp-content/plugins/ilab-media-tools
COPY healthz.php /var/www/html/healthz.php
COPY docker-entrypoint-init.sh /usr/local/bin/gkhubs-init.sh
RUN chmod +x /usr/local/bin/gkhubs-init.sh \
 && a2enmod rewrite headers \
 && chown -R www-data:www-data /var/www/html

# 包装官方 entrypoint：
#   - Apache 占据 PID 1（保证信号转发正常）
#   - 后台 watcher 轮询 /healthz；一旦返回 200 就跑一次 init
COPY <<'EOF' /usr/local/bin/gkhubs-entrypoint.sh
#!/usr/bin/env bash
set -e
(
    for i in $(seq 1 120); do
        if curl -sf http://localhost/healthz >/dev/null 2>&1; then
            /usr/local/bin/gkhubs-init.sh 2>&1 | sed 's/^/[gkhubs-init] /' || true
            break
        fi
        sleep 2
    done
) &
exec docker-entrypoint.sh apache2-foreground
EOF
RUN chmod +x /usr/local/bin/gkhubs-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/gkhubs-entrypoint.sh"]
```

- [ ] **步骤 2：占位 `docker-entrypoint-init.sh`**

```bash
#!/usr/bin/env bash
# cms/docker-entrypoint-init.sh
# 幂等的首次启动 bootstrap，可放心重跑。
set -euo pipefail
echo "[gkhubs-init] start"
echo "[gkhubs-init] (占位 —— 任务 10 才填实际逻辑)"
echo "[gkhubs-init] done"
```

- [ ] **步骤 3：占位 `cms/plugins-vendor/.gitignore`**

```
*
!.gitignore
```

- [ ] **步骤 4：构建（test 阶段会失败 —— 还没 mu-plugins）**

```bash
make cms-build
```

预期：因为 `mu-plugins/` 只有 `.gitkeep`，`static-check.sh` 在"至少要有一个 mu-plugin"那一步失败。**好 —— 红绿门控生效。**

- [ ] **步骤 5：加一个最小占位 mu-plugin 让 build 通过**

写 `cms/mu-plugins/00-gkhubs-cpt.php`：

```php
<?php
/**
 * Plugin Name: gkhubs CPT (placeholder)
 * Description: 任务 5 才注册 gk_figure / gk_listing
 */
defined('ABSPATH') || exit;
```

- [ ] **步骤 6：再次构建 —— 必须成功**

```bash
make cms-build
```

预期：`gkhubs-cms:test` 与 `gkhubs-cms:dev` 两个镜像都构建出来。

- [ ] **步骤 7：提交**

```bash
git add cms/Dockerfile cms/docker-entrypoint-init.sh \
        cms/plugins-vendor/.gitignore cms/mu-plugins/00-gkhubs-cpt.php \
        cms/mu-plugins/.gitkeep cms/tests/fixtures/.gitkeep
git commit -m "feat(cms): 多阶段 Dockerfile，含 test 门控与 Media Cloud 内置"
```

---

## 任务 5：注册 `gk_figure` CPT（TDD）

**文件：**
- 修改：`cms/mu-plugins/00-gkhubs-cpt.php`
- 修改：`cms/tests/smoke.sh`（新建）

- [ ] **步骤 1：写 `cms/tests/smoke.sh`，先包含 gk_figure 失败断言**

```bash
#!/usr/bin/env bash
# cms/tests/smoke.sh —— 用 docker network 跑完整集成测试
set -euo pipefail

NET="gkhubs-smoke-$$"
DB="gkhubs-smoke-db-$$"
WP="gkhubs-smoke-wp-$$"
DB_PASS="smoketest"

cleanup() {
    docker rm -f "$DB" "$WP" 2>/dev/null || true
    docker network rm "$NET" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> 构建 CMS 镜像"
docker build --target runtime -t gkhubs-cms:smoke cms

echo "==> 创建 docker network"
docker network create "$NET" >/dev/null

echo "==> 启动 MySQL"
docker run -d --name "$DB" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$DB_PASS" \
  -e MYSQL_DATABASE=wordpress \
  -e MYSQL_USER=wordpress \
  -e MYSQL_PASSWORD="$DB_PASS" \
  mysql:8.0 >/dev/null

echo -n "==> 等 MySQL 就绪"
for i in $(seq 1 60); do
    if docker exec "$DB" mysqladmin ping -uroot -p"$DB_PASS" --silent 2>/dev/null; then
        echo " OK"; break
    fi
    echo -n "."; sleep 1
    [ "$i" = "60" ] && { echo " TIMEOUT"; exit 1; }
done

echo "==> 启动 WP"
docker run -d --name "$WP" --network "$NET" \
  -e WORDPRESS_DB_HOST="$DB" \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
  -e WORDPRESS_DB_NAME=wordpress \
  -p 18080:80 \
  gkhubs-cms:smoke >/dev/null

echo -n "==> 等 WP 健康"
for i in $(seq 1 90); do
    if curl -sf http://localhost:18080/healthz >/dev/null 2>&1; then
        echo " OK"; break
    fi
    echo -n "."; sleep 1
    [ "$i" = "90" ] && { echo " TIMEOUT"; docker logs "$WP" | tail -50; exit 1; }
done

echo "==> 触发 WP 安装（匿名 GET 让 WP 创表）"
curl -sf -L http://localhost:18080/wp-admin/install.php >/dev/null

echo "==> wp-cli 静默安装"
docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root core install \
  --url=http://localhost:18080 \
  --title=gkhubs \
  --admin_user=admin \
  --admin_password=admintest \
  --admin_email=admin@example.com \
  --skip-email

echo "==> 断言 gk_figure CPT 存在"
RESP=$(curl -sf "http://localhost:18080/wp-json/wp/v2/types/gk_figure" || true)
if [ -z "$RESP" ]; then
    echo "FAIL: 未找到 gk_figure CPT"; exit 1
fi
echo "$RESP" | grep -q '"slug":"gk_figure"' || { echo "FAIL: 响应结构: $RESP"; exit 1; }

echo "==> 断言 gk_listing CPT 存在"
RESP=$(curl -sf "http://localhost:18080/wp-json/wp/v2/types/gk_listing" || true)
if [ -z "$RESP" ]; then
    echo "FAIL: 未找到 gk_listing CPT"; exit 1
fi
echo "$RESP" | grep -q '"slug":"gk_listing"' || { echo "FAIL: 响应结构: $RESP"; exit 1; }

echo "==> Smoke OK"
```

```bash
chmod +x cms/tests/smoke.sh
```

- [ ] **步骤 2：跑 smoke —— gk_figure 断言会失败**

```bash
make cms-smoke
```

预期：WP 安装通过，但在"断言 gk_figure CPT 存在"失败，因为 mu-plugin 还是占位。

- [ ] **步骤 3：在 `cms/mu-plugins/00-gkhubs-cpt.php` 实现 `gk_figure` 注册**

```php
<?php
/**
 * Plugin Name: gkhubs CPT
 * Description: figure 与 listing 的自定义文章类型
 */
defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('gk_figure', [
        'label' => 'Figures',
        'labels' => [
            'name' => 'Figures',
            'singular_name' => 'Figure',
        ],
        // Headless：Next.js 是唯一的渲染端。关掉 WP 主题渲染单页/归档，
        // 保证搜索引擎不会看到 WP 风格的 URL。
        'public' => false,
        'show_ui' => true,              // wp-admin 里仍可编辑
        'show_in_menu' => true,
        'show_in_rest' => true,
        'rest_base' => 'figures',
        'has_archive' => false,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'taxonomies' => ['gk_ip', 'gk_character', 'gk_studio'],
    ]);

    foreach (['gk_ip' => 'IPs', 'gk_character' => 'Characters', 'gk_studio' => 'Studios'] as $tax => $label) {
        register_taxonomy($tax, 'gk_figure', [
            'label' => $label,
            'show_in_rest' => true,
            'hierarchical' => false,
            // editor 角色（bot 用户）必须能通过 REST 创建/挂 term。
            // 默认 caps 是 admin-only（manage_categories），upsert 时 wp_set_object_terms() 会静默失败。
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms'   => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);
    }

    // 通过 REST 暴露的 meta 字段
    foreach ([
        'scale' => 'string',
        'material' => 'string',
        'release_date' => 'string',
        'msrp' => 'number',
        'msrp_currency' => 'string',
        'status' => 'string',
        'canonical_listing_id' => 'integer',
    ] as $key => $type) {
        register_post_meta('gk_figure', $key, [
            'type' => $type,
            'single' => true,
            'show_in_rest' => true,
        ]);
    }
});
```

- [ ] **步骤 4：再跑 smoke —— gk_figure 通过，gk_listing 仍失败**

```bash
make cms-smoke
```

预期：gk_figure 通过，gk_listing 失败。

- [ ] **步骤 5：先提交（只 figure，listing 下一任务）**

```bash
git add cms/mu-plugins/00-gkhubs-cpt.php cms/tests/smoke.sh
git commit -m "feat(cms): 注册 gk_figure CPT 与 smoke 测试"
```

---

## 任务 6：注册 `gk_listing` CPT（TDD）

**文件：**
- 修改：`cms/mu-plugins/00-gkhubs-cpt.php`

- [ ] **步骤 1：再跑 smoke 确认 gk_listing 断言失败**

```bash
make cms-smoke
```

预期：在 gk_listing 那步失败。

- [ ] **步骤 2：在同一个 `add_action('init', ...)` 回调里追加 `gk_listing`**

```php
register_post_type('gk_listing', [
    'label' => 'Listings',
    'labels' => ['name' => 'Listings', 'singular_name' => 'Listing'],
    'public' => false,                   // 永不前台展示
    'show_in_rest' => true,
    'rest_base' => 'listings',
    'show_ui' => true,                   // wp-admin 可编辑
    'show_in_menu' => true,
    'supports' => ['title', 'thumbnail', 'custom-fields'],
    'taxonomies' => ['gk_shop'],
]);

register_taxonomy('gk_shop', 'gk_listing', [
    'label' => 'Shops',
    'show_in_rest' => true,
    'hierarchical' => false,
    'capabilities' => [
        'manage_terms' => 'edit_posts',
        'edit_terms'   => 'edit_posts',
        'delete_terms' => 'edit_posts',
        'assign_terms' => 'edit_posts',
    ],
]);

foreach ([
    'shop_listing_url' => 'string',
    'price_current' => 'number',
    'price_currency' => 'string',
    'stock_status' => 'string',
    'listing_type' => 'string',
    'ship_from' => 'string',
    'fetched_at' => 'string',
    'gk_figure_ref' => 'integer',
    'match_confidence' => 'number',
    'raw_payload' => 'string',
] as $key => $type) {
    register_post_meta('gk_listing', $key, [
        'type' => $type,
        'single' => true,
        'show_in_rest' => true,
    ]);
}
```

- [ ] **步骤 3：在 `shop_listing_url` 上加查询索引**（mu-plugin 没有 `register_activation_hook` 时机，所以用 `init` action + 一次性 flag）

继续在同一文件追加：

```php
add_action('init', function () {
    if (get_option('gkhubs_listing_url_index_v1')) return;
    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = %s
           AND index_name = 'gkhubs_listing_url_idx'",
        $wpdb->postmeta
    ));
    if ((int) $existing === 0) {
        $created = $wpdb->query(
            "CREATE INDEX gkhubs_listing_url_idx ON {$wpdb->postmeta} (meta_key(20), meta_value(64))"
        );
        if ($created === false) return;   // 创建失败 → 不打 done flag，下次启动重试
    }
    update_option('gkhubs_listing_url_index_v1', 1);
}, 20);
```

> 说明：WP postmeta 索引按字符串前缀，这个索引能让 `shop_listing_url` 查得快一点。真正的唯一性是在应用层（REST upsert 先查后写）保证。`(meta_value(64))` 取 64 字符前缀偏保守 —— 大部分 URL 头 64 字符就能区分开。

- [ ] **步骤 4：再跑 smoke —— 必须通过**

```bash
make cms-smoke
```

预期：所有断言通过。

- [ ] **步骤 5：提交**

```bash
git add cms/mu-plugins/00-gkhubs-cpt.php
git commit -m "feat(cms): 注册 gk_listing CPT + meta + 查询索引"
```

---

## 任务 7：自定义 REST `upsert-figure`（TDD）

**文件：**
- 创建：`cms/mu-plugins/01-gkhubs-rest.php`
- 创建：`cms/tests/fixtures/figure-sample.json`
- 修改：`cms/tests/smoke.sh`

- [ ] **步骤 1：加 fixture**

```json
{
  "slug": "demoncore-tanjiro-1-7",
  "title": "炭治郎 1/7",
  "ip": "鬼灭之刃",
  "character": "竈門炭治郎",
  "studio": "Demoncore Studio",
  "scale": "1/7",
  "material": "PVC 完成品",
  "release_date": "2026-08",
  "msrp": 1280,
  "msrp_currency": "CNY",
  "status": "预订"
}
```

- [ ] **步骤 2：在 `smoke.sh` 追加失败断言**（在 "Smoke OK" 之前）

```bash
echo "==> 给 admin 生成 App Password"
APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create admin smoke --porcelain)

echo "==> POST /upsert-figure"
RESP=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-figure" \
    --data @cms/tests/fixtures/figure-sample.json)
echo "$RESP" | grep -q '"id":' || { echo "FAIL: upsert-figure response: $RESP"; exit 1; }
FIGURE_ID=$(echo "$RESP" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

echo "==> 幂等性：同 slug → 同 id"
RESP2=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-figure" \
    --data @cms/tests/fixtures/figure-sample.json)
ID2=$(echo "$RESP2" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
[ "$FIGURE_ID" = "$ID2" ] || { echo "FAIL: 幂等失败，第一次 $FIGURE_ID 第二次 $ID2"; exit 1; }
```

- [ ] **步骤 3：跑 smoke —— 因端点不存在而失败**

```bash
make cms-smoke
```

预期：`/wp-json/gkhubs/v1/upsert-figure` 404。

- [ ] **步骤 4：实现 `cms/mu-plugins/01-gkhubs-rest.php`**

```php
<?php
/**
 * Plugin Name: gkhubs REST
 * Description: 给爬虫写入用的自定义 REST 端点
 */
defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/upsert-figure', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => 'gkhubs_upsert_figure',
    ]);
});

function gkhubs_upsert_figure(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (empty($body['slug']) || empty($body['title'])) {
        return new WP_Error('bad_request', 'slug 和 title 必填', ['status' => 400]);
    }

    // 幂等键：slug
    $existing = get_page_by_path($body['slug'], OBJECT, 'gk_figure');
    $post_id = $existing ? $existing->ID : 0;

    $args = [
        'ID' => $post_id,
        'post_type' => 'gk_figure',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field($body['title']),
        'post_name' => sanitize_title($body['slug']),
        'post_content' => isset($body['description']) ? wp_kses_post($body['description']) : '',
    ];
    $post_id = $post_id ? wp_update_post($args, true) : wp_insert_post($args, true);
    if (is_wp_error($post_id)) return $post_id;

    // 分类法
    foreach (['ip' => 'gk_ip', 'character' => 'gk_character', 'studio' => 'gk_studio'] as $key => $tax) {
        if (!empty($body[$key])) {
            wp_set_object_terms($post_id, [sanitize_text_field($body[$key])], $tax, false);
        }
    }

    // Meta
    foreach (['scale','material','release_date','msrp','msrp_currency','status','canonical_listing_id'] as $k) {
        if (array_key_exists($k, $body)) {
            update_post_meta($post_id, $k, $body[$k]);
        }
    }

    return [
        'id' => (int) $post_id,
        'slug' => get_post_field('post_name', $post_id),
    ];
}
```

- [ ] **步骤 5：跑 smoke —— 必须通过**

```bash
make cms-smoke
```

预期：upsert-figure 200 带 id，幂等检查通过。

- [ ] **步骤 6：提交**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/fixtures/figure-sample.json cms/tests/smoke.sh
git commit -m "feat(cms): REST upsert-figure，按 slug 幂等"
```

---

## 任务 8：自定义 REST `upsert-listing`（TDD）

**文件：**
- 修改：`cms/mu-plugins/01-gkhubs-rest.php`
- 创建：`cms/tests/fixtures/listing-sample.json`
- 修改：`cms/tests/smoke.sh`

- [ ] **步骤 1：加 fixture `listing-sample.json`**

```json
{
  "title": "[Demoncore] 炭治郎 1/7 PVC 预订",
  "shop": "favorgk",
  "shop_listing_url": "https://favorgk.com/products/demoncore-tanjiro-17",
  "price_current": 1180,
  "price_currency": "CNY",
  "stock_status": "pre_order",
  "listing_type": "new",
  "ship_from": "上海",
  "fetched_at": "2026-04-30T12:00:00Z",
  "gk_figure_slug": "demoncore-tanjiro-1-7"
}
```

- [ ] **步骤 2：在 `smoke.sh` 追加失败断言**

```bash
echo "==> POST /upsert-listing"
RESP=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-listing" \
    --data @cms/tests/fixtures/listing-sample.json)
echo "$RESP" | grep -q '"id":' || { echo "FAIL: upsert-listing: $RESP"; exit 1; }
LISTING_ID=$(echo "$RESP" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

echo "==> 幂等性：同 shop_listing_url → 同 id"
RESP2=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-listing" \
    --data @cms/tests/fixtures/listing-sample.json)
ID2=$(echo "$RESP2" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
[ "$LISTING_ID" = "$ID2" ] || { echo "FAIL: listing 幂等失败"; exit 1; }

echo "==> 验证 figure_ref 已挂上"
LINKED_FIGURE=$(curl -sf "http://localhost:18080/wp-json/wp/v2/listings/$LISTING_ID" \
    | python3 -c 'import json,sys;print(json.load(sys.stdin)["meta"]["gk_figure_ref"])')
[ "$LINKED_FIGURE" = "$FIGURE_ID" ] || { echo "FAIL: figure_ref 期望 $FIGURE_ID 实际 $LINKED_FIGURE"; exit 1; }
```

- [ ] **步骤 3：跑 smoke —— 失败（端点不存在）**

```bash
make cms-smoke
```

- [ ] **步骤 4：在 `cms/mu-plugins/01-gkhubs-rest.php` 追加 handler**

```php
add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/upsert-listing', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => 'gkhubs_upsert_listing',
    ]);
});

function gkhubs_upsert_listing(WP_REST_Request $req) {
    $body = $req->get_json_params();
    foreach (['shop', 'shop_listing_url', 'title', 'price_current', 'price_currency', 'stock_status', 'fetched_at'] as $k) {
        if (!isset($body[$k]) || $body[$k] === '') {
            return new WP_Error('bad_request', "缺少字段: $k", ['status' => 400]);
        }
    }

    // 幂等键：shop_listing_url meta
    $existing = get_posts([
        'post_type' => 'gk_listing',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_query' => [[
            'key' => 'shop_listing_url',
            'value' => $body['shop_listing_url'],
            'compare' => '=',
        ]],
        'fields' => 'ids',
    ]);
    $post_id = $existing[0] ?? 0;

    $args = [
        'ID' => $post_id,
        'post_type' => 'gk_listing',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field($body['title']),
    ];
    $post_id = $post_id ? wp_update_post($args, true) : wp_insert_post($args, true);
    if (is_wp_error($post_id)) return $post_id;

    // shop 分类法
    wp_set_object_terms($post_id, [sanitize_text_field($body['shop'])], 'gk_shop', false);

    // 用 slug 反查 figure_ref
    $figure_ref = 0;
    if (!empty($body['gk_figure_slug'])) {
        $fig = get_page_by_path($body['gk_figure_slug'], OBJECT, 'gk_figure');
        if ($fig) $figure_ref = (int) $fig->ID;
    } elseif (!empty($body['gk_figure_ref'])) {
        $figure_ref = (int) $body['gk_figure_ref'];
    }
    if ($figure_ref) update_post_meta($post_id, 'gk_figure_ref', $figure_ref);

    // 其他 meta
    foreach ([
        'shop_listing_url','price_current','price_currency','stock_status',
        'listing_type','ship_from','fetched_at','match_confidence','raw_payload'
    ] as $k) {
        if (array_key_exists($k, $body)) update_post_meta($post_id, $k, $body[$k]);
    }

    return [
        'id' => (int) $post_id,
        'figure_ref' => $figure_ref,
    ];
}
```

- [ ] **步骤 5：跑 smoke —— 必须通过**

```bash
make cms-smoke
```

- [ ] **步骤 6：提交**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/fixtures/listing-sample.json cms/tests/smoke.sh
git commit -m "feat(cms): REST upsert-listing，按 shop_listing_url 幂等 + figure 关联"
```

---

## 任务 9：自定义 REST `figures-with-listings`（读端点）

**文件：**
- 修改：`cms/mu-plugins/01-gkhubs-rest.php`
- 修改：`cms/tests/smoke.sh`

- [ ] **步骤 1：在 smoke 追加失败断言**

```bash
echo "==> GET /figures-with-listings"
RESP=$(curl -sf "http://localhost:18080/wp-json/gkhubs/v1/figures-with-listings?per_page=10")
COUNT=$(echo "$RESP" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["items"]))')
[ "$COUNT" -ge 1 ] || { echo "FAIL: 期望 ≥1 个 figure，实际 $COUNT"; exit 1; }
echo "$RESP" | grep -q "\"shop_listing_url\":\"https://favorgk.com" || { echo "FAIL: listing 没嵌入"; exit 1; }
```

- [ ] **步骤 2：跑 smoke —— 失败（端点不存在）**

- [ ] **步骤 3：追加 handler**

```php
add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/figures-with-listings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',   // 公开读
        'callback' => 'gkhubs_figures_with_listings',
        'args' => [
            'per_page' => ['default' => 20, 'sanitize_callback' => 'absint'],
            'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
        ],
    ]);
});

function gkhubs_figures_with_listings(WP_REST_Request $req) {
    $per_page = min(50, max(1, (int) $req->get_param('per_page')));
    $page = max(1, (int) $req->get_param('page'));

    $q = new WP_Query([
        'post_type' => 'gk_figure',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    $items = [];
    foreach ($q->posts as $p) {
        $listings = get_posts([
            'post_type' => 'gk_listing',
            'post_status' => 'publish',
            'numberposts' => 20,
            'meta_query' => [[
                'key' => 'gk_figure_ref',
                'value' => (string) $p->ID,
                'compare' => '=',
            ]],
        ]);
        $listing_payload = array_map(function ($l) {
            return [
                'id' => $l->ID,
                'title' => $l->post_title,
                'shop' => wp_get_post_terms($l->ID, 'gk_shop', ['fields' => 'names'])[0] ?? null,
                'shop_listing_url' => get_post_meta($l->ID, 'shop_listing_url', true),
                'price_current' => (float) get_post_meta($l->ID, 'price_current', true),
                'price_currency' => get_post_meta($l->ID, 'price_currency', true),
                'stock_status' => get_post_meta($l->ID, 'stock_status', true),
                'fetched_at' => get_post_meta($l->ID, 'fetched_at', true),
            ];
        }, $listings);

        $items[] = [
            'id' => $p->ID,
            'slug' => $p->post_name,
            'title' => $p->post_title,
            'ip' => wp_get_post_terms($p->ID, 'gk_ip', ['fields' => 'names'])[0] ?? null,
            'character' => wp_get_post_terms($p->ID, 'gk_character', ['fields' => 'names'])[0] ?? null,
            'studio' => wp_get_post_terms($p->ID, 'gk_studio', ['fields' => 'names'])[0] ?? null,
            'scale' => get_post_meta($p->ID, 'scale', true),
            'material' => get_post_meta($p->ID, 'material', true),
            'release_date' => get_post_meta($p->ID, 'release_date', true),
            'msrp' => (float) get_post_meta($p->ID, 'msrp', true),
            'msrp_currency' => get_post_meta($p->ID, 'msrp_currency', true),
            'status' => get_post_meta($p->ID, 'status', true),
            'listings' => $listing_payload,
        ];
    }

    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $q->found_posts,
    ];
}
```

- [ ] **步骤 4：跑 smoke —— 必须通过**

- [ ] **步骤 5：提交**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/smoke.sh
git commit -m "feat(cms): REST figures-with-listings（公开读，分页）"
```

---

## 任务 10：bot 用户与 bootstrap 脚本

**文件：**
- 修改：`cms/docker-entrypoint-init.sh`
- 修改：`cms/tests/smoke.sh`

- [ ] **步骤 1：替换占位 `docker-entrypoint-init.sh`**

```bash
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
```

> **首次部署前的验证**：任务 14 部署完镜像后，SSH 上去跑 `docker exec gkhubs-cms wp --allow-root option list --search='mcloud-storage*' --format=table`。如果列不到任何 key，说明 Media Cloud 4.6.4 用的是别的前缀（历史上有 `ilab-media-*`）—— `grep -r 'storage-driver' /var/www/html/wp-content/plugins/ilab-media-tools` 找到正确的 key，更新 `gkhubs-init.sh` 后重新部署。

- [ ] **步骤 2：smoke 改用 bot 用户而非 admin 上传**

`smoke.sh` 里把 App Password 那一段改成：

```bash
echo "==> 在容器内跑 gkhubs-init.sh"
docker exec "$WP" bash /usr/local/bin/gkhubs-init.sh

echo "==> 给 bot 用户生成 App Password"
APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create crawler-bot smoke --porcelain)
# （后续改用 crawler-bot:$APP_PWD 而非 admin:$APP_PWD）
```

把所有 `-u "admin:$APP_PWD"` 替换成 `-u "crawler-bot:$APP_PWD"`。

- [ ] **步骤 3：跑 smoke —— 必须通过**

```bash
make cms-smoke
```

- [ ] **步骤 4：提交**

```bash
git add cms/docker-entrypoint-init.sh cms/tests/smoke.sh
git commit -m "feat(cms): bot 用户 bootstrap + smoke 改用 bot 而非 admin"
```

---

## 任务 11：R2 上传集成测试

R2 接线在 `gkhubs-init.sh`（任务 10 步骤 1）里 —— 通过 `wp option update` 写入。本任务只补 smoke 断言：在 R2 变量齐备时跑端到端上传。

**文件：**
- 修改：`cms/tests/smoke.sh`

- [ ] **步骤 1：在 smoke 加 R2 上传断言**

> R2 需要真凭据。如果测试环境里 `R2_ACCESS_KEY` 等没设，**跳过**断言（不要 fail）。计划里说明上线前必须用 R2 变量跑一次。

在 "Smoke OK" 前追加：

```bash
if [ -n "${R2_ACCESS_KEY:-}" ] && [ -n "${R2_SECRET:-}" ] && [ -n "${R2_BUCKET:-}" ] && [ -n "${R2_ENDPOINT:-}" ]; then
    echo "==> R2 变量齐备 —— 用 R2 env 重启 WP"
    docker rm -f "$WP" >/dev/null
    docker run -d --name "$WP" --network "$NET" \
      -e WORDPRESS_DB_HOST="$DB" \
      -e WORDPRESS_DB_USER=wordpress \
      -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
      -e WORDPRESS_DB_NAME=wordpress \
      -e R2_ENDPOINT="$R2_ENDPOINT" \
      -e R2_BUCKET="$R2_BUCKET" \
      -e R2_ACCESS_KEY="$R2_ACCESS_KEY" \
      -e R2_SECRET="$R2_SECRET" \
      -p 18080:80 \
      gkhubs-cms:smoke >/dev/null
    sleep 5
    docker exec "$WP" bash /usr/local/bin/gkhubs-init.sh

    echo "==> 上传测试图"
    APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create crawler-bot r2test --porcelain)
    # 1x1 px PNG
    printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\xfe\xa0\x9d\xe1\x00\x00\x00\x00IEND\xaeB`\x82' > /tmp/test.png
    UPLOAD=$(curl -sf -u "crawler-bot:$APP_PWD" \
        -H "Content-Disposition: attachment; filename=test.png" \
        -H "Content-Type: image/png" \
        --data-binary @/tmp/test.png \
        "http://localhost:18080/wp-json/wp/v2/media")
    URL=$(echo "$UPLOAD" | python3 -c 'import json,sys;print(json.load(sys.stdin)["source_url"])')
    echo "上传 URL: $URL"
    # Media Cloud 应当把 URL 重写到 R2 endpoint
    echo "$URL" | grep -q "$R2_BUCKET" || { echo "FAIL: URL 不在 R2 上: $URL"; exit 1; }
else
    echo "==> R2 变量缺失 —— 跳过 R2 上传测试（部署前请手动跑一次）"
fi
```

- [ ] **步骤 2：本地跑（不带 R2）**

```bash
make cms-smoke
```

预期：通过；R2 块跳过并打印提示。

- [ ] **步骤 3：本地带 R2 跑（部署前推荐）**

```bash
R2_ENDPOINT=https://<acct>.r2.cloudflarestorage.com \
R2_BUCKET=gkhubs-media \
R2_ACCESS_KEY=... \
R2_SECRET=... \
make cms-smoke
```

预期：R2 块执行，上传 URL 包含桶名。**如果失败，参考任务 10 里的 option key 验证步骤** —— 在容器里跑 `option list --search` 找到 Media Cloud 4.6.4 的真实 key 名。

- [ ] **步骤 4：提交**

```bash
git add cms/tests/smoke.sh
git commit -m "test(cms): R2 上传 smoke（无变量时跳过）"
```

---

## 任务 12：REST 健康端点（给 Dokploy 用）

**文件：**
- 创建：`cms/mu-plugins/99-gkhubs-health.php`

- [ ] **步骤 1：在 smoke 加失败断言**

```bash
echo "==> GET /wp-json/gkhubs/v1/health"
RESP=$(curl -sf "http://localhost:18080/wp-json/gkhubs/v1/health")
echo "$RESP" | grep -q '"ok":true' || { echo "FAIL: health: $RESP"; exit 1; }
```

- [ ] **步骤 2：跑 smoke —— 失败**

- [ ] **步骤 3：实现端点**

```php
<?php
/**
 * Plugin Name: gkhubs Health
 * Description: 含 WP + DB + plugin 检查的 REST 健康端点
 */
defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/health', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            global $wpdb;
            $db_ok = (bool) $wpdb->get_var('SELECT 1');
            $cpts_ok = post_type_exists('gk_figure') && post_type_exists('gk_listing');
            $r2_configured = (bool) (getenv('R2_BUCKET'));
            return [
                'ok' => $db_ok && $cpts_ok,
                'wp_version' => get_bloginfo('version'),
                'db' => $db_ok,
                'cpts' => $cpts_ok,
                'r2_configured' => $r2_configured,
            ];
        },
    ]);
});
```

- [ ] **步骤 4：跑 smoke —— 必须通过**

- [ ] **步骤 5：提交**

```bash
git add cms/mu-plugins/99-gkhubs-health.php cms/tests/smoke.sh
git commit -m "feat(cms): /wp-json/gkhubs/v1/health 端点"
```

---

## 任务 13：推代码 + 创建 Dokploy MySQL 应用

**前置条件：** Dokploy API key 已就绪（用户给过的，不在这里复读）。

- [ ] **步骤 1：推送到 git remote**

```bash
git remote -v
# 还没 remote 的话，用户在 GitHub 创 gkhubs-forum 仓库，然后:
# git remote add origin git@github.com:USER/gkhubs-forum.git
# git push -u origin main
```

> 如果你倾向直接把仓库挂到 Dokploy（Dokploy 自带 git 拉取），用 Dokploy 提供的仓库 URL。

- [ ] **步骤 2：用 Dokploy skill 创建 MySQL 服务**

调用 `dp` skill（或在 Dokploy UI 手动操作）。所需配置：
- 应用名：`gkhubs-cms-db`
- 类型：Docker image
- 镜像：`mysql:8.0`
- 环境变量：
  - `MYSQL_ROOT_PASSWORD`（生成 32 位随机串，存进密码管理器）
  - `MYSQL_DATABASE=wordpress`
  - `MYSQL_USER=wordpress`
  - `MYSQL_PASSWORD`（生成 32 位随机串，存进密码管理器）
- 持久卷：`/var/lib/mysql` → 20GB
- 网络：默认 Dokploy network
- 不暴露公网端口

- [ ] **步骤 3：验证 MySQL 起来**

在 Dokploy 主机：
```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms-db mysqladmin ping -uroot -p<root_pass>"
```

预期：`mysqld is alive`。

- [ ] **步骤 4：把连接变量记进密码管理器**

记录：
- `WORDPRESS_DB_HOST=gkhubs-cms-db`（Dokploy 网络里的 Docker 服务名）
- `WORDPRESS_DB_NAME=wordpress`
- `WORDPRESS_DB_USER=wordpress`
- `WORDPRESS_DB_PASSWORD=<已存>`

---

## 任务 14：创建 Dokploy CMS 应用

- [ ] **步骤 1：创建 Dokploy 应用**

通过 Dokploy skill 或 UI：
- 应用名：`gkhubs-cms`
- 构建：Git source，本仓库，build context = `cms/`
- Dockerfile 路径：`Dockerfile`
- Build target：`runtime`
- 端口：80 → Dokploy 反向代理对外
- 域名：`cms.gkhubs.com`（Phase 5 才接 DNS；先用 Dokploy 自动生成的子域名）
- 健康检查：`GET /healthz` interval 30s timeout 5s
- 环境变量：
  - `WORDPRESS_DB_HOST=gkhubs-cms-db`
  - `WORDPRESS_DB_NAME=wordpress`
  - `WORDPRESS_DB_USER=wordpress`
  - `WORDPRESS_DB_PASSWORD=<从密码管理器取>`
  - `WORDPRESS_TABLE_PREFIX=gk_`
  - `R2_ENDPOINT=<R2 控制台>`
  - `R2_BUCKET=gkhubs-media`
  - `R2_ACCESS_KEY=<R2>`
  - `R2_SECRET=<R2>`
  - `GKHUBS_BOT_USER=crawler-bot`
  - `GKHUBS_BOT_EMAIL=crawler@gkhubs.local`

- [ ] **步骤 2：触发首次部署**

推一个空提交，或在 Dokploy 点 "Deploy"。看构建日志：
- test 阶段必须通过（static-check.sh 跑过）
- runtime 镜像启动
- 60 秒内健康检查通过

- [ ] **步骤 3：浏览器跑一次 wp-admin 安装**

打开 Dokploy 提供的 HTTPS URL → wp-admin 安装页 → 填：
- 站点标题：`gkhubs CMS`
- 管理员用户：`admin`
- 管理员密码：从密码管理器取（预检 P-3）
- 邮箱：你自己的
- 搜索引擎可见性：**勾上"discourage"**（headless 站，永远不希望被索引）

- [ ] **步骤 3.5：手动触发 gkhubs-init.sh（创建 bot 用户）**

entrypoint 里的后台 watcher 只在容器启动时跑一次（那时 WP 还没装），所以手动安装完后要显式跑一次 init：

```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms /usr/local/bin/gkhubs-init.sh"
```

预期输出包含 `创建 bot 用户 crawler-bot`，以及（若 R2 变量已设）`配置 R2 offload`。验证：

```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms wp --allow-root user list --role=editor --field=user_login"
```

应当打印 `crawler-bot`。

- [ ] **步骤 4：从公网验证端点**

```bash
curl -sf https://<dokploy-cms-url>/healthz | jq .
curl -sf https://<dokploy-cms-url>/wp-json/gkhubs/v1/health | jq .
```

两个都要返回 `"ok": true`。

- [ ] **步骤 5：在 wp-admin 给 crawler-bot 生成 App Password**

wp-admin → Users → crawler-bot → Application Passwords → Add。把生成的值存到密码管理器，命名 `GKHUBS_CRAWLER_APP_PASSWORD` —— Plan 3 会用到。

- [ ] **步骤 6：生产环境端到端 smoke**

```bash
APP_PWD=<从密码管理器取>
URL=https://<dokploy-cms-url>

curl -sf -u "crawler-bot:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "$URL/wp-json/gkhubs/v1/upsert-figure" \
    -d @cms/tests/fixtures/figure-sample.json | jq .

curl -sf "$URL/wp-json/gkhubs/v1/figures-with-listings" | jq '.items | length'
```

预期：figure 创建成功（id 非零）且能在 figures-with-listings 列表里看到。

- [ ] **步骤 7：提交部署文档**

创建 `cms/DEPLOY.md`，记录：
- Dokploy 里的应用名
- 环境变量参考（只列 key，不写 secret）
- 重新部署的方式（`git push` 触发 auto-deploy）
- 各 secret 在密码管理器的条目名
- 怎么看日志（Dokploy UI → 应用 → 日志）

```bash
git add cms/DEPLOY.md
git commit -m "docs(cms): 部署运维手册"
```

---

## 完成标准

- [ ] 任务 1–12 每个至少在 `main` 上产出一个 commit（任务 13 仅是 Dokploy 操作，无 commit；任务 14 末尾产出一个 commit：`cms/DEPLOY.md`）
- [ ] `make cms-smoke` 在不带 R2 变量时通过（R2 块跳过）
- [ ] `make cms-smoke` 在**带** R2 变量时通过（R2 上传断言成功）
- [ ] `gkhubs-cms` 与 `gkhubs-cms-db` 在 Dokploy 上跑起来
- [ ] `https://<cms-url>/healthz` 返回 200
- [ ] `https://<cms-url>/wp-json/gkhubs/v1/health` 返回 `"ok": true`
- [ ] 生产端到端（任务 14 步骤 6）跑通
- [ ] crawler bot 的 App Password 已存进密码管理器
- [ ] 通过 REST 上传的图片落到 R2（URL 含桶名，不是本地 `/wp-content/uploads`）
- [ ] `cms/DEPLOY.md` 已 commit

---

## 执行时要调用的 skill

- @superpowers:test-driven-development —— 本计划每个任务都用红绿提交节奏
- @superpowers:verification-before-completion —— 每个任务声明完成前要先跑对应的 smoke 段
- `dp`（dokploy-deploy）—— 仅任务 13、14 真正动 Dokploy 时调用

## Plan 1 不包含的内容

- 水印服务（Plan 2）
- 爬虫（Plan 3）
- 前端（Plan 4）
- 域名切换、Cloudflare 配置、监控（Plan 5）
- 备份脚本（Plan 5）
