<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Exception;

use WorkerFramework\Runtime\ExitCode;

/**
 * The configured handler, command or transport does not exist.
 */
final class HandlerNotFoundException extends WorkerException
{
    public function exitCode(): int
    {
        return ExitCode::HANDLER_NOT_FOUND;
    }
}
