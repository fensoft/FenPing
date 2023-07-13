FROM mariadb:10.1 as builder
RUN ["sed", "-i", "s/exec \"$@\"/echo \"not running $@\"/", "/usr/local/bin/docker-entrypoint.sh"]
ENV MYSQL_ROOT_PASSWORD=root
COPY db.sql /docker-entrypoint-initdb.d/
RUN sed -i '1s#^#create database ping; use ping;\n#g' /docker-entrypoint-initdb.d/db.sql
RUN ["/usr/local/bin/docker-entrypoint.sh", "mysqld", "--datadir", "/default", "--aria-log-dir-path", "/default"]
RUN cd /default; tar czf ../default.tgz *

FROM ubuntu:bionic
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y cron nano apache2 mariadb-server libapache2-mod-php php-mysql isc-dhcp-server bind9 libxml-xpath-perl nmap iputils-ping php-curl sudo iptables composer curl unzip && apt clean; rm /var/www/html/index.html
COPY --from=builder /default.tgz /var/lib/mysql.tgz
ADD res/xsl /var/www/html/res/xsl/
ADD res/png /var/www/html/res/png/
ADD templates /var/www/html/templates/
ADD favicon.ico adminer.php functions.php index.php api.php composer.json dhcpd.conf.template ips2hosts.sh ping.sh composer.lock config.php.template dhcpd.leases.php network_inventory.sh /var/www/html/
RUN cd /var/www/html; composer install
RUN chown -R www-data:www-data /var/www/html; chown -R mysql:mysql /var/lib/mysql
RUN echo 'zone "lan" {\ntype master;\ncheck-names ignore;\nfile "/etc/bind/lan";\n};' >> /etc/bind/named.conf.options
RUN echo 'www-data ALL = NOPASSWD: /var/www/html/ips2hosts.sh' >> /etc/sudoers
RUN echo '0 * * * * root flock -n /tmp/inv.lck -c "/var/www/html/network_inventory.sh"\n* * * * * root flock -n /tmp/ping.lck -c "/var/www/html/ping.sh"\n* * * * * root flock -n /tmp/dhcp.lck -c "php /var/www/html/dhcpd.leases.php"' >> /etc/crontab
RUN touch /etc/dhcp/dhcpd.hosts
ADD boot.sh /.boot
EXPOSE 80
CMD /.boot
