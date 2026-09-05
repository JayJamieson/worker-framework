<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Handler;

use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * A handler the runtime is ready to call, plus the reflection needed to work
 * out what to pass it.
 */
final class ResolvedHandler
{
    /**
     * @param object|null              $target     null for plain functions and closures
     * @param string|callable          $callable   method name on $target, or a standalone callable
     */
    public function __construct(
        public readonly ?object $target,
        public readonly string|Closure|array $callable,
        public readonly string $specification,
    ) {
    }

    public function reflect(): ReflectionFunctionAbstract
    {
        if (null !== $this->target && \is_string($this->callable)) {
            return new ReflectionMethod($this->target, $this->callable);
        }

        /** @var callable $callable */
        $callable = $this->callable;

        return new ReflectionFunction(Closure::fromCallable($callable));
    }

    /**
     * @param list<mixed> $arguments
     */
    public function call(array $arguments): mixed
    {
        if (null !== $this->target && \is_string($this->callable)) {
            return $this->target->{$this->callable}(...$arguments);
        }

        /** @var callable $callable */
        $callable = $this->callable;

        return $callable(...$arguments);
    }

    public function describe(): string
    {
        if (null !== $this->target && \is_string($this->callable)) {
            return sprintf('%s::%s()', $this->target::class, $this->callable);
        }

        return \is_string($this->callable) ? $this->callable . '()' : $this->specification;
    }
}
