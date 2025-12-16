<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Contracts;

use App\Modules\Billing\Domain\Invoice;

/**
 * Interface for invoice PDF generation.
 */
interface InvoiceGeneratorInterface
{
    /**
     * Generate PDF for an invoice.
     *
     * @return string Binary PDF content
     */
    public function generatePdf(Invoice $invoice): string;

    /**
     * Generate PDF and save to storage.
     *
     * @return string Path to saved file
     */
    public function generateAndStore(Invoice $invoice): string;
}
