# An existing application, containerised as a worker without changing how it
# boots. Build the base image first, from the repository root:
#
#   docker build -f runtimes/php/Dockerfile -t worker-framework/php:8.3 .

FROM worker-framework/php:8.3

COPY --chown=worker:worker . /var/task

# Whatever this application already needs. The runtime only requires that
# worker.php can boot it.
RUN composer install --no-dev --optimize-autoloader && composer clear-cache

ENV WORKER_JOB_REPORTER='Acme\Worker\JobStatusReporter'

# Below the scheduler's own stop timeout, so a wedged run reports
# stopped(forced) before anything resorts to SIGKILL.
ENV WORKER_SHUTDOWN_TIMEOUT=45

CMD ["handler", "Acme\\Worker\\SendInvoicesJob::perform"]
