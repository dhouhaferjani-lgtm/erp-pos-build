<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\DTOs\CreatePOSChargeJournalEntryCommand;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

/**
 * General Ledger Service for creating and managing journal entries.
 *
 * IMPORTANT: This service uses SystemAccountPurpose for account lookups
 * instead of hardcoded account codes. This enables country-agnostic
 * accounting regardless of the chart of accounts structure.
 */
final class GeneralLedgerService
{
    public function __construct(
        private readonly PartnerBalanceService $partnerBalanceService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly GeneralLedgerHashService $hashService,
        private readonly FiscalPeriodResolverService $fiscalPeriodResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    private function currencyCodeForCompany(string $companyId): string
    {
        $currency = Company::query()->whereKey($companyId)->value('currency');
        if (! is_string($currency) || $currency === '') {
            throw new \RuntimeException("Cannot resolve currency for company {$companyId}.");
        }

        return $currency;
    }

    private function postEntryAndDispatchPostedEvent(
        JournalEntry $entry,
        User $user,
        string $companyId,
        ?string $currencyCode,
    ): void {
        $this->postEntry($entry, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
        $entry->refresh()->load('lines');
    }

    private function postSystemGeneratedEntryAndDispatchPostedEvent(
        JournalEntry $entry,
        string $companyId,
        ?string $currencyCode,
    ): void {
        $this->postEntryWithOptionalActor($entry, null, $currencyCode ?? $this->currencyCodeForCompany($companyId));
        $entry->refresh()->load('lines');
    }

    private function postEntryAndDispatchPostedEventAfterCommit(
        JournalEntry $entry,
        User $user,
        string $companyId,
        ?string $currencyCode,
    ): void {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($entry, $user, $companyId, $currencyCode): void {
                $this->postEntryAndDispatchPostedEvent($entry, $user, $companyId, $currencyCode);
            });

            return;
        }

        $this->postEntryAndDispatchPostedEvent($entry, $user, $companyId, $currencyCode);
    }

    private function postSystemGeneratedEntryAndDispatchPostedEventAfterCommit(
        JournalEntry $entry,
        string $companyId,
        ?string $currencyCode,
    ): void {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($entry, $companyId, $currencyCode): void {
                $this->postSystemGeneratedEntryAndDispatchPostedEvent($entry, $companyId, $currencyCode);
            });

            return;
        }

        $this->postSystemGeneratedEntryAndDispatchPostedEvent($entry, $companyId, $currencyCode);
    }

    /**
     * Create journal entry from a posted invoice.
     * Debit: Accounts Receivable (total)
     * Credit: Sales Revenue (subtotal)
     * Credit: VAT Payable (tax)
     */
    public function createFromInvoice(Document $invoice, User $user): JournalEntry
    {
        $entry = DB::transaction(function () use ($invoice): JournalEntry {
            $companyId = $invoice->company_id;

            // Get required accounts by system purpose (country-agnostic)
            $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $taxAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::VatCollected);

            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $invoice->document_date,
                'description' => "Invoice {$invoice->document_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'invoice',
                'journal_code' => JournalCode::fromSourceType('invoice')->value,
                'source_id' => $invoice->id,
            ]);

            $lineOrder = 0;

            // Debit: Accounts Receivable (total amount) - with partner for subledger
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $invoice->partner_id,
                'debit' => $invoice->total ?? '0',
                'credit' => '0',
                'description' => 'Accounts receivable',
                'line_order' => $lineOrder++,
            ]);

            // Credit: Sales Revenue (subtotal)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'debit' => '0',
                'credit' => $invoice->subtotal ?? '0',
                'description' => 'Sales revenue',
                'line_order' => $lineOrder++,
            ]);

            // Credit: VAT Payable (tax amount) - only if there's tax
            $taxAmount = $invoice->tax_amount ?? '0';
            if (bccomp($taxAmount, '0', $this->scale()) > 0) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $taxAccount->id,
                    'debit' => '0',
                    'credit' => $taxAmount,
                    'description' => 'VAT payable',
                    'line_order' => $lineOrder,
                ]);
            }

            return $entry->load('lines');
        });

        return $entry;
    }

    /**
     * Create journal entry from a credit note.
     * This is the reverse of an invoice:
     * Debit: Sales Revenue (subtotal)
     * Debit: VAT Payable (tax)
     * Credit: Accounts Receivable (total)
     */
    public function createFromCreditNote(Document $creditNote, User $user): JournalEntry
    {
        $entry = DB::transaction(function () use ($creditNote): JournalEntry {
            $companyId = $creditNote->company_id;

            // Get required accounts by system purpose (country-agnostic)
            $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $taxAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::VatCollected);

            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $creditNote->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $creditNote->document_date,
                'description' => "Credit Note {$creditNote->document_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'credit_note',
                'journal_code' => JournalCode::fromSourceType('credit_note')->value,
                'source_id' => $creditNote->id,
            ]);

            $lineOrder = 0;

            // Debit: Sales Revenue (subtotal) - reduces revenue
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'debit' => $creditNote->subtotal ?? '0',
                'credit' => '0',
                'description' => 'Sales revenue reversal',
                'line_order' => $lineOrder++,
            ]);

            // Debit: VAT Payable (tax amount) - only if there's tax
            $taxAmount = $creditNote->tax_amount ?? '0';
            if (bccomp($taxAmount, '0', $this->scale()) > 0) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $taxAccount->id,
                    'debit' => $taxAmount,
                    'credit' => '0',
                    'description' => 'VAT payable reversal',
                    'line_order' => $lineOrder++,
                ]);
            }

            // Credit: Accounts Receivable (total) - reduces receivable - with partner for subledger
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $creditNote->partner_id,
                'debit' => '0',
                'credit' => $creditNote->total ?? '0',
                'description' => 'Accounts receivable reduction',
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });

        return $entry;
    }

    /**
     * Create a payment journal entry.
     * Debit: Cash/Bank account
     * Credit: Accounts Receivable (with partner_id for subledger tracking)
     */
    public function createPaymentEntry(
        string $companyId,
        string $amount,
        string $debitAccountId,
        string $creditAccountId,
        string $description,
        User $user,
        ?string $partnerId = null,
    ): JournalEntry {
        $entry = DB::transaction(function () use ($companyId, $amount, $debitAccountId, $creditAccountId, $description, $user, $partnerId): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => now()->toDateString(),
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'payment',
                'journal_code' => JournalCode::fromSourceType('payment')->value,
            ]);

            // Debit: Cash/Bank (no partner - asset account)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Cash received',
                'line_order' => 0,
            ]);

            // Credit: Accounts Receivable (with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Receivable cleared',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        return $entry;
    }

    /**
     * Create journal entry for customer advance/prepayment.
     *
     * Advance payments create a liability (we owe the customer until invoice issued).
     * Debit: Bank/Cash
     * Credit: Customer Advances (liability - with partner for subledger)
     */
    public function createCustomerAdvanceJournalEntry(
        string $companyId,
        string $partnerId,
        string $advanceId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        ?User $user,
        ?string $description = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        // Mirror createPaymentReceivedJournalEntry: synchronous in-transaction posting
        // (Task 19 — cash-moving deposit flow) must sit inside the caller's transaction
        // so the returned entry is already POSTED and can be linked to the movement leg
        // recorded through the write port. Refuse to create a Draft that postEntryNow
        // would then orphan outside a transaction.
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createCustomerAdvanceJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $advanceId, $amount, $paymentMethodAccountId,
            $date, $description, $advanceAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            // Derive tenant_id from the company (not the actor): the actor is
            // nullable now — an offline-authored ACCOUNT_PAYMENT whose cashier is
            // not a resolvable company member still moves cash and must post its
            // customer-advance GL consequence (Task 24 Fix A).
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Customer advance received',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'advance',
                'journal_code' => JournalCode::fromSourceType('advance')->value,
                'source_id' => $advanceId,
            ]);

            // Debit: Bank/Cash
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Advance payment received',
                'line_order' => 0,
            ]);

            // Credit: Customer Advances (liability - with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $advanceAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Customer advance liability',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        // Synchronous in-transaction posting is only used by atomic money-movement
        // flows that always carry a resolved actor — refuse a null actor there
        // (mirrors createPaymentReceivedJournalEntry).
        if ($mode === PostingMode::SynchronousInTransaction && $user === null) {
            throw new \LogicException('createCustomerAdvanceJournalEntry: synchronous in-transaction GL posting requires an actor ($user); refusing to return an unposted Draft into an atomic money-movement flow.');
        }

        if ($user !== null) {
            if ($mode === PostingMode::SynchronousInTransaction) {
                $this->postEntryNow($entry, $user, $currencyCode);
            } else {
                $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
            }
        } else {
            // Actor could not be resolved (e.g. an offline-authored ACCOUNT_PAYMENT
            // whose cashier is not a resolvable company member). The GL consequence
            // (Dr Bank / Cr Customer-Advance) is deterministic and independent of who
            // posted it, so seal it as a SYSTEM-generated POSTED entry rather than
            // leaving an unposted Draft that the treasury reconcile would freeze on
            // (Task 24 Fix A). AfterCommit only (the Synchronous + null-actor
            // combination is refused above).
            $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Reverse a supplier advance (vendor prepayment refund).
     *
     * When a PO prepayment is refunded, this reverses the original advance:
     * Original: Dr Supplier Advance / Cr Bank (asset increased, cash decreased)
     * Reversal: Dr Bank / Cr Supplier Advance (cash returned, asset decreased)
     */
    public function reverseSupplierAdvanceJournalEntry(
        string $companyId,
        string $partnerId,
        string $refundId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        // Treasury spine (Task 18): SynchronousInTransaction posts the reversal
        // via postEntryNow so the GL post is atomic with — and its company
        // advisory lock is taken BEFORE — the movement port's repository row lock
        // (global lock order, BLOCKER-1). It therefore requires an enclosing
        // transaction; refuse to mint a Draft that postEntryNow would orphan.
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('reverseSupplierAdvanceJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierAdvance);
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $refundId, $amount, $paymentMethodAccountId,
            $date, $description, $advanceAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Supplier advance refund',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'supplier_advance_refund',
                'journal_code' => JournalCode::fromSourceType('supplier_advance_refund')->value,
                'source_id' => $refundId,
            ]);

            // Debit: Bank/Cash (money returned to company)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Refund from supplier',
                'line_order' => 0,
            ]);

            // Credit: Supplier Advance (reduce the advance asset)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $advanceAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Supplier advance reversed',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            // postEntryNow tolerates a null actor (posted_by stays null); the
            // supplier-refund flow always supplies one in practice.
            $this->postEntryNow($entry, $user, $currencyCode);
        } elseif ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create the GL reversal for a REFUND of a customer payment received
     * (Treasury spine, Task 18). Mirrors {@see createPaymentReceivedJournalEntry}
     * with the legs flipped:
     *   Debit:  Accounts Receivable (partner-tagged) — the receivable re-opens
     *   Credit: Bank/Cash — money leaves the till, back to the customer
     *
     * source_type is 'customer_payment_refund' and source_id is the REFUND
     * payment row id (not the original), so multiple partial refunds of one
     * original payment each get their own entry without colliding on
     * (source_type, source_id).
     */
    public function createPaymentRefundJournalEntry(
        string $companyId,
        string $partnerId,
        string $refundPaymentId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createPaymentRefundJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);

        // Actor-nullable (reconciliation-readiness Fix 3): mirror
        // reverseSupplierAdvanceJournalEntry — resolve the poster when supplied,
        // tolerate its absence. A refund whose actor can't be resolved (automated
        // refund, unresolvable posted_by) STILL posts the reversal via
        // postEntryNow (which tolerates a null poster), so the cash movement it
        // backs always carries a linked, POSTED journal entry (spine §9.2).
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $refundPaymentId, $amount, $paymentMethodAccountId,
            $date, $description, $receivableAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Customer payment refund',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'customer_payment_refund',
                'journal_code' => JournalCode::fromSourceType('customer_payment_refund')->value,
                'source_id' => $refundPaymentId,
            ]);

            // Debit: Accounts Receivable (with partner for subledger) — re-open it
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Receivable reinstated (refund)',
                'line_order' => 0,
            ]);

            // Credit: Bank/Cash — money returned to the customer
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Payment refunded',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            // postEntryNow tolerates a null actor (posted_by stays null); this is
            // what guarantees the reversal is POSTED even for an unresolvable actor.
            $this->postEntryNow($entry, $user, $currencyCode);
        } elseif ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create journal entry for supplier invoice (purchase).
     *
     * Debit: Expense/Asset account
     * Debit: VAT Deductible (if applicable)
     * Credit: Accounts Payable (with partner for subledger)
     */
    public function createSupplierInvoiceJournalEntry(
        string $companyId,
        string $partnerId,
        string $invoiceId,
        string $totalAmount,
        string $netAmount,
        string $vatAmount,
        string $expenseAccountId,
        \DateTimeInterface $date,
        User $user,
        ?string $description = null,
        ?string $currencyCode = null
    ): JournalEntry {
        $payableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);
        $vatAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::VatDeductible);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $invoiceId, $totalAmount, $netAmount, $vatAmount,
            $expenseAccountId, $date, $description, $payableAccount, $vatAccount, $user
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Supplier invoice',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'supplier_invoice',
                'journal_code' => JournalCode::fromSourceType('supplier_invoice')->value,
                'source_id' => $invoiceId,
            ]);

            $lineOrder = 0;

            // Debit: Expense/Asset account
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expenseAccountId,
                'partner_id' => null,
                'debit' => $netAmount,
                'credit' => '0',
                'description' => 'Purchase expense/asset',
                'line_order' => $lineOrder++,
            ]);

            // Debit: VAT Deductible (if applicable)
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($vatAmount, '0', $this->scale()) > 0) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $vatAccount->id,
                    'partner_id' => null,
                    'debit' => $vatAmount,
                    'credit' => '0',
                    'description' => 'VAT deductible',
                    'line_order' => $lineOrder++,
                ]);
            }

            // Credit: Accounts Payable (with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payableAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $totalAmount,
                'description' => 'Supplier payable',
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });

        $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);

        return $entry;
    }

    /**
     * Create journal entry for payment to supplier.
     *
     * Debit: Accounts Payable (with partner for subledger)
     * Credit: Bank/Cash
     */
    public function createSupplierPaymentJournalEntry(
        string $companyId,
        string $partnerId,
        string $paymentId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        User $user,
        ?string $description = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createSupplierPaymentJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $payableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $paymentId, $amount, $paymentMethodAccountId,
            $date, $description, $payableAccount, $user
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Supplier payment',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'supplier_payment',
                'journal_code' => JournalCode::fromSourceType('supplier_payment')->value,
                'source_id' => $paymentId,
            ]);

            // Debit: Accounts Payable (with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payableAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Payable cleared',
                'line_order' => 0,
            ]);

            // Credit: Bank/Cash
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Payment to supplier',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, $currencyCode);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create the settlement journal entry that pays down the accounts-payable
     * liability booked by {@see createFromExpense()} for an UNPAID expense.
     *
     * Mirrors that AP booking EXACTLY — same SupplierPayable account, same
     * (nullable) partner tag — so the two entries net to zero on the AP
     * subledger. Unlike {@see createSupplierPaymentJournalEntry()} this accepts
     * a NULL partner, because `documents.partner_id` is nullable and a
     * petty-cash / anonymous-vendor expense books its AP line with no partner.
     *
     *   Dr SupplierPayable (401, partner = nullable) = amount
     *   Cr Cash/Bank                                 = amount
     */
    public function createExpenseSettlementJournalEntry(
        string $companyId,
        ?string $partnerId,
        string $expenseId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        User $user,
        ?string $description = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createExpenseSettlementJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $payableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $expenseId, $amount, $paymentMethodAccountId,
            $date, $description, $payableAccount, $user
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $user->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Expense settlement',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'expense_settlement',
                'journal_code' => JournalCode::fromSourceType('expense_settlement')->value,
                'source_id' => $expenseId,
            ]);

            // Debit: Accounts Payable — partner nullable, mirroring the AP booking.
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payableAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Payable cleared',
                'line_order' => 0,
            ]);

            // Credit: Bank/Cash — money left the treasury repository.
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Expense settlement payment',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, $currencyCode);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create + post the GL entry for a manual repository (cash) adjustment —
     * count-variance / correction / theft-loss (Treasury spine Task 23).
     *
     * The 658/758 "payment tolerance" purposes ({@see SystemAccountPurpose::PaymentToleranceExpense}
     * / {@see SystemAccountPurpose::PaymentToleranceIncome}) are reused as the
     * cash-variance account rather than minting a new SystemAccountPurpose — a
     * manual cash-count discrepancy is the same class of "small unexplained
     * monetary gap" those tolerance accounts already model, and they are
     * literally the 658/758-family purposes (see the enum's own inline
     * comments). A company must have these purposes assigned in its chart of
     * accounts (findByPurposeOrFail throws otherwise).
     *
     *   direction OUT (cash short — a loss): Dr PaymentToleranceExpense (658) / Cr Cash
     *   direction IN  (cash over — a gain):   Dr Cash / Cr PaymentToleranceIncome (758)
     *
     * Always posts SYNCHRONOUSLY in the caller's transaction (never
     * AfterCommit) — the spine's recon-readiness invariant requires every
     * cash movement to carry a non-null journal_entry_id at the instant the
     * movement row is written, so the caller can pass $entry->id into
     * MovementIntent immediately after this returns.
     */
    public function createRepositoryAdjustmentJournalEntry(
        string $companyId,
        string $tenantId,
        string $adjustmentId,
        string $repositoryGlAccountId,
        MovementDirection $direction,
        string $amount,
        \DateTimeInterface $date,
        User $user,
        string $description,
        ?string $currencyCode = null,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('createRepositoryAdjustmentJournalEntry: requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $varianceAccount = $direction === MovementDirection::Out
            ? $this->getAccountByPurpose($companyId, SystemAccountPurpose::PaymentToleranceExpense)
            : $this->getAccountByPurpose($companyId, SystemAccountPurpose::PaymentToleranceIncome);

        $entry = DB::transaction(function () use (
            $companyId, $tenantId, $adjustmentId, $repositoryGlAccountId, $direction, $amount, $date, $description, $varianceAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'repository_adjustment',
                'journal_code' => JournalCode::fromSourceType('repository_adjustment')->value,
                'source_id' => $adjustmentId,
            ]);

            if ($direction === MovementDirection::Out) {
                // Dr variance expense (cash short) / Cr cash — money "left"
                // the repository to cover the shortfall.
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $varianceAccount->id,
                    'partner_id' => null,
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => 'Cash count variance (short)',
                    'line_order' => 0,
                ]);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $repositoryGlAccountId,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => 'Repository adjustment',
                    'line_order' => 1,
                ]);
            } else {
                // Dr cash / Cr variance income (cash over).
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $repositoryGlAccountId,
                    'partner_id' => null,
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => 'Repository adjustment',
                    'line_order' => 0,
                ]);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $varianceAccount->id,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => 'Cash count variance (over)',
                    'line_order' => 1,
                ]);
            }

            return $entry->load('lines');
        });

        $this->postEntryNow($entry, $user, $currencyCode);

        return $entry;
    }

    /**
     * Create journal entry for customer payment received.
     *
     * Standard payment against an invoice:
     * Debit: Bank/Cash
     * Credit: Accounts Receivable (with partner for subledger)
     */
    public function createPaymentReceivedJournalEntry(
        string $companyId,
        string $partnerId,
        string $paymentId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        ?string $description = null,
        ?User $user = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createPaymentReceivedJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $paymentId, $amount, $paymentMethodAccountId,
            $date, $description, $receivableAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            // Get tenant_id from company
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Customer payment received',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'customer_payment',
                'journal_code' => JournalCode::fromSourceType('customer_payment')->value,
                'source_id' => $paymentId,
            ]);

            // Debit: Bank/Cash
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Payment received',
                'line_order' => 0,
            ]);

            // Credit: Accounts Receivable (with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Receivable cleared',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction && $user === null) {
            throw new \LogicException('createPaymentReceivedJournalEntry: synchronous in-transaction GL posting requires an actor ($user); refusing to return an unposted Draft into an atomic money-movement flow.');
        }

        if ($user !== null) {
            if ($mode === PostingMode::SynchronousInTransaction) {
                $this->postEntryNow($entry, $user, $currencyCode);
            } else {
                $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
            }
        } else {
            // Actor could not be resolved (e.g. an offline-authored ACCOUNT_PAYMENT
            // whose cashier is not a resolvable company member). The GL consequence
            // (Dr Bank / Cr Accounts-Receivable) is deterministic and independent of
            // who posted it, so seal it as a SYSTEM-generated POSTED entry rather
            // than leaving an unposted Draft that the caller would then link to a
            // cash movement — which the treasury reconcile freezes on (Task 24 Fix
            // A). AfterCommit only (the Synchronous + null-actor combination is
            // refused above).
            $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create journal entry for payment tolerance write-off.
     *
     * For underpayment (customer pays slightly less):
     * Debit: Payment Tolerance Expense
     * Credit: Accounts Receivable (with partner for subledger)
     *
     * For overpayment (customer pays slightly more):
     * Debit: Accounts Receivable (adjustment - with partner for subledger)
     * Credit: Payment Tolerance Income
     */
    public function createPaymentToleranceJournalEntry(
        string $companyId,
        string $partnerId,
        string $documentId,
        string $amount,
        string $type, // 'underpayment' or 'overpayment'
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): JournalEntry {
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $writeoffPurpose = $type === 'underpayment'
            ? SystemAccountPurpose::PaymentToleranceExpense
            : SystemAccountPurpose::PaymentToleranceIncome;
        $writeoffAccount = $this->getAccountByPurpose($companyId, $writeoffPurpose);

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $documentId, $amount, $type,
            $date, $description, $receivableAccount, $writeoffAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            // Get tenant_id from company
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? "Payment tolerance write-off ({$type})",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'payment_tolerance',
                'journal_code' => JournalCode::fromSourceType('payment_tolerance')->value,
                'source_id' => $documentId,
            ]);

            if ($type === 'underpayment') {
                // Underpayment: expense absorbs the difference
                // Dr. Tolerance Expense, Cr. AR
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $writeoffAccount->id,
                    'partner_id' => null,
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => 'Underpayment tolerance expense',
                    'line_order' => 0,
                ]);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $receivableAccount->id,
                    'partner_id' => $partnerId,
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => 'AR reduced by tolerance',
                    'line_order' => 1,
                ]);
            } else {
                // Overpayment: income from rounding in our favor
                // Dr. AR (adjustment), Cr. Tolerance Income
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $receivableAccount->id,
                    'partner_id' => $partnerId,
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => 'Overpayment tolerance adjustment',
                    'line_order' => 0,
                ]);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $writeoffAccount->id,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => 'Overpayment tolerance income',
                    'line_order' => 1,
                ]);
            }

            return $entry->load('lines');
        });

        if ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Create journal entry to clear customer advance against accounts receivable.
     *
     * When a sales order with prepayments is converted to an invoice,
     * this clears the advance liability and reduces the receivable.
     *
     * Debit: Customer Advances (4191) - clear the liability
     * Credit: Accounts Receivable (411) - reduce the receivable (with partner for subledger)
     *
     * @param  numeric-string  $amount
     */
    public function clearCustomerAdvanceToReceivable(
        string $companyId,
        string $partnerId,
        string $invoiceId,
        string $amount,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): JournalEntry {
        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance);
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $invoiceId, $amount,
            $date, $description, $advanceAccount, $receivableAccount
        ): JournalEntry {
            Partner::query()
                ->whereKey($partnerId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (bccomp($amount, '0', $this->scale()) <= 0) {
                throw new \InvalidArgumentException('Customer advance clearing amount must be positive.');
            }

            $availableAdvance = $this->availableCustomerAdvanceMagnitude($companyId, $partnerId, $advanceAccount->id);

            if (bccomp($amount, $availableAdvance, $this->scale()) > 0) {
                throw new \InvalidArgumentException(
                    "Cannot clear customer advance beyond available balance ({$availableAdvance})."
                );
            }

            $entryNumber = $this->generateEntryNumber($companyId);

            // Get tenant_id from company
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Apply prepayment to invoice',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'prepayment_application',
                'journal_code' => JournalCode::fromSourceType('prepayment_application')->value,
                'source_id' => $invoiceId,
            ]);

            // Debit: Customer Advances (clear the liability)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $advanceAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Clear customer advance',
                'line_order' => 0,
            ]);

            // Credit: Accounts Receivable (reduce the receivable)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Prepayment applied to invoice',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * @return numeric-string
     */
    private function availableCustomerAdvanceMagnitude(string $companyId, string $partnerId, string $advanceAccountId): string
    {
        $advanceBalance = $this->partnerBalanceService->getCustomerAdvanceBalance($companyId, $partnerId);

        if (bccomp($advanceBalance, '0', $this->scale()) >= 0) {
            return '0';
        }

        $postedAdvanceMagnitude = bcsub('0', $advanceBalance, $this->scale());

        /** @var object{debit_total: numeric-string|null}|null $pendingDraftResult */
        $pendingDraftResult = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', JournalEntryStatus::Draft)
            ->where('journal_entries.source_type', 'prepayment_application')
            ->where('journal_lines.account_id', $advanceAccountId)
            ->where('journal_lines.partner_id', $partnerId)
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit), 0) AS TEXT) as debit_total')
            ->first();

        $pendingDraftClearing = $pendingDraftResult->debit_total ?? '0';
        $availableAfterDrafts = bcsub($postedAdvanceMagnitude, $pendingDraftClearing, $this->scale());

        if (bccomp($availableAfterDrafts, '0', $this->scale()) <= 0) {
            return '0';
        }

        return $availableAfterDrafts;
    }

    /**
     * Create journal entry for Cost of Goods Sold (COGS).
     *
     * When an invoice containing physical products is posted, this records
     * the cost of inventory sold:
     * Debit: Cost of Goods Sold (expense - 601 in Tunisia)
     * Credit: Inventory (asset - 37 in Tunisia)
     *
     * @param  array<int, array{product_id: string, quantity: string, unit_cost: string}>  $lineItems
     */
    public function createCOGSEntry(
        string $companyId,
        string $invoiceId,
        string $documentNumber,
        array $lineItems,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $currencyCode = null,
    ): ?JournalEntry {
        // Calculate total COGS.
        //
        // unit_cost carries the perpetual WAC at higher internal precision (6 dp,
        // see WeightedAverageCostService::COST_SCALE). We therefore accumulate
        // quantity × unit_cost at a HIGH working precision (no per-line truncation
        // that would bias the total downward / violate NC 01 §62) and round the
        // TOTAL exactly once, HALF-UP, to the currency scale at this GL posting
        // boundary. The single rounded $totalCOGS is used for BOTH the debit and
        // the credit leg, so the entry balances by construction.
        $scale = $currencyCode !== null
            ? $this->scaleResolver->getScale($currencyCode)
            : $this->scale();
        $working = $scale + 6; // headroom beyond the 6-dp at-rest cost precision
        $totalCOGSPrecise = '0';
        foreach ($lineItems as $item) {
            /** @var numeric-string $quantity */
            $quantity = $item['quantity'];
            /** @var numeric-string $unitCost */
            $unitCost = $item['unit_cost'];
            $lineCost = bcmul($quantity, $unitCost, $working);
            $totalCOGSPrecise = bcadd($totalCOGSPrecise, $lineCost, $working);
        }

        // Round HALF-UP at the posting boundary (presentation/recording boundary).
        $totalCOGS = CurrencyScale::bcround($totalCOGSPrecise, $scale);

        // Don't create entry if no COGS
        if (bccomp($totalCOGS, '0', $scale) <= 0) {
            return null;
        }

        $cogsAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CostOfGoodsSold);
        $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);

        $entry = DB::transaction(function () use (
            $companyId, $invoiceId, $documentNumber, $totalCOGS,
            $date, $description, $cogsAccount, $inventoryAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            // Get tenant_id from company
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? "COGS for Invoice {$documentNumber}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'cogs',
                'journal_code' => JournalCode::fromSourceType('cogs')->value,
                'source_id' => $invoiceId,
            ]);

            // Debit: Cost of Goods Sold (expense increases)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cogsAccount->id,
                'partner_id' => null,
                'debit' => $totalCOGS,
                'credit' => '0',
                'description' => 'Cost of goods sold',
                'line_order' => 0,
            ]);

            // Credit: Inventory (asset decreases)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $totalCOGS,
                'description' => 'Inventory reduction',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $companyId, $currencyCode);

        return $entry;
    }

    /**
     * Create GR-IR journal entry when goods are received against a purchase order.
     *
     * Posts a balanced, hash-chained entry:
     *   Debit:  Inventory (asset increases — stock on hand)
     *   Credit: GoodsReceivedNotInvoiced / 408 (accrued liability until supplier invoice matches)
     *
     * Amount = CurrencyScale::bcround(bcmul($unitCost, $qty, $scale+2), $scale)
     * computed entirely from numeric strings — never floats.
     *
     * No VAT line: under Code de la TVA Art. 9/18, TVA is deductible only
     * at invoice receipt, not at physical delivery.
     *
     * No partner_id on either leg: 408 is an accrual account, not a
     * partner sub-ledger entry (that happens at the 401-payable step).
     *
     * Idempotent: if a GR-IR entry already exists for $movementId (source_type
     * = 'goods_receipt', source_id = $movementId), the method is a no-op and
     * returns null. The StockMovement UUID is unique per receipt line, so a
     * genuine re-delivery produces a new movement and a new entry; only true
     * retries of the same movement are suppressed.
     *
     * @param  string  $receivedQty  Quantity received (4 dp), must be a numeric string
     * @param  string  $unitCost  Landed unit cost from PO line (3+ dp), captured as string before any float cast, must be numeric
     */
    public function createGoodsReceiptGrIrEntry(
        string $companyId,
        string $movementId,
        string $receivedQty,
        string $unitCost,
        string $currency,
    ): ?JournalEntry {
        // Idempotency: skip if already posted for this movement
        if (JournalEntry::where('source_type', 'goods_receipt')->where('source_id', $movementId)->exists()) {
            return null;
        }

        if (! is_numeric($unitCost)) {
            throw new \InvalidArgumentException("GR-IR: unitCost must be a numeric string, got: {$unitCost}");
        }

        if (! is_numeric($receivedQty)) {
            throw new \InvalidArgumentException("GR-IR: receivedQty must be a numeric string, got: {$receivedQty}");
        }

        $scale = $this->scaleResolver->getScale($currency);

        // Compute amount from strings; round HALF-UP once at the posting boundary.
        // $unitCost and $receivedQty are narrowed to numeric-string by the is_numeric() guards above.
        $amount = CurrencyScale::bcround(bcmul($unitCost, $receivedQty, $scale + 2), $scale);

        if (bccomp($amount, '0', $scale) <= 0) {
            return null;
        }

        $inventoryAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::Inventory);
        $grirAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::GoodsReceivedNotInvoiced);

        /** @var JournalEntry|null $entry */
        $entry = DB::transaction(function () use (
            $companyId,
            $movementId,
            $amount,
            $inventoryAccount,
            $grirAccount,
        ): ?JournalEntry {
            // Re-check idempotency inside the transaction to guard concurrent retries
            if (JournalEntry::where('source_type', 'goods_receipt')->where('source_id', $movementId)->exists()) {
                return null;
            }

            $company = Company::findOrFail($companyId);
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => now()->toDateString(),
                'description' => 'Goods Receipt GR-IR accrual',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'goods_receipt',
                'journal_code' => JournalCode::fromSourceType('goods_receipt')->value,
                'source_id' => $movementId,
            ]);

            // Debit: Inventory (asset increases on receipt)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Goods received — inventory',
                'line_order' => 0,
            ]);

            // Credit: GoodsReceivedNotInvoiced / 408 (accrued payable until invoice)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $grirAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Goods received — 408 GR-IR accrual',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($entry === null) {
            return null;
        }

        $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $companyId, $currency);

        return $entry;
    }

    /**
     * Post the supplier-invoice GR-IR clearing entry (C3, option 1 — accrued basis).
     *
     * Clears the 408 accrual B1 raised on goods receipt, at the SAME accrued PO-cost
     * basis (so 408 always zeroes), and routes the price delta + capitalized
     * non-recoverable VAT to Inventory as a BALANCING PLUG. Posts a balanced,
     * hash-chained entry through the IN-TRANSACTION system path so the GL post is
     * atomic with the orchestration's PO-line lock + quantity_invoiced increment
     * (NOT the afterCommit variant).
     *
     *   Dr GoodsReceivedNotInvoiced (408) = accruedHt   (Σ invoiced_qty × PO unit_price)
     *   Dr VatDeductible                  = recoverableVat
     *   Dr PurchaseStampDuty              = timbre  (never to VatDeductible)
     *   Dr/Cr Inventory                   = plug = Cr401 − (Dr408 + DrVAT + DrTimbre)
     *   Cr SupplierPayable (401)          = invoice.total  (partner-tagged)
     *
     * The Inventory leg carries capitalized non-recoverable VAT after the PPV
     * split. Zero legs (VAT, timbre, plug) are omitted.
     *
     * Balance invariant (fail loudly): invoice.total must equal
     *   billedHt + recoverableVat + nonRecoverableVat + timbre.
     *
     * @param  string  $accruedHt  Σ invoiced_qty × PO unit_price (numeric string; high precision ok, rounded here)
     * @param  string  $billedHt  invoice.subtotal (numeric string; Σ line qty × invoice unit_price)
     * @param  string  $recoverableVat  Σ document_lines.recoverable_tax_amount (numeric string)
     * @param  string  $nonRecoverableVat  Σ document_lines.non_recoverable_tax_amount (numeric string)
     * @param  string  $timbre  documents.stamp_duty_amount (numeric string)
     */
    public function createSupplierInvoiceGrIrClearingEntry(
        Document $supplierInvoice,
        string $accruedHt,
        string $billedHt,
        string $recoverableVat,
        string $nonRecoverableVat,
        string $timbre,
    ): JournalEntry {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'createSupplierInvoiceGrIrClearingEntry must run inside the orchestration transaction '
                .'so the GL post is atomic with the PO-line lock and quantity_invoiced increment.'
            );
        }

        foreach (['accruedHt' => $accruedHt, 'billedHt' => $billedHt, 'recoverableVat' => $recoverableVat, 'nonRecoverableVat' => $nonRecoverableVat, 'timbre' => $timbre] as $name => $value) {
            if (! is_numeric($value)) {
                throw new \InvalidArgumentException("SupplierInvoice GR-IR clearing: {$name} must be a numeric string, got: {$value}");
            }
        }

        $companyId = $supplierInvoice->company_id;
        $currency = $supplierInvoice->currency;
        $scale = $this->scaleResolver->getScale($currency);

        /** @var numeric-string $total */
        $total = $supplierInvoice->total ?? '0';

        // Round each leg once, HALF-UP, at the currency scale.
        /** @var numeric-string $accruedHtR */
        $accruedHtR = CurrencyScale::bcround($accruedHt, $scale);
        /** @var numeric-string $billedHtR */
        $billedHtR = CurrencyScale::bcround($billedHt, $scale);
        /** @var numeric-string $recoverableVatR */
        $recoverableVatR = CurrencyScale::bcround($recoverableVat, $scale);
        /** @var numeric-string $nonRecoverableVatR */
        $nonRecoverableVatR = CurrencyScale::bcround($nonRecoverableVat, $scale);
        /** @var numeric-string $timbreR */
        $timbreR = CurrencyScale::bcround($timbre, $scale);
        /** @var numeric-string $totalR */
        $totalR = CurrencyScale::bcround($total, $scale);

        // Balance invariant: total must reconcile to the sum of its economic parts.
        $expectedTotal = bcadd(bcadd(bcadd($billedHtR, $recoverableVatR, $scale), $nonRecoverableVatR, $scale), $timbreR, $scale);
        if (bccomp($expectedTotal, $totalR, $scale) !== 0) {
            throw new \DomainException(sprintf(
                'Supplier invoice [%s] is internally inconsistent: total %s != billedHT %s + recoverableVAT %s + nonRecoverableVAT %s + timbre %s (= %s). Refusing to post an unbalanced GR-IR clearing entry.',
                $supplierInvoice->id, $totalR, $billedHtR, $recoverableVatR, $nonRecoverableVatR, $timbreR, $expectedTotal,
            ));
        }

        // Plug = Cr401 − (Dr408 + DrVAT + DrTimbre). Split invoice-vs-accrual
        // price delta to PPV; leave non-recoverable VAT on Inventory.
        $drKnown = bcadd(bcadd($accruedHtR, $recoverableVatR, $scale), $timbreR, $scale);
        /** @var numeric-string $plug */
        $plug = bcsub($totalR, $drKnown, $scale);
        /** @var numeric-string $priceDelta */
        $priceDelta = bcsub($billedHtR, $accruedHtR, $scale);
        /** @var numeric-string $inventoryPlug */
        $inventoryPlug = bcsub($plug, $priceDelta, $scale);

        $grirAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VatDeductible);
        $stampAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PurchaseStampDuty);
        $inventoryAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::Inventory);
        $ppvExpenseAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $ppvIncomeAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PurchasePriceVarianceIncome);
        $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SupplierPayable);

        $company = Company::findOrFail($companyId);

        $entry = JournalEntry::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $companyId,
            'entry_number' => $this->generateEntryNumber($companyId),
            'entry_date' => $supplierInvoice->document_date,
            'description' => "Supplier invoice {$supplierInvoice->document_number} — GR-IR clearing",
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'supplier_invoice',
            'journal_code' => JournalCode::fromSourceType('supplier_invoice')->value,
            'source_id' => $supplierInvoice->id,
        ]);

        $lineOrder = 0;

        // Dr 408 — clear the accrual at the PO-cost basis.
        if (bccomp($accruedHtR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $grirAccount->id,
                'partner_id' => null,
                'debit' => $accruedHtR,
                'credit' => '0',
                'description' => 'Clear GR-IR (408) at accrued PO cost',
                'line_order' => $lineOrder++,
            ]);
        }

        // Dr VatDeductible — recoverable input VAT only.
        if (bccomp($recoverableVatR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => $recoverableVatR,
                'credit' => '0',
                'description' => 'VAT deductible (input)',
                'line_order' => $lineOrder++,
            ]);
        }

        // Dr PurchaseStampDuty — timbre, never to VatDeductible.
        if (bccomp($timbreR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $stampAccount->id,
                'partner_id' => null,
                'debit' => $timbreR,
                'credit' => '0',
                'description' => 'Purchase stamp duty (timbre)',
                'line_order' => $lineOrder++,
            ]);
        }

        // Dr/Cr PPV — invoice-vs-accrual price delta. Inventory is untouched.
        $priceDeltaCmp = bccomp($priceDelta, '0', $scale);
        if ($priceDeltaCmp > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $ppvExpenseAccount->id,
                'partner_id' => null,
                'debit' => $priceDelta,
                'credit' => '0',
                'description' => 'Purchase price variance (unfavorable)',
                'line_order' => $lineOrder++,
            ]);
        } elseif ($priceDeltaCmp < 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $ppvIncomeAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => bcmul($priceDelta, '-1', $scale),
                'description' => 'Purchase price variance (favorable)',
                'line_order' => $lineOrder++,
            ]);
        }

        // Dr/Cr Inventory — only non-recoverable VAT.
        $inventoryPlugCmp = bccomp($inventoryPlug, '0', $scale);
        if ($inventoryPlugCmp > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => $inventoryPlug,
                'credit' => '0',
                'description' => 'Inventory non-recoverable VAT',
                'line_order' => $lineOrder++,
            ]);
        } elseif ($inventoryPlugCmp < 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => bcmul($inventoryPlug, '-1', $scale),
                'description' => 'Inventory non-recoverable VAT',
                'line_order' => $lineOrder++,
            ]);
        }

        // Cr 401 — billed gross TTC, partner-tagged to the supplier.
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $supplierInvoice->partner_id,
            'debit' => '0',
            'credit' => $totalR,
            'description' => 'Supplier payable (401)',
            'line_order' => $lineOrder,
        ]);

        $entry->load('lines');

        // Defensive: assert debits == credits before posting (the plug guarantees this).
        /** @var numeric-string $debitSum */
        $debitSum = '0';
        /** @var numeric-string $creditSum */
        $creditSum = '0';
        foreach ($entry->lines as $line) {
            $debitSum = bcadd($debitSum, $line->debit, $scale);
            $creditSum = bcadd($creditSum, $line->credit, $scale);
        }
        if (bccomp($debitSum, $creditSum, $scale) !== 0) {
            throw new \DomainException(
                "Supplier invoice GR-IR clearing entry does not balance: debit {$debitSum} != credit {$creditSum}."
            );
        }

        $this->postSystemGeneratedEntryAndDispatchPostedEvent($entry, $companyId, $currency);

        return $entry;
    }

    /**
     * Post a supplier-credit-note reversing entry (D1 — mirrors C3 in reverse).
     *
     * A supplier credit note reduces what we owe the supplier. It posts a balanced,
     * hash-chained NEW entry (never mutating the original invoice — event
     * immutability) that reverses a portion of the supplier invoice:
     *
     *   Dr SupplierPayable (401)  = gross  (HT + recoverable VAT), partner-tagged → REDUCES the payable
     *   Cr VatDeductible          = recoverableVat   (reverses the input VAT we claimed)
     *   Cr/Dr Inventory           = plug = Dr401 − CrVAT   (reverses the inventory cost / price reduction)
     *
     * Timbre is NOT reversed in Phase 1 (it is omitted from the credit-note gross).
     * The Inventory leg is the BALANCING PLUG: it absorbs the HT, any capitalized
     * non-recoverable VAT, and per-leg rounding residue so that debits == credits
     * EXACTLY at the currency scale. Zero legs (VAT, plug) are omitted.
     *
     * Runs through the IN-TRANSACTION system path so the GL post is atomic with the
     * orchestration's PO-line lock + quantity_invoiced decrement (NOT afterCommit).
     *
     * Balance invariant (fail loudly): creditNote.total must equal
     *   ht + recoverableVat + nonRecoverableVat.
     *
     * @param  string  $ht  credit-note subtotal (Σ line qty × price) — numeric string
     * @param  string  $recoverableVat  Σ document_lines.recoverable_tax_amount — numeric string
     * @param  string  $nonRecoverableVat  Σ document_lines.non_recoverable_tax_amount — numeric string (capitalized into the Inventory plug)
     */
    public function createSupplierCreditNoteEntry(
        Document $creditNote,
        string $ht,
        string $recoverableVat,
        string $nonRecoverableVat,
    ): JournalEntry {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'createSupplierCreditNoteEntry must run inside the orchestration transaction '
                .'so the GL post is atomic with the PO-line lock and quantity_invoiced decrement.'
            );
        }

        foreach (['ht' => $ht, 'recoverableVat' => $recoverableVat, 'nonRecoverableVat' => $nonRecoverableVat] as $name => $value) {
            if (! is_numeric($value)) {
                throw new \InvalidArgumentException("Supplier credit note: {$name} must be a numeric string, got: {$value}");
            }
        }

        $companyId = $creditNote->company_id;
        $currency = $creditNote->currency;
        $scale = $this->scaleResolver->getScale($currency);

        /** @var numeric-string $total */
        $total = $creditNote->total ?? '0';

        // Round each leg once, HALF-UP, at the currency scale.
        /** @var numeric-string $htR */
        $htR = CurrencyScale::bcround($ht, $scale);
        /** @var numeric-string $recoverableVatR */
        $recoverableVatR = CurrencyScale::bcround($recoverableVat, $scale);
        /** @var numeric-string $nonRecoverableVatR */
        $nonRecoverableVatR = CurrencyScale::bcround($nonRecoverableVat, $scale);
        /** @var numeric-string $totalR */
        $totalR = CurrencyScale::bcround($total, $scale);

        // Balance invariant: the credit-note gross must reconcile to its economic parts
        // (timbre is intentionally excluded — it is not reversed in Phase 1).
        $expectedTotal = bcadd(bcadd($htR, $recoverableVatR, $scale), $nonRecoverableVatR, $scale);
        if (bccomp($expectedTotal, $totalR, $scale) !== 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] is internally inconsistent: total %s != HT %s + recoverableVAT %s + nonRecoverableVAT %s (= %s). Refusing to post an unbalanced reversing entry.',
                $creditNote->id, $totalR, $htR, $recoverableVatR, $nonRecoverableVatR, $expectedTotal,
            ));
        }

        // Inventory plug = Dr401 − CrVAT. Absorbs the HT, the capitalized
        // non-recoverable VAT, and any rounding residue → debits == credits exactly.
        /** @var numeric-string $plug */
        $plug = bcsub($totalR, $recoverableVatR, $scale);

        $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VatDeductible);
        $inventoryAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::Inventory);
        $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SupplierPayable);

        $company = Company::findOrFail($companyId);

        $entry = JournalEntry::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $companyId,
            'entry_number' => $this->generateEntryNumber($companyId),
            'entry_date' => $creditNote->document_date,
            'description' => "Supplier credit note {$creditNote->document_number} — reversal",
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'supplier_credit_note',
            'journal_code' => JournalCode::fromSourceType('supplier_credit_note')->value,
            'source_id' => $creditNote->id,
        ]);

        $lineOrder = 0;

        // Dr 401 — reduce the supplier payable, partner-tagged.
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $creditNote->partner_id,
            'debit' => $totalR,
            'credit' => '0',
            'description' => 'Supplier payable reduction (401)',
            'line_order' => $lineOrder++,
        ]);

        // Cr VatDeductible — reverse the recoverable input VAT only.
        if (bccomp($recoverableVatR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $recoverableVatR,
                'description' => 'VAT deductible reversal (input)',
                'line_order' => $lineOrder++,
            ]);
        }

        // Cr/Dr Inventory — balancing plug (HT + non-recoverable VAT + rounding).
        $plugCmp = bccomp($plug, '0', $scale);
        if ($plugCmp > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $plug,
                'description' => 'Inventory reversal (HT / non-recoverable VAT)',
                'line_order' => $lineOrder++,
            ]);
        } elseif ($plugCmp < 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => bcmul($plug, '-1', $scale),
                'credit' => '0',
                'description' => 'Inventory reversal (HT / non-recoverable VAT)',
                'line_order' => $lineOrder,
            ]);
        }

        $entry->load('lines');

        // Defensive: assert debits == credits before posting (the plug guarantees this).
        /** @var numeric-string $debitSum */
        $debitSum = '0';
        /** @var numeric-string $creditSum */
        $creditSum = '0';
        foreach ($entry->lines as $line) {
            $debitSum = bcadd($debitSum, $line->debit, $scale);
            $creditSum = bcadd($creditSum, $line->credit, $scale);
        }
        if (bccomp($debitSum, $creditSum, $scale) !== 0) {
            throw new \DomainException(
                "Supplier credit note reversing entry does not balance: debit {$debitSum} != credit {$creditSum}."
            );
        }

        $this->postSystemGeneratedEntryAndDispatchPostedEvent($entry, $companyId, $currency);

        return $entry;
    }

    /**
     * Post a supplier-credit-note reversing entry with a bonus-quantity stock return.
     *
     * Bonus free goods have no supplier-payable reduction. When they are returned
     * after invoice matching, the stock leaves inventory at the current diluted WAC:
     *
     *   Dr PurchaseExpenses      = returned bonus stock value
     *   Cr Inventory             = returned bonus stock value
     *
     * For mixed credit notes, the normal supplier-credit-note legs are included in
     * the same source entry, preserving the source_type/source_id uniqueness model.
     *
     * @param  string  $ht  credit-note subtotal (Σ paid return qty × price) — numeric string
     * @param  string  $recoverableVat  Σ document_lines.recoverable_tax_amount — numeric string
     * @param  string  $nonRecoverableVat  Σ document_lines.non_recoverable_tax_amount — numeric string
     * @param  string  $bonusInventoryValue  returned free stock valued at current WAC — numeric string, scale 6
     */
    public function createSupplierCreditNoteEntryWithBonusReturn(
        Document $creditNote,
        string $ht,
        string $recoverableVat,
        string $nonRecoverableVat,
        string $bonusInventoryValue,
    ): JournalEntry {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'createSupplierCreditNoteEntryWithBonusReturn must run inside the orchestration transaction '
                .'so the GL post is atomic with the PO-line lock and bonus stock issue.'
            );
        }

        foreach (
            [
                'ht' => $ht,
                'recoverableVat' => $recoverableVat,
                'nonRecoverableVat' => $nonRecoverableVat,
                'bonusInventoryValue' => $bonusInventoryValue,
            ] as $name => $value
        ) {
            if (! is_numeric($value)) {
                throw new \InvalidArgumentException("Supplier credit note: {$name} must be a numeric string, got: {$value}");
            }
        }

        $companyId = $creditNote->company_id;
        $currency = $creditNote->currency;
        $scale = $this->scaleResolver->getScale($currency);

        /** @var numeric-string $total */
        $total = $creditNote->total ?? '0';

        /** @var numeric-string $htR */
        $htR = CurrencyScale::bcround($ht, $scale);
        /** @var numeric-string $recoverableVatR */
        $recoverableVatR = CurrencyScale::bcround($recoverableVat, $scale);
        /** @var numeric-string $nonRecoverableVatR */
        $nonRecoverableVatR = CurrencyScale::bcround($nonRecoverableVat, $scale);
        /** @var numeric-string $totalR */
        $totalR = CurrencyScale::bcround($total, $scale);
        /** @var numeric-string $bonusInventoryValueR */
        $bonusInventoryValueR = CurrencyScale::bcround($bonusInventoryValue, $scale);

        $expectedTotal = bcadd(bcadd($htR, $recoverableVatR, $scale), $nonRecoverableVatR, $scale);
        if (bccomp($expectedTotal, $totalR, $scale) !== 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s] is internally inconsistent: total %s != HT %s + recoverableVAT %s + nonRecoverableVAT %s (= %s). Refusing to post an unbalanced reversing entry.',
                $creditNote->id, $totalR, $htR, $recoverableVatR, $nonRecoverableVatR, $expectedTotal,
            ));
        }

        /** @var numeric-string $plug */
        $plug = bcsub($totalR, $recoverableVatR, $scale);

        $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VatDeductible);
        $inventoryAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::Inventory);
        $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SupplierPayable);
        $purchaseExpensesAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PurchaseExpenses);

        $company = Company::findOrFail($companyId);

        $entry = JournalEntry::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $companyId,
            'entry_number' => $this->generateEntryNumber($companyId),
            'entry_date' => $creditNote->document_date,
            'description' => "Supplier credit note {$creditNote->document_number} — reversal",
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'supplier_credit_note',
            'journal_code' => JournalCode::fromSourceType('supplier_credit_note')->value,
            'source_id' => $creditNote->id,
        ]);

        $lineOrder = 0;

        if (bccomp($totalR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payableAccount->id,
                'partner_id' => $creditNote->partner_id,
                'debit' => $totalR,
                'credit' => '0',
                'description' => 'Supplier payable reduction (401)',
                'line_order' => $lineOrder++,
            ]);
        }

        if (bccomp($recoverableVatR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $recoverableVatR,
                'description' => 'VAT deductible reversal (input)',
                'line_order' => $lineOrder++,
            ]);
        }

        $plugCmp = bccomp($plug, '0', $scale);
        if ($plugCmp > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $plug,
                'description' => 'Inventory reversal (HT / non-recoverable VAT)',
                'line_order' => $lineOrder++,
            ]);
        } elseif ($plugCmp < 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => bcmul($plug, '-1', $scale),
                'credit' => '0',
                'description' => 'Inventory reversal (HT / non-recoverable VAT)',
                'line_order' => $lineOrder++,
            ]);
        }

        if (bccomp($bonusInventoryValueR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $purchaseExpensesAccount->id,
                'partner_id' => null,
                'debit' => $bonusInventoryValueR,
                'credit' => '0',
                'description' => 'Returned bonus stock expense',
                'line_order' => $lineOrder++,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $bonusInventoryValueR,
                'description' => 'Returned bonus stock inventory issue',
                'line_order' => $lineOrder++,
            ]);
        }

        $entry->load('lines');

        /** @var numeric-string $debitSum */
        $debitSum = '0';
        /** @var numeric-string $creditSum */
        $creditSum = '0';
        foreach ($entry->lines as $line) {
            $debitSum = bcadd($debitSum, $line->debit, $scale);
            $creditSum = bcadd($creditSum, $line->credit, $scale);
        }
        if (bccomp($debitSum, $creditSum, $scale) !== 0) {
            throw new \DomainException(
                "Supplier credit note bonus return entry does not balance: debit {$debitSum} != credit {$creditSum}."
            );
        }

        $this->postSystemGeneratedEntryAndDispatchPostedEvent($entry, $companyId, $currency);

        return $entry;
    }

    /**
     * Create a GL journal entry for a voucher ledger event.
     *
     * IMPORTANT: Voucher redemption MUST NOT route through createPOSPaymentEntry()
     * — that method credits revenue and would double-count it. This method posts
     * the correct non-taxable liability legs per the §5.2 GL matrix.
     *
     * Per EU Directive 2016/1065, MPV-issued vouchers carry NO VAT lines at the
     * voucher layer. VAT is computed only on the underlying redeeming sale.
     *
     * Phase 1 supports: Issued (from Refund, ExchangeSurplus, Goodwill),
     * Redeemed (voucher redemption GL impact), Voided (voucher write-off),
     * and RoundingAdjustment (cross-currency residual settlement).
     * PartiallyRedeemed is a projection-only event and explicitly throws
     * here — callers must not invoke this method for it.
     *
     * Codex review m2 + R4 (2026-04-30): the administrative ExpiryExtended
     * event (added by VoucherController::extendExpiry) is intentionally
     * NOT in the wired set. It is a metadata-only ledger row with
     * amount = '0.00000', written by the controller via VoucherLedger::forceCreate
     * directly so the ledger keeps its 1:1 audit-of-mutations property
     * without producing a phantom GL journal entry. Likewise, Transferred
     * and Reversed remain Phase 2+ and will fall through to the
     * unwired-event LogicException.
     *
     * GL matrix (Phase 1):
     *   Issued + Refund/ExchangeSurplus → Dr SalesReturnsClearing / Cr VoucherLiability
     *   Issued + Goodwill               → Dr MarketingGoodwillExpense / Cr VoucherLiability
     *   Redeemed                        → Dr VoucherLiability        / Cr Cash (or revenue clearing)
     *   Voided                          → Dr VoucherLiability        / Cr GoodwillExpense (write-off)
     *   RoundingAdjustment              → cross-currency residual leg
     *
     * @throws \LogicException when event is PartiallyRedeemed (no GL impact)
     *                         or not yet wired in Phase 1 (Transferred,
     *                         Reversed, Expired)
     */
    public function createVoucherLedgerEntry(VoucherLedger $ledgerRow, Voucher $voucher): JournalEntry
    {
        if ($ledgerRow->event === VoucherEvent::PartiallyRedeemed) {
            // Projection-only event: no monetary movement at the GL layer.
            // Callers should not invoke this method for PartiallyRedeemed.
            throw new \LogicException(
                'VoucherEvent::PartiallyRedeemed is a projection-only event; '
                .'it carries no GL lines. Do not call createVoucherLedgerEntry() for it.'
            );
        }

        $wiredEvents = [
            VoucherEvent::Issued,
            VoucherEvent::Redeemed,
            VoucherEvent::RoundingAdjustment,
            VoucherEvent::Voided,
        ];

        if (! in_array($ledgerRow->event, $wiredEvents, true)) {
            throw new \LogicException(sprintf(
                'VoucherEvent::%s GL wiring is not yet implemented in Phase 1. '
                .'This ships in Task 15/16.',
                $ledgerRow->event->name
            ));
        }

        $user = User::query()->find($ledgerRow->user_id);

        $entry = DB::transaction(function () use ($ledgerRow, $voucher): JournalEntry {
            $companyId = $voucher->company_id;
            $entryNumber = $this->generateEntryNumber($companyId);
            /** @var numeric-string $rawAmount */
            $rawAmount = $ledgerRow->amount;
            $absAmount = bccomp($rawAmount, '0', $this->scale()) < 0
                ? bcmul($rawAmount, '-1', $this->scale())
                : $rawAmount;

            $entry = JournalEntry::create([
                'tenant_id' => $voucher->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $ledgerRow->occurred_at->toDateString(),
                'description' => $this->describeVoucherEvent($ledgerRow, $voucher),
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'voucher_ledger',
                'journal_code' => JournalCode::fromSourceType('voucher_ledger')->value,
                'source_id' => $ledgerRow->id,
            ]);

            [$debitPurpose, $creditPurpose] = $this->resolveVoucherEventAccounts($ledgerRow, $voucher);

            $debitAccount = $this->getAccountByPurpose($companyId, $debitPurpose);
            $creditAccount = $this->getAccountByPurpose($companyId, $creditPurpose);

            // Debit leg
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccount->id,
                'partner_id' => null,
                'debit' => $absAmount,
                'credit' => '0',
                'description' => $debitPurpose->label(),
                'line_order' => 0,
            ]);

            // Credit leg
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $absAmount,
                'description' => $creditPurpose->label(),
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $voucher->company_id, (string) $ledgerRow->currency);
        } else {
            $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $voucher->company_id, (string) $ledgerRow->currency);
        }

        return $entry;
    }

    /**
     * Resolve the debit and credit system account purposes for a voucher ledger event.
     *
     * GL matrix (Phase 1):
     *   Issued + Refund/ExchangeSurplus → Dr SalesReturnsClearing / Cr VoucherLiability
     *   Issued + Goodwill               → Dr MarketingGoodwillExpense / Cr VoucherLiability
     *   Redeemed                        → Dr VoucherLiability / Cr PosTenderClearing
     *   RoundingAdjustment              → Dr VoucherLiability / Cr RoundingLossExpense
     *
     * No VAT lines on any voucher GL event (EU Directive 2016/1065 MPV rule).
     *
     * NOTE: Voucher tender rows MUST bypass GeneralLedgerService::createPOSPaymentEntry()
     * in the sale receipt finalization path — that method credits revenue and would
     * double-count it. TODO: wire the bypass in Tasks 22-25 (Phase E) / Task 53 (POS frontend).
     *
     * @return array{0: SystemAccountPurpose, 1: SystemAccountPurpose}
     */
    private function resolveVoucherEventAccounts(VoucherLedger $ledgerRow, Voucher $voucher): array
    {
        return match ($ledgerRow->event) {
            VoucherEvent::Issued => match ($voucher->source) {
                VoucherSource::Refund,
                VoucherSource::ExchangeSurplus => [
                    SystemAccountPurpose::SalesReturnsClearing,
                    SystemAccountPurpose::VoucherLiability,
                ],
                VoucherSource::Goodwill => [
                    SystemAccountPurpose::MarketingGoodwillExpense,
                    SystemAccountPurpose::VoucherLiability,
                ],
                default => throw new \LogicException(sprintf(
                    'VoucherSource::%s GL wiring is not yet implemented in Phase 1.',
                    $voucher->source->name
                )),
            },
            // Redemption: paying off the outstanding voucher liability.
            // Credit goes to PosTenderClearing — a transient suspense account that
            // accumulates per shift and will be offset by the POS receipt's revenue entry.
            // Voucher tender MUST bypass createPOSPaymentEntry() (TODO Tasks 22-25 / 53).
            VoucherEvent::Redeemed => [
                SystemAccountPurpose::VoucherLiability,
                SystemAccountPurpose::PosTenderClearing,
            ],
            // Rounding adjustment: write off the sub-minor-unit residual balance.
            VoucherEvent::RoundingAdjustment => [
                SystemAccountPurpose::VoucherLiability,
                SystemAccountPurpose::RoundingLossExpense,
            ],
            // Voided: mirror-reversal of the original issuance GL entry.
            //   Refund/ExchangeSurplus issuance: Dr SalesReturnsClearing / Cr VoucherLiability
            //   → Reversal:                      Dr VoucherLiability / Cr SalesReturnsClearing
            //   Goodwill issuance: Dr MarketingGoodwillExpense / Cr VoucherLiability
            //   → Reversal:        Dr VoucherLiability / Cr MarketingGoodwillExpense
            //
            // The Voided event applies to:
            //   - Auto-void (fraud-detection: 5 failed attempts, spec §4.7)
            //   - Cascade void (credit-note void, spec §4.9)
            // In both cases the source discriminates the credit account.
            VoucherEvent::Voided => match ($voucher->source) {
                VoucherSource::Refund,
                VoucherSource::ExchangeSurplus => [
                    SystemAccountPurpose::VoucherLiability,
                    SystemAccountPurpose::SalesReturnsClearing,
                ],
                VoucherSource::Goodwill => [
                    SystemAccountPurpose::VoucherLiability,
                    SystemAccountPurpose::MarketingGoodwillExpense,
                ],
                default => throw new \LogicException(sprintf(
                    'VoucherSource::%s Voided GL wiring is not yet implemented in Phase 1.',
                    $voucher->source->name
                )),
            },
            default => throw new \LogicException(sprintf(
                'VoucherEvent::%s GL wiring is not yet implemented in Phase 1.',
                $ledgerRow->event->name
            )),
        };
    }

    /**
     * Build a human-readable description for a voucher ledger event.
     */
    private function describeVoucherEvent(VoucherLedger $ledgerRow, Voucher $voucher): string
    {
        return sprintf(
            'Voucher %s — %s (%s)',
            $voucher->code,
            $ledgerRow->event->value,
            $voucher->source->value
        );
    }

    /**
     * Post a journal entry (make it permanent with hash).
     *
     * @param  string|null  $currencyCode  Pass the entity currency when calling
     *                                     from a queued job or console command —
     *                                     there is no CompanyContext bound there,
     *                                     so the no-arg scale resolution throws
     *                                     (precision contract, F-RES-1).
     */
    public function postEntry(JournalEntry $entry, User $user, ?string $currencyCode = null): void
    {
        $this->postEntryWithOptionalActor($entry, $user, $currencyCode);
    }

    private function postEntryWithOptionalActor(JournalEntry $entry, ?User $user, ?string $currencyCode = null): void
    {
        // Preserve historical semantics for every existing (non-spine) caller:
        // seal + persist, then fire the event inline (synchronously).
        $posted = $this->sealAndPersistEntry($entry, $user, $currencyCode);

        event($posted);
    }

    /**
     * Post a journal entry SYNCHRONOUSLY inside the current transaction.
     *
     * Unlike {@see postEntryAndDispatchPostedEventAfterCommit}, which defers the
     * ENTIRE post (status seal + hash + event) to DB::afterCommit when inside a
     * transaction, this seals + persists (status -> Posted, chain_sequence,
     * fiscal_hash) durably-in-transaction and defers ONLY the JournalEntryPosted
     * event to afterCommit. This makes a money movement and its GL posting atomic:
     * they commit or roll back together.
     *
     * Used by the Treasury money-movement spine (PostingMode::SynchronousInTransaction).
     *
     * @param  string|null  $currencyCode  Pass the entity currency when calling
     *                                     from a queued job, console command, or
     *                                     projection — there is no CompanyContext
     *                                     bound there, so no-arg scale resolution
     *                                     throws (precision contract, F-RES-1).
     */
    /**
     * Draft the immutable counter-entry for cancelling a received instrument.
     * The lifecycle service posts it synchronously after holding the instrument
     * row lock, preserving the global lock order.
     *
     * @param  numeric-string  $amount
     */
    public function createInstrumentCancellationEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        ?string $partnerId,
        string $portfolioAccountId,
        string $amount,
        CancellationShape $shape,
        \DateTimeInterface $date,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Instrument cancellation entries require an enclosing transaction.');
        }

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $instrumentId,
            $partnerId,
            $portfolioAccountId,
            $amount,
            $shape,
            $date,
        ): JournalEntry {
            $counterpart = $this->getAccountByPurpose(
                $companyId,
                $shape === CancellationShape::B2b
                    ? SystemAccountPurpose::CustomerReceivable
                    : SystemAccountPurpose::ProductRevenue,
            );
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => 'Instrument receipt cancellation',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'instrument',
                'journal_code' => JournalCode::Effets,
                'source_id' => $instrumentId,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $counterpart->id,
                'partner_id' => $shape === CancellationShape::B2b ? $partnerId : null,
                'debit' => $amount,
                'credit' => '0',
                'description' => $shape === CancellationShape::B2b
                    ? 'Customer receivable restored'
                    : 'POS revenue reversed',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $portfolioAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Instrument portfolio reversed',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }

    public function postEntryNow(JournalEntry $entry, ?User $user, ?string $currencyCode = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('postEntryNow must be called inside a database transaction — synchronous in-transaction posting is only atomic with the caller\'s work (spine BLOCKER-1 / MED-9).');
        }

        $posted = $this->sealAndPersistEntry($entry, $user, $currencyCode);

        // The DB state change above is already durable within the caller
        // transaction. Only the event is a side effect — defer it to afterCommit
        // so listeners never observe an uncommitted (or rolled-back) post.
        DB::afterCommit(function () use ($posted): void {
            event($posted);
        });
    }

    /**
     * Seal + persist a draft journal entry into the fiscal hash chain and RETURN
     * the JournalEntryPosted event (the caller decides when/how to dispatch it).
     *
     * This is the single source of truth for the hash-chain sealing sequence:
     * Draft check -> balance assertion -> getLastChainHash / getNextChainSequence /
     * calculateHash -> $entry->update([...]). The hash inputs, ordering and
     * chain-sequence derivation MUST NOT change here — every posting path funnels
     * through this method so the chain stays byte-identical.
     *
     * NOTE (Task 7): the getLastChainHash + getNextChainSequence reads are
     * serialized per company by a transaction-scoped advisory lock taken just
     * before them (below), closing the concurrent-post chain_sequence race.
     */
    private function sealAndPersistEntry(JournalEntry $entry, ?User $user, ?string $currencyCode = null): JournalEntryPosted
    {
        if ($entry->status !== JournalEntryStatus::Draft) {
            throw new \InvalidArgumentException('Only draft entries can be posted');
        }

        $entry->load('lines');
        /** @var numeric-string $totalDebit */
        $totalDebit = '0';
        /** @var numeric-string $totalCredit */
        $totalCredit = '0';
        /** @var numeric-string $eventTotalDebit */
        $eventTotalDebit = '0';
        /** @var numeric-string $eventTotalCredit */
        $eventTotalCredit = '0';
        $companyCurrencyCode = $this->currencyCodeForCompany($entry->company_id);

        $currencyScale = $this->scaleResolver->getScale($currencyCode ?? $companyCurrencyCode);
        $balanceScale = max(3, $currencyScale);

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $balanceScale);
            $totalCredit = bcadd($totalCredit, $line->credit, $balanceScale);
            $eventTotalDebit = bcadd($eventTotalDebit, $line->debit, $currencyScale);
            $eventTotalCredit = bcadd($eventTotalCredit, $line->credit, $currencyScale);
        }

        if (bccomp($totalDebit, $totalCredit, $balanceScale) !== 0) {
            throw new \InvalidArgumentException(
                "Cannot post unbalanced journal entry: total debit {$totalDebit} does not equal total credit {$totalCredit}."
            );
        }

        // Serialize chain-sequence + hash reads per company via a transaction-scoped
        // advisory lock (released at commit). Concurrent posts to one company would
        // otherwise race on these unlocked max() reads and allocate duplicate
        // chain_sequence — an FEC-sequentiality break (Task 7). This is step 1 of
        // every converged flow: taken BEFORE any payment_repositories row lock, so
        // the global order is always advisory -> repo. The lock is only effective
        // inside an explicit transaction; postEntryNow enforces one, and the legacy
        // autocommit path degrades to a harmless per-statement no-op.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$entry->company_id]);
        }

        // Reject posting into a fiscal period that EXISTS and is CLOSED for the
        // entry date (spine BLOCKER-2). Absence of any period for the date is
        // allowed — period configuration may not be set up yet, and this guard
        // must never brick posting for an unconfigured company. Checked after
        // the advisory lock so the reject is consistent with the same
        // transaction-serialized view used for the chain-sequence allocation.
        if ($this->fiscalPeriodResolver->isDateInClosedPeriod($entry->company_id, $entry->entry_date)) {
            throw new ClosedFiscalPeriodException($entry->company_id, $entry->entry_date->toDateString());
        }

        $previousHash = JournalEntry::getLastChainHash($entry->company_id);
        $chainSequence = JournalEntry::getNextChainSequence($entry->company_id);
        $hash = $this->hashService->calculateHash($entry, $previousHash, $companyCurrencyCode);

        $postedAt = now();

        $entry->update([
            'status' => JournalEntryStatus::Posted,
            'chain_sequence' => $chainSequence,
            'fiscal_hash' => $hash,
            'previous_hash' => $previousHash,
            'posted_at' => $postedAt,
            'posted_by' => $user?->id,
        ]);

        return new JournalEntryPosted(
            entryId: $entry->id,
            tenantId: $entry->tenant_id,
            companyId: $entry->company_id,
            entryNumber: $entry->entry_number,
            totalDebit: $eventTotalDebit,
            totalCredit: $eventTotalCredit,
            postedAt: $postedAt->toIso8601String(),
        );
    }

    /**
     * Create journal entry for a POS cash-sale tolerance write-off.
     *
     * POS receipts are direct-to-revenue (no AR, no partner — walk-in sales).
     * The B2B createPaymentToleranceJournalEntry is partner/AR-shaped and not
     * usable here. This method posts a partner-less mirror:
     *   Dr 658 PaymentToleranceExpense   amount
     *   Cr ProductRevenue                amount
     *
     * VAT is NOT touched — tolerance is a non-VAT accounting loss
     * (see docs/superpowers/specs/2026-04-24-payment-tolerance-design.md §4).
     *
     * @param  numeric-string  $amount
     */
    public function createPOSPaymentToleranceEntry(
        string $companyId,
        string $receiptId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        $toleranceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PaymentToleranceExpense);
        $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);

        return DB::transaction(function () use (
            $companyId,
            $receiptId,
            $amount,
            $date,
            $toleranceAccount,
            $revenueAccount,
        ): JournalEntry {
            $company = Company::findOrFail($companyId);
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => "POS tolerance write-off / Receipt {$receiptId}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_payment_tolerance',
                'journal_code' => JournalCode::fromSourceType('pos_payment_tolerance')->value,
                'source_id' => $receiptId,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $toleranceAccount->id,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'POS cash-sale tolerance write-off',
                'line_order' => 0,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'POS sales revenue (tolerance offset)',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * Create journal entry for POS payment.
     *
     * POS payments are DIRECT TO REVENUE (no AR account).
     * Debit: Cash/Bank Account (from payment repository's GL account)
     * Credit: Revenue Account (ProductRevenue system purpose)
     */
    public function createPOSPaymentEntry(
        Payment $payment,
        Receipt $receipt,
        PaymentRepository $repository
    ): JournalEntry {
        if ($repository->gl_account_id === null) {
            throw new \InvalidArgumentException(
                "Cannot create GL entry: payment repository '{$repository->name}' ({$repository->code}) "
                .'is not linked to a General Ledger account. '
                .'Go to Settings → Treasury → Payment Repositories and assign a GL account to this repository.'
            );
        }

        $entry = DB::transaction(function () use ($payment, $receipt, $repository): JournalEntry {
            $companyId = $payment->company_id;

            // Get revenue account by system purpose
            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);

            $entryNumber = $this->generateEntryNumber($companyId);

            // Get tenant_id from payment
            $entry = JournalEntry::create([
                'tenant_id' => $payment->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $receipt->posted_at,
                'description' => "POS Receipt {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_receipt',
                'journal_code' => JournalCode::fromSourceType('pos_receipt')->value,
                'source_id' => $receipt->id,
            ]);

            // Debit: Cash/Bank Account (from payment repository)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $repository->gl_account_id,
                'partner_id' => null,
                'debit' => $payment->amount,
                'credit' => '0',
                'description' => "POS payment via {$repository->name}",
                'line_order' => 0,
            ]);

            // Credit: Revenue Account
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $payment->amount,
                'description' => 'POS sales revenue',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        return $entry;
    }

    /**
     * Create the GL REVERSAL for a POS refund/void receipt (Treasury spine,
     * Task 21). A refund rides a SALE_RECEIPT fiscal event carrying
     * `invoice_type_code='REFUND'` (or 'VOID') — there is NO separate refund
     * event type. It pays cash OUT of the drawer, so the sale entry a normal
     * receipt of the same tender would post ({@see createPOSPaymentEntry},
     * Dr Cash / Cr Revenue) is REVERSED here — legs flipped:
     *   Debit:  Revenue (ProductRevenue) — revenue is reversed
     *   Credit: Cash/Bank (from payment repository's GL account) — money out
     *
     * This is the pure inverse of the sale entry, so it reuses the EXACT same
     * accounts (guaranteed to exist wherever the sale entry can post) and
     * always balances. A dedicated contra-revenue Sales-Return (709) account
     * exists as a SystemAccountPurpose but has no posting helper yet; routing
     * the debit through it is a Phase-1.5 accounting refinement — until then
     * the symmetric reversal is the correct, unambiguous treatment.
     *
     * `source_type='pos_receipt_refund'` (distinct from the sale entry's
     * `pos_receipt`) so refund reversals are filterable in FEC/reporting;
     * `source_id=$receipt->id` (the refund/Return receipt row). Returned in
     * Draft — the caller posts it via `postEntryNow` inside its transaction,
     * exactly as with the sale entry.
     */
    public function createPOSRefundReversalEntry(
        Payment $payment,
        Receipt $receipt,
        PaymentRepository $repository
    ): JournalEntry {
        if ($repository->gl_account_id === null) {
            throw new \InvalidArgumentException(
                "Cannot create GL entry: payment repository '{$repository->name}' ({$repository->code}) "
                .'is not linked to a General Ledger account. '
                .'Go to Settings → Treasury → Payment Repositories and assign a GL account to this repository.'
            );
        }

        $entry = DB::transaction(function () use ($payment, $receipt, $repository): JournalEntry {
            $companyId = $payment->company_id;

            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);

            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $payment->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $receipt->posted_at,
                'description' => "POS Refund Receipt {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_receipt_refund',
                'journal_code' => JournalCode::fromSourceType('pos_receipt_refund')->value,
                'source_id' => $receipt->id,
            ]);

            // Debit: Revenue Account — the sale revenue is reversed.
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => $payment->amount,
                'credit' => '0',
                'description' => 'POS sales revenue reversed (refund)',
                'line_order' => 0,
            ]);

            // Credit: Cash/Bank Account (from payment repository) — money out.
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $repository->gl_account_id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $payment->amount,
                'description' => "POS refund via {$repository->name}",
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        return $entry;
    }

    /**
     * Create journal entry for a POS account charge.
     *
     * ACCOUNT_CHARGE is customer credit: it increases AR and recognizes sale
     * revenue/VAT without creating Treasury payment rows.
     */
    public function createPOSChargeEntry(CreatePOSChargeJournalEntryCommand $command): JournalEntry
    {
        Company::query()
            ->where('tenant_id', $command->tenantId)
            ->whereKey($command->companyId)
            ->firstOrFail();

        Partner::query()
            ->where('tenant_id', $command->tenantId)
            ->where('company_id', $command->companyId)
            ->whereKey($command->partnerId)
            ->firstOrFail();

        $receivableAccount = $this->getAccountByPurpose($command->companyId, SystemAccountPurpose::CustomerReceivable);
        $revenueAccount = $this->getAccountByPurpose($command->companyId, SystemAccountPurpose::ProductRevenue);
        $vatAccount = $this->isPositive($command->vatTotal, $command->currencyScale)
            ? $this->getAccountByPurpose($command->companyId, SystemAccountPurpose::VatCollected)
            : null;
        $discountAccount = $this->isPositive($command->transactionDiscountAmount, $command->currencyScale)
            ? $this->getAccountByPurpose($command->companyId, SystemAccountPurpose::SalesDiscount)
            : null;

        $entry = DB::transaction(function () use (
            $command,
            $receivableAccount,
            $revenueAccount,
            $vatAccount,
            $discountAccount,
        ): JournalEntry {
            $entry = JournalEntry::create([
                'tenant_id' => $command->tenantId,
                'company_id' => $command->companyId,
                'entry_number' => $this->generateEntryNumber($command->companyId),
                'entry_date' => $command->businessDate,
                'description' => "POS Account Charge {$command->accountChargeUuid}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_account_charge',
                'journal_code' => JournalCode::fromSourceType('pos_account_charge')->value,
                'source_id' => $command->fiscalEventId,
            ]);

            $lineOrder = 0;

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $command->partnerId,
                'debit' => $command->total,
                'credit' => '0',
                'description' => 'POS account charge receivable',
                'line_order' => $lineOrder++,
            ]);

            if ($discountAccount !== null) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $discountAccount->id,
                    'partner_id' => null,
                    'debit' => $command->transactionDiscountAmount,
                    'credit' => '0',
                    'description' => 'POS account charge sales discount',
                    'line_order' => $lineOrder++,
                ]);
            }

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $command->subtotal,
                'description' => 'POS account charge sales revenue',
                'line_order' => $lineOrder++,
            ]);

            if ($vatAccount !== null) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $vatAccount->id,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $command->vatTotal,
                    'description' => 'POS account charge VAT collected',
                    'line_order' => $lineOrder,
                ]);
            }

            $this->partnerBalanceService->refreshPartnerBalance($command->companyId, $command->partnerId);

            return $entry->load('lines.account');
        });

        return $entry;
    }

    /**
     * Create journal entry from a posted expense.
     *
     * Expenses are typically non-fiscal operational documents.
     * Debit: Expense Account (from category or default to GeneralExpense).
     * Credit depends on whether the expense is paid:
     *   - paid   → Cash/Bank Account (based on payment repository type) — money left treasury.
     *   - unpaid → Accounts-Payable liability (SupplierPayable), tracked against the
     *              vendor partner for the AP subledger. NO cash is credited: an unpaid
     *              expense has not moved any money yet (Wave D bug fix).
     */
    public function createFromExpense(Document $expense, User $user, PostingMode $mode = PostingMode::AfterCommit): JournalEntry
    {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createFromExpense: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $entry = DB::transaction(function () use ($expense): JournalEntry {
            $companyId = $expense->company_id;
            $metadata = $expense->expenseMetadata;

            // Determine expense account (from category or default to GeneralExpense)
            $expenseAccountPurpose = SystemAccountPurpose::GeneralExpense;
            if ($metadata?->category?->account_id !== null) {
                // api.accounting.007: tenant+company-scoped Account lookup.
                // The expense object is already tenant-scoped at the caller
                // (createFromExpense receives a fully-loaded Document). Pinning
                // the Account by both tenant_id + company_id of the source
                // expense refuses any cross-tenant account_id smuggled into
                // metadata.category.account_id.
                $expenseAccount = Account::query()
                    ->where('tenant_id', $expense->tenant_id)
                    ->where('company_id', $expense->company_id)
                    ->whereKey($metadata->category->account_id)
                    ->firstOrFail();
            } else {
                $expenseAccount = $this->getAccountByPurpose($companyId, $expenseAccountPurpose);
            }

            // Determine the credit account. A PAID expense credits the Cash/Bank
            // account the money left from; an UNPAID expense has not moved any
            // money yet, so it credits the Accounts-Payable liability instead
            // (Wave D bug fix — previously it credited Cash unconditionally).
            $isPaid = $metadata?->is_paid === true;
            if ($isPaid) {
                // $isPaid === true implies $metadata is non-null (is_paid was read off it).
                $repositoryType = $metadata->paymentRepository !== null ? $metadata->paymentRepository->type : RepositoryType::CashRegister;
                $creditAccount = match ($repositoryType) {
                    RepositoryType::BankAccount => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Bank),
                    default => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Cash),
                };
                $creditPartnerId = null;
                $creditDescription = 'Expense payment';
            } else {
                $creditAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);
                $creditPartnerId = $expense->partner_id;
                $creditDescription = 'Expense payable';
            }

            $entryNumber = $this->generateEntryNumber($companyId);
            $vendorName = $metadata->vendor_name ?? 'General Expense';

            $entry = JournalEntry::create([
                'tenant_id' => $expense->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $metadata->payment_date ?? $expense->document_date,
                'description' => "Expense: {$expense->document_number} - {$vendorName}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'expense',
                'journal_code' => JournalCode::fromSourceType('expense')->value,
                'source_id' => $expense->id,
            ]);

            $lineOrder = 0;

            // Debit: Expense Account
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expenseAccount->id,
                'partner_id' => null, // Expenses typically don't have partner tracking
                'debit' => $expense->total ?? '0',
                'credit' => '0',
                'description' => $vendorName,
                'line_order' => $lineOrder++,
            ]);

            // Credit: Cash/Bank (paid) or Accounts-Payable liability (unpaid)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccount->id,
                'partner_id' => $creditPartnerId,
                'debit' => '0',
                'credit' => $expense->total ?? '0',
                'description' => $creditDescription,
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, (string) $expense->currency);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $expense->company_id, (string) $expense->currency);
        }

        return $entry;
    }

    /**
     * Create journal entry from a posted income (the mirror of createFromExpense).
     *
     * Income is money received into a cash/bank repository against a class-7
     * revenue account.
     * Debit: Cash/Bank Account — the receiving payment repository's GL account
     *        (repository->gl_account_id, NOT account_id). Falls back to the
     *        Cash/Bank system account by repository type when the repository
     *        has no linked GL account, and to Cash when no repository is set.
     * Credit: Income Account — the selected class-7 account
     *        (metadata->income_account_id) or ProductRevenue by default.
     */
    public function createFromIncome(Document $income, User $user, PostingMode $mode = PostingMode::AfterCommit): JournalEntry
    {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createFromIncome: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $entry = DB::transaction(function () use ($income): JournalEntry {
            $companyId = $income->company_id;
            $metadata = $income->incomeMetadata;

            // Determine income account (from selection or default to ProductRevenue).
            if ($metadata?->income_account_id !== null) {
                // Pin the Account by both tenant_id + company_id of the source
                // income to refuse any cross-tenant account_id smuggled into
                // metadata.income_account_id.
                $incomeAccount = Account::query()
                    ->where('tenant_id', $income->tenant_id)
                    ->where('company_id', $income->company_id)
                    ->whereKey($metadata->income_account_id)
                    ->firstOrFail();
            } else {
                $incomeAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            }

            // Determine the receiving cash/bank account. Prefer the repository's
            // linked GL account (gl_account_id — the account_id column is a
            // DIFFERENT, legacy link and must NOT be used here). Fall back to the
            // Cash/Bank system account by repository type, then to Cash.
            $repository = $metadata?->paymentRepository;
            if ($repository?->gl_account_id !== null) {
                $paymentAccount = Account::query()
                    ->where('tenant_id', $income->tenant_id)
                    ->where('company_id', $income->company_id)
                    ->whereKey($repository->gl_account_id)
                    ->firstOrFail();
            } else {
                $repositoryType = $repository !== null ? $repository->type : RepositoryType::CashRegister;
                $paymentAccount = match ($repositoryType) {
                    RepositoryType::BankAccount => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Bank),
                    default => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Cash),
                };
            }

            $entryNumber = $this->generateEntryNumber($companyId);
            $sourceName = $metadata->source_name ?? 'Income';

            $entry = JournalEntry::create([
                'tenant_id' => $income->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $metadata->payment_date ?? $income->document_date,
                'description' => "Income: {$income->document_number} - {$sourceName}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'income',
                'journal_code' => JournalCode::fromSourceType('income')->value,
                'source_id' => $income->id,
            ]);

            $lineOrder = 0;

            // Debit: Cash/Bank Account (money received)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentAccount->id,
                'partner_id' => null,
                'debit' => $income->total ?? '0',
                'credit' => '0',
                'description' => 'Income received',
                'line_order' => $lineOrder++,
            ]);

            // Credit: Income Account (class-7 revenue)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $incomeAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $income->total ?? '0',
                'description' => $sourceName,
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, (string) $income->currency);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $income->company_id, (string) $income->currency);
        }

        return $entry;
    }

    /**
     * @param  array{inventory_total: numeric-string, cogs_total: numeric-string}  $application
     */
    public function createLinkedCostCapitalizationEntry(
        Document $expense,
        DocumentAdditionalCost $cost,
        array $application,
        User $user,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createLinkedCostCapitalizationEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $existing = JournalEntry::query()
            ->where('source_type', 'linked_cost_capitalization')
            ->where('source_id', $cost->id)
            ->first();
        if ($existing !== null) {
            return $existing->load('lines');
        }

        $entry = DB::transaction(function () use ($expense, $cost, $application): JournalEntry {
            $companyId = $expense->company_id;
            $metadata = $expense->expenseMetadata;
            $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);
            $cogsAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CostOfGoodsSold);
            $paymentAccount = $this->expensePaymentAccount($expense);
            $scale = $this->scaleResolver->getScale((string) $expense->currency);
            $inventoryTotal = CurrencyScale::bcformatStrict($application['inventory_total'], $scale);
            $cogsTotal = CurrencyScale::bcformatStrict($application['cogs_total'], $scale);

            $entry = JournalEntry::create([
                'tenant_id' => $expense->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $metadata->payment_date ?? $expense->document_date,
                'description' => "Linked cost capitalization: {$expense->document_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'linked_cost_capitalization',
                'journal_code' => JournalCode::fromSourceType('linked_cost_capitalization')->value,
                'source_id' => $cost->id,
            ]);

            $lineOrder = 0;
            if ($this->isPositive($inventoryTotal, $scale)) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $inventoryAccount->id,
                    'debit' => $inventoryTotal,
                    'credit' => '0',
                    'description' => 'Linked landed cost inventory capitalization',
                    'line_order' => $lineOrder++,
                ]);
            }

            if ($this->isPositive($cogsTotal, $scale)) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $cogsAccount->id,
                    'debit' => $cogsTotal,
                    'credit' => '0',
                    'description' => 'Linked landed cost sold portion',
                    'line_order' => $lineOrder++,
                ]);
            }

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentAccount->id,
                'debit' => '0',
                'credit' => $expense->total ?? '0',
                'description' => 'Linked cost cash payment',
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, (string) $expense->currency);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $expense->company_id, (string) $expense->currency);
        }

        return $entry;
    }

    /**
     * @param  array{inventory_total: numeric-string, cogs_total: numeric-string}  $application
     */
    public function createLinkedCostCapitalizationReversalEntry(
        Document $expense,
        DocumentAdditionalCost $reversalCost,
        array $application,
        User $user,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('createLinkedCostCapitalizationReversalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $existing = JournalEntry::query()
            ->where('source_type', 'linked_cost_capitalization_reversal')
            ->where('source_id', $reversalCost->id)
            ->first();
        if ($existing !== null) {
            return $existing->load('lines');
        }

        $entry = DB::transaction(function () use ($expense, $reversalCost, $application): JournalEntry {
            $companyId = $expense->company_id;
            $metadata = $expense->expenseMetadata;
            $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);
            $cogsAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CostOfGoodsSold);
            $paymentAccount = $this->expensePaymentAccount($expense);
            $scale = $this->scaleResolver->getScale((string) $expense->currency);
            $inventoryTotal = CurrencyScale::bcformatStrict($application['inventory_total'], $scale);
            $cogsTotal = CurrencyScale::bcformatStrict($application['cogs_total'], $scale);

            $entry = JournalEntry::create([
                'tenant_id' => $expense->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => now()->toDateString(),
                'description' => "Linked cost reversal: {$expense->document_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'linked_cost_capitalization_reversal',
                'journal_code' => JournalCode::fromSourceType('linked_cost_capitalization_reversal')->value,
                'source_id' => $reversalCost->id,
            ]);

            $lineOrder = 0;
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentAccount->id,
                'debit' => $expense->total ?? '0',
                'credit' => '0',
                'description' => 'Linked cost cash reversal',
                'line_order' => $lineOrder++,
            ]);

            if ($this->isPositive($inventoryTotal, $scale)) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $inventoryAccount->id,
                    'debit' => '0',
                    'credit' => $inventoryTotal,
                    'description' => 'Linked landed cost inventory reversal',
                    'line_order' => $lineOrder++,
                ]);
            }

            if ($this->isPositive($cogsTotal, $scale)) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $cogsAccount->id,
                    'debit' => '0',
                    'credit' => $cogsTotal,
                    'description' => 'Linked landed cost COGS reversal',
                    'line_order' => $lineOrder,
                ]);
            }

            return $entry->load('lines');
        });

        if ($mode === PostingMode::SynchronousInTransaction) {
            $this->postEntryNow($entry, $user, (string) $expense->currency);
        } else {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $expense->company_id, (string) $expense->currency);
        }

        return $entry;
    }

    private function expensePaymentAccount(Document $expense): Account
    {
        $metadata = $expense->expenseMetadata;
        $repositoryType = $metadata !== null && $metadata->paymentRepository !== null ? $metadata->paymentRepository->type : RepositoryType::CashRegister;

        return match ($repositoryType) {
            RepositoryType::BankAccount => $this->getAccountByPurpose($expense->company_id, SystemAccountPurpose::Bank),
            default => $this->getAccountByPurpose($expense->company_id, SystemAccountPurpose::Cash),
        };
    }

    /**
     * Create journal entry for inventory write-off (expired/damaged batch stock).
     *
     * Debit: Cost of Goods Sold (write-off expense)
     * Credit: Inventory (asset reduction)
     */
    public function createInventoryWriteOffEntry(
        string $companyId,
        string $batchNumber,
        string $productId,
        string $amount,
        MovementReason $reason,
        string $movementId,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): ?JournalEntry {
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            return null;
        }

        $cogsAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CostOfGoodsSold);
        $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);

        $user = null;
        if ($postedByUserId !== null) {
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $batchNumber, $amount, $reason, $movementId,
            $cogsAccount, $inventoryAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => now()->toDateString(),
                'description' => "Batch write-off ({$reason->label()}): {$batchNumber}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'batch_write_off',
                'journal_code' => JournalCode::fromSourceType('batch_write_off')->value,
                'source_id' => $movementId,
            ]);

            // Debit: COGS (write-off expense increases)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cogsAccount->id,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => "Batch write-off: {$batchNumber}",
                'line_order' => 0,
            ]);

            // Credit: Inventory (asset decreases)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Inventory reduction from write-off',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Reverse a posted/draft inventory write-off journal entry (Phase C / C2).
     *
     * Looks up the ORIGINAL write-off entry by its canonical
     * (source_type='batch_write_off', source_id=$originalMovementId) coordinates,
     * then mirror-reverses it: every line is copied with debit/credit FLIPPED, so
     * the reversing entry balances by construction and its amount EQUALS the
     * original exactly (the lines are copied verbatim — no recomputation, no
     * float). The new entry is tagged source_type='batch_write_off_reversal',
     * source_id=$reversalMovementId so it points at the inverse stock movement.
     *
     * Status is MIRRORED:
     *  - If the original is Posted, the reversal is posted synchronously (so the
     *    reversal's fiscal status is deterministic within the caller's
     *    transaction; afterCommit posting would not fire under test transactions).
     *  - If the original is Draft, the reversal is left Draft.
     *
     * ABSENT case: if no original write-off entry exists (e.g. the original
     * write-off amount was non-positive, or the original GL posting was swallowed
     * because GL accounts were unconfigured), this returns NULL and creates
     * nothing — the caller still restores stock.
     */
    public function reverseInventoryWriteOffEntry(
        string $companyId,
        string $originalMovementId,
        string $reversalMovementId,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): ?JournalEntry {
        $original = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $originalMovementId)
            ->with('lines')
            ->first();

        if ($original === null) {
            // ABSENT: no journal entry to mirror — caller restores stock only.
            return null;
        }

        $wasPosted = $original->status === JournalEntryStatus::Posted;

        $user = null;
        if ($wasPosted && $postedByUserId !== null) {
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use ($companyId, $original, $reversalMovementId): JournalEntry {
            $company = Company::findOrFail($companyId);
            $entryNumber = $this->generateEntryNumber($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => now()->toDateString(),
                'description' => "Reversal of batch write-off (orig {$original->entry_number})",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'batch_write_off_reversal',
                'journal_code' => JournalCode::fromSourceType('batch_write_off_reversal')->value,
                'source_id' => $reversalMovementId,
            ]);

            $lineOrder = 0;
            foreach ($original->lines as $line) {
                // Mirror-reverse: swap debit <-> credit, keep account + partner.
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line->account_id,
                    'partner_id' => $line->partner_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Reversal: '.($line->description ?? ''),
                    'line_order' => $lineOrder++,
                ]);
            }

            return $entry->load('lines');
        });

        // Mirror the original's posted status. Post synchronously (not afterCommit)
        // so the reversal status is deterministic in the caller's transaction.
        if ($user !== null) {
            $this->postEntry($entry, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
            $entry->refresh()->load('lines');
        }

        return $entry;
    }

    /**
     * Get account by system purpose - the ONLY way to lookup system accounts.
     *
     * NEVER use hardcoded account codes like '411' or '1200'.
     * ALWAYS use this method with SystemAccountPurpose enum.
     */
    private function getAccountByPurpose(string $companyId, SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($companyId, $purpose);
    }

    /**
     * Non-throwing existence check for a system-purpose account, exposed so
     * callers outside this module (Treasury's RepositoryAdjustmentController —
     * Audit fix 4 / K2) can validate a chart of accounts BEFORE attempting a
     * GL post and return a graceful 422 instead of letting
     * {@see Account::findByPurposeOrFail}'s bare RuntimeException escape as a
     * 500. Does not change findByPurposeOrFail's own throwing semantics for
     * any other caller.
     */
    public function hasAccountForPurpose(string $companyId, SystemAccountPurpose $purpose): bool
    {
        return Account::findByPurpose($companyId, $purpose) !== null;
    }

    private function generateEntryNumber(string $companyId): string
    {
        // Same per-company advisory lock as sealAndPersistEntry so entry-number and
        // chain-sequence allocation share serialization: concurrent creates would
        // otherwise race on this unlocked max()+1 read and allocate a duplicate
        // entry_number (Task 7). Transaction-scoped, released at commit; when
        // running outside a transaction it degrades to a harmless per-statement
        // no-op — every GL create path wraps this in DB::transaction.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$companyId]);
        }

        $year = date('Y');
        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "JE-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr($lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('JE-%s-%06d', $year, $nextNumber);
    }

    /**
     * @param  numeric-string  $amount
     */
    private function isPositive(string $amount, int $scale): bool
    {
        return bccomp($amount, '0', $scale) > 0;
    }
}
