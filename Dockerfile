FROM php:8.2-apache

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    docker-php-ext-enable mysqli pdo_mysql

# Enable mod_rewrite
RUN a2enmod rewrite headers

# Copy API file
COPY api.php /var/www/html/api.php

# Fix Apache config: allow access to /var/www/html, enable AllowOverride
RUN sed -i 's|<Directory /var/www/>|<Directory /var/www/html/>|g' /etc/apache2/apache2.conf || true && \
    printf '<Directory /var/www/html>\n\tOptions Indexes FollowSymLinks\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/smarthome.conf && \
    a2enconf smarthome

# Write .htaccess inline
RUN printf 'Options -Indexes\nRewriteEngine On\nRewriteCond %%{REQUEST_METHOD} OPTIONS\nRewriteRule .* - [L]\n' \
    > /var/www/html/.htaccess

# Permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod 755 /var/www/html && \
    chmod 644 /var/www/html/api.php && \
    chmod 644 /var/www/html/.htaccess

EXPOSE 80
