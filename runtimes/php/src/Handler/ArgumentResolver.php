<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Handler;

use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Payload;

/**
 * Works out what to pass to a handler method.
 *
 * The rules are deliberately boring, and are applied per parameter in order:
 *
 *   1. Typed `Context` or `Payload` - the runtime object.
 *   2. Any other class type the container can provide - that service.
 *   3. Typed `array` - the decoded payload.
 *   4. A parameter whose name matches a payload key - that value, cast to the
 *      declared scalar type where one is declared.
 *   5. A default value, or null for a nullable parameter.
 *
 * That covers the two signatures people actually write - `handle(Context $c)`
 * and `handle(array $payload)` - while letting a worker declare exactly the
 * fields it wants: `handle(int $companyId, string $reportType)` reads straight
 * from `{"companyId": 42, "reportType": "monthly"}`.
 */
final class ArgumentResolver
{
    public function __construct(private readonly ApplicationContext $application)
    {
    }

    /**
     * @return list<mixed>
     */
    public function resolve(ReflectionFunctionAbstract $function, Context $context): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $context, $function);
        }

        return $arguments;
    }

    private function resolveParameter(ReflectionParameter $parameter, Context $context, ReflectionFunctionAbstract $function): mixed
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        $payload = $context->payload();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $class = $type->getName();

            if (Context::class === $class || is_subclass_of(Context::class, $class)) {
                return $context;
            }

            if (Payload::class === $class) {
                return $payload;
            }

            if (null !== $service = $this->application->findService([$class])) {
                return $service;
            }
        }

        if ($type instanceof ReflectionNamedType && 'array' === $type->getName() && !$payload->has($name)) {
            return $payload->all();
        }

        if ($payload->has($name)) {
            return $this->cast($payload->get($name), $type);
        }

        // Snake_case payloads are common when the dispatcher is not PHP.
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        if ($payload->has($snake)) {
            return $this->cast($payload->get($snake), $type);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ConfigurationException(sprintf(
            'Cannot resolve argument $%s of %s: it is not in the payload, has no default, and is not a service.',
            $name,
            $this->describe($function),
        ));
    }

    /**
     * Coerce a JSON value to the parameter's declared scalar type.
     *
     * JSON has no integer/string distinction once a value has been through a
     * shell environment variable, so `WORKER_PAYLOAD='{"companyId":"42"}'`
     * should still satisfy `int $companyId`.
     */
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

    private function describe(ReflectionFunctionAbstract $function): string
    {
        $class = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() . '::' : '';

        return $class . $function->getName() . '()';
    }
}
