#!/bin/bash
echo -e "\n\n-- booting fenping ... ---"
ip route del default
route add default gw ${DEFAULT_GATEWAY}
iptables -A FORWARD -i eth0 -j ACCEPT
iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
mkdir -p /var/run/mysqld
chown mysql:mysql /var/run/mysqld
cd /var/www/html
chown mysql:mysql -R /var/lib/mysql
sudo -u mysql mysqld&
sleep 2
ME=`ip a show dev eth0 | awk '/inet/ {print $2}' | head -n1 | sed "s#/.*##"`
cp dhcpd.conf.template /etc/dhcp/dhcpd.conf
for i in `env | sed "s#=.*##" | grep -v "^_$" | awk '{ print length, $0 }' | sort -r -n -s | cut -d" " -f2-` ME; do
  eval "CURRENT=\${$i}"
  sed -i.bak "s#ENV_$i#$CURRENT#g" /etc/dhcp/dhcpd.conf
done
IFS=","
for i in ${OTHER_NETWORKS}; do
  ip address add $i dev eth0
done
echo 'INTERFACESv4="eth0"' > /etc/default/isc-dhcp-server
echo 'OPTIONS="-4"'       >> /etc/default/isc-dhcp-server
cat config.php.template | sed "s#\$db_pass = ''#\$db_pass = 'root'#" | sed "s#\$network = .*#\$network = '$NETWORK';#" | sed "s#\$myself = .*#\$myself = '$ME';#" > config.php
./ips2hosts.sh
cron
apachectl start
service syslog-ng start
while `true`; do
  tail -n 1000 -f /var/log/syslog
  sleep 5
done
