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
echo "@       IN      A       $myself" >> $TARGET2
for i in `echo 'select concat(name, ";", ifnull(mac, ""), ";", ip, ";", ifnull(router, ""),";", ifnull(dns, "")) from ips where ip is not null and name is not null' | mysql -h${db_host} -u${db_user} -p${db_pass} ${db_name} | tail -n+2`; do
  name=`echo $i | cut -d\; -f1`
  mac=`echo $i | cut -d\; -f2`
  ip=`echo $i | cut -d\; -f3`
  router=`echo $i | cut -d\; -f4`
  dns=`echo $i | cut -d\; -f5`
  echo "host $name {" >> $TARGET
  if [ "$mac" ]; then
    echo "  hardware ethernet $mac;" >> $TARGET
  fi
  echo "  fixed-address $ip;" >> $TARGET
  if [ "$router" != "" ]; then
    echo "  option routers $network.$router;" >> $TARGET
  fi
  if [ "$dns" != "" ]; then
    dns=`echo $dns | sed "s#[ ;]#,#g"`
    echo "option domain-name-servers $dns;" >> $TARGET
  fi
  echo "}" >> $TARGET
  echo >> $TARGET
  echo "$name     IN      A       $ip" >> $TARGET2
done
#echo "filename \"netboot.xyz.kpxe\";" >> $TARGET
#echo "next-server 10.68.69.7;" >> $TARGET
for i in `seq 255`; do
  echo "_$i     IN      A       $network.$i" >> $TARGET2
  echo "@$i     IN      A       $network.$i" >> $TARGET2
  echo "ip$i     IN      A       $network.$i" >> $TARGET2
done
service bind9 restart
service isc-dhcp-server restart
echo "dhcp reloaded !"
