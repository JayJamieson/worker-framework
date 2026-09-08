<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Reporting;

use Throwable;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;

/**
 * Throttles `heartbeat()` so a tight `checkpoint()` loop does not turn into a
 * write on every iteration; every other call passes straight through.
 *
 * Implementations of JobReporter do not need to think about this - a
 * DynamoDB-backed reporter's `heartbeat()` can just write, unconditionally.
 */
final class RateLimitedJobReporter implements JobReporter
{
    private ?float $lastHeartbeatAt = null;

    public function __construct(
        private readonly JobReporter $reporter,
        private readonly int $intervalSeconds,
    ) {
    }

    public function starting(Context $context): void
    {
        $this->reporter->starting($context);
    }

    public function heartbeat(Context $context): void
    {
        $now = microtime(true);

        // Always let the first one through, so even a job that finishes
        // inside one interval leaves a "this was alive" record.
        if (null !== $this->lastHeartbeatAt && $now - $this->lastHeartbeatAt < $this->intervalSeconds) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $this->reporter->heartbeat($context);
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        $this->reporter->stopping($context, $reason);
    }

    public function succeeded(Context $context): void
    {
        $this->reporter->succeeded($context);
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        $this->reporter->failed($context, $exitCode, $error);
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        $this->reporter->stopped($context, $reason);
    }
}
