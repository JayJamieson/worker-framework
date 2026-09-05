<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendReport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An ordinary message handler, unchanged from what it would be under
 * `bin/console messenger:consume`.
 *
 * The worker framework does not ask handlers to know they are running in a
 * container: it runs them through the normal bus, so middleware, retries and
 * the failure transport behave exactly as configured.
 */
#[AsMessageHandler]
final class SendReportHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(SendReport $message): void
    {
        $this->logger->info('Sending report', [
            'company' => $message->companyId,
            'period' => $message->period,
        ]);

        // ... build the report and email it ...
    }
}
