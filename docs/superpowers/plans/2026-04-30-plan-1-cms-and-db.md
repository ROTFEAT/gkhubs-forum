# Plan 1: CMS + DB — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy a Headless WordPress + MySQL stack to Dokploy (152.53.53.84) with two custom post types (`gk_figure`, `gk_listing`), three custom REST endpoints, R2 media offload, and a dedicated bot user — ready to receive crawler writes.

**Architecture:** Single-container WP image based on `wordpress:6.5-apache`, plus a separate `mysql:8.0` container. CPTs registered via must-use plugins (no UI plugin dependency). Custom REST endpoints in mu-plugin. First-run idempotent bootstrap script creates bot user + activates plugins. Tests embedded in Dockerfile multi-stage build (static checks) plus a `smoke.sh` integration test using docker network.

**Tech Stack:** WordPress 6.5, PHP 8.2, Apache, MySQL 8.0, Media Cloud (ILAB) plugin, Cloudflare R2 (S3 API), bash + curl + jq for tests.

**Spec reference:** [`docs/superpowers/specs/2026-04-30-gkhubs-aggregation-mvp-design.md`](../specs/2026-04-30-gkhubs-aggregation-mvp-design.md) — sections 3, 4.2, 4.3, 6.

**Server / credentials:**
- Dokploy host: `152.53.53.84`
- Dokploy API key: stored in user instruction (do not echo); retrieve from session memory or ask user
- R2 bucket: to be created — `gkhubs-media`
- DNS: `cms.gkhubs.com` will point to this service after Phase 5

---

## File Structure

After Plan 1, the repo looks like:

```
GKHUBS-forum/
├── README.md
├── .gitignore
├── Makefile                          # convenience targets (test, build)
├── cms/
│   ├── Dockerfile                    # multi-stage: builder → test → runtime
│   ├── apache-vhost.conf             # vhost tuned for headless (no public uploads dir)
│   ├── docker-entrypoint-init.sh     # idempotent first-run bootstrap
│   ├── healthz.php                   # standalone health endpoint
│   ├── mu-plugins/
│   │   ├── 00-gkhubs-cpt.php         # register gk_figure, gk_listing
│   │   ├── 01-gkhubs-rest.php        # custom REST endpoints
│   │   └── 99-gkhubs-health.php      # /wp-json/gkhubs/v1/health
│   ├── tests/
│   │   ├── smoke.sh                  # full integration test (mysql + wp + curl)
│   │   ├── static-check.sh           # called inside Dockerfile test stage
│   │   └── fixtures/
│   │       ├── figure-sample.json
│   │       └── listing-sample.json
│   └── plugins-vendor/
│       └── .gitignore                # downloaded at build, not committed
├── docs/
│   ├── superpowers/
│   │   ├── specs/  (existing)
│   │   └── plans/  (this file lives here)
└── (other service dirs added in later plans)
```

**Boundary notes:**
- `mu-plugins/*.php` — each file ≤ 200 lines, one concern per file
- `tests/smoke.sh` — does **not** depend on host's WP or DB; spins up everything in docker
- `docker-entrypoint-init.sh` — runs once on first boot, idempotent (re-runs on rebuild are no-ops)

---

## Pre-flight Checklist (do once, by user)

- [ ] **P-1**: Confirm Dokploy is reachable
  ```bash
  curl -sf -H "x-api-key: $DOKPLOY_API_KEY" https://152.53.53.84:3000/api/trpc/admin.getOne | jq .
  ```
  Expected: 200 with admin info JSON. If 401 → wrong key. If timeout → check server.

- [ ] **P-2**: Create R2 bucket `gkhubs-media` in Cloudflare dashboard
  - Settings → R2 → Create bucket → name `gkhubs-media`, location auto
  - Save: account ID, access key ID, secret access key, S3 API endpoint URL
  - Public access: **disabled** (we'll serve via Cloudflare CDN with a custom domain mapping later)

- [ ] **P-3**: Decide cms admin password (16+ chars, store in your password manager)

- [ ] **P-4**: Confirm Docker BuildKit is on
  ```bash
  docker buildx version
  ```
  Expected: prints version. If missing, install Docker Desktop ≥ 20.10 or `export DOCKER_BUILDKIT=1` for every build. The plan uses heredoc `COPY <<'EOF'` which requires BuildKit.

If any pre-flight fails, **stop and fix before continuing**.

> **Note on secret storage**: the plan refers to "1Password" as shorthand for your password manager. Substitute with whatever you actually use (Bitwarden, Apple Keychain, plain `~/.gkhubs-secrets.txt`, etc.) — the only requirement is that secrets don't end up in git.

---

## Task 1: Repo skeleton & root files

**Files:**
- Create: `README.md`
- Create: `.gitignore`
- Create: `Makefile`
- Create: `cms/.gitkeep`

- [ ] **Step 1: Initialize git**

```bash
cd /Users/wyx/Documents/GitHub/GKHUBS-forum
git init
git branch -m main
```

- [ ] **Step 2: Write `.gitignore`**

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

# Plugin downloads (re-fetched at build)
cms/plugins-vendor/*
!cms/plugins-vendor/.gitignore

# Local env
.env
.env.local
*.local

# Logs
*.log
```

- [ ] **Step 3: Write `README.md`**

```markdown
# gkhubs.com Aggregation Hub

GK figure information aggregation site. See `docs/superpowers/specs/` for design and `docs/superpowers/plans/` for implementation plans.

## Services
- `cms/` — Headless WordPress
- `web/` — Next.js frontend (Plan 4)
- `crawler/` — Python CLI (Plan 3)
- `watermark/` — IOPaint service (Plan 2)

## Quickstart
See current plan in `docs/superpowers/plans/`.
```

- [ ] **Step 4: Write `Makefile`**

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

- [ ] **Step 5: Verify and commit**

```bash
git add README.md .gitignore Makefile cms/.gitkeep
git commit -m "chore: initialize repo skeleton"
```

Expected: clean commit, `git status` shows clean tree.

---

## Task 2: Apache vhost & healthz

**Files:**
- Create: `cms/apache-vhost.conf`
- Create: `cms/healthz.php`

- [ ] **Step 1: Write `cms/apache-vhost.conf`**

```apache
<VirtualHost *:80>
    ServerName cms.gkhubs.local
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Disable directory listing for uploads (offloaded to R2 anyway)
    <Directory /var/www/html/wp-content/uploads>
        Options -Indexes
    </Directory>

    # Health endpoint — bypass WP entirely for low-latency checks
    Alias /healthz /var/www/html/healthz.php
    <Location /healthz>
        Require all granted
    </Location>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

- [ ] **Step 2: Write `cms/healthz.php`**

```php
<?php
// Standalone — no WP bootstrap, used by Dokploy healthcheck
// Must respond <100ms even if WP is slow.
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

- [ ] **Step 3: Commit**

```bash
git add cms/apache-vhost.conf cms/healthz.php
git commit -m "feat(cms): apache vhost + standalone healthz"
```

---

## Task 3: Static-check script (will be called from Dockerfile test stage)

**Files:**
- Create: `cms/tests/static-check.sh`

- [ ] **Step 1: Write the failing check first**

```bash
#!/usr/bin/env bash
# cms/tests/static-check.sh — runs inside Docker test stage; no network/DB.
set -euo pipefail

echo "==> PHP syntax check"
find mu-plugins healthz.php -type f -name "*.php" -print0 \
  | xargs -0 -n1 php -l

echo "==> Bash syntax check"
find tests -type f -name "*.sh" -print0 \
  | xargs -0 -n1 bash -n

echo "==> Require at least one mu-plugin"
test -n "$(find mu-plugins -maxdepth 1 -name '*.php' -print -quit)" \
  || { echo "FAIL: mu-plugins/ has no PHP files"; exit 1; }

echo "==> JSON fixture validation"
for f in tests/fixtures/*.json; do
    [ -f "$f" ] || continue
    python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$f"
done

echo "==> All static checks passed"
```

- [ ] **Step 2: Make it executable**

```bash
chmod +x cms/tests/static-check.sh
```

- [ ] **Step 3: Run it locally to verify (will fail — no mu-plugins yet)**

```bash
cd cms && bash tests/static-check.sh; cd ..
```

Expected: failure due to no `mu-plugins` directory yet. **This proves the check works** — we'll re-run in Task 5+ after creating mu-plugins.

- [ ] **Step 4: Commit**

```bash
git add cms/tests/static-check.sh
git commit -m "test(cms): static-check script for Docker test stage"
```

---

## Task 4: Multi-stage Dockerfile (skeleton)

**Files:**
- Create: `cms/Dockerfile`
- Create: `cms/mu-plugins/.gitkeep`
- Create: `cms/tests/fixtures/.gitkeep`
- Create: `cms/plugins-vendor/.gitignore`

- [ ] **Step 1: Author multi-stage Dockerfile**

```dockerfile
# syntax=docker/dockerfile:1.6

# ---- builder: download external plugins ----
FROM alpine:3.19 AS builder
RUN apk add --no-cache curl unzip ca-certificates
WORKDIR /vendor
ARG MEDIA_CLOUD_VERSION=4.6.4
RUN curl -fsSL -o ilab-media-tools.zip \
      "https://downloads.wordpress.org/plugin/ilab-media-tools.${MEDIA_CLOUD_VERSION}.zip" \
 && unzip -q ilab-media-tools.zip \
 && rm ilab-media-tools.zip

# ---- test: static checks (fails build if anything's broken) ----
FROM wordpress:6.5-apache AS test
RUN apt-get update && apt-get install -y --no-install-recommends \
      python3 bash file \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /work
COPY mu-plugins ./mu-plugins
COPY healthz.php ./healthz.php
COPY tests ./tests
RUN chmod +x tests/*.sh && bash tests/static-check.sh

# ---- runtime: actual image ----
FROM wordpress:6.5-apache AS runtime

# Install wp-cli (required by gkhubs-init.sh and smoke tests)
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

# Wrap official entrypoint:
#   - Apache stays PID 1 (signal forwarding intact)
#   - A background watcher polls /healthz; once it answers 200, run init once
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

- [ ] **Step 2: Stub `docker-entrypoint-init.sh`**

```bash
#!/usr/bin/env bash
# cms/docker-entrypoint-init.sh
# Idempotent first-run bootstrap. Re-runnable safely.
set -euo pipefail
echo "[gkhubs-init] start"
echo "[gkhubs-init] (placeholder — populated in Task 8)"
echo "[gkhubs-init] done"
```

- [ ] **Step 3: Stub `cms/plugins-vendor/.gitignore`**

```
*
!.gitignore
```

- [ ] **Step 4: Build (test stage will fail — no mu-plugins yet)**

```bash
make cms-build
```

Expected: failure at `static-check.sh` since `mu-plugins/` is empty (only .gitkeep). **Good — fails-fast working.**

- [ ] **Step 5: Add a tiny stub mu-plugin so build can complete**

Write `cms/mu-plugins/00-gkhubs-cpt.php`:

```php
<?php
/**
 * Plugin Name: gkhubs CPT (placeholder)
 * Description: Will register gk_figure / gk_listing — populated in Task 5.
 */
defined('ABSPATH') || exit;
```

- [ ] **Step 6: Build again — must succeed**

```bash
make cms-build
```

Expected: both `gkhubs-cms:test` and `gkhubs-cms:dev` images built.

- [ ] **Step 7: Commit**

```bash
git add cms/Dockerfile cms/docker-entrypoint-init.sh \
        cms/plugins-vendor/.gitignore cms/mu-plugins/00-gkhubs-cpt.php \
        cms/mu-plugins/.gitkeep cms/tests/fixtures/.gitkeep
git commit -m "feat(cms): multi-stage Dockerfile with test gate + media-cloud bundling"
```

---

## Task 5: Register `gk_figure` CPT (TDD)

**Files:**
- Modify: `cms/mu-plugins/00-gkhubs-cpt.php`
- Modify: `cms/tests/smoke.sh` (create new)

- [ ] **Step 1: Write `cms/tests/smoke.sh` with failing assertion for gk_figure**

```bash
#!/usr/bin/env bash
# cms/tests/smoke.sh — full integration test using docker network
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

echo "==> Build CMS image"
docker build --target runtime -t gkhubs-cms:smoke cms

echo "==> Create network"
docker network create "$NET" >/dev/null

echo "==> Start MySQL"
docker run -d --name "$DB" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$DB_PASS" \
  -e MYSQL_DATABASE=wordpress \
  -e MYSQL_USER=wordpress \
  -e MYSQL_PASSWORD="$DB_PASS" \
  mysql:8.0 >/dev/null

echo -n "==> Wait MySQL ready"
for i in $(seq 1 60); do
    if docker exec "$DB" mysqladmin ping -uroot -p"$DB_PASS" --silent 2>/dev/null; then
        echo " OK"; break
    fi
    echo -n "."; sleep 1
    [ "$i" = "60" ] && { echo " TIMEOUT"; exit 1; }
done

echo "==> Start WP"
docker run -d --name "$WP" --network "$NET" \
  -e WORDPRESS_DB_HOST="$DB" \
  -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
  -e WORDPRESS_DB_NAME=wordpress \
  -p 18080:80 \
  gkhubs-cms:smoke >/dev/null

echo -n "==> Wait WP healthy"
for i in $(seq 1 90); do
    if curl -sf http://localhost:18080/healthz >/dev/null 2>&1; then
        echo " OK"; break
    fi
    echo -n "."; sleep 1
    [ "$i" = "90" ] && { echo " TIMEOUT"; docker logs "$WP" | tail -50; exit 1; }
done

echo "==> Trigger WP install via wp-admin (anon GET makes WP create tables)"
curl -sf -L http://localhost:18080/wp-admin/install.php >/dev/null

echo "==> WP-CLI install (silent)"
docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root core install \
  --url=http://localhost:18080 \
  --title=gkhubs \
  --admin_user=admin \
  --admin_password=admintest \
  --admin_email=admin@example.com \
  --skip-email

echo "==> Assert gk_figure CPT exists"
RESP=$(curl -sf "http://localhost:18080/wp-json/wp/v2/types/gk_figure" || true)
if [ -z "$RESP" ]; then
    echo "FAIL: gk_figure CPT not found"; exit 1
fi
echo "$RESP" | grep -q '"slug":"gk_figure"' || { echo "FAIL: response shape: $RESP"; exit 1; }

echo "==> Assert gk_listing CPT exists"
RESP=$(curl -sf "http://localhost:18080/wp-json/wp/v2/types/gk_listing" || true)
if [ -z "$RESP" ]; then
    echo "FAIL: gk_listing CPT not found"; exit 1
fi
echo "$RESP" | grep -q '"slug":"gk_listing"' || { echo "FAIL: response shape: $RESP"; exit 1; }

echo "==> Smoke OK"
```

```bash
chmod +x cms/tests/smoke.sh
```

- [ ] **Step 2: Run smoke — gk_listing assertion will fail**

```bash
make cms-smoke
```

Expected: passes WP install, fails at "Assert gk_figure CPT exists" because mu-plugin is still a stub.

- [ ] **Step 3: Implement `gk_figure` registration in `cms/mu-plugins/00-gkhubs-cpt.php`**

```php
<?php
/**
 * Plugin Name: gkhubs CPT
 * Description: Custom post types for figures and listings.
 */
defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('gk_figure', [
        'label' => 'Figures',
        'labels' => [
            'name' => 'Figures',
            'singular_name' => 'Figure',
        ],
        // Headless: Next.js is the only renderer. Disable WP-rendered single
        // pages and archives so search engines never see WP-themed URLs.
        'public' => false,
        'show_ui' => true,              // still editable in wp-admin
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
            // Editor role (bot user) must be able to assign and create terms via REST.
            // Default caps are admin-only (manage_categories), which would silently fail
            // wp_set_object_terms() during upsert.
            'capabilities' => [
                'manage_terms' => 'edit_posts',
                'edit_terms'   => 'edit_posts',
                'delete_terms' => 'edit_posts',
                'assign_terms' => 'edit_posts',
            ],
        ]);
    }

    // Meta fields exposed via REST
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

- [ ] **Step 4: Re-run smoke — gk_figure passes, gk_listing still fails**

```bash
make cms-smoke
```

Expected: gk_figure assertion passes, gk_listing fails.

- [ ] **Step 5: Commit (figure-only, listing comes next)**

```bash
git add cms/mu-plugins/00-gkhubs-cpt.php cms/tests/smoke.sh
git commit -m "feat(cms): register gk_figure CPT + smoke test"
```

---

## Task 6: Register `gk_listing` CPT (TDD)

**Files:**
- Modify: `cms/mu-plugins/00-gkhubs-cpt.php`

- [ ] **Step 1: Re-run smoke to confirm gk_listing assertion fails**

```bash
make cms-smoke
```

Expected: fails at gk_listing.

- [ ] **Step 2: Append `gk_listing` registration to mu-plugin**

In the same `add_action('init', ...)` callback, add:

```php
register_post_type('gk_listing', [
    'label' => 'Listings',
    'labels' => ['name' => 'Listings', 'singular_name' => 'Listing'],
    'public' => false,                   // never show in front
    'show_in_rest' => true,
    'rest_base' => 'listings',
    'show_ui' => true,                   // editable in wp-admin
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

- [ ] **Step 3: Add unique-key index on `shop_listing_url`** (DB-level constraint via `dbDelta` in `register_activation_hook` won't fire for mu-plugins; use `init` action with a flag).

Append to the same file:

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
        if ($created === false) return;   // do NOT mark done; let next boot retry
    }
    update_option('gkhubs_listing_url_index_v1', 1);
}, 20);
```

> Note: WP postmeta is stringly indexed; this gives reasonable lookup perf for `shop_listing_url`. True uniqueness is enforced at the application layer (REST upsert checks before insert). The `(meta_value(64))` prefix is conservative — most URLs differ in the first 64 chars.

- [ ] **Step 4: Re-run smoke — must pass**

```bash
make cms-smoke
```

Expected: all assertions pass.

- [ ] **Step 5: Commit**

```bash
git add cms/mu-plugins/00-gkhubs-cpt.php
git commit -m "feat(cms): register gk_listing CPT + meta + lookup index"
```

---

## Task 7: Custom REST `upsert-figure` (TDD)

**Files:**
- Create: `cms/mu-plugins/01-gkhubs-rest.php`
- Create: `cms/tests/fixtures/figure-sample.json`
- Modify: `cms/tests/smoke.sh`

- [ ] **Step 1: Add fixture**

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

- [ ] **Step 2: Add failing assertion to `smoke.sh`** (append before "Smoke OK")

```bash
echo "==> Generate App Password for admin"
APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create admin smoke --porcelain)

echo "==> POST /upsert-figure"
RESP=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-figure" \
    --data @cms/tests/fixtures/figure-sample.json)
echo "$RESP" | grep -q '"id":' || { echo "FAIL: upsert-figure response: $RESP"; exit 1; }
FIGURE_ID=$(echo "$RESP" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

echo "==> Idempotency: same slug → same id"
RESP2=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-figure" \
    --data @cms/tests/fixtures/figure-sample.json)
ID2=$(echo "$RESP2" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
[ "$FIGURE_ID" = "$ID2" ] || { echo "FAIL: idempotency, got $FIGURE_ID then $ID2"; exit 1; }
```

- [ ] **Step 3: Run smoke — fails because endpoint doesn't exist**

```bash
make cms-smoke
```

Expected: 404 on `/wp-json/gkhubs/v1/upsert-figure`.

- [ ] **Step 4: Implement `cms/mu-plugins/01-gkhubs-rest.php`**

```php
<?php
/**
 * Plugin Name: gkhubs REST
 * Description: Custom REST endpoints for crawler ingest.
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
        return new WP_Error('bad_request', 'slug and title required', ['status' => 400]);
    }

    // Idempotency: find by slug
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

    // Taxonomies
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

- [ ] **Step 5: Run smoke — must pass**

```bash
make cms-smoke
```

Expected: upsert-figure 200 with id, idempotency check passes.

- [ ] **Step 6: Commit**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/fixtures/figure-sample.json cms/tests/smoke.sh
git commit -m "feat(cms): REST upsert-figure with slug-based idempotency"
```

---

## Task 8: Custom REST `upsert-listing` (TDD)

**Files:**
- Modify: `cms/mu-plugins/01-gkhubs-rest.php`
- Create: `cms/tests/fixtures/listing-sample.json`
- Modify: `cms/tests/smoke.sh`

- [ ] **Step 1: Add fixture `listing-sample.json`**

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

- [ ] **Step 2: Add failing assertions to `smoke.sh`** (append)

```bash
echo "==> POST /upsert-listing"
RESP=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-listing" \
    --data @cms/tests/fixtures/listing-sample.json)
echo "$RESP" | grep -q '"id":' || { echo "FAIL: upsert-listing: $RESP"; exit 1; }
LISTING_ID=$(echo "$RESP" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

echo "==> Idempotency: same shop_listing_url → same id"
RESP2=$(curl -sf -u "admin:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "http://localhost:18080/wp-json/gkhubs/v1/upsert-listing" \
    --data @cms/tests/fixtures/listing-sample.json)
ID2=$(echo "$RESP2" | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
[ "$LISTING_ID" = "$ID2" ] || { echo "FAIL: listing idempotency"; exit 1; }

echo "==> Verify figure_ref linked"
LINKED_FIGURE=$(curl -sf "http://localhost:18080/wp-json/wp/v2/listings/$LISTING_ID" \
    | python3 -c 'import json,sys;print(json.load(sys.stdin)["meta"]["gk_figure_ref"])')
[ "$LINKED_FIGURE" = "$FIGURE_ID" ] || { echo "FAIL: figure_ref expected $FIGURE_ID got $LINKED_FIGURE"; exit 1; }
```

- [ ] **Step 3: Run smoke — fails (no endpoint)**

```bash
make cms-smoke
```

- [ ] **Step 4: Append handler to `cms/mu-plugins/01-gkhubs-rest.php`**

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
            return new WP_Error('bad_request', "missing field: $k", ['status' => 400]);
        }
    }

    // Idempotency: lookup by shop_listing_url meta
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

    // Shop taxonomy
    wp_set_object_terms($post_id, [sanitize_text_field($body['shop'])], 'gk_shop', false);

    // Resolve figure_ref by slug if provided
    $figure_ref = 0;
    if (!empty($body['gk_figure_slug'])) {
        $fig = get_page_by_path($body['gk_figure_slug'], OBJECT, 'gk_figure');
        if ($fig) $figure_ref = (int) $fig->ID;
    } elseif (!empty($body['gk_figure_ref'])) {
        $figure_ref = (int) $body['gk_figure_ref'];
    }
    if ($figure_ref) update_post_meta($post_id, 'gk_figure_ref', $figure_ref);

    // Other meta
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

- [ ] **Step 5: Run smoke — must pass**

```bash
make cms-smoke
```

- [ ] **Step 6: Commit**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/fixtures/listing-sample.json cms/tests/smoke.sh
git commit -m "feat(cms): REST upsert-listing with shop_listing_url idempotency + figure linking"
```

---

## Task 9: Custom REST `figures-with-listings` (read endpoint)

**Files:**
- Modify: `cms/mu-plugins/01-gkhubs-rest.php`
- Modify: `cms/tests/smoke.sh`

- [ ] **Step 1: Append failing assertion to smoke**

```bash
echo "==> GET /figures-with-listings"
RESP=$(curl -sf "http://localhost:18080/wp-json/gkhubs/v1/figures-with-listings?per_page=10")
COUNT=$(echo "$RESP" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["items"]))')
[ "$COUNT" -ge 1 ] || { echo "FAIL: expected ≥1 figure, got $COUNT"; exit 1; }
echo "$RESP" | grep -q "\"shop_listing_url\":\"https://favorgk.com" || { echo "FAIL: listing not embedded"; exit 1; }
```

- [ ] **Step 2: Run smoke — fails (no endpoint)**

- [ ] **Step 3: Append handler**

```php
add_action('rest_api_init', function () {
    register_rest_route('gkhubs/v1', '/figures-with-listings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',   // public read
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

- [ ] **Step 4: Run smoke — must pass**

- [ ] **Step 5: Commit**

```bash
git add cms/mu-plugins/01-gkhubs-rest.php cms/tests/smoke.sh
git commit -m "feat(cms): REST figures-with-listings (public read, paginated)"
```

---

## Task 10: Bot user + bootstrap script

**Files:**
- Modify: `cms/docker-entrypoint-init.sh`
- Modify: `cms/tests/smoke.sh`

- [ ] **Step 1: Replace stub `docker-entrypoint-init.sh`**

```bash
#!/usr/bin/env bash
# cms/docker-entrypoint-init.sh — idempotent first-run bootstrap.
# Runs after Apache is up; safe to re-run on every container restart.
set -euo pipefail

WP_PATH=/var/www/html
BOT_USER="${GKHUBS_BOT_USER:-crawler-bot}"
BOT_EMAIL="${GKHUBS_BOT_EMAIL:-crawler@gkhubs.local}"

cd "$WP_PATH"

# 1. WP not installed yet → skip; first request will trigger install
if ! wp --allow-root core is-installed 2>/dev/null; then
    echo "[gkhubs-init] WP not installed; skipping bootstrap"
    exit 0
fi

# 2. Activate bundled plugins (idempotent — wp-cli no-ops if already active)
wp --allow-root plugin activate ilab-media-tools 2>/dev/null || true

# 3. Ensure bot user exists (role=editor — can edit posts but not site settings)
if ! wp --allow-root user get "$BOT_USER" >/dev/null 2>&1; then
    echo "[gkhubs-init] creating bot user $BOT_USER"
    wp --allow-root user create "$BOT_USER" "$BOT_EMAIL" \
        --role=editor \
        --user_pass="$(openssl rand -hex 32)" \
        --display_name="Crawler Bot"
fi

# 4. Configure Media Cloud → R2 via wp options (more reliable than filter hook
#    whose name varies across plugin versions). All keys are idempotent updates.
if [ -n "${R2_ENDPOINT:-}" ] && [ -n "${R2_BUCKET:-}" ] && [ -n "${R2_ACCESS_KEY:-}" ] && [ -n "${R2_SECRET:-}" ]; then
    echo "[gkhubs-init] configuring R2 offload"
    # Verify option key prefix against your installed Media Cloud version on first run:
    #   wp --allow-root option list --search='mcloud-storage*' --format=table
    # Adjust below if 4.6.4 uses different keys.
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

> **Verification step before first deploy**: After Plan 1 Task 14 deploys the image, SSH in and run `docker exec gkhubs-cms wp --allow-root option list --search='mcloud-storage*' --format=table`. If the option keys don't appear, Media Cloud 4.6.4 likely uses different prefixes (`ilab-media-*` is one historical pattern) — `grep -r 'storage-driver' /var/www/html/wp-content/plugins/ilab-media-tools` to find the right keys, then update `gkhubs-init.sh` and redeploy.

- [ ] **Step 2: Update smoke to use bot user instead of admin for upsert**

In `smoke.sh`, change the `App Password` block to:

```bash
echo "==> Run gkhubs-init.sh inside container"
docker exec "$WP" bash /usr/local/bin/gkhubs-init.sh

echo "==> Generate App Password for bot user"
APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create crawler-bot smoke --porcelain)
# (rest of script uses crawler-bot:$APP_PWD instead of admin:$APP_PWD)
```

Replace all `-u "admin:$APP_PWD"` with `-u "crawler-bot:$APP_PWD"`.

- [ ] **Step 3: Run smoke — must pass**

```bash
make cms-smoke
```

- [ ] **Step 4: Commit**

```bash
git add cms/docker-entrypoint-init.sh cms/tests/smoke.sh
git commit -m "feat(cms): bot user bootstrap + smoke uses bot user not admin"
```

---

## Task 11: R2 upload integration test

R2 wiring lives in `gkhubs-init.sh` (Task 10 step 1) — set via `wp option update`. This task only adds the smoke assertion that exercises an upload end-to-end when R2 vars are present.

**Files:**
- Modify: `cms/tests/smoke.sh`

- [ ] **Step 1: Add R2 upload assertion to smoke**

> R2 needs real credentials. If `R2_ACCESS_KEY` / etc. are unset in the test env, **skip** this assertion (don't fail). Document in plan that running with R2 vars is required pre-deploy.

Append to smoke before "Smoke OK":

```bash
if [ -n "${R2_ACCESS_KEY:-}" ] && [ -n "${R2_SECRET:-}" ] && [ -n "${R2_BUCKET:-}" ] && [ -n "${R2_ENDPOINT:-}" ]; then
    echo "==> R2 vars present — restart WP with R2 env"
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

    echo "==> Upload test image"
    APP_PWD=$(docker exec -e WP_CLI_ALLOW_ROOT=1 "$WP" wp --allow-root user application-password create crawler-bot r2test --porcelain)
    # 1x1 px PNG
    printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\xfe\xa0\x9d\xe1\x00\x00\x00\x00IEND\xaeB`\x82' > /tmp/test.png
    UPLOAD=$(curl -sf -u "crawler-bot:$APP_PWD" \
        -H "Content-Disposition: attachment; filename=test.png" \
        -H "Content-Type: image/png" \
        --data-binary @/tmp/test.png \
        "http://localhost:18080/wp-json/wp/v2/media")
    URL=$(echo "$UPLOAD" | python3 -c 'import json,sys;print(json.load(sys.stdin)["source_url"])')
    echo "Uploaded URL: $URL"
    # Media Cloud should rewrite URL to R2 endpoint
    echo "$URL" | grep -q "$R2_BUCKET" || { echo "FAIL: URL not on R2: $URL"; exit 1; }
else
    echo "==> R2 vars unset — skipping R2 upload test (run manually pre-deploy)"
fi
```

- [ ] **Step 2: Local sanity run (without R2)**

```bash
make cms-smoke
```

Expected: passes; R2 block skipped with message.

- [ ] **Step 3: Local run with R2 (optional, recommended before Task 14 prod deploy)**

```bash
R2_ENDPOINT=https://<acct>.r2.cloudflarestorage.com \
R2_BUCKET=gkhubs-media \
R2_ACCESS_KEY=... \
R2_SECRET=... \
make cms-smoke
```

Expected: R2 block runs and upload URL contains the bucket name. **If this fails, the option-key verification note in Task 10 applies** — run the `option list --search` command inside the container to find the right keys for Media Cloud 4.6.4.

- [ ] **Step 4: Commit**

```bash
git add cms/tests/smoke.sh
git commit -m "test(cms): R2 upload smoke (skipped when R2 env vars unset)"
```

---

## Task 12: Health endpoint via REST (for Dokploy)

**Files:**
- Create: `cms/mu-plugins/99-gkhubs-health.php`

- [ ] **Step 1: Add failing assertion to smoke**

```bash
echo "==> GET /wp-json/gkhubs/v1/health"
RESP=$(curl -sf "http://localhost:18080/wp-json/gkhubs/v1/health")
echo "$RESP" | grep -q '"ok":true' || { echo "FAIL: health: $RESP"; exit 1; }
```

- [ ] **Step 2: Run smoke — fails**

- [ ] **Step 3: Implement endpoint**

```php
<?php
/**
 * Plugin Name: gkhubs Health
 * Description: REST health endpoint with WP + DB + plugin checks.
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

- [ ] **Step 4: Run smoke — must pass**

- [ ] **Step 5: Commit**

```bash
git add cms/mu-plugins/99-gkhubs-health.php cms/tests/smoke.sh
git commit -m "feat(cms): /wp-json/gkhubs/v1/health endpoint"
```

---

## Task 13: Push code → create Dokploy MySQL app

**Pre-req:** Dokploy API key in env (the one user shared — not echoed here).

- [ ] **Step 1: Push to git remote**

```bash
git remote -v
# if no remote yet, user creates GitHub repo gkhubs-forum and runs:
# git remote add origin git@github.com:USER/gkhubs-forum.git
# git push -u origin main
```

> If user prefers Dokploy direct Git target, use Dokploy's built-in repo URL instead.

- [ ] **Step 2: Use Dokploy skill to create MySQL service**

Invoke `dp` skill (or manual via Dokploy UI). Required config:
- App name: `gkhubs-cms-db`
- Type: Docker image
- Image: `mysql:8.0`
- Env vars:
  - `MYSQL_ROOT_PASSWORD` (generate 32-char random, store in your password manager)
  - `MYSQL_DATABASE=wordpress`
  - `MYSQL_USER=wordpress`
  - `MYSQL_PASSWORD` (generate 32-char random, store in your password manager)
- Persistent volume: `/var/lib/mysql` → 20GB
- Network: default Dokploy network
- No public ports

- [ ] **Step 3: Verify MySQL up**

From Dokploy host:
```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms-db mysqladmin ping -uroot -p<root_pass>"
```

Expected: `mysqld is alive`.

- [ ] **Step 4: Note connection vars in 1Password**

Document:
- `WORDPRESS_DB_HOST=gkhubs-cms-db` (Docker service name within Dokploy network)
- `WORDPRESS_DB_NAME=wordpress`
- `WORDPRESS_DB_USER=wordpress`
- `WORDPRESS_DB_PASSWORD=<saved>`

---

## Task 14: Create Dokploy CMS app

- [ ] **Step 1: Create Dokploy application**

Via Dokploy skill or UI:
- App name: `gkhubs-cms`
- Build: Git source, this repo, build context = `cms/`
- Dockerfile path: `Dockerfile`
- Build target: `runtime`
- Port: 80 → exposed via Dokploy's reverse proxy
- Domain: `cms.gkhubs.com` (DNS to be pointed in Phase 5; for now use Dokploy-provided subdomain)
- Healthcheck: `GET /healthz` interval 30s timeout 5s
- Env vars:
  - `WORDPRESS_DB_HOST=gkhubs-cms-db`
  - `WORDPRESS_DB_NAME=wordpress`
  - `WORDPRESS_DB_USER=wordpress`
  - `WORDPRESS_DB_PASSWORD=<from 1pw>`
  - `WORDPRESS_TABLE_PREFIX=gk_`
  - `R2_ENDPOINT=<from R2 dashboard>`
  - `R2_BUCKET=gkhubs-media`
  - `R2_ACCESS_KEY=<from R2>`
  - `R2_SECRET=<from R2>`
  - `GKHUBS_BOT_USER=crawler-bot`
  - `GKHUBS_BOT_EMAIL=crawler@gkhubs.local`

- [ ] **Step 2: Trigger first deploy**

Push a no-op commit or click "Deploy" in Dokploy. Watch build logs:
- Test stage must complete (static-check.sh passes)
- Runtime image starts
- Healthcheck within 60s passes

- [ ] **Step 3: Run WP install via wp-admin browser**

Open Dokploy's provided HTTPS URL → wp-admin install page → fill in:
- Site title: `gkhubs CMS`
- Admin user: `admin`
- Admin password: from your password manager (Pre-flight P-3)
- Email: yours
- Search engine visibility: **discourage** (headless, never indexed)

- [ ] **Step 3.5: Trigger gkhubs-init.sh manually (creates bot user)**

The entrypoint's background watcher only runs once at container start (before WP was installed). After manual install, run init explicitly:

```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms /usr/local/bin/gkhubs-init.sh"
```

Expected output includes `creating bot user crawler-bot` and (if R2 vars set) `configuring R2 offload`. Verify:

```bash
ssh root@152.53.53.84 "docker exec gkhubs-cms wp --allow-root user list --role=editor --field=user_login"
```

Should print `crawler-bot`.

- [ ] **Step 4: Verify endpoints from outside**

```bash
curl -sf https://<dokploy-cms-url>/healthz | jq .
curl -sf https://<dokploy-cms-url>/wp-json/gkhubs/v1/health | jq .
```

Both must return `"ok": true`.

- [ ] **Step 5: Generate App Password for crawler-bot in wp-admin**

wp-admin → Users → crawler-bot → Application Passwords → Add. Save the value to 1Password as `GKHUBS_CRAWLER_APP_PASSWORD` — needed by Plan 3.

- [ ] **Step 6: End-to-end production smoke**

```bash
APP_PWD=<from 1pw>
URL=https://<dokploy-cms-url>

curl -sf -u "crawler-bot:$APP_PWD" -H "Content-Type: application/json" \
    -X POST "$URL/wp-json/gkhubs/v1/upsert-figure" \
    -d @cms/tests/fixtures/figure-sample.json | jq .

curl -sf "$URL/wp-json/gkhubs/v1/figures-with-listings" | jq '.items | length'
```

Expected: figure created (non-zero id) and visible in figures-with-listings.

- [ ] **Step 7: Commit deployment notes**

Create `cms/DEPLOY.md` documenting:
- App names in Dokploy
- Env var reference (no secrets, just keys)
- How to redeploy (`git push` triggers auto-deploy)
- Where each secret lives (1Password item names)
- How to view logs (`Dokploy UI → app → logs`)

```bash
git add cms/DEPLOY.md
git commit -m "docs(cms): deployment runbook"
```

---

## Definition of Done

- [ ] Tasks 1–12 each produced at least one commit on `main` (Task 13 is Dokploy-only operations, no commit; Task 14 produces one commit at the end: `cms/DEPLOY.md`)
- [ ] `make cms-smoke` passes locally with no R2 vars (R2 block skipped)
- [ ] `make cms-smoke` passes locally **with** R2 vars (R2 upload assertion succeeds)
- [ ] `gkhubs-cms` and `gkhubs-cms-db` running in Dokploy
- [ ] `https://<cms-url>/healthz` returns 200
- [ ] `https://<cms-url>/wp-json/gkhubs/v1/health` returns `"ok": true`
- [ ] Production end-to-end (Task 14 Step 6) succeeds
- [ ] Crawler bot App Password stored in your password manager
- [ ] Image upload via REST lands on R2 (URL contains the bucket name, not local `/wp-content/uploads`)
- [ ] `cms/DEPLOY.md` committed

---

## Skills to invoke during execution

- @superpowers:test-driven-development — every task here uses red-green-commit cycle
- @superpowers:verification-before-completion — before claiming a task done, run the relevant smoke section
- `dp` (dokploy-deploy) — only at Tasks 13–14 for actual Dokploy operations

## Out of scope for Plan 1

- Watermark service (Plan 2)
- Crawler (Plan 3)
- Frontend (Plan 4)
- Domain cutover, Cloudflare config, monitoring (Plan 5)
- Backup script (Plan 5)
