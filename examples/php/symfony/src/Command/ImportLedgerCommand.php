<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An ordinary Symfony command. Running it as a worker needs no changes:
 *
 *   docker run --rm reports console app:import-ledger 2024-Q4
 *
 * A command that implements SignalableCommandInterface keeps its own shutdown
 * behaviour - the runtime chains its signal handlers rather than replacing
 * them, so both run.
 */
#[AsCommand(name: 'app:import-ledger', description: 'Imports a ledger export')]
final class ImportLedgerCommand extends Command implements SignalableCommandInterface
{
    private bool $shouldStop = false;

    protected function configure(): void
    {
        $this
            ->addArgument('period', InputArgument::REQUIRED, 'The period to import, e.g. 2024-Q4')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse without writing');
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldStop = true;

        // false means "carry on"; the command decides when to return.
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $period = (string) $input->getArgument('period');

        $io->title(sprintf('Importing ledger for %s', $period));

        foreach (range(1, 1000) as $row) {
            if ($this->shouldStop) {
                $io->warning(sprintf('Stopped after %d rows; rerun to continue.', $row));

                return Command::SUCCESS;
            }

            // ... import the row ...
        }

        $io->success('Import complete');

        return Command::SUCCESS;
    }
}
