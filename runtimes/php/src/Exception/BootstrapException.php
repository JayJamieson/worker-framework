<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Exception;

use WorkerFramework\Runtime\ExitCode;

/**
 * The user application could not be loaded or booted.
 */
final class BootstrapException extends WorkerException
{
    public function exitCode(): int
    {
        return ExitCode::BOOTSTRAP_ERROR;
    }
}
