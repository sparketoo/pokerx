# Default Dockerfile
#
# @link     https://www.hyperf.io
# @document https://hyperf.wiki
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

FROM hyperf/hyperf:8.4-alpine-v3.22-swoole

LABEL org.opencontainers.image.title="PokerX Hyperf" \
      org.opencontainers.image.description="PokerX HTTP and WebSocket backend"

ARG TIMEZONE=Asia/Shanghai

ENV TIMEZONE=${TIMEZONE} \
    APP_ENV=prod \
    SCAN_CACHEABLE=true \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /opt/www

# The official Hyperf image already includes Swoole, Redis and pdo_mysql.
RUN set -eux; \
    apk add --no-cache tzdata; \
    ln -snf "/usr/share/zoneinfo/${TIMEZONE}" /etc/localtime; \
    echo "${TIMEZONE}" > /etc/timezone; \
    php_ini_scan_dir="$(php --ini | awk -F ': ' '/Scan for additional .ini files in:/{print $2}')"; \
    test -d "${php_ini_scan_dir}"; \
    { \
        echo 'upload_max_filesize=128M'; \
        echo 'post_max_size=128M'; \
        echo 'memory_limit=1G'; \
        echo "date.timezone=${TIMEZONE}"; \
    } > "${php_ini_scan_dir}/99-pokerx.ini"; \
    php -m | grep -qx 'pdo_mysql'; \
    php -m | grep -qx 'redis'; \
    php --ri swoole >/dev/null

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

COPY . .
RUN set -eux; \
    mkdir -p runtime; \
    chmod +x docker/entrypoint.sh

EXPOSE 18080 18081

ENTRYPOINT ["/opt/www/docker/entrypoint.sh"]
