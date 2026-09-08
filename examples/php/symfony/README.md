# Symfony worker example

One image, four workers. Which one a container becomes is decided by its
command, not by a separate build or a separate Dockerfile.

| Command | What runs | Deployed as |
| --- | --- | --- |
| `consume async` | `messenger:consume async` with the app's retry strategy and failure transport | long-running Deployment |
| `console app:import-ledger 2024-Q4` | the existing Symfony command, unchanged | one-off Job |
| `handler 'App\Worker\RebuildSearchIndex'` | a class from the container, payload fields as arguments | one-off Job |
| `message` | one message dispatched onto the bus, then exit | per-message Job |

Nothing in `src/` knows it is running in a worker. `SendReportHandler` is a
plain `#[AsMessageHandler]`, `ImportLedgerCommand` is a plain
`Symfony\Component\Console\Command`, and both behave identically under
`bin/console`.

## The two things the application has to declare

Symfony makes services private by default, and the runtime fetches two kinds of
thing out of the container. `config/services.yaml` opts them in:

```yaml
# handler mode fetches workers by class name
App\Worker\:
    resource: '../src/Worker/'
    public: true

# message mode dispatches onto the bus itself
message_bus:
    alias: messenger.default_bus
    public: true
```

Neither is needed for `console` or `consume` mode, which go through the
Console Application and touch no private services.

## Reporting job status independently of the exit code

`rebuild`'s `WORKER_JOB_REPORTER=App\Reporting\DynamoJobReporter` (see
`src/Reporting/DynamoJobReporter.php`) updates a DynamoDB item as the job moves
through `claimed → succeeded|failed|stopped`, with a heartbeat in between -
for an ECS RunTask-per-job deployment where a separate table, not the queue
itself, is the actual record of what happened to a job. `WORKER_JOB_ID` should
match whatever id the caller already tracks the job by.

This is deliberately *not* how `consumer`'s per-message status would be
reported: Messenger already fires `WorkerMessageHandledEvent` /
`WorkerMessageFailedEvent` for that, at the right granularity (per message,
not per container run) - an `#[AsEventListener]` on those needs no runtime
support at all. `WORKER_JOB_REPORTER` is for the one-job-per-container modes
(`handler`, `message`) where the whole run *is* the job.

## Running it

```sh
docker build -f ../../../runtimes/php/Dockerfile -t worker-framework/php:8.3 ../../..
docker build -t reports .

docker compose up consumer
docker compose run --rm import
docker compose run --rm rebuild
```

## Graceful shutdown

`docker compose stop consumer` sends SIGTERM. The consumer finishes the message
it is holding, acknowledges it, leaves the rest on the queue and exits 0.
Anything still running after `WORKER_SHUTDOWN_TIMEOUT` (default 30s) is ended;
give the container at least that long with `stop_grace_period`.

## worker.php

`worker.php.dist` shows the escape hatch for applications the runtime cannot
guess at. Copy it to `worker.php` only if you need it - without it the runtime
boots `App\Kernel` on its own, which is what this example relies on.
