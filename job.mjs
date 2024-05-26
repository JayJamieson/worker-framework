const sleep = async (duration) => {
  return new Promise((resolve) => setTimeout(resolve, duration));
};

export async function run(companyId, context) {
  console.log("[JS] Job starting in run function");

  console.log("[JOB] company_id:", companyId);
  console.log("[JOB]", JSON.stringify(context));
  console.log("[JOB] my pid", process.pid);

  for (let index = 0; index <= 10; index++) {
    await sleep(1000);
    console.log(`[JS] ${index}`);
  }
}
