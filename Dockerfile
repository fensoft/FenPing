FROM ubuntu:26.04 AS frontend
ARG DEBIAN_FRONTEND=noninteractive
WORKDIR /app
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates nodejs npm && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY package.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm install --no-audit --no-fund --prefer-offline --maxsockets=1 --fetch-timeout=3600000 --fetch-retries=15 --fetch-retry-mintimeout=30000 --fetch-retry-maxtimeout=300000 --loglevel=http
COPY index.html vite.config.js ./
COPY frontend ./frontend
RUN npm run build

FROM ubuntu:26.04
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends cron nano apache2 mariadb-server mariadb-client libapache2-mod-php php-mysql isc-dhcp-server bind9 libxml-xpath-perl nmap iputils-ping iputils-arping net-tools php-curl sudo iptables syslog-ng && apt-get clean && rm -rf /var/lib/apt/lists/* /var/www/html/index.html
RUN a2enmod rewrite && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
COPY --from=frontend /app/dist/ /var/www/html/
COPY .htaccess /var/www/html/.htaccess
COPY res/xsl /var/www/html/res/xsl/
COPY favicon.ico favicon-32x32.png functions.php api.php cli.php database.php dhcpd.conf.template ips2hosts.sh ping.php config.php.template dhcpd.leases.php network_inventory.sh db.sql /var/www/html/
RUN mkdir -p /var/lib/mysql && chown -R www-data:www-data /var/www/html && chown -R mysql:mysql /var/lib/mysql
RUN echo 'zone "lan" {\ntype master;\ncheck-names ignore;\nfile "/etc/bind/lan";\n};' >> /etc/bind/named.conf.options
RUN echo 'www-data ALL = NOPASSWD: /var/www/html/ips2hosts.sh' >> /etc/sudoers
RUN echo 'www-data ALL = NOPASSWD: /usr/bin/php /var/www/html/cli.php ping' >> /etc/sudoers
RUN echo '0 * * * * root flock -n /tmp/inv.lck -c "/var/www/html/network_inventory.sh"\n*/15 * * * * root flock -n /tmp/ping.lck -c "php /var/www/html/cli.php ping"\n* * * * * root flock -n /tmp/dhcp.lck -c "php /var/www/html/dhcpd.leases.php"' >> /etc/crontab
RUN touch /etc/dhcp/dhcpd.hosts
COPY boot.sh /.boot
EXPOSE 80
CMD ["/.boot"]
