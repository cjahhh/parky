#!/bin/bash
export MYSQLHOST=$(echo $MYSQLHOST)
export MYSQLDATABASE=$(echo $MYSQLDATABASE)
export MYSQLUSER=$(echo $MYSQLUSER)
export MYSQLPASSWORD=$(echo $MYSQLPASSWORD)
export MYSQLPORT=$(echo $MYSQLPORT)
export PARKY_TF_SERVICE_URL=$(echo $PARKY_TF_SERVICE_URL)

# Pass env vars to PHP-FPM
echo "env[PARKY_TF_SERVICE_URL] = $(echo $PARKY_TF_SERVICE_URL)" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLHOST] = $(echo $MYSQLHOST)" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLDATABASE] = $(echo $MYSQLDATABASE)" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLUSER] = $(echo $MYSQLUSER)" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLPASSWORD] = $(echo $MYSQLPASSWORD)" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLPORT] = $(echo $MYSQLPORT)" >> /usr/local/etc/php-fpm.d/www.conf

/usr/local/sbin/php-fpm -D
sed -i "s/PORT_PLACEHOLDER/${PORT}/g" /etc/nginx/sites-enabled/default
nginx -g 'daemon off;'
