<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Application\Bootstrapper;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;
use WorkerFramework\Runtime\Exception\WorkerException;
use WorkerFramework\Runtime\Invoker\InvokerFactory;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Reporting\JobReporterFactory;
use WorkerFramework\Runtime\Signal\SignalHandler;

/**
 * The PHP runtime's entry point.
 *
 * Order matters here. Signal handling is installed before anything else so a
 * container killed during a slow kernel boot still exits cleanly; error
 * handlers go in next so that bootstrap failures are reported through the same
 * logger as everything else; only then is the user application touched.
 */
final class Runtime
{
    private readonly Logger $logger;

    private readonly SignalHandler $signals;

    private readonly float $startedAt;

    /**
     * @param array<string, string> $env    the environment the worker was started with
     * @param Logger|null           $logger overrides where runtime records go; tests and
     *                                      host applications embedding the runtime use this
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly array $env = [],
        ?Logger $logger = null,
    ) {
        $this->startedAt = microtime(true);
        $this->logger = ($logger ?? new Logger($config->logLevel, $config->logFormat))->withContext([
            'job_id' => $config->jobId,
            'mode' => $config->mode->value,
        ]);
        $this->signals = new SignalHandler($this->logger, $config->shutdownTimeout);
    }

    /**
     * @param list<string> $argv
     */
    public static function create(array $argv, ?array $env = null): self
    {
        $env ??= self::environment();

        return new self(Configuration::create($argv, $env), $env);
    }

    public function run(): int
    {
        $this->signals->register();
        $this->registerErrorHandlers();

        if ($this->config->timeout > 0) {
            $this->signals->startTimeout($this->config->timeout);
            $this->logger->debug('Wall-clock timeout armed', ['seconds' => $this->config->timeout]);
        }

        try {
            $exitCode = $this->execute();
        } catch (WorkerException $error) {
            // These are the runtime telling the operator they misconfigured
            // something; the message is the useful part, not the trace.
            $this->logger->error($error->getMessage(), ['exception' => $error::class]);
            $this->logger->debug($error->getTraceAsString());

            return $this->finish($error->exitCode());
        } catch (Throwable $error) {
            $this->logger->exception($error, 'Worker failed');

            return $this->finish(ExitCode::WORKER_ERROR);
        }

        return $this->finish($exitCode);
    }

    private function execute(): int
    {
        $application = (new Bootstrapper($this->config, $this->logger))->boot();
        $reporter = (new JobReporterFactory($application, $this->logger))
            ->create($this->config->jobReporterClass, $this->config->heartbeatInterval);
        $context = $this->createContext($application, $reporter);
        $invoker = InvokerFactory::create($this->config, $application, $this->logger);

        $this->logger->info('Starting ' . $invoker->describe(), ['pid' => getmypid()]);
        $reporter?->starting($context);

        if (null !== $reporter) {
            // Registered after starting() so that call is always the first the
            // reporter sees. A signal that arrived before now is not lost:
            // onShutdown() invokes a late listener immediately.
            $this->signals->onShutdown(function () use ($reporter, $context): void {
                $reporter->stopping($context, $this->stopReason());
            });

            // Fires only if the worker ignores the stop request entirely and
            // the runtime has to force-exit it - the one moment a normal
            // return from invoke() never happens, so it needs its own hook.
            $this->signals->onForceKill(static fn () => $reporter->stopped($context, StopReason::Forced));
        }

        try {
            $exitCode = $invoker->invoke($context);
        } catch (WorkerException $error) {
            $reporter?->failed($context, $error->exitCode(), $error);

            throw $error;
        } catch (Throwable $error) {
            $reporter?->failed($context, ExitCode::WORKER_ERROR, $error);

            throw $error;
        }

        $exitCode = $this->reconcile($exitCode);
        $this->reportOutcome($reporter, $context, $exitCode);

        return $exitCode;
    }

    private function createContext(ApplicationContext $application, ?JobReporter $reporter): Context
    {
        return new Context(
            $this->config->jobId,
            $this->config->mode,
            Payload::discover($this->env),
            $this->logger,
            $this->signals,
            $this->config->deadline($this->startedAt),
            $application->container(),
            $reporter,
        );
    }

    /**
     * Tell the reporter what happened to a run that returned normally -
     * stopped, succeeded or failed by its own return value, in that priority,
     * since "was this caused by a stop request" is more informative than the
     * raw exit code once one was requested.
     */
    private function reportOutcome(?JobReporter $reporter, Context $context, int $exitCode): void
    {
        if (null === $reporter) {
            return;
        }

        if ($this->signals->isStopping()) {
            $reporter->stopped($context, $this->stopReason());
        } elseif (ExitCode::SUCCESS === $exitCode) {
            $reporter->succeeded($context);
        } else {
            $reporter->failed($context, $exitCode, null);
        }
    }

    /**
     * Why a stop was requested. Only meaningful once `isStopping()` is true.
     * Forced is never one of the answers here: that is decided later, by
     * SignalHandler, if the grace period runs out.
     */
    private function stopReason(): StopReason
    {
        return $this->signals->hasTimedOut() ? StopReason::Timeout : StopReason::Signal;
    }

    /**
     * Decide what a run that was interrupted should report.
     *
     * A long-running worker (WORKER_LONG_RUNNING, on by default for `consume`)
     * stopped by SIGTERM did exactly what it was told, so it exits 0 and a
     * rolling deploy stays quiet. A one-shot job cut short did not finish its
     * work, so it reports 128+signal and the scheduler can retry. Either way
     * this only overrides a *successful* return - a worker that already
     * reported its own failure or its own exit code keeps it. A run killed by
     * WORKER_TIMEOUT always reports the timeout, because that is a fault
     * however it is deployed.
     */
    private function reconcile(int $exitCode): int
    {
        if (ExitCode::SUCCESS !== $exitCode) {
            return $exitCode;
        }

        if ($this->signals->hasTimedOut()) {
            return ExitCode::TIMEOUT;
        }

        if ($this->signals->isStopping() && !$this->config->longRunning) {
            return $this->signals->exitCode();
        }

        return ExitCode::SUCCESS;
    }

    private function finish(int $exitCode): int
    {
        // Leave the process as it was found, so a host application embedding
        // the runtime - or a second run - is unaffected.
        restore_error_handler();
        $this->signals->detach();

        // A worker that was asked to stop and did so is not a failure, even
        // though its exit code is non-zero.
        $stopped = $this->signals->isStopping() && !$this->signals->hasTimedOut();
        $failed = ExitCode::SUCCESS !== $exitCode && !$stopped;

        $this->logger->log(
            $failed ? Log\LogLevel::Error : Log\LogLevel::Info,
            match (true) {
                $failed => 'Worker finished with errors',
                $stopped => 'Worker stopped on request',
                default => 'Worker completed',
            },
            [
                'exit_code' => $exitCode,
                'duration' => sprintf('%.3fs', microtime(true) - $this->startedAt),
                'peak_memory' => sprintf('%.1fMB', memory_get_peak_usage(true) / 1048576),
            ],
        );

        return $exitCode;
    }

    /**
     * Route PHP's own diagnostics through the runtime logger.
     *
     * Warnings are logged rather than thrown by default: Symfony emits
     * deprecations as errors during boot, and turning those into exceptions
     * would break perfectly healthy applications. Set WORKER_STRICT_ERRORS=1
     * to get the fail-fast behaviour instead.
     */
    private function registerErrorHandlers(): void
    {
        $strict = filter_var(getenv('WORKER_STRICT_ERRORS') ?: '0', FILTER_VALIDATE_BOOL);

        set_error_handler(function (int $number, string $message, string $file, int $line) use ($strict): bool {
            // Respect the configured error_reporting mask, and never promote a
            // deprecation to a fatal error.
            if (0 === (error_reporting() & $number)) {
                return true;
            }

            if ($strict && !\in_array($number, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                throw new \ErrorException($message, 0, $number, $file, $line);
            }

            $this->logger->warning($message, ['origin' => sprintf('%s:%d', $file, $line), 'errno' => $number]);

            return true;
        });

        // Fatal errors bypass the exception handler entirely; this is the only
        // place they can still be reported with context.
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if (null !== $error && \in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logger->critical($error['message'], [
                    'origin' => sprintf('%s:%d', $error['file'], $error['line']),
                    'exit_code' => ExitCode::WORKER_ERROR,
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        /** @var array<string, string> $env */
        $env = getenv();

        // $_SERVER carries anything symfony/dotenv has populated since start-up.
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key) && \is_string($value) && !\array_key_exists($key, $env)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
