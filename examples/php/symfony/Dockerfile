# One image, several workers: which one a container becomes is decided by its
# command, not by a separate build.
#
#   docker build -t reports .

FROM worker-framework/php:8.3

# Dependencies first, so a source-only change does not reinstall them.
# A real project copies composer.lock alongside this and drops --no-lock.
COPY --chown=worker:worker composer.json /var/task/

RUN composer install --no-dev --no-scripts --no-autoloader --no-lock \
    && composer clear-cache

COPY --chown=worker:worker . /var/task/

# The class map has to be built after the source is in place, or it will not
# contain the application's own classes.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

ENV APP_ENV=prod \
    APP_DEBUG=0

# Warm the cache at build time rather than on every container start.
RUN php bin/console cache:warmup --env=prod

# The default: consume the async transport until told to stop. Override per
# deployment - see docker-compose.yml.
CMD ["consume", "async"]
