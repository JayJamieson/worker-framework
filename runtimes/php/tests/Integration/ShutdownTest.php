<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Messenger\Envelope;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\SelfTerminatingWorker;
use WorkerFramework\Runtime\Tests\Fixtures\SendReport;
use WorkerFramework\Runtime\Tests\Fixtures\SleepingWorker;
use WorkerFramework\Runtime\Tests\Fixtures\StopAfterThis;
use WorkerFramework\Runtime\Tests\Fixtures\StubbornWorker;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * How the runtime behaves when the container is asked to stop.
 *
 * These are the tests that matter for a rolling deploy or a spot-instance
 * reclaim: work in flight must finish, and the exit code has to tell the
 * scheduler whether the job is done.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class ShutdownTest extends RuntimeTestCase
{
    public function testAWorkerThatCooperatesStopsAtTheNextCheckpoint(): void
    {
        $this->runWorker(['handler', SelfTerminatingWorker::class]);

        self::assertSame([0, 1, 2], Recorder::details('tick'));
        self::assertSame([3], Recorder::details('stopped_at'), 'the tick in flight finishes first');
        self::assertNotContains('ran_to_completion', Recorder::events());
    }

    public function testAnInterruptedJobReportsTheSignalSoASchedulerCanRetryIt(): void
    {
        $result = $this->runWorker(['handler', SelfTerminatingWorker::class]);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode);
        self::assertStringContainsString('Shutdown requested', $result->log);
        self::assertStringContainsString('Worker stopped on request', $result->log);
    }

    public function testAWorkerThatIgnoresTheRequestStillRunsToTheEndAndIsReported(): void
    {
        // Nothing forcibly interrupts PHP mid-statement; the runtime reports
        // what happened rather than pretending the job succeeded.
        $result = $this->runWorker(['handler', StubbornWorker::class]);

        self::assertSame(ExitCode::SIGTERM, $result->exitCode);
        self::assertCount(5, Recorder::details('tick'));
    }

    public function testAConsumerStoppedByASignalFinishesItsMessageAndExitsCleanly(): void
    {
        $transport = MessengerFixture::transport('async');
        $transport->send(new Envelope(new StopAfterThis()));
        $transport->send(new Envelope(new SendReport(42)));

        $result = $this->runWorker(['consume', 'async'], [
            'WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/messenger.php',
            'WORKER_MESSAGE_LIMIT' => '5',
        ]);

        // A consumer asked to stop did what it was told: exit 0, so a rolling
        // deploy does not look like a crash loop.
        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['handled_stop'], Recorder::events(), 'the second message is left on the queue');
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(1, $transport->get(), 'the unhandled message is still queued');
    }

    public function testTheWallClockTimeoutStopsTheWorkerAndIsReportedDistinctly(): void
    {
        $result = $this->runWorker(['handler', SleepingWorker::class], [
            'WORKER_TIMEOUT' => '1',
        ]);

        self::assertSame(ExitCode::TIMEOUT, $result->exitCode);
        self::assertStringContainsString('Worker timeout reached', $result->log);
    }
}
