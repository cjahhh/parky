FROM php:8.2-fpm

RUN apt-get update && apt-get install -y nginx unzip curl && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY nginx.conf /etc/nginx/sites-available/default
COPY start.sh /start.sh
RUN chmod +x /start.sh

COPY . /var/www/html/
WORKDIR /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
CMD ["/start.sh"]
