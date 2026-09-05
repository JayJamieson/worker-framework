/**
 * Node.js worker runtime.
 *
 * Mirrors the PHP runtime's contract - same environment variables, same exit
 * codes, same cooperative shutdown - so that a scheduler can treat workers of
 * either language identically.
 */

import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { pathToFileURL } from "node:url";

const EXIT = {
  SUCCESS: 0,
  WORKER_ERROR: 1,
  CONFIGURATION_ERROR: 2,
  BOOTSTRAP_ERROR: 3,
  HANDLER_NOT_FOUND: 4,
  TIMEOUT: 5,
};

const SIGNAL_NUMBERS = { SIGINT: 2, SIGTERM: 15, SIGHUP: 1 };

/** Methods tried on a handler module, in order. */
const ENTRYPOINTS = ["default", "handle", "handler", "main", "run", "perform"];

class ConfigurationError extends Error {
  exitCode = EXIT.CONFIGURATION_ERROR;
}

class HandlerNotFoundError extends Error {
  exitCode = EXIT.HANDLER_NOT_FOUND;
}

/**
 * Structured logging on stderr, leaving stdout to the worker itself.
 */
class Logger {
  #level;
  #format;
  #context;

  static LEVELS = { debug: 0, info: 1, notice: 2, warning: 3, error: 4, critical: 5 };

  constructor(level = "info", format = "text", context = {}) {
    this.#level = level;
    this.#format = format;
    this.#context = context;
  }

  withContext(context) {
    return new Logger(this.#level, this.#format, { ...this.#context, ...context });
  }

  debug(message, context) { this.#write("debug", message, context); }
  info(message, context) { this.#write("info", message, context); }
  notice(message, context) { this.#write("notice", message, context); }
  warning(message, context) { this.#write("warning", message, context); }
  error(message, context) { this.#write("error", message, context); }
  critical(message, context) { this.#write("critical", message, context); }

  exception(error, message = "Unhandled exception") {
    this.error(message, {
      exception: error?.name ?? "Error",
      error: error?.message ?? String(error),
      stack: error?.stack,
    });
  }

  #write(level, message, context = {}) {
    const minimum = Logger.LEVELS[this.#level] ?? Logger.LEVELS.info;

    if ((Logger.LEVELS[level] ?? 0) < minimum) {
      return;
    }

    const record = { ...this.#context, ...context };

    if (this.#format === "json") {
      process.stderr.write(
        JSON.stringify({ timestamp: new Date().toISOString(), level, message, ...record }) + "\n",
      );
      return;
    }

    const { stack, ...rest } = record;
    const fields = Object.entries(rest)
      .filter(([, value]) => value !== undefined && value !== null)
      .map(([key, value]) => `${key}=${/[\s"]/.test(String(value)) ? JSON.stringify(String(value)) : value}`)
      .join(" ");

    const time = new Date().toISOString().slice(11, 19);
    process.stderr.write(`[${time}] [${level.toUpperCase()}] ${message}${fields ? " " + fields : ""}\n`);
    if (stack) {
      process.stderr.write(stack + "\n");
    }
  }
}

/**
 * Cooperative shutdown. The first signal asks the worker to stop; a second one
 * of the same kind ends the process immediately.
 */
class Signals {
  #stopping = false;
  #signal = null;
  #timedOut = false;
  #listeners = [];
  #seen = new Map();
  #graceTimer = null;

  constructor(logger, shutdownTimeout) {
    this.logger = logger;
    this.shutdownTimeout = shutdownTimeout;
  }

  register() {
    for (const name of Object.keys(SIGNAL_NUMBERS)) {
      process.on(name, () => this.#onSignal(name));
    }
  }

  get stopping() { return this.#stopping; }
  get signal() { return this.#signal; }
  get timedOut() { return this.#timedOut; }

  onShutdown(listener) {
    this.#listeners.push(listener);
    if (this.#stopping) {
      this.#invoke(listener);
    }
  }

  startTimeout(seconds) {
    if (seconds > 0) {
      // unref so a worker that finishes early is not held open by the timer.
      setTimeout(() => {
        this.#timedOut = true;
        this.logger.warning("Worker timeout reached, requesting shutdown");
        this.#requestStop("SIGTERM");
      }, seconds * 1000).unref();
    }
  }

  exitCode() {
    if (this.#timedOut) return EXIT.TIMEOUT;
    if (this.#signal) return 128 + (SIGNAL_NUMBERS[this.#signal] ?? 15);
    return EXIT.SUCCESS;
  }

  detach() {
    if (this.#graceTimer) {
      clearTimeout(this.#graceTimer);
      this.#graceTimer = null;
    }
    for (const name of Object.keys(SIGNAL_NUMBERS)) {
      process.removeAllListeners(name);
    }
  }

  #onSignal(name) {
    const count = (this.#seen.get(name) ?? 0) + 1;
    this.#seen.set(name, count);

    if (count > 1) {
      this.logger.warning("Signal received again, exiting immediately", { signal: name });
      process.exit(128 + (SIGNAL_NUMBERS[name] ?? 15));
    }

    this.#requestStop(name);
  }

  #requestStop(name) {
    if (this.#stopping) {
      return;
    }

    this.#stopping = true;
    this.#signal = name;

    this.logger.notice("Shutdown requested, finishing current unit of work", {
      signal: name,
      grace_period: this.shutdownTimeout,
    });

    if (this.shutdownTimeout > 0) {
      this.#graceTimer = setTimeout(() => {
        this.logger.error("Worker did not stop within the grace period, exiting", {
          grace_period: this.shutdownTimeout,
        });
        process.exit(this.exitCode());
      }, this.shutdownTimeout * 1000);
      this.#graceTimer.unref();
    }

    for (const listener of this.#listeners) {
      this.#invoke(listener);
    }
  }

  #invoke(listener) {
    try {
      listener(this.#signal);
    } catch (error) {
      this.logger.exception(error, "Shutdown listener failed");
    }
  }
}

/**
 * What the runtime knows about this run, handed to worker code.
 */
class Context {
  #signals;
  #attributes = new Map();

  constructor({ jobId, payload, logger, signals, deadline }) {
    this.jobId = jobId;
    this.payload = payload;
    this.logger = logger;
    this.#signals = signals;
    this.deadline = deadline;
  }

  get isStopping() {
    return this.#signals.stopping;
  }

  /** Seconds left before WORKER_TIMEOUT fires, or null when unbounded. */
  get remainingTime() {
    return this.deadline === null ? null : Math.max(0, (this.deadline - Date.now()) / 1000);
  }

  onShutdown(listener) {
    this.#signals.onShutdown(listener);
  }

  set(key, value) { this.#attributes.set(key, value); }
  get(key, fallback = undefined) {
    return this.#attributes.has(key) ? this.#attributes.get(key) : fallback;
  }
}

function readConfiguration(argv, env) {
  const workerRoot = env.WORKER_ROOT || process.cwd();
  const entrypoint = argv[2] ?? env.WORKER_HANDLER;

  if (!entrypoint) {
    throw new ConfigurationError(
      "No worker specified. Pass the module as the container's CMD, or set WORKER_HANDLER.",
    );
  }

  return {
    workerRoot,
    entrypoint,
    jobId: env.WORKER_JOB_ID || Math.random().toString(16).slice(2, 18),
    timeout: Math.max(0, Number.parseInt(env.WORKER_TIMEOUT ?? "0", 10) || 0),
    shutdownTimeout: Math.max(0, Number.parseInt(env.WORKER_SHUTDOWN_TIMEOUT ?? "30", 10) || 0),
    logLevel: (env.WORKER_LOG_LEVEL ?? "info").toLowerCase(),
    logFormat: (env.WORKER_LOG_FORMAT ?? "text").toLowerCase() === "json" ? "json" : "text",
  };
}

async function readPayload(env) {
  const raw = env.WORKER_PAYLOAD
    ?? (env.WORKER_PAYLOAD_FILE ? await fs.readFile(env.WORKER_PAYLOAD_FILE, "utf8") : "");

  if (!raw.trim()) {
    return {};
  }

  try {
    const decoded = JSON.parse(raw);
    return decoded !== null && typeof decoded === "object" ? decoded : { value: decoded };
  } catch {
    return { value: raw };
  }
}

/**
 * Resolve the worker module. `index`, `index.mjs` and `./jobs/index.mjs` all
 * work; the extension is optional because the original proof of concept's CMD
 * did not include one.
 */
async function loadHandler(workerRoot, entrypoint) {
  const base = path.resolve(workerRoot, entrypoint);
  const candidates = path.extname(base) ? [base] : [`${base}.mjs`, `${base}.js`, path.join(base, "index.mjs")];

  for (const candidate of candidates) {
    if (await fs.stat(candidate).then(() => true, () => false)) {
      const module = await import(pathToFileURL(candidate).href);
      const name = ENTRYPOINTS.find((key) => typeof module[key] === "function");

      if (!name) {
        throw new HandlerNotFoundError(
          `${candidate} exports none of: ${ENTRYPOINTS.join(", ")}.`,
        );
      }

      return { fn: module[name], description: `${path.basename(candidate)}#${name}` };
    }
  }

  throw new HandlerNotFoundError(
    `Worker module "${entrypoint}" not found under ${workerRoot} (tried ${candidates.join(", ")}).`,
  );
}

async function main() {
  const env = process.env;
  const config = readConfiguration(process.argv, env);
  const startedAt = Date.now();

  const logger = new Logger(config.logLevel, config.logFormat).withContext({
    job_id: config.jobId,
    mode: "handler",
  });

  const signals = new Signals(logger, config.shutdownTimeout);
  signals.register();
  signals.startTimeout(config.timeout);

  // Anything unhandled is the worker failing, not something to swallow.
  process.on("unhandledRejection", (reason) => {
    logger.exception(reason, "Unhandled promise rejection");
    process.exitCode = EXIT.WORKER_ERROR;
  });

  const context = new Context({
    jobId: config.jobId,
    payload: await readPayload(env),
    logger,
    signals,
    deadline: config.timeout > 0 ? startedAt + config.timeout * 1000 : null,
  });

  const { fn, description } = await loadHandler(config.workerRoot, config.entrypoint);

  logger.info(`Starting handler ${description}`, { pid: process.pid });

  const result = await fn(context);
  let exitCode = Number.isInteger(result) ? result : EXIT.SUCCESS;

  if (exitCode === EXIT.SUCCESS && signals.timedOut) {
    exitCode = EXIT.TIMEOUT;
  } else if (exitCode === EXIT.SUCCESS && signals.stopping) {
    exitCode = signals.exitCode();
  }

  const stopped = signals.stopping && !signals.timedOut;
  logger[exitCode !== EXIT.SUCCESS && !stopped ? "error" : "info"](
    exitCode !== EXIT.SUCCESS && !stopped
      ? "Worker finished with errors"
      : stopped
        ? "Worker stopped on request"
        : "Worker completed",
    {
      exit_code: exitCode,
      duration: `${((Date.now() - startedAt) / 1000).toFixed(3)}s`,
      peak_memory: `${(process.memoryUsage().rss / 1048576).toFixed(1)}MB`,
    },
  );

  signals.detach();

  return exitCode;
}

try {
  process.exitCode = await main();
} catch (error) {
  const logger = new Logger(process.env.WORKER_LOG_LEVEL, process.env.WORKER_LOG_FORMAT);

  if (error instanceof ConfigurationError || error instanceof HandlerNotFoundError) {
    logger.error(error.message);
    process.exitCode = error.exitCode;
  } else {
    logger.exception(error, "Worker failed");
    process.exitCode = EXIT.WORKER_ERROR;
  }
}
