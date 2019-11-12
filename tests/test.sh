SITE=XXX
USER=XXX
PASS=XXX
TOKEN=`curl -s -X POST --form user=$USER --form pass=$PASS "${SITE}/api.php?method=login" | sed "s#\"##g"`
echo login: $TOKEN
echo bad auth: `curl -s -w "%{http_code}" -X POST "${SITE}/api.php?method=restore"`
echo good auth and restore: `curl -s --header "Authorization: $TOKEN" -w "%{http_code}" -X POST "${SITE}/api.php?method=restore"`
echo get ip: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ip&ip=10.68.69.1"`
echo get mac: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=mac&mac=70:3a:d8:50:81:26"`
echo del: `curl -s --header "Authorization: $TOKEN" -X DELETE "${SITE}/api.php?method=ip&id=66"`
echo get ip: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ip&ip=10.68.69.1"`
echo get ips: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ips" | wc -c`=9902
echo del category: `curl -s --header "Authorization: $TOKEN" -X DELETE "${SITE}/api.php?method=category&ip=10.68.69.1"`
echo get ips: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ips" | wc -c`=9838
echo add category: `curl -s --header "Authorization: $TOKEN" -X POST "${SITE}/api.php?method=category&ip=10.68.69.2&name=a"`
echo get ips: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ips" | wc -c`=9880
echo add ip: `curl -s --header "Authorization: $TOKEN" -X POST "${SITE}/api.php?method=ip&ip=10.68.69.250&mac=11:22:33:44:55:66"`
echo get ips: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ips" | wc -c`=9884
echo edit ip: `curl -s --header "Authorization: $TOKEN" -X PUT "${SITE}/api.php?method=ip&id=74&ip=10.68.69.251&mac=66:55:44:33:22:11&name=lol&repeater=0&important=0"`
echo get ips: `curl -s --header "Authorization: $TOKEN" -X GET "${SITE}/api.php?method=ips" | wc -c`=10032
