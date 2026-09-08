<?php

declare(strict_types=1);

namespace App\Message;

/**
 * An ordinary Messenger message. Nothing about it is worker-specific.
 */
final class SendReport
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $period = 'monthly',
    ) {
    }
}
