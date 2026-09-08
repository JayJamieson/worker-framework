# Adapting an existing PHP project

No Symfony, no DI package, no rewrite of the application. Four files:

| File | What it is |
| --- | --- |
| `worker.php` | the whole integration — boots the app, hands the runtime a container |
| `src/Container.php` | a thirty-line container, because the runtime duck-types `has()`/`get()` |
| `src/JobStatusReporter.php` | the adapter: six methods, one `UPDATE` each, on the app's own `jobs` table |
| `src/SendInvoicesJob.php` | an ordinary job class, using the app's own services |

## The one line the application adds

```php
if ($context->checkpoint()) {
    return 0;   // work so far is committed; the rest is still queued
}
```

Everything else — claiming the row, heartbeating, recording the outcome — happens
around the job, not inside it.

## Wiring

```dockerfile
FROM worker-framework/php:8.3

COPY . /var/task

ENV WORKER_JOB_REPORTER='Acme\Worker\JobStatusReporter'

CMD ["handler", "Acme\\Worker\\SendInvoicesJob::perform"]
```

```sh
docker run --rm \
  -e WORKER_JOB_ID=job-7 \
  -e WORKER_PAYLOAD='{"companyId": 42}' \
  invoices
```

`WORKER_JOB_ID` is the id of the row in your own `jobs` table — set it to whatever
the enqueuing code already generated, and the reporter updates that row rather
than inventing a parallel one.

## Why `worker.php` needs a container

The runtime resolves both the handler and the reporter **by class name**. Given a
container it fetches them, dependencies and all; without one it falls back to
`new`, which fails for anything with constructor arguments — and both
`SendInvoicesJob(InvoiceRepository)` and `JobStatusReporter(PDO)` have them.

The runtime has no dependency on psr/container or Symfony DI. It checks for
`has()` and `get()` and nothing else, so `src/Container.php` is a complete,
sufficient implementation. If the application already has a service locator or
registry, return that instead and delete the file.

## What the `jobs` table sees

For a job that runs to completion:

| | `status` | `attempts` | `heartbeat_at` |
| --- | --- | --- | --- |
| before | `queued` | 0 | — |
| `starting()` | `running` | 1 | set |
| `heartbeat()` | `running` | 1 | refreshed |
| `succeeded()` | `done` | 1 | — |

For a job interrupted by a deploy or a scale-in:

| | `status` | `stop_reason` | `attempts` |
| --- | --- | --- | --- |
| `stopping()` | `stopping` | `signal` | 1 |
| `stopped()` | `queued` | `signal` | 1 |

`stopping()` fires the moment SIGTERM arrives; `stopped()` only once the job has
actually wound down. Between them the job can legitimately keep working for up to
`WORKER_SHUTDOWN_TIMEOUT` — that gap is why both calls exist.

### Why `stopped()` writes `queued` and not `stopped`

An interrupted job hasn't reached an end state — it's back where it started,
one attempt poorer. A terminal-looking `stopped` would leave the row stranded:
a dequeue query looking for `status = 'queued'` would never pick it up again.
`stop_reason` is what distinguishes "interrupted, try again" from "freshly
enqueued", and `attempts` is what stops a repeatedly-interrupted job from
cycling forever — it hits the same retry ceiling as any other failure.

This is a consequence of the table being the queue. Where the queue is
somewhere else — SQS, say, with this table as a status record alongside it —
a purely descriptive `stopped` is the right value, because redelivery is the
transport's job and nothing dequeues off this column. That's what
[`examples/php/symfony`](../symfony) does.

If immediate re-claim isn't what you want (a job that keeps getting caught by
deploys, say), this is where a `run_at`/`available_at` column earns its place:
set it a few minutes out and let the dequeue query respect it.

## Failures the adapter cannot see

A `SIGKILL` — ECS past its stop timeout, the OOM killer, the host vanishing —
runs no PHP at all, so the row stays `running`. That is what `heartbeat_at` is
for: anything still `running` with a heartbeat older than a few minutes was
killed, and can be released by a reconciler:

```sql
UPDATE jobs
   SET status = 'queued', stop_reason = 'lost'
 WHERE status IN ('running', 'stopping')
   AND heartbeat_at < DATETIME('now', '-5 minutes')
   AND attempts < 5;
```

Keep `WORKER_SHUTDOWN_TIMEOUT` below your scheduler's own stop timeout so the
runtime gets to report `stopped(forced)` itself before anything resorts to
`SIGKILL`.

## A note on error handling in the adapter

There is no `try`/`catch` anywhere in `JobStatusReporter`. The runtime wraps every
reporter in `GuardedJobReporter`: a database blip is logged and the job carries
on, because a job that did its work succeeded whether or not the record of that
landed. Writing defensive boilerplate in each method would only hide errors the
runtime already reports.
