FROM ubuntu:26.04 AS frontend
ARG DEBIAN_FRONTEND=noninteractive
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates nodejs npm && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund --prefer-offline --maxsockets=1 --fetch-timeout=3600000 --fetch-retries=15 --fetch-retry-mintimeout=30000 --fetch-retry-maxtimeout=300000 --loglevel=http
COPY index.html vite.config.js ./
COPY frontend ./frontend
RUN npm run build

FROM ubuntu:26.04
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends cron nano apache2 mariadb-server mariadb-client libapache2-mod-php php-mysql dnsmasq-base libxml-xpath-perl nmap iputils-ping iputils-arping net-tools php-curl sudo iptables && apt-get clean && rm -rf /var/lib/apt/lists/* /var/www/html
RUN PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" && printf "upload_max_filesize=512M\npost_max_size=512M\nmax_file_uploads=20\n" > "/etc/php/$PHP_VERSION/apache2/conf.d/99-fenping-upload.ini"
RUN a2enmod rewrite && echo 'ServerName 127.0.0.1' >> /etc/apache2/apache2.conf
COPY apache-fenping.conf /etc/apache2/sites-available/000-default.conf
COPY --from=frontend /app/dist/ /var/www/public/
COPY public/api.php favicon.ico favicon-32x32.png /var/www/public/
COPY res/xsl /var/www/public/res/xsl/
COPY routes /opt/fenping/routes/
COPY functions.php api.php auth.php cli.php database.php discord.php hosts.php health.php scans.php inventory.php backup.php dnsmasq.conf.template ping.php config.php dnsmasq.leases.php db.sql /opt/fenping/
COPY netboot.htaccess /.netboot-htaccess
RUN mkdir -p /var/lib/mysql /var/lib/fenping/nmap /var/lib/fenping/netboot /var/lib/fenping/backups /var/lib/fenping/state && chown -R www-data:www-data /var/lib/fenping/netboot && chown -R mysql:mysql /var/lib/mysql
RUN echo 'Defaults env_keep += "DB_HOST DB_PORT DB_USER DB_PASS DB_NAME NETWORK IFACE IP PASSWORD SECRET DISCORD_WEBHOOK_URL FENPING_DATA_DIR DNSMASQ_RELOAD_MODE"' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts --apply-pending' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /opt/fenping/cli.php hosts --sync-locked' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /opt/fenping/cli.php ping' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /opt/fenping/cli.php inventory --work' >> /etc/sudoers
RUN mkdir -p /etc/dnsmasq.d /var/lib/misc && touch /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts /var/lib/misc/dnsmasq.leases
COPY boot.sh /.boot
ENV FENPING_DATA_DIR=/var/lib/fenping
EXPOSE 80
CMD ["/.boot"]
