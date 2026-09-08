<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Reporting;

use Throwable;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Stops a reporter's own failures from becoming the job's failures.
 *
 * Reporting is a side channel. A job that did its work succeeded, whether or
 * not the record of that reached DynamoDB - so a throwing reporter must not
 * be able to rewrite a successful exit code, and `heartbeat()` must never
 * propagate out of `Context::checkpoint()` into a worker's own loop, where a
 * transient network error would take down a job that was running perfectly
 * well.
 *
 * Failures are logged at error level rather than swallowed quietly: a status
 * update that never lands is a real operational problem - the job looks stuck
 * to whatever is watching it - just not one worth failing the job over. This
 * is the same rule SignalHandler applies to its own listeners.
 */
final class GuardedJobReporter implements JobReporter
{
    public function __construct(
        private readonly JobReporter $reporter,
        private readonly Logger $logger,
    ) {
    }

    public function starting(Context $context): void
    {
        $this->guard('starting', fn () => $this->reporter->starting($context));
    }

    public function heartbeat(Context $context): void
    {
        $this->guard('heartbeat', fn () => $this->reporter->heartbeat($context));
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        $this->guard('stopping', fn () => $this->reporter->stopping($context, $reason));
    }

    public function succeeded(Context $context): void
    {
        $this->guard('succeeded', fn () => $this->reporter->succeeded($context));
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        $this->guard('failed', fn () => $this->reporter->failed($context, $exitCode, $error));
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        $this->guard('stopped', fn () => $this->reporter->stopped($context, $reason));
    }

    private function guard(string $call, callable $report): void
    {
        try {
            $report();
        } catch (Throwable $error) {
            $this->logger->exception($error, sprintf('Job reporter failed on %s()', $call), [
                'reporter' => $this->reporter::class,
            ]);
        }
    }
}
