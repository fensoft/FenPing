#!/bin/bash
ME=`cd $(dirname $0); pwd`
config=`mktemp`
cat $ME/config.php | grep -v php | sed "s#\\\$##" | sed -e "s#[ ]*=[ ]*'#=#" | sed "s#';##" > $config
. $config
rm $config
file1=`mktemp`
file2=`mktemp`
nmap $network.1-254 -sP -v -oG $file1 -T3 -e $interface --max-retries 10 > /dev/null 2> /dev/null
cat $file1 | grep "Host: " | sed "s#Host: ##" | sed "s#[ ]*Status: ##" | sed "s# ([)][\t]*#;#" > $file2
rm -f $file1
FROM=1
TO=254
if [ "$1" -a "$1" != "DEBUG" ]; then
  FROM=$1
  TO=$1
fi
for i in `seq $FROM $TO`; do
  status=`cat $file2 | grep "$network.$i;" | sed "s#$network.$i;##"`
  arp=`arp -a $network.$i | grep -v "no match found" | grep -v "incomplete" | sed "s#.* at ##" | sed "s# [[]ether.*##"`
  if [ "$status" == "Down" ]; then
    if [ "$arp" != "" ]; then
      status=arp
      noarp=`arping -C1 -c7 -w 200000 $network.$i | grep "packets received" | grep -c "0 packets received"`
      if [ "$noarp" = "1" ]; then
        status=arp-down
      else
        if [ `ping -c 1 10.68.69.140 > /dev/null; echo $?` = "0" ]; then
          status=Up
        fi
      fi
    fi
  fi
  if [ "$arp" == "" ]; then
    echo "INSERT INTO ping (ip,status) VALUES ('$network.$i', '${status}') on duplicate key update status=values(status)" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db_name} 2> /dev/null
    echo "INSERT INTO stats (ip,status) VALUES ('$network.$i', '${status}')" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db_name} 2> /dev/null
  else
    echo "INSERT INTO ping (ip,mac,status) VALUES ('$network.$i', '${arp}', '${status}') on duplicate key update mac=values(mac), status=values(status)" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db_name} 2> /dev/null
    echo "INSERT INTO stats (ip,mac,status) VALUES ('$network.$i', '${arp}', '${status}')" | mysql -h${db_host} -u${db_user} -p${db_pass} ${db_name} 2> /dev/null
  fi
  if [ "$1" != "" ]; then
    echo "$network.$i $status"
  fi
done
rm -f $file2
