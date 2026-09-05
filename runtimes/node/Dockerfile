# Node.js worker base image.
#
# Build from the repository root:
#
#   docker build -f runtimes/node/Dockerfile -t worker-framework/node:20 .

ARG NODE_VERSION=20
FROM node:${NODE_VERSION}-slim

ENV WORKER_ROOT=/var/task \
    WORKER_RUNTIME_DIR=/var/runtime \
    WORKER_RUNTIME_COMMAND="node --enable-source-maps /var/runtime/runtime.mjs" \
    NODE_ENV=production

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY bootstrap /var/runtime/bootstrap
COPY runtimes/node/runtime.mjs /var/runtime/runtime.mjs

RUN chmod +x /usr/local/bin/entrypoint.sh /var/runtime/bootstrap \
    && mkdir -p /var/task \
    && chown node:node /var/task

WORKDIR /var/task
USER node

ENTRYPOINT ["entrypoint.sh"]

CMD ["index.mjs"]
