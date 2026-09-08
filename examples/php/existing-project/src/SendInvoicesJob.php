<?php

declare(strict_types=1);

namespace Acme\Worker;

use WorkerFramework\Runtime\Context;

/**
 * An ordinary job class from the application, using the application's own
 * services. It knows nothing about containers or queues.
 *
 * The one framework-aware line is `$context->checkpoint()`. Without it the
 * container can only ever be killed mid-batch; with it, a deploy or a scale-in
 * finishes the invoice in flight and stops cleanly.
 */
final class SendInvoicesJob
{
    public function __construct(private readonly InvoiceRepository $invoices)
    {
    }

    public function perform(int $companyId, Context $context): int
    {
        $sent = 0;

        foreach ($this->invoices->due($companyId) as $invoice) {
            if ($context->checkpoint()) {
                $context->logger()->notice('Stopping early', ['sent' => $sent]);

                // The invoices already sent are committed; the rest are still
                // due and will be picked up next run.
                return 0;
            }

            $this->invoices->send($invoice);
            ++$sent;
        }

        return 0;
    }
}
