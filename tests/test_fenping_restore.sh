#!/bin/bash
set -e

ROOT=$(cd "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/data/backups"
touch "$TMP/.env" "$TMP/data/backups/restore-test.tgz"

cat > "$TMP/bin/docker" <<'EOF'
#!/bin/bash
printf '%s\n' "$*" >> "$EVENTS"
if [ "$*" = "compose config" ]; then
  printf 'services:\n  app:\n    image: example/fenping:dev\n'
fi
EOF
chmod +x "$TMP/bin/docker"

EVENTS="$TMP/events"
export EVENTS
(
  cd "$TMP"
  PATH="$TMP/bin:$PATH" "$ROOT/fenping.sh" restore data/backups/restore-test.tgz
)

DOWN_LINE=$(grep -n '^compose down --remove-orphans$' "$EVENTS" | cut -d: -f1)
RESTORE_LINE=$(grep -n '^compose run --rm --no-deps --pull never --name fenping-restore-' "$EVENTS" | cut -d: -f1)
test -n "$DOWN_LINE"
test -n "$RESTORE_LINE"
test "$DOWN_LINE" -lt "$RESTORE_LINE"
grep -q ' app php /opt/fenping/cli.php restore /var/lib/fenping/backups/restore-test.tgz$' "$EVENTS"
! grep -q '^exec fenping ' "$EVENTS"
! grep -q '^build ' "$EVENTS"

: > "$EVENTS"
(
  cd "$TMP"
  PATH="$TMP/bin:$PATH" "$ROOT/fenping.sh" dev restore data/backups/restore-test.tgz
)

BUILD_LINE=$(grep -n '^build --pull --build-arg FENPING_VERSION=dev --tag example/fenping:dev \.$' "$EVENTS" | cut -d: -f1)
DOWN_LINE=$(grep -n '^compose down --remove-orphans$' "$EVENTS" | cut -d: -f1)
RESTORE_LINE=$(grep -n '^compose run --rm --no-deps --pull never --name fenping-restore-' "$EVENTS" | cut -d: -f1)
test -n "$BUILD_LINE"
test -n "$DOWN_LINE"
test -n "$RESTORE_LINE"
test "$BUILD_LINE" -lt "$DOWN_LINE"
test "$DOWN_LINE" -lt "$RESTORE_LINE"

: > "$EVENTS"
if (
  cd "$TMP"
  PATH="$TMP/bin:$PATH" "$ROOT/fenping.sh" dev restore data/backups/missing.tgz
); then
  echo "restore unexpectedly accepted a missing backup" >&2
  exit 1
fi
! grep -q '^compose down --remove-orphans$' "$EVENTS"
! grep -q '^build ' "$EVENTS"

echo "fenping restore shell tests passed"
