#!/bin/sh
printf '\n\n-- booting fenping ... ---\n'
grep -q "[[:space:]]$(hostname)\\([[:space:]]\\|$\\)" /etc/hosts || echo "127.0.1.1 $(hostname)" >> /etc/hosts
if [ -z "${IFACE:-}" ]; then
  echo "fatal: IFACE is not set; set it to the host interface that dnsmasq should bind to" >&2
  exit 1
fi
echo "host networking enabled on $IFACE; leaving host routes, addresses and iptables unchanged"
cd /opt/fenping
mkdir -p /var/lib/fenping/netboot /var/lib/fenping/backups /var/lib/fenping/state
chown www-data:www-data /var/lib/fenping/netboot
install -d -o www-data -g www-data -m 0700 /run/fenping/dnsmasq-pending
install -d -o www-data -g www-data -m 0700 /run/fenping/sessions
install -d -o root -g root -m 0755 /run/lock/apache2
install -m 0666 /dev/null /tmp/fenping-dnsmasq-update.lock
cmp -s /.netboot-htaccess /var/lib/fenping/netboot/.htaccess || install -o root -g root -m 0644 /.netboot-htaccess /var/lib/fenping/netboot/.htaccess
MYSQL=$(command -v mariadb || command -v mysql)
MYSQLADMIN=$(command -v mariadb-admin || command -v mysqladmin)
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-3306}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-root}
DB_NAME=${DB_NAME:-ping}
export DB_HOST DB_PORT DB_USER DB_PASS DB_NAME
if [ "$DB_HOST" = "localhost" ] && [ -S /run/mysqld/mysqld.sock ]; then
  MYSQL_PROTOCOL=SOCKET
else
  MYSQL_PROTOCOL=TCP
fi

mysql_connection() {
  MYSQL_CLIENT=$1
  shift
  if [ "$MYSQL_PROTOCOL" = "SOCKET" ]; then
    MYSQL_PWD=$DB_PASS "$MYSQL_CLIENT" --protocol=SOCKET --socket=/run/mysqld/mysqld.sock "$@"
  else
    MYSQL_PWD=$DB_PASS "$MYSQL_CLIENT" --protocol=TCP --host="$DB_HOST" --port="$DB_PORT" "$@"
  fi
}

DB_READY=0
i=0
while [ "$i" -lt 60 ]; do
  if mysql_connection "$MYSQLADMIN" --user="$DB_USER" ping > /dev/null 2>&1; then
    DB_READY=1
    break
  fi
  sleep 1
  i=$((i + 1))
done
if [ "$DB_READY" -ne 1 ]; then
  echo "fatal: MariaDB connection did not become ready" >&2
  exit 1
fi
mysql_connection "$MYSQL" --user="$DB_USER" "$DB_NAME" < db.sql
IP=${IP:-$(ip -4 a show dev "$IFACE" | awk '/inet/ {print $2}' | head -n1 | sed 's#/.*##')}
export IP
export NETWORK
export IFACE
export PASSWORD
export SECRET
export DISCORD_WEBHOOK_URL
FENPING_DATA_DIR=${FENPING_DATA_DIR:-/var/lib/fenping}
export FENPING_DATA_DIR
php /opt/fenping/cli.php scan-port-backfill
php /opt/fenping/cli.php oui-sync
mkdir -p /etc/dnsmasq.d
DNSMASQ_RENDERED=/run/fenping/dnsmasq.conf
cp dnsmasq.conf.template "$DNSMASQ_RENDERED"
for i in $(env | sed 's#=.*##' | grep -v '^_$' | awk '{ print length, $0 }' | sort -r -n -s | cut -d' ' -f2-) IFACE ME; do
  eval "CURRENT=\${$i}"
  sed -i "s#ENV_$i#$CURRENT#g" "$DNSMASQ_RENDERED"
done
cmp -s "$DNSMASQ_RENDERED" /etc/dnsmasq.d/fenping.conf || install -m 0644 "$DNSMASQ_RENDERED" /etc/dnsmasq.d/fenping.conf
rm -f /etc/dnsmasq.d/fenping.conf.bak
for file in /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts; do
  [ -e "$file" ] || install -m 0644 /dev/null "$file"
done
mkdir -p /var/lib/misc
[ -e /var/lib/misc/dnsmasq.leases ] || install -m 0644 /dev/null /var/lib/misc/dnsmasq.leases
cat > /etc/cron.d/fenping <<EOF
SHELL=/bin/sh
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

0 * * * * root php /opt/fenping/cli.php inventory
* * * * * root php /opt/fenping/cli.php inventory --work
17 3 1 * * root php /opt/fenping/cli.php oui-refresh
*/15 * * * * root php /opt/fenping/cli.php ping
* * * * * root php /opt/fenping/cli.php dnsmasq-leases
EOF
chmod 0644 /etc/cron.d/fenping
php /opt/fenping/cli.php discord-restart || true
php /opt/fenping/cli.php hosts
cron
exec apachectl -D FOREGROUND
