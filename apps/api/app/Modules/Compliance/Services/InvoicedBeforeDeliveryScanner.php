<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DeliveredQuantityResolver;
use App\Modules\Document\Domain\Services\DeliveryComplianceGate;
use App\Modules\Product\Domain\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detector **D-c** — posted goods invoices with no delivery behind them.
 *
 * The MIRROR of {@see UninvoicedDeliveryNoteService::getUninvoicedDeliveryNotes()}
 * (detector D-d): that one finds goods that left without an invoice, this one
 * finds invoices issued without goods leaving.
 *
 * ── WHAT THIS IS, AFTER T25b ── (Wave 3 D-18′ / D-26)
 * A **legacy / exception register**, not a control for a permitted flow. Under
 * `require_delivery_first` no new invoice joins this population **EXCEPT A
 * RECORDED EXEMPTION** — the precise claim, restated in fix round 2 (inv N-1)
 * after fix round 1 created the first exemption and the earlier wording ("no NEW
 * invoice can join this population") became false.
 *
 * THREE populations land here, and telling them apart is the entire job:
 *
 *   1. **pre-policy legacy** — no T25e stamp at all (`policy_at_post_time =
 *      pre_policy`). Historical, needs accountant disposition, not investigation.
 *   2. **recorded exemption** — `delivery_requirement_exempted = true` with
 *      `posting_context` naming the caller that claimed it (today: the Workshop
 *      WO→invoice adapter, per the F-1 ruling; ticket
 *      `2026-08-10-workshop-parts-goods-lane-gap.md`). Deliberate, bounded,
 *      expected to disappear when the WO goods lane lands.
 *   3. **a hole** — a live policy, no exemption, no stamp explanation. THIS is
 *      the row worth an operator's evening.
 *
 * Emitting (2) as though it were (3) is not a cosmetic problem: a register that
 * cries wolf stops being read, and then (3) is missed. That is why the exemption
 * fields travel with every row rather than being inferred at the UI.
 *
 * ── THE PREDICATE IS `hasEverIssuedGoods()`, NOT `hasGoodsIssued()` ── (D-29)
 * The latter nets prior returns. Using it here would list every invoice that was
 * properly delivered and then fully returned as a compliance exception — a
 * false positive on the most sensitive report in the lane.
 *
 * ── COST ── Set-based first pass (one query, physical-line join, chunked), then
 * the authoritative resolver predicate per SURVIVOR only. `resolve()` — which
 * eager-loads lines and runs a prior-returns query — is never called here;
 * `hasEverIssuedGoods()` is the cheaper sibling and is the correct predicate.
 */
class InvoicedBeforeDeliveryScanner
{
    /**
     * How many candidate invoices are materialised at a time. The first pass is
     * set-based precisely so this loop stays bounded on a tenant with years of
     * posted invoices.
     */
    private const CHUNK = 200;

    public function __construct(
        private readonly DeliveredQuantityResolver $resolver,
    ) {}

    /**
     * Posted invoices with at least one physical line and no goods ever issued.
     *
     * @return list<array{
     *     id: string,
     *     document_number: string,
     *     document_date: string,
     *     partner_id: string,
     *     partner_name: string,
     *     total: string,
     *     currency: string,
     *     policy_at_post_time: string,
     *     policy_source_at_post_time: string,
     *     posting_context: string,
     *     delivery_requirement_exempted: bool
     * }>
     */
    public function scan(string $companyId, ?Carbon $fromDate = null, ?Carbon $toDate = null): array
    {
        $tenantId = (string) Company::query()->whereKey($companyId)->value('tenant_id');

        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            // First pass, set-based: only invoices that actually carry goods.
            // A services-only invoice is not in scope for a delivery rule and
            // must never appear on this report. This is the documented SQL
            // counterpart in PhysicalLinePredicate's class docblock, pinned by
            // test_scanner_sql_physical_predicates_match_the_scoped_row_predicate.
            ->whereHas('lines', function (Builder $lineQuery) use ($tenantId, $companyId): void {
                $lineQuery->whereNotNull('product_id')
                    ->whereIn('product_id', Product::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->where('is_physical', true)
                        ->select('id'));
            })
            ->with('partner:id,name')
            ->orderBy('document_date')
            ->orderBy('id');

        if ($fromDate !== null) {
            $query->where('document_date', '>=', $fromDate->startOfDay());
        }

        if ($toDate !== null) {
            $query->where('document_date', '<=', $toDate->endOfDay());
        }

        $findings = [];

        $query->chunk(self::CHUNK, function ($invoices) use (&$findings): void {
            foreach ($invoices as $invoice) {
                // Authoritative predicate, per SURVIVOR only, on the one traversal.
                if ($this->resolver->hasEverIssuedGoods($invoice)) {
                    continue;
                }

                $stamp = $invoice->payload[DeliveryComplianceGate::STAMP_KEY] ?? null;

                $findings[] = [
                    'id' => (string) $invoice->id,
                    'document_number' => (string) $invoice->document_number,
                    'document_date' => $invoice->document_date->toDateString(),
                    'partner_id' => $invoice->partner_id,
                    // 📌 `documents` has NO `posted_at` column — the posting
                    // instant is only recoverable from the T25e audit stamp
                    // (`stamped_at`), and only for documents posted after this
                    // wave. Emitting a silently-null `posted_at` here would look
                    // like data rather than an absent column.
                    // NOT null-guarded, deliberately (fix round 1, P3-11 assessed
                    // and REJECTED as a non-defect): `documents.partner_id` is
                    // `NOT NULL` with an FK to `partners`
                    // (`2025_11_30_080000_create_documents_table.php:16`), so the
                    // eager-loaded relation cannot be absent. Both a `?->` and an
                    // `=== null` guard are rejected by PHPStan as never-null /
                    // always-false, which is the analyser telling the truth.
                    'partner_name' => $invoice->partner->name,
                    'total' => (string) $invoice->total,
                    'currency' => (string) $invoice->currency,
                    // No stamp at all ⇒ the document predates the policy. That is
                    // the distinction that makes this register readable, and the
                    // reason the stamp exists.
                    'policy_at_post_time' => is_array($stamp) ? (string) ($stamp['policy'] ?? 'unknown') : 'pre_policy',
                    'policy_source_at_post_time' => is_array($stamp) ? (string) ($stamp['policy_source'] ?? 'unknown') : 'pre_policy',
                    // 🚨 THE THIRD POPULATION (fix round 2 / inv N-1). Fix round
                    // 1's F-1 exemption lets a work-order invoice post with no
                    // goods issued — deliberately, under a ruling, with the fact
                    // recorded on its own stamp. Those rows land in THIS bucket,
                    // and without these two keys they are indistinguishable from
                    // the thing this register exists to find: an invoice that
                    // reached posting without passing the delivery check.
                    //
                    // Both keys, not one: the exemption flag says "this was
                    // allowed", the context says BY WHOM. A future second exempt
                    // caller must be separable from this one without a schema
                    // change, and an exemption granted under a live policy must
                    // be separable from a pre-policy document — which the policy
                    // column alone cannot do.
                    'posting_context' => is_array($stamp) ? (string) ($stamp['posting_context'] ?? 'unknown') : 'pre_policy',
                    'delivery_requirement_exempted' => is_array($stamp)
                        && ($stamp['delivery_requirement_exempted'] ?? false) === true,
                ];
            }
        });

        return $findings;
    }
}
