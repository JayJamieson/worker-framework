FROM php:7.4-fpm

RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install \
  pcntl

COPY ./runtime.php /runtime/runtime.php
COPY ./job.php /runtime/job.php

COPY ./entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh", "php", "/runtime/runtime.php"]
