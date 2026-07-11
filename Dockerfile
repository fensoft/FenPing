FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY index.html vite.config.js ./
COPY frontend ./frontend
RUN npm run build

FROM alpine:3.23
RUN apk add --no-cache \
      ca-certificates \
      dnsmasq \
      iproute2-minimal \
      iputils-arping \
      iputils-ping \
      nginx \
      nmap \
      nmap-scripts \
      php84 \
      php84-ctype \
      php84-curl \
      php84-fpm \
      php84-pdo_sqlite \
      php84-posix \
      php84-session \
      php84-sockets \
      sudo \
    && adduser -S -D -H -s /sbin/nologin -G www-data www-data
COPY config.php oui.php /opt/fenping/
RUN mkdir -p /usr/share/fenping \
    && php -r 'require "/opt/fenping/config.php"; require "/opt/fenping/oui.php"; $result=ieeeOuiRefresh(IEEE_OUI_SEED_PATH); printf("IEEE OUI registry seed: %d assignments from %d registries\n", $result["assignments"], $result["registries"]);'
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
COPY nginx-fenping.conf /etc/nginx/nginx.conf
COPY --from=frontend /app/dist/ /var/www/public/
COPY public/api.php /var/www/public/
COPY img/icon.png /var/www/public/icon.png
COPY favicon.ico favicon-32x32.png /var/www/public/
COPY res/xsl /var/www/public/res/xsl/
COPY routes /opt/fenping/routes/
COPY functions.php api.php auth.php cli.php database.php discord.php hosts.php health.php ipam.php scans.php inventory.php backup.php dnsmasq.conf.template ping.php dnsmasq.leases.php db.sql /opt/fenping/
RUN install -d -o www-data -g www-data /var/lib/fenping/netboot \
    && install -d -o www-data -g www-data -m 2770 /var/lib/fenping/database \
    && mkdir -p /var/lib/fenping/backups /var/lib/fenping/state /etc/dnsmasq.d /var/lib/misc \
    && touch /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts /var/lib/misc/dnsmasq.leases \
    && printf '%s\n' \
      'Defaults umask=0007' \
      'Defaults env_keep += "DATABASE_PATH NETWORK IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL FENPING_DATA_DIR DNSMASQ_RELOAD_MODE"' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts --apply-pending' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts --sync-locked' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/fenping/cli.php ping' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/php /opt/fenping/cli.php inventory --work' \
      > /etc/sudoers.d/fenping \
    && chmod 0440 /etc/sudoers.d/fenping
COPY boot.sh /.boot
ENV FENPING_DATA_DIR=/var/lib/fenping
EXPOSE 80
CMD ["/.boot"]
