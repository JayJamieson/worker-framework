<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\FailingWorker;
use WorkerFramework\Runtime\Tests\Fixtures\InjectedJobReporter;
use WorkerFramework\Runtime\Tests\Fixtures\InterfaceWorker;
use WorkerFramework\Runtime\Tests\Fixtures\QueueWorkerJob;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\RecordingJobReporter;
use WorkerFramework\Runtime\Tests\Fixtures\ThrowingWorker;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * WORKER_JOB_REPORTER: the hook an application uses to update its own job
 * record (DynamoDB, an API, whatever "the queue" means to it) as a job moves
 * through its lifecycle - independent of, and in addition to, the exit code.
 *
 * The one lifecycle event this cannot cover - StopReason::Forced, a worker
 * that ignores the stop request entirely - needs a real subprocess the test
 * can SIGKILL-adjacent without taking PHPUnit down with it; see
 * ForcedKillTest.
 */
final class JobReporterTest extends RuntimeTestCase
{
    public function testASuccessfulJobReportsStartingThenSucceeded(): void
    {
        $result = $this->runWorker(['handler', InterfaceWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
            'WORKER_JOB_ID' => 'job-42',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['job-42'], Recorder::details('reporter.starting'));
        self::assertSame(['job-42'], Recorder::details('reporter.succeeded'));
        self::assertSame([], Recorder::details('reporter.failed'));
        self::assertSame([], Recorder::details('reporter.stopped'));
    }

    public function testAThrownExceptionIsReportedAsFailedWithTheError(): void
    {
        $result = $this->runWorker(['handler', ThrowingWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        self::assertSame(ExitCode::WORKER_ERROR, $result->exitCode);
        self::assertSame(
            [['exit_code' => ExitCode::WORKER_ERROR, 'error' => 'worker exploded']],
            Recorder::details('reporter.failed'),
        );
        self::assertSame([], Recorder::details('reporter.succeeded'));
    }

    public function testANonZeroReturnIsReportedAsFailedWithoutAnException(): void
    {
        $this->runWorker(['handler', FailingWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        self::assertSame([['exit_code' => 17, 'error' => null]], Recorder::details('reporter.failed'));
    }

    public function testATimeoutIsReportedAsStoppedWithTheTimeoutReasonNotFailed(): void
    {
        $this->runWorker(['handler', QueueWorkerJob::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
            'WORKER_TIMEOUT' => '1',
        ]);

        self::assertSame(['timeout'], Recorder::details('reporter.stopped'));
        self::assertSame([], Recorder::details('reporter.failed'));
        self::assertSame([], Recorder::details('reporter.succeeded'));
    }

    public function testHeartbeatsAreRateLimitedByDefault(): void
    {
        // QueueWorkerJob checkpoints every 20ms; a 1s WORKER_TIMEOUT gives it
        // roughly 50 chances to heartbeat, which should collapse to exactly
        // one with the default 30s interval - the first call always gets
        // through, nothing after it does within a single second.
        $this->runWorker(['handler', QueueWorkerJob::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
            'WORKER_TIMEOUT' => '1',
        ]);

        self::assertSame(1, Recorder::count('reporter.heartbeat'));
    }

    public function testWorkerHeartbeatIntervalZeroDisablesThrottling(): void
    {
        $this->runWorker(['handler', QueueWorkerJob::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
            'WORKER_TIMEOUT' => '1',
            'WORKER_HEARTBEAT_INTERVAL' => '0',
        ]);

        // Every checkpoint() should have produced a heartbeat: as many as ticks.
        self::assertGreaterThan(10, Recorder::count('reporter.heartbeat'));
    }

    public function testAReporterWithDependenciesComesFromTheServiceContainer(): void
    {
        $result = $this->runWorker(['handler', InterfaceWorker::class], [
            'WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/container.php',
            'WORKER_JOB_REPORTER' => InjectedJobReporter::class,
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['https://status.internal/jobs'], Recorder::details('reporter.starting'));
        self::assertSame(['https://status.internal/jobs'], Recorder::details('reporter.succeeded'));
    }

    public function testAReporterThatIsNotAJobReporterIsRejected(): void
    {
        $result = $this->runWorker(['handler', InterfaceWorker::class], [
            'WORKER_JOB_REPORTER' => \stdClass::class,
        ]);

        self::assertSame(ExitCode::BOOTSTRAP_ERROR, $result->exitCode);
        self::assertStringContainsString('must implement', $result->log);
    }

    public function testNoReporterConfiguredMeansNoReporterCalls(): void
    {
        $this->runWorker(['handler', InterfaceWorker::class]);

        self::assertSame([], array_filter(
            Recorder::events(),
            static fn (string $event): bool => str_starts_with($event, 'reporter.'),
        ));
    }
}
