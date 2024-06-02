const sleep = async (duration) => {
  return new Promise((resolve) => setTimeout(resolve, duration));
};

export async function run(context) {
  console.log("[WORKER] Job starting in run function");

  console.log("[WORKER]", JSON.stringify(context));
  console.log("[WORKER] my pid", process.pid);

  for (let index = 0; index <= 10; index++) {
    await sleep(1000);
    console.log(`[JS] ${index}`);
  }
}
