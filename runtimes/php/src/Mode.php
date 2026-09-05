<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use WorkerFramework\Runtime\Exception\ConfigurationException;

/**
 * The four shapes a PHP worker can take.
 */
enum Mode: string
{
    /** Invoke a callable/class handler once with a payload. */
    case Handler = 'handler';

    /** Run a Symfony Console command as the body of the worker. */
    case Console = 'console';

    /** Long-running Symfony Messenger consumer. */
    case Consume = 'consume';

    /** Handle exactly one Messenger message, then exit. */
    case Message = 'message';

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }

    public static function fromName(string $name): self
    {
        return self::tryFromName($name)
            ?? throw new ConfigurationException(sprintf(
                'Unknown worker mode "%s". Expected one of: %s.',
                $name,
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }
}
