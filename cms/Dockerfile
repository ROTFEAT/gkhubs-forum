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
COPY plugins/gkhubs-r2-pro/ /var/www/html/wp-content/plugins/gkhubs-r2-pro/
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
