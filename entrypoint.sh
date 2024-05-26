#!/bin/sh

# Initialization of environment, start job, run startup code
init() {
  echo "[BASH] Starting job $JOB_ID"
  curl -X POST "http://host.docker.internal:3000/job/$JOB_ID/start"
}

post_run_handler() {
  echo "[BASH] Job completed successfuly"
  echo "[BASH] Running cleanup tasks"
  curl -X POST -H 'Content-Type: text/plain' --data-binary '@/var/log/stdout.log' "http://host.docker.internal:3000/job/$JOB_ID/complete"
}

cleanup_handler() {
  echo "[BASH] Job stopped before completion"
  echo "[BASH] Running cleanup tasks"
  curl -X POST -H 'Content-Type: text/plain' --data-binary '@/var/log/stdout.log' "http://host.docker.internal:3000/job/$JOB_ID/fail"
}

# Sigterm Handler
sigterm_handler() {
  if [ $pid -ne 0 ]; then
    # the above if statement is important because it ensures
    # that the application has already started. without this you
    # could attempt cleanup steps if the application failed to
    # start, causing errors.
    echo "[BASH] SIGINT|SIGERM received for PID $pid"
    kill -15 "$pid"
    wait "$pid"
    cleanup_handler
    echo "[BASH] SIGINT|SIGERM handled for PID $pid"
  fi
  exit 143; # 128 + 15 -- SIGTERM
}

# Setup signal trap
# on callback execute the specified handler
trap 'sigterm_handler' TERM INT HUP KILL

# Initialization
init
# run job in background and record PID
"$@" &

# use this if you want job logs put into file and saved for later viewing
# >/var/log/stdout.log 2>/var/log/stderr.log "$@" &
>/var/log/stdout.log 2>&1 "$@" &

pid="$!"

# wait until job completes
wait "$pid"
return_code="$?"

# cleanup
post_run_handler
exit $return_code
