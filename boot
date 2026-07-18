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
mkdir -p "$DATABASE_DIR" /var/lib/fenping/netboot /var/lib/fenping/backups /var/lib/fenping/state /etc/dnsmasq.d /var/lib/misc

DEFAULT_DATABASE_UID=$(id -u www-data)
DEFAULT_DATABASE_GID=$(id -g www-data)
DATABASE_UID=$(stat -c '%u' "$DATABASE_DIR")
DATABASE_GID=$(stat -c '%g' "$DATABASE_DIR")

# Docker creates missing bind-mount sources as root. Repair that case to the
# image's unprivileged worker identity without following symlinks or descending
# into nested mounts. Existing non-root host ownership remains untouched.
if [ "$DATABASE_UID" -eq 0 ]; then
  echo "database directory is root-owned; assigning it to the application worker"
  chown "$DEFAULT_DATABASE_UID:$DEFAULT_DATABASE_GID" "$DATABASE_DIR"
  find "$DATABASE_DIR" -xdev -mindepth 1 \
    \( -type d -exec mountpoint -q {} \; -prune \) -o \
    \( -type d -exec chown "$DEFAULT_DATABASE_UID:$DEFAULT_DATABASE_GID" {} \; \) -o \
    \( -type f -exec chown "$DEFAULT_DATABASE_UID:$DEFAULT_DATABASE_GID" {} \; \)
  su www-data -s /bin/sh -c 'chmod 2770 "$1"' sh "$DATABASE_DIR"
  find "$DATABASE_DIR" -xdev -mindepth 1 \
    \( -type d -exec mountpoint -q {} \; -prune \) -o \
    \( -type d -exec su www-data -s /bin/sh -c 'chmod 2770 "$1"' sh {} \; \) -o \
    \( -type f -exec chmod 0660 {} \; \)
  DATABASE_UID=$DEFAULT_DATABASE_UID
  DATABASE_GID=$DEFAULT_DATABASE_GID
fi

# For a mount deliberately owned by a non-root host user, match the
# unprivileged application worker to that numeric owner instead.
if [ "$DATABASE_UID" -ne 0 ] && [ "$DATABASE_UID" -ne "$(id -u www-data)" ]; then
  sed -i "s/^www-data:\([^:]*\):[^:]*:/www-data:\1:$DATABASE_UID:/" /etc/passwd
fi
if [ "$DATABASE_GID" -ne 0 ] && [ "$DATABASE_GID" -ne "$(id -g www-data)" ]; then
  sed -i "s/^\(www-data:[^:]*:[^:]*:\)[^:]*/\1$DATABASE_GID/" /etc/passwd
  sed -i "s/^\(www-data:[^:]*:\)[^:]*/\1$DATABASE_GID/" /etc/group
fi

# Complete a previously interrupted repair without taking ownership away from
# a non-root host user. Apply directory modes as the final owner because the
# intentionally reduced root capability set excludes CAP_FSETID.
find "$DATABASE_DIR" -xdev -mindepth 1 \
  \( -type d -exec mountpoint -q {} \; -prune \) -o \
  \( -type d -user 0 -exec chown "$DATABASE_UID:$DATABASE_GID" {} \; \
    -exec su www-data -s /bin/sh -c 'chmod 2770 "$1"' sh {} \; \) -o \
  \( -type f -user 0 -exec chown "$DATABASE_UID:$DATABASE_GID" {} \; -exec chmod 0660 {} \; \)

chown www-data:www-data /var/lib/fenping/netboot
chgrp www-data /var/lib/fenping/backups
chmod 0770 /var/lib/fenping/backups
install -d -o www-data -g www-data -m 0750 /run/fenping
install -d -o www-data -g www-data -m 0700 /run/fenping/dnsmasq-pending
install -d -o www-data -g www-data -m 0700 /run/fenping/sessions
install -d -o www-data -g www-data -m 0750 /tmp/nginx
install -d -o www-data -g www-data -m 0700 /tmp/nginx/client-body
install -d -o www-data -g www-data -m 0700 /tmp/nginx/fastcgi
install -m 0666 /dev/null /tmp/fenping-dnsmasq-update.lock
for file in /etc/dnsmasq.d/fenping.dhcp-hosts /etc/dnsmasq.d/fenping.dhcp-opts /etc/dnsmasq.d/fenping.hosts; do
  [ -e "$file" ] || install -m 0644 /dev/null "$file"
done
[ -e /var/lib/misc/dnsmasq.leases ] || install -m 0644 /dev/null /var/lib/misc/dnsmasq.leases
if [ -n "${DOCKER_SOCKET:-}" ] && [ -S "$DOCKER_SOCKET" ]; then
  if ! php /opt/fenping/cli.php docker-networks-refresh >/dev/null; then
    echo "warning: initial Docker network refresh failed; continuing without new Docker network data" >&2
  fi
  (
    while [ -S "$DOCKER_SOCKET" ]; do
      php /opt/fenping/cli.php docker-networks-watch || true
      sleep 5
    done
  ) &
fi
IP=${IP:-$(ip -4 a show dev "$IFACE" 2>/dev/null | awk '/inet/ {print $2}' | head -n1 | sed 's#/.*##')}
export IP
php /opt/fenping/cli.php doctor
su www-data -s /bin/sh -c 'exec php /opt/fenping/cli.php database'
DHCP_ADDRESS=${DHCP_NETWORK%/24}
DHCP_PREFIX=${DHCP_ADDRESS%.*}
export DHCP_NETWORK
export EXTRA_NETWORKS
export DHCP_PREFIX
export IFACE
export PASSWORD
export SECRET
export DISCORD_WEBHOOK_URL
export DISCORD_MENTION
export TELEGRAM_BOT_TOKEN
php /opt/fenping/cli.php scan-port-backfill
if ! php /opt/fenping/cli.php oui-refresh; then
  echo "warning: IEEE OUI startup refresh failed; keeping the existing vendor cache and SQL data" >&2
  php /opt/fenping/cli.php oui-sync || true
fi
DNSMASQ_RENDERED=/run/fenping/dnsmasq.conf
if [ -n "${DHCP_DEFAULT_ROUTER:-}" ]; then
  DHCP_ROUTER_OPTION="dhcp-option=option:router,$DHCP_DEFAULT_ROUTER"
else
  DHCP_ROUTER_OPTION="dhcp-option=option:router"
fi
export DHCP_ROUTER_OPTION
cp dnsmasq.conf.template "$DNSMASQ_RENDERED"
for i in $(env | sed 's#=.*##' | grep -v '^_$' | awk '{ print length, $0 }' | sort -r -n -s | cut -d' ' -f2-) IFACE ME; do
  eval "CURRENT=\${$i}"
  sed -i "s#ENV_$i#$CURRENT#g" "$DNSMASQ_RENDERED"
done
cmp -s "$DNSMASQ_RENDERED" /etc/dnsmasq.d/fenping.conf || install -m 0644 "$DNSMASQ_RENDERED" /etc/dnsmasq.d/fenping.conf
rm -f /etc/dnsmasq.d/fenping.conf.bak
cat > /etc/crontabs/root <<'EOF'
59 * * * * php /opt/fenping/cli.php docker-networks-refresh
0 * * * * php /opt/fenping/cli.php inventory
* * * * * php /opt/fenping/cli.php inventory --work
17 3 1 * * php /opt/fenping/cli.php oui-refresh
*/15 * * * * php /opt/fenping/cli.php ping
* * * * * php /opt/fenping/cli.php dnsmasq-leases
11 * * * * php /opt/fenping/cli.php scheduled-report
23 2 * * * php /opt/fenping/cli.php backup-maintenance daily
41 4 * * 0 php /opt/fenping/cli.php backup-maintenance verify
43 1 * * * php /opt/fenping/cli.php database-check
7 5 * * * php /opt/fenping/cli.php status-clean
EOF
chmod 0600 /etc/crontabs/root
php /opt/fenping/cli.php notify-restart || true
php /opt/fenping/cli.php hosts
php-fpm84 --test
nginx -t
crond -b -l 8 -L /dev/stderr
php-fpm84 -D
exec nginx -g 'daemon off;'
