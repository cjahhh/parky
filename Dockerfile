FROM php:8.2-fpm

# Install nginx
RUN apt-get update && apt-get install -y nginx && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Nginx config
COPY nginx.conf /etc/nginx/sites-available/default

# Copy app files
COPY . /var/www/html/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Startup script
CMD bash -c "php-fpm -D && nginx -g 'daemon off;'"
