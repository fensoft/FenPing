#!/bin/bash
set -e

SITE=${SITE:-XXX}
PASS=${PASS:-XXX}
JSON='Content-Type: application/json'
COOKIE=${COOKIE:-/tmp/fenping-cookie.txt}
OUT_DIR=${OUT_DIR:-/tmp}

slug() {
  echo "$1" | sed 's#[^A-Za-z0-9]#-#g'
}

expect_code() {
  local name="$1"
  local expected="$2"
  shift 2

  local out="${OUT_DIR}/fenping-$(slug "$name").json"
  local code
  code=$(curl -s -w "%{http_code}" "$@" -o "$out")
  echo "$name: $code"

  if [ "$expected" != "" ] && [ "$code" != "$expected" ]; then
    echo "expected $expected for $name"
    cat "$out"
    exit 1
  fi
}

PUBLIC_GET_ROUTES=(
  /api/health
  /api/auth/session
  /api/inventory
  /api/notify
  /api/netboot/images
)

DENIED_WEB_PATHS=(
  /.env
  /config.php
  /db.sql
  /backups/test.tgz
  /netboot/test.efi
  /state/test
)

rm -f "$COOKIE"

for route in "${PUBLIC_GET_ROUTES[@]}"; do
  expect_code "GET $route" 200 "${SITE}${route}"
done

for route in "${DENIED_WEB_PATHS[@]}"; do
  expect_code "GET $route" 403 "${SITE}${route}"
done

echo inventory bytes: `wc -c < "${OUT_DIR}/fenping-GET--api-inventory.json"`

expect_code "POST /api/auth/login" 200 -c "$COOKIE" -X POST -H "$JSON" -d "{\"password\":\"$PASS\"}" "${SITE}/api/auth/login"

if [ "$TEST_IP" != "" ]; then
  expect_code "GET /api/scans/ip/status" 200 "${SITE}/api/scans/${TEST_IP}/status"
  expect_code "GET /api/scans/ip/history" 200 "${SITE}/api/scans/${TEST_IP}/history"
fi

expect_code "POST /api/ping/refresh" 200 -b "$COOKIE" -X POST "${SITE}/api/ping/refresh"
expect_code "POST /api/categories bad" 403 -X POST -H "$JSON" -d '{"ip":"10.68.69.2","name":"a","password":"bad"}' "${SITE}/api/categories"
expect_code "POST /api/categories" 200 -b "$COOKIE" -X POST -H "$JSON" -d '{"ip":"10.68.69.2","name":"a"}' "${SITE}/api/categories"
expect_code "DELETE /api/categories" 200 -b "$COOKIE" -X DELETE -H "$JSON" -d '{"ip":"10.68.69.2"}' "${SITE}/api/categories"
