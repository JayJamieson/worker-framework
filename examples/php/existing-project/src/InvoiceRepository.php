<?php

declare(strict_types=1);

namespace Acme\Worker;

use PDO;

/**
 * Stands in for whatever service layer the application already has.
 */
final class InvoiceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function due(int $companyId): iterable
    {
        $statement = $this->db->prepare('SELECT * FROM invoices WHERE company_id = :company AND sent_at IS NULL');
        $statement->execute(['company' => $companyId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public function send(array $invoice): void
    {
        // ... render the PDF, call the mail provider, mark it sent ...
    }
}
