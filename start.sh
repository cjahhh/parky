#!/bin/bash
export MYSQLHOST=$(echo $MYSQLHOST)
export MYSQLDATABASE=$(echo $MYSQLDATABASE)
export MYSQLUSER=$(echo $MYSQLUSER)
export MYSQLPASSWORD=$(echo $MYSQLPASSWORD)
export MYSQLPORT=$(echo $MYSQLPORT)
export PARKY_TF_SERVICE_URL=$(echo $PARKY_TF_SERVICE_URL)

/usr/local/sbin/php-fpm -D
sed -i "s/PORT_PLACEHOLDER/${PORT}/g" /etc/nginx/sites-enabled/default
nginx -g 'daemon off;'
