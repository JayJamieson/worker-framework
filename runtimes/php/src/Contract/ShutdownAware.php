<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Contract;

use WorkerFramework\Runtime\Context;

/**
 * Implemented by workers that need to release resources when the container is
 * being stopped.
 *
 * `onShutdown` runs from inside the signal handler, before the worker's own
 * method has returned, so it should do as little as possible: flush a buffer,
 * mark a row, close a file. Anything slower belongs after the main loop, gated
 * on `Context::isStopping()`.
 */
interface ShutdownAware
{
    public function onShutdown(Context $context, int $signal): void;
}
