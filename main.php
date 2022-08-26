<?php

declare(strict_types=1);

require 'job.php';
require 'bootstrap.php';

// Key Value pair cli arguments from docker can be parsed into associative array to pass to jobs
// foo=bar -> [ 'foo' => 'bar']
// we can also pass env variables from docker
$context = [];
$company_id = getenv("COMPANY_ID");

if (isset($argv)) {
  parse_str(implode('&', array_slice($argv, 1)), $context);
}

$job = new Hot_new_fergus_job($company_id, $context);

$job->perform();
