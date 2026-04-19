#!/bin/bash
export MYSQLHOST=$(echo $MYSQLHOST)
export MYSQLDATABASE=$(echo $MYSQLDATABASE)
export MYSQLUSER=$(echo $MYSQLUSER)
export MYSQLPASSWORD=$(echo $MYSQLPASSWORD)
export MYSQLPORT=$(echo $MYSQLPORT)
export PARKY_TF_SERVICE_URL=$(echo $PARKY_TF_SERVICE_URL)

# Pass env vars to PHP-FPM - only write if value is not empty
if [ -n "$PARKY_TF_SERVICE_URL" ]; then
    echo "env[PARKY_TF_SERVICE_URL] = $PARKY_TF_SERVICE_URL" >> /usr/local/etc/php-fpm.d/www.conf
else
    echo "env[PARKY_TF_SERVICE_URL] = https://detector-production-up.railway.app/detect" >> /usr/local/etc/php-fpm.d/www.conf
fi

echo "env[MYSQLHOST] = $MYSQLHOST" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLDATABASE] = $MYSQLDATABASE" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLUSER] = $MYSQLUSER" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLPASSWORD] = $MYSQLPASSWORD" >> /usr/local/etc/php-fpm.d/www.conf
echo "env[MYSQLPORT] = $MYSQLPORT" >> /usr/local/etc/php-fpm.d/www.conf

/usr/local/sbin/php-fpm -D
sed -i "s/PORT_PLACEHOLDER/${PORT}/g" /etc/nginx/sites-enabled/default
nginx -g 'daemon off;'
