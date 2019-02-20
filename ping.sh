#!/bin/bash
ME=`cd $(dirname $0); pwd`
config=`mktemp`
cat $ME/config.php | grep -v php | sed "s#\\\$##" | sed -e "s#[ ]*=[ ]*'#=#" | sed "s#';##" > $config
. $config
file1=`mktemp`
file2=`mktemp`
nmap $network.1-254 -sP -v -oG $file1 -T5 -e p6p1 > /dev/null
cat $file1 | grep "Host: " | sed "s#Host: ##" | sed "s#[ ]*Status: ##" | sed "s# ([)][\t]*#;#" > $file2
rm -f $file1
for i in `seq 254`; do
  status=`cat $file2 | grep "$network.$i;" | sed "s#$network.$i;##"`
  arp=`arp -a $network.$i | grep -v "no match found" | grep -v "incomplete" | sed "s#.* at ##" | sed "s# [[]ether.*##"`
  if [ "$status" == "Down" ]; then
    if [ "$arp" != "" ]; then
      status=arp
      noarp=`arping -C1 -c5 -w 500000 $network.$i | grep "packets received" | grep -c "0 packets received"`
      if [ "$noarp" = "1" ]; then
        status=arp-down
      fi
    fi
  fi
  if [ "$arp" == "" ]; then
    echo "INSERT INTO ping (ip,status) VALUES ('$network.$i', '${status}') on duplicate key update status=values(status)" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db} 2> /dev/null
  else
    echo "INSERT INTO ping (ip,mac,status) VALUES ('$network.$i', '${arp}', '${status}') on duplicate key update mac=values(mac), status=values(status)" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db} 2> /dev/null
  fi
done
rm -f $file2
