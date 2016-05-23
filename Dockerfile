FROM php:5.6-apache
RUN apt-get update
RUN apt-get -y install vim
RUN echo "date.timezone = 'Europe/Amsterdam'" >> /usr/local/etc/php/conf.d/docker-php-ext-mysql.ini
RUN docker-php-ext-install mysql
RUN docker-php-ext-configure mysql
RUN a2enmod rewrite
COPY web/ /var/www/html
COPY amialive /usr/local/bin
COPY startme /usr/local/bin
COPY debug.log /var/www/html
RUN chmod 777 /var/www/html/debug.log
CMD ["startme"]
