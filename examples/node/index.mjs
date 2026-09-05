/**
 * The simplest Node worker: one exported function, called once.
 *
 *   docker build -t reports .
 *   docker run --rm -e WORKER_PAYLOAD='{"companyId":42}' reports
 */

export async function run(context) {
  const { companyId = "unknown" } = context.payload;

  context.logger.info("Generating reports", { companyId });

  // Release anything that must not be left half-done if the container is
  // stopped. Runs as soon as SIGTERM arrives, before the loop below notices.
  context.onShutdown((signal) => {
    context.logger.notice("Cleaning up", { signal });
  });

  for (let page = 1; page <= 100; page++) {
    // The one line that makes a long worker interruptible: without it, the
    // container can only be killed.
    if (context.isStopping) {
      context.logger.notice("Stopped early, will resume from here", { page });
      return 0;
    }

    console.log(`page ${page} of company ${companyId}`);
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}
