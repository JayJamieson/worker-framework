# PHP worker base image.
#
# Build from the repository root so the shared entrypoint and bootstrap are in
# the build context:
#
#   docker build -f runtimes/php/Dockerfile -t worker-framework/php:8.3 .
#
# Applications extend this image, add their code and vendor directory, and set
# a CMD describing what to run:
#
#   FROM worker-framework/php:8.3
#   COPY . /var/task
#   RUN composer install --no-dev --optimize-autoloader --classmap-authoritative
#   CMD ["consume", "async"]

ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

ENV WORKER_ROOT=/var/task \
    WORKER_RUNTIME_DIR=/var/runtime \
    WORKER_RUNTIME_COMMAND="php /var/runtime/runtime.php" \
    COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    APP_DEBUG=0

# pcntl is what makes graceful shutdown possible and is not in the base image.
# The rest are the extensions a Symfony application and its Messenger
# transports normally expect; anything else belongs in the application's own
# Dockerfile.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libpq-dev; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        sockets \
        zip; \
    rm -rf /var/lib/apt/lists/*

# The -dev packages are deliberately left installed: `apt-get purge
# --auto-remove` on them takes the shared libraries the extensions just linked
# against with it, and the result only fails at runtime.

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# A worker is a long-lived CLI process, so opcache is worth enabling and its
# timestamp checks are not: the code cannot change under a running container.
RUN { \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'memory_limit=512M'; \
        echo 'date.timezone=UTC'; \
        echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/worker.ini

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY bootstrap /var/runtime/bootstrap
COPY runtimes/php/runtime.php /var/runtime/runtime.php
COPY runtimes/php/src /var/runtime/src

RUN chmod +x /usr/local/bin/entrypoint.sh /var/runtime/bootstrap

# Workers handle other people's data; there is no reason for them to be root.
RUN useradd --uid 1000 --create-home --shell /usr/sbin/nologin worker \
    && mkdir -p /var/task \
    && chown worker:worker /var/task

WORKDIR /var/task
USER worker

ENTRYPOINT ["entrypoint.sh"]

# Overridden by the application image; on its own this prints the runtime's
# "no worker specified" message rather than failing obscurely.
CMD ["handler"]
