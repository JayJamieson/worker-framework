<?php

declare(strict_types=1);

namespace App\Worker;

use Psr\Log\LoggerInterface;
use WorkerFramework\Runtime\Context;

/**
 * A worker in the framework's own style: no base class, constructor injection
 * from the Symfony container, payload fields as typed arguments.
 *
 *   docker run --rm \
 *     -e WORKER_PAYLOAD='{"companyId": 42, "since": "2024-01-01"}' \
 *     reports handler 'App\Worker\RebuildSearchIndex'
 *
 * The class must be a public service for the container to hand it over; see
 * the `App\Worker\` block in config/services.yaml.
 */
final class RebuildSearchIndex
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(int $companyId, string $since, Context $context): int
    {
        $this->logger->info('Rebuilding index', ['company' => $companyId, 'since' => $since]);

        foreach ($this->documents($companyId, $since) as $index => $document) {
            if ($context->checkpoint()) {
                $this->logger->notice('Stopped early', ['indexed' => $index]);

                // Non-zero would tell the scheduler to retry; the work so far
                // is durable, so report success and let the next run continue.
                return 0;
            }

            // ... index the document ...
        }

        return 0;
    }

    /**
     * @return iterable<int, object>
     */
    private function documents(int $companyId, string $since): iterable
    {
        return [];
    }
}
