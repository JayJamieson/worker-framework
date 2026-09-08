<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\FileReportingJobReporter;
use WorkerFramework\Runtime\Tests\Fixtures\StuckQueueWorkerJob;

/**
 * StopReason::Forced only fires from inside the SIGALRM handler, a moment
 * away from a hard `exit()` - which would take the whole PHPUnit process
 * down with it if exercised in-process. This test runs the runtime as a real
 * subprocess instead, the way it actually runs in production, and inspects
 * its outcome from the outside: exit code, and what the reporter wrote to a
 * file (a real reporter would write to DynamoDB; a separate process can't
 * share the in-memory Recorder the rest of the suite uses).
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class ForcedKillTest extends TestCase
{
    public function testAWorkerThatIgnoresTheStopRequestIsForceKilledAndReportedAsForced(): void
    {
        $runtimeDir = \dirname(__DIR__, 2);
        $reportFile = tempnam(sys_get_temp_dir(), 'wf-report');

        $env = getenv();
        $env['WORKER_ROOT'] = $runtimeDir;
        $env['WORKER_JOB_REPORTER'] = FileReportingJobReporter::class;
        $env['WORKER_TEST_REPORT_FILE'] = $reportFile;
        $env['WORKER_SHUTDOWN_TIMEOUT'] = '1';

        $process = proc_open(
            [\PHP_BINARY, $runtimeDir . '/runtime.php', 'handler', StuckQueueWorkerJob::class],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $runtimeDir,
            $env,
        );

        self::assertIsResource($process, 'could not spawn the runtime subprocess');

        try {
            // Give it time to boot and reach the loop before signalling it.
            usleep(300_000);

            $status = proc_get_status($process);
            self::assertTrue($status['running'], 'the worker exited before it could be signalled');

            posix_kill($status['pid'], \SIGTERM);

            // WORKER_SHUTDOWN_TIMEOUT=1 plus signal-delivery slack.
            $deadline = microtime(true) + 5;

            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(100_000);
            } while (microtime(true) < $deadline);

            self::assertFalse($status['running'], 'the runtime should have force-killed itself by now');
            self::assertSame(ExitCode::SIGTERM, $status['exitcode']);

            $events = array_map(
                static fn (string $line): array => json_decode($line, true),
                array_filter(explode("\n", (string) file_get_contents($reportFile))),
            );

            self::assertNotEmpty($events, 'the reporter never wrote anything - did the worker even start?');
            self::assertSame('starting', $events[0]['event']);
            self::assertContains(['event' => 'stopped', 'detail' => 'forced'], $events);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            @unlink($reportFile);
        }
    }
}
