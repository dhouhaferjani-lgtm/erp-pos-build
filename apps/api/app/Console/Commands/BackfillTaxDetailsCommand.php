<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
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
 * Contract:
 * - DRY-RUN BY DEFAULT. Pass --apply to actually write. Never wired into a
 *   deploy step or scheduler — owner-executed only, one tenant/company at a
 *   time, with the printed before/after aggregates reviewed before the next
 *   run.
 * - Scope: documents of type invoice/credit_note that already carry at
 *   least one document_tax_details row (proof they went through a real
 *   confirm() at some point). Expense documents are OUT OF SCOPE — their
 *   tax details are written by ExpenseService's own writer, not
 *   TaxCalculationService::snapshotTaxDetails(), and have no `lines` for
 *   calculateDocumentTaxes() to read.
 * - Operates against whichever tenant database connection is currently
 *   bound. Invoke per tenant, e.g. via `tenants:run` (fleet) or directly
 *   inside an already-bound tenant context (local/staging).
 */
final class BackfillTaxDetailsCommand extends Command
{
    protected $signature = 'vat:backfill-tax-details
                            {--apply : Actually rewrite document_tax_details rows. Without this flag the command is a DRY-RUN (default) and writes nothing.}
                            {--company= : Optional company id to scope to a single company within the current tenant}';

    protected $description = 'Recompute document_tax_details (tax_base, is_stamp_duty) for existing invoice/credit-note documents via the CURRENT TaxCalculationService pipeline. DRY-RUN by default. Owner-executed only -- never wire into an automated deploy step. See docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md V6.';

    public function __construct(
        private readonly TaxCalculationService $taxCalculationService,
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
        /** @var list<array{id: string, number: string, stored_total: string, recomputed_total: string}> $skipped */
        $skipped = [];
        /** @var array<string, array{rate: string, is_stamp_duty: bool, base: numeric-string, tax: numeric-string, count: int}> $simulatedAfter */
        $simulatedAfter = [];

        foreach ($query->cursor() as $document) {
            $scanned++;
            $result = $this->taxCalculationService->calculateDocumentTaxes($document);

            // INVARIANT GUARD -- see class docblock. Compare against the
            // ALREADY-SIGNED stored total; never write when they disagree.
            /** @var numeric-string $storedTotal */
            $storedTotal = (string) ($document->total ?? '0');
            /** @var numeric-string $recomputedTotal */
            $recomputedTotal = $result->total;
            if (bccomp($recomputedTotal, $storedTotal, 3) !== 0) {
                $skipped[] = [
                    'id' => (string) $document->id,
                    'number' => (string) $document->document_number,
                    'stored_total' => $storedTotal,
                    'recomputed_total' => $recomputedTotal,
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
            'Scanned %d document(s). %s %d. Skipped (signed total would change) %d.',
            $scanned,
            $apply ? 'Rewrote' : 'Would rewrite',
            $touched,
            count($skipped),
        ));

        foreach ($skipped as $row) {
            $this->warn(sprintf(
                '  SKIPPED %s (%s): stored total %s != recomputed %s -- needs manual review, NOT backfilled',
                $row['number'],
                $row['id'],
                $row['stored_total'],
                $row['recomputed_total'],
            ));
        }

        $this->reportBeforeAfter($before, $simulatedAfter);

        if (! $apply) {
            $this->info('Dry-run only. The AFTER column above is a simulation (not yet written) -- re-run with --apply to write it for real.');
        }

        return self::SUCCESS;
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
