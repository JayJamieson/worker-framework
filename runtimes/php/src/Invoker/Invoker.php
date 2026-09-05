<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use WorkerFramework\Runtime\Context;

/**
 * One way of running a worker: a handler call, a console command, a Messenger
 * consumer, or a single Messenger message.
 *
 * Implementations return the exit code the container should report and are
 * expected to stop when `Context::isStopping()` turns true.
 */
interface Invoker
{
    public function invoke(Context $context): int;

    /**
     * Human-readable description of what is about to run, for the startup log.
     */
    public function describe(): string;
}
