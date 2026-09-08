<?php

declare(strict_types=1);

namespace WorkerFramework\Runtime\Tests\Fixtures;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerFramework\Runtime\Context;
use WorkerFramework\Runtime\Contract\ContextAwareCommand;

/**
 * An ordinary Symfony command, of the kind an application already has.
 */
#[AsCommand(name: 'app:import', description: 'Imports things')]
final class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Where to import from')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Recorder::record('command', [
            'source' => $input->getArgument('source'),
            'dry-run' => $input->getOption('dry-run'),
        ]);

        $output->writeln('imported ' . $input->getArgument('source'));

        return Command::SUCCESS;
    }
}

/**
 * A command that fails, to check the exit code reaches the container.
 */
#[AsCommand(name: 'app:fail')]
final class FailingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 9;
    }
}

/**
 * A command with its own signal handling: the runtime must not displace it.
 */
#[AsCommand(name: 'app:long')]
final class LongRunningCommand extends Command implements SignalableCommandInterface
{
    private bool $stop = false;

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        Recorder::record('command_signal', $signal);
        $this->stop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (!$this->stop) {
            Recorder::record('command_tick');
            usleep(20_000);

            if (Recorder::count('command_tick') > 500) {
                break;
            }
        }

        return Command::SUCCESS;
    }
}

/**
 * A long-running console command with no idea SignalableCommandInterface
 * exists - it cooperates with a stop request purely through the runtime's
 * Context, the way a handler-mode worker already can.
 */
#[AsCommand(name: 'app:queue-work')]
final class QueueWorkCommand extends Command implements ContextAwareCommand
{
    private ?Context $context = null;

    public function setWorkerContext(Context $context): void
    {
        $this->context = $context;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        for ($i = 0; $i < 1000; ++$i) {
            if (true === $this->context?->checkpoint()) {
                Recorder::record('drained_at', $i);

                return Command::SUCCESS;
            }

            Recorder::record('tick', $i);
            usleep(20_000);

            if (2 === $i) {
                posix_kill((int) getmypid(), \SIGTERM);
            }
        }

        return Command::SUCCESS;
    }
}

/**
 * A Messenger message, written in the usual read-only DTO style.
 */
final class SendReport
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $period = 'monthly',
    ) {
    }
}

/**
 * The handler Symfony Messenger would route SendReport to.
 */
final class SendReportHandler
{
    public function __invoke(SendReport $message): int
    {
        Recorder::record('handled', ['companyId' => $message->companyId, 'period' => $message->period]);

        return 0;
    }
}

/**
 * A handler that always throws, for the failure path.
 */
final class BrokenHandler
{
    public function __invoke(BrokenMessage $message): void
    {
        throw new \DomainException('handler blew up');
    }
}

final class BrokenMessage
{
}

/**
 * A message whose handler asks the worker to stop, standing in for a SIGTERM
 * arriving mid-message.
 */
final class StopAfterThis
{
}

final class StopAfterThisHandler
{
    public function __invoke(StopAfterThis $message): void
    {
        Recorder::record('handled_stop');
        posix_kill((int) getmypid(), \SIGTERM);
    }
}
