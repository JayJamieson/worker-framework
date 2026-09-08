FROM worker-framework/php:8.3

COPY ReportWorker.php /var/task/

# A single-file worker: the runtime loads the path and calls what it returns.
CMD ["handler", "ReportWorker.php"]
