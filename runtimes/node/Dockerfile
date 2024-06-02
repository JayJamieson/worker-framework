FROM node:20-alpine

ENV WORKER_ROOT=/var/task
ENV WORKER_RUNTIME_DIR=/var/runtime

WORKDIR /var/task

COPY entrypoint.sh /usr/local/bin/
COPY bootstrap "$WORKER_RUNTIME_DIR/"

COPY runtime.mjs "$WORKER_RUNTIME_DIR/"

RUN chmod +x /usr/local/bin/entrypoint.sh
RUN chmod +x /var/runtime/bootstrap

ENTRYPOINT ["entrypoint.sh"]
