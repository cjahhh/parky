FROM php:8.2-apache

# Fix: disable conflicting MPMs, enable only prefork
RUN a2dismod mpm_event mpm_worker && a2enmod mpm_prefork

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Copy everything into Apache root
COPY . /var/www/html/

# Debug: list files
RUN ls -la /var/www/html/

# Expose port 80
EXPOSE 80

# Run Apache in foreground
CMD ["apache2-foreground"]
