#!/bin/bash
echo -e "\n\n-- booting fenping ... ---"
grep -q "[[:space:]]$(hostname)\\([[:space:]]\\|$\\)" /etc/hosts || echo "127.0.1.1 $(hostname)" >> /etc/hosts
if [ -z "${IFACE:-}" ]; then
  echo "fatal: IFACE is not set; set it to the host interface that dnsmasq should bind to" >&2
  exit 1
fi
echo "host networking enabled on $IFACE; leaving host routes, addresses and iptables unchanged"
mkdir -p /var/run/mysqld
chown mysql:mysql /var/run/mysqld
mkdir -p /var/log/mysql
chown mysql:mysql /var/log/mysql
cd /opt/fenping
mkdir -p /var/lib/fenping/nmap /var/lib/fenping/netboot /var/lib/fenping/backups /var/lib/fenping/state
chown -R www-data:www-data /var/lib/fenping/netboot
install -o root -g root -m 0644 /.netboot-htaccess /var/lib/fenping/netboot/.htaccess
mkdir -p /var/lib/mysql
chown mysql:mysql -R /var/lib/mysql
MYSQL_AUTH="-proot"
if [ ! -d /var/lib/mysql/mysql ]; then
  echo "initializing MariaDB data directory"
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=normal
  MYSQL_AUTH=""
fi
MYSQLD=`command -v mariadbd || command -v mysqld`
MYSQL=`command -v mariadb || command -v mysql`
MYSQLADMIN=`command -v mariadb-admin || command -v mysqladmin`
sudo -u mysql $MYSQLD --datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock --log-error=/var/log/mysql/error.log&
for i in `seq 30`; do
  $MYSQLADMIN --socket=/var/run/mysqld/mysqld.sock -uroot $MYSQL_AUTH ping > /dev/null 2>&1 && break
  sleep 1
done
MYSQL_ROOT="$MYSQL --socket=/var/run/mysqld/mysqld.sock -uroot $MYSQL_AUTH"
$MYSQL_ROOT -e "CREATE DATABASE IF NOT EXISTS ping; ALTER USER 'root'@'localhost' IDENTIFIED BY 'root'; FLUSH PRIVILEGES;"
MYSQL_ROOT="$MYSQL --socket=/var/run/mysqld/mysqld.sock -uroot -proot"
$MYSQL_ROOT ping < db.sql
export IP=${IP:-`ip -4 a show dev $IFACE | awk '/inet/ {print $2}' | head -n1 | sed "s#/.*##"`}
export DB_HOST=${DB_HOST:-localhost}
export DB_PORT=${DB_PORT:-3306}
export DB_USER=${DB_USER:-root}
export DB_PASS=${DB_PASS:-root}
export DB_NAME=${DB_NAME:-ping}
export NETWORK
export IFACE
export PASSWORD
export SECRET
export DISCORD_WEBHOOK_URL
export FENPING_DATA_DIR=${FENPING_DATA_DIR:-/var/lib/fenping}
mkdir -p /etc/dnsmasq.d
cp dnsmasq.conf.template /etc/dnsmasq.d/fenping.conf
for i in `env | sed "s#=.*##" | grep -v "^_$" | awk '{ print length, $0 }' | sort -r -n -s | cut -d" " -f2-` IFACE ME; do
  eval "CURRENT=\${$i}"
  sed -i.bak "s#ENV_$i#$CURRENT#g" /etc/dnsmasq.d/fenping.conf
done
touch /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts
mkdir -p /var/lib/misc
touch /var/lib/misc/dnsmasq.leases
cat > /etc/cron.d/fenping <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_NAME=$DB_NAME
NETWORK=$NETWORK
IFACE=$IFACE
IP=$IP
DISCORD_WEBHOOK_URL=$DISCORD_WEBHOOK_URL
FENPING_DATA_DIR=$FENPING_DATA_DIR

0 * * * * root flock -n /tmp/inventory-discovery.lck -c "php /opt/fenping/cli.php inventory"
* * * * * root php /opt/fenping/cli.php inventory --work
*/15 * * * * root flock -n /tmp/ping.lck -c "php /opt/fenping/cli.php ping"
* * * * * root flock -n /tmp/dnsmasq-leases.lck -c "php /opt/fenping/dnsmasq.leases.php"
EOF
chmod 0644 /etc/cron.d/fenping
php /opt/fenping/cli.php discord-restart || true
php /opt/fenping/cli.php hosts
cron
exec apachectl -D FOREGROUND
