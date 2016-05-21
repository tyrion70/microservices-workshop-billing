FROM php:5.6-apache
RUN docker-php-ext-install mysql
RUN docker-php-ext-configure mysql
RUN a2enmod rewrite
COPY web/ /var/www/html
COPY amialive /usr/local/bin
