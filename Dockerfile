FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

# Copy everything into Apache root
COPY . /var/www/html/

# Debug: list files (optional but helpful)
RUN ls -la /var/www/html/

EXPOSE 80
