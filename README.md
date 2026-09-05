# Worker framework

Worker framework takes heavy inspiration from AWS Lambda without the maximum 15
minute run duration. It gives you a `handler`-shaped entry point and a container
that can run for as long as the job needs.

The PHP runtime goes further than that. Because most PHP jobs already exist —
as a Symfony Console command, or as a Messenger handler — it can run those
directly, with no rewrite:

```dockerfile
CMD ["handler", "App\\Worker\\RebuildSearchIndex"]   # a class, called once
CMD ["console", "app:import-ledger", "2024-Q4"]      # an existing console command
CMD ["consume", "async"]                             # a Messenger consumer
CMD ["message"]                                      # one message, then exit
```

Same image, same runtime, same signal handling and exit codes. The mode is the
first argument of the container's command.

## How it works

`entrypoint.sh` execs `bootstrap`, so the bootstrap becomes PID 1 and receives
the signals Docker sends on `docker stop`. The bootstrap runs the language
runtime as a child process, forwards signals to it, and waits — which is what
lets a worker finish the unit of work in flight instead of being killed
mid-write.

The language runtime (`runtimes/php/runtime.php`, `runtimes/node/runtime.mjs`)
loads the worker, builds a context, calls it, and turns the result into an exit
code.

```
docker stop
    │
    ├─► entrypoint.sh ─exec─► bootstrap (PID 1)
    │                            │  traps TERM/INT/HUP, runs hooks
    │                            ├─► php /var/runtime/runtime.php consume async
    │                            │      │  boots the app, installs signal handlers
    │                            │      └─► your handler / command / consumer
    │                            │
    │                            └─ waits for the child, then exits with its status
```

## Quick start

```sh
# Build a base image (from the repository root, so the shared files are in context)
docker build -f runtimes/php/Dockerfile -t worker-framework/php:8.3 .
```

```dockerfile
# Your application's Dockerfile
FROM worker-framework/php:8.3

COPY . /var/task
RUN composer install --no-dev --optimize-autoloader

CMD ["consume", "async"]
```

```sh
docker run --rm -e MESSENGER_TRANSPORT_DSN=amqp://... reports

# or override the command to get a different worker from the same image
docker run --rm reports console app:import-ledger 2024-Q4
docker run --rm -e WORKER_PAYLOAD='{"companyId":42}' reports handler 'App\Worker\RebuildSearchIndex'
```

Working examples: [`examples/php/symfony`](examples/php/symfony) (all four
modes against one Symfony app), [`examples/php/simple`](examples/php/simple)
(one file, no Composer), [`examples/node`](examples/node).

## PHP modes

### `handler` — a class, called once

```dockerfile
CMD ["handler", "App\\Worker\\RebuildSearchIndex"]
```

The handler is fetched **from the service container** when the application
registers it, so constructor injection works as it does anywhere else in the
app. Register it as public — `App\Worker\` with `public: true` in
`services.yaml` — otherwise Symfony hides it and the runtime falls back to
`new`, which fails for anything with dependencies.

Any of `__invoke`, `handle`, `run`, `perform`, `main` or `execute` is called;
name one explicitly with `App\Worker\Thing::rebuild`. A handler can also be a
service id, a plain function, or a file path that returns a callable.

Arguments come from the payload:

```php
// WORKER_PAYLOAD='{"companyId": 42, "since": "2024-01-01"}'
public function __invoke(int $companyId, string $since, Context $context): int
```

Parameters are matched by name (`company_id` matches `$companyId`), cast to the
declared scalar type, and filled from defaults when absent. A `Context`
parameter gets the run context, an `array` parameter gets the whole payload, and
any other class type is resolved from the container.

### `console` — an existing Symfony command

```dockerfile
CMD ["console", "app:import-ledger", "2024-Q4", "--dry-run"]
```

Arguments, options, output and exit code all behave as they do under
`bin/console`. `--no-interaction` is added automatically, because a container
has nobody to answer a prompt. Commands implementing
`SignalableCommandInterface` keep their own shutdown handling — the runtime
chains its signal handlers rather than replacing them.

### `consume` — a Messenger consumer

```dockerfile
CMD ["consume", "async", "failed"]
```

This delegates to `messenger:consume` rather than reimplementing it, so retry
strategies, the failure transport, middleware, rate limiting and worker event
listeners are all the ones your application configured. Stop conditions map to
the command's options:

```sh
WORKER_MESSAGE_LIMIT=500     # --limit
WORKER_TIME_LIMIT=3600       # --time-limit
WORKER_MEMORY_LIMIT=256M     # --memory-limit
WORKER_FAILURE_LIMIT=10      # --failure-limit
WORKER_SLEEP=1000000         # --sleep, in microseconds
```

Setting one or more of these is the usual way to recycle a PHP consumer instead
of chasing leaks. Options the runtime does not model are passed straight
through: `CMD ["consume", "async", "--queues=high"]`.

Projects using Messenger standalone — no FrameworkBundle — can hand the runtime
receivers and a bus from `worker.php`, and it drives `Messenger\Worker`
directly with the same stop conditions attached.

### `message` — one message, then exit

```dockerfile
CMD ["message"]
```

For schedulers that take the message off the queue themselves and want a
container per message, with the exit code deciding ack or retry. The message is
described either as a class plus JSON:

```sh
WORKER_MESSAGE_CLASS='App\Message\SendReport'
WORKER_PAYLOAD='{"companyId": 42, "period": "quarterly"}'
```

or as the transport payload exactly as it came off the queue, decoded with the
application's own Messenger serializer:

```sh
WORKER_PAYLOAD='{"body": "...", "headers": {"type": "App\\Message\\SendReport"}}'
```

Handlers run through the normal bus. This mode needs `message_bus` to be a
public service (`message_bus: { alias: messenger.default_bus, public: true }`).

## Graceful shutdown

A worker that can run for hours has to be stoppable without losing work in
flight. The first SIGTERM **requests** a stop; a second one ends the process.

```php
foreach ($rows as $row) {
    // Returns true once a stop has been requested. Without this, the container
    // can only be killed.
    if ($context->checkpoint()) {
        return 0;
    }

    $this->import($row);
}
```

`WORKER_SHUTDOWN_TIMEOUT` (default 30s) bounds the polite phase; a worker still
running after it is ended with the same exit code the signal would have
produced. Give containers at least that long — `stop_grace_period: 60s` in
Compose, `terminationGracePeriodSeconds: 60` in Kubernetes.

For cleanup that has to happen the moment the signal arrives, implement
`ShutdownAware` or register a listener:

```php
$context->onShutdown(fn (int $signal) => $this->releaseLock());
```

It runs inside the signal handler, so keep it short.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | the worker ran to completion |
| 1 | the worker threw, or returned `false` |
| 2 | the runtime could not make sense of its configuration |
| 3 | the application could not be booted |
| 4 | the handler, command or transport does not exist |
| 5 | `WORKER_TIMEOUT` expired |
| 130 / 143 | stopped by SIGINT / SIGTERM |
| *n* | whatever the handler or console command returned |

A **consumer** stopped by SIGTERM exits 0: it did what it was told, and a
rolling deploy should not look like a crash loop. A **one-shot job** cut short
exits 143, because its work is not finished and the scheduler should know.

## Configuration

Everything is an environment variable, so a scheduler can change what a
container does without rebuilding it.

| Variable | Default | Purpose |
| --- | --- | --- |
| `WORKER_MODE` | from the command | `handler`, `console`, `consume` or `message` |
| `WORKER_HANDLER` | — | handler for `handler` mode |
| `WORKER_COMMAND` | — | command line for `console` mode |
| `WORKER_TRANSPORTS` | — | comma-separated transports for `consume` mode |
| `WORKER_PAYLOAD` | — | the job's input, usually JSON |
| `WORKER_PAYLOAD_FILE` | — | read the payload from a file instead |
| `WORKER_JOB_ID` | generated | correlation id, added to every log record |
| `WORKER_TIMEOUT` | `0` | wall-clock limit in seconds; `0` is unbounded |
| `WORKER_SHUTDOWN_TIMEOUT` | `30` | grace period after a stop is requested |
| `WORKER_LOG_LEVEL` | `info` | `debug` … `critical` |
| `WORKER_LOG_FORMAT` | `text` | `text` or `json` |
| `WORKER_ROOT` | `/var/task` | where the application lives |
| `WORKER_KERNEL_CLASS` | `App\Kernel` | kernel to boot |
| `WORKER_BOOTSTRAP` | `$WORKER_ROOT/worker.php` | explicit wiring, if present |
| `WORKER_AUTOLOAD` | `$WORKER_ROOT/vendor/autoload.php` | Composer autoloader |
| `WORKER_MESSAGE_CLASS` | — | message to build for `message` mode |
| `WORKER_STRICT_ERRORS` | `0` | turn PHP warnings into exceptions |
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` | passed to the kernel |

Payloads can also be piped in: `cat job.json | docker run -i reports`. The
runtime only reads stdin when it is a pipe or a file, so a container started
without one never waits.

Runtime logs go to **stderr**, so stdout stays exactly what the worker printed.

## Hooks

Hooks are how an image adds behaviour around the worker without touching worker
code. They are optional executables in `$WORKER_ROOT/hooks` (override with
`WORKER_HOOK_DIR`):

| Hook | When | Arguments |
| --- | --- | --- |
| `init` | before the worker starts | — |
| `sigterm` | a termination signal arrived | the signal name |
| `shutdown` | after the worker exited | its exit code |

```sh
#!/bin/sh
# hooks/shutdown — report the outcome to a job server
curl -fsS -X POST "$JOB_SERVER/job/$WORKER_JOB_ID/$([ "$1" = 0 ] && echo complete || echo fail)"
```

A hook that fails is logged and ignored: observability must not break the job.

## Worker bootstrap (`worker.php`)

If `$WORKER_ROOT/worker.php` exists, the runtime uses what it returns instead of
discovering the application itself. Return a Kernel, a Console Application, a
message bus, or an array of parts:

```php
return [
    'bus' => $bus,
    'receivers' => ['async' => $receiver],
    'serializer' => $serializer,
];
```

This is the escape hatch for applications that are not stock Symfony — a
standalone Messenger setup, a custom container, a second bus. Most projects do
not need it.

## Node runtime

The Node runtime supports handler mode with the same environment variables,
exit codes and shutdown semantics:

```js
export async function run(context) {
  context.onShutdown((signal) => context.logger.notice("cleaning up", { signal }));

  for (const row of rows) {
    if (context.isStopping) return 0;
    await handle(row);
  }
}
```

`export default`, `handle`, `handler`, `main`, `run` and `perform` are all
recognised.

## Type hints for worker code

`Context`, `Worker` and `ShutdownAware` live in the runtime image, so they
resolve at run time without any dependency. To get them in your editor and in
static analysis, add the runtime as a dev dependency:

```sh
composer require --dev worker-framework/php-runtime
```

It has no dependencies of its own, so it cannot conflict with your application's
Symfony version.

## Development

```sh
cd runtimes/php
composer install
composer test          # 78 tests against real Symfony 7 components
```

The test suite runs the real runtime end to end: console commands through a
real Console Application, consumers against a real `Messenger\Worker` and
in-memory transport, and shutdown behaviour by having fixtures signal
themselves.

## Adding a language runtime

See [`Dockerfile.template`](Dockerfile.template). A runtime needs to read the
worker from its arguments, honour the environment variables above, stop when
asked, and exit with one of the documented codes. The shared `bootstrap` handles
signals and hooks for it.
