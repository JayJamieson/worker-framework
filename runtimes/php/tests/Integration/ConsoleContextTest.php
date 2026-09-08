<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * Two things a console command could not do before: get the runtime's
 * Context without implementing SignalableCommandInterface itself, and - once
 * it uses that Context to drain cleanly - be believed when it reports success.
 */
final class ConsoleContextTest extends RuntimeTestCase
{
    public function testAConsoleCommandReceivesTheRuntimeContext(): void
    {
        // No WORKER_LONG_RUNNING: console mode still defaults to "a stop
        // means the job did not finish", so the clean Command::SUCCESS the
        // fixture returns is overridden to 143. What this test is actually
        // checking is that the command *saw the stop at all* - "drained_at"
        // only appears if setWorkerContext() ran and checkpoint() worked.
        $result = $this->runWorker(['console', 'app:queue-work'], $this->bootstrap());

        self::assertSame(ExitCode::SIGTERM, $result->exitCode);
        self::assertSame([0, 1, 2], Recorder::details('tick'));
        self::assertSame([3], Recorder::details('drained_at'), 'checkpoint() only works if Context reached the command');
    }

    public function testALongRunningConsoleCommandsCleanStopIsReportedAsSuccess(): void
    {
        // The bug this guards against: a console-mode worker that is really a
        // long-running consumer (a plain `queue:work`, not Messenger) drains
        // on SIGTERM and returns Command::SUCCESS - and used to have that
        // rewritten to 143 purely because its mode was "console" rather than
        // "consume", turning every clean stop into an apparent crash.
        $result = $this->runWorker(['console', 'app:queue-work'], [
            ...$this->bootstrap(),
            'WORKER_LONG_RUNNING' => '1',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame([3], Recorder::details('drained_at'));
    }

    /**
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        return ['WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/console.php'];
    }
}
