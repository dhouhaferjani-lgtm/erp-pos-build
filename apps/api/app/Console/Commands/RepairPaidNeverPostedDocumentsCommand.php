<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\Accounting\FiscalPeriodLockReaderInterface;
use App\Shared\Contracts\Accounting\PaymentLedgerPartitionReaderInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N-6 REPAIR — invoices that are `paid` but were NEVER posted.
 *
 * THE STATE THIS REPAIRS. Before N-6, seven treasury writers flipped a document
 * to `Paid` on a pure TYPE test, and `DocumentPostingService::post()` accepts
 * only `Confirmed`. A payment collected on a confirmed invoice therefore left it
 * at `Paid` with `fiscal_hash IS NULL`: never sealed, never in the hash chain,
 * no invoice journal entry, no revenue, no VAT — and unpostable forever, because
 * `post()` refuses anything that is not `Confirmed`. The first tenant has such a
 * document (INV-2026-0003).
 *
 * WHAT IT DOES, per affected invoice:
 *   1. `paid → confirmed` through {@see DocumentStatusService::repairNeverPostedToConfirmed()},
 *      which re-checks `fiscal_hash IS NULL` itself and refuses to downgrade a
 *      genuinely sealed invoice.
 *   2. Re-books the money: the payment credited the receivable (411) when no
 *      receivable existed. A new, hash-chained entry moves it to the customer
 *      advance (Dr 411 / Cr 419) — the state the N-6 code path would have
 *      produced. The original payment entry is never mutated.
 *   3. Stamps the allocation rows `booked_as_advance = true` with the reclass
 *      entry, so `DocumentPostingService::post()` clears exactly this 419 when
 *      the operator finally posts the invoice.
 *
 * EVIDENCE-GATED. An invoice is SKIPPED, never half-repaired, when:
 *   - its payment journal entry falls in a CLOSED/LOCKED fiscal period (the
 *     reclass is dated on the original entry's date, so writing it would move a
 *     period whose books are shut);
 *   - a reclass entry already exists for that payment (idempotence);
 *   - the invoice carries no payment allocation at all (nothing to re-book — it
 *     reached `Paid` some other way, and that wants a human).
 *
 * INVOCATION — tenant-DB-scoped; run via `tenants:run`. No `--tenant` flag: the
 * tenancy runner switches the default connection per tenant, and a flag would
 * invite half-applied state. stancl/tenancy forwards flags only through
 * repeatable `--option='k=v'` pairs; boolean flags are passed as `=1`:
 *
 *   php artisan tenants:run documents:repair-paid-never-posted --option='dry-run=1'
 *   php artisan tenants:run documents:repair-paid-never-posted --option='execute=1'
 *
 * `--dry-run` is the DEFAULT: `--execute` must be passed explicitly, and passing
 * neither reports without writing.
 *
 * THE EXIT CODE IS NOT A GATE under `tenants:run` (`Run::handle()` returns null
 * after `$this->call(...)`, so the child's status is swallowed). This command
 * therefore emits ONE stable summary token as its last line —
 * `PAID-NEVER-POSTED REPAIR: candidates=<n> repaired=<n> skipped=<n>` — which
 * deploy checklists gate on. ABSENCE of the token means the command aborted
 * before finishing and is itself a failure.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: every table it touches is a TENANT table, so post-2026-05-28
 *   (database-per-tenant) it reads ONLY the tenant database `tenants:run` binds around it. A bare run on the CENTRAL
 *   connection is stopped BEFORE any query by the fail-closed `Schema::hasTable()` guard that opens `handle()`.
 */
final class RepairPaidNeverPostedDocumentsCommand extends Command
{
    /**
     * `journal_entries.source_type` for an AR customer payment — see
     * `GeneralLedgerService::createPaymentReceivedJournalEntry()`. NOT `'payment'`
     * (treasury gate r1 C-1).
     */
    private const CUSTOMER_PAYMENT_SOURCE_TYPE = 'customer_payment';

    protected $signature = 'documents:repair-paid-never-posted
        {--dry-run : Report only (the default; no write is performed)}
        {--execute : Perform the repair}';

    protected $description = 'N-6: move invoices that are paid-but-never-posted back to Confirmed and re-book their payment from the receivable (411) to the customer advance (419).';

    public function __construct(
        private readonly DocumentStatusService $documentStatus,
        private readonly GeneralLedgerService $generalLedger,
        private readonly FiscalPeriodLockReaderInterface $fiscalPeriodLock,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly PaymentLedgerPartitionReaderInterface $partitionReader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('payment_allocations')) {
            $this->error('This command is tenant-scoped. Run it through `php artisan tenants:run documents:repair-paid-never-posted`.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        if ($execute) {
            $this->warn('EXECUTE mode — repairs WILL be written.');
        } else {
            $this->info('DRY RUN — nothing will be written. Pass --execute to apply.');
        }

        $candidates = Document::query()
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Paid)
            ->whereNull('fiscal_hash')
            // Historical opening-balance invoices are Posted-without-a-seal BY
            // DESIGN (`ArApOpeningService`): they were posted in the customer's
            // previous system. They are not the N-6 dead end and must never be
            // moved back to Confirmed.
            ->where(function ($query): void {
                $query->where('is_historical', false)->orWhereNull('is_historical');
            })
            ->orderBy('document_date')
            ->orderBy('document_number')
            ->get();

        $repaired = 0;
        $skipped = 0;

        foreach ($candidates as $invoice) {
            $verdict = $this->assess($invoice);

            $this->line(sprintf(
                '  %s  %s  total=%s  %s',
                $invoice->document_number ?? '(unnumbered)',
                $invoice->id,
                (string) ($invoice->total ?? '0'),
                $verdict['reason'],
            ));

            if (! $verdict['repairable']) {
                $skipped++;

                continue;
            }

            if (! $execute) {
                $repaired++;

                continue;
            }

            $this->repair($invoice, $verdict);
            $repaired++;
        }

        $this->newLine();
        $this->line(sprintf(
            'PAID-NEVER-POSTED REPAIR: candidates=%d repaired=%d skipped=%d',
            $candidates->count(),
            $repaired,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * Decide whether this invoice can be repaired, and gather what the repair
     * needs. Read-only — this is exactly what `--dry-run` reports.
     *
     * @return array{repairable: bool, reason: string, amount: numeric-string, allocationIds: list<string>, paymentIds: list<string>, entryDate: Carbon|null}
     */
    private function assess(Document $invoice): array
    {
        $scale = $this->scaleResolver->getScaleSafe((string) $invoice->currency, 3);

        $allocations = PaymentAllocation::query()
            ->where('document_id', $invoice->id)
            ->whereNotNull('payment_id')
            ->get();

        $empty = [
            'repairable' => false,
            'reason' => '',
            'amount' => '0',
            'allocationIds' => [],
            'paymentIds' => [],
            'entryDate' => null,
        ];

        if ($allocations->isEmpty()) {
            return [...$empty, 'reason' => 'SKIP — no payment allocation; this document reached Paid some other way (needs a human)'];
        }

        if ($allocations->contains(fn (PaymentAllocation $row): bool => $row->booked_as_advance)) {
            return [...$empty, 'reason' => 'SKIP — already marked as an advance (repaired, or authored by the post-N-6 path)'];
        }

        /** @var numeric-string $amount */
        $amount = '0';
        foreach ($allocations as $allocation) {
            /** @var numeric-string $row */
            $row = (string) $allocation->amount;
            $amount = bcadd($amount, $row, $scale);
        }

        if (bccomp($amount, '0', $scale) <= 0) {
            return [...$empty, 'reason' => 'SKIP — allocations net to zero; nothing to re-book'];
        }

        /** @var list<string> $paymentIds */
        $paymentIds = array_values(array_unique(array_filter(
            $allocations->pluck('payment_id')->all(),
            static fn (mixed $id): bool => is_string($id),
        )));

        $alreadyReclassified = JournalEntry::query()
            ->where('company_id', $invoice->company_id)
            ->where('source_type', GeneralLedgerService::PAYMENT_ADVANCE_RECLASS_SOURCE_TYPE)
            ->whereIn('source_id', $paymentIds)
            ->exists();

        if ($alreadyReclassified) {
            return [...$empty, 'reason' => 'SKIP — a reclassification entry already exists for this payment'];
        }

        // N-6 fix round r1 / treasury gate C-1 [CRITICAL] — THE SOURCE TYPE.
        //
        // This read used `source_type = 'payment'`. AR customer payments are
        // written with `source_type = 'customer_payment'`
        // (`GeneralLedgerService::createPaymentReceivedJournalEntry()`); the only
        // writer of `'payment'` is `createPaymentEntry()`, which sets no
        // `source_id` at all. So the lookup was ALWAYS null for the population
        // this command targets, and the code below silently fell back to the
        // INVOICE's `document_date` — which meant (a) the correcting entry landed
        // in the wrong accounting period, the very thing the comment above forbids,
        // and (b) the brief's evidence gate ("refuses if the JE is in a locked
        // period") was evaluated against the wrong date entirely, so a payment
        // booked into a now-CLOSED period could be repaired straight into it.
        //
        // AND THE FALLBACK IS GONE. A repair that cannot find the entry it is
        // restating has no business choosing a date for it: that is now a named
        // SKIP, not a guess.
        $paymentEntry = JournalEntry::query()
            ->where('company_id', $invoice->company_id)
            ->where('source_type', self::CUSTOMER_PAYMENT_SOURCE_TYPE)
            ->whereIn('source_id', $paymentIds)
            ->orderBy('entry_date')
            ->first();

        if (! $paymentEntry instanceof JournalEntry) {
            return [
                ...$empty,
                'reason' => 'SKIP — no posted customer-payment journal entry found for this invoice; nothing to restate (needs a human)',
            ];
        }

        $entryDate = $paymentEntry->entry_date;

        // N-6 fix round r1 / treasury gate I-2 — TAKE EVIDENCE THAT THE MONEY IS
        // ACTUALLY IN 411 BEFORE MOVING IT OUT.
        //
        // The repair unconditionally writes Dr 411 / Cr 419. If the money was
        // never in 411 that INVENTS a receivable debit and DOUBLES the advance.
        // That population is live: a customer deposit applied pre-N-6 through
        // `MultiPaymentService::applyDepositToDocument()` posted no GL at all
        // while the deposit's money already sat in 419, and the old type-only
        // writer still flipped the invoice to `Paid` — giving exactly the
        // paid + unsealed + not-historical shape this command selects.
        //
        // `PaymentLedgerPartitionReader` is the existing primitive for the
        // question, and it is the same one the refund path uses to choose which
        // account to reverse against, so the two can never disagree.
        /** @var numeric-string $arBacked */
        $arBacked = '0';
        foreach ($paymentIds as $paymentId) {
            $partition = $this->partitionReader->read(
                (string) $invoice->company_id,
                $paymentId,
                (string) $invoice->currency,
            );
            $arBacked = bcadd($arBacked, $partition->arBacked, $scale);
        }

        if (bccomp($arBacked, $amount, $scale) < 0) {
            return [
                ...$empty,
                'reason' => sprintf(
                    'SKIP — only %s of %s is backed by a posted receivable credit; the rest is not in 411 and must not be moved out of it (needs a human)',
                    $arBacked,
                    $amount,
                ),
            ];
        }

        if ($this->fiscalPeriodLock->isDateInClosedFiscalPeriod((string) $invoice->company_id, $entryDate)) {
            return [
                ...$empty,
                'reason' => 'SKIP — the payment entry falls in a CLOSED/LOCKED fiscal period ('.$entryDate->toDateString().')',
            ];
        }

        return [
            'repairable' => true,
            'reason' => sprintf(
                'REPAIR — paid -> confirmed, re-book %s from 411 to 419 (%d allocation(s), entry date %s)',
                $amount,
                $allocations->count(),
                $entryDate->toDateString(),
            ),
            'amount' => $amount,
            'allocationIds' => array_values(array_map(
                static fn (PaymentAllocation $row): string => $row->id,
                $allocations->all(),
            )),
            'paymentIds' => $paymentIds,
            'entryDate' => $entryDate,
        ];
    }

    /**
     * @param  array{repairable: bool, reason: string, amount: numeric-string, allocationIds: list<string>, paymentIds: list<string>, entryDate: Carbon|null}  $verdict
     */
    private function repair(Document $invoice, array $verdict): void
    {
        DB::transaction(function () use ($invoice, $verdict): void {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a concurrent post could have sealed it.
            $this->documentStatus->repairNeverPostedToConfirmed($locked);

            $entry = $this->generalLedger->reclassifyCustomerPaymentToAdvance(
                companyId: (string) $locked->company_id,
                partnerId: (string) $locked->partner_id,
                paymentId: $verdict['paymentIds'][0],
                amount: $verdict['amount'],
                date: $verdict['entryDate'] ?? Carbon::now(),
                description: "N-6 repair: payment on unposted invoice {$locked->document_number} re-booked as customer advance",
                postedByUserId: null,
                currencyCode: (string) $locked->currency,
            );

            PaymentAllocation::query()
                ->whereIn('id', $verdict['allocationIds'])
                ->update([
                    'booked_as_advance' => true,
                    'advance_journal_entry_id' => $entry->id,
                    'advance_cleared_at' => null,
                ]);
        });
    }
}
