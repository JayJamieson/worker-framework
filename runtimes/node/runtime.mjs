import fs from "node:fs/promises";
import path from "node:path";

if (process.argv.length < 3) {
  console.log("Args:", process.argv);
  throw new Error("No handler specified");
}

const workerDir = process.env.WORKER_ROOT || process.cwd(); // defaults /var/task
const entrypoint = process.argv[2]; // usually index

console.log(`Executing '${entrypoint}' in directory '${workerDir}'`);

async function main(workerDir, entrypoint) {
  const fileDir = path.resolve(workerDir, entrypoint);
  const filepath = `${fileDir}.mjs`;
  let workerModule;
  let workerFunc;

  try {
    const _ = await fs.stat(filepath);
    workerModule = await import(filepath);

  } catch (e) {
    if (e instanceof SyntaxError) {
      throw new UserCodeSyntaxError(e);
    } else if (e.code !== undefined && e.code === "MODULE_NOT_FOUND") {
      console.log("globalPaths", JSON.stringify(require("module").globalPaths));
      throw new ImportModuleError(e);
    } else {
      throw e;
    }
  }

  workerFunc = workerModule["main"]
    ? workerModule["main"]
    : workerModule["run"];

  if (!workerFunc) {
    throw new EntrypointNotFound(`entrypoint function (main or run) is undefined or not exported`);
  }

  if (typeof workerFunc !== "function") {
    throw new EntrypointNotFound(`main or run is not a function`);
  }

  let errorCallbacks = {
    uncaughtException: (error) => {
      console.log("uncaughtException", JSON.stringify(toError(error)));
    },
    unhandledRejection: (error) => {
      console.log("unhandledRejection", JSON.stringify(toError(error)));
    },
  };

  process.on("unhandledRejection", (error) => {
    errorCallbacks.unhandledRejection(error);
  });

  process.on("uncaughtException", (error) => {
    errorCallbacks.uncaughtException(error);
  });

  process.on("SIGINT", () => {
    console.log("[JS] SIGINT received and exiting...");
    process.exit(1);
  });

  process.on("SIGTERM", () => {
    console.log("[JS] SIGTERM received and exiting...");
    process.exit(1);
  });

  process.on("SIGHUP", () => {
    console.log("[JS] SIGUP received and exiting...");
    process.exit(1);
  });

  // TODO revisit in next dev cycle to inject runtime information
  // let context = {};

  // for (const arg of process.argv.slice(2, process.argv.length)) {
  //   const [key, val] = arg.split("=");
  //   context[key] = val;
  // }

  await workerFunc()
}

await main(workerDir, entrypoint);

console.log("Worker completed run!");

class EntrypointNotFound extends Error {}
class UserCodeSyntaxError extends Error {}
class ImportModuleError extends Error {}

export function toError(error) {
  if (error instanceof Error) {
    return {
      message: error.message,
      code: error.code,
      name: error.name,
      stack: error.stack,
    };
  }

  const err = new Error(error);

  return {
    message: err.message,
    code: err.code,
    name: err.name,
    stack: err.stack,
  };
}
