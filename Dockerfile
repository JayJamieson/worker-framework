FROM php:7.4-fpm

RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install \
  pcntl

COPY ./main.php /main/main.php
COPY ./job.php /main/job.php
COPY ./bootstrap.php /main/bootstrap.php

COPY ./entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh", "php", "/main/main.php"]
