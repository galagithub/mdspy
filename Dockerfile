# mdspy - A web interface to show mdadm status
# Copyright (c) 2026, Dragos GALALAE (gala_dragos@yahoo.com)
# Licensed under the MIT License.

FROM php:8.3-apache

COPY mdspy.php /var/www/html/index.php

RUN a2enmod headers

EXPOSE 80
