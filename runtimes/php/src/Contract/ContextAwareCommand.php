<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Contract;

use WorkerFramework\Runtime\Context;

/**
 * Implemented by a Console command that wants the runtime's Context: the
 * payload, the logger, and - the reason this exists - `checkpoint()` /
 * `isStopping()`, so a long-running console command can cooperate with a stop
 * request the same way a handler-mode worker already does, instead of
 * reimplementing that state itself via SignalableCommandInterface.
 *
 * The runtime calls `setWorkerContext()` once, before the command runs. Use
 * SignalableCommandInterface as normal for signal handling; Context is for
 * the state the runtime already tracks - `checkpoint()`, `onShutdown()`,
 * `remainingTime()` - so the command does not have to track it a second time.
 */
interface ContextAwareCommand
{
    public function setWorkerContext(Context $context): void;
}
