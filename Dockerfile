FROM php:8.3-apache

COPY mdspy.php /var/www/html/index.php

RUN a2enmod headers

EXPOSE 80
