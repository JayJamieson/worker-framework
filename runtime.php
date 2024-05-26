<?php
declare(strict_types=1);

require 'job.php';

function error_handler($errno, $errstr, $errfile, $errline)
{
  // handle error with sentry or cloudwatch logging here
  printf("[PHP] %s\n", 'error_handler rethrowing errors as exceptions');
  throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}

function exception_handler(Throwable $ex) {
  printf("[PHP] %s\n", 'exception_handler handling exception');
  printf("[PHP] %s\n", $ex);
  exit(1);
}

set_error_handler("error_handler");
set_exception_handler("exception_handler");

// Register a signal handler to enable running code in php before exit.
// Could attempt a cleanup of resources or revert half done work
pcntl_async_signals(true);
function sigint()
{
  printf("[PHP] %s\n\n", 'SIGINT|SIGERM received');
  exit(1);
}

pcntl_signal(SIGTERM, 'sigint', false);
pcntl_signal(SIGINT, 'sigint', false);
pcntl_signal(SIGHUP, 'sigint', false);

// Key Value pair cli arguments from docker can be parsed into associative array to pass to jobs
// foo=bar -> [ 'foo' => 'bar']
// we can also pass env variables from docker
$context = [];
$company_id = getenv("COMPANY_ID");

if (isset($argv)) {
  parse_str(implode('&', array_slice($argv, 1)), $context);
}

$class = $context["name"];

$job = new $class($company_id, $context);

$job->perform();
