<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Contract;

use Throwable;
use WorkerFramework\Runtime\Context;

/**
 * Reports a job's lifecycle to wherever its status actually lives - a
 * DynamoDB item, an internal API, whatever "the queue" means for your app.
 *
 * The runtime calls exactly one of `succeeded()`, `failed()` or `stopped()`
 * per run, always preceded by `starting()` and, in a long loop, interleaved
 * with `heartbeat()`. Register one with `WORKER_JOB_REPORTER=App\Reporting\...`;
 * it is resolved from the service container when the application provides
 * one, so it can take a DynamoDB client, an HTTP client, whatever it needs
 * as constructor arguments.
 *
 * What this cannot do: report a job that was SIGKILLed outright - by ECS
 * after its own stop timeout, by the kernel's OOM killer, by the host
 * disappearing. No process-level hook survives that, by construction. That
 * case has to be caught from outside: don't delete the queue message until
 * you've recorded success (a transport's own visibility timeout then retries
 * it for you), or, if you track status yourself, treat a job whose
 * `heartbeat()` has gone stale with no terminal status as failed. The most
 * this interface can do for that second option is give you a heartbeat to
 * write.
 */
interface JobReporter
{
    /**
     * The runtime is about to hand this job to your code.
     */
    public function starting(Context $context): void;

    /**
     * Called on every `Context::checkpoint()`, rate-limited by
     * WORKER_HEARTBEAT_INTERVAL (default 30s) so a tight loop does not hammer
     * whatever this writes to. This is the one call an external reconciler
     * can use to tell "idle" from "wedged".
     */
    public function heartbeat(Context $context): void;

    /**
     * A stop has been requested and the job is winding down. Not a terminal
     * state: one of succeeded/failed/stopped still follows, and may be a long
     * way off - a worker draining a large batch can take the whole of
     * WORKER_SHUTDOWN_TIMEOUT to get there.
     *
     * This exists so the window in between is not silent. Without it, a
     * scheduler watching the job sees "claimed" right up until the worker
     * finally returns, with nothing to say a shutdown was already in flight.
     *
     * $reason is Signal or Timeout - never Forced, which is by definition
     * something that only happens after this call, when the worker fails to
     * wind down in time.
     *
     * Runs inside the signal handler, so keep it short and expect it to
     * interrupt whatever the worker was doing.
     */
    public function stopping(Context $context, StopReason $reason): void;

    /**
     * The job ran to completion.
     */
    public function succeeded(Context $context): void;

    /**
     * The job threw, or reported failure through its own return value.
     * $error is null when the worker returned a non-zero/false result
     * without throwing.
     */
    public function failed(Context $context, int $exitCode, ?Throwable $error): void;

    /**
     * Terminal counterpart to `stopping()`: the job is over and did not
     * finish its work, because it was asked to stop - or, for
     * StopReason::Forced, because it did not stop when asked and the runtime
     * ended it.
     */
    public function stopped(Context $context, StopReason $reason): void;
}
