<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * Console mode turns `bin/console app:something` into a container.
 */
final class ConsoleModeTest extends RuntimeTestCase
{
    public function testACommandRunsWithItsArgumentsAndOptions(): void
    {
        $result = $this->runWorker(['console', 'app:import', 'ledger', '--dry-run'], $this->bootstrap());

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(['source' => 'ledger', 'dry-run' => true], Recorder::detail('command'));
    }

    public function testTheCommandLineCanComeFromTheEnvironment(): void
    {
        $result = $this->runWorker(['console'], [
            ...$this->bootstrap(),
            'WORKER_COMMAND' => "app:import 'general ledger'",
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame('general ledger', Recorder::detail('command')['source']);
    }

    public function testACommandsExitCodeIsTheContainersExitCode(): void
    {
        self::assertSame(9, $this->runWorker(['console', 'app:fail'], $this->bootstrap())->exitCode);
    }

    public function testAnUnknownCommandIsReportedWithoutRunningAnything(): void
    {
        $result = $this->runWorker(['console', 'app:nope'], $this->bootstrap());

        self::assertSame(ExitCode::HANDLER_NOT_FOUND, $result->exitCode);
        self::assertStringContainsString('is not registered', $result->log);
    }

    public function testAMissingArgumentIsReportedAsAWorkerFailure(): void
    {
        // `source` is required; Symfony cannot prompt for it without a terminal.
        $result = $this->runWorker(['console', 'app:import'], $this->bootstrap());

        self::assertSame(ExitCode::WORKER_ERROR, $result->exitCode);
        self::assertStringContainsString('Not enough arguments', $result->log);
    }

    public function testConsoleModeWithoutAnApplicationExplainsWhatToDo(): void
    {
        $result = $this->runWorker(['console', 'app:import']);

        self::assertSame(ExitCode::BOOTSTRAP_ERROR, $result->exitCode);
        self::assertStringContainsString('WORKER_KERNEL_CLASS', $result->log);
    }

    public function testConsoleModeWithoutACommandExplainsWhatToDo(): void
    {
        $result = $this->runWorker(['console'], $this->bootstrap());

        self::assertSame(ExitCode::CONFIGURATION_ERROR, $result->exitCode);
        self::assertStringContainsString('WORKER_COMMAND', $result->log);
    }

    /**
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        return ['WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/console.php'];
    }
}
