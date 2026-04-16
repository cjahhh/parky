#!/bin/bash
export MYSQLHOST=$(echo $MYSQLHOST)
export MYSQLDATABASE=$(echo $MYSQLDATABASE)
export MYSQLUSER=$(echo $MYSQLUSER)
export MYSQLPASSWORD=$(echo $MYSQLPASSWORD)
export MYSQLPORT=$(echo $MYSQLPORT)
/usr/local/sbin/php-fpm -D
sleep 1
sed -i "s/PORT_PLACEHOLDER/$PORT/g" /etc/nginx/sites-enabled/default
nginx -g 'daemon off;'
