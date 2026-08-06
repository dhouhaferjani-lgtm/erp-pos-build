<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V6 (2026-08-03 gate, docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md):
 * historical `document_tax_details` rows written before the tax_base /
 * is_stamp_duty fixes (74383eb19, ada81fec9, d8cf49b0e) are wrong and CANNOT
 * self-heal — the gate proved (V6) there is no re-confirm path for invoices
 * or credit notes (`InvoiceController::confirm()` and
 * `CreditNoteController::confirm()` both hard-guard to Draft-only;
 * `DocumentPostingService::revert()` only supports Quote/SalesOrder/
 * PurchaseOrder, none of which reach the VAT declaration). This command is
 * the only remediation path.
 *
 * ORCHESTRATOR RULING (permits this command to exist): gate question D
 * proved tax_base/is_stamp_duty are UNSIGNED derived projections — the
 * fiscal hash chain signs ONLY document_number/posted_at/total/currency
 * (`DocumentPostingService::post()`), and the Fiscal module has zero
 * references to tax_base/is_stamp_duty. Rewriting a document_tax_details
 * row therefore cannot desynchronize a document from its own signed hash
 * or break chain-of-custody verification. This command still asserts that
 * invariant PER DOCUMENT before touching a row (skip + report any document
 * whose recomputed total would differ from its already-signed stored
 * total — the recomputation is not a labeling fix for that document, it is
 * a real value change, and touching its tax details silently would be
 * exactly the kind of retroactive rewrite the ruling does NOT permit).
 *
 * N1 (2026-08-03 re-gate): comparing `total` alone is not sufficient — a
 * document whose header is ALREADY internally inconsistent can still pass
 * that single check by coincidence. Live example on this tenant,
 * INV-2026-0029: zero lines, stored subtotal=250.000, tax_amount=1.000,
 * total=1.000 (subtotal + tax_amount = 251.000 != total — the stored
 * header itself doesn't add up). Recomputed from zero lines: subtotal
 * 0.000, tax 1.000 (stamp only), total 1.000 — which MATCHES the stored
 * total by coincidence, so a total-only guard would have rewritten this
 * document's tax details while leaving its already-broken header alone.
 * Two more checks now gate every document: the STORED header itself must
 * already be self-consistent (subtotal + tax_amount == total), and the
 * RECOMPUTED subtotal must match the STORED subtotal, not just the total.
 *
 * Contract:
 * - DRY-RUN BY DEFAULT. Pass --apply to actually write. Never wired into a
 *   deploy step or scheduler — owner-executed only, one tenant/company at a
 *   time, with the printed before/after aggregates reviewed before the next
 *   run.
 * - Scope: documents of type invoice/credit_note that already carry at
 *   least one document_tax_details row (proof they went through a real
 *   confirm() at some point), PLUS a separate expense leg (below). Expense
 *   documents never go through TaxCalculationService::snapshotTaxDetails()
 *   / calculateDocumentTaxes() (they have no `lines`), so the main leg above
 *   still does not touch them — the expense leg is its own, narrower fix.
 * - Operates against whichever tenant database connection is currently
 *   bound. Invoke per tenant, e.g. via `tenants:run` (fleet) or directly
 *   inside an already-bound tenant context (local/staging).
 *
 * Expense leg (Q2 expert-comptable ruling, 2026-08-06,
 * docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md):
 * ExpenseService::writeDeductibleVatSnapshot() used to write tax_base as the
 * DEDUCTIBLE-PROPORTION base (V5, 2026-08-03 gate) for a partially-deductible
 * expense; the Q2 ruling requires the FULL FACIAL subtotal instead (the DGI
 * cross-matches supplier/customer declared bases, so under-declaring the
 * base creates a cross-matching anomaly). Rows written by the pre-fix
 * writer under-declare the base and need remediation:
 * - Scope: documents of type `expense` carrying a DocumentTaxDetail at
 *   sequence_order=1 with is_stamp_duty=false (the writer's own stable
 *   slot — see writeDeductibleVatSnapshot()) whose tax_base differs from
 *   the document's stored subtotal AND whose expense metadata records
 *   vat_deductible_percent < 100 (at 100% the V5 base already equals the
 *   full subtotal — nothing to fix, and touching it would be a no-op
 *   dressed up as a rewrite).
 * - tax_amount is NEVER touched — only tax_base is rewritten, to the
 *   document's own stored subtotal. No recomputation of the deductible
 *   share is performed, so there is no "recomputed total differs from
 *   signed total" risk the way there is for the invoice/credit-note leg
 *   above (an expense's total/subtotal/tax_amount are independently
 *   attested fields, not derived from lines — this leg touches nothing
 *   that could desynchronize a signed value).
 * - Self-guarding: skips (does not throw) and reports any expense document
 *   missing expense metadata, missing a vat_deductible_percent, with a
 *   null stored subtotal, or missing the `expense_metadata` table
 *   entirely (m-5, 2026-08-06 gate), rather than guessing.
 * - I-1 (2026-08-06 gate, docs/superpowers/reviews/2026-08-06-q2-expense-vat-base-gate.md):
 *   `document_tax_details` has NO unique index on (document_id,
 *   sequence_order) — a pre-V5 `firstOrCreate`-written document can
 *   legitimately carry TWO rows in this writer's sequence_order=1/
 *   is_stamp_duty=false slot. The leg fetches ALL matching rows and, when
 *   more than one exists, SKIPS the document and reports the duplicate
 *   explicitly rather than picking one nondeterministically.
 * - I-2 (2026-08-06 gate): `vat_period_breakdowns` is a MATERIALIZED
 *   SNAPSHOT taken at period close (`VatPeriodManagementService::
 *   persistBreakdowns()`/`closePeriod()`), which this leg does not touch.
 *   After the scan, the leg looks up every CLOSED `VatPeriod` overlapping
 *   a REWRITTEN expense's `document_date` and prints an explicit
 *   REOPEN + RE-CLOSE instruction naming each affected period. It never
 *   reopens or re-closes a period itself.
 * - I-3 (2026-08-06 gate): a 0%-deductible expense (vat_deductible_percent
 *   = 0.00) is OUT OF SCOPE for this leg pending an owner/expert ruling on
 *   whether it should also declare the full facial base — see the
 *   `writeDeductibleVatSnapshot()` docblock. The leg SKIPS AND REPORTS
 *   0%-deductible rows via their own counter line rather than rewriting
 *   them.
 * - DRY-RUN BY DEFAULT / --apply / --company, same contract as the main leg.
 */
final class BackfillTaxDetailsCommand extends Command
{
    protected $signature = 'vat:backfill-tax-details
                            {--apply : Actually rewrite document_tax_details rows. Without this flag the command is a DRY-RUN (default) and writes nothing.}
                            {--company= : Optional company id to scope to a single company within the current tenant}';

    protected $description = 'Recompute document_tax_details (tax_base, is_stamp_duty) for existing invoice/credit-note documents via the CURRENT TaxCalculationService pipeline, plus a separate expense leg that rewrites the declared VAT base to the full facial subtotal per the Q2 expert-comptable ruling. DRY-RUN by default. Owner-executed only -- never wire into an automated deploy step. See docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md V6 and docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md.';

    public function __construct(
        private readonly TaxCalculationService $taxCalculationService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('document_tax_details')) {
            $this->error('Tenant tables are unavailable. Run this command inside a tenant context (for example via tenants:run).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $companyOption = $this->option('company');
        $companyId = is_string($companyOption) && $companyOption !== '' ? $companyOption : null;

        $this->line($apply
            ? '[APPLY] document_tax_details rows WILL be rewritten.'
            : '[DRY-RUN] No rows will be written. Pass --apply to write.');

        $before = $this->aggregateExisting($companyId);

        $query = Document::query()
            ->whereIn('id', DocumentTaxDetail::query()->select('document_id')->distinct())
            ->whereIn('type', [DocumentType::Invoice, DocumentType::CreditNote])
            ->with('lines');
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $scanned = 0;
        $touched = 0;
        /** @var list<array{id: string, number: string, reason: string}> $skipped */
        $skipped = [];
        /** @var array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}> $simulatedAfter */
        $simulatedAfter = [];

        foreach ($query->cursor() as $document) {
            $scanned++;
            $result = $this->taxCalculationService->calculateDocumentTaxes($document);

            // INVARIANT GUARD -- see class docblock (N1). Three checks,
            // ALL must pass before a document is touched:
            /** @var numeric-string $storedSubtotal */
            $storedSubtotal = (string) ($document->subtotal ?? '0');
            /** @var numeric-string $storedTaxAmount */
            $storedTaxAmount = (string) ($document->tax_amount ?? '0');
            /** @var numeric-string $storedTotal */
            $storedTotal = (string) ($document->total ?? '0');
            /** @var numeric-string $recomputedSubtotal */
            $recomputedSubtotal = $result->subtotal;
            /** @var numeric-string $recomputedTotal */
            $recomputedTotal = $result->total;

            // 1) The STORED header must already be self-consistent -- a
            // document whose subtotal + tax_amount doesn't even equal its
            // own stored total is not a candidate for a labeling fix at
            // all, regardless of what total-only comparison (3) would say.
            $headerConsistent = bccomp(bcadd($storedSubtotal, $storedTaxAmount, 3), $storedTotal, 3) === 0;
            // 2) The RECOMPUTED subtotal must match the STORED subtotal --
            // catches a lineless/detached document whose recomputed TOTAL
            // happens to coincide with the stored total by accident (the
            // subtotal component tells a different story).
            $subtotalMatches = bccomp($recomputedSubtotal, $storedSubtotal, 3) === 0;
            // 3) The original guard: recomputed total vs the ALREADY-SIGNED
            // stored total.
            $totalMatches = bccomp($recomputedTotal, $storedTotal, 3) === 0;

            if (! $headerConsistent || ! $subtotalMatches || ! $totalMatches) {
                $reasons = [];
                if (! $headerConsistent) {
                    $reasons[] = sprintf(
                        'stored subtotal %s + tax_amount %s != stored total %s (header already inconsistent)',
                        $storedSubtotal,
                        $storedTaxAmount,
                        $storedTotal,
                    );
                }
                if (! $subtotalMatches) {
                    $reasons[] = sprintf('recomputed subtotal %s != stored subtotal %s', $recomputedSubtotal, $storedSubtotal);
                }
                if (! $totalMatches) {
                    $reasons[] = sprintf('stored total %s != recomputed %s', $storedTotal, $recomputedTotal);
                }

                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) $document->document_number,
                    'reason' => implode('; ', $reasons),
                ];

                // Left untouched -- fold its EXISTING (unmodified) rows
                // into the simulation so the before/after report reflects
                // reality, not a phantom fix that never happened.
                foreach (DocumentTaxDetail::where('document_id', $document->id)->get() as $existing) {
                    /** @var numeric-string $existingBase */
                    $existingBase = (string) ($existing->tax_base ?? '0');
                    /** @var numeric-string $existingAmount */
                    $existingAmount = (string) $existing->tax_amount;
                    $existingRate = CurrencyScale::bcformat((string) ($existing->tax_rate ?? '0'), 2);
                    $this->accumulate($simulatedAfter, $existingRate, (bool) $existing->is_stamp_duty, $existingBase, $existingAmount);
                }

                continue;
            }

            if ($apply) {
                DB::transaction(function () use ($document, $result): void {
                    $this->taxCalculationService->snapshotTaxDetails($document, $result);
                });
            }
            $touched++;

            foreach ($result->taxes as $tax) {
                /** @var numeric-string $taxBase */
                $taxBase = $tax->base;
                /** @var numeric-string $taxAmount */
                $taxAmount = $tax->amount;
                $taxRate = CurrencyScale::bcformat($tax->rate ?? '0', 2);
                $this->accumulate($simulatedAfter, $taxRate, $tax->isStampDuty, $taxBase, $taxAmount);
            }
        }

        $this->line(sprintf(
            'Scanned %d document(s). %s %d. Skipped (header/subtotal/total invariant failed) %d.',
            $scanned,
            $apply ? 'Rewrote' : 'Would rewrite',
            $touched,
            count($skipped),
        ));

        foreach ($skipped as $row) {
            $this->warn(sprintf(
                '  SKIPPED %s (%s): %s -- needs manual review, NOT backfilled',
                $row['number'],
                $row['id'],
                $row['reason'],
            ));
        }

        $this->reportBeforeAfter($before, $simulatedAfter);

        if (! $apply) {
            $this->info('Dry-run only. The AFTER column above is a simulation (not yet written) -- re-run with --apply to write it for real.');
        }

        $this->handleExpenseLeg($apply, $companyId);

        return self::SUCCESS;
    }

    /**
     * Expense leg (Q2 ruling) — see class docblock. Rewrites tax_base on a
     * partially-deductible expense's own DocumentTaxDetail row (sequence
     * order 1, non-stamp) from the V5 deductible-proportion base to the
     * document's full stored subtotal. tax_amount is never touched.
     */
    private function handleExpenseLeg(bool $apply, ?string $companyId): void
    {
        $this->line('');
        $this->line('Expense leg (Q2 ruling, declared base = full facial subtotal):');

        // m-5 (2026-08-06 gate): the main leg guards `documents` and
        // `document_tax_details` at the top of handle(); this leg also
        // eager-loads `expense_metadata`, which needs the same guard --
        // skip (not throw) when the table is unavailable.
        if (! Schema::hasTable('expense_metadata')) {
            $this->warn('  expense_metadata table unavailable on this tenant -- expense leg skipped.');

            return;
        }

        $query = Document::query()
            ->where('type', DocumentType::Expense)
            ->whereIn('id', DocumentTaxDetail::query()
                ->select('document_id')
                ->where('sequence_order', 1)
                ->where('is_stamp_duty', false))
            ->with('expenseMetadata');
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $scanned = 0;
        $touched = 0;
        $zeroDeductibleSkipped = 0;
        /** @var list<array{id: string, number: string, reason: string}> $skipped */
        $skipped = [];
        /** @var numeric-string $baseDelta */
        $baseDelta = '0';
        /** @var array<string, VatPeriod> $affectedClosedPeriods */
        $affectedClosedPeriods = [];

        foreach ($query->cursor() as $document) {
            // m-2 (2026-08-06 gate): count every candidate document BEFORE
            // any skip branch, so "Scanned" reflects how many expenses the
            // leg actually examined -- not just the ones it rewrote.
            $scanned++;

            // I-1 (2026-08-06 gate): fetch ALL rows in this writer's slot,
            // not just one. `document_tax_details` has no unique index on
            // (document_id, sequence_order); a pre-V5 `firstOrCreate`
            // write can legitimately leave two rows here. Picking one with
            // `->first()` is nondeterministic and can silently
            // mis-remediate (see the class docblock). Skip and report
            // instead of guessing.
            $details = DocumentTaxDetail::query()
                ->where('document_id', $document->id)
                ->where('sequence_order', 1)
                ->where('is_stamp_duty', false)
                ->get();

            if ($details->count() > 1) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'reason' => sprintf(
                        '%d rows at sequence_order=1 -- duplicate legacy snapshot, manual review required',
                        $details->count(),
                    ),
                ];

                continue;
            }

            $detail = $details->first();
            if ($detail === null) {
                // The whereIn subquery guarantees a matching row existed at
                // query time; a concurrent delete between then and now is
                // not impossible -- skip rather than throw.
                continue;
            }

            $metadata = $document->expenseMetadata;
            if ($metadata === null) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'reason' => 'no expense_metadata row -- cannot read vat_deductible_percent',
                ];

                continue;
            }

            $rawDeductiblePercent = $metadata->vat_deductible_percent;
            if ($rawDeductiblePercent === null) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'reason' => 'vat_deductible_percent is null -- cannot determine whether the base needs correcting',
                ];

                continue;
            }

            $deductiblePercent = (string) $rawDeductiblePercent;

            // I-3 (2026-08-06 gate): 0%-deductible expenses now declare the
            // FULL facial base with 0.000 deducted VAT -- outside the
            // ticket's stated 80%-case scope, with an owner/expert ruling
            // still PENDING (see writeDeductibleVatSnapshot() docblock).
            // Conservative interim handling: skip and report rather than
            // rewrite while the ruling is open.
            if (bccomp($deductiblePercent, '0', 2) === 0) {
                $zeroDeductibleSkipped++;

                continue;
            }

            if (bccomp($deductiblePercent, '100', 2) >= 0) {
                // 100% deductible: the V5 prorated base already equals the
                // full subtotal. Not a candidate.
                continue;
            }

            $rawSubtotal = $document->subtotal;
            if ($rawSubtotal === null) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'reason' => 'stored subtotal is null -- cannot determine the full facial base',
                ];

                continue;
            }

            $scale = $this->scaleResolver->getScale((string) $document->currency);
            /** @var numeric-string $subtotal */
            $subtotal = CurrencyScale::bcformatStrict((string) $rawSubtotal, $scale);
            /** @var numeric-string $storedBase */
            $storedBase = CurrencyScale::bcformatStrict((string) ($detail->tax_base ?? '0'), $scale);

            if (bccomp($storedBase, $subtotal, $scale) === 0) {
                // Already the full subtotal -- nothing to fix (covers
                // rows already migrated to the Q2 shape, or coincidental
                // matches).
                continue;
            }

            if ($apply) {
                DB::transaction(function () use ($detail, $subtotal): void {
                    $detail->tax_base = $subtotal;
                    $detail->save();
                });
            }
            $touched++;
            $baseDelta = bcadd($baseDelta, bcsub($subtotal, $storedBase, $scale), $scale);

            // I-2 (2026-08-06 gate): a rewritten row inside an already
            // CLOSED period leaves that period's materialized
            // vat_period_breakdowns snapshot stale. Record the period so
            // the report can name it and instruct the operator to reopen
            // + re-close it -- this command never does so automatically.
            $documentDate = $document->document_date->toDateString();
            $closedPeriod = VatPeriod::query()
                ->where('company_id', $document->company_id)
                ->where('status', VatPeriodStatus::Closed)
                ->where('period_start', '<=', $documentDate)
                ->where('period_end', '>=', $documentDate)
                ->first();
            if ($closedPeriod !== null) {
                $affectedClosedPeriods[$closedPeriod->id] = $closedPeriod;
            }
        }

        $this->line(sprintf(
            '  Scanned %d expense document(s) carrying an eligible input-VAT row. %s %d. Skipped %d.',
            $scanned,
            $apply ? 'Rewrote' : 'Would rewrite',
            $touched,
            count($skipped),
        ));
        $this->line(sprintf('  Cumulative declared-base delta (AFTER - BEFORE): %s', $baseDelta));
        $this->line(sprintf('  0%%-deductible: awaiting ruling, skipped %d document(s).', $zeroDeductibleSkipped));

        foreach ($skipped as $row) {
            $this->warn(sprintf(
                '  SKIPPED %s (%s): %s -- needs manual review, NOT backfilled',
                $row['number'],
                $row['id'],
                $row['reason'],
            ));
        }

        if ($affectedClosedPeriods !== []) {
            $this->line('');
            $this->warn('  CLOSED-PERIOD IMPACT -- vat_period_breakdowns is a snapshot taken at period close and is now STALE for:');
            foreach ($affectedClosedPeriods as $period) {
                $this->warn(sprintf(
                    '    - %s (%s, %s to %s): reopen this period then re-close it to refresh its breakdowns. This command does NOT do so automatically.',
                    $period->label,
                    $period->id,
                    $period->period_start->toDateString(),
                    $period->period_end->toDateString(),
                ));
            }
        }

        if (! $apply && $touched > 0) {
            $this->info('Dry-run only for the expense leg -- re-run with --apply to write it for real.');
        }
    }

    /**
     * @param  array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}>  $accumulator
     */
    /**
     * @param  array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}>  &$accumulator
     * @param  numeric-string  $base
     * @param  numeric-string  $amount
     */
    private function accumulate(array &$accumulator, string $rate, bool $isStampDuty, string $base, string $amount): void
    {
        $key = $rate.'|'.($isStampDuty ? 'stamp' : 'vat');
        if (! isset($accumulator[$key])) {
            $accumulator[$key] = [
                'rate' => $rate,
                'is_stamp_duty' => $isStampDuty,
                'base' => '0',
                'tax' => '0',
                'count' => 0,
            ];
        }
        $accumulator[$key]['base'] = bcadd($accumulator[$key]['base'], $base, 3);
        $accumulator[$key]['tax'] = bcadd($accumulator[$key]['tax'], $amount, 3);
        $accumulator[$key]['count']++;
    }

    /**
     * Straight-from-the-DB aggregates, grouped by (tax_rate, is_stamp_duty)
     * -- this is exactly the bucketing where a mis-flagged stamp row hides:
     * a STAMP_TAX_INVOICE row with is_stamp_duty=false lands in the SAME
     * rate=0.00/vat bucket as a genuine 0% exempt line.
     *
     * @return array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}>
     */
    private function aggregateExisting(?string $companyId): array
    {
        $query = DB::table('document_tax_details as dtd')
            ->join('documents as d', 'dtd.document_id', '=', 'd.id')
            ->whereIn('d.type', ['invoice', 'credit_note']);
        if ($companyId !== null) {
            $query->where('d.company_id', $companyId);
        }

        $rows = $query->selectRaw('
                dtd.tax_rate,
                dtd.is_stamp_duty,
                SUM(dtd.tax_base) as base_amount,
                SUM(dtd.tax_amount) as tax_amount,
                COUNT(*) as row_count
            ')
            ->groupBy('dtd.tax_rate', 'dtd.is_stamp_duty')
            ->orderBy('dtd.tax_rate')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            // Normalize to 2dp regardless of driver formatting (a raw,
            // non-Eloquent-cast DB read can return "19" where the
            // Eloquent-cast side of this command reliably returns "19.00"),
            // so the bucket key always matches its $simulatedAfter
            // counterpart.
            $rate = CurrencyScale::bcformat($row->tax_rate, 2);
            $isStampDuty = (bool) $row->is_stamp_duty;
            $key = $rate.'|'.($isStampDuty ? 'stamp' : 'vat');
            $result[$key] = [
                'rate' => $rate,
                'is_stamp_duty' => $isStampDuty,
                'base' => CurrencyScale::bcformat($row->base_amount, 3),
                'tax' => CurrencyScale::bcformat($row->tax_amount, 3),
                'count' => (int) $row->row_count,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}>  $before
     * @param  array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}>  $after
     */
    private function reportBeforeAfter(array $before, array $after): void
    {
        $this->line('');
        $this->line('BEFORE -> AFTER (per rate / stamp flag):');

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys);

        foreach ($keys as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            $rate = $a['rate'] ?? $b['rate'] ?? '?';
            $flag = ($a['is_stamp_duty'] ?? $b['is_stamp_duty'] ?? false) ? 'STAMP' : 'VAT';

            $this->line(sprintf(
                '  rate=%-6s %-5s  base %10s -> %10s   tax %10s -> %10s   rows %4d -> %4d',
                $rate,
                $flag,
                $b['base'] ?? '-',
                $a['base'] ?? '-',
                $b['tax'] ?? '-',
                $a['tax'] ?? '-',
                $b['count'] ?? 0,
                $a['count'] ?? 0,
            ));
        }

        if ($keys === []) {
            $this->line('  (no document_tax_details rows for invoice/credit_note in scope)');
        }
    }
}
