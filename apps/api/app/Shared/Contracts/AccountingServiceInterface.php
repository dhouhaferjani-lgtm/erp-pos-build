<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\Document\Domain\Document;
use DateTimeInterface;

/**
 * Interface for accounting operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface AccountingServiceInterface
{
    /**
     * Find an account ID by code.
     *
     * @return string|null Account ID or null if not found
     */
    public function findAccountIdByCode(
        string $tenantId,
        string $companyId,
        string $code
    ): ?string;

    /**
     * Create an opening balance journal entry.
     *
     * @return string The journal entry ID
     */
    public function createOpeningBalanceEntry(
        string $tenantId,
        string $companyId,
        string $accountId,
        string $debit,
        string $credit,
        string $description,
        ?string $reference,
        DateTimeInterface $date
    ): string;

    /**
     * Create GL entries for a posted invoice.
     *
     * Creates journal entries with:
     * - Debit: Accounts Receivable (AR)
     * - Credit: Revenue (by line item tax category)
     * - Credit: Tax Payable (by tax rate)
     *
     * @return string The journal entry ID
     */
    public function createInvoiceGLEntries(Document $invoice): string;

    /**
     * Create GL reversal entries for a posted credit note.
     *
     * Creates journal entries that reverse the original invoice GL entries:
     * - Debit: Revenue (by line item tax category)
     * - Debit: Tax Payable (by tax rate)
     * - Credit: Accounts Receivable (AR)
     *
     * @return string The journal entry ID
     */
    public function createCreditNoteGLEntries(Document $creditNote): string;
}
