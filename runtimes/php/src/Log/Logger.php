<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Log;

use Stringable;
use Throwable;

/**
 * Runtime logger.
 *
 * Runtime records go to STDERR so that STDOUT stays exactly what the worker
 * itself printed - that separation is what lets a job's output be piped
 * somewhere useful without runtime chatter mixed in.
 *
 * Two formats are supported: `text` for humans reading `docker logs`, and
 * `json` for log shippers. Both carry the job id so a container's records can
 * be correlated with whatever dispatched it.
 */
final class Logger
{
    /** @var resource */
    private $stream;

    /**
     * @param array<string, scalar|null> $context fields added to every record
     * @param resource|null              $stream
     */
    public function __construct(
        private LogLevel $minimumLevel = LogLevel::Info,
        private string $format = 'text',
        private array $context = [],
        $stream = null,
    ) {
        $this->stream = $stream ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'wb'));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->minimumLevel,
            $this->format,
            [...$this->context, ...$context],
            $this->stream,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    /**
     * Log a throwable with its class, message, origin and stack trace.
     *
     * @param array<string, mixed> $context
     */
    public function exception(Throwable $error, string $message = 'Unhandled exception', array $context = []): void
    {
        $this->log(LogLevel::Error, $message, [
            ...$context,
            'exception' => $error::class,
            'error' => $error->getMessage(),
            'origin' => sprintf('%s:%d', $error->getFile(), $error->getLine()),
            'trace' => $error->getTraceAsString(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string|Stringable $message, array $context = []): void
    {
        if (!$this->minimumLevel->includes($level)) {
            return;
        }

        $record = [
            ...$this->context,
            ...array_map($this->stringify(...), $context),
        ];

        fwrite($this->stream, 'json' === $this->format
            ? $this->formatJson($level, (string) $message, $record)
            : $this->formatText($level, (string) $message, $record));
    }

    /**
     * @param array<string, string|null> $record
     */
    private function formatJson(LogLevel $level, string $message, array $record): string
    {
        $encoded = json_encode([
            'timestamp' => gmdate('c'),
            'level' => $level->value,
            'message' => $message,
            ...$record,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        // json_encode can still fail on recursive structures; never let logging
        // be the thing that kills a worker.
        return ($encoded ?: sprintf('{"level":"%s","message":"%s"}', $level->value, addslashes($message))) . "\n";
    }

    /**
     * @param array<string, string|null> $record
     */
    private function formatText(LogLevel $level, string $message, array $record): string
    {
        $line = sprintf('[%s] [%s] %s', gmdate('H:i:s'), strtoupper($level->value), $message);

        // The stack trace is multi-line by nature; keep it out of the key=value
        // tail and print it underneath instead.
        $trace = $record['trace'] ?? null;
        unset($record['trace']);

        foreach ($record as $key => $value) {
            if (null !== $value) {
                $line .= sprintf(' %s=%s', $key, $this->quote($value));
            }
        }

        return $line . "\n" . (null !== $trace ? $trace . "\n" : '');
    }

    private function quote(string $value): string
    {
        return preg_match('/[\s"]/', $value) ? '"' . str_replace('"', '\\"', $value) . '"' : $value;
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null,
        };
    }
}
