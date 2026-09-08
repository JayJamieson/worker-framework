<?php

declare(strict_types=1);

namespace App\Reporting;

use Aws\DynamoDb\DynamoDbClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;

/**
 * Updates a job's status in DynamoDB as the runtime moves it through its
 * lifecycle - the "separate status store" pattern: SQS carries the message,
 * this table is the actual record of what happened to it. Register it with
 * `WORKER_JOB_REPORTER=App\Reporting\DynamoJobReporter`.
 *
 * Table shape (partition key `job_id`):
 *
 *     job_id          S   Context::jobId() - set WORKER_JOB_ID to your own
 *                         job's id when you enqueue it, so this updates the
 *                         same row your application already created
 *     status          S   claimed | stopping | succeeded | failed | stopped
 *     reason          S   signal | timeout | forced - set when stopping/stopped
 *     error           S   the exception message - only set when failed
 *     heartbeat_at    N   epoch seconds, refreshed on every checkpoint()
 *     updated_at      N   epoch seconds
 *
 * What this cannot do: report a job ECS SIGKILLed outright - see
 * runtimes/php/src/Contract/JobReporter.php for why no in-process hook can.
 * The backstop is `heartbeat_at`: a reconciler (a scheduled Lambda, say)
 * treats a row that is still `claimed` with a stale heartbeat as failed and
 * requeues it. Keep WORKER_SHUTDOWN_TIMEOUT comfortably below this task's ECS
 * stop timeout, so a wedged worker reports `stopped/forced` itself before ECS
 * has to resort to SIGKILL.
 */
final class DynamoJobReporter implements JobReporter
{
    public function __construct(
        private readonly DynamoDbClient $dynamoDb,
        #[Autowire(env: 'JOBS_TABLE_NAME')]
        private readonly string $tableName,
    ) {
    }

    public function starting(Context $context): void
    {
        $this->write($context, '#status = :status', [
            '#status' => 'status',
        ], [
            ':status' => ['S' => 'claimed'],
        ]);
    }

    public function heartbeat(Context $context): void
    {
        // starting() already set the status; a heartbeat only needs to prove
        // the process is still alive and making progress.
        $this->touch($context);
    }

    public function stopping(Context $context, StopReason $reason): void
    {
        // Not terminal - the job is winding down and one of the calls below
        // still follows. Recording it means a scheduler watching this row can
        // tell "shutting down, expect it back on the queue shortly" from
        // "claimed and running normally", instead of seeing no change until
        // the worker finally returns.
        $this->write($context, '#status = :status, #reason = :reason', [
            '#status' => 'status',
            '#reason' => 'reason',
        ], [
            ':status' => ['S' => 'stopping'],
            ':reason' => ['S' => $reason->value],
        ]);
    }

    public function succeeded(Context $context): void
    {
        $this->write($context, '#status = :status', [
            '#status' => 'status',
        ], [
            ':status' => ['S' => 'succeeded'],
        ]);
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        $this->write($context, '#status = :status, #error = :error', [
            '#status' => 'status',
            '#error' => 'error',
        ], [
            ':status' => ['S' => 'failed'],
            ':error' => ['S' => $error?->getMessage() ?? sprintf('exit code %d', $exitCode)],
        ]);
    }

    public function stopped(Context $context, StopReason $reason): void
    {
        $this->write($context, '#status = :status, #reason = :reason', [
            '#status' => 'status',
            '#reason' => 'reason',
        ], [
            ':status' => ['S' => 'stopped'],
            ':reason' => ['S' => $reason->value],
        ]);
    }

    private function touch(Context $context): void
    {
        $this->write($context, '#heartbeat = :heartbeat', [
            '#heartbeat' => 'heartbeat_at',
        ], [
            ':heartbeat' => ['N' => (string) time()],
        ]);
    }

    /**
     * @param array<string, string>               $names  placeholder => real attribute name
     * @param array<string, array<string, string>> $values placeholder => DynamoDB typed value
     */
    private function write(Context $context, string $expression, array $names, array $values): void
    {
        // No try/catch needed: the runtime wraps every reporter in
        // GuardedJobReporter, so a DynamoDB outage is logged and the job
        // carries on rather than failing over a status update.
        $this->dynamoDb->updateItem([
            'TableName' => $this->tableName,
            'Key' => ['job_id' => ['S' => $context->jobId()]],
            'UpdateExpression' => 'SET ' . $expression . ', #updated = :updated',
            'ExpressionAttributeNames' => [...$names, '#updated' => 'updated_at'],
            'ExpressionAttributeValues' => [...$values, ':updated' => ['N' => (string) time()]],
        ]);
    }
}
