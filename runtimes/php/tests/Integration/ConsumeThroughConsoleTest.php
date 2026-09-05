<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use Symfony\Component\Messenger\Envelope;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\SendReport;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * Consume mode against Symfony's own `messenger:consume` command.
 *
 * This is the path every Symfony application takes, and the reason the runtime
 * delegates rather than reimplementing the loop: retry strategies, the failure
 * transport and the worker event listeners come from the framework, not from
 * here. These tests exist to prove the delegation is wired correctly - the
 * command's own behaviour is Symfony's to test.
 */
final class ConsumeThroughConsoleTest extends RuntimeTestCase
{
    public function testMessagesAreConsumedThroughTheFrameworksOwnCommand(): void
    {
        $transport = MessengerFixture::transport('async');
        $transport->send(new Envelope(new SendReport(42)));
        $transport->send(new Envelope(new SendReport(7, 'weekly')));

        $result = $this->runWorker(['consume', 'async'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_LIMIT' => '2',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame(
            [['companyId' => 42, 'period' => 'monthly'], ['companyId' => 7, 'period' => 'weekly']],
            Recorder::details('handled'),
        );
        self::assertCount(2, $transport->getAcknowledged());
    }

    public function testTheMessageLimitIsPassedThroughAsTheCommandsOwnOption(): void
    {
        $transport = MessengerFixture::transport('async');

        foreach (range(1, 5) as $companyId) {
            $transport->send(new Envelope(new SendReport($companyId)));
        }

        $this->runWorker(['consume', 'async'], [...$this->bootstrap(), 'WORKER_MESSAGE_LIMIT' => '3']);

        self::assertCount(3, Recorder::details('handled'), 'the consumer recycles after three messages');
        self::assertCount(2, $transport->get(), 'the rest stay on the queue for the next container');
    }

    public function testUnmodelledOptionsReachTheCommand(): void
    {
        MessengerFixture::transport('async');

        // --bus is a `messenger:consume` option the runtime knows nothing
        // about; it must still arrive intact.
        $result = $this->runWorker(['consume', 'async', '--bus=messenger.bus.default'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_LIMIT' => '1',
            'WORKER_TIME_LIMIT' => '1',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
    }

    public function testAnUnknownTransportIsReportedByTheCommand(): void
    {
        $result = $this->runWorker(['consume', 'nope'], [...$this->bootstrap(), 'WORKER_MESSAGE_LIMIT' => '1']);

        self::assertNotSame(ExitCode::SUCCESS, $result->exitCode);
    }

    /**
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        return ['WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/consume_console.php'];
    }
}
