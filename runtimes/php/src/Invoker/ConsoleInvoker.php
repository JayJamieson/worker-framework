<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Invoker;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use WorkerFramework\Runtime\Application\ApplicationContext;
use WorkerFramework\Runtime\Application\ConsoleFactory;
use WorkerFramework\Runtime\Configuration;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\ContextAwareCommand;
use WorkerFramework\Runtime\Exception\ConfigurationException;
use WorkerFramework\Runtime\Exception\HandlerNotFoundException;
use WorkerFramework\Runtime\Log\Logger;
use WorkerFramework\Runtime\Log\LogLevel;

/**
 * Runs a Symfony Console command as the body of the worker.
 *
 * This is the mode that turns any existing `bin/console app:something` into a
 * container: the command keeps its arguments, options, exit code and output,
 * and gains the runtime's signal handling, timeout and structured logging.
 *
 * Commands implementing SignalableCommandInterface keep working - the runtime
 * chains its handlers rather than replacing them - so a command that already
 * knows how to stop cleanly still does. A command can also implement
 * ContextAwareCommand to get the runtime's Context directly, which makes this
 * mode a first-class home for a long-running, non-Messenger worker (a plain
 * `queue:work`, say) rather than something only Messenger consumers can be:
 * pair it with WORKER_LONG_RUNNING=1 so a clean stop reports exit 0.
 */
final class ConsoleInvoker implements Invoker
{
    private ?Application $application = null;

    /**
     * @param OutputInterface|null $output where command output goes; defaults to the
     *                                     process's STDOUT, which is what a container wants
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly ApplicationContext $applicationContext,
        private readonly Logger $logger,
        private readonly ?OutputInterface $output = null,
    ) {
    }

    public function describe(): string
    {
        return sprintf('console command `%s`', implode(' ', $this->arguments()));
    }

    public function invoke(Context $context): int
    {
        $application = $this->application();
        $arguments = $this->arguments();
        $commandName = $arguments[0];

        if (!$application->has($commandName)) {
            throw new HandlerNotFoundException(sprintf(
                'Console command "%s" is not registered. Available commands can be listed with `bin/console list`.',
                $commandName,
            ));
        }

        // Application caches the resolved instance on first lookup (has(),
        // above, already triggered that), so mutating it here reaches the
        // exact object run() executes below, however it was constructed -
        // by hand in worker.php, or lazily from the service container.
        $command = $application->find($commandName);

        if ($command instanceof ContextAwareCommand) {
            $command->setWorkerContext($context);
        }

        $output = $this->createOutput();
        $input = new ArgvInput(['worker', ...$arguments]);
        $input->setInteractive(false);

        $this->logger->info('Running console command', ['command' => implode(' ', $arguments)]);

        try {
            return $application->run($input, $output);
        } catch (Throwable $error) {
            // Symfony renders usage errors far better than a stack trace does;
            // show its version on stdout, then let the runtime log and classify.
            $application->renderThrowable($error, $output);

            throw $error;
        }
    }

    /**
     * @return non-empty-list<string>
     */
    private function arguments(): array
    {
        $arguments = $this->config->arguments;

        if ([] === $arguments) {
            throw new ConfigurationException(
                'Console mode needs a command. Use CMD ["console", "app:import", "--force"] or set '
                . 'WORKER_COMMAND="app:import --force".',
            );
        }

        // Containers have no terminal to answer a question on.
        if (!\in_array('--no-interaction', $arguments, true) && !\in_array('-n', $arguments, true)) {
            $arguments[] = '--no-interaction';
        }

        return array_values($arguments);
    }

    private function application(): Application
    {
        return $this->application ??= ConsoleFactory::create($this->applicationContext);
    }

    /**
     * Command output goes to STDOUT, where the worker's own output belongs.
     *
     * WORKER_LOG_LEVEL=debug also makes the command talkative, but a quieter
     * log level never silences it: that setting is about runtime chatter, and
     * a command's output is the job's result. Use `--quiet` for that.
     */
    private function createOutput(): OutputInterface
    {
        return $this->output ?? new ConsoleOutput(
            LogLevel::Debug === $this->config->logLevel
                ? OutputInterface::VERBOSITY_DEBUG
                : OutputInterface::VERBOSITY_NORMAL,
        );
    }
}
