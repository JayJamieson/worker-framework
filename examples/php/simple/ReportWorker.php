<?php

declare(strict_types=1);

use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\ShutdownAware;
use WorkerFramework\Runtime\Contract\Worker;

/**
 * A plain PHP worker - no framework, no Composer dependencies.
 *
 *   docker run --rm -e WORKER_PAYLOAD='{"companyId":42}' reports
 *
 * The file returns the worker at the bottom, which is what lets the runtime
 * load it by path without an autoloader.
 */
final class ReportWorker implements Worker, ShutdownAware
{
    private int $page = 0;

    public function handle(Context $context): ?int
    {
        $companyId = $context->payload()->get('companyId', 'unknown');

        $context->logger()->info('Generating reports', ['company' => $companyId]);

        for ($this->page = 1; $this->page <= 100; ++$this->page) {
            // Without this check the container could only ever be killed.
            if ($context->checkpoint()) {
                $context->logger()->notice('Stopped early', ['page' => $this->page]);

                return 0;
            }

            printf("page %d of company %s\n", $this->page, $companyId);
            usleep(100_000);
        }

        return 0;
    }

    /**
     * Runs the moment a stop is requested, before `handle()` has returned.
     * Keep it short: it executes inside the signal handler.
     */
    public function onShutdown(Context $context, int $signal): void
    {
        $context->logger()->notice('Recording progress before exit', ['page' => $this->page]);
    }
}

return new ReportWorker();
