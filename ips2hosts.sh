#!/bin/bash
ME=`cd $(dirname $0); pwd`
config=`mktemp`
cat $ME/config.php | grep -v php | sed "s#\\\$##" | sed -e "s#[ ]*=[ ]*'#=#" | sed "s#';##" > $config
. $config

IFS=$'\n'
TARGET=/etc/dhcp/dhcpd.hosts
TARGET2=/etc/bind/lan
rm -f $TARGET $TARGET2
echo '$TTL    3600' >> $TARGET2
echo '@       IN      SOA     lan. lan. ( 2012033101  3600 1800 604800 43200 )' >> $TARGET2
echo '        IN      NS      lan.' >> $TARGET2
echo '@       IN      A       10.68.69.7' >> $TARGET2
echo 'dom     IN      A       10.68.69.7' >> $TARGET2
for i in `echo 'select concat(name, ";", ifnull(mac, ""), ";", ip) from ips where ip is not null and name is not null' | mysql -h${db_host} -u${db_user} -p${db_pass} ${db} | tail -n+2`; do
  name=`echo $i | cut -d\; -f1`
  mac=`echo $i | cut -d\; -f2`
  ip=`echo $i | cut -d\; -f3`
  echo "host $name {" >> $TARGET
  if [ "$mac" ]; then
    echo "  hardware ethernet $mac;" >> $TARGET
  fi
  echo "  fixed-address $ip;" >> $TARGET
  echo "}" >> $TARGET
  echo >> $TARGET
  echo "$name     IN      A       $ip" >> $TARGET2
done
for i in `seq 255`; do
  echo "_$i     IN      A       10.68.69.$i" >> $TARGET2
done
service bind9 restart
service isc-dhcp-server restart
echo "dhcp reloaded !"
service isc-dhcp-server status > /dev/null || echo dhcp down
service bind9 status > /dev/null || echo bind down
