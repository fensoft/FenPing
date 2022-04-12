#!/bin/bash
ip address add 192.168.1.2/24 dev eth0
ip address add 192.168.10.2/24 dev eth0
route del default gw 10.68.69.7
route add default gw 10.68.69.3
iptables -A FORWARD -i eth0 -j ACCEPT
iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
mkdir /var/run/mysqld
chown mysql:mysql /var/run/mysqld
cd /var/www/html
chown mysql:mysql -R /var/lib/mysql
sudo -u mysql mysqld&
sleep 2
./ips2hosts.sh
cron
apachectl -D FOREGROUND
