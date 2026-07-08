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
RUN apt-get update && apt-get install -y --no-install-recommends cron nano apache2 mariadb-server mariadb-client libapache2-mod-php php-mysql dnsmasq-base libxml-xpath-perl nmap iputils-ping iputils-arping net-tools php-curl sudo iptables && apt-get clean && rm -rf /var/lib/apt/lists/* /var/www/html/index.html
RUN a2enmod rewrite && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf && echo 'ServerName 127.0.0.1' >> /etc/apache2/apache2.conf
COPY --from=frontend /app/dist/ /var/www/html/
COPY .htaccess /var/www/html/.htaccess
COPY res/xsl /var/www/html/res/xsl/
COPY favicon.ico favicon-32x32.png functions.php api.php cli.php database.php hosts.php health.php scans.php inventory.php dnsmasq.conf.template ping.php config.php.template dnsmasq.leases.php db.sql /var/www/html/
RUN mkdir -p /var/lib/mysql && chown -R www-data:www-data /var/www/html && chown -R mysql:mysql /var/lib/mysql
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /var/www/html/cli.php hosts' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /var/www/html/cli.php ping' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /var/www/html/cli.php inventory --quick *' >> /etc/sudoers
RUN mkdir -p /etc/dnsmasq.d /var/lib/misc && touch /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts /var/lib/misc/dnsmasq.leases
COPY boot.sh /.boot
EXPOSE 80
CMD ["/.boot"]
