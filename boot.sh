#!/bin/sh
set -e
printf '\n\n-- booting fenping ... ---\n'
grep -q "[[:space:]]$(hostname)\\([[:space:]]\\|$\\)" /etc/hosts || echo "127.0.1.1 $(hostname)" >> /etc/hosts
if [ -z "${IFACE:-}" ]; then
  echo "fatal: IFACE is not set; set it to the host interface that dnsmasq should bind to" >&2
  exit 1
fi
echo "host networking enabled on $IFACE; leaving host routes, addresses and iptables unchanged"
cd /opt/fenping
umask 0007
FENPING_DATA_DIR=${FENPING_DATA_DIR:-/var/lib/fenping}
DATABASE_PATH=${DATABASE_PATH:-/var/lib/fenping/database/fenping.sqlite3}
export FENPING_DATA_DIR DATABASE_PATH
DATABASE_DIR=$(dirname "$DATABASE_PATH")
mkdir -p "$DATABASE_DIR" /var/lib/fenping/netboot /var/lib/fenping/backups /var/lib/fenping/state

# Keep the bind-mounted SQLite directory and file under the host user's existing
# ownership and mode. Match the unprivileged application worker to that numeric
# owner instead of changing permissions on live database storage.
DATABASE_UID=$(stat -c '%u' "$DATABASE_DIR")
DATABASE_GID=$(stat -c '%g' "$DATABASE_DIR")
if [ "$DATABASE_UID" -ne 0 ] && [ "$DATABASE_UID" -ne "$(id -u www-data)" ]; then
  sed -i "s/^www-data:\([^:]*\):[^:]*:/www-data:\1:$DATABASE_UID:/" /etc/passwd
fi
if [ "$DATABASE_GID" -ne 0 ] && [ "$DATABASE_GID" -ne "$(id -g www-data)" ]; then
  sed -i "s/^\(www-data:[^:]*:[^:]*:\)[^:]*/\1$DATABASE_GID/" /etc/passwd
  sed -i "s/^\(www-data:[^:]*:\)[^:]*/\1$DATABASE_GID/" /etc/group
fi
if ! su www-data -s /bin/sh -c 'test -w "$(dirname "$DATABASE_PATH")" && { [ ! -e "$DATABASE_PATH" ] || [ -w "$DATABASE_PATH" ]; }'; then
  echo "fatal: current permissions do not allow the database owner to write $DATABASE_PATH" >&2
  exit 1
fi

chown www-data:www-data /var/lib/fenping/netboot
chgrp www-data /var/lib/fenping/backups
chmod 0750 /var/lib/fenping/backups
install -d -o www-data -g www-data -m 0750 /run/fenping
install -d -o www-data -g www-data -m 0700 /run/fenping/dnsmasq-pending
install -d -o www-data -g www-data -m 0700 /run/fenping/sessions
install -d -o www-data -g www-data -m 0750 /tmp/nginx
install -d -o www-data -g www-data -m 0700 /tmp/nginx/client-body
install -d -o www-data -g www-data -m 0700 /tmp/nginx/fastcgi
install -m 0666 /dev/null /tmp/fenping-dnsmasq-update.lock
su www-data -s /bin/sh -c 'exec php /opt/fenping/cli.php database'
IP=${IP:-$(ip -4 a show dev "$IFACE" | awk '/inet/ {print $2}' | head -n1 | sed 's#/.*##')}
export IP
DHCP_ADDRESS=${DHCP_NETWORK%/24}
DHCP_PREFIX=${DHCP_ADDRESS%.*}
export DHCP_NETWORK
export EXTRA_NETWORKS
export DHCP_PREFIX
export IFACE
export PASSWORD
export SECRET
export DISCORD_WEBHOOK_URL
php /opt/fenping/cli.php scan-port-backfill
if ! php /opt/fenping/cli.php oui-refresh; then
  echo "warning: IEEE OUI startup refresh failed; keeping the existing vendor cache and SQL data" >&2
  php /opt/fenping/cli.php oui-sync || true
fi
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
cat > /etc/crontabs/root <<'EOF'
0 * * * * php /opt/fenping/cli.php inventory
* * * * * php /opt/fenping/cli.php inventory --work
17 3 1 * * php /opt/fenping/cli.php oui-refresh
*/15 * * * * php /opt/fenping/cli.php ping
* * * * * php /opt/fenping/cli.php dnsmasq-leases
23 2 * * * php /opt/fenping/cli.php backup-maintenance daily
41 4 * * 0 php /opt/fenping/cli.php backup-maintenance verify
EOF
chmod 0600 /etc/crontabs/root
php /opt/fenping/cli.php discord-restart || true
php /opt/fenping/cli.php hosts
php-fpm84 --test
nginx -t
crond -b -l 8 -L /dev/stderr
php-fpm84 -D
exec nginx -g 'daemon off;'
