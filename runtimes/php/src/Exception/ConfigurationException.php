<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Exception;

use WorkerFramework\Runtime\ExitCode;

/**
 * The runtime was started with arguments or environment it cannot act on.
 */
final class ConfigurationException extends WorkerException
{
    public function exitCode(): int
    {
        return ExitCode::CONFIGURATION_ERROR;
    }
}
