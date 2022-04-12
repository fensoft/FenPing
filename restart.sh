#!/bin/bash
docker build -t fenping .
docker network rm lan
docker network create -d macvlan --subnet=10.68.69.0/24 -o parent=enp3s0 lan
docker stop fenping
docker rm fenping
docker run -d --name fenping --privileged -v `pwd`/data/dhcp:/var/lib/dhcp -v /sys/fs/cgroup:/sys/fs/cgroup:ro --sysctl net.ipv4.conf.all.send_redirects=0 --ip 10.68.69.2 -v `pwd`/data/db:/var/lib/mysql -v `pwd`:/var/www/html -p 80:80/tcp --net lan --restart unless-stopped -h fenping fenping:latest
