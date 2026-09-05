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
 * Message mode: one message in, one container run, one exit code out.
 */
final class MessageModeTest extends RuntimeTestCase
{
    public function testAMessageIsBuiltFromJsonAndHandled(): void
    {
        $result = $this->runWorker(['message'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_CLASS' => SendReport::class,
            'WORKER_PAYLOAD' => '{"companyId": 42, "period": "quarterly"}',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame([['companyId' => 42, 'period' => 'quarterly']], Recorder::details('handled'));
    }

    public function testConstructorDefaultsFillTheGaps(): void
    {
        $this->runWorker(['message'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_CLASS' => SendReport::class,
            'WORKER_PAYLOAD' => '{"company_id": "7"}',
        ]);

        self::assertSame([['companyId' => 7, 'period' => 'monthly']], Recorder::details('handled'));
    }

    public function testAMissingConstructorValueIsReportedRatherThanGuessed(): void
    {
        $result = $this->runWorker(['message'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_CLASS' => SendReport::class,
            'WORKER_PAYLOAD' => '{"period": "monthly"}',
        ]);

        self::assertSame(ExitCode::CONFIGURATION_ERROR, $result->exitCode);
        self::assertStringContainsString('$companyId', $result->log);
    }

    public function testATransportEncodedMessageIsDecodedWithTheApplicationsSerializer(): void
    {
        // Exactly what another Symfony app would have put on the queue.
        $encoded = MessengerFixture::serializer()->encode(new Envelope(new SendReport(99, 'daily')));

        $result = $this->runWorker(['message', 'async'], [
            ...$this->bootstrap(),
            'WORKER_PAYLOAD' => json_encode($encoded, JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame([['companyId' => 99, 'period' => 'daily']], Recorder::details('handled'));
    }

    public function testAFailingHandlerFailsTheContainerSoTheMessageCanBeRetried(): void
    {
        $result = $this->runWorker(['message'], [
            ...$this->bootstrap(),
            'WORKER_MESSAGE_CLASS' => BrokenMessage::class,
        ]);

        self::assertSame(ExitCode::WORKER_ERROR, $result->exitCode);
        self::assertStringContainsString('handler blew up', $result->log);
        self::assertStringContainsString('DomainException', $result->log);
    }

    public function testAPayloadThatIsNeitherFormIsExplained(): void
    {
        $result = $this->runWorker(['message'], [
            ...$this->bootstrap(),
            'WORKER_PAYLOAD' => '{"companyId": 42}',
        ]);

        self::assertSame(ExitCode::CONFIGURATION_ERROR, $result->exitCode);
        self::assertStringContainsString('WORKER_MESSAGE_CLASS', $result->log);
    }

    public function testMessageModeWithoutABusExplainsHowToExposeOne(): void
    {
        $result = $this->runWorker(['message'], ['WORKER_MESSAGE_CLASS' => SendReport::class, 'WORKER_PAYLOAD' => '{"companyId":1}']);

        self::assertSame(ExitCode::BOOTSTRAP_ERROR, $result->exitCode);
        self::assertStringContainsString('services.yaml', $result->log);
    }

    public function testTheBusIsFoundThroughTheServiceContainer(): void
    {
        // The realistic Symfony case: no bootstrap wiring, just a container
        // with a public `message_bus`.
        $result = $this->runWorker(['message'], [
            'WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/container.php',
            'WORKER_MESSAGE_CLASS' => SendReport::class,
            'WORKER_PAYLOAD' => '{"companyId": 5}',
        ]);

        self::assertSame(ExitCode::SUCCESS, $result->exitCode);
        self::assertSame([['companyId' => 5, 'period' => 'monthly']], Recorder::details('handled'));
    }

    /**
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        return ['WORKER_BOOTSTRAP' => \dirname(__DIR__) . '/Fixtures/bootstrap/messenger.php'];
    }
}
