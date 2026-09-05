<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Handler;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Exception\HandlerNotFoundException;

/**
 * Turns the `WORKER_HANDLER` string into something callable.
 *
 * Accepted forms, in the order they are tried:
 *
 *   App\Worker\SendReports          class, method auto-detected
 *   App\Worker\SendReports::run     class and explicit method
 *   app.worker.send_reports         service id from the container
 *   ./jobs/send-reports.php         PHP file returning a callable
 *   send_reports                    plain function
 *
 * Class instances come from the service container whenever it can provide
 * them, so a Symfony worker gets its constructor dependencies injected exactly
 * as it would anywhere else in the app. Only when the container has no such
 * service does the resolver fall back to `new`.
 */
final class HandlerResolver
{
    /**
     * Method names tried on a handler class, in order. `__invoke` first keeps
     * single-purpose invokable classes idiomatic; the rest cover the shapes
     * that already existed in the wild, including the proof of concept's
     * `perform()`.
     */
    private const METHODS = ['__invoke', 'handle', 'run', 'perform', 'main', 'execute'];

    public function __construct(private readonly ApplicationContext $application)
    {
    }

    public function resolve(?string $specification): ResolvedHandler
    {
        if (null === $specification || '' === trim($specification)) {
            throw new ConfigurationException(
                'No handler configured. Pass one as the first argument (CMD ["handler", "App\\\\Worker\\\\SendReports"]) '
                . 'or set WORKER_HANDLER.',
            );
        }

        $specification = trim($specification);

        [$name, $method] = $this->split($specification);

        if (str_ends_with($name, '.php')) {
            return $this->fromFile($name, $method);
        }

        $target = $this->instantiate($name, $specification);

        if (\is_object($target)) {
            return new ResolvedHandler($target, $this->selectMethod($target, $method, $specification), $specification);
        }

        // A plain function; there is no object to reflect a method on.
        return new ResolvedHandler(null, $target, $specification);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function split(string $specification): array
    {
        if (str_contains($specification, '::')) {
            [$name, $method] = explode('::', $specification, 2);

            return [$name, '' === $method ? null : $method];
        }

        return [$specification, null];
    }

    private function fromFile(string $path, ?string $method): ResolvedHandler
    {
        if (!is_file($path)) {
            throw new HandlerNotFoundException(sprintf('Handler file "%s" does not exist.', $path));
        }

        /** @psalm-suppress UnresolvableInclude */
        $result = require $path;

        if (\is_callable($result)) {
            return new ResolvedHandler(null, $result, $path);
        }

        if (\is_object($result)) {
            return new ResolvedHandler($result, $this->selectMethod($result, $method, $path), $path);
        }

        throw new HandlerNotFoundException(sprintf(
            'Handler file "%s" must return a callable or an object, got %s.',
            $path,
            get_debug_type($result),
        ));
    }

    /**
     * @return object|callable-string
     */
    private function instantiate(string $name, string $specification): object|string
    {
        // Service ids win over class names: an app that registers
        // `App\Worker\Foo` as a service wants that instance, wired up, rather
        // than a bare `new`.
        if (null !== $service = $this->application->findService([$name])) {
            return $service;
        }

        if (\function_exists($name)) {
            return $name;
        }

        if (!class_exists($name)) {
            throw new HandlerNotFoundException(sprintf(
                'Handler "%s" could not be resolved: there is no class, function, service or file by that name. '
                . 'Check that it is autoloadable from %s.',
                $specification,
                $this->application->container() ? 'the application container or Composer' : 'Composer',
            ));
        }

        return $this->construct($name, $specification);
    }

    private function construct(string $class, string $specification): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $error) {
            throw new HandlerNotFoundException(sprintf('Handler class "%s" is not usable: %s', $class, $error->getMessage()), 0, $error);
        }

        if (!$reflection->isInstantiable()) {
            throw new HandlerNotFoundException(sprintf(
                'Handler class "%s" cannot be instantiated (it is abstract or has a non-public constructor).',
                $class,
            ));
        }

        $required = $reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0;

        if ($required > 0) {
            throw new HandlerNotFoundException(sprintf(
                'Handler "%s" needs %d constructor argument(s) and is not registered as a service. '
                . 'Register it in services.yaml with `public: true`, or build it yourself in worker.php.',
                $specification,
                $required,
            ));
        }

        try {
            return $reflection->newInstance();
        } catch (Throwable $error) {
            throw new HandlerNotFoundException(sprintf('Handler "%s" could not be constructed: %s', $class, $error->getMessage()), 0, $error);
        }
    }

    private function selectMethod(object $target, ?string $requested, string $specification): string
    {
        if (null !== $requested) {
            if (!$this->isCallableMethod($target, $requested)) {
                throw new HandlerNotFoundException(sprintf(
                    'Handler "%s" has no public method "%s".',
                    $specification,
                    $requested,
                ));
            }

            return $requested;
        }

        foreach (self::METHODS as $candidate) {
            if ($this->isCallableMethod($target, $candidate)) {
                return $candidate;
            }
        }

        throw new HandlerNotFoundException(sprintf(
            'Handler "%s" exposes none of the expected methods (%s). Add one, or name it explicitly as "%s::yourMethod".',
            $specification,
            implode(', ', self::METHODS),
            $target::class,
        ));
    }

    private function isCallableMethod(object $target, string $method): bool
    {
        if (!method_exists($target, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($target, $method);

        return $reflection->isPublic() && !$reflection->isStatic() && !$reflection->isAbstract();
    }
}
