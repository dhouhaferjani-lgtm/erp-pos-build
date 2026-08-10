<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Shared\Contracts\Accounting\PaymentLedgerPartition;
use App\Shared\Contracts\Accounting\PaymentLedgerPartitionReaderInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * DPA `DPA-REV2-A` (A5, predicate per A-D4) — the lane's sole GL-shape selector.
 *
 * ```
 * arBacked      = Σ credit on lines whose account_id = CustomerReceivable
 *                 over POSTED journal_entries where source_id = P.id
 *                 AND source_type = 'customer_payment'
 * advanceBacked = Σ credit on lines whose account_id = CustomerAdvance
 *                 over POSTED journal_entries where source_id = P.id
 *                 AND source_type = 'advance'
 * ```
 *
 * Four rulings are baked into that predicate, each deliberate:
 *
 * 1. **Lines are identified by purpose-resolved `account_id`, never by
 *    `line_order`.** `line_order` is positional and identical across three
 *    different builders (`createCustomerAdvanceJournalEntry`,
 *    `createPaymentReceivedJournalEntry`, `createPaymentRefundJournalEntry`), so
 *    keying on it would silently pick the wrong leg.
 * 2. **`'payment'` is NOT in the predicate.** `createPaymentEntry()` writes
 *    `source_type='payment'` but no `source_id` at all, so `source_id = P.id`
 *    could never match — and it has zero callers.
 * 3. **DRAFT entries do not count.** A failed `AfterCommit` post leaves a Draft;
 *    counting only `posted` means the partition UNDER-counts and the caller's
 *    coverage belt refuses. Fail-closed is right: a Draft is money we cannot
 *    prove was recognised.
 * 4. **Scale comes from the ENTITY currency passed by the caller** (rule 19),
 *    never a no-arg `getScale()` — this runs in queued/console contexts where no
 *    `CompanyContext` is bound and a bare `getScale()` throws.
 *
 * **Non-inheritance note.** `PaymentAllocationService.php:353`/`:381` compare
 * money at a hardcoded scale 4 (`bccomp($totalAllocated, '0', 4)`). That idiom is
 * pre-existing and outside this lane's edit surface, but this reader will be read
 * alongside it: this class takes its scale from the entity currency and
 * deliberately does **not** inherit that pattern.
 *
 * The purposes are resolved with the NON-throwing probe. A chart that has not
 * mapped `CustomerAdvance` (every French company before `DPA-REV2-A` A2/A3)
 * yields a zero bucket rather than a 500 — and a zero bucket is what the
 * caller's coverage belt is there to refuse.
 */
final readonly class PaymentLedgerPartitionReader implements PaymentLedgerPartitionReaderInterface
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function read(string $companyId, string $paymentId, string $currency): PaymentLedgerPartition
    {
        $scale = $this->scaleResolver->getScale($currency);

        /** @var numeric-string $arBacked */
        $arBacked = $this->creditTotal(
            $companyId,
            $paymentId,
            'customer_payment',
            SystemAccountPurpose::CustomerReceivable,
            $scale,
        );

        /** @var numeric-string $advanceBacked */
        $advanceBacked = $this->creditTotal(
            $companyId,
            $paymentId,
            'advance',
            SystemAccountPurpose::CustomerAdvance,
            $scale,
        );

        return new PaymentLedgerPartition($arBacked, $advanceBacked, $scale);
    }

    /**
     * @return numeric-string
     */
    private function creditTotal(
        string $companyId,
        string $paymentId,
        string $sourceType,
        SystemAccountPurpose $purpose,
        int $scale,
    ): string {
        $account = Account::findByPurpose($companyId, $purpose);

        if (! $account instanceof Account) {
            // Unmapped purpose: report zero rather than throwing. The caller's
            // coverage belt turns that into a refusal, which is the fail-closed
            // direction — this reader never decides policy.
            return $this->zero($scale);
        }

        /** @var object{total: string|null}|null $row */
        $row = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.source_id', $paymentId)
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->where('journal_lines.account_id', $account->id)
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.credit), 0) AS TEXT) as total')
            ->first();

        $total = $row->total ?? '0';

        if (! is_numeric($total)) {
            return $this->zero($scale);
        }

        /** @var numeric-string $normalised */
        $normalised = bcadd($total, '0', $scale);

        return $normalised;
    }

    /**
     * @return numeric-string
     */
    private function zero(int $scale): string
    {
        /** @var numeric-string $zero */
        $zero = bcadd('0', '0', $scale);

        return $zero;
    }
}
