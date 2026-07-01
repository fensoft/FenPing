#!/bin/bash
VERSION=1.3
set -e
docker build -t fensoft/fenping:$VERSION .
. .env
docker stop fenping || true
docker rm fenping || true
if [ "$DEV" ]; then
  EXTRA="-v `pwd`:/var/www/html"
fi
docker run -d --name fenping --privileged --env-file .env -e HOST_INTERFACE=end0 -e FENPING_NETWORK_MODE=host -v `pwd`/data/dhcp:/var/lib/dhcp -v /sys/fs/cgroup:/sys/fs/cgroup:ro -v `pwd`/data/db:/var/lib/mysql $EXTRA --network host --restart unless-stopped -h fenping fensoft/fenping:$VERSION
