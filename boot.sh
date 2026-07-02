#!/bin/bash
echo -e "\n\n-- booting fenping ... ---"
if [ "$FENPING_NETWORK_MODE" = "host" ]; then
  IFACE=${HOST_INTERFACE:-`ip route show default 2>/dev/null | awk '{ print $5; exit }'`}
  IFACE=${IFACE:-eth0}
  echo "host networking enabled on $IFACE; leaving host routes, addresses and iptables unchanged"
else
  IFACE=eth0
  ip route del default
  route add default gw ${DEFAULT_GATEWAY}
  iptables -A FORWARD -i $IFACE -j ACCEPT
  iptables -t nat -A POSTROUTING -o $IFACE -j MASQUERADE
fi
mkdir -p /var/run/mysqld
chown mysql:mysql /var/run/mysqld
cd /var/www/html
mkdir -p /var/lib/mysql
chown mysql:mysql -R /var/lib/mysql
if [ ! -d /var/lib/mysql/mysql ]; then
  echo "initializing MariaDB data directory"
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=normal
fi
MYSQLD=`command -v mariadbd || command -v mysqld`
MYSQL=`command -v mariadb || command -v mysql`
MYSQLADMIN=`command -v mariadb-admin || command -v mysqladmin`
sudo -u mysql $MYSQLD --datadir=/var/lib/mysql --socket=/var/run/mysqld/mysqld.sock&
for i in `seq 30`; do
  $MYSQLADMIN --socket=/var/run/mysqld/mysqld.sock -uroot ping > /dev/null 2>&1 && break
  $MYSQLADMIN --socket=/var/run/mysqld/mysqld.sock -uroot -proot ping > /dev/null 2>&1 && break
  sleep 1
done
MYSQL_ROOT="$MYSQL --socket=/var/run/mysqld/mysqld.sock -uroot"
$MYSQL_ROOT -e "SELECT 1" > /dev/null 2>&1 || MYSQL_ROOT="$MYSQL --socket=/var/run/mysqld/mysqld.sock -uroot -proot"
$MYSQL_ROOT -e "CREATE DATABASE IF NOT EXISTS ping; ALTER USER 'root'@'localhost' IDENTIFIED BY 'root'; FLUSH PRIVILEGES;"
MYSQL_ROOT="$MYSQL --socket=/var/run/mysqld/mysqld.sock -uroot -proot"
$MYSQL_ROOT ping < db.sql
ME=${IP:-`ip -4 a show dev $IFACE | awk '/inet/ {print $2}' | head -n1 | sed "s#/.*##"`}
cp dhcpd.conf.template /etc/dhcp/dhcpd.conf
for i in `env | sed "s#=.*##" | grep -v "^_$" | awk '{ print length, $0 }' | sort -r -n -s | cut -d" " -f2-` ME; do
  eval "CURRENT=\${$i}"
  sed -i.bak "s#ENV_$i#$CURRENT#g" /etc/dhcp/dhcpd.conf
done
if [ "$FENPING_NETWORK_MODE" != "host" ]; then
  IFS=","
  for i in ${OTHER_NETWORKS}; do
    ip address add $i dev $IFACE
  done
fi
echo "INTERFACESv4=\"$IFACE\"" > /etc/default/isc-dhcp-server
echo 'OPTIONS="-4"'       >> /etc/default/isc-dhcp-server
mkdir -p /etc/dhcp
touch /etc/dhcp/dhcpd.hosts
mkdir -p /var/lib/dhcp
touch /var/lib/dhcp/dhcpd.leases
cat config.php.template | sed "s#\$db_pass = ''#\$db_pass = 'root'#" | sed "s#\$network = .*#\$network = '$NETWORK';#" | sed "s#\$interface = .*#\$interface = '$IFACE';#" | sed "s#\$myself = .*#\$myself = '$ME';#" > config.php
./ips2hosts.sh
service syslog-ng start
service isc-dhcp-server start || echo "isc-dhcp-server failed to start; check /var/log/syslog"
cron
apachectl start
while `true`; do
  tail -n 1000 -f /var/log/syslog
  sleep 5
done
