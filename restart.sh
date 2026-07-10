#!/bin/bash
VERSION=1.5
set -e
export DOCKER_BUILDKIT=1
if [ ! -f .env ]; then
  echo ".env not found; copy env.template to .env and edit it first" >&2
  exit 1
fi
mkdir -p `pwd`/data/db
mkdir -p `pwd`/data/dnsmasq
mkdir -p `pwd`/data/dnsmasq.d
mkdir -p `pwd`/data/nmap
mkdir -p `pwd`/data/netboot
mkdir -p `pwd`/data/backups
mkdir -p `pwd`/data/state
DOCKER_BUILDKIT=1 docker build --network=host -t fensoft/fenping:$VERSION .
. .env
docker stop fenping || true
docker rm fenping || true
if [ "$DEV" ]; then
  EXTRA="-v `pwd`:/opt/fenping"
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
  -v `pwd`/data/dnsmasq:/var/lib/misc \
  -v `pwd`/data/dnsmasq.d:/etc/dnsmasq.d \
  -v `pwd`/data/nmap:/var/lib/fenping/nmap \
  -v `pwd`/data/netboot:/var/lib/fenping/netboot \
  -v `pwd`/data/backups:/var/lib/fenping/backups \
  -v `pwd`/data/state:/var/lib/fenping/state \
  -v `pwd`/data/db:/var/lib/mysql \
  $EXTRA \
  --network host \
  --restart unless-stopped \
  -h fenping \
  fensoft/fenping:$VERSION
docker ps --filter name=fenping
