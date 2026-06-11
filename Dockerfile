FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy entire project into Apache web root
COPY . /var/www/html/

# Set Apache DocumentRoot to the public folder
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/html>|<Directory /var/www/html/public>|g' /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
