# Dockerfile for Smart Home API (api.php)
# Base image: official PHP 8.2 with Apache bundled in
FROM php:8.2-apache

# Install the mysqli and PDO MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    docker-php-ext-enable mysqli pdo_mysql

# Copy the API file into the Apache web root
COPY api.php /var/www/html/api.php

# Apache listens on port 80 inside the container.
# Render automatically maps this to its public HTTPS URL.
EXPOSE 80
