<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Repositories;

use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

class EloquentVatDataRepository implements VatDataRepositoryInterface
{
    /**
     * V2 (2026-08-03 gate, P0): credit notes must REDUCE the declared OUTPUT
     * base/VAT, not add to it. document_tax_details rows for a credit note
     * are stored POSITIVE (CreditNoteService::materializeLinesFromAllocation()
     * writes positive quantities/unit prices) -- ORCHESTRATOR RULING:
     * negate at AGGREGATION, not storage, so the immutable-snapshot
     * convention is preserved (a document_tax_details row always reads as
     * "this much base/tax on this document", never sign-overloaded) and
     * every credit-note row already written before this fix becomes correct
     * automatically the next time this query runs -- no backfill needed for
     * this specific defect (unlike V4/tax_base and V5/is_stamp_duty, which
     * ARE baked into the stored row and DO need V6's backfill).
     */
    public function aggregateByRateAndDirection(string $companyId, string $dateFrom, string $dateTo): array
    {
        // Document-based VAT aggregation
        $documentQuery = DB::table('document_tax_details as dtd')
            ->join('documents as d', 'dtd.document_id', '=', 'd.id')
            ->leftJoin('tax_configurations as tc', function ($join) use ($companyId): void {
                $join->on('dtd.tax_rate', '=', 'tc.percentage_rate')
                    ->on('tc.country_code', '=', DB::raw(
                        '(SELECT country_code FROM companies WHERE id = '.DB::getPdo()->quote($companyId).')'
                    ))
                    ->where('tc.is_active', true)
                    ->where('tc.is_stamp_duty', false);
            })
            ->where('d.company_id', $companyId)
            ->whereBetween('d.document_date', [$dateFrom, $dateTo])
            ->whereIn('d.type', ['invoice', 'credit_note', 'expense'])
            ->where('dtd.is_stamp_duty', false)
            ->whereNull('d.deleted_at')
            ->selectRaw("
                CASE
                    WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT'
                    WHEN d.type = 'expense' THEN 'INPUT'
                END as direction,
                dtd.tax_rate,
                SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_base ELSE dtd.tax_base END) as base_amount,
                SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_amount ELSE dtd.tax_amount END) as vat_amount,
                COUNT(DISTINCT d.id) as document_count,
                COALESCE(tc.is_recoverable, true) as is_recoverable,
                tc.id as tax_configuration_id
            ")
            ->groupByRaw("
                CASE
                    WHEN d.type IN ('invoice', 'credit_note') THEN 'OUTPUT'
                    WHEN d.type = 'expense' THEN 'INPUT'
                END,
                dtd.tax_rate,
                tc.is_recoverable,
                tc.id
            ");

        // POS receipt VAT aggregation (all OUTPUT — sales AND refunds).
        //
        // G-4 (2026-08-21): a POS refund (`pos_receipts.receipt_type = 'return'`)
        // must REDUCE the declared OUTPUT base/VAT, exactly as a credit note does
        // on the document arm above. Before this fix the arm was a bare SUM() with
        // no receipt_type predicate, so refunds were mis-declared.
        //
        // The `-ABS()` is load-bearing, NOT decorative: the two POS writers store
        // OPPOSITE signs for the same refund.
        //   - Canonical/fiscal path (PosCoreReceiptProjection::writeVatBreakdown)
        //     mirrors the canonical `vat_breakdown[]` verbatim, and the canonical
        //     view carries non-negative magnitudes -> POSITIVE rows.
        //   - Legacy server path (ReceiptReturnService::buildReturnLines) derives
        //     the row from a negated line_total -> NEGATIVE rows.
        // A bare `-` would flip the legacy rows back to positive and re-inflate the
        // declaration; `-ABS()` normalises both eras to a single deduction. Same
        // convention as PosAnalyticsService::netOfReturns().
        //
        // The literal 'return' is App\Modules\POS\Domain\Enums\ReceiptType::Return
        // ->value; it stays a SQL literal (not an imported enum) because Taxation
        // must not depend on POS internals — matching how the document arm above
        // hardcodes 'credit_note' rather than importing DocumentType.
        //
        // `document_count` is COUNT(DISTINCT r.id) with NO receipt_type predicate,
        // and that is the RULING OF RECORD, not an accident.
        //
        // B-6(i) RULED 2026-08-23 (owner sheet 2026-08-21, resolution line 41;
        // research: docs/handoff/RESEARCH-opening-float-and-vat-doc-count-2026-08-23.md
        // Part 2): refund receipts and credit notes COUNT as declared documents,
        // on BOTH arms. Grounds:
        //   - TN statute: CDET art. 126 requires "le nombre des factures ou des
        //     tickets de vente, documents..." on the monthly declaration, and DGELF
        //     prise de position n° 99188 (29/03/1999) holds that a facture d'avoir
        //     bears the timbre AS A FACTURE — i.e. an avoir is a counted, dutiable
        //     document in Tunisia.
        //   - Comparative: SAF-T PT states NumberOfEntries "deve conter o número
        //     total de documentos, INCLUINDO" documents that TotalDebit/TotalCredit
        //     deliberately EXCLUDE. Count population and money population are
        //     different populations on purpose — never derive one from a filter over
        //     the other. Italy's certified registratori telematici carry the same
        //     shape (mandatory NumeroDocCommerciali counts every commercial document
        //     "comprese le operazioni di correzione e rettifica", with Totale Reso as
        //     a separate MONEY line).
        // Hence: count every fiscal document; net the money by sign. The SUM(...)
        // above carries the type predicate; the COUNT deliberately does not.
        //
        // ARM SYMMETRY IS A REQUIREMENT, NOT A COINCIDENCE. The document arm's
        // COUNT(DISTINCT d.id) (:53) likewise has no type predicate and counts credit
        // notes. Any future change to one arm's count semantics must change the
        // other in the same commit — the union at :132 SUMs them into one figure.
        //
        // Scope note: `document_count` is a control/audit figure, NOT a DGI form
        // field. TunisiaVatStrategy::mapToDeclarationFields emits only
        // base_*/vat_*/total_* and never reads it. The count that IS legally filed is
        // `stamp_duty_count` in TunisiaVatStrategy::getSpecialLineItems — a different
        // number, and it must not be confused with this one.
        //
        // Pinned by VatDataRepositoryTest's documentCount assertions on both arms.
        $posQuery = DB::table('pos_receipt_vat_details as prvd')
            ->join('pos_receipts as r', 'prvd.receipt_id', '=', 'r.id')
            ->leftJoin('tax_configurations as tc2', function ($join) use ($companyId): void {
                $join->on('prvd.tax_rate', '=', 'tc2.percentage_rate')
                    ->on('tc2.country_code', '=', DB::raw(
                        '(SELECT country_code FROM companies WHERE id = '.DB::getPdo()->quote($companyId).')'
                    ))
                    ->where('tc2.is_active', true)
                    ->where('tc2.is_stamp_duty', false);
            })
            ->where('r.company_id', $companyId)
            ->whereBetween('r.posted_at', [$dateFrom, $dateTo])
            ->where('r.is_voided', false)
            ->where('r.is_training', false)
            ->where('prvd.tax_rate', '>', 0)
            ->selectRaw("
                'OUTPUT' as direction,
                prvd.tax_rate,
                SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.net_amount) ELSE prvd.net_amount END) as base_amount,
                SUM(CASE WHEN r.receipt_type = 'return' THEN -ABS(prvd.vat_amount) ELSE prvd.vat_amount END) as vat_amount,
                COUNT(DISTINCT r.id) as document_count,
                COALESCE(tc2.is_recoverable, true) as is_recoverable,
                tc2.id as tax_configuration_id
            ")
            ->groupByRaw('
                prvd.tax_rate,
                tc2.is_recoverable,
                tc2.id
            ');

        // Union both queries, then re-aggregate by rate + direction
        $combined = DB::query()
            ->fromSub($documentQuery->unionAll($posQuery), 'combined')
            ->selectRaw('
                direction,
                tax_rate,
                SUM(base_amount) as base_amount,
                SUM(vat_amount) as vat_amount,
                SUM(document_count) as document_count,
                is_recoverable,
                tax_configuration_id
            ')
            ->groupByRaw('direction, tax_rate, is_recoverable, tax_configuration_id')
            ->orderBy('direction')
            ->orderBy('tax_rate')
            ->get();

        return $combined->map(fn (object $row): VatAggregation => new VatAggregation(
            direction: (string) $row->direction,
            taxRate: CurrencyScale::bcformat($row->tax_rate, 2),
            baseAmount: CurrencyScale::bcformat($row->base_amount, 3),
            vatAmount: CurrencyScale::bcformat($row->vat_amount, 3),
            documentCount: (int) $row->document_count,
            isRecoverable: (bool) $row->is_recoverable,
            taxConfigurationId: $row->tax_configuration_id,
        ))->all();
    }
}
