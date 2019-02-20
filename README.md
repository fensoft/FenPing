### Install
1. Edit config.php and import db.sql
2. run php composer.phar install
3. Add this crontab:

```
    0    * * * * flock -n /tmp/ping.lck -c "/var/www/html/ping/network_inventory.sh"
    *    * * * * flock -n /tmp/ping.lck -c "/var/www/html/ping/ping.sh"
```

or if you dont' have flock:

```
    0    * * * * /var/www/html/ping/network_inventory.sh
    *    * * * * /var/www/html/ping/ping.sh
```
