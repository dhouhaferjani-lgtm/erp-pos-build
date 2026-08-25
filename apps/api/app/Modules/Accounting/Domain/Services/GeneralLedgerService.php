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
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
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
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use App\Shared\Contracts\Accounting\PaymentLedgerPartition;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\ExpenseVatSplit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * General Ledger Service for creating and managing journal entries.
 *
 * IMPORTANT: This service uses SystemAccountPurpose for account lookups
 * instead of hardcoded account codes. This enables country-agnostic
 * accounting regardless of the chart of accounts structure.
 */
final class GeneralLedgerService
{
    /**
     * `journal_entries.source_type` for the N-6 repair entry that re-books a
     * customer payment from the receivable (411) to the customer advance (419).
     * Deliberately distinct from `'payment'` so the repair can never be mistaken
     * for the payment it corrects — and so the repair command can detect that a
     * payment has already been repaired.
     */
    public const PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE = 'payment_advance_reclass';

    public const string INVENTORY_MOVEMENT_REVERSAL_SOURCE_TYPE = 'inventory_movement_reversal';

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
     * Debit: VAT Payable (line tax only — see Q1 below)
     * Debit: fiscal-charge expense / Credit: stamp-duty payable (the credit
     *        note's OWN stamp duty, when it carries one — see Q1 below)
     * Credit: Accounts Receivable (total, EX-STAMP — see Q1 below)
     *
     * Q1 (2026-08-07 expert-comptable ruling): "Le compte client (411) ne doit
     * être diminué que du montant crédité hors timbre, et le timbre de l'avoir
     * doit être comptabilisé séparément comme une charge fiscale pour
     * l'entreprise." `documents.stamp_duty_amount` is bundled into `tax_amount`
     * (`tax_amount = line VAT + stamp_duty_amount`) — this method peels it back
     * out so it neither reduces AR nor lands on VatCollected, and books it as a
     * separate self-balancing pair instead.
     * docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md §Q1
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

            // Q1 — `tax_amount` bundles the LINE VAT with the credit note's own
            // `stamp_duty_amount`; only the line VAT belongs on VatCollected.
            //
            // Gate I-2 (2026-08-07): explicit currency, never the bare no-arg
            // `$this->scale()` (rule 19/20) — this method is dead in
            // production today, but it is the one someone would revive, and a
            // no-arg resolve throws outside a bound CompanyContext (e.g. a
            // queued/console caller).
            $scale = $this->scaleResolver->getScaleSafe($creditNote->currency, 3);

            /** @var numeric-string $stampDutyAmount */
            $stampDutyAmount = $creditNote->stamp_duty_amount ?? '0';
            $hasStampDuty = bccomp($stampDutyAmount, '0', $scale) > 0;

            /** @var numeric-string $taxAmount */
            $taxAmount = $creditNote->tax_amount ?? '0';
            /** @var numeric-string $lineVatAmount */
            $lineVatAmount = $hasStampDuty ? bcsub($taxAmount, $stampDutyAmount, $scale) : $taxAmount;

            // Debit: VAT Payable (line tax only) - only if there's tax
            if (bccomp($lineVatAmount, '0', $scale) > 0) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $taxAccount->id,
                    'debit' => $lineVatAmount,
                    'credit' => '0',
                    'description' => 'VAT payable reversal',
                    'line_order' => $lineOrder++,
                ]);
            }

            // Q1 — the credit note's own stamp duty, booked as a separate
            // self-balancing pair: DEBIT the fiscal-charge expense account (the
            // company bears this cost), CREDIT the stamp-payable liability (the
            // company owes the avoir's timbre to the State). Fail-closed via
            // `getAccountByPurpose()`'s existing RuntimeException idiom — this
            // class's established pattern — rather than silently dropping the
            // charge or sealing an unbalanced entry.
            if ($hasStampDuty) {
                $stampExpenseAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PurchaseStampDuty);
                $stampPayableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SalesStampDutyPayable);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $stampExpenseAccount->id,
                    'debit' => $stampDutyAmount,
                    'credit' => '0',
                    'description' => 'Stamp duty (timbre) on credit note — fiscal charge',
                    'line_order' => $lineOrder++,
                ]);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $stampPayableAccount->id,
                    'debit' => '0',
                    'credit' => $stampDutyAmount,
                    'description' => 'Stamp duty (timbre) payable — credit note',
                    'line_order' => $lineOrder++,
                ]);
            }

            // Credit: Accounts Receivable — EX-STAMP only (Q1) - with partner for subledger
            /** @var numeric-string $total */
            $total = $creditNote->total ?? '0';
            /** @var numeric-string $arCreditAmount */
            $arCreditAmount = $hasStampDuty ? bcsub($total, $stampDutyAmount, $scale) : $total;

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $creditNote->partner_id,
                'debit' => '0',
                'credit' => $arCreditAmount,
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
     * N-6 REPAIR — re-book a customer payment that was credited to the
     * receivable (411) when no receivable existed, as a customer advance (419).
     *
     * Dr Customer Receivable (411, partner-tagged) — undo the wrong credit
     * Cr Customer Advance   (419, partner-tagged) — state the real liability
     *
     * WHY A NEW ENTRY AND NOT AN EDIT. The original payment entry is posted and
     * hash-chained; it is never mutated. This is a correcting movement in its own
     * right, keyed on its own `source_type` so it can never be mistaken for the
     * payment it repairs, nor double-applied (the command checks for an existing
     * one before writing).
     *
     * WHY IT IS NOT A `CorrectingEntry` DOCUMENT (owner ruling c4's mechanism).
     * `AccountingService::assertCorrectingEntryIsPostable()` refuses with
     * `targetHasNoLedgerEntry` when the target document's ledger footprint is
     * EMPTY — and a never-posted invoice has exactly that: no entry at all. The
     * document-per-action mechanism structurally cannot express "the invoice was
     * never booked, and the payment against it was mis-booked", because the
     * misbooking lives on the PAYMENT's entry, not the document's. Recorded for
     * the owner in the N-6 handback.
     *
     * @param  numeric-string  $amount
     */
    public function reclassifyCustomerPaymentToAdvance(
        string $companyId,
        string $partnerId,
        string $paymentId,
        string $amount,
        \DateTimeInterface $date,
        string $description,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): JournalEntry {
        $scale = $this->scaleResolver->getScaleSafe($currencyCode, 3);

        if (bccomp($amount, '0', $scale) <= 0) {
            throw new \InvalidArgumentException('A payment reclassification amount must be positive.');
        }

        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance);

        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $paymentId, $amount, $date, $description,
            $receivableAccount, $advanceAccount
        ): JournalEntry {
            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => self::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE,
                'journal_code' => JournalCode::fromSourceType(self::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE)->value,
                'source_id' => $paymentId,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Reverse receivable credit booked without a posted invoice',
                'line_order' => 0,
            ]);

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

        // Synchronous, null-actor safe: the command that drives this runs on the
        // console with no authenticated user, and leaving a DRAFT entry behind
        // would be worse than the misclassification it repairs.
        $this->postEntryNow($entry, $user, $currencyCode);

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

        if ($mode === PostingMode::SynchronousInTransaction) {
            // Worker projections may not resolve the device cashier. The
            // posting primitive supports a null actor and still seals the
            // entry synchronously; actor absence must not weaken atomicity.
            $this->postEntryNow($entry, $user, $currencyCode);
        } elseif ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        } else {
            // Actor could not be resolved (e.g. an offline-authored ACCOUNT_PAYMENT
            // whose cashier is not a resolvable company member). The GL consequence
            // (Dr Bank / Cr Customer-Advance) is deterministic and independent of who
            // posted it, so seal it as a SYSTEM-generated POSTED entry rather than
            // leaving an unposted Draft that the treasury reconcile would freeze on
            // (Task 24 Fix A). This branch is the AfterCommit legacy path;
            // synchronous null-actor projection posting was handled above.
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
     * DPA `DPA-REV2-A` (A-D1) — the GL reversal for a CUSTOMER ADVANCE, and the
     * missing half of the customer-advance idiom.
     *
     * The exact algebraic inverse of {@see createCustomerAdvanceJournalEntry}
     * (which posts Dr cash / Cr CustomerAdvance partner-tagged), and the customer
     * twin of {@see reverseSupplierAdvanceJournalEntry} — with the legs on the
     * sides a customer LIABILITY requires rather than a supplier asset:
     *
     *   Debit:  CustomerAdvance (partner-tagged) — the liability we owed is discharged
     *   Credit: Bank/Cash                        — money goes back to the customer
     *
     * `source_type` is `'customer_advance_refund'` and `source_id` is the
     * REVERSAL payment row id, never the original (D-9). That is what lets a
     * MIXED reversal post two entries — one `'customer_payment_refund'`, one
     * `'customer_advance_refund'` — against the same reversal payment without
     * colliding on `(source_type, source_id)`.
     *
     * JOURNAL CODE: `'customer_advance_refund'` has no arm in
     * {@see JournalCode::fromSourceType()} and therefore falls to `Misc`/OD,
     * matching `'advance'` and `'supplier_advance_refund'`. This is DELIBERATE
     * and must not be "fixed" to `Bank`/BQ: re-classifying the advance family
     * would move EXISTING `'advance'` entries between FEC journals. It is open
     * question OQ-4, pending an expert-comptable ruling. A consequence worth
     * knowing (A-D11): a single mixed reversal therefore splits one economic act
     * across two FEC journals, BQ + OD.
     *
     * @param  numeric-string  $amount
     */
    public function reverseCustomerAdvanceJournalEntry(
        string $companyId,
        string $partnerId,
        string $reversalPaymentId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        // Verbatim from the supplier sibling (:513-515): SynchronousInTransaction
        // posts via postEntryNow so the GL post is atomic with — and its company
        // advisory lock is taken BEFORE — the movement port's repository row lock
        // (global lock order). Refuse to mint a Draft that postEntryNow would orphan.
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('reverseCustomerAdvanceJournalEntry: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance);
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $reversalPaymentId, $amount, $paymentMethodAccountId,
            $date, $description, $advanceAccount
        ): JournalEntry {
            $entryNumber = $this->generateEntryNumber($companyId);

            $company = Company::findOrFail($companyId);

            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $entryNumber,
                'entry_date' => $date,
                'description' => $description ?? 'Customer advance reversal',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'customer_advance_refund',
                'journal_code' => JournalCode::fromSourceType('customer_advance_refund')->value,
                'source_id' => $reversalPaymentId,
            ]);

            // Debit: Customer Advances (discharge the liability - with partner for subledger)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $advanceAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Customer advance reversed',
                'line_order' => 0,
            ]);

            // Credit: Bank/Cash — money returned to the customer
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentMethodAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Advance returned to customer',
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

    /** Draft Dr 401 (partner) / Cr payable-instrument at outbound issue time. */
    public function createOutboundInstrumentIssueEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        ?string $partnerId,
        string $payableAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        $supplierPayable = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);

        return $this->createOutboundInstrumentEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            instrumentId: $instrumentId,
            debitAccountId: $supplierPayable->id,
            debitPartnerId: $partnerId,
            creditAccountId: $payableAccountId,
            creditPartnerId: null,
            amount: $amount,
            date: $date,
            description: 'Outbound instrument issued',
        );
    }

    /** Draft Dr payable-instrument / Cr exact repository GL at clearing. */
    public function createOutboundInstrumentClearingEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        string $payableAccountId,
        string $bankAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        return $this->createOutboundInstrumentEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            instrumentId: $instrumentId,
            debitAccountId: $payableAccountId,
            debitPartnerId: null,
            creditAccountId: $bankAccountId,
            creditPartnerId: null,
            amount: $amount,
            date: $date,
            description: 'Outbound instrument cleared',
        );
    }

    /** Draft Dr exact repository GL / Cr payable-instrument after dishonor. */
    public function createOutboundInstrumentDishonorEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        string $bankAccountId,
        string $payableAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        return $this->createOutboundInstrumentEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            instrumentId: $instrumentId,
            debitAccountId: $bankAccountId,
            debitPartnerId: null,
            creditAccountId: $payableAccountId,
            creditPartnerId: null,
            amount: $amount,
            date: $date,
            description: 'Outbound instrument dishonored',
        );
    }

    /** Draft Dr payable-instrument / Cr 401 (partner) on cancellation. */
    public function createOutboundInstrumentCancellationEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        ?string $partnerId,
        string $payableAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        $supplierPayable = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SupplierPayable);

        return $this->createOutboundInstrumentEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            instrumentId: $instrumentId,
            debitAccountId: $payableAccountId,
            debitPartnerId: null,
            creditAccountId: $supplierPayable->id,
            creditPartnerId: $partnerId,
            amount: $amount,
            date: $date,
            description: 'Outbound instrument cancelled',
        );
    }

    private function createOutboundInstrumentEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        string $debitAccountId,
        ?string $debitPartnerId,
        string $creditAccountId,
        ?string $creditPartnerId,
        string $amount,
        \DateTimeInterface $date,
        string $description,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Outbound instrument entries require an enclosing transaction.');
        }

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $instrumentId,
            $debitAccountId,
            $debitPartnerId,
            $creditAccountId,
            $creditPartnerId,
            $amount,
            $date,
            $description,
        ): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'instrument',
                'journal_code' => JournalCode::Effets,
                'source_id' => $instrumentId,
            ]);

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => $debitPartnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => $description,
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => $creditPartnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => $description,
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
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
     * Create and synchronously post the fee retained by a card acquirer.
     *
     * Dr the payment method's configured fee expense account and Cr the exact
     * GL account backing the statement repository. The statement line id is
     * the immutable source identity used by the matching execution ledger.
     */
    public function createAcquirerFeeJournalEntry(
        string $companyId,
        string $tenantId,
        string $statementLineId,
        string $feeAccountId,
        string $repositoryGlAccountId,
        string $amount,
        \DateTimeInterface $date,
        User $user,
        string $currencyCode,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('createAcquirerFeeJournalEntry: requires an enclosing database transaction.');
        }

        $entry = DB::transaction(function () use (
            $companyId,
            $tenantId,
            $statementLineId,
            $feeAccountId,
            $repositoryGlAccountId,
            $amount,
            $date,
        ): JournalEntry {
            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => 'Card acquirer fee retained from statement settlement',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'acquirer_fee',
                'journal_code' => JournalCode::fromSourceType('acquirer_fee')->value,
                'source_id' => $statementLineId,
            ]);
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $feeAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Card acquirer fee',
                'line_order' => 0,
            ]);
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $repositoryGlAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Acquirer fee deducted from bank settlement',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        $this->postEntryNow($entry, $user, $currencyCode);

        return $entry;
    }

    /**
     * Create the Draft GL entry for an inter-repository cash transfer.
     *
     * Dr destination repository account / Cr source repository account. This
     * factory deliberately never posts: TreasuryMovementService::transfer()
     * posts the entry inside its repository-lock scope, while its caller owns
     * the enclosing transaction that rolls this Draft back on failure.
     */
    public function createRepositoryTransferJournalEntry(
        string $companyId,
        string $tenantId,
        string $transferGroupId,
        string $fromGlAccountId,
        string $toGlAccountId,
        string $amount,
        \DateTimeInterface $date,
        string $description,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('createRepositoryTransferJournalEntry: requires an enclosing database transaction; the caller must be able to roll the Draft back if transfer() fails.');
        }

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $transferGroupId,
            $fromGlAccountId,
            $toGlAccountId,
            $amount,
            $date,
            $description,
        ): JournalEntry {
            $entry = JournalEntry::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'treasury_transfer',
                'journal_code' => JournalCode::fromSourceType('treasury_transfer')->value,
                'source_id' => $transferGroupId,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $toGlAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Inter-repository transfer (in)',
                'line_order' => 0,
            ]);
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $fromGlAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Inter-repository transfer (out)',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
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

        if ($mode === PostingMode::SynchronousInTransaction) {
            // Same worker-safe posture as customer advances: postEntryNow
            // accepts a null actor and keeps the financial write atomic.
            $this->postEntryNow($entry, $user, $currencyCode);
        } elseif ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        } else {
            // Actor could not be resolved (e.g. an offline-authored ACCOUNT_PAYMENT
            // whose cashier is not a resolvable company member). The GL consequence
            // (Dr Bank / Cr Accounts-Receivable) is deterministic and independent of
            // who posted it, so seal it as a SYSTEM-generated POSTED entry rather
            // than leaving an unposted Draft that the caller would then link to a
            // cash movement — which the treasury reconcile freezes on (Task 24 Fix
            // A). This branch is the AfterCommit legacy path; synchronous
            // null-actor projection posting was handled above.
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
        PostingMode $mode = PostingMode::AfterCommit,
    ): JournalEntry {
        // N-6 — `SynchronousInTransaction` exists for the INVOICE POSTING path.
        // Clearing must be atomic with the seal: if the 419 -> 411 entry cannot
        // be written the posting is refused with it, never left half-done with
        // a sealed invoice and a stranded advance. It also closes a real trap in
        // the legacy signature — with a NULL `$postedByUserId` the AfterCommit
        // branch below creates the entry and never posts it, leaving a DRAFT
        // journal entry that no reconcile would ever consume.
        if ($mode === PostingMode::SynchronousInTransaction && DB::transactionLevel() < 1) {
            throw new \LogicException('clearCustomerAdvanceToReceivable: SynchronousInTransaction requires an enclosing database transaction; refusing to create a Draft that postEntryNow would then orphan.');
        }

        // N-6 fix round r1 / treasury gate I-8 (rule 19) — the ceiling arithmetic
        // below used the bare no-arg `$this->scale()`, which resolves from
        // `CompanyContext` and THROWS outside a request. The lane routes a new
        // caller through here from inside `DocumentPostingService::post()`, so
        // the moment posting is driven from a queue or a console command the
        // clearing would throw and — being inside the posting transaction —
        // refuse the posting itself. It also compared a DOCUMENT-currency amount
        // at the COMPANY's scale. The entity currency is already a parameter.
        $scale = $this->scaleResolver->getScaleSafe($currencyCode, 3);

        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance);
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);
        $user = null;
        if ($postedByUserId !== null) {
            /** @var User $user */
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = DB::transaction(function () use (
            $companyId, $partnerId, $invoiceId, $amount,
            $date, $description, $advanceAccount, $receivableAccount, $scale
        ): JournalEntry {
            Partner::query()
                ->whereKey($partnerId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (bccomp($amount, '0', $scale) <= 0) {
                throw new \InvalidArgumentException('Customer advance clearing amount must be positive.');
            }

            // N-3: the helper now takes an explicit scale. This caller already
            // holds the Partner lock and already resolved the advance account
            // above, so it keeps using the private helper directly rather than
            // re-resolving through availableCustomerAdvance().
            $availableAdvance = $this->availableCustomerAdvanceMagnitude($companyId, $partnerId, $advanceAccount->id, $scale);

            if (bccomp($amount, $availableAdvance, $scale) > 0) {
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

        if ($mode === PostingMode::SynchronousInTransaction) {
            // Null-actor safe: the GL consequence of clearing an advance is
            // deterministic and independent of who triggered it, and posting
            // runs from `DocumentPostingService::post()` which has no actor of
            // its own.
            //
            // R2-F5 — NO ORPHAN DRAFT MAY SURVIVE A FAILED POST.
            //
            // The entry above was created inside its own `DB::transaction`.
            // Under an enclosing transaction that is a SAVEPOINT which has
            // already been released, so if `postEntryNow()` throws and the
            // CALLER catches it (the converter downgrades a non-balance failure
            // to a payload note), the draft entry stays durable — a journal
            // entry that discharges nothing, that no reconcile consumes, and
            // that nothing links to, since the caller returns before recording
            // `advance_journal_entry_id`. That is the same orphan-Draft class
            // F-3 just closed, minus the marker that would let anyone find it.
            //
            // Deleting it here fixes it for EVERY caller rather than asking each
            // one to clean up: the entry is still DRAFT (unchained), so
            // `JournalEntryObserver::deleting()` permits it, and a chained entry
            // would refuse — which is the correct direction. The original
            // exception is always re-thrown; the cleanup never masks it.
            try {
                $this->postEntryNow($entry, $user, $currencyCode);
            } catch (\Throwable $postFailure) {
                try {
                    // R3 (treasury gate r3) — DELETE THROUGH THE MODELS, AND
                    // PROVE THE ENTRY IS STILL A DRAFT FIRST.
                    //
                    // `$entry->lines()->delete()` is a BUILDER mass-delete: it
                    // emits one `DELETE ... WHERE journal_entry_id = ?` and fires
                    // NO model events, so `JournalLineObserver::deleting()` —
                    // the guard that refuses to remove a line of a chained entry
                    // — never runs. A probe confirmed it succeeds against a
                    // POSTED, hash-chained entry. `$entry->delete()` below IS a
                    // model delete and its own observer does fire, so the entry
                    // header was protected while its lines were not: exactly the
                    // wrong half.
                    //
                    // The re-read is the belt: `$entry` is the in-memory object
                    // that failed to post, and this cleanup runs on the failure
                    // path, so the row is re-read and its status asserted before
                    // anything is removed. Not-Draft means something else posted
                    // it between the failure and here — refuse and let the outer
                    // catch log it, rather than delete a chained entry through a
                    // path that was only ever meant to remove a stillborn draft.
                    /** @var JournalEntry|null $reread */
                    $reread = JournalEntry::query()->find($entry->id);

                    if (! $reread instanceof JournalEntry) {
                        throw new \RuntimeException(
                            "clearing entry {$entry->id} vanished before cleanup could remove it."
                        );
                    }

                    if ($reread->status !== JournalEntryStatus::Draft) {
                        throw new \RuntimeException(
                            "refusing to clean up clearing entry {$reread->entry_number}: it is "
                            ."{$reread->status->value}, not a draft, so it is no longer this path's to remove."
                        );
                    }

                    // Model deletes, one per line, so the observer fires on each.
                    foreach ($reread->lines()->get() as $line) {
                        $line->delete();
                    }

                    $reread->delete();
                } catch (\Throwable $cleanupFailure) {
                    Log::warning('Could not remove the unposted customer-advance clearing entry', [
                        'entry_id' => $entry->id,
                        'entry_number' => $entry->entry_number,
                        'company_id' => $companyId,
                        'post_failure' => $postFailure->getMessage(),
                        'cleanup_failure' => $cleanupFailure->getMessage(),
                    ]);
                }

                throw $postFailure;
            }
        } elseif ($user !== null) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * DPA `DPA-REV2-A` (A6, gate finding I-2) — the UNCONSUMED customer-advance
     * balance a partner holds, at the ENTITY currency's scale.
     *
     * This is the ceiling the reversal lane refuses against (A-D3): reversing an
     * advance that has already been applied to an invoice would drive the
     * `CustomerAdvance` liability negative and re-credit cash the customer
     * consumed as goods.
     *
     * **POOL-LEVEL SEMANTICS (m-1), stated so it is not re-raised as a bug.**
     * Consumption is partner-pool-level with no back-link to the funding payment
     * (`clearCustomerAdvanceToReceivable()` posts with
     * `source_id = the INVOICE id`), so this is a ceiling on the PARTNER'S POOL,
     * not on any individual advance. Reversing advance P1 — even a fully consumed
     * one — therefore passes if some other advance P2 for the same partner is
     * unconsumed, draining P2's liability instead. That is economically
     * defensible: the liability is per-partner by construction, and the fail
     * direction is safe — it can never drive the liability negative.
     *
     * The figure is posted balance NET of pending `prepayment_application`
     * DRAFTS, so an in-flight order→invoice conversion cannot be double-spent.
     *
     * **N-3 — this replaced `availableCustomerAdvanceMagnitude(..., string
     * $advanceAccountId)`.** The account id parameter is gone: the account is
     * resolved INSIDE from `SystemAccountPurpose::CustomerAdvance`, so no caller
     * can pass the wrong one.
     *
     * **Rule 19 / I-2:** every comparison and subtraction is at
     * `getScale($currency)` for the ENTITY currency passed in — never the bare
     * no-arg `$this->scale()` this method used to use, which throws outside
     * request context (queued jobs, console commands).
     *
     * **OQ-7 is MOOT at the data level — see the task report.** The plan offered
     * "add a currency predicate to the balance query" or "refuse a
     * multi-currency partner". Neither is needed and the first is not even
     * implementable: `journal_entries` and `journal_lines` carry **no currency
     * column at all**. All GL for a company is denominated in that company's
     * `currency`, so a partner cannot hold advances in two currencies within one
     * company's ledger. The currency argument therefore governs SCALE only,
     * which is exactly the defect I-2 actually found.
     *
     * @param  string  $currency  the ENTITY currency (rule 19 — never resolved implicitly)
     * @return numeric-string
     */
    public function availableCustomerAdvance(string $companyId, string $partnerId, string $currency): string
    {
        $scale = $this->scaleResolver->getScale($currency);
        $advanceAccountId = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance)->id;

        return $this->availableCustomerAdvanceMagnitude($companyId, $partnerId, $advanceAccountId, $scale);
    }

    /**
     * @param  int  $scale  ENTITY-currency scale, supplied by the caller (rule 19)
     * @return numeric-string
     */
    private function availableCustomerAdvanceMagnitude(string $companyId, string $partnerId, string $advanceAccountId, int $scale): string
    {
        $advanceBalance = $this->partnerBalanceService->getCustomerAdvanceBalance($companyId, $partnerId);

        if (bccomp($advanceBalance, '0', $scale) >= 0) {
            return '0';
        }

        $postedAdvanceMagnitude = bcsub('0', $advanceBalance, $scale);

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
        $availableAfterDrafts = bcsub($postedAdvanceMagnitude, $pendingDraftClearing, $scale);

        if (bccomp($availableAfterDrafts, '0', $scale) <= 0) {
            return '0';
        }

        return $availableAfterDrafts;
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
     * ...and, since DPA V8, that is only HALF of what the sub-ledger does. The
     * exit is followed by a WAC UN-DILUTION that puts `wac_undilution_applied`
     * back onto the surviving units, so the sub-ledger's net drop is
     * `q × WAC − applied`, not `q × WAC`. A compensating pair restores the
     * identity (stock-GL gate P1-1):
     *
     *   Dr Inventory             = wac_undilution_applied
     *   Cr PurchaseExpenses      = wac_undilution_applied
     *
     * Net effect: Inventory falls by exactly what the sub-ledger says left, and
     * PurchaseExpenses carries only the value genuinely FORGONE. Booking the
     * gross pair and the compensating pair separately (rather than one netted
     * leg) keeps the physical movement and the costing correction independently
     * auditable — and keeps the entry non-empty when they cancel out, which they
     * very nearly do whenever the returned unit's value is fully restorable.
     *
     * For mixed credit notes, the normal supplier-credit-note legs are included in
     * the same source entry, preserving the source_type/source_id uniqueness model.
     *
     * @param  string  $ht  credit-note subtotal (Σ paid return qty × price) — numeric string
     * @param  string  $recoverableVat  Σ document_lines.recoverable_tax_amount — numeric string
     * @param  string  $nonRecoverableVat  Σ document_lines.non_recoverable_tax_amount — numeric string
     * @param  string  $bonusInventoryValue  returned free stock valued at current WAC — numeric string, scale 6
     * @param  string  $bonusUndilutionApplied  Σ wac_undilution_applied on the note's bonus lines — numeric string, scale 6
     */
    public function createSupplierCreditNoteEntryWithBonusReturn(
        Document $creditNote,
        string $ht,
        string $recoverableVat,
        string $nonRecoverableVat,
        string $bonusInventoryValue,
        string $bonusUndilutionApplied = '0',
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
                'bonusUndilutionApplied' => $bonusUndilutionApplied,
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
        /** @var numeric-string $bonusUndilutionAppliedR */
        $bonusUndilutionAppliedR = CurrencyScale::bcround($bonusUndilutionApplied, $scale);

        // The un-dilution can only ever put back value that left; more than that
        // would mean the compensating pair is inventing inventory, which must
        // fail loudly rather than post a plausible-looking wrong entry.
        if (bccomp($bonusUndilutionAppliedR, $bonusInventoryValueR, $scale) > 0) {
            throw new \DomainException(sprintf(
                'Supplier credit note [%s]: wac_undilution_applied %s exceeds the returned bonus '
                .'stock value %s. The un-dilution cannot restore more value than the units carried out.',
                $creditNote->id, $bonusUndilutionAppliedR, $bonusInventoryValueR,
            ));
        }

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

        // P1-1 compensating pair: the un-dilution's value increase, which the
        // sub-ledger has already applied to the surviving units. Without these
        // two legs GL relieves Inventory by the gross exit while the sub-ledger
        // relieves it by the net, and the gap never closes.
        if (bccomp($bonusUndilutionAppliedR, '0', $scale) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $inventoryAccount->id,
                'partner_id' => null,
                'debit' => $bonusUndilutionAppliedR,
                'credit' => '0',
                'description' => 'Bonus return WAC un-dilution (value restored to remaining stock)',
                'line_order' => $lineOrder++,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $purchaseExpensesAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $bonusUndilutionAppliedR,
                'description' => 'Bonus return WAC un-dilution (expense not incurred)',
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

        // Rule 19/20 — LEDGER row C-5. This method is reached from
        // `PosCoreReceiptProjection::redeemVouchers()` via
        // `VoucherRedemptionService::redeem()`, i.e. from
        // `ApplyFiscalEventProjectionJob`, which binds NO `CompanyContext`. The
        // bare no-arg `$this->scale()` this used to call therefore threw
        // `UnboundCompanyContextException` (F-RES-1) on a real Horizon worker,
        // and `redeemVouchers()` has no try/catch — the whole SALE_RECEIPT
        // projection rolled back and the job retried forever.
        //
        // The scale must come from the ENTITY currency instead. The ledger row
        // carries the denomination of this very movement and is what the
        // posting calls at the end of this method already use; the voucher's
        // own currency is the authoritative fallback for a row whose currency
        // was never populated.
        //
        // NOTE (mirrors the same tradeoff at `createInventoryWriteOffEntry()`
        // :4794-4799): this is not strictly behaviour-identical for the three
        // request-context callers (`VoucherIssuanceService`,
        // `VoucherLookupService`, `VoucherCascadeService`) — `getScale($code)`
        // reads the static ISO 4217 map, while the no-arg path read the
        // company country's `currency_decimal_places` column, so a country row
        // that overrides the ISO scale now resolves differently here. The only
        // use is the sign test and magnitude flip below, so the observable
        // difference is confined to sub-minor-unit amounts.
        //
        // P3-5: refuse an empty denomination rather than limping on. Both
        // `CurrencyScale::for()` and therefore `getScale()` fall back to
        // DEFAULT_SCALE for an unknown code (`CurrencyScale:64`), so an empty
        // string would silently pick a scale instead of failing — the exact
        // silent-wrong-scale the precision contract exists to prevent. Fail the
        // way `currencyCodeForCompany()` above does.
        $currencyCode = (string) $ledgerRow->currency !== ''
            ? (string) $ledgerRow->currency
            : (string) $voucher->currency;

        if ($currencyCode === '') {
            throw new \RuntimeException(
                "Cannot resolve currency for voucher {$voucher->id}: "
                .'neither the ledger row nor the voucher carries one.'
            );
        }

        $scale = $this->scaleResolver->getScale($currencyCode);

        $entry = DB::transaction(function () use ($ledgerRow, $voucher, $scale): JournalEntry {
            $companyId = $voucher->company_id;
            $entryNumber = $this->generateEntryNumber($companyId);
            /** @var numeric-string $rawAmount */
            $rawAmount = $ledgerRow->amount;
            $absAmount = bccomp($rawAmount, '0', $scale) < 0
                ? bcmul($rawAmount, '-1', $scale)
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
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $voucher->company_id, $currencyCode);
        } else {
            $this->postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, $voucher->company_id, $currencyCode);
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

    /** Draft the aggregate transit entry for an effet remittance. */
    public function createInstrumentRemittanceEntry(
        string $companyId,
        string $tenantId,
        string $remittanceId,
        string $debitAccountId,
        string $creditAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        return $this->createInstrumentTransitEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            sourceType: 'instrument_remittance',
            sourceId: $remittanceId,
            debitAccountId: $debitAccountId,
            creditAccountId: $creditAccountId,
            partnerId: null,
            amount: $amount,
            date: $date,
            description: 'Instrument remittance',
        );
    }

    public function createInstrumentRepresentationEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        ?string $partnerId,
        string $portfolioAccountId,
        string $amount,
        \DateTimeInterface $date,
    ): JournalEntry {
        $receivable = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable);

        return $this->createInstrumentTransitEntry(
            companyId: $companyId,
            tenantId: $tenantId,
            sourceType: 'instrument',
            sourceId: $instrumentId,
            debitAccountId: $portfolioAccountId,
            creditAccountId: $receivable->id,
            partnerId: $partnerId,
            amount: $amount,
            date: $date,
            description: 'Cheque re-presentation',
        );
    }

    /**
     * Draft the clearing entry whose bank debit is exactly the repository
     * movement amount. The lifecycle caller posts it synchronously before the
     * movement port takes the repository-row lock.
     *
     * @param  numeric-string  $nominal
     * @param  numeric-string  $net
     * @param  numeric-string  $fee
     * @param  numeric-string  $feeVat
     */
    public function createInstrumentClearingEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        string $bankAccountId,
        string $portfolioAccountId,
        string $feeAccountId,
        string $vatAccountId,
        string $nominal,
        string $net,
        string $fee,
        string $feeVat,
        int $scale,
        \DateTimeInterface $date,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Instrument clearing entries require an enclosing transaction.');
        }

        $debits = bcadd(bcadd($net, $fee, $scale), $feeVat, $scale);
        if (bccomp($debits, $nominal, $scale) !== 0) {
            throw new \LogicException('Instrument clearing entry is not balanced at the currency scale.');
        }

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $instrumentId,
            $bankAccountId,
            $portfolioAccountId,
            $feeAccountId,
            $vatAccountId,
            $nominal,
            $net,
            $fee,
            $feeVat,
            $scale,
            $date,
        ): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => 'Instrument clearing',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'instrument',
                'journal_code' => JournalCode::Effets,
                'source_id' => $instrumentId,
            ]);

            $lineOrder = 0;
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $bankAccountId,
                'partner_id' => null,
                'debit' => $net,
                'credit' => '0',
                'description' => 'Instrument clearing net bank receipt',
                'line_order' => $lineOrder++,
            ]);
            if (bccomp($fee, '0', $scale) > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $feeAccountId,
                    'partner_id' => null,
                    'debit' => $fee,
                    'credit' => '0',
                    'description' => 'Instrument clearing bank fee',
                    'line_order' => $lineOrder++,
                ]);
            }
            if (bccomp($feeVat, '0', $scale) > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $vatAccountId,
                    'partner_id' => null,
                    'debit' => $feeVat,
                    'credit' => '0',
                    'description' => 'Recoverable VAT on instrument clearing fee',
                    'line_order' => $lineOrder++,
                ]);
            }
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $portfolioAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $nominal,
                'description' => 'Instrument portfolio cleared',
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * Draft a dishonor entry. Before clearing, nominal value leaves the
     * portfolio and only bank fees touch the bank. After clearing, the bank
     * funds the nominal claw-back and fees in one exact credit.
     *
     * @param  numeric-string  $nominal
     * @param  numeric-string  $fee
     * @param  numeric-string  $feeVat
     */
    public function createInstrumentDishonorEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        ?string $partnerId,
        string $routingAccountId,
        string $portfolioAccountId,
        string $bankAccountId,
        string $feeAccountId,
        string $vatAccountId,
        string $nominal,
        string $fee,
        string $feeVat,
        bool $afterClearing,
        int $scale,
        \DateTimeInterface $date,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Instrument dishonor entries require an enclosing transaction.');
        }

        $feeGross = bcadd($fee, $feeVat, $scale);
        $bankCredit = $afterClearing ? bcadd($nominal, $feeGross, $scale) : $feeGross;

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $instrumentId,
            $partnerId,
            $routingAccountId,
            $portfolioAccountId,
            $bankAccountId,
            $feeAccountId,
            $vatAccountId,
            $nominal,
            $fee,
            $feeVat,
            $afterClearing,
            $scale,
            $bankCredit,
            $date,
        ): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => $afterClearing ? 'Instrument dishonor after clearing' : 'Instrument bounce before clearing',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'instrument',
                'journal_code' => JournalCode::Effets,
                'source_id' => $instrumentId,
            ]);

            $lineOrder = 0;
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $routingAccountId,
                'partner_id' => $partnerId,
                'debit' => $nominal,
                'credit' => '0',
                'description' => 'Dishonored instrument routing',
                'line_order' => $lineOrder++,
            ]);
            if (bccomp($fee, '0', $scale) > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $feeAccountId,
                    'partner_id' => null,
                    'debit' => $fee,
                    'credit' => '0',
                    'description' => 'Instrument dishonor bank fee',
                    'line_order' => $lineOrder++,
                ]);
            }
            if (bccomp($feeVat, '0', $scale) > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $vatAccountId,
                    'partner_id' => null,
                    'debit' => $feeVat,
                    'credit' => '0',
                    'description' => 'Recoverable VAT on dishonor fee',
                    'line_order' => $lineOrder++,
                ]);
            }
            if (! $afterClearing) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $portfolioAccountId,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $nominal,
                    'description' => 'Dishonored instrument leaves portfolio',
                    'line_order' => $lineOrder++,
                ]);
            }
            if (bccomp($bankCredit, '0', $scale) > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $bankAccountId,
                    'partner_id' => null,
                    'debit' => '0',
                    'credit' => $bankCredit,
                    'description' => 'Bank debit for instrument dishonor',
                    'line_order' => $lineOrder,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /** Draft a mirror counter-entry for a document tolerance write-off. */
    public function createInstrumentToleranceReversalEntry(
        string $companyId,
        string $tenantId,
        string $instrumentId,
        JournalEntry $original,
        \DateTimeInterface $date,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Instrument tolerance reversals require an enclosing transaction.');
        }

        $original->loadMissing('lines');

        return DB::transaction(function () use ($companyId, $tenantId, $instrumentId, $original, $date): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => 'Tolerance reversal after instrument dishonor',
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'instrument_tolerance_reversal',
                'journal_code' => JournalCode::Effets,
                'source_id' => $instrumentId,
            ]);
            foreach ($original->lines as $order => $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line->account_id,
                    'partner_id' => $line->partner_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => 'Tolerance reversal: '.($line->description ?? ''),
                    'line_order' => $order,
                ]);
            }

            return $entry->load('lines');
        });
    }

    private function createInstrumentTransitEntry(
        string $companyId,
        string $tenantId,
        string $sourceType,
        string $sourceId,
        string $debitAccountId,
        string $creditAccountId,
        ?string $partnerId,
        string $amount,
        \DateTimeInterface $date,
        string $description,
    ): JournalEntry {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Instrument transit entries require an enclosing transaction.');
        }

        return DB::transaction(function () use (
            $companyId,
            $tenantId,
            $sourceType,
            $sourceId,
            $debitAccountId,
            $creditAccountId,
            $partnerId,
            $amount,
            $date,
            $description,
        ): JournalEntry {
            $entry = JournalEntry::query()->create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $date,
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::Effets,
                'source_id' => $sourceId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => $description.' debit',
                'line_order' => 0,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => $description.' credit',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }

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
        ?PaymentLedgerPartition $partition = null,
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
            $partition,
        ): JournalEntry {
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

            // EXHAUSTIVE — no `default`, deliberately (A-D7). The pre-DPA code
            // resolved the debit through three parallel ternaries (account,
            // partner_id, description), which is how a fourth shape gets added
            // wrongly. A future `CancellationShape` case is now a compile error.
            $debitLines = match ($shape) {
                // UNCHANGED, byte for byte: one Dr ProductRevenue, partner_id
                // null. Only its SELECTION narrowed — under A-D7 it is reachable
                // solely through an explicit caller-supplied shape (the POS void
                // lane), never from a reversal.
                CancellationShape::PosRevenue => [[
                    'account_id' => $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue)->id,
                    'partner_id' => null,
                    'amount' => $amount,
                    'description' => 'POS revenue reversed',
                ]],

                CancellationShape::B2b => $this->b2bCancellationDebits(
                    $companyId,
                    $partnerId,
                    $amount,
                    $partition,
                ),
            };

            $lineOrder = 0;
            foreach ($debitLines as $line) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'],
                    'debit' => $line['amount'],
                    'credit' => '0',
                    'description' => $line['description'],
                    'line_order' => $lineOrder++,
                ]);
            }

            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $portfolioAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Instrument portfolio reversed',
                'line_order' => $lineOrder,
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * DPA `DPA-REV2-A` (A-D7) — the `B2b` debit side, driven by the LEDGER.
     *
     * A non-empty partition emits one debit per non-zero bucket, each
     * partner-tagged, and asserts they sum to the instrument nominal. That is the
     * only way a MIXED deferred tender (one cheque, an AR leg and an advance leg
     * against the same portfolio) can be cancelled correctly — no single
     * `CancellationShape` case could represent it, which is why the ENTRY takes
     * the partition rather than the enum gaining a third case.
     *
     * An EMPTY partition keeps the LEGACY shape verbatim: a single partner-tagged
     * `Dr CustomerReceivable` at the nominal. This is deliberate and load-bearing
     * — the standalone `cancel()` endpoint is pinned unchanged by the plan (§8,
     * A9's test list), and it is the arm that receives an `origin = Pos` payment
     * with no AR/advance footprint under the 2026-08-10 orchestrator ruling.
     *
     * @param  numeric-string  $amount  the instrument nominal
     * @return list<array{account_id: string, partner_id: string|null, amount: numeric-string, description: string}>
     */
    private function b2bCancellationDebits(
        string $companyId,
        ?string $partnerId,
        string $amount,
        ?PaymentLedgerPartition $partition,
    ): array {
        $receivableAccountId = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerReceivable)->id;

        if ($partition === null || $partition->isEmpty()) {
            return [[
                'account_id' => $receivableAccountId,
                'partner_id' => $partnerId,
                'amount' => $amount,
                'description' => 'Customer receivable restored',
            ]];
        }

        $scale = $partition->scale;

        // The reversing DOCUMENT and the cancellation ENTRY must agree about how
        // much was unwound (the D-5b equality already guarantees
        // nominal == netUnreversed). Fail closed on divergence rather than
        // posting an unbalanced or under-stated restoration.
        if (bccomp($partition->total(), $amount, $scale) !== 0) {
            throw new \DomainException(
                "instrument cancellation partition sums to {$partition->total()} but the instrument "
                ."nominal is {$amount}; the cancellation entry and the payment's ledger footprint "
                .'disagree about how much was unwound. Refusing.'
            );
        }

        $lines = [];

        if (bccomp($partition->arBacked, '0', $scale) > 0) {
            $lines[] = [
                'account_id' => $receivableAccountId,
                'partner_id' => $partnerId,
                'amount' => $partition->arBacked,
                'description' => 'Customer receivable restored',
            ];
        }

        if (bccomp($partition->advanceBacked, '0', $scale) > 0) {
            $lines[] = [
                'account_id' => $this->getAccountByPurpose($companyId, SystemAccountPurpose::CustomerAdvance)->id,
                'partner_id' => $partnerId,
                'amount' => $partition->advanceBacked,
                'description' => 'Customer advance reversed',
            ];
        }

        return $lines;
    }

    /**
     * Post a journal entry SYNCHRONOUSLY inside the current transaction.
     *
     * Unlike {@see postEntryAndDispatchPostedEventAfterCommit}, which defers the
     * ENTIRE post (status seal + hash + event) to DB::afterCommit when inside a
     * transaction, this seals + persists durably in-transaction and defers only
     * the JournalEntryPosted event.
     *
     * @param  string|null  $currencyCode  Explicit entity currency for workers,
     *                                     projections, and console contexts.
     */
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

        // enforcement-P3 M1 deliverable D: the balance ALGORITHM above is
        // unchanged — only the FAILURE MODE is normalized. The refusal is now
        // raised as a NAMED type (`UnbalancedJournalEntryPostException`) rather than a bare
        // `\InvalidArgumentException` that callers cannot tell apart from ordinary
        // argument noise and therefore swallow in broad `catch` blocks. It extends
        // `\InvalidArgumentException` and keeps the message byte-identical, so the
        // blast radius is IDENTICAL to the pre-M1 behaviour — nothing that caught
        // this before stops, nothing new starts. It is deliberately NOT the
        // post-seal `UnbalancedJournalEntryException`, whose `\RuntimeException`
        // parent other call sites depend on. See
        // docs/handoff/reviews/enforcement-p3/M1-census.md §5.
        if (bccomp($totalDebit, $totalCredit, $balanceScale) !== 0) {
            throw UnbalancedJournalEntryPostException::forChokepoint($totalDebit, $totalCredit);
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
     * Debit: Cash/Bank Account (from payment repository's GL account), or the
     * caller-supplied portfolio-account override for a maturity tender.
     * Credit: Revenue Account (ProductRevenue system purpose)
     *
     * The override is deliberately a debit-only seam: revenue lines remain
     * byte-identical across immediate and deferred POS tender legs.
     */
    public function createPOSPaymentEntry(
        Payment $payment,
        Receipt $receipt,
        PaymentRepository $repository,
        ?string $cashAccountOverrideId = null,
    ): JournalEntry {
        if ($repository->gl_account_id === null) {
            throw new \InvalidArgumentException(
                "Cannot create GL entry: payment repository '{$repository->name}' ({$repository->code}) "
                .'is not linked to a General Ledger account. '
                .'Go to Settings → Treasury → Payment Repositories and assign a GL account to this repository.'
            );
        }

        $entry = DB::transaction(function () use ($payment, $receipt, $repository, $cashAccountOverrideId): JournalEntry {
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
                'account_id' => $cashAccountOverrideId ?? $repository->gl_account_id,
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
     * POS cash-rounding difference (cash-rounding spec §4.6 entry 1).
     *
     *   R > 0 (rounded UP — more was collected than the sale is worth):
     *       Dr ProductRevenue R / Cr PaymentToleranceIncome (7580) R
     *   R < 0 (rounded DOWN):
     *       Dr PaymentToleranceExpense (6580) |R| / Cr ProductRevenue |R|
     *
     * Net effect: the revenue account ends at the EXACT sale value while the
     * cash account carries what was actually collected.
     *
     * **`$sourceType` selects the DIRECTION, not just the label.** It is
     * `'pos_cash_rounding'` on a sale and `'pos_cash_rounding_refund'` on a
     * refund/void, and the refund posts the SYMMETRIC REVERSAL — the same two
     * accounts with the legs flipped. Deriving direction from the sign of `R`
     * alone would replay the sale's own legs onto its refund and DOUBLE the
     * rounding expense instead of clearing it. The counter account still
     * follows the sign (a refunded round-DOWN credits back the 6580 it
     * originally debited), so both halves land on the same account pair.
     *
     * The two distinct literals are also the entire basis of disjointness from
     * the legacy 658 writer, because `journal_entries` has no `fiscal_event_id`
     * — and they are what the Task-7 partial unique indexes key on.
     * `JournalCode::fromSourceType` deliberately has no arm for either: both
     * fall through to Misc/OD, an explicit spec decision, no enum change.
     *
     * Returned in Draft; the caller posts it via `postEntryNow()` inside its own
     * transaction, exactly as with {@see createPOSPaymentEntry}.
     *
     * @param  string  $adjustment  SIGNED adjustment at the receipt currency scale
     * @param  int|null  $currencyScale  Explicit scale from the sealed canonical
     *                                   payload. Pass it: the ISO fallback is a
     *                                   SECOND scale source, and if it were the
     *                                   narrower of the two the sign flip below
     *                                   would silently TRUNCATE real money.
     */
    public function createPosCashRoundingEntry(
        Receipt $receipt,
        string $adjustment,
        string $sourceType,
        ?int $currencyScale = null,
    ): JournalEntry {
        // `is_numeric()` narrows the type; the regex rejects what bcmath cannot
        // parse but is_numeric() still accepts (scientific notation, leading
        // whitespace, hex-ish forms).
        if (! is_numeric($adjustment) || preg_match('/^[+-]?\d+(\.\d+)?$/', $adjustment) !== 1) {
            throw new \InvalidArgumentException('Cash-rounding adjustment must be a plain decimal string; got '.$adjustment);
        }

        $isReversal = match ($sourceType) {
            'pos_cash_rounding' => false,
            'pos_cash_rounding_refund' => true,
            default => throw new \InvalidArgumentException(
                'Unknown cash-rounding source type '.$sourceType.'; expected pos_cash_rounding or pos_cash_rounding_refund.'
            ),
        };

        return DB::transaction(function () use ($receipt, $adjustment, $sourceType, $currencyScale, $isReversal): JournalEntry {
            $companyId = (string) $receipt->company_id;
            $scale = $currencyScale ?? CurrencyScale::for((string) $receipt->currency);

            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $roundedUp = bccomp($adjustment, '0', $scale) > 0;
            $counterAccount = $this->getAccountByPurpose(
                $companyId,
                $roundedUp ? SystemAccountPurpose::PaymentToleranceIncome : SystemAccountPurpose::PaymentToleranceExpense,
            );

            $magnitude = $roundedUp
                ? bcadd($adjustment, '0', $scale)
                : bcmul($adjustment, '-1', $scale);

            $entry = JournalEntry::create([
                'tenant_id' => (string) $receipt->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $receipt->posted_at,
                'description' => "POS cash rounding {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::fromSourceType($sourceType)->value,
                'source_id' => $receipt->id,
            ]);

            // XOR: a reversal swaps which side revenue sits on, the sign picks
            // which counter account is involved.
            $debitIsRevenue = $roundedUp !== $isReversal;
            $debitAccountId = $debitIsRevenue ? $revenueAccount->id : $counterAccount->id;
            $creditAccountId = $debitIsRevenue ? $counterAccount->id : $revenueAccount->id;
            $description = $isReversal
                ? 'POS cash rounding difference reversed (refund)'
                : 'POS cash rounding difference';

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitAccountId,
                'partner_id' => null,
                'debit' => $magnitude,
                'credit' => '0',
                'description' => $description,
                'line_order' => 0,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccountId,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $magnitude,
                'description' => $description,
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
    }

    /**
     * v3-refund-chain-integration spec §5.3 — class-dependent compensation
     * entry for a rejected refund fiscal event.
     *
     *   - `invalid_refund` (the refund was genuinely wrong): Dr RefundWriteOff
     *     (expense) / Cr Cash — a genuine loss booking.
     *   - `valid_unbooked` (a genuine refund whose booking was merely
     *     delayed by infrastructure): Dr SalesReturn / Cr Cash — the SAME
     *     reversal shape a successfully-booked refund would have received
     *     via the normal path.
     *
     * Caller (`RefundCompensationService`) wraps this + the movement-port
     * call + the `fiscal_refund_compensations` row insert in ONE
     * `DB::transaction()` (§5.2) — this method does NOT open its own
     * transaction (unlike {@see createPosCashRoundingEntry}) so it can
     * participate in that outer one.
     *
     * **review round-2 CRITICAL 4.** The credit leg posts to the
     * DECREMENTED REPOSITORY's own `gl_account_id` — the actual cash
     * account the movement-port write-off leg pulls money out of — never
     * the company-wide `SystemAccountPurpose::Cash` account, which would
     * silently diverge from the real repository whenever a company has
     * more than one cash account. Same null-gl_account_id refusal
     * precedent as {@see createPOSRefundReversalEntry()}, but as a
     * `\DomainException` (422-mapped by the generic handler in
     * `bootstrap/app.php`) rather than `\InvalidArgumentException`
     * (unmapped, would bubble to a 500).
     *
     * @param  numeric-string  $amount  positive magnitude at $scale
     */
    public function createRefundCompensationEntry(
        string $tenantId,
        string $companyId,
        string $fiscalEventId,
        string $compensationClass,
        string $amount,
        int $scale,
        \DateTimeInterface $entryDate,
        PaymentRepository $repository,
    ): JournalEntry {
        $debitPurpose = match ($compensationClass) {
            'invalid_refund' => SystemAccountPurpose::RefundWriteOff,
            'valid_unbooked' => SystemAccountPurpose::SalesReturn,
            default => throw new \InvalidArgumentException(
                'Unknown compensation_class '.$compensationClass.'; expected invalid_refund or valid_unbooked.'
            ),
        };

        if ($repository->gl_account_id === null) {
            throw new \DomainException(
                "Cannot post a refund compensation entry: payment repository '{$repository->name}' ({$repository->code}) ".
                'has no linked GL account. Assign a GL account to this repository first.'
            );
        }

        $debitAccount = $this->getAccountByPurpose($companyId, $debitPurpose);

        $sourceType = 'fiscal_refund_compensation';

        $entry = JournalEntry::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'entry_number' => $this->generateEntryNumber($companyId),
            'entry_date' => $entryDate,
            'description' => "Refund compensation ({$compensationClass}) for fiscal_event {$fiscalEventId}",
            'status' => JournalEntryStatus::Draft,
            'source_type' => $sourceType,
            'journal_code' => JournalCode::fromSourceType($sourceType)->value,
            'source_id' => $fiscalEventId,
        ]);

        $normalizedAmount = CurrencyScale::bcformatStrict($amount, $scale);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debitAccount->id,
            'partner_id' => null,
            'debit' => $normalizedAmount,
            'credit' => '0',
            'description' => "Refund compensation ({$compensationClass})",
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $repository->gl_account_id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $normalizedAmount,
            'description' => "Refund compensation ({$compensationClass})",
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }

    /**
     * POS tender-tolerance write-off (cash-rounding spec §4.6 entry 2).
     *
     *   Dr PaymentToleranceExpense (6580) S / Cr ProductRevenue S
     *
     * `S` is the positive shortfall between the receipt total and the TENDERED
     * sum — what the customer was let off, which the drawer never received.
     *
     * `source_type = 'pos_tolerance_bridge'`, deliberately DISTINCT from the
     * legacy `createPOSPaymentToleranceEntry`'s `'pos_payment_tolerance'`: the
     * two writers must never be mistaken for one another in FEC/reporting, and
     * the distinct literal is what the Task-7 partial unique index keys on.
     *
     * Returned in Draft; the caller posts it.
     *
     * @param  string  $shortfall  POSITIVE amount at the receipt currency scale
     */
    public function createPosToleranceWriteoffEntry(Receipt $receipt, string $shortfall): JournalEntry
    {
        if (! is_numeric($shortfall) || preg_match('/^\+?\d+(\.\d+)?$/', $shortfall) !== 1) {
            throw new \InvalidArgumentException('Tolerance shortfall must be a non-negative plain decimal string; got '.$shortfall);
        }

        return DB::transaction(function () use ($receipt, $shortfall): JournalEntry {
            $companyId = (string) $receipt->company_id;

            $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::ProductRevenue);
            $expenseAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PaymentToleranceExpense);

            $entry = JournalEntry::create([
                'tenant_id' => (string) $receipt->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $receipt->posted_at,
                'description' => "POS tender tolerance {$receipt->receipt_number}",
                'status' => JournalEntryStatus::Draft,
                'source_type' => 'pos_tolerance_bridge',
                'journal_code' => JournalCode::fromSourceType('pos_tolerance_bridge')->value,
                'source_id' => $receipt->id,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expenseAccount->id,
                'partner_id' => null,
                'debit' => $shortfall,
                'credit' => '0',
                'description' => 'POS tender tolerance write-off',
                'line_order' => 0,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenueAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $shortfall,
                'description' => 'POS tender tolerance write-off',
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });
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
    /**
     * The GL account a NON-CASH payment method settles through, when an expense
     * was paid without naming a treasury repository (W4-10's sanctioned
     * carve-out; gate r1 F-3).
     *
     * Resolved from `payment_methods.default_account_id` — seeded configuration,
     * never a hardcoded code — and scoped by tenant+company so a foreign
     * method's account can never be reached. Null when the method is absent,
     * carries no default account, or that account does not resolve for this
     * company; the caller then falls back to the BANK purpose account.
     */
    private function paymentMethodAccountForExpense(
        Document $expense,
        string $companyId,
        ?string $paymentMethodId,
    ): ?Account {
        if ($paymentMethodId === null) {
            return null;
        }

        $accountId = PaymentMethod::query()
            ->where('tenant_id', $expense->tenant_id)
            ->where('company_id', $companyId)
            ->whereKey($paymentMethodId)
            ->value('default_account_id');

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        $account = Account::query()
            ->where('tenant_id', $expense->tenant_id)
            ->where('company_id', $companyId)
            ->whereKey($accountId)
            ->first();

        return $account instanceof Account ? $account : null;
    }

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
                $repository = $metadata->paymentRepository;

                // W4-10: credit the repository's OWN cash/bank account when it
                // has one, so the treasury movement and the GL line land on the
                // same account and ReconcileTreasuryCommand's check 2 (which
                // treats the repository's own gl_account_id line as
                // AUTHORITATIVE) actually enforces till == ledger. The
                // purpose-based lookup stays as the fallback for a repository
                // with no GL link and for the legacy no-repository shape — on
                // the seeded chart the two resolve to the same account
                // (PaymentRepositorySeeder links both tills to the Cash
                // purpose account), so this is a no-op there and only bites
                // when a tenant splits its cash accounts per till.
                $repositoryGlAccount = $repository?->gl_account_id !== null
                    ? Account::query()
                        ->where('tenant_id', $expense->tenant_id)
                        ->where('company_id', $companyId)
                        ->whereKey($repository->gl_account_id)
                        ->first()
                    : null;

                if ($repositoryGlAccount instanceof Account) {
                    $creditAccount = $repositoryGlAccount;
                } elseif ($repository !== null) {
                    // A repository with no GL link: fall back on its TYPE.
                    $creditAccount = match ($repository->type) {
                        RepositoryType::BankAccount => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Bank),
                        default => $this->getAccountByPurpose($companyId, SystemAccountPurpose::Cash),
                    };
                } else {
                    // NO repository at all. Since W4-10 this is reachable ONLY
                    // through the sanctioned non-cash carve-out: an expense paid
                    // by a method whose `is_cash_tender` is false
                    // (ExpenseService::assertPaidExpenseNamesRepository refuses
                    // every other shape). Crediting Cash here — which is what
                    // shipped — put a CARD payment against `53 Caisse` and moved
                    // no till, silently breaking the `Σ till balances == GL cash`
                    // equality W4-2 establishes, in a way treasury:reconcile
                    // cannot see because there is no movement to check
                    // (gate r1 F-3, PROBE F: `credit53=45 credit512=0 movements=0`).
                    //
                    // The money left through the method's own rail, so credit the
                    // account that rail is configured with — `payment_methods
                    // .default_account_id` — and fall back to the BANK purpose
                    // account, never Cash: a non-cash tender by definition did not
                    // come out of a drawer.
                    $creditAccount = $this->paymentMethodAccountForExpense($expense, $companyId, $metadata->payment_method_id)
                        ?? $this->getAccountByPurpose($companyId, SystemAccountPurpose::Bank);
                }
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

            $scale = $this->scaleResolver->getScale((string) $expense->currency);
            $total = (string) ($expense->total ?? '0');
            $vatAmount = $expense->tax_amount !== null ? (string) $expense->tax_amount : null;

            if ($vatAmount !== null && bccomp($vatAmount, '0', $scale) === 1) {
                $deductiblePercent = (string) ($metadata->vat_deductible_percent ?? '100.00');
                $deductibleVat = ExpenseVatSplit::deductible($vatAmount, $deductiblePercent, $scale);
                $nonDeductibleVat = bcsub($vatAmount, $deductibleVat, $scale);
                $expenseDebit = bcadd((string) ($expense->subtotal ?? '0'), $nonDeductibleVat, $scale);

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $expenseAccount->id,
                    'partner_id' => null,
                    'debit' => $expenseDebit,
                    'credit' => '0',
                    'description' => $vendorName,
                    'line_order' => $lineOrder++,
                ]);

                if (bccomp($deductibleVat, '0', $scale) === 1) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $this->getAccountByPurpose($companyId, SystemAccountPurpose::VatDeductible)->id,
                        'partner_id' => null,
                        'debit' => $deductibleVat,
                        'credit' => '0',
                        'description' => 'TVA déductible',
                        'line_order' => $lineOrder++,
                    ]);
                }
            } else {
                // Debit: Expense Account (legacy VAT-less shape)
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $expenseAccount->id,
                    'partner_id' => null, // Expenses typically don't have partner tracking
                    'debit' => $expense->total ?? '0',
                    'credit' => '0',
                    'description' => $vendorName,
                    'line_order' => $lineOrder++,
                ]);
            }

            // Credit: Cash/Bank (paid) or Accounts-Payable liability (unpaid)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $creditAccount->id,
                'partner_id' => $creditPartnerId,
                'debit' => '0',
                'credit' => $total,
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
     * True when this company has BOTH accounts an inventory write-off entry
     * needs. Lets a caller decide up front whether posting is possible, instead
     * of calling {@see createInventoryWriteOffEntry} and catching the bare
     * RuntimeException `Account::findByPurposeOrFail` throws — a catch that
     * inevitably also swallows genuine faults (ModelNotFound, QueryException,
     * ClosedFiscalPeriod all extend RuntimeException) and silently leaves
     * destroyed inventory on the balance sheet (DPA V10 gate M4/I7).
     */
    public function hasInventoryWriteOffAccounts(string $companyId): bool
    {
        return Account::findByPurpose($companyId, SystemAccountPurpose::InventoryShrinkageExpense) !== null
            && Account::findByPurpose($companyId, SystemAccountPurpose::Inventory) !== null;
    }

    /**
     * True when the chart can post a movement-keyed inventory entry.
     * Wave 3D's tenant migration installs both count-correction purposes on
     * existing charts. Sales exits/returns use COGS, destructive-loss exits use
     * shrinkage, and count corrections select shrinkage or gain by direction.
     */
    public function hasInventoryMovementAccounts(string $companyId, MovementReason $reason): bool
    {
        if ($reason === MovementReason::CountCorrection) {
            return Account::findByPurpose($companyId, SystemAccountPurpose::Inventory) !== null
                && Account::findByPurpose($companyId, SystemAccountPurpose::InventoryShrinkageExpense) !== null
                && Account::findByPurpose($companyId, SystemAccountPurpose::InventoryGainIncome) !== null;
        }

        $counterPurpose = $reason->affectsShrinkage()
            ? SystemAccountPurpose::InventoryShrinkageExpense
            : SystemAccountPurpose::CostOfGoodsSold;

        return Account::findByPurpose($companyId, $counterPurpose) !== null
            && Account::findByPurpose($companyId, SystemAccountPurpose::Inventory) !== null;
    }

    /**
     * Create one idempotent, movement-keyed inventory entry.
     *
     * The amount is already rounded once by InventoryGlPostingService. Both
     * lines reuse it verbatim, so the entry balances by construction.
     *
     * @param  numeric-string  $amount
     */
    public function createInventoryMovementEntry(
        string $companyId,
        string $movementId,
        string $sourceType,
        string $amount,
        MovementReason $reason,
        SystemAccountPurpose $counterPurpose,
        bool $debitInventory,
        \DateTimeInterface $entryDate,
        string $description,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
        bool $postSynchronously = false,
    ): ?JournalEntry {
        $scale = $this->scaleResolver->getScale($currencyCode);
        if (bccomp($amount, '0', $scale) <= 0) {
            return null;
        }

        $user = null;
        if ($postedByUserId !== null) {
            $user = User::query()->find($postedByUserId);
            if ($user === null) {
                Log::error('createInventoryMovementEntry: actor does not resolve; posting as system-generated', [
                    'company_id' => $companyId,
                    'movement_id' => $movementId,
                    'posted_by_user_id' => $postedByUserId,
                ]);
            }
        }

        $existing = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $movementId)
            ->with('lines')
            ->first();
        if ($existing !== null) {
            if ($postSynchronously && $existing->status !== JournalEntryStatus::Posted) {
                $this->postEntryNow($existing, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
                $existing->refresh()->load('lines');
            }

            return $existing;
        }

        $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);
        $counterAccount = $this->getAccountByPurpose($companyId, $counterPurpose);

        $entry = DB::transaction(function () use (
            $companyId,
            $movementId,
            $sourceType,
            $amount,
            $reason,
            $debitInventory,
            $entryDate,
            $description,
            $inventoryAccount,
            $counterAccount,
        ): JournalEntry {
            $existing = JournalEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $movementId)
                ->with('lines')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $company = Company::findOrFail($companyId);
            $entry = JournalEntry::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $companyId,
                'entry_number' => $this->generateEntryNumber($companyId),
                'entry_date' => $entryDate->format('Y-m-d'),
                'description' => $description,
                'status' => JournalEntryStatus::Draft,
                'source_type' => $sourceType,
                'journal_code' => JournalCode::fromSourceType($sourceType)->value,
                'source_id' => $movementId,
            ]);

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitInventory ? $inventoryAccount->id : $counterAccount->id,
                'partner_id' => null,
                'debit' => $amount,
                'credit' => '0',
                'description' => $reason->label(),
                'line_order' => 0,
            ]);
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debitInventory ? $counterAccount->id : $inventoryAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $amount,
                'description' => $reason->label(),
                'line_order' => 1,
            ]);

            return $entry->load('lines');
        });

        if ($postSynchronously && $entry->status !== JournalEntryStatus::Posted) {
            $this->postEntryNow($entry, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
            $entry->refresh()->load('lines');
        } elseif (! $postSynchronously && $user !== null && $entry->status !== JournalEntryStatus::Posted) {
            $this->postEntryAndDispatchPostedEventAfterCommit($entry, $user, $companyId, $currencyCode);
        }

        return $entry;
    }

    /**
     * Post an immutable compensating entry for a movement-keyed inventory leg.
     *
     * The original Posted entry is never edited. Idempotency is keyed by the
     * reversal's source tuple `(inventory_movement_reversal, original entry id)`.
     */
    public function reverseInventoryMovementEntry(
        JournalEntry $original,
        \DateTimeInterface $entryDate,
    ): JournalEntry {
        if (! in_array($original->source_type, ['inventory_exit', 'inventory_entry'], true)) {
            throw new \InvalidArgumentException('Only inventory_exit and inventory_entry entries can be reversed here.');
        }
        if ($original->status !== JournalEntryStatus::Posted) {
            throw new \InvalidArgumentException('Only Posted inventory entries can be reversed.');
        }

        return DB::transaction(function () use ($original, $entryDate): JournalEntry {
            $locked = JournalEntry::query()
                ->whereKey($original->id)
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            $existing = JournalEntry::query()
                ->where('company_id', $locked->company_id)
                ->where('source_type', self::INVENTORY_MOVEMENT_REVERSAL_SOURCE_TYPE)
                ->where('source_id', $locked->id)
                ->with('lines')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $reversal = JournalEntry::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'entry_number' => $this->generateEntryNumber($locked->company_id),
                'entry_date' => $entryDate,
                'description' => 'Cutover reversal of '.$locked->entry_number,
                'status' => JournalEntryStatus::Draft,
                'source_type' => self::INVENTORY_MOVEMENT_REVERSAL_SOURCE_TYPE,
                'journal_code' => JournalCode::fromSourceType(self::INVENTORY_MOVEMENT_REVERSAL_SOURCE_TYPE)->value,
                'source_id' => $locked->id,
            ]);

            foreach ($locked->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'partner_id' => $line->partner_id,
                    'debit' => (string) $line->credit,
                    'credit' => (string) $line->debit,
                    'description' => 'Cutover reversal: '.($line->description ?? $locked->entry_number),
                    'line_order' => $line->line_order,
                ]);
            }

            $reversal->load('lines');
            $this->postEntryNow(
                $reversal,
                null,
                $this->currencyCodeForCompany($locked->company_id),
            );

            return $reversal->refresh()->load('lines');
        });
    }

    /**
     * Create journal entry for inventory write-off (expired/damaged batch stock,
     * POS return scrap).
     *
     * Debit: Inventory Shrinkage (destructive-loss expense)
     * Credit: Inventory (asset reduction)
     *
     * @param  bool  $postSynchronously  Seal + persist the entry INSIDE the caller's
     *                                   transaction via {@see postEntryNow} instead of
     *                                   deferring the whole post to `DB::afterCommit`.
     *                                   REQUIRED for queue/projection callers: the
     *                                   deferred path runs in autocommit, where the
     *                                   per-company `pg_advisory_xact_lock` that
     *                                   serializes `chain_sequence` allocation degrades
     *                                   to a no-op (duplicate sequences under concurrent
     *                                   workers), and where a post-commit throw leaves
     *                                   the entry Draft forever — invisible to every
     *                                   trial balance, P&L and balance sheet.
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
        bool $postSynchronously = false,
    ): ?JournalEntry {
        // Rule 19/20: prefer the EXPLICIT currency when the caller supplied one.
        // A bare no-arg getScale() reads CompanyContext, which is unbound in
        // queued/projection contexts (the POS scrap write-off posts from the
        // fiscal projector) and throws there — silently swallowing the entry via
        // the callers' RuntimeException guard.
        //
        // NOTE: this is NOT strictly behaviour-identical for the pre-existing
        // caller — getScale($code) reads the static ISO 4217 map while the no-arg
        // path reads the company country's `currency_decimal_places` column, so a
        // country row that overrides the ISO scale now resolves differently here.
        // The only use is the `bccomp($amount, '0')` zero-test below, so the
        // observable difference is confined to sub-minor-unit amounts.
        $scale = $currencyCode !== null
            ? $this->scaleResolver->getScale($currencyCode)
            : $this->scale();

        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $scale) <= 0) {
            return null;
        }

        // Resolve the actor DEFENSIVELY. On the POS projection path the id is
        // `fiscal_events.operator_id` — a device-authored bare uuid column with no
        // FK, which this projector already treats as untrusted elsewhere. A
        // `findOrFail` here used to abort the whole GL leg (and, under the callers'
        // RuntimeException guard, silently), producing a costed destruction with no
        // journal entry at all. An unresolvable actor degrades to a
        // system-generated post — never to "no entry".
        $user = null;
        if ($postedByUserId !== null) {
            $user = User::query()->find($postedByUserId);

            if ($user === null) {
                Log::error('createInventoryWriteOffEntry: postedByUserId does not resolve to a User; posting the write-off entry as system-generated', [
                    'company_id' => $companyId,
                    'movement_id' => $movementId,
                    'posted_by_user_id' => $postedByUserId,
                ]);
            }
        }

        $existing = JournalEntry::query()
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $movementId)
            ->with('lines')
            ->first();
        if ($existing !== null) {
            if ($postSynchronously && $existing->status !== JournalEntryStatus::Posted) {
                $this->postEntryNow($existing, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
                $existing->refresh()->load('lines');
            }

            return $existing;
        }

        $shrinkageAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::InventoryShrinkageExpense);
        $inventoryAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::Inventory);

        $entry = DB::transaction(function () use (
            $companyId, $batchNumber, $amount, $reason, $movementId,
            $shrinkageAccount, $inventoryAccount
        ): JournalEntry {
            $existing = JournalEntry::query()
                ->where('source_type', 'batch_write_off')
                ->where('source_id', $movementId)
                ->with('lines')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

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

            // Debit: Inventory shrinkage (destructive-loss expense increases)
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $shrinkageAccount->id,
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

        if ($postSynchronously && $entry->status !== JournalEntryStatus::Posted) {
            // Seal + persist in the caller's transaction: the entry and the stock
            // movement commit or roll back as ONE unit, and the chain advisory lock
            // is actually effective (it only is inside an explicit transaction).
            // postEntryNow accepts a null actor, so an unresolvable device operator
            // still yields a POSTED entry with posted_by = null.
            $this->postEntryNow($entry, $user, $currencyCode ?? $this->currencyCodeForCompany($companyId));
        } elseif ($user !== null) {
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

        $existing = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'batch_write_off_reversal')
            ->where('source_id', $reversalMovementId)
            ->with('lines')
            ->first();

        $user = null;
        if ($wasPosted && $postedByUserId !== null) {
            $user = User::query()->findOrFail($postedByUserId);
        }

        $entry = $existing ?? DB::transaction(function () use ($companyId, $original, $reversalMovementId): JournalEntry {
            $existing = JournalEntry::query()
                ->where('company_id', $companyId)
                ->where('source_type', 'batch_write_off_reversal')
                ->where('source_id', $reversalMovementId)
                ->with('lines')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

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
        if ($user !== null && $entry->status !== JournalEntryStatus::Posted) {
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
