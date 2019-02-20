### Install
1. add this to ```/etc/dhcp/dhcpd.conf```:
```
include "/etc/dhcp/dhcpd.hosts";
```
2. add this to ```/etc/bind/named.conf.options```:
```
zone "lan" {
        type master;
        check-names ignore;
        file "/etc/bind/lan";
};
```
3. Add this to ```/etc/sudoers```
```
www-data ALL=(ALL) NOPASSWD: ALL
```
4. Edit config.php and import db.sql
5. Run php composer.phar install
6. Add this crontab:

```
    0    * * * * flock -n /tmp/ping.lck -c "/var/www/html/ping/network_inventory.sh"
    *    * * * * flock -n /tmp/ping.lck -c "/var/www/html/ping/ping.sh"
```

or if you dont' have flock:

```
    0    * * * * /var/www/html/ping/network_inventory.sh
    *    * * * * /var/www/html/ping/ping.sh
```
