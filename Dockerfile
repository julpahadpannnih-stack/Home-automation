# Dockerfile for Smart Home API (api.php)
# Base image: official PHP 8.2 with Apache bundled in
FROM php:8.2-apache

# Install the mysqli and PDO MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    docker-php-ext-enable mysqli pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy the API file into the Apache web root
COPY api.php /var/www/html/api.php

# Write .htaccess directly — no need to commit a separate .htaccess file to your repo
RUN echo 'Options -Indexes\n\
<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteCond %{REQUEST_METHOD} OPTIONS\n\
    RewriteRule .* - [L]\n\
</IfModule>' > /var/www/html/.htaccess

# Allow AllowOverride so .htaccess is respected by Apache
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set correct file permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod 644 /var/www/html/api.php && \
    chmod 644 /var/www/html/.htaccess

# Apache listens on port 80 inside the container.
EXPOSE 80
