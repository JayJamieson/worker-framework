<?php

/**
 * PHP worker runtime entry point.
 *
 * Invoked by the shell bootstrap as:
 *
 *   php /var/runtime/runtime.php <mode> [arguments...]
 *
 * Everything else - which application to boot, what to run, how long to let it
 * run - comes from the environment. See the README for the full list.
 */

declare(strict_types=1);

use WorkerFramework\Runtime\Exception\WorkerException;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Runtime;

require __DIR__ . '/src/autoload.php';

// Workers are not web requests: an unbounded run time is the whole point, and
// output should reach the log as it happens rather than at the end.
set_time_limit(0);
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

try {
    exit(Runtime::create($argv)->run());
} catch (WorkerException $error) {
    // Configuration is read before the logger exists, so its failures are
    // reported here - as a message an operator can act on, not a trace.
    fwrite(STDERR, sprintf("[RUNTIME] %s\n", $error->getMessage()));

    exit($error->exitCode());
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("[RUNTIME] fatal: %s\n%s\n", $error->getMessage(), $error->getTraceAsString()));

    exit(ExitCode::CONFIGURATION_ERROR);
}
