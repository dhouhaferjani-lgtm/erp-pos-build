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
 *   a REWRITTEN (or I-3-remediated) expense's `document_date` and prints
 *   an explicit REOPEN + RE-CLOSE instruction naming each affected period.
 *   It never reopens or re-closes a period itself.
 * - m-7/m-8 (2026-08-06 re-gate, folded into R2-G): the period lookup now
 *   ALSO matches `FILED` periods — reported in their OWN "FILED-PERIOD
 *   IMPACT" section with an escalation message, since `reopenPeriod()`
 *   refuses filed periods ("Only closed periods can be reopened") and the
 *   reopen/re-close remedy above does not apply to them. The lookup also
 *   uses `->get()` instead of `->first()` and merges every match (a
 *   monthly + quarterly period after a `period_type` switch, or two
 *   `country_code` rows, can legitimately overlap one document_date) so a
 *   second overlapping period is never silently dropped.
 * - I-3 — EXPERT RULING RECEIVED 2026-08-07 (
 *   docs/superpowers/tickets/2026-08-06-q2-gate-minor-followups.md): a
 *   0%-deductible expense (vat_deductible_percent = 0.00) is EXCLUDED
 *   ENTIRELY from the VAT declaration — see the `writeDeductibleVatSnapshot()`
 *   docblock for the verbatim ruling. This leg now REMEDIATES (rather than
 *   skips) 0%-deductible rows: it DELETES the writer's sequence_order=1/
 *   is_stamp_duty=false row outright, covering BOTH pre-ruling shapes —
 *   the V5-era row (tax_base 0.000/tax_amount 0.000, prorated at 0%) and
 *   the interim Q2 row (tax_base = full subtotal/tax_amount 0.000) — the
 *   branch keys purely on `vat_deductible_percent`, never on the row's
 *   current tax_base, so it catches both shapes identically. Deleting is
 *   idempotent by construction: a re-run's `WHERE` clause (a
 *   sequence_order=1/is_stamp_duty=false row must exist) no longer matches
 *   the document once its row is gone, so it drops out of the scan
 *   entirely on the next run. Duplicate-slot rows (I-1, >1 row in the slot)
 *   are still skipped-and-reported BEFORE this branch runs, regardless of
 *   percent.
 * - IMP-2 (2026-08-07 gate, docs/superpowers/reviews/2026-08-07-r2g-backend-gate.md):
 *   `DocumentTaxDetail` has no `SoftDeletes`, and the original I-3
 *   remediation let `--apply` delete a row inside an ALREADY-FILED VAT
 *   period, with the FILED-PERIOD IMPACT warning printing only AFTER that
 *   mutation. Both mutation paths (0%-deductible deletion and
 *   partially-deductible base rewrite) now check FILED-period membership
 *   BEFORE deciding to write: a document whose `document_date` falls
 *   inside an ALREADY-FILED period is SKIPPED (reported, never mutated)
 *   under BOTH dry-run and --apply unless `--include-filed` is also
 *   passed. The FILED-PERIOD IMPACT section always prints when any FILED
 *   period is found, in every mode, independent of whether the mutation
 *   was allowed.
 * - IMP-3 (2026-08-07 gate): a 0%-deductible deletion is a HARD delete
 *   with no audit trail of its own, so the printed output is the ONLY
 *   surviving record a row ever existed. Every deletion (would-delete or
 *   deleted) is reported with a FULL ROW SNAPSHOT — document number, id,
 *   tax_base, tax_amount, tax_rate — captured before the delete, in BOTH
 *   dry-run and --apply, not merely a document reference list.
 * - DRY-RUN BY DEFAULT / --apply / --company / --include-filed, same
 *   contract as the main leg (--include-filed is expense-leg-only; the
 *   main invoice/credit-note leg does not delete rows and is unaffected).
 */
final class BackfillTaxDetailsCommand extends Command
{
    protected $signature = 'vat:backfill-tax-details
                            {--apply : Actually rewrite/delete document_tax_details rows. Without this flag the command is a DRY-RUN (default) and writes nothing.}
                            {--company= : Optional company id to scope to a single company within the current tenant}
                            {--include-filed : IMP-2 (2026-08-07 gate). Without this flag, expense-leg documents whose document_date falls inside an ALREADY-FILED VAT period are SKIPPED (never rewritten/deleted) even under --apply -- FILED is the highest-consequence case (see the FILED-PERIOD IMPACT section). Pass this flag to allow the mutation anyway.}';

    protected $description = 'Recompute document_tax_details (tax_base, is_stamp_duty) for existing invoice/credit-note documents via the CURRENT TaxCalculationService pipeline, plus a separate expense leg that rewrites the declared VAT base to the full facial subtotal for partially-deductible expenses AND DELETES the row entirely for 0%-deductible expenses (I-3 expert-comptable ruling). DRY-RUN by default; documents inside an ALREADY-FILED VAT period are skipped unless --include-filed is also passed. Owner-executed only -- never wire into an automated deploy step. See docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md V6, docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md, and docs/superpowers/reviews/2026-08-07-r2g-backend-gate.md.';

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
        $includeFiled = (bool) $this->option('include-filed');
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

        $this->handleExpenseLeg($apply, $companyId, $includeFiled);

        return self::SUCCESS;
    }

    /**
     * Expense leg (Q2 ruling) — see class docblock. Rewrites tax_base on a
     * partially-deductible expense's own DocumentTaxDetail row (sequence
     * order 1, non-stamp) from the V5 deductible-proportion base to the
     * document's full stored subtotal, and DELETES that row entirely for a
     * 0%-deductible expense (I-3 ruling). tax_amount is never touched by
     * the rewrite path. IMP-2 (2026-08-07 gate): either mutation is
     * refused for a document whose document_date falls inside an
     * ALREADY-FILED VAT period unless `--include-filed` is passed — see
     * `recordPeriodImpact()`.
     */
    private function handleExpenseLeg(bool $apply, ?string $companyId, bool $includeFiled): void
    {
        $this->line('');
        $this->line('Expense leg (Q2 ruling, declared base = full facial subtotal; I-3 ruling, 0%-deductible rows DELETED):');

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
        /** @var list<array{id: string, number: string, reason: string}> $skipped */
        $skipped = [];
        /** @var numeric-string $baseDelta */
        $baseDelta = '0';
        // IMP-3 (2026-08-07 gate): DocumentTaxDetail is a HARD delete with
        // no SoftDeletes/audit event -- this row snapshot (id, number,
        // tax_base, tax_amount, tax_rate) captured BEFORE the delete is the
        // ONLY record a deleted row ever existed, so it is collected (and
        // printed) in BOTH dry-run and --apply, not just on write.
        /** @var list<array{id: string, number: string, tax_base: string, tax_amount: string, tax_rate: string|null}> $zeroDeductibleRemediated */
        $zeroDeductibleRemediated = [];
        /** @var array<string, VatPeriod> $affectedClosedPeriods */
        $affectedClosedPeriods = [];
        /** @var array<string, VatPeriod> $affectedFiledPeriods */
        $affectedFiledPeriods = [];
        // minor-3 (2026-08-07 gate): memoise the period lookup per
        // (company_id, document_date) so a batch of same-day documents only
        // queries VatPeriod once for that date instead of once per document.
        /** @var array<string, array{closed: list<VatPeriod>, filed: list<VatPeriod>}> $periodLookupCache */
        $periodLookupCache = [];

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

            // I-3 EXPERT RULING (2026-08-07): 0%-deductible expenses are
            // excluded entirely from the VAT declaration -- see the class
            // docblock. Delete the row outright; this keys purely on the
            // percent, not on the row's current tax_base, so it remediates
            // BOTH pre-ruling shapes (V5-era 0.000/0.000 and interim
            // full-base/0.000) identically.
            if (bccomp($deductiblePercent, '0', 2) === 0) {
                // IMP-2 (2026-08-07 gate): check FILED-period membership
                // BEFORE deciding to delete, not after -- the FILED-PERIOD
                // IMPACT section must never trail the mutation it warns
                // about. Without --include-filed this row is left
                // untouched, in BOTH dry-run and --apply.
                $isFiled = $this->recordPeriodImpact(
                    $document,
                    $affectedClosedPeriods,
                    $affectedFiledPeriods,
                    $periodLookupCache,
                );

                if ($isFiled && ! $includeFiled) {
                    $skipped[] = [
                        'id' => (string) $document->id,
                        'number' => (string) ($document->document_number ?? $document->id),
                        'reason' => 'document_date falls inside an ALREADY-FILED VAT period -- deletion refused; pass --include-filed to override',
                    ];

                    continue;
                }

                $snapshot = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'tax_base' => (string) ($detail->tax_base ?? '0'),
                    'tax_amount' => (string) $detail->tax_amount,
                    'tax_rate' => $detail->tax_rate !== null ? (string) $detail->tax_rate : null,
                ];
                $zeroDeductibleRemediated[] = $snapshot;

                // B-1 (2026-08-07 re-gate): the snapshot line is the ONLY
                // surviving record of a hard-deleted row, so it must reach
                // the output BEFORE the delete commits — a crash mid-scan
                // must never leave a committed deletion with no printed
                // record. The end-of-run section keeps the count summary.
                $this->line(sprintf(
                    '    - %s (%s): tax_base=%s tax_amount=%s tax_rate=%s%s',
                    $snapshot['number'],
                    $snapshot['id'],
                    $snapshot['tax_base'],
                    $snapshot['tax_amount'],
                    $snapshot['tax_rate'] ?? 'null',
                    $apply ? ' -- deleting' : ' -- would delete',
                ));

                if ($apply) {
                    DB::transaction(function () use ($detail): void {
                        $detail->delete();
                    });
                }

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

            // I-2 (2026-08-06 gate) + IMP-2 (2026-08-07 gate): a rewritten
            // row inside an already CLOSED (or FILED -- m-7) period leaves
            // that period's materialized vat_period_breakdowns snapshot (or
            // frozen declaration) stale. Record it BEFORE deciding whether
            // to write, so the FILED-PERIOD IMPACT section can never trail
            // the mutation it warns about -- and so a FILED-period document
            // is refused (skipped) unless --include-filed is passed. This
            // command never touches a period itself.
            $isFiled = $this->recordPeriodImpact(
                $document,
                $affectedClosedPeriods,
                $affectedFiledPeriods,
                $periodLookupCache,
            );

            if ($isFiled && ! $includeFiled) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->document_number ?? $document->id),
                    'reason' => 'document_date falls inside an ALREADY-FILED VAT period -- base rewrite refused; pass --include-filed to override',
                ];

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
        }

        $this->line(sprintf(
            '  Scanned %d expense document(s) carrying an eligible input-VAT row. %s %d. Skipped %d.',
            $scanned,
            $apply ? 'Rewrote' : 'Would rewrite',
            $touched,
            count($skipped),
        ));
        $this->line(sprintf('  Cumulative declared-base delta (AFTER - BEFORE): %s', $baseDelta));
        $this->line(sprintf(
            '  0%%-deductible (I-3 ruling -- excluded from the declaration): %s %d document(s).',
            $apply ? 'Deleted' : 'Would delete',
            count($zeroDeductibleRemediated),
        ));
        // IMP-3 (2026-08-07 gate) + B-1 (re-gate): the FULL row snapshots
        // print at capture time inside the scan loop, BEFORE each delete
        // commits (crash-safety: a mid-scan crash never leaves a committed
        // deletion with no printed record). This end-of-run section is the
        // SUMMARY reference list only.
        foreach ($zeroDeductibleRemediated as $row) {
            $this->line(sprintf(
                '    - %s (%s): tax_base=%s tax_amount=%s tax_rate=%s',
                $row['number'],
                $row['id'],
                $row['tax_base'],
                $row['tax_amount'],
                $row['tax_rate'] ?? 'null',
            ));
        }

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

        if ($affectedFiledPeriods !== []) {
            $this->line('');
            $this->error(sprintf(
                '  FILED-PERIOD IMPACT -- these periods are ALREADY FILED; reopenPeriod() REFUSES filed periods, so do NOT attempt reopen+re-close. Affected documents are SKIPPED (not mutated) unless --include-filed is passed%s:',
                $includeFiled ? ' -- THIS RUN PASSED --include-filed, so affected documents WERE mutated' : '',
            ));
            foreach ($affectedFiledPeriods as $period) {
                $this->error(sprintf(
                    '    - %s (%s, %s to %s): the filed declaration is now stale for this document. Remedy: a filed-declaration correction (amended/corrective filing) -- ESCALATE TO THE ACCOUNTANT before taking any action.',
                    $period->label,
                    $period->id,
                    $period->period_start->toDateString(),
                    $period->period_end->toDateString(),
                ));
            }
        }

        if (! $apply && ($touched > 0 || $zeroDeductibleRemediated !== [])) {
            $this->info('Dry-run only for the expense leg -- re-run with --apply to write it for real.');
        }
    }

    /**
     * I-2 (2026-08-06 gate) + m-7/m-8 (2026-08-06 re-gate): look up every
     * VatPeriod overlapping a candidate expense's document_date whose
     * status is CLOSED or FILED, and merge every match into the
     * corresponding report bucket by reference. `->get()` (not `->first()`)
     * so two overlapping periods for the same company (e.g. a monthly +
     * quarterly period after a `period_type` switch, or two `country_code`
     * rows) are both reported, not just the first one found.
     *
     * IMP-2 (2026-08-07 gate): called BEFORE any mutation decision (not
     * after, as before) so the caller can gate a FILED-period document's
     * delete/rewrite on the return value — the printed FILED-PERIOD IMPACT
     * section must never trail the write it warns about.
     *
     * minor-3 (2026-08-07 gate): memoises the VatPeriod query per
     * `company_id|document_date` in `$periodLookupCache` so a batch of
     * same-day documents (a common case: an import or a bulk entry
     * session) issues one query per unique date instead of one per
     * document.
     *
     * @param  array<string, VatPeriod>  $affectedClosedPeriods
     * @param  array<string, VatPeriod>  $affectedFiledPeriods
     * @param  array<string, array{closed: list<VatPeriod>, filed: list<VatPeriod>}>  $periodLookupCache
     * @return bool true if this document overlaps at least one FILED period
     */
    private function recordPeriodImpact(
        Document $document,
        array &$affectedClosedPeriods,
        array &$affectedFiledPeriods,
        array &$periodLookupCache,
    ): bool {
        $documentDate = $document->document_date->toDateString();
        $cacheKey = $document->company_id.'|'.$documentDate;

        if (! isset($periodLookupCache[$cacheKey])) {
            $overlappingPeriods = VatPeriod::query()
                ->where('company_id', $document->company_id)
                ->whereIn('status', [VatPeriodStatus::Closed, VatPeriodStatus::Filed])
                ->where('period_start', '<=', $documentDate)
                ->where('period_end', '>=', $documentDate)
                ->get();

            /** @var list<VatPeriod> $closed */
            $closed = [];
            /** @var list<VatPeriod> $filed */
            $filed = [];
            foreach ($overlappingPeriods as $period) {
                if ($period->status === VatPeriodStatus::Filed) {
                    $filed[] = $period;
                } else {
                    $closed[] = $period;
                }
            }
            $periodLookupCache[$cacheKey] = ['closed' => $closed, 'filed' => $filed];
        }

        foreach ($periodLookupCache[$cacheKey]['closed'] as $period) {
            $affectedClosedPeriods[$period->id] = $period;
        }
        foreach ($periodLookupCache[$cacheKey]['filed'] as $period) {
            $affectedFiledPeriods[$period->id] = $period;
        }

        return $periodLookupCache[$cacheKey]['filed'] !== [];
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
