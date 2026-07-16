#!/bin/bash
set -e

usage() {
  printf '%s\n' \
    "Usage:" \
    "  $0 [restart]" \
    "  $0 start" \
    "  $0 destroy" \
    "  $0 dev" \
    "  $0 demo" \
    "  $0 rollback" \
    "  $0 publish [version]"
}

usage_error() {
  usage >&2
  exit 2
}

run_publish() (
  set -euo pipefail

  if [ "$#" -gt 1 ]; then
    usage_error
  fi

  IMAGE=${FENPING_IMAGE:-fensoft/fenping}
  VERSION=${1:-${FENPING_VERSION:-1.8}}
  PLATFORMS=linux/arm64,linux/amd64,linux/arm/v7
  BUILDER=${BUILDX_BUILDER:-fenping-multiarch}
  PUBLISH_LATEST=${PUBLISH_LATEST:-1}

  if [[ ! "$IMAGE" =~ ^[a-z0-9]+([._-][a-z0-9]+)*/[a-z0-9]+([._-][a-z0-9]+)*$ ]]; then
    echo "invalid Docker Hub image: $IMAGE" >&2
    exit 2
  fi
  if [[ ! "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
    echo "invalid image version: $VERSION" >&2
    exit 2
  fi

  echo "installing binfmt emulators"
  docker run --privileged --rm tonistiigi/binfmt --install all

  if ! docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
    docker buildx create --name "$BUILDER" --driver docker-container --use >/dev/null
  else
    docker buildx use "$BUILDER"
    docker buildx stop "$BUILDER" >/dev/null
  fi
  docker buildx inspect --bootstrap >/dev/null

  SUPPORTED=$(docker buildx inspect | sed -n 's/^Platforms:[[:space:]]*//p')
  SUPPORTED=${SUPPORTED//[[:space:]]/}
  SUPPORTED=${SUPPORTED//\*/}
  IFS=',' read -ra REQUESTED <<< "$PLATFORMS"
  for platform in "${REQUESTED[@]}"; do
    platform=${platform//[[:space:]]/}
    if [ -z "$platform" ] || [[ ",$SUPPORTED," != *",$platform,"* ]]; then
      echo "builder does not support $platform" >&2
      echo "available platforms: $SUPPORTED" >&2
      echo "binfmt installation did not enable every requested platform" >&2
      exit 1
    fi
  done

  TAGS=(--tag "$IMAGE:$VERSION")
  if [ "$PUBLISH_LATEST" = "1" ]; then
    TAGS+=(--tag "$IMAGE:latest")
  fi

  echo "publishing $IMAGE:$VERSION for $PLATFORMS"
  docker buildx build \
    --platform "$PLATFORMS" \
    --build-arg "FENPING_VERSION=$VERSION" \
    --pull \
    --push \
    --provenance=mode=max \
    --sbom=true \
    "${TAGS[@]}" \
    .

  docker buildx imagetools inspect "$IMAGE:$VERSION"
)

COMMAND=${1:-restart}
if [ "$#" -gt 0 ]; then
  shift
fi

case "$COMMAND" in
  restart)
    [ "$#" -eq 0 ] || usage_error
    ACTION="restart"
    MODE=""
    ;;
  dev|demo|rollback)
    [ "$#" -eq 0 ] || usage_error
    ACTION="restart"
    MODE="$COMMAND"
    ;;
  start|destroy)
    [ "$#" -eq 0 ] || usage_error
    ACTION="$COMMAND"
    MODE=""
    ;;
  publish)
    run_publish "$@"
    exit 0
    ;;
  help|-h|--help)
    [ "$#" -eq 0 ] || usage_error
    usage
    exit 0
    ;;
  *)
    usage_error
    ;;
esac

if [ ! -f .env ]; then
  echo ".env not found; copy env.template to .env and edit it first" >&2
  exit 1
fi

if [ "$ACTION" = "destroy" ]; then
  docker compose down --remove-orphans
  echo "FenPing container removed; persistent data and images were preserved"
  exit 0
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

if [ "$ACTION" = "start" ]; then
  docker compose up -d --remove-orphans --pull never
  docker compose ps
  wait_for_fenping
  echo "FenPing started from the configured local image"
else
  source "$(dirname "$0")/tools/restart-recovery.sh"
  run_restart "$MODE"
fi
