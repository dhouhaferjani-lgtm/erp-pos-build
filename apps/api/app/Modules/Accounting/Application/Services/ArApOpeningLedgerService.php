<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts the GL leg of an AR/AP OPEN-ITEM opening (W4-4).
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this service the AR/AP opening batches created historical documents and
 * posted no GL at all, while the ACCOUNTING opening batch posted a lump
 * `Dr 411 / Cr 401` with `partner_id = NULL` on every line. The consequence,
 * reproduced on the first tenant: `GL 411` held 150.000 and `GL 401` held 500.000
 * while both partner pages read `0.000`, because `partners.receivable_balance` /
 * `payable_balance` are refreshed from POSTED journal lines carrying a partner
 * dimension ({@see PartnerBalanceService::refreshPartnerBalance()}). No opening
 * balance could reach the sub-ledger, so the sub-ledger could not agree with the
 * control account at cutover BY CONSTRUCTION.
 *
 * The fix makes the open-item batch the single writer of the control accounts at
 * cutover: one historical journal entry PER opening document, control leg tagged
 * with the partner, counterpart on `SystemAccountPurpose::OpeningBalanceEquity`
 * (TN `119 Solde d'ouverture`, FR `891 Bilan d'ouverture`, generic `1190` — the
 * code is never hardcoded, it is resolved from the seeded purpose).
 *
 *   AR invoice            Dr CustomerReceivable (partner) / Cr OpeningBalanceEquity
 *   AR credit note        Dr OpeningBalanceEquity         / Cr CustomerReceivable (partner)
 *   AP supplier invoice   Dr OpeningBalanceEquity         / Cr SupplierPayable (partner)
 *   AP supplier credit    Dr SupplierPayable (partner)    / Cr OpeningBalanceEquity
 *
 * The mirror-image guard lives in {@see AccountingOpeningService::validateRow()},
 * which now REFUSES a GL opening row targeting a partner control account: without
 * it an operator following the old template would state the same 411/401 twice and
 * double the control balance.
 *
 * THREE DELIBERATE SHAPES, each load-bearing:
 *
 * 1. `source_type` is the document's OWN posting source (`invoice`,
 *    `supplier_invoice`, …) with `source_id` = the document id — not
 *    `opening_balance`. `PaymentController::store()` refuses to pay a supplier
 *    invoice unless a POSTED journal entry with `source_type = 'supplier_invoice'`
 *    and `source_id = <document>` carries a Cr line on the payable account tagged
 *    to the partner. An opening supplier invoice is a real payable and must be
 *    payable through exactly that path, so it posts exactly that entry.
 * 2. `is_historical = true` and the entry is created directly `Posted`, the same
 *    shape {@see AccountingOpeningService::postBatch()} uses: an opening carries no
 *    fiscal hash and must stay out of the GL hash chain. Because the entry never
 *    goes through `sealAndPersistEntry()`, no `JournalEntryPosted` fires and the
 *    partner-balance listener never runs — the caller must refresh the sub-ledger
 *    explicitly via {@see refreshPartnerBalances()}.
 * 3. The amount is the OPEN amount (`documents.balance_due`), not the original
 *    total. A migrated invoice of 200.000 that the previous system already
 *    collected 50.000 against owes 150.000 at cutover; posting the total would
 *    re-open a receivable that was settled in the old system.
 */
class ArApOpeningLedgerService
{
    /**
     * Money is stored at the fixed scale 3 (currency `decimal(N,3)` floor, rule 19);
     * `journal_lines.debit/credit` are `decimal(15,3)`. This is a STORAGE constant,
     * not a display scale — see {@see AccountingOpeningService::MONEY_STORAGE_SCALE}.
     */
    private const MONEY_STORAGE_SCALE = 3;

    public function __construct(
        private readonly PartnerBalanceService $partnerBalanceService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Post the cutover journal entry for one AR/AP opening document.
     *
     * MUST be called inside the batch's open transaction: the entry-number
     * generator takes a transaction-scoped advisory lock, and the document and its
     * entry must land or fail together.
     *
     * Returns null when the document carries no open amount — a fully settled
     * historical document is a record, not a balance, and must not post a
     * zero-value entry.
     *
     * @throws RuntimeException if the document is not an AR/AP opening shape
     */
    public function postOpeningEntry(
        Document $document,
        DateTimeInterface $cutoverDate,
        string $userId,
    ): ?JournalEntry {
        if ($document->is_historical !== true) {
            throw new RuntimeException(
                "Document {$document->document_number} is not a historical opening document."
            );
        }

        $companyId = (string) $document->company_id;
        $partnerId = $document->partner_id;

        if (! is_string($partnerId) || $partnerId === '') {
            throw new RuntimeException(
                "Opening document {$document->document_number} has no partner — it cannot reach the sub-ledger."
            );
        }

        $scale = $this->moneyScale(is_string($document->currency) ? $document->currency : null);

        /** @var numeric-string $amount */
        $amount = CurrencyScale::bcformatStrict((string) ($document->balance_due ?? '0'), $scale);

        if (bccomp($amount, '0', $scale) <= 0) {
            return null;
        }

        $controlPurpose = $this->controlPurposeFor($document->type);
        $controlAccount = Account::findByPurposeOrFail($companyId, $controlPurpose);
        $counterpartAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::OpeningBalanceEquity);

        $sourceType = $this->sourceTypeFor($document->type);
        // Debit-normal control (411) increases on an invoice and decreases on a
        // credit note; credit-normal control (401) does the opposite. One boolean
        // drives both legs so they can never disagree.
        $controlIsDebited = $this->controlIsDebited($document->type);

        $entry = JournalEntry::create([
            'tenant_id' => $document->tenant_id,
            'company_id' => $companyId,
            'entry_number' => $this->generateOpeningEntryNumber($companyId),
            'entry_date' => $cutoverDate,
            'description' => "Opening balance {$document->document_number}",
            'status' => JournalEntryStatus::Posted,
            'source_type' => $sourceType,
            'journal_code' => JournalCode::fromSourceType($sourceType)->value,
            'source_id' => $document->id,
            'is_historical' => true,
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $controlAccount->id,
            'partner_id' => $partnerId,
            'debit' => $controlIsDebited ? $amount : '0',
            'credit' => $controlIsDebited ? '0' : $amount,
            'description' => 'Opening balance',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $counterpartAccount->id,
            'partner_id' => null,
            'debit' => $controlIsDebited ? '0' : $amount,
            'credit' => $controlIsDebited ? $amount : '0',
            'description' => 'Opening balance counterpart',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }

    /**
     * Refresh the denormalized partner balances for the partners an opening batch
     * touched.
     *
     * Separate from {@see postOpeningEntry()} because the opening entries are
     * created directly `Posted` and therefore never dispatch `JournalEntryPosted`
     * (only `sealAndPersistEntry()` does). Called once per DISTINCT partner at the
     * end of the batch rather than once per document: the computation re-reads the
     * whole sub-ledger every time, so per-document calls would be quadratic on a
     * partner with many open items and would produce identical results.
     *
     * @param  list<string>  $partnerIds
     */
    public function refreshPartnerBalances(string $companyId, array $partnerIds): void
    {
        foreach (array_unique($partnerIds) as $partnerId) {
            $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
        }
    }

    private function controlPurposeFor(DocumentType $type): SystemAccountPurpose
    {
        return match ($type) {
            DocumentType::Invoice, DocumentType::CreditNote => SystemAccountPurpose::CustomerReceivable,
            DocumentType::SupplierInvoice, DocumentType::SupplierCreditNote => SystemAccountPurpose::SupplierPayable,
            default => throw new RuntimeException(
                "Document type {$type->value} is not an AR/AP opening item type."
            ),
        };
    }

    private function sourceTypeFor(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Invoice => 'invoice',
            DocumentType::CreditNote => 'credit_note',
            DocumentType::SupplierInvoice => 'supplier_invoice',
            DocumentType::SupplierCreditNote => 'supplier_credit_note',
            default => throw new RuntimeException(
                "Document type {$type->value} is not an AR/AP opening item type."
            ),
        };
    }

    private function controlIsDebited(DocumentType $type): bool
    {
        return match ($type) {
            // 411 is debit-normal: an open customer invoice is a debit balance.
            DocumentType::Invoice => true,
            DocumentType::CreditNote => false,
            // 401 is credit-normal: an open supplier invoice is a credit balance.
            DocumentType::SupplierInvoice => false,
            DocumentType::SupplierCreditNote => true,
            default => throw new RuntimeException(
                "Document type {$type->value} is not an AR/AP opening item type."
            ),
        };
    }

    private function moneyScale(?string $currency): int
    {
        return max(
            $this->scaleResolver->getScaleSafe($currency, self::MONEY_STORAGE_SCALE),
            self::MONEY_STORAGE_SCALE,
        );
    }

    /**
     * Allocate the next number in the company's OPENING entry sequence.
     *
     * Deliberately the SAME `OB-{year}-{seq}` sequence and the SAME advisory-lock
     * key as {@see AccountingOpeningService::generateEntryNumber()}: both services
     * mint opening entries for the same company and year, so they must serialize
     * against each other or two concurrent posts read the same max and both mint
     * `OB-{year}-000001` (the loser then fails on the
     * `journal_entries (tenant_id, entry_number)` unique index, which stays as the
     * second line of defence). Changing the key here without changing it there
     * re-opens that race. On SQLite (test runner) the advisory lock is skipped;
     * concurrency is not meaningful there.
     */
    private function generateOpeningEntryNumber(string $companyId): string
    {
        $year = date('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ["gl-ob-seq:{$companyId}:{$year}"],
            );
        }

        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "OB-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        $nextNumber = $lastEntry !== null
            ? ((int) substr($lastEntry->entry_number, -6)) + 1
            : 1;

        return sprintf('OB-%s-%06d', $year, $nextNumber);
    }
}
