#!/bin/sh
#
# Container entrypoint, shared by every runtime.
#
# Its only job is to hand the arguments to the runtime bootstrap with `exec`,
# so that the bootstrap becomes PID 1 and receives the signals Docker sends on
# `docker stop`.

set -eu

: "${WORKER_RUNTIME_DIR:=/var/runtime}"

BOOTSTRAP="$WORKER_RUNTIME_DIR/bootstrap"

if [ ! -x "$BOOTSTRAP" ]; then
  echo "[RUNTIME] $BOOTSTRAP is missing or not executable" >&2
  exit 2
fi

exec "$BOOTSTRAP" "$@"
