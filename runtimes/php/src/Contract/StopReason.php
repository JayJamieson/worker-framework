<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Contract;

/**
 * Why a worker stopped without completing its work.
 */
enum StopReason: string
{
    /** SIGTERM/SIGINT/SIGHUP/SIGQUIT, and the worker complied in time. */
    case Signal = 'signal';

    /** WORKER_TIMEOUT expired, and the worker complied in time. */
    case Timeout = 'timeout';

    /**
     * The worker did not comply within WORKER_SHUTDOWN_TIMEOUT, and the
     * runtime force-exited it. This is the "running away / stuck" case - it
     * only fires when the runtime's own grace period expires *before*
     * whatever external supervisor (ECS, Kubernetes) sends SIGKILL. Set
     * WORKER_SHUTDOWN_TIMEOUT comfortably below your task's stop timeout so
     * this has a chance to run at all.
     */
    case Forced = 'forced';
}
