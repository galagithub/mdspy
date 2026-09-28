# mdspy - A web interface to show mdadm status
# Copyright (c) 2026, Dragos GALALAE (gala_dragos@yahoo.com)
# Licensed under the MIT License.

FROM php:8.5-apache

RUN apt update && apt install -y libmosquitto-dev && pecl install mosquitto && docker-php-ext-enable mosquitto

COPY mdspy.php /var/www/html/index.php
COPY mqtt_worker.php /var/www/html/mqtt_worker.php

RUN a2enmod headers

# Use a wrapper script to start both Apache and the MQTT worker
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["/usr/local/bin/start.sh"]
