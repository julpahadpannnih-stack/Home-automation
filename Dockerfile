# Dockerfile for Smart Home API (api.php)
# FIXED: Added .htaccess COPY for Apache rewrite support
# FIXED: Added mod_rewrite enable step
# Base image: official PHP 8.2 with Apache bundled in
FROM php:8.2-apache

# Install the mysqli and PDO MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    docker-php-ext-enable mysqli pdo_mysql

# Enable Apache mod_rewrite (needed if you later add URL routing)
RUN a2enmod rewrite

# Copy the API file into the Apache web root
COPY api.php /var/www/html/api.php

# FIXED: Copy .htaccess to allow direct access to api.php and block all other paths
COPY .htaccess /var/www/html/.htaccess

# FIXED: Set correct file permissions so Apache can read the files
RUN chown -R www-data:www-data /var/www/html && \
    chmod 644 /var/www/html/api.php && \
    chmod 644 /var/www/html/.htaccess

# Apache listens on port 80 inside the container.
# Render automatically maps this to its public HTTPS URL.
EXPOSE 80
