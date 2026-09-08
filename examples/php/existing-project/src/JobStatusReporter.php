<?php

declare(strict_types=1);

namespace Acme\Worker;

use PDO;
use Throwable;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\JobReporter;
use WorkerFramework\Runtime\Contract\StopReason;

/**
 * Keeps an existing `jobs` table in step with what the runtime is doing.
 *
 * This is the whole adapter: six methods, one UPDATE each, using the
 * application's own PDO handle. Nothing about the app changes to accommodate
 * the framework - the framework calls into the app's existing database layer.
 *
 * Assumed table (whatever your app already has, renamed to taste):
 *
 *     CREATE TABLE jobs (
 *         id            VARCHAR(64) PRIMARY KEY,
 *         status        VARCHAR(16) NOT NULL,   -- queued|running|stopping|done|failed
 *         stop_reason   VARCHAR(16) NULL,       -- signal|timeout|forced|lost; why the
 *                                               -- last attempt ended early, if it did
 *         error         TEXT NULL,
 *         attempts      INT NOT NULL DEFAULT 0,
 *         heartbeat_at  DATETIME NULL,
 *         updated_at    DATETIME NOT NULL
 *     );
 */
final class JobStatusReporter implements JobReporter
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The job is ours. Claiming it here - rather than when it was enqueued -
     * is what lets a reconciler tell "waiting in the queue" from "picked up
     * by a worker that has since vanished".
     */
    public function starting(Context $context): void
    {
        $this->update($context, [
            'status' => 'running',
            'heartbeat_at' => $this->now(),
        ], 'attempts = attempts + 1');
    }

    /**
     * Proof of life, at most once per WORKER_HEARTBEAT_INTERVAL. A row still
     * `running` with an old heartbeat is the signature of a worker that was
     * SIGKILLed - the one outcome no in-process hook can report for itself.
     */
    public function heartbeat(Context $context): void
    {
        $this->update($context, ['heartbeat_at' => $this->now()]);
    }

    /**
     * A stop was requested and the job is winding down. Not terminal: one of
     * the three below still follows, possibly a while later. Recording it
     * means a dashboard shows "draining" instead of a row that looks
     * identical to a healthy one right up until it disappears.
     */
    public function stopping(Context $context, StopReason $reason): void
    {
        $this->update($context, [
            'status' => 'stopping',
            'stop_reason' => $reason->value,
        ]);
    }

    public function succeeded(Context $context): void
    {
        $this->update($context, ['status' => 'done', 'error' => null]);
    }

    public function failed(Context $context, int $exitCode, ?Throwable $error): void
    {
        $this->update($context, [
            'status' => 'failed',
            'error' => $error?->getMessage() ?? sprintf('exit code %d', $exitCode),
        ]);
    }

    /**
     * The job did not finish, so it goes back to `queued` - not to a terminal
     * "stopped" state, which would leave the row looking finished and stop
     * the dequeue query from ever picking it up again. An interrupted job has
     * not reached an end state; it is back where it started, one attempt
     * poorer.
     *
     * `stop_reason` is what records that it was interrupted rather than
     * freshly enqueued, and `attempts` (incremented in starting()) is what
     * stops a job that is repeatedly interrupted from cycling forever - it
     * hits the application's own retry ceiling like any other failure.
     */
    public function stopped(Context $context, StopReason $reason): void
    {
        $this->update($context, [
            'status' => 'queued',
            'stop_reason' => $reason->value,
        ]);
    }

    /**
     * @param array<string, string|null> $columns
     */
    private function update(Context $context, array $columns, ?string $rawAssignment = null): void
    {
        $columns['updated_at'] = $this->now();

        $assignments = array_map(static fn (string $name): string => "{$name} = :{$name}", array_keys($columns));

        if (null !== $rawAssignment) {
            $assignments[] = $rawAssignment;
        }

        // No try/catch: the runtime wraps every reporter in GuardedJobReporter,
        // so a database blip is logged and the job carries on rather than
        // failing over a status update it could not write.
        $statement = $this->db->prepare(sprintf(
            'UPDATE jobs SET %s WHERE id = :id',
            implode(', ', $assignments),
        ));

        $statement->execute([...$columns, 'id' => $context->jobId()]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
