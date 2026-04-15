FROM php:8.2-apache

# Install MySQL PDO driver
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache rewrite (optional but good)
RUN a2enmod rewrite

# Copy project files
COPY . /var/www/html/
