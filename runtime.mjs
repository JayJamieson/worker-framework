const job = await import("./job.mjs");

export function toError(error) {
  if (error instanceof Error) {
    return {
      message: error.message,
      // @ts-ignore
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
  console.log("[JS] SIGINT|SIGERM|SIGUP received exiting...");
  process.exit(0);
});

process.on("SIGTERM", () => {
  console.log("[JS] SIGINT|SIGERM|SIGUP received exiting...");
  process.exit(0);
});

process.on("SIGHUP", () => {
  console.log("[JS] SIGINT|SIGERM|SIGUP received exiting...");
  process.exit(0);
});

let companyId = process.env["COMPANY_ID"];
let context = {};

for (const arg of process.argv.slice(2,process.argv.length)) {
  const [key, val] = arg.split("=");
  context[key] = val;
}

console.log();

await job.run(companyId, context);

console.log("Job completed run!");
