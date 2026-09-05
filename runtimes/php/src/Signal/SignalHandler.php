<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Signal;

use WorkerFramework\Runtime\ExitCode;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Cooperative shutdown for long-running workers.
 *
 * A worker that can run for hours has to be stoppable without losing the unit
 * of work in flight, so the first termination signal only *requests* a stop:
 * listeners fire, `isStopping()` flips, and the invoker is expected to finish
 * what it is doing and return. A second signal of the same kind means the
 * operator is no longer asking politely, and the process exits immediately.
 *
 * `WORKER_SHUTDOWN_TIMEOUT` puts an upper bound on the polite phase: if the
 * worker has not returned by then, SIGALRM ends the process with the same exit
 * code the original signal would have produced.
 *
 * The handlers chain to whatever was registered before them. That matters
 * because Symfony's Console component installs its own pcntl handlers for
 * commands implementing SignalableCommandInterface - `messenger:consume` among
 * them - and both sets need to run.
 */
final class SignalHandler
{
    private const TERMINATION_SIGNALS = [SIGTERM, SIGINT, SIGHUP, SIGQUIT];

    private bool $stopping = false;

    private ?int $signal = null;

    private bool $timedOut = false;

    private bool $registered = false;

    private bool $active = true;

    /** @var list<callable(int): void> */
    private array $listeners = [];

    /** @var array<int, int> */
    private array $received = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly int $shutdownTimeout = 30,
    ) {
    }

    /**
     * True when pcntl is available; without it the runtime still works but can
     * only be stopped by the container runtime's SIGKILL.
     */
    public static function isSupported(): bool
    {
        return \function_exists('pcntl_async_signals') && \function_exists('pcntl_signal');
    }

    public function register(): void
    {
        if ($this->registered || !self::isSupported()) {
            if (!$this->registered && !self::isSupported()) {
                $this->logger->warning('ext-pcntl is not loaded; graceful shutdown is disabled');
            }

            return;
        }

        pcntl_async_signals(true);

        foreach (self::TERMINATION_SIGNALS as $signal) {
            $this->chain($signal, fn () => $this->handleTermination($signal));
        }

        if (\defined('SIGALRM')) {
            $this->chain(SIGALRM, fn () => $this->handleAlarm());
        }

        $this->registered = true;
    }

    /**
     * Start the wall-clock budget for the whole worker. When it expires the
     * runtime asks the worker to stop, exactly as if it had been signalled.
     */
    public function startTimeout(int $seconds): void
    {
        if ($seconds > 0 && \function_exists('pcntl_alarm')) {
            pcntl_alarm($seconds);
        }
    }

    /**
     * Ask the worker to stop as if $signal had been received. Used for the
     * wall-clock timeout and by hooks that decide the job should not continue.
     */
    public function requestStop(int $signal = SIGTERM): void
    {
        $this->handleTermination($signal, forced: false);
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * The signal that triggered the shutdown, or null if none has arrived.
     */
    public function signal(): ?int
    {
        return $this->signal;
    }

    public function hasTimedOut(): bool
    {
        return $this->timedOut;
    }

    /**
     * Exit code matching the reason the worker is stopping.
     */
    public function exitCode(): int
    {
        return match (true) {
            $this->timedOut => ExitCode::TIMEOUT,
            null !== $this->signal => ExitCode::fromSignal($this->signal),
            default => ExitCode::SUCCESS,
        };
    }

    /**
     * Register a callback to run when a stop is first requested. Callbacks run
     * inside the signal handler, so they must be quick and must not throw.
     *
     * @param callable(int): void $listener
     */
    public function onShutdown(callable $listener): void
    {
        $this->listeners[] = $listener;

        // A listener registered after the signal already arrived would
        // otherwise never run.
        if ($this->stopping) {
            $this->invoke($listener, $this->signal ?? SIGTERM);
        }
    }

    /**
     * Dispatch pending signals. Only needed when async signal delivery is
     * unavailable; harmless otherwise, which is why Context exposes it to
     * worker code as a "checkpoint" call.
     */
    public function tick(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Stop reacting to signals. Called once a run is over; the handlers stay
     * installed because pcntl offers no way to remove just ours, but they no
     * longer do anything.
     */
    public function detach(): void
    {
        $this->active = false;

        if (\function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }

    private function handleTermination(int $signal, bool $forced = true): void
    {
        // pcntl handlers live for the life of the process and this one chains
        // to whatever came before it, so a finished run must stop reacting -
        // otherwise a second run in the same process inherits the first one's
        // "already asked once, exit now" state.
        if (!$this->active) {
            return;
        }

        $this->received[$signal] = ($this->received[$signal] ?? 0) + 1;

        // Second time of asking: the operator wants the process gone now.
        if ($forced && $this->received[$signal] > 1) {
            $this->logger->warning('Signal received again, exiting immediately', ['signal' => $signal]);

            exit(ExitCode::fromSignal($signal));
        }

        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->signal = $signal;

        $this->logger->notice('Shutdown requested, finishing current unit of work', [
            'signal' => $signal,
            'grace_period' => $this->shutdownTimeout,
        ]);

        // Bound the graceful phase. This replaces any timeout alarm, which is
        // what we want: once we are stopping, the grace period is the only
        // deadline that still matters.
        if ($this->shutdownTimeout > 0 && \function_exists('pcntl_alarm')) {
            pcntl_alarm($this->shutdownTimeout);
        }

        foreach ($this->listeners as $listener) {
            $this->invoke($listener, $signal);
        }
    }

    private function handleAlarm(): void
    {
        if (!$this->active) {
            return;
        }

        if ($this->stopping) {
            $this->logger->error('Worker did not stop within the grace period, exiting', [
                'grace_period' => $this->shutdownTimeout,
            ]);

            exit($this->exitCode());
        }

        $this->timedOut = true;
        $this->logger->warning('Worker timeout reached, requesting shutdown');
        $this->handleTermination(SIGTERM, forced: false);
    }

    /**
     * Install $handler for $signal while preserving any existing handler.
     */
    private function chain(int $signal, callable $handler): void
    {
        $previous = pcntl_signal_get_handler($signal);

        pcntl_signal($signal, static function (int $received) use ($handler, $previous): void {
            $handler($received);

            if (\is_callable($previous)) {
                $previous($received);
            }
        });
    }

    /**
     * @param callable(int): void $listener
     */
    private function invoke(callable $listener, int $signal): void
    {
        try {
            $listener($signal);
        } catch (\Throwable $error) {
            // A failing shutdown listener must not mask the shutdown itself.
            $this->logger->exception($error, 'Shutdown listener failed');
        }
    }
}
