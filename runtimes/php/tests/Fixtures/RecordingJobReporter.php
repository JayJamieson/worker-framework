<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Fixtures;

use Throwable;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;

/**
 * Stands in for a real reporter (DynamoDB, an API call) so in-process tests
 * can assert on what the runtime told it, without any real storage involved.
 */
final class RecordingJobReporter implements JobReporter
{
    public function starting(Context $context): void
    {
        Recorder::record('reporter.starting', $context->jobId());
    }

    public function heartbeat(Context $context): void
    {
        Recorder::record('reporter.heartbeat', $context->jobId());
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        Recorder::record('reporter.stopping', $reason->value);
    }

    public function succeeded(Context $context): void
    {
        Recorder::record('reporter.succeeded', $context->jobId());
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        Recorder::record('reporter.failed', ['exit_code' => $exitCode, 'error' => $error?->getMessage()]);
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        Recorder::record('reporter.stopped', $reason->value);
    }
}

/**
 * A reporter with a required constructor argument, to prove the factory
 * fetches it from the service container rather than trying `new`.
 */
final class InjectedJobReporter implements JobReporter
{
    public function __construct(private readonly string $endpoint)
    {
    }

    public function starting(Context $context): void
    {
        Recorder::record('reporter.starting', $this->endpoint);
    }

    public function heartbeat(Context $context): void
    {
    }

    public function stopping(Context $context, StopReason $reason): void
    {
    }

    public function succeeded(Context $context): void
    {
        Recorder::record('reporter.succeeded', $this->endpoint);
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
    }

    public function stopped(Context $context, StopReason $reason): void
    {
    }
}

/**
 * Writes each call to a file instead of Recorder, so a genuinely separate
 * subprocess (one that gets SIGKILLed by the test itself, which an in-process
 * test cannot survive) can still be inspected by the test that spawned it.
 * The path comes from WORKER_TEST_REPORT_FILE, set by the test.
 */
final class FileReportingJobReporter implements JobReporter
{
    public function starting(Context $context): void
    {
        $this->append('starting', $context->jobId());
    }

    public function heartbeat(Context $context): void
    {
        $this->append('heartbeat', $context->jobId());
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        $this->append('stopping', $reason->value);
    }

    public function succeeded(Context $context): void
    {
        $this->append('succeeded', $context->jobId());
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        $this->append('failed', $exitCode);
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        $this->append('stopped', $reason->value);
    }

    private function append(string $event, mixed $detail): void
    {
        $file = getenv('WORKER_TEST_REPORT_FILE');

        if (false === $file) {
            return;
        }

        file_put_contents($file, json_encode(['event' => $event, 'detail' => $detail]) . "\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Loops, checkpointing regularly, and returns as soon as a stop is requested -
 * a well-behaved long-running job. Used for the timeout and heartbeat tests,
 * where the loop needs to actually run for a while rather than complete
 * instantly.
 */
final class QueueWorkerJob
{
    public function handle(Context $context): void
    {
        for ($i = 0; $i < 500; ++$i) {
            if ($context->checkpoint()) {
                Recorder::record('drained_at', $i);

                return;
            }

            usleep(20_000);
        }
    }
}

/**
 * Loops forever, checkpointing (so its heartbeat still fires, as a real
 * wedged worker's would) but never acting on the result - the fixture behind
 * the StopReason::Forced test. Only ever run in a subprocess the test kills.
 */
final class StuckQueueWorkerJob
{
    public function handle(Context $context): void
    {
        for ($i = 0; $i < 10_000; ++$i) {
            $context->checkpoint();
            usleep(10_000);
        }
    }
}

/**
 * Throws on every call, to prove a broken reporter cannot break the job.
 */
final class ExplodingJobReporter implements JobReporter
{
    public function starting(Context $context): void
    {
        throw new \RuntimeException('reporter is down');
    }

    public function heartbeat(Context $context): void
    {
        throw new \RuntimeException('reporter is down');
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        throw new \RuntimeException('reporter is down');
    }

    public function succeeded(Context $context): void
    {
        throw new \RuntimeException('reporter is down');
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        throw new \RuntimeException('reporter is down');
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        throw new \RuntimeException('reporter is down');
    }
}
