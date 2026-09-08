<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Log\LogLevel;

/**
 * Everything the runtime was asked to do, resolved from argv and environment.
 *
 * The command line reads `runtime.php <mode> [mode arguments...]`, which is
 * what a Dockerfile expresses as `CMD ["consume", "async"]`. For backwards
 * compatibility with the original proof of concept, a first argument that is
 * not a known mode is treated as a handler name, so `CMD ["App\\Job"]` still
 * runs. Every argument also has an environment equivalent, because job
 * schedulers usually find it easier to set a variable than to rewrite a
 * container's command.
 */
final class Configuration
{
    /**
     * @param list<string> $arguments mode-specific arguments from the command line
     */
    private function __construct(
        public readonly Mode $mode,
        public readonly array $arguments,
        public readonly string $workerRoot,
        public readonly string $runtimeDir,
        public readonly ?string $handler,
        public readonly ?string $autoloadFile,
        public readonly ?string $bootstrapFile,
        public readonly string $kernelClass,
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly bool $loadDotenv,
        public readonly string $jobId,
        public readonly int $timeout,
        public readonly int $shutdownTimeout,
        public readonly bool $longRunning,
        public readonly LogLevel $logLevel,
        public readonly string $logFormat,
        public readonly Limits $limits,
        public readonly ?string $messageClass,
        public readonly ?string $transport,
        public readonly ?string $busService,
        public readonly ?string $serializerService,
        public readonly ?string $jobReporterClass,
        public readonly int $heartbeatInterval,
    ) {
    }

    /**
     * @param list<string>          $argv full argv, including the script name
     * @param array<string, string> $env
     */
    public static function create(array $argv, array $env): self
    {
        $workerRoot = self::directory($env['WORKER_ROOT'] ?? null) ?? getcwd() ?: '/var/task';
        $arguments = array_values(array_slice($argv, 1));
        $mode = self::resolveMode($arguments, $env);

        // resolveMode consumes the leading mode token when it recognises one.
        $handler = Mode::Handler === $mode
            ? ($arguments[0] ?? null) ?? self::value($env, 'WORKER_HANDLER')
            : self::value($env, 'WORKER_HANDLER');

        if (Mode::Handler === $mode && [] !== $arguments) {
            $arguments = array_slice($arguments, 1);
        }

        if ([] === $arguments) {
            $arguments = self::argumentsFromEnvironment($mode, $env);
        }

        return new self(
            mode: $mode,
            arguments: array_values($arguments),
            workerRoot: $workerRoot,
            runtimeDir: self::directory($env['WORKER_RUNTIME_DIR'] ?? null) ?? \dirname(__DIR__),
            handler: $handler,
            autoloadFile: self::autoloadFile($env, $workerRoot),
            bootstrapFile: self::bootstrapFile($env, $workerRoot),
            kernelClass: self::value($env, 'WORKER_KERNEL_CLASS') ?? 'App\\Kernel',
            appEnv: self::value($env, 'APP_ENV') ?? 'prod',
            appDebug: self::bool($env, 'APP_DEBUG', false),
            loadDotenv: self::bool($env, 'WORKER_DOTENV', true),
            jobId: self::value($env, 'WORKER_JOB_ID') ?? self::generateJobId(),
            timeout: max(0, (int) ($env['WORKER_TIMEOUT'] ?? 0)),
            shutdownTimeout: max(0, (int) ($env['WORKER_SHUTDOWN_TIMEOUT'] ?? 30)),
            // A stop that finds the worker mid-task is a fault for a one-shot
            // job, but the whole point of a consumer - so consume mode
            // defaults to true. Any mode can be told otherwise: a `console`
            // command that is really a long-running queue:work needs this
            // set explicitly, and it is exactly what makes that legitimate
            // rather than something the runtime infers from the mode alone.
            longRunning: self::bool($env, 'WORKER_LONG_RUNNING', Mode::Consume === $mode),
            logLevel: LogLevel::tryFrom(strtolower($env['WORKER_LOG_LEVEL'] ?? 'info')) ?? LogLevel::Info,
            logFormat: 'json' === strtolower($env['WORKER_LOG_FORMAT'] ?? 'text') ? 'json' : 'text',
            limits: Limits::fromEnvironment($env),
            messageClass: self::value($env, 'WORKER_MESSAGE_CLASS'),
            transport: self::value($env, 'WORKER_TRANSPORT'),
            busService: self::value($env, 'WORKER_BUS'),
            serializerService: self::value($env, 'WORKER_SERIALIZER'),
            jobReporterClass: self::value($env, 'WORKER_JOB_REPORTER'),
            heartbeatInterval: max(0, (int) ($env['WORKER_HEARTBEAT_INTERVAL'] ?? 30)),
        );
    }

    /**
     * Copy with a different argument list.
     *
     * Used when one invoker delegates to another - consume mode builds a
     * `messenger:consume` command line and hands it to the console invoker.
     *
     * @param list<string> $arguments
     */
    public function withArguments(array $arguments): self
    {
        // readonly properties cannot be reassigned, so rebuild instead.
        return new self(
            mode: $this->mode,
            arguments: $arguments,
            workerRoot: $this->workerRoot,
            runtimeDir: $this->runtimeDir,
            handler: $this->handler,
            autoloadFile: $this->autoloadFile,
            bootstrapFile: $this->bootstrapFile,
            kernelClass: $this->kernelClass,
            appEnv: $this->appEnv,
            appDebug: $this->appDebug,
            loadDotenv: $this->loadDotenv,
            jobId: $this->jobId,
            timeout: $this->timeout,
            shutdownTimeout: $this->shutdownTimeout,
            longRunning: $this->longRunning,
            logLevel: $this->logLevel,
            logFormat: $this->logFormat,
            limits: $this->limits,
            messageClass: $this->messageClass,
            transport: $this->transport,
            busService: $this->busService,
            serializerService: $this->serializerService,
            jobReporterClass: $this->jobReporterClass,
            heartbeatInterval: $this->heartbeatInterval,
        );
    }

    /**
     * Absolute deadline for this run, or null when unbounded.
     */
    public function deadline(float $startedAt): ?float
    {
        return $this->timeout > 0 ? $startedAt + $this->timeout : null;
    }

    /**
     * Transports to consume from, for `consume` mode.
     *
     * @return list<string>
     */
    public function transports(): array
    {
        return array_values(array_filter(
            $this->arguments,
            static fn (string $argument): bool => !str_starts_with($argument, '-'),
        ));
    }

    /**
     * Extra `messenger:consume` options passed straight through from the
     * command line, so anything the runtime does not model explicitly (say
     * `--queues`) still reaches the command.
     *
     * @return list<string>
     */
    public function consumeOptions(): array
    {
        return array_values(array_filter(
            $this->arguments,
            static fn (string $argument): bool => str_starts_with($argument, '-'),
        ));
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private static function resolveMode(array &$arguments, array $env): Mode
    {
        $first = $arguments[0] ?? null;

        if (null !== $first && null !== $mode = Mode::tryFromName($first)) {
            array_shift($arguments);

            return $mode;
        }

        if (null !== $configured = self::value($env, 'WORKER_MODE')) {
            return Mode::fromName($configured);
        }

        if (null === $first && null === self::value($env, 'WORKER_HANDLER')) {
            throw new ConfigurationException(
                'No worker specified. Pass a mode (handler, console, consume, message) as the first '
                . 'argument, or set WORKER_MODE / WORKER_HANDLER.',
            );
        }

        // Legacy form: `CMD ["App\\Worker"]`.
        return Mode::Handler;
    }

    /**
     * @param array<string, string> $env
     *
     * @return list<string>
     */
    private static function argumentsFromEnvironment(Mode $mode, array $env): array
    {
        $raw = match ($mode) {
            Mode::Console => self::value($env, 'WORKER_COMMAND'),
            Mode::Consume => self::value($env, 'WORKER_TRANSPORTS'),
            Mode::Message => self::value($env, 'WORKER_TRANSPORT'),
            Mode::Handler => null,
        };

        if (null === $raw) {
            return [];
        }

        // Transports are a comma-separated list; a console command is a
        // command line and has to keep its quoting.
        if (Mode::Console === $mode) {
            return self::tokenize($raw);
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $v): bool => '' !== $v));
    }

    /**
     * Split a command line into tokens the way a shell would.
     *
     * Quotes group but do not separate, so `--name='Acme Ltd'` stays a single
     * argument and arrives at the console command intact.
     *
     * @return list<string>
     */
    private static function tokenize(string $commandLine): array
    {
        $tokens = [];
        $current = null;
        $quote = null;
        $length = \strlen($commandLine);

        for ($i = 0; $i < $length; ++$i) {
            $char = $commandLine[$i];

            if (null !== $quote) {
                if ($char === $quote) {
                    $quote = null;
                } elseif ('\\' === $char && '"' === $quote && $i + 1 < $length) {
                    $current .= $commandLine[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ('"' === $char || "'" === $char) {
                $quote = $char;
                // An empty quoted string is still an argument.
                $current ??= '';

                continue;
            }

            if (preg_match('/\s/', $char)) {
                if (null !== $current) {
                    $tokens[] = $current;
                    $current = null;
                }

                continue;
            }

            $current .= $char;
        }

        if (null !== $quote) {
            throw new ConfigurationException(sprintf('Unbalanced %s quote in command line "%s".', $quote, $commandLine));
        }

        if (null !== $current) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * @param array<string, string> $env
     */
    private static function autoloadFile(array $env, string $workerRoot): ?string
    {
        $configured = self::value($env, 'WORKER_AUTOLOAD');

        if (null !== $configured) {
            if (!is_file($configured)) {
                throw new ConfigurationException(sprintf('WORKER_AUTOLOAD "%s" does not exist.', $configured));
            }

            return $configured;
        }

        $candidate = $workerRoot . '/vendor/autoload.php';

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * @param array<string, string> $env
     */
    private static function bootstrapFile(array $env, string $workerRoot): ?string
    {
        $configured = self::value($env, 'WORKER_BOOTSTRAP');

        if (null !== $configured) {
            if (!is_file($configured)) {
                throw new ConfigurationException(sprintf('WORKER_BOOTSTRAP "%s" does not exist.', $configured));
            }

            return $configured;
        }

        $candidate = $workerRoot . '/worker.php';

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * @param array<string, string> $env
     */
    private static function value(array $env, string $key): ?string
    {
        $value = $env[$key] ?? null;

        return null !== $value && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<string, string> $env
     */
    private static function bool(array $env, string $key, bool $default): bool
    {
        $value = self::value($env, $key);

        return null === $value ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function directory(?string $path): ?string
    {
        return null !== $path && '' !== $path && is_dir($path) ? rtrim($path, '/') : null;
    }

    private static function generateJobId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
