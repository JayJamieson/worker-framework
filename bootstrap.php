<?php

function exception_error_handler($errno, $errstr, $errfile, $errline)
{
  echo "here";
  // handle error with sentry or cloudwatch logging here
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}
set_error_handler("exception_error_handler");

pcntl_async_signals(true);

function sigint()
{
  printf("\n[PHP] %s\n", 'SIGINT|SIGERM received');
  exit;
}

pcntl_signal(SIGTERM, 'sigint', false);
pcntl_signal(SIGINT, 'sigint', false);
pcntl_signal(SIGHUP, 'sigint', false);
