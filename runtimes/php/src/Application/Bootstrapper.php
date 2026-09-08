<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Application;

use Closure;
use Throwable;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Exception\BootstrapException;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Turns a worker image's file system into a usable application.
 *
 * Three sources are tried, most explicit first:
 *
 *  1. `worker.php` (or `WORKER_BOOTSTRAP`) - a file returning the Kernel,
 *     Console Application, message bus, or an array of those. This is the
 *     escape hatch for apps whose wiring the runtime cannot guess.
 *  2. `WORKER_KERNEL_CLASS` (default `App\Kernel`) - instantiated with
 *     (env, debug) and booted, which covers a stock Symfony Flex project.
 *  3. Nothing - plain PHP workers get an empty context and still run.
 *
 * Composer's autoloader and `.env` are handled first in every case, because
 * neither the bootstrap file nor the Kernel class can be found without them.
 */
final class Bootstrapper
{
    public function __construct(
        private readonly Configuration $config,
        private readonly Logger $logger,
    ) {
    }

    public function boot(): ApplicationContext
    {
        $this->loadAutoloader();
        $this->loadDotenv();

        $context = $this->fromBootstrapFile() ?? $this->fromKernelClass() ?? ApplicationContext::empty();

        return $this->hydrate($context);
    }

    private function loadAutoloader(): void
    {
        if (null === $this->config->autoloadFile) {
            $this->logger->debug('No Composer autoloader found; only classes shipped with the runtime are available', [
                'worker_root' => $this->config->workerRoot,
            ]);

            return;
        }

        require_once $this->config->autoloadFile;

        $this->logger->debug('Composer autoloader loaded', ['file' => $this->config->autoloadFile]);
    }

    /**
     * Populate the environment from `.env` when symfony/dotenv is installed.
     *
     * `bootEnv` never overrides variables that are already set, so anything
     * passed with `docker run -e` still wins over the file baked into the image.
     */
    private function loadDotenv(): void
    {
        $dotenv = $this->config->workerRoot . '/.env';

        if (!$this->config->loadDotenv || !class_exists(\Symfony\Component\Dotenv\Dotenv::class) || !is_file($dotenv)) {
            return;
        }

        try {
            (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($dotenv, $this->config->appEnv);
            $this->logger->debug('Environment loaded from .env', ['file' => $dotenv]);
        } catch (Throwable $error) {
            throw new BootstrapException(sprintf('Failed to load "%s": %s', $dotenv, $error->getMessage()), 0, $error);
        }
    }

    private function fromBootstrapFile(): ?ApplicationContext
    {
        if (null === $this->config->bootstrapFile) {
            return null;
        }

        $this->logger->debug('Running bootstrap file', ['file' => $this->config->bootstrapFile]);

        try {
            /** @psalm-suppress UnresolvableInclude */
            $result = require $this->config->bootstrapFile;
        } catch (Throwable $error) {
            throw new BootstrapException(
                sprintf('Bootstrap file "%s" failed: %s', $this->config->bootstrapFile, $error->getMessage()),
                0,
                $error,
            );
        }

        if ($result instanceof Closure) {
            $result = $result($this->config);
        }

        // `require` yields int(1) for a file with no return statement; treat
        // that as "the file only had side effects" and keep looking.
        if (null === $result || true === $result || 1 === $result) {
            return null;
        }

        return $this->interpret($result, $this->config->bootstrapFile);
    }

    private function fromKernelClass(): ?ApplicationContext
    {
        $class = $this->config->kernelClass;

        if (!class_exists($class)) {
            $this->logger->debug('No kernel class found; running without a framework', ['class' => $class]);

            return null;
        }

        $env = (string) ($_SERVER['APP_ENV'] ?? $this->config->appEnv);
        $debug = filter_var($_SERVER['APP_DEBUG'] ?? $this->config->appDebug, FILTER_VALIDATE_BOOL);

        try {
            $kernel = new $class($env, $debug);
        } catch (Throwable $error) {
            throw new BootstrapException(
                sprintf('Could not instantiate kernel "%s(%s, %s)": %s', $class, $env, $debug ? 'true' : 'false', $error->getMessage()),
                0,
                $error,
            );
        }

        $this->logger->info('Booting application kernel', ['kernel' => $class, 'env' => $env, 'debug' => $debug]);

        return $this->bootKernel($kernel);
    }

    /**
     * @param mixed $result whatever the bootstrap file returned
     */
    private function interpret(mixed $result, string $source): ApplicationContext
    {
        if (\is_array($result)) {
            return $this->fromArray($result);
        }

        if (!\is_object($result)) {
            throw new BootstrapException(sprintf(
                'Bootstrap file "%s" must return a Kernel, a Console Application, a message bus, or an array of those; got %s.',
                $source,
                get_debug_type($result),
            ));
        }

        return match (true) {
            $this->isKernel($result) => $this->bootKernel($result),
            $result instanceof \Symfony\Component\Console\Application => new ApplicationContext(console: $result),
            $this->isBus($result) => new ApplicationContext(bus: $result),
            $this->isContainer($result) => new ApplicationContext(container: $result),
            default => throw new BootstrapException(sprintf(
                'Bootstrap file "%s" returned a %s, which the runtime does not know how to use. '
                . 'Return an array such as ["bus" => $bus, "receivers" => [...]] to be explicit.',
                $source,
                $result::class,
            )),
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private function fromArray(array $result): ApplicationContext
    {
        $known = ['kernel', 'container', 'console', 'application', 'bus', 'message_bus', 'receivers', 'serializer', 'event_dispatcher', 'dispatcher'];

        $kernel = $this->object($result, 'kernel');

        if (null !== $kernel) {
            $booted = $this->bootKernel($kernel);
            $container = $this->object($result, 'container') ?? $booted->container();
        } else {
            $container = $this->object($result, 'container');
        }

        /** @var array<string, object> $receivers */
        $receivers = \is_array($result['receivers'] ?? null) ? $result['receivers'] : [];

        return new ApplicationContext(
            kernel: $kernel,
            container: $container,
            console: $this->object($result, 'console') ?? $this->object($result, 'application'),
            bus: $this->object($result, 'bus') ?? $this->object($result, 'message_bus'),
            receivers: $receivers,
            serializer: $this->object($result, 'serializer'),
            eventDispatcher: $this->object($result, 'event_dispatcher') ?? $this->object($result, 'dispatcher'),
            extra: array_diff_key($result, array_flip($known)),
        );
    }

    private function bootKernel(object $kernel): ApplicationContext
    {
        try {
            if (method_exists($kernel, 'boot')) {
                $kernel->boot();
            }

            $container = method_exists($kernel, 'getContainer') ? $kernel->getContainer() : null;
        } catch (Throwable $error) {
            throw new BootstrapException(sprintf('Kernel "%s" failed to boot: %s', $kernel::class, $error->getMessage()), 0, $error);
        }

        return new ApplicationContext(kernel: $kernel, container: \is_object($container) ? $container : null);
    }

    /**
     * Fill in the services the runtime can find on its own, leaving anything
     * the bootstrap file supplied untouched.
     */
    private function hydrate(ApplicationContext $context): ApplicationContext
    {
        if (null === $context->container()) {
            return $context;
        }

        return new ApplicationContext(
            kernel: $context->kernel(),
            container: $context->container(),
            console: $context->console(),
            bus: $context->bus() ?? $context->findService($this->busServiceIds()),
            receivers: $context->receivers(),
            serializer: $context->serializer() ?? $context->findService($this->serializerServiceIds()),
            eventDispatcher: $context->eventDispatcher() ?? $context->findService(['event_dispatcher', \Symfony\Component\EventDispatcher\EventDispatcherInterface::class]),
            extra: $context->extra(),
        );
    }

    /**
     * @return list<string>
     */
    private function busServiceIds(): array
    {
        return array_values(array_filter([
            $this->config->busService,
            'message_bus',
            'messenger.default_bus',
            \Symfony\Component\Messenger\MessageBusInterface::class,
        ]));
    }

    /**
     * @return list<string>
     */
    private function serializerServiceIds(): array
    {
        return array_values(array_filter([
            $this->config->serializerService,
            'messenger.default_serializer',
            'messenger.transport.symfony_serializer',
            \Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class,
        ]));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function object(array $values, string $key): ?object
    {
        $value = $values[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_object($value)) {
            throw new BootstrapException(sprintf('Bootstrap entry "%s" must be an object, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    private function isKernel(object $candidate): bool
    {
        return $candidate instanceof \Symfony\Component\HttpKernel\KernelInterface
            || (method_exists($candidate, 'boot') && method_exists($candidate, 'getContainer'));
    }

    private function isBus(object $candidate): bool
    {
        return $candidate instanceof \Symfony\Component\Messenger\MessageBusInterface;
    }

    private function isContainer(object $candidate): bool
    {
        return $candidate instanceof \Psr\Container\ContainerInterface
            || (method_exists($candidate, 'get') && method_exists($candidate, 'has'));
    }
}
