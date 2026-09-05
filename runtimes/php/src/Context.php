<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Signal\SignalHandler;

/**
 * Everything the runtime knows about the current run, handed to worker code.
 *
 * This is the framework's equivalent of Lambda's context object, with one
 * addition that matters here: because a worker may run for hours rather than
 * minutes, it can be asked to stop. Long loops should check `isStopping()` (or
 * call `checkpoint()`, which also dispatches pending signals) and return
 * cleanly when it returns true, rather than waiting to be killed.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        private readonly string $jobId,
        private readonly Mode $mode,
        private readonly Payload $payload,
        private readonly Logger $logger,
        private readonly SignalHandler $signals,
        private readonly ?float $deadline = null,
        private readonly ?object $container = null,
    ) {
    }

    /**
     * Identifier for this run. Taken from WORKER_JOB_ID when the dispatcher
     * supplies one, otherwise generated, so logs are always correlatable.
     */
    public function jobId(): string
    {
        return $this->jobId;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * The application's service container when one was booted, null for plain
     * PHP workers. Typed loosely on purpose: the runtime has no dependency on
     * psr/container.
     */
    public function container(): ?object
    {
        return $this->container;
    }

    /**
     * True once a stop has been requested by signal or by WORKER_TIMEOUT.
     */
    public function isStopping(): bool
    {
        return $this->signals->isStopping();
    }

    /**
     * Dispatch pending signals and report whether the worker should stop.
     *
     * Call this between units of work in a long loop; it is the one call that
     * keeps a worker interruptible even when async signal delivery is off.
     */
    public function checkpoint(): bool
    {
        $this->signals->tick();

        return $this->signals->isStopping();
    }

    /**
     * Run $listener when a stop is requested. Keep it short - it executes
     * inside the signal handler.
     *
     * @param callable(int): void $listener
     */
    public function onShutdown(callable $listener): void
    {
        $this->signals->onShutdown($listener);
    }

    /**
     * Seconds left before WORKER_TIMEOUT fires, or null when unbounded.
     */
    public function remainingTime(): ?float
    {
        return null === $this->deadline ? null : max(0.0, $this->deadline - microtime(true));
    }

    /**
     * Arbitrary per-run state, useful for passing values between a handler and
     * its shutdown listener.
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
