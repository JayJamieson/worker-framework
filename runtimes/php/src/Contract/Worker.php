<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Contract;

use WorkerFramework\Runtime\Context;

/**
 * Optional interface for handler-mode workers.
 *
 * Implementing it is never required - the runtime will happily call
 * `__invoke`, `handle`, `run`, `perform`, `main` or `execute` on any class -
 * but it documents the contract and lets static analysis check the signature.
 *
 * Return an exit code, or nothing at all to report success.
 */
interface Worker
{
    public function handle(Context $context): ?int;
}
