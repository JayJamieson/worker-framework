#!/bin/bash

## Redirecting Filehanders
# ln -sf /proc/$$/fd/1 /log/stdout.log
# ln -sf /proc/$$/fd/2 /log/stderr.log

## Pre execution handler
pre_execution_handler() {
  ## Pre Execution
  echo "starting job with command :$@"
  echo "Starting pre execution of command $@"
}

## Post execution handler
post_execution_handler() {
  ## Post Execution
  echo "Completing post execution of command $@"
}

## Sigterm Handler
sigterm_handler() {
  if [ $pid -ne 0 ]; then
    # the above if statement is important because it ensures
    # that the application has already started. without this you
    # could attempt cleanup steps if the application failed to
    # start, causing errors.
    kill -15 "$pid"
    wait "$pid"
    post_execution_handler
  fi
  exit 143; # 128 + 15 -- SIGTERM
}

## Setup signal trap
# on callback execute the specified handler
trap 'sigterm_handler' TERM INT HUP KILL

## Initialization
pre_execution_handler

## Start Process
# Application can log to stdout/stderr, /log/stdout.log or /log/stderr.log
# >/log/stdout.log 2>/log/stderr.log "$@" &

# run process in background and record PID
"$@" &

pid="$!"
# Application can log to stdout/stderr, /log/stdout.log or /log/stderr.log

## Wait forever until app dies
wait "$pid"
return_code="$?"

## Cleanup
post_execution_handler
exit $return_code
