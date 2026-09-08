<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Reporting;

use ReflectionClass;
use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Exception\BootstrapException;
use WorkerFramework\Runtime\Log\Logger;

/**
 * Resolves WORKER_JOB_REPORTER the same way a handler is resolved: a service
 * id first, so a reporter can take a DynamoDB client or an HTTP client as a
 * constructor argument the way any other service does, falling back to `new`
 * for a reporter with no dependencies.
 */
final class JobReporterFactory
{
    public function __construct(
        private readonly ApplicationContext $application,
        private readonly Logger $logger,
    ) {
    }

    public function create(?string $class, int $heartbeatInterval): ?JobReporter
    {
        if (null === $class) {
            return null;
        }

        $reporter = $this->application->findService([$class]) ?? $this->construct($class);

        if (!$reporter instanceof JobReporter) {
            throw new BootstrapException(sprintf(
                'WORKER_JOB_REPORTER "%s" must implement %s, got %s.',
                $class,
                JobReporter::class,
                $reporter::class,
            ));
        }

        // Guard outermost: every call the runtime makes, including the
        // heartbeat that fires from inside worker code, is covered by it.
        return new GuardedJobReporter(
            new RateLimitedJobReporter($reporter, $heartbeatInterval),
            $this->logger,
        );
    }

    private function construct(string $class): object
    {
        if (!class_exists($class)) {
            throw new BootstrapException(sprintf(
                'WORKER_JOB_REPORTER "%s" does not exist. Check that it is autoloadable, or register it as a '
                . 'public service so the runtime can fetch it from the container instead.',
                $class,
            ));
        }

        $reflection = new ReflectionClass($class);
        $required = $reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0;

        if ($required > 0) {
            throw new BootstrapException(sprintf(
                'WORKER_JOB_REPORTER "%s" needs %d constructor argument(s) and is not registered as a service. '
                . 'Register it in services.yaml with `public: true` so the runtime can fetch it with its '
                . 'dependencies (a DynamoDB client, say) already wired up.',
                $class,
                $required,
            ));
        }

        try {
            return $reflection->newInstance();
        } catch (Throwable $error) {
            throw new BootstrapException(
                sprintf('WORKER_JOB_REPORTER "%s" could not be constructed: %s', $class, $error->getMessage()),
                0,
                $error,
            );
        }
    }
}
