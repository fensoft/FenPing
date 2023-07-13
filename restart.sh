#!/bin/bash
docker build -t fensoft/fenping:1.1 .
. .env
docker network rm lan
docker network create -d macvlan --subnet=${NETWORK}.0/24 -o parent=${HOST_INTERFACE} lan
docker stop fenping
docker rm fenping
if [ "$DEV" ]; then
  EXTRA="-v `pwd`:/var/www/html"
fi
docker run -d --name fenping --privileged --env-file .env -v `pwd`/data/dhcp:/var/lib/dhcp -v /sys/fs/cgroup:/sys/fs/cgroup:ro --sysctl net.ipv4.conf.all.send_redirects=0 --ip ${IP} -v `pwd`/data/db:/var/lib/mysql $EXTRA --net lan --restart unless-stopped -h fenping fensoft/fenping:1.1
