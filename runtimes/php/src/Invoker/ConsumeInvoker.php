<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Application\ConsoleFactory;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Exception\BootstrapException;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Long-running Symfony Messenger consumer.
 *
 * Where a Symfony application is present this defers to `messenger:consume`
 * itself rather than reimplementing it. That is a deliberate choice: retry
 * strategies, failure transports, rate limiting, the middleware stack and the
 * worker event listeners are all wired by the framework, and a hand-rolled
 * loop would quietly lose them.
 *
 * For projects using Messenger standalone - no FrameworkBundle, receivers
 * handed over by `worker.php` - the runtime drives `Messenger\Worker` directly
 * and attaches the same stop-condition listeners the command would have used.
 *
 * Either way, SIGTERM finishes the message in flight and then stops, which is
 * the behaviour a rolling deploy or a spot-instance reclaim depends on.
 */
final class ConsumeInvoker implements Invoker
{
    public function __construct(
        private readonly Configuration $config,
        private readonly ApplicationContext $application,
        private readonly Logger $logger,
        private readonly ?\Symfony\Component\Console\Output\OutputInterface $output = null,
    ) {
    }

    public function describe(): string
    {
        $transports = $this->config->transports();

        return sprintf('messenger consumer for [%s]', [] === $transports ? 'all transports' : implode(', ', $transports));
    }

    public function invoke(Context $context): int
    {
        $this->guardTransports();

        return $this->canUseConsole()
            ? $this->consumeThroughConsole($context)
            : $this->consumeStandalone($context);
    }

    /**
     * `messenger:consume` asks which transport to use when none is named, and
     * a container has nobody to ask; fail with an actionable message instead.
     */
    private function guardTransports(): void
    {
        if ([] !== $this->config->transports() || \in_array('--all', $this->config->consumeOptions(), true)) {
            return;
        }

        throw new ConfigurationException(
            'Consume mode needs at least one transport. Use CMD ["consume", "async"], set '
            . 'WORKER_TRANSPORTS="async,failed", or pass --all to consume every configured transport.',
        );
    }

    private function canUseConsole(): bool
    {
        if ([] !== $this->application->receivers()) {
            // Explicit receivers from worker.php mean the user has opted into
            // driving the worker directly.
            return false;
        }

        return null !== $this->application->console() || null !== $this->application->kernel();
    }

    private function consumeThroughConsole(Context $context): int
    {
        $application = ConsoleFactory::create($this->application);

        if (!$application->has('messenger:consume')) {
            throw new BootstrapException(
                'The application has no `messenger:consume` command. Install symfony/messenger, or supply receivers '
                . 'from worker.php to let the runtime consume them directly.',
            );
        }

        $arguments = [
            'messenger:consume',
            ...$this->config->transports(),
            ...$this->config->limits->toConsoleOptions(),
            ...$this->config->consumeOptions(),
            '--no-interaction',
        ];

        $this->logger->info('Consuming messages', [
            'transports' => implode(',', $this->config->transports()) ?: 'all',
            'driver' => 'messenger:consume',
        ]);

        // Delegating to the command keeps every option the framework supports
        // available, including ones the runtime does not model. The already
        // built Application is passed along so it is not constructed twice.
        return (new ConsoleInvoker(
            $this->config->withArguments($arguments),
            $this->application->withConsole($application),
            $this->logger,
            $this->output,
        ))->invoke($context);
    }

    private function consumeStandalone(Context $context): int
    {
        if (!class_exists(Worker::class)) {
            throw new BootstrapException('symfony/messenger is not installed in the worker image.');
        }

        $receivers = $this->receivers();
        $bus = $this->application->bus();

        if (!$bus instanceof MessageBusInterface) {
            throw new BootstrapException(
                'No message bus is available. Return ["bus" => $bus, "receivers" => [...]] from worker.php, or make '
                . 'the "message_bus" service public in the application.',
            );
        }

        $dispatcher = $this->dispatcher();
        $worker = new Worker($receivers, $bus, $dispatcher, null);

        $this->attachStopConditions($dispatcher, $context);

        $this->logger->info('Consuming messages', [
            'transports' => implode(',', array_keys($receivers)),
            'driver' => 'standalone',
        ]);

        $worker->run(['sleep' => $this->config->limits->sleep]);

        return ExitCode::SUCCESS;
    }

    /**
     * @return array<string, object>
     */
    private function receivers(): array
    {
        $provided = $this->application->receivers();
        $requested = $this->config->transports();

        if ([] === $requested) {
            return $provided;
        }

        $receivers = [];

        foreach ($requested as $name) {
            $receiver = $provided[$name] ?? $this->application->findService(['messenger.transport.' . $name, $name]);

            if (null === $receiver) {
                throw new BootstrapException(sprintf(
                    'Transport "%s" is not available. Known transports: %s.',
                    $name,
                    implode(', ', array_keys($provided)) ?: 'none',
                ));
            }

            $receivers[$name] = $receiver;
        }

        return $receivers;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->application->eventDispatcher();

        if ($dispatcher instanceof EventDispatcher) {
            return $dispatcher;
        }

        if (!class_exists(EventDispatcher::class)) {
            throw new BootstrapException('symfony/event-dispatcher is required to run a standalone consumer.');
        }

        return new EventDispatcher();
    }

    /**
     * Reproduce the stop conditions `messenger:consume` would have applied,
     * plus the runtime's own shutdown flag.
     */
    private function attachStopConditions(EventDispatcherInterface $dispatcher, Context $context): void
    {
        if (!$dispatcher instanceof EventDispatcher) {
            return;
        }

        $limits = $this->config->limits;

        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($context): void {
            if ($context->checkpoint()) {
                $event->getWorker()->stop();
            }
        });

        if ($limits->messages > 0 && class_exists(StopWorkerOnMessageLimitListener::class)) {
            $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limits->messages));
        }

        if ($limits->time > 0 && class_exists(StopWorkerOnTimeLimitListener::class)) {
            $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($limits->time));
        }

        if (null !== $limits->memory && class_exists(StopWorkerOnMemoryLimitListener::class)) {
            $dispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener($this->toBytes($limits->memory)));
        }

        if ($limits->failures > 0 && class_exists(StopWorkerOnFailureLimitListener::class)) {
            $dispatcher->addSubscriber(new StopWorkerOnFailureLimitListener($limits->failures));
        }
    }

    /**
     * Parse a php.ini-style size ("128M", "1G") into bytes.
     */
    private function toBytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtolower(substr(trim($limit), -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
