#!/bin/bash
set -e

MODE=${1:-}
if [ "$MODE" != "" ] && [ "$MODE" != "demo" ]; then
  echo "Usage: $0 [demo]" >&2
  exit 2
fi

if [ ! -f .env ]; then
  echo ".env not found; copy env.template to .env and edit it first" >&2
  exit 1
fi

for dir in db dnsmasq dnsmasq.d netboot backups state; do
  mkdir -p "$(pwd)/data/$dir"
done

DEMO_TMP=""
cleanup() {
  if [ -n "$DEMO_TMP" ] && [ -d "$DEMO_TMP" ]; then
    rm -rf "$DEMO_TMP"
  fi
}
trap cleanup EXIT

build_demo_backup() {
  if [ ! -f demo/db.sql ] || [ ! -f demo/manifest.json ] || [ ! -f demo/netboot-index.json ] || [ ! -d demo/netboot ]; then
    echo "demo source is incomplete" >&2
    exit 1
  fi

  DEMO_TMP=$(mktemp -d)
  mkdir -p "$DEMO_TMP/archive"
  install -m 0644 demo/db.sql "$DEMO_TMP/archive/db.sql"
  install -m 0644 demo/manifest.json demo/netboot-index.json "$DEMO_TMP/archive/"
  cp -a demo/netboot "$DEMO_TMP/archive/netboot"
  tar -czf "$DEMO_TMP/fenping-demo.tgz" -C "$DEMO_TMP/archive" .
  install -m 0600 "$DEMO_TMP/fenping-demo.tgz" data/backups/fenping-demo.tgz
  echo "demo backup built: data/backups/fenping-demo.tgz"
}

wait_for_fenping() {
  local response
  for i in $(seq 1 60); do
    response=$(curl -fsS http://127.0.0.1/api/health 2>/dev/null || true)
    case "$response" in
      '{"status":"ok"'*) return 0 ;;
    esac
    sleep 1
  done
  echo "FenPing did not become healthy" >&2
  docker compose logs --tail 100 app db >&2 || true
  return 1
}

if [ "$MODE" = "demo" ]; then
  build_demo_backup
fi

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
wait_for_fenping

if [ "$MODE" = "demo" ]; then
  docker exec fenping sh -c 'install -m 0644 /dev/null /etc/cron.d/fenping'
  BEFORE_DEMO="fenping-before-demo-$(date +%Y%m%d-%H%M%S).tgz"
  docker exec fenping php /opt/fenping/cli.php backup "/var/lib/fenping/backups/$BEFORE_DEMO"
  docker exec fenping php /opt/fenping/cli.php restore /var/lib/fenping/backups/fenping-demo.tgz
  wait_for_fenping
  echo "demo restored; previous state saved to data/backups/$BEFORE_DEMO"
fi
