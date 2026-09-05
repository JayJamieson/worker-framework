<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Fixtures;

use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\ShutdownAware;
use WorkerFramework\Runtime\Contract\Worker;

/** Implements the documented interface and reports success. */
final class InterfaceWorker implements Worker
{
    public function handle(Context $context): ?int
    {
        Recorder::record('handle', $context->jobId());

        return null;
    }
}

/** The proof of concept's shape: a `perform()` method and no interface. */
final class LegacyWorker
{
    public function perform(): void
    {
        Recorder::record('perform');
    }
}

/** Single-purpose invokable with payload fields as typed arguments. */
final class InvokableWorker
{
    public function __invoke(int $companyId, string $reportType, bool $dryRun = false): void
    {
        Recorder::record('invoke', compact('companyId', 'reportType', 'dryRun'));
    }
}

/** Reports failure through its return value. */
final class FailingWorker
{
    public function handle(): int
    {
        return 17;
    }
}

/** Throws, to exercise the runtime's exception path. */
final class ThrowingWorker
{
    public function handle(): void
    {
        throw new \RuntimeException('worker exploded');
    }
}

/** Needs constructor arguments, so it can only come from a container. */
final class InjectedWorker
{
    public function __construct(private readonly string $connection)
    {
    }

    public function handle(): void
    {
        Recorder::record('injected', $this->connection);
    }
}

/** Loops until asked to stop; used for signal tests. */
final class LoopingWorker implements ShutdownAware
{
    public function run(Context $context): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            if ($context->checkpoint()) {
                Recorder::record('stopped_at', $i);

                return;
            }

            Recorder::record('tick', $i);
        }
    }

    public function onShutdown(Context $context, int $signal): void
    {
        Recorder::record('shutdown_listener', $signal);
    }
}

/** Receives the whole payload as an array. */
final class ArrayWorker
{
    public function handle(array $payload, Context $context): void
    {
        Recorder::record('array', $payload);
    }
}

/**
 * Signals itself part way through, which is how the tests reproduce a `docker
 * stop` without needing a second process.
 */
final class SelfTerminatingWorker
{
    public function run(Context $context): void
    {
        for ($i = 0; $i < 100; ++$i) {
            if ($context->checkpoint()) {
                Recorder::record('stopped_at', $i);

                return;
            }

            Recorder::record('tick', $i);

            if (2 === $i) {
                posix_kill((int) getmypid(), \SIGTERM);
            }
        }

        Recorder::record('ran_to_completion');
    }
}

/**
 * Ignores the stop request entirely, to prove the runtime still reports it.
 */
final class StubbornWorker
{
    public function run(Context $context): void
    {
        posix_kill((int) getmypid(), \SIGTERM);

        for ($i = 0; $i < 5; ++$i) {
            Recorder::record('tick', $i);
        }
    }
}

/**
 * Sleeps in short bursts, checking for a stop request between them.
 */
final class SleepingWorker
{
    public function run(Context $context): void
    {
        for ($i = 0; $i < 200; ++$i) {
            if ($context->checkpoint()) {
                Recorder::record('stopped_at', $i);

                return;
            }

            usleep(20_000);
        }
    }
}
