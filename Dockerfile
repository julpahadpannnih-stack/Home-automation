FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Allow Apache to serve files properly
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/smarthome.conf \
    && a2enconf smarthome

COPY api.php /var/www/html/api.php

RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
