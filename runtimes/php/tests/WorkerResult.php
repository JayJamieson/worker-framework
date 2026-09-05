<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests;

/**
 * The observable outcome of a worker run.
 */
final class WorkerResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $log,
    ) {
    }
}
