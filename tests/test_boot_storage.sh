#!/bin/bash
set -euo pipefail

IMAGE=${1:-fenping-check}
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/root/database" "$TMP/root/outside" "$TMP/nested" "$TMP/nonroot/database"
printf 'database-content\n' > "$TMP/root/database/fenping.sqlite3"
printf 'outside-content\n' > "$TMP/root/outside/keep"
printf 'nested-content\n' > "$TMP/nested/keep"
ln -s ../outside/keep "$TMP/root/database/outside-link"
chmod 0777 "$TMP/root/database"
chmod 0666 "$TMP/root/database/fenping.sqlite3"
ROOT_CHECKSUM=$(sha256sum "$TMP/root/database/fenping.sqlite3" | awk '{print $1}')

DEFAULT_ID=$(docker run --rm --entrypoint sh "$IMAGE" -c 'printf "%s:%s" "$(id -u www-data)" "$(id -g www-data)"')
case "$DEFAULT_ID" in
  0:*|*:0) echo "www-data must remain unprivileged: $DEFAULT_ID" >&2; exit 1 ;;
esac

run_boot() {
  local fixture=$1 log=$2
  shift 2
  docker run --rm --network none \
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
    -e IFACE=fenping-test-missing \
    -e IP=192.0.2.2 \
    -e DHCP_NETWORK=192.0.2.0/24 \
    -e DHCP_DEFAULT_ROUTER=192.0.2.1 \
    -e DATABASE_PATH=/fixture/database/fenping.sqlite3 \
    -v "$fixture:/fixture" \
    "$@" \
    "$IMAGE" > "$log" 2>&1 || true
  grep -q '^PASS storage:' "$log"
}

run_boot "$TMP/root" "$TMP/root.log" -v "$TMP/nested:/fixture/database/nested"
docker run --rm --entrypoint sh \
  -v "$TMP/root:/fixture" \
  -v "$TMP/nested:/fixture/database/nested" \
  "$IMAGE" -c '
    test "$(stat -c "%u:%g" /fixture/database)" = "$(id -u www-data):$(id -g www-data)"
    test "$(stat -c "%u:%g" /fixture/database/fenping.sqlite3)" = "$(id -u www-data):$(id -g www-data)"
    test "$(stat -c "%a" /fixture/database)" = 2770
    test "$(stat -c "%a" /fixture/database/fenping.sqlite3)" = 660
    test -L /fixture/database/outside-link
    test "$(stat -c "%u:%g:%a" /fixture/outside/keep)" = "0:0:644"
    test "$(stat -c "%u:%g:%a" /fixture/database/nested/keep)" = "0:0:644"
  '
test "$(sha256sum "$TMP/root/database/fenping.sqlite3" | awk '{print $1}')" = "$ROOT_CHECKSUM"

printf 'database-content\n' > "$TMP/nonroot/database/fenping.sqlite3"
docker run --rm --entrypoint sh -v "$TMP/nonroot:/fixture" "$IMAGE" \
  -c 'chown -R 1234:1235 /fixture/database; chmod 0751 /fixture/database; chmod 0640 /fixture/database/fenping.sqlite3'
printf 'partial-repair\n' > "$TMP/nonroot/database/root-owned.sqlite3"
NONROOT_CHECKSUM=$(sha256sum "$TMP/nonroot/database/fenping.sqlite3" | awk '{print $1}')
run_boot "$TMP/nonroot" "$TMP/nonroot.log"
docker run --rm --entrypoint sh -v "$TMP/nonroot:/fixture" "$IMAGE" -c '
  test "$(stat -c "%u:%g:%a" /fixture/database)" = "1234:1235:751"
  test "$(stat -c "%u:%g:%a" /fixture/database/fenping.sqlite3)" = "1234:1235:640"
  test "$(stat -c "%u:%g:%a" /fixture/database/root-owned.sqlite3)" = "1234:1235:660"
'
test "$(sha256sum "$TMP/nonroot/database/fenping.sqlite3" | awk '{print $1}')" = "$NONROOT_CHECKSUM"

echo "boot storage ownership tests passed"
