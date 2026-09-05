<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Mode;

final class ConfigurationTest extends TestCase
{
    public function testFirstArgumentSelectsTheMode(): void
    {
        $config = $this->create(['console', 'app:import', '--force']);

        self::assertSame(Mode::Console, $config->mode);
        self::assertSame(['app:import', '--force'], $config->arguments);
    }

    public function testAnUnknownFirstArgumentIsTreatedAsAHandler(): void
    {
        // The proof of concept's calling convention, still supported.
        $config = $this->create(['App\\Worker\\SendReports']);

        self::assertSame(Mode::Handler, $config->mode);
        self::assertSame('App\\Worker\\SendReports', $config->handler);
        self::assertSame([], $config->arguments);
    }

    public function testHandlerModeReadsItsHandlerFromTheEnvironment(): void
    {
        $config = $this->create([], ['WORKER_MODE' => 'handler', 'WORKER_HANDLER' => 'App\\Job']);

        self::assertSame(Mode::Handler, $config->mode);
        self::assertSame('App\\Job', $config->handler);
    }

    public function testExplicitHandlerModeStillTakesTheHandlerFromTheCommandLine(): void
    {
        $config = $this->create(['handler', 'App\\Job']);

        self::assertSame('App\\Job', $config->handler);
        self::assertSame([], $config->arguments);
    }

    public function testMissingWorkerIsReportedClearly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No worker specified');

        $this->create([]);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown worker mode "sideways"');

        $this->create([], ['WORKER_MODE' => 'sideways']);
    }

    public function testTransportsAndOptionsAreSeparated(): void
    {
        $config = $this->create(['consume', 'async', 'failed', '--queues=high']);

        self::assertSame(['async', 'failed'], $config->transports());
        self::assertSame(['--queues=high'], $config->consumeOptions());
    }

    public function testTransportsCanComeFromTheEnvironment(): void
    {
        $config = $this->create(['consume'], ['WORKER_TRANSPORTS' => 'async, failed ']);

        self::assertSame(['async', 'failed'], $config->transports());
    }

    #[DataProvider('commandLines')]
    public function testCommandLinesFromTheEnvironmentAreTokenisedLikeAShellWould(string $commandLine, array $expected): void
    {
        $config = $this->create(['console'], ['WORKER_COMMAND' => $commandLine]);

        self::assertSame($expected, $config->arguments);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function commandLines(): iterable
    {
        yield 'plain' => ['app:import --force', ['app:import', '--force']];
        yield 'single quotes' => ["app:import --name='Acme Ltd'", ['app:import', '--name=Acme Ltd']];
        yield 'double quotes' => ['app:import "two words"', ['app:import', 'two words']];
        yield 'empty argument' => ['app:import ""', ['app:import', '']];
        yield 'extra whitespace' => ["app:import   --force\t-v", ['app:import', '--force', '-v']];
    }

    public function testUnbalancedQuotesAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unbalanced');

        $this->create(['console'], ['WORKER_COMMAND' => "app:import --name='Acme"]);
    }

    public function testDefaultsAreProductionSafe(): void
    {
        $config = $this->create(['handler', 'App\\Job']);

        self::assertSame('prod', $config->appEnv);
        self::assertFalse($config->appDebug);
        self::assertSame(0, $config->timeout, 'workers run for as long as they need by default');
        self::assertSame(30, $config->shutdownTimeout);
        self::assertSame('App\\Kernel', $config->kernelClass);
        self::assertNotSame('', $config->jobId);
    }

    public function testAJobIdIsGeneratedWhenTheDispatcherDoesNotSupplyOne(): void
    {
        $first = $this->create(['handler', 'App\\Job'])->jobId;
        $second = $this->create(['handler', 'App\\Job'])->jobId;

        self::assertNotSame($first, $second);
    }

    public function testDeadlineIsOnlySetWhenATimeoutIsConfigured(): void
    {
        self::assertNull($this->create(['handler', 'A'])->deadline(1000.0));
        self::assertSame(1060.0, $this->create(['handler', 'A'], ['WORKER_TIMEOUT' => '60'])->deadline(1000.0));
    }

    public function testMissingBootstrapFileIsReportedRatherThanIgnored(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('WORKER_BOOTSTRAP');

        $this->create(['handler', 'A'], ['WORKER_BOOTSTRAP' => '/no/such/worker.php']);
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    private function create(array $argv, array $env = []): Configuration
    {
        return Configuration::create(['runtime.php', ...$argv], ['WORKER_ROOT' => sys_get_temp_dir(), ...$env]);
    }
}
