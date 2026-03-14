<?php

declare(strict_types=1);

namespace App\Modules\Document\Infrastructure\External;

/**
 * Interface for Plateforme de Dématérialisation Partenaire (PDP) submission.
 *
 * France requires B2B invoices to be submitted through a certified PDP
 * starting September 2026. This interface defines the contract for
 * future PDP gateway integration.
 */
interface PdpClientInterface
{
    /**
     * Submit a Factur-X invoice to the PDP.
     *
     * @param  string  $invoiceId  Document UUID
     * @param  string  $facturXXml  The Factur-X XML content
     * @param  string  $pdfContent  Optional PDF/A-3 with embedded XML
     * @return array{submission_id: string, status: string, submitted_at: string}
     */
    public function submitInvoice(string $invoiceId, string $facturXXml, string $pdfContent = ''): array;

    /**
     * Check the status of a submitted invoice.
     *
     * @return array{status: string, message: string|null, updated_at: string}
     */
    public function checkStatus(string $submissionId): array;
}
