SITE=XXX
PASS=XXX
JSON='Content-Type: application/json'
COOKIE=/tmp/fenping-cookie.txt
echo inventory: `curl -s -w "%{http_code}" "${SITE}/api/inventory" -o /tmp/fenping-inventory.json`
echo inventory bytes: `wc -c < /tmp/fenping-inventory.json`
echo login: `curl -s -w "%{http_code}" -c "$COOKIE" -X POST -H "$JSON" -d "{\"password\":\"$PASS\"}" "${SITE}/api/auth/login" -o /tmp/fenping-login.json`
echo refresh: `curl -s -w "%{http_code}" -b "$COOKIE" -X POST "${SITE}/api/ping/refresh" -o /tmp/fenping-refresh.json`
echo bad category: `curl -s -w "%{http_code}" -X POST -H "$JSON" -d '{"ip":"10.68.69.2","name":"a","password":"bad"}' "${SITE}/api/categories"`
echo add category: `curl -s -w "%{http_code}" -b "$COOKIE" -X POST -H "$JSON" -d '{"ip":"10.68.69.2","name":"a"}' "${SITE}/api/categories"`
echo del category: `curl -s -w "%{http_code}" -b "$COOKIE" -X DELETE -H "$JSON" -d '{"ip":"10.68.69.2"}' "${SITE}/api/categories"`
