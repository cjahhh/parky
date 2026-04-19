#!/bin/bash
export MYSQLHOST=$(echo $MYSQLHOST)
export MYSQLDATABASE=$(echo $MYSQLDATABASE)
export MYSQLUSER=$(echo $MYSQLUSER)
export MYSQLPASSWORD=$(echo $MYSQLPASSWORD)
export MYSQLPORT=$(echo $MYSQLPORT)
export PARKY_TF_SERVICE_URL=$(echo $PARKY_TF_SERVICE_URL)

# Pass env vars to PHP-FPM
echo "env[PARKY_TF_SERVICE_URL] = $(echo $PARKY_TF_SERVICE_URL)" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "env[MYSQLHOST] = $(echo $MYSQLHOST)" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "env[MYSQLDATABASE] = $(echo $MYSQLDATABASE)" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "env[MYSQLUSER] = $(echo $MYSQLUSER)" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "env[MYSQLPASSWORD] = $(echo $MYSQLPASSWORD)" >> /etc/php/8.2/fpm/pool.d/www.conf
echo "env[MYSQLPORT] = $(echo $MYSQLPORT)" >> /etc/php/8.2/fpm/pool.d/www.conf

/usr/local/sbin/php-fpm -D
sed -i "s/PORT_PLACEHOLDER/${PORT}/g" /etc/nginx/sites-enabled/default
nginx -g 'daemon off;'
