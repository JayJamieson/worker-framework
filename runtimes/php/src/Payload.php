<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

use WorkerFramework\Runtime\Exception\ConfigurationException;

/**
 * The input a worker was started with.
 *
 * A payload can arrive three ways, checked in this order: `WORKER_PAYLOAD`,
 * the file named by `WORKER_PAYLOAD_FILE`, or STDIN when it is a pipe or a
 * file. The last one is what makes `cat job.json | docker run -i worker`
 * behave the way people expect, while never blocking a container started
 * without stdin attached.
 *
 * JSON objects are decoded into an array; anything else is kept verbatim and
 * reachable through `raw()`.
 */
final class Payload
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly string $raw,
        private readonly array $data,
    ) {
    }

    public static function empty(): self
    {
        return new self('', []);
    }

    public static function fromString(string $raw): self
    {
        $trimmed = trim($raw);

        if ('' === $trimmed) {
            return self::empty();
        }

        $decoded = json_decode($trimmed, true);

        return new self($raw, \is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_SLASHES) ?: '', $data);
    }

    /**
     * Resolve the payload from the environment, in precedence order.
     *
     * @param array<string, string> $env
     * @param resource|null         $stdin defaults to the process's STDIN
     */
    public static function discover(array $env, $stdin = null): self
    {
        if (isset($env['WORKER_PAYLOAD']) && '' !== $env['WORKER_PAYLOAD']) {
            return self::fromString($env['WORKER_PAYLOAD']);
        }

        $file = $env['WORKER_PAYLOAD_FILE'] ?? null;

        if (null !== $file && '' !== $file) {
            if (!is_readable($file)) {
                throw new ConfigurationException(sprintf('WORKER_PAYLOAD_FILE "%s" does not exist or is not readable.', $file));
            }

            return self::fromString((string) file_get_contents($file));
        }

        return self::fromStdin($env['WORKER_PAYLOAD_STDIN'] ?? null, $stdin ?? (\defined('STDIN') ? STDIN : null));
    }

    /**
     * @param array<string, mixed>|null $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->raw);
    }

    /**
     * Read STDIN only when doing so cannot hang.
     *
     * `$mode` mirrors WORKER_PAYLOAD_STDIN: "1"/"true" forces the read, "0"/
     * "false" disables it, and unset auto-detects - a terminal or a character
     * device (the /dev/null a plain `docker run` supplies) is left alone, a
     * pipe or redirected file is read to EOF.
     *
     * @param resource|null $stream
     */
    private static function fromStdin(?string $mode, $stream): self
    {
        if (!\is_resource($stream)) {
            return self::empty();
        }

        // Unset means auto-detect. filter_var() reports an unset value as
        // false, which would be indistinguishable from an explicit "no".
        $forced = null !== $mode && '' !== $mode
            ? filter_var($mode, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        if (false === $forced) {
            return self::empty();
        }

        if (true !== $forced && !self::isReadable($stream)) {
            return self::empty();
        }

        return self::fromString((string) stream_get_contents($stream));
    }

    /**
     * @param resource $stream
     */
    private static function isReadable($stream): bool
    {
        if (\function_exists('posix_isatty') && @posix_isatty($stream)) {
            return false;
        }

        $stat = @fstat($stream);

        if (false === $stat) {
            return false;
        }

        // S_IFMT mask: accept FIFOs (pipes) and regular files only. Character
        // devices such as /dev/null are excluded so the common
        // `docker run image` case never waits on a read.
        $type = $stat['mode'] & 0170000;

        return 0010000 === $type || 0100000 === $type;
    }
}
