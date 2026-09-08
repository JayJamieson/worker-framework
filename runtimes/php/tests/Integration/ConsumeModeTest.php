<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Integration;

use Symfony\Component\Messenger\Envelope;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Tests\Fixtures\BrokenMessage;
use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;
use WorkerFramework\Runtime\Tests\Fixtures\Recorder;
use WorkerFramework\Runtime\Tests\Fixtures\SendReport;
use WorkerFramework\Runtime\Tests\RuntimeTestCase;

/**
 * Consume mode against a real Messenger worker and transport.
 *
 * The message limit is what makes these tests terminate: it is the same
 * mechanism a production consumer uses to recycle itself, so exercising it here
 * checks the stop conditions rather than working around them.
 */
final class ConsumeModeTest extends RuntimeTestCase
{
    public function testQueuedMessagesReachTheirHandlers(): void
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
    }

    public function testHandledMessagesAreAcknowledged(): void
    {
        $transport = MessengerFixture::transport('async');
        $transport->send(new Envelope(new SendReport(42)));

        $this->runWorker(['consume', 'async'], [...$this->bootstrap(), 'WORKER_MESSAGE_LIMIT' => '1']);

        self::assertCount(1, $transport->getAcknowledged());
        self::assertSame([], $transport->get(), 'the queue is drained');
    }

    public function testAFailingHandlerRejectsTheMessageAndKeepsTheWorkerAlive(): void
    {
        $transport = MessengerFixture::transport('async');
        $transport->send(new Envelope(new BrokenMessage()));
        $transport->send(new Envelope(new SendReport(1)));

        $result = $this->runWorker(['consume', 'async'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_LIMIT' => '2',
        ]);

        // Without a retry strategy the message is rejected, and the consumer
        // carries on to the next one - the behaviour a queue depends on.
        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertCount(1, $transport->getRejected());
        self::assertCount(1, Recorder::events());
    }

    public function testConsumingWithoutATransportExplainsWhatToDo(): void
    {
        $result = $this->runWorker(['consume'], $this->bootstrap());

        self::assertSame(ExitCode::CONFIGURATION_ERROR, $result->exitCode);
        self::assertStringContainsString('WORKER_TRANSPORTS', $result->log);
    }

    public function testAnUnknownTransportIsReported(): void
    {
        $result = $this->runWorker(['consume', 'nope'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_LIMIT' => '1',
        ]);

        self::assertSame(ExitCode::BOOTSTRAP_ERROR, $result->exitCode);
        self::assertStringContainsString('Transport "nope" is not available', $result->log);
    }

    /**
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        return ['WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/messenger.php'];
    }
}
