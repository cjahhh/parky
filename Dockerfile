FROM php:8.2-fpm

# Install nginx
RUN apt-get update && apt-get install -y nginx && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Find where php-fpm actually is
RUN find / -name "php-fpm*" 2>/dev/null

# Nginx config
COPY nginx.conf /etc/nginx/sites-available/default

# Copy app files
COPY . /var/www/html/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD bash -c "/usr/local/sbin/php-fpm -D && sed -i \"s/PORT_PLACEHOLDER/$PORT/g\" /etc/nginx/sites-enabled/default && nginx -g 'daemon off;'"
