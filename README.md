# Worker framework (WIP)

Worker framework takes heavy inspiration from AWS Lambda without the maximum 15 minute run duration. I wanted to be able to implement workers
with a similar signature to `handler` that Lambda has but be able to have my worker run for as long as needed.

## How it works

The `entrypoint.sh` calls `bootstrap` with `exec` to run language specific runtime setup. For the most part this just configures signal handlers to terminate main process and perform cleanup of worker environment.

Each language has a `runtime.*` implementation that handles calling worker code. Additionally configures error handlers and signal handlers used for gracefull shutdown of your worker.

### Hooks

> [!NOTE]
> Hooks are not fully operational in current version. Feel free to open a PR with your ideas.

Hooks are how you can configure additional functionality into your runtime separate to the worker code itself.

- `init` can be used to regiser the worker somewhere or send of a notification it has started.
- `sigterm_handler` can be used to handle cancellation of worker. This hook gets invoked after a signal has been sent to your worker, your worker
runtime has an additional signal handler function that can be configured to read worker state at time of termination and perform any cleanup tasks
as needed.
- `shutdown` can be used for anything that needs to happen at successfuly completion/termination of your worker.

## Example

```js
// named index.mjs

export async function main(context) {
  console.log("[WORKER] Job starting in run function");
  console.log("[WORKER] my pid", process.pid);

  for (let index = 0; index <= 10; index++) {
    await sleep(1000);
    console.log(`[JS] ${index}`);
  }
}
```

```Dockerfile
FROM <image>:<tag>

COPY index.mjs "$WORKER_ROOT/"

CMD ["main"]
```

## TODO

- publish base docker images
- implement hooks to invoke user provided code
- finish php runtime
