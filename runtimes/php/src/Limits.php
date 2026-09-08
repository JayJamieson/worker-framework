<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

/**
 * Stop conditions for a long-running consumer.
 *
 * These mirror the options of `messenger:consume` because they solve the same
 * problem: a PHP process that handles thousands of messages will eventually
 * leak, so it is cheaper to recycle the container on a bound than to chase the
 * leak. Every value of 0 (or null for memory) means "no bound".
 */
final class Limits
{
    public function __construct(
        /** Stop after this many messages. */
        public readonly int $messages = 0,
        /** Stop after this many seconds. */
        public readonly int $time = 0,
        /** Stop once the process is using more than this much memory, e.g. "128M". */
        public readonly ?string $memory = null,
        /** Stop after this many consecutive failures. */
        public readonly int $failures = 0,
        /** Microseconds to wait when a transport has nothing to deliver. */
        public readonly int $sleep = 1_000_000,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(array $env): self
    {
        return new self(
            messages: (int) ($env['WORKER_MESSAGE_LIMIT'] ?? 0),
            time: (int) ($env['WORKER_TIME_LIMIT'] ?? 0),
            memory: ($env['WORKER_MEMORY_LIMIT'] ?? '') ?: null,
            failures: (int) ($env['WORKER_FAILURE_LIMIT'] ?? 0),
            sleep: (int) ($env['WORKER_SLEEP'] ?? 1_000_000),
        );
    }

    /**
     * Render as `messenger:consume` options, omitting anything unbounded.
     *
     * @return list<string>
     */
    public function toConsoleOptions(): array
    {
        $options = [];

        if ($this->messages > 0) {
            $options[] = '--limit=' . $this->messages;
        }

        if ($this->time > 0) {
            $options[] = '--time-limit=' . $this->time;
        }

        if (null !== $this->memory) {
            $options[] = '--memory-limit=' . $this->memory;
        }

        if ($this->failures > 0) {
            $options[] = '--failure-limit=' . $this->failures;
        }

        $options[] = '--sleep=' . ($this->sleep / 1_000_000);

        return $options;
    }
}
