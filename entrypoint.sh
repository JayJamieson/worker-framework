#!/bin/sh

if [ $# -ne 1 ]; then
  echo "entrypoint.sh requires worker filename as first argument" 1>&2
  exit 142
fi
export WORKER="$1"

RUNTIME_ENTRYPOINT="$WORKER_RUNTIME_DIR/bootstrap"

exec $RUNTIME_ENTRYPOINT
