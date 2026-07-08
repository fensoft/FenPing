#!/bin/bash
VERSION=1.4
set -e
DOCKER_BUILDKIT=1 docker build --progress=plain --network=host -t fensoft/fenping:$VERSION .
. .env
mkdir -p `pwd`/data/nmap
docker stop fenping || true
docker rm fenping || true
if [ "$DEV" ]; then
  EXTRA="-v `pwd`:/var/www/html"
fi
docker run -d --name fenping --privileged --env-file .env -e HOST_INTERFACE=end0 -e FENPING_NETWORK_MODE=host -v `pwd`/data/dnsmasq:/var/lib/misc -v `pwd`/data/nmap:/var/www/html/nmap -v /sys/fs/cgroup:/sys/fs/cgroup:ro -v `pwd`/data/db:/var/lib/mysql $EXTRA --network host --restart unless-stopped -h fenping fensoft/fenping:$VERSION
 
