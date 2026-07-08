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
docker run -d \
  --name fenping \
  --cap-drop ALL \
  --cap-add AUDIT_WRITE \
  --cap-add CHOWN \
  --cap-add DAC_OVERRIDE \
  --cap-add FOWNER \
  --cap-add SETGID \
  --cap-add SETUID \
  --cap-add NET_ADMIN \
  --cap-add NET_BIND_SERVICE \
  --cap-add NET_BROADCAST \
  --cap-add NET_RAW \
  --env-file .env \
  -e HOST_INTERFACE=end0 \
  -e FENPING_NETWORK_MODE=host \
  -v `pwd`/data/dnsmasq:/var/lib/misc \
  -v `pwd`/data/nmap:/var/www/html/nmap \
  -v `pwd`/data/db:/var/lib/mysql \
  $EXTRA \
  --network host \
  --restart unless-stopped \
  -h fenping \
  fensoft/fenping:$VERSION
 
