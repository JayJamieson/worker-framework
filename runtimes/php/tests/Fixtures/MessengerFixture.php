<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Fixtures;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A minimal but real Messenger setup: a bus with the handler middleware, and
 * in-memory transports standing in for a queue.
 *
 * Everything is static so that a bootstrap file loaded inside a worker run and
 * the test that started it are looking at the same objects.
 */
final class MessengerFixture
{
    private static ?MessageBusInterface $bus = null;

    /** @var array<string, InMemoryTransport> */
    private static array $transports = [];

    public static function reset(): void
    {
        self::$bus = null;
        self::$transports = [];
    }

    public static function bus(): MessageBusInterface
    {
        return self::$bus ??= new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                SendReport::class => [new SendReportHandler()],
                BrokenMessage::class => [new BrokenHandler()],
                StopAfterThis::class => [new StopAfterThisHandler()],
            ])),
        ]);
    }

    public static function transport(string $name = 'async'): InMemoryTransport
    {
        return self::$transports[$name] ??= new InMemoryTransport(self::serializer());
    }

    /**
     * @return array<string, InMemoryTransport>
     */
    public static function receivers(): array
    {
        // Make sure the default transport exists even if nothing was sent yet.
        self::transport();

        return self::$transports;
    }

    public static function serializer(): SerializerInterface
    {
        return new PhpSerializer();
    }

    public static function console(): ConsoleApplication
    {
        $application = new ConsoleApplication('worker-fixture', '1.0.0');
        $application->add(new ImportCommand());
        $application->add(new FailingCommand());
        $application->add(new LongRunningCommand());
        $application->add(new QueueWorkCommand());

        return $application;
    }

    /**
     * A Console Application carrying the real `messenger:consume` command,
     * wired the way FrameworkBundle wires it.
     *
     * This is what the runtime delegates to for any Symfony application, so it
     * is worth testing against the genuine command rather than a stand-in.
     */
    public static function consoleWithConsume(): ConsoleApplication
    {
        $application = self::console();

        $application->add(new ConsumeMessagesCommand(
            new RoutableMessageBus(new ServiceLocator([
                'messenger.bus.default' => static fn (): MessageBusInterface => self::bus(),
            ]), self::bus()),
            new ServiceLocator(array_map(
                static fn (InMemoryTransport $transport): callable => static fn (): InMemoryTransport => $transport,
                self::receivers(),
            )),
            new EventDispatcher(),
            null,
            array_keys(self::receivers()),
        ));

        return $application;
    }
}
