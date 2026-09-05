<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Application\Bootstrapper;
use WorkerFramework\Runtime\Exception\WorkerException;
use WorkerFramework\Runtime\Invoker\InvokerFactory;
use WorkerFramework\Runtime\Log\Logger;
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
        $context = $this->createContext($application);
        $invoker = InvokerFactory::create($this->config, $application, $this->logger);

        $this->logger->info('Starting ' . $invoker->describe(), ['pid' => getmypid()]);

        $exitCode = $invoker->invoke($context);

        return $this->reconcile($exitCode);
    }

    private function createContext(ApplicationContext $application): Context
    {
        return new Context(
            $this->config->jobId,
            $this->config->mode,
            Payload::discover($this->env),
            $this->logger,
            $this->signals,
            $this->config->deadline($this->startedAt),
            $application->container(),
        );
    }

    /**
     * Decide what a run that was interrupted should report.
     *
     * A consumer stopped by SIGTERM did exactly what it was told, so it exits
     * 0 and a rolling deploy stays quiet. A one-shot job cut short did not
     * finish its work, so it reports 128+signal and the scheduler can retry.
     * A run killed by WORKER_TIMEOUT always reports the timeout, because that
     * is a fault however it is deployed.
     */
    private function reconcile(int $exitCode): int
    {
        if (ExitCode::SUCCESS !== $exitCode) {
            return $exitCode;
        }

        if ($this->signals->hasTimedOut()) {
            return ExitCode::TIMEOUT;
        }

        if ($this->signals->isStopping() && Mode::Consume !== $this->config->mode) {
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
