<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests;

use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Log\LogLevel;
use WorkerFramework\Runtime\Runtime;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;

/**
 * Base class for tests that run a worker end to end.
 *
 * Runs go through the real Runtime with an explicit environment array rather
 * than by touching `putenv`, so tests stay independent of each other and of
 * whatever the machine's environment happens to hold.
 */
abstract class RuntimeTestCase extends TestCase
{
    protected function setUp(): void
    {
        Recorder::reset();
        MessengerFixture::reset();
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    protected function runWorker(array $argv, array $env = []): WorkerResult
    {
        $env = [
            // Nothing should be discovered by accident; every test states what
            // it wants explicitly.
            'WORKER_ROOT' => sys_get_temp_dir(),
            'WORKER_KERNEL_CLASS' => 'Tests\\NoSuchKernel',
            'WORKER_DOTENV' => '0',
            'WORKER_PAYLOAD_STDIN' => '0',
            ...$env,
        ];

        $configuration = Configuration::create(['runtime.php', ...$argv], $env);

        $log = fopen('php://memory', 'w+b');
        $logger = new Logger(
            LogLevel::tryFrom($env['WORKER_LOG_LEVEL'] ?? 'debug') ?? LogLevel::Debug,
            $configuration->logFormat,
            [],
            $log,
        );

        ob_start();

        try {
            $exitCode = (new Runtime($configuration, $env, $logger))->run();
        } finally {
            $stdout = (string) ob_get_clean();
        }

        rewind($log);
        $records = (string) stream_get_contents($log);
        fclose($log);

        return new WorkerResult($exitCode, $stdout, $records);
    }
}
