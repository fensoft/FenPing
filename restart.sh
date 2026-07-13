#!/bin/bash
set -e

MODE=${1:-}
if [ "$MODE" != "" ] && [ "$MODE" != "demo" ] && [ "$MODE" != "dev" ] && [ "$MODE" != "rollback" ]; then
  echo "Usage: $0 [demo|dev|rollback]" >&2
  exit 2
fi

if [ ! -f .env ]; then
  echo ".env not found; copy env.template to .env and edit it first" >&2
  exit 1
fi

resolve_docker_socket() {
  local configured candidate
  configured=$(docker compose config --environment 2>/dev/null | sed -n 's/^DOCKER_SOCKET=//p' | tail -n1)
  candidate="$configured"
  if [ -z "$candidate" ] && [ -S /var/run/docker.sock ]; then
    candidate=/var/run/docker.sock
  fi
  if [ -n "$candidate" ] && [ -S "$candidate" ]; then
    export FENPING_DOCKER_SOCKET_SOURCE="$candidate"
    echo "Docker network discovery enabled through $candidate"
  else
    export FENPING_DOCKER_SOCKET_SOURCE=/dev/null
    if [ -n "$candidate" ]; then
      echo "Docker network discovery disabled: $candidate is not a Unix socket" >&2
    fi
  fi
}
resolve_docker_socket

for dir in database dnsmasq dnsmasq.d netboot backups state; do
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
  if [ ! -f demo/db.json ] || [ ! -f demo/manifest.json ] || [ ! -f demo/netboot-index.json ] || [ ! -d demo/netboot ]; then
    echo "demo source is incomplete" >&2
    exit 1
  fi

  DEMO_TMP=$(mktemp -d)
  mkdir -p "$DEMO_TMP/archive"
  install -m 0644 demo/db.json "$DEMO_TMP/archive/db.json"
  install -m 0644 demo/manifest.json demo/netboot-index.json "$DEMO_TMP/archive/"
  cp -a demo/netboot "$DEMO_TMP/archive/netboot"
  tar -czf "$DEMO_TMP/fenping-demo.tgz" -C "$DEMO_TMP/archive" .
  install -m 0600 "$DEMO_TMP/fenping-demo.tgz" data/backups/fenping-demo.tgz
  echo "demo backup built: data/backups/fenping-demo.tgz"
}

wait_for_fenping() {
  local response state
  for i in $(seq 1 60); do
    response=$(curl -fsS http://127.0.0.1/api/health/ready 2>/dev/null || true)
    case "$response" in
      '{"status":"ok"'*) return 0 ;;
    esac
    state=$(docker inspect fenping --format '{{.State.Status}}' 2>/dev/null || true)
    case "$state" in
      exited|dead|restarting)
        echo "FenPing exited during startup" >&2
        docker stop fenping >/dev/null 2>&1 || true
        docker compose logs --tail 100 app >&2 || true
        return 1
        ;;
    esac
    sleep 1
  done
  echo "FenPing did not become healthy" >&2
  docker compose logs --tail 100 app >&2 || true
  return 1
}

source "$(dirname "$0")/tools/restart-recovery.sh"
run_restart "$MODE"
