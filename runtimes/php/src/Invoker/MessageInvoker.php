<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Exception\BootstrapException;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Payload;

/**
 * Handles exactly one Messenger message, then exits.
 *
 * This is the shape that fits a job scheduler: something outside the container
 * already took the message off the queue and now wants a process to handle it,
 * with the container's exit code deciding whether the message is acked or
 * retried. Nothing polls, nothing loops, and the container's lifetime is the
 * message's lifetime.
 *
 * The message can be described two ways:
 *
 *   WORKER_MESSAGE_CLASS=App\Message\SendReport with a JSON payload, which the
 *   runtime denormalises into the message object; or
 *
 *   a transport-shaped payload {"body": "...", "headers": {...}} exactly as it
 *   came off the queue, decoded with the application's Messenger serializer -
 *   the right choice when another Symfony app produced the message.
 *
 * Handlers run through the normal bus, so middleware, validation and
 * transactions all apply.
 */
final class MessageInvoker implements Invoker
{
    public function __construct(
        private readonly Configuration $config,
        private readonly ApplicationContext $application,
        private readonly Logger $logger,
    ) {
    }

    public function describe(): string
    {
        return sprintf('single message%s', null !== $this->config->messageClass ? ' ' . $this->config->messageClass : '');
    }

    public function invoke(Context $context): int
    {
        $bus = $this->bus();
        $envelope = $this->envelope($context->payload());

        $this->logger->info('Dispatching message', [
            'message' => $envelope->getMessage()::class,
            'transport' => $this->transportName(),
        ]);

        try {
            // The ReceivedStamp is what tells the bus this message has already
            // been through a transport: middleware handles it here instead of
            // routing it back onto the queue.
            $envelope = $bus->dispatch($envelope->with(new ReceivedStamp($this->transportName())));
        } catch (HandlerFailedException $error) {
            $this->logNestedFailures($error);

            return ExitCode::WORKER_ERROR;
        }

        return $this->exitCodeFrom($envelope);
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->application->bus();

        if ($bus instanceof MessageBusInterface) {
            return $bus;
        }

        throw new BootstrapException(
            'No message bus is available. Symfony keeps most services private; expose the bus by adding '
            . '`message_bus: { alias: messenger.default_bus, public: true }` to services.yaml, set WORKER_BUS to a '
            . 'public service id, or return ["bus" => $bus] from worker.php.',
        );
    }

    private function transportName(): string
    {
        return $this->config->transport ?? $this->config->arguments[0] ?? 'worker';
    }

    private function envelope(Payload $payload): Envelope
    {
        if ($payload->isEmpty() && null === $this->config->messageClass) {
            throw new ConfigurationException(
                'Message mode needs a message. Provide WORKER_PAYLOAD / WORKER_PAYLOAD_FILE, pipe it on stdin, or '
                . 'set WORKER_MESSAGE_CLASS for a message with no data.',
            );
        }

        if (null !== $this->config->messageClass) {
            return new Envelope($this->buildMessage($this->config->messageClass, $payload->all()));
        }

        return $this->decode($payload);
    }

    /**
     * Decode a transport-shaped payload with the application's serializer.
     */
    private function decode(Payload $payload): Envelope
    {
        $data = $payload->all();

        if (!isset($data['body'])) {
            throw new ConfigurationException(
                'The payload is not in transport format. Either set WORKER_MESSAGE_CLASS so the runtime can build '
                . 'the message from JSON, or supply {"body": "...", "headers": {...}} as it came off the queue.',
            );
        }

        $serializer = $this->serializer();

        try {
            return $serializer->decode([
                'body' => (string) $data['body'],
                'headers' => array_map(strval(...), $data['headers'] ?? []),
            ]);
        } catch (Throwable $error) {
            throw new ConfigurationException(sprintf(
                'Could not decode the message with %s: %s',
                $serializer::class,
                $error->getMessage(),
            ), 0, $error);
        }
    }

    private function serializer(): SerializerInterface
    {
        $serializer = $this->application->serializer();

        if ($serializer instanceof SerializerInterface) {
            return $serializer;
        }

        if (!class_exists(PhpSerializer::class)) {
            throw new BootstrapException('symfony/messenger is not installed in the worker image.');
        }

        // Messenger's own default when a transport does not configure one.
        $this->logger->debug('No Messenger serializer service found, falling back to PhpSerializer');

        return new PhpSerializer();
    }

    /**
     * Build a message object from a JSON payload.
     *
     * symfony/serializer does this properly when it is installed. Without it,
     * constructor parameters are matched to payload keys by name, which covers
     * the readonly-DTO style most Messenger messages are written in.
     *
     * @param array<string, mixed> $data
     */
    private function buildMessage(string $class, array $data): object
    {
        if (!class_exists($class)) {
            throw new ConfigurationException(sprintf('WORKER_MESSAGE_CLASS "%s" does not exist.', $class));
        }

        $serializer = $this->application->findService(['serializer', \Symfony\Component\Serializer\Normalizer\DenormalizerInterface::class]);

        if (null !== $serializer && $serializer instanceof \Symfony\Component\Serializer\Normalizer\DenormalizerInterface) {
            /** @var object $message */
            $message = $serializer->denormalize($data, $class);

            return $message;
        }

        return $this->constructFromArray($class, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function constructFromArray(string $class, array $data): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (null === $constructor || 0 === $constructor->getNumberOfParameters()) {
            return $reflection->newInstance();
        }

        $arguments = [];
        $missing = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

            if (\array_key_exists($name, $data) || \array_key_exists($snake, $data)) {
                $arguments[$name] = $this->cast($data[$name] ?? $data[$snake], $parameter->getType());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[$name] = null;

                continue;
            }

            $missing[] = '$' . $name;
        }

        if ([] !== $missing) {
            throw new ConfigurationException(sprintf(
                'Cannot build %s: the payload has no value for %s. Install symfony/serializer for richer mapping, '
                . 'or add the missing key(s) to the payload.',
                $class,
                implode(', ', $missing),
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function cast(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin() || null === $value) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            'bool' => \is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
    }

    /**
     * A handler returning an int decides the container's exit code; that is
     * how a job can report "failed, do not retry" versus "failed, retry".
     */
    private function exitCodeFrom(Envelope $envelope): int
    {
        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            if ($stamp instanceof HandledStamp && \is_int($stamp->getResult())) {
                return $stamp->getResult();
            }
        }

        return ExitCode::SUCCESS;
    }

    private function logNestedFailures(HandlerFailedException $error): void
    {
        // getWrappedExceptions() replaced getNestedExceptions() in Messenger 6.4.
        $wrapped = method_exists($error, 'getWrappedExceptions')
            ? $error->getWrappedExceptions()
            : $error->getNestedExceptions();

        foreach ($wrapped as $nested) {
            $this->logger->exception($nested, 'Message handler failed');
        }
    }
}
