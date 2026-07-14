#!/bin/bash
set -e

ROOT=$(cd "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
cd "$TMP"
mkdir -p data/database data/netboot data/backups data/state
touch .env

EVENTS="$TMP/events"
CONTAINER_RUNNING=true
docker() {
  echo "$*" >> "$EVENTS"
  case "$*" in
    "inspect fenping") return 0 ;;
    "inspect fenping --format {{.State.Running}}") echo "$CONTAINER_RUNNING" ;;
    "inspect fenping --format {{.Image}}") echo sha256:old ;;
    "inspect fenping --format {{.Config.Image}}") echo example/fenping:old ;;
    "image inspect sha256:old --format {{join .RepoDigests \"\\n\"}}") echo example/fenping@sha256:old ;;
    "image inspect example/fenping:new --format {{.Id}}") echo sha256:new ;;
    "image inspect sha256:new --format {{join .RepoDigests \"\\n\"}}") echo example/fenping@sha256:new ;;
    "compose config --quiet") return 0 ;;
    "compose config") printf 'services:\n  app:\n    image: example/fenping:new\n' ;;
    run\ --rm*sha256sum*) echo "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  archive.tgz" ;;
    exec\ fenping\ php\ /opt/fenping/cli.php\ backup\ *)
      archive_path=$(printf '%s\n' "$*" | awk '{print $NF}')
      printf archive > "$TMP/data/backups/$(basename "$archive_path")"
      ;;
  esac
}
wait_for_fenping() { return 0; }
build_demo_backup() { return 0; }

source "$ROOT/tools/restart-recovery.sh"
run_restart ""

JOURNAL=$(find data/state/upgrades -name '*.json' -type f | head -n1)
test -n "$JOURNAL"
test "$(journal_value "$JOURNAL" status)" = active
test "$(journal_value "$JOURNAL" previous_image_id)" = sha256:old
test "$(journal_value "$JOURNAL" target_image_id)" = sha256:new

VERIFY_LINE=$(grep -n '^run --rm ' "$EVENTS" | head -n1 | cut -d: -f1)
STOP_LINE=$(grep -n '^stop ' "$EVENTS" | head -n1 | cut -d: -f1)
test "$VERIFY_LINE" -lt "$STOP_LINE"
grep -q '^image tag sha256:old fenping-rollback:' "$EVENTS"
grep -q '^compose up -d --remove-orphans --pull never$' "$EVENTS"

sleep 1
: > "$EVENTS"
CONTAINER_RUNNING=false
run_restart "dev"

! grep -q '^start fenping$' "$EVENTS"
grep -q '^build --pull --build-arg FENPING_VERSION=dev --tag example/fenping:new \.$' "$EVENTS"
grep -q '^run --rm --env-file .env .* php /opt/fenping/cli.php backup ' "$EVENTS"
! grep -q '^exec fenping php /opt/fenping/cli.php backup ' "$EVENTS"

echo "restart recovery shell tests passed"
