<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime;

/**
 * Exit codes shared by the shell bootstrap and the PHP runtime.
 *
 * Anything in the 0-63 range is produced by the runtime itself. Codes at 128+n
 * follow the shell convention for "terminated by signal n" so that supervisors
 * (Docker, Kubernetes, Nomad) classify the container the same way they would
 * classify any other signalled process.
 */
final class ExitCode
{
    /** The worker ran to completion. */
    public const SUCCESS = 0;

    /** The worker code threw or returned a failure. */
    public const WORKER_ERROR = 1;

    /** The runtime could not make sense of its configuration. */
    public const CONFIGURATION_ERROR = 2;

    /** The application (Kernel, console, bus) could not be booted. */
    public const BOOTSTRAP_ERROR = 3;

    /** The requested handler/command/transport does not exist. */
    public const HANDLER_NOT_FOUND = 4;

    /** The worker exceeded WORKER_TIMEOUT and did not stop in time. */
    public const TIMEOUT = 5;

    /** Terminated by SIGINT (128 + 2). */
    public const SIGINT = 130;

    /** Terminated by SIGTERM (128 + 15). */
    public const SIGTERM = 143;

    private function __construct()
    {
    }

    /**
     * Exit code a process should report after being stopped by $signal.
     */
    public static function fromSignal(int $signal): int
    {
        return 128 + $signal;
    }
}
