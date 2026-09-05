<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Invoker\ConsoleInvoker;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Log\LogLevel;
use WorkerFramework\Runtime\Mode;
use WorkerFramework\Runtime\Payload;
use WorkerFramework\Runtime\Signal\SignalHandler;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;

/**
 * What a command writes has to reach the container's stdout unchanged - that is
 * the whole value of running an existing command as a worker. The invoker is
 * driven directly here because ConsoleOutput writes past PHP's output buffer.
 */
final class ConsoleOutputTest extends TestCase
{
    protected function setUp(): void
    {
        Recorder::reset();
    }

    public function testCommandOutputIsWrittenVerbatim(): void
    {
        $output = new BufferedOutput();

        $exitCode = $this->invoke(['app:import', 'ledger'], $output);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame("imported ledger\n", $output->fetch());
    }

    public function testUsageErrorsAreRenderedTheWaySymfonyWouldRenderThem(): void
    {
        $output = new BufferedOutput();

        try {
            $this->invoke(['app:import'], $output);
            self::fail('a missing required argument should surface as an exception');
        } catch (\Throwable) {
            // The runtime re-throws after rendering so that Runtime can log it.
        }

        $rendered = $output->fetch();

        self::assertStringContainsString('Not enough arguments', $rendered);
        self::assertStringContainsString('app:import', $rendered, 'the usage line is part of what makes this useful');
    }

    /**
     * @param list<string> $arguments
     */
    private function invoke(array $arguments, BufferedOutput $output): int
    {
        $config = Configuration::create(
            ['runtime.php', 'console', ...$arguments],
            ['WORKER_ROOT' => sys_get_temp_dir(), 'WORKER_KERNEL_CLASS' => 'None'],
        );

        $application = new ApplicationContext(console: MessengerFixture::console());
        $logger = new Logger(LogLevel::Critical);

        $context = new Context('test', Mode::Console, Payload::empty(), $logger, new SignalHandler($logger));

        return (new ConsoleInvoker($config, $application, $logger, $output))->invoke($context);
    }
}
