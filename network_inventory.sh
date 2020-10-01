#!/bin/bash
ME=`cd $(dirname $0); pwd`
config=`mktemp`
cat $ME/config.php | grep -v php | sed "s#\\\$##" | sed -e "s#[ ]*=[ ]*'#=#" | sed "s#';##" > $config
. $config
rm $config

lastinv=`mktemp`
mkdir -p $ME/nmap.raw
for i in `seq 254`; do
  nmap ${network}.$i -sS -v -oX $lastinv > /dev/null 2> /dev/null
  status=`cat $lastinv | xpath -e '//status/@state' -q | sed "s#.*=\"\(.*\)\"#\\1#"`
  if [ "$status" == "up" ]; then
    mv $lastinv $ME/nmap.raw/$i.xml
    chmod a+r $ME/nmap.raw/$i.xml
  fi
done
