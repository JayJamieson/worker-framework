<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Log;

/**
 * The subset of RFC 5424 levels the runtime emits, ordered by severity.
 *
 * The values match PSR-3's level strings so records can be forwarded to a
 * PSR-3 logger without translation, but nothing here depends on psr/log.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
        };
    }

    public function includes(self $other): bool
    {
        return $other->severity() >= $this->severity();
    }
}
