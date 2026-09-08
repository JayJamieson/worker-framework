<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Exception;

use RuntimeException;
use WorkerFramework\Runtime\ExitCode;

/**
 * Base class for every failure the runtime raises on its own behalf.
 *
 * Each subclass carries the exit code the container should report, so the
 * top-level handler in Runtime never has to map exception types to numbers.
 */
abstract class WorkerException extends RuntimeException
{
    public function exitCode(): int
    {
        return ExitCode::WORKER_ERROR;
    }
}
