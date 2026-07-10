#!/bin/bash
set -e

if [ ! -f .env ]; then
  echo ".env not found; copy env.template to .env and edit it first" >&2
  exit 1
fi

for dir in db dnsmasq dnsmasq.d netboot backups state; do
  mkdir -p "$(pwd)/data/$dir"
done

docker compose config --quiet
DOCKER_BUILDKIT=1 docker compose build

# Stop the legacy embedded database cleanly before the first split-container
# upgrade. On later runs the Compose-managed app has no local database server.
if docker inspect fenping >/dev/null 2>&1; then
  COMPOSE_SERVICE=`docker inspect --format '{{ index .Config.Labels "com.docker.compose.service" }}' fenping 2>/dev/null || true`
  if [ -z "$COMPOSE_SERVICE" ]; then
    docker exec fenping mariadb-admin --socket=/var/run/mysqld/mysqld.sock --user=root --password=root shutdown >/dev/null 2>&1 || true
  fi
  docker stop --time 30 fenping >/dev/null 2>&1 || true
  docker rm fenping >/dev/null 2>&1 || true
fi

docker compose up -d --remove-orphans
docker compose ps
