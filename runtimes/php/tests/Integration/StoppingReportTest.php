<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\ExplodingJobReporter;
use WorkerFramework\Runtime\Tests\Fixtures\InterfaceWorker;
use WorkerFramework\Runtime\Tests\Fixtures\QueueWorkerJob;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\RecordingJobReporter;
use WorkerFramework\Runtime\Tests\Fixtures\SelfTerminatingWorker;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * `stopping()` closes the window between a stop being requested and the job
 * finally reporting a terminal state - a window that can be as long as
 * WORKER_SHUTDOWN_TIMEOUT, and used to be completely silent.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class StoppingReportTest extends RuntimeTestCase
{
    public function testASignalIsReportedWhenItArrives(): void
    {
        $result = $this->runWorker(['handler', SelfTerminatingWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode);
        self::assertSame(['signal'], Recorder::details('reporter.stopping'));
        self::assertSame(['signal'], Recorder::details('reporter.stopped'));
    }

    public function testStoppingArrivesBeforeTheTerminalReport(): void
    {
        $this->runWorker(['handler', SelfTerminatingWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        $reports = array_values(array_filter(
            Recorder::events(),
            static fn (string $event): bool => \in_array(
                $event,
                ['reporter.starting', 'reporter.stopping', 'reporter.stopped'],
                true,
            ),
        ));

        self::assertSame(
            ['reporter.starting', 'reporter.stopping', 'reporter.stopped'],
            $reports,
            'the job is claimed, then seen winding down, then finally resolved',
        );
    }

    public function testATimeoutReportsStoppingWithTheTimeoutReason(): void
    {
        $this->runWorker(['handler', QueueWorkerJob::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
            'WORKER_TIMEOUT' => '1',
        ]);

        self::assertSame(['timeout'], Recorder::details('reporter.stopping'));
        self::assertSame(['timeout'], Recorder::details('reporter.stopped'));
    }

    public function testAnUninterruptedRunNeverReportsStopping(): void
    {
        $this->runWorker(['handler', InterfaceWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        self::assertSame([], Recorder::details('reporter.stopping'));
        self::assertSame(1, Recorder::count('reporter.succeeded'));
    }

    public function testStoppingIsReportedOnlyOnce(): void
    {
        // The fixture signals itself once; a second SIGTERM would exit the
        // process outright, so one report is all there should ever be.
        $this->runWorker(['handler', SelfTerminatingWorker::class], [
            'WORKER_JOB_REPORTER' => RecordingJobReporter::class,
        ]);

        self::assertSame(1, Recorder::count('reporter.stopping'));
    }

    public function testABrokenReporterCannotBreakTheJob(): void
    {
        // ExplodingJobReporter throws on every call. The job still succeeds,
        // and the container still reports 0 - reporting is a side channel,
        // not part of the job's own correctness.
        $result = $this->runWorker(['handler', InterfaceWorker::class], [
            'WORKER_JOB_REPORTER' => ExplodingJobReporter::class,
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['handle'], Recorder::events(), 'the worker still ran');
        self::assertStringContainsString('Job reporter failed on starting()', $result->log);
        self::assertStringContainsString('Job reporter failed on succeeded()', $result->log);
    }

    public function testAThrowingHeartbeatCannotBreakAWorkersLoop(): void
    {
        // heartbeat() fires from inside checkpoint(), in the worker's own
        // loop - the most dangerous place for an unguarded throw.
        $result = $this->runWorker(['handler', SelfTerminatingWorker::class], [
            'WORKER_JOB_REPORTER' => ExplodingJobReporter::class,
        ]);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode);
        self::assertSame([0, 1, 2], Recorder::details('tick'), 'the loop ran to its own stopping point');
        self::assertSame([3], Recorder::details('stopped_at'));
        self::assertStringContainsString('Job reporter failed on heartbeat()', $result->log);
    }
}
