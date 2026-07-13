FROM composer:2 AS composer

FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY index.html vite.config.js postcss.config.mjs ./
COPY frontend ./frontend
RUN npm run build

FROM alpine:3.23 AS runtime-base
RUN --mount=type=bind,source=tools/prune-nmap-nselib.php,target=/tmp/prune-nmap-nselib.php,readonly \
    apk add --no-cache \
      arp-scan \
      ca-certificates \
      curl \
      doas \
      dnsmasq \
      iproute2-minimal \
      iproute2-ss \
      iputils-arping \
      iputils-ping \
      nginx \
      nginx-mod-http-nchan \
      nmap \
      nmap-scripts \
      php84 \
      php84-ctype \
      php84-fpm \
      php84-openssl \
      php84-pdo_sqlite \
      php84-posix \
      php84-session \
      php84-sockets \
    && awk -F'"' '/categories = .*"default"/ { print $2 }' /usr/share/nmap/scripts/script.db > /tmp/nmap-retained-scripts \
    && printf '%s\n' broadcast-dhcp-discover.nse >> /tmp/nmap-retained-scripts \
    && find /usr/share/nmap/scripts -maxdepth 1 -type f -name '*.nse' | while IFS= read -r script; do \
         grep -Fqx "${script##*/}" /tmp/nmap-retained-scripts || rm -f "$script"; \
       done \
    && php /tmp/prune-nmap-nselib.php /usr/share/nmap \
    && nmap --script-updatedb >/dev/null \
    && nmap --script-help default >/dev/null \
    && nmap --script-help broadcast-dhcp-discover >/dev/null \
    && rm -f \
      /tmp/nmap-retained-scripts \
      /usr/share/nmap/nmap-mac-prefixes \
      /usr/share/arp-scan/ieee-oui.txt \
      /usr/share/arp-scan/ieee-iab.txt \
      /etc/arp-scan/mac-vendor.txt \
    && adduser -S -D -H -s /sbin/nologin -G www-data www-data
RUN printf '%s\n' \
      'upload_max_filesize=512M' \
      'post_max_size=512M' \
      'max_file_uploads=20' \
      'session.save_path=/run/fenping/sessions' \
      'session.lazy_write=1' \
      > /etc/php84/conf.d/99-fenping.ini \
    && sed -i \
      -e 's/^user = .*/user = www-data/' \
      -e 's/^group = .*/group = www-data/' \
      -e 's#^listen = .*#listen = /run/fenping/php-fpm.sock#' \
      /etc/php84/php-fpm.d/www.conf \
    && printf '%s\n' \
      'listen.owner = www-data' \
      'listen.group = www-data' \
      'listen.mode = 0660' \
      'clear_env = no' \
      'catch_workers_output = yes' \
      >> /etc/php84/php-fpm.d/www.conf \
    && sed -i 's#^error_log = .*#error_log = /proc/self/fd/2#' /etc/php84/php-fpm.conf

FROM runtime-base AS backend-deps
RUN apk add --no-cache php84-mbstring php84-phar
WORKDIR /app
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative

FROM runtime-base AS backend-test
RUN apk add --no-cache php84-dom php84-mbstring php84-phar php84-tokenizer php84-xml php84-xmlwriter
WORKDIR /app
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock phpunit.xml ./
COPY src ./src
COPY tests ./tests
COPY migrations ./migrations
COPY demo ./demo
COPY cli.php ./
COPY db.sql ./
RUN composer install --no-interaction --prefer-dist --classmap-authoritative \
    && composer test

FROM runtime-base
COPY nginx-fenping.conf /etc/nginx/nginx.conf
COPY --from=frontend /app/dist/ /var/www/public/
COPY public/api.php /var/www/public/
COPY img/icon.webp /var/www/public/icon.webp
COPY favicon.ico favicon-32x32.png /var/www/public/
COPY res/xsl /var/www/public/res/xsl/
COPY --from=backend-deps /app/vendor /opt/fenping/vendor
COPY src /opt/fenping/src/
COPY migrations /opt/fenping/migrations/
COPY composer.json composer.lock api.php cli.php dnsmasq.conf.template db.sql /opt/fenping/
RUN install -d -o www-data -g www-data /var/lib/fenping/netboot \
    && install -d -o www-data -g www-data -m 2770 /var/lib/fenping/database \
    && mkdir -p /var/lib/fenping/backups /var/lib/fenping/state /etc/dnsmasq.d /var/lib/misc \
    && touch /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts /var/lib/misc/dnsmasq.leases \
    && printf '%s\n' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS SCAN_NETWORK INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php hosts' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS SCAN_NETWORK INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php hosts --apply-pending' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS SCAN_NETWORK INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php hosts --sync-locked' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS SCAN_NETWORK INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php ping' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS SCAN_NETWORK INVENTORY_DOWN_RETENTION_DAYS SCAN_GLOBAL_CONCURRENCY SCAN_NETWORK_CONCURRENCY SCAN_NETWORK_DAILY_BUDGET SCAN_NETWORK_OVERRIDES IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php inventory --work' \
      'permit nopass setenv { DATABASE_PATH DHCP_NETWORK DHCP_DYNAMIC_BEGIN DHCP_DYNAMIC_END DHCP_DEFAULT_ROUTER EXTRA_NETWORKS INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR DNSMASQ_RELOAD_MODE } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php doctor --runtime --json' \
      'permit nopass setenv { DOCKER_SOCKET DOCKER_NETWORK_CACHE DATABASE_PATH DHCP_NETWORK EXTRA_NETWORKS INVENTORY_DOWN_RETENTION_DAYS IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL DISCORD_MENTION TELEGRAM_BOT_TOKEN FENPING_DATA_DIR } www-data as root cmd /usr/bin/php args /opt/fenping/cli.php docker-networks-refresh --api' \
      > /etc/doas.conf \
    && chmod 0400 /etc/doas.conf \
    && doas -C /etc/doas.conf
COPY boot.sh /.boot
ENV FENPING_DATA_DIR=/var/lib/fenping
EXPOSE 80
CMD ["/.boot"]
