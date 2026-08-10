<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

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
 * `require_delivery_first` no NEW invoice can join this population — that is the
 * regression assertion of the whole sub-wave. What remains here is documents
 * posted BEFORE the policy existed, plus anything that slipped through a path
 * not yet routed through `DocumentPostingService::post()`. Each row therefore
 * carries `policy_at_post_time`, read from the T25e audit stamp, so an operator
 * can tell "pre-policy legacy" from "posted under a policy that permitted it"
 * from "we have a hole".
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
     *     partner_id: string|null,
     *     partner_name: string|null,
     *     total: string,
     *     currency: string,
     *     posted_at: string|null,
     *     policy_at_post_time: string,
     *     policy_source_at_post_time: string
     * }>
     */
    public function scan(string $companyId, ?Carbon $fromDate = null, ?Carbon $toDate = null): array
    {
        $query = Document::query()
            ->where('company_id', $companyId)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            // First pass, set-based: only invoices that actually carry goods.
            // A services-only invoice is not in scope for a delivery rule and
            // must never appear on this report.
            ->whereHas('lines', function (Builder $lineQuery) use ($companyId): void {
                $lineQuery->whereNotNull('product_id')
                    ->whereIn('product_id', Product::query()
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
                    'document_date' => $invoice->document_date?->toDateString() ?? '',
                    'partner_id' => $invoice->partner_id,
                    'partner_name' => $invoice->partner?->name,
                    'total' => (string) $invoice->total,
                    'currency' => (string) $invoice->currency,
                    'posted_at' => $invoice->posted_at?->toIso8601String(),
                    // No stamp at all ⇒ the document predates the policy. That is
                    // the distinction that makes this register readable, and the
                    // reason the stamp exists.
                    'policy_at_post_time' => is_array($stamp) ? (string) ($stamp['policy'] ?? 'unknown') : 'pre_policy',
                    'policy_source_at_post_time' => is_array($stamp) ? (string) ($stamp['policy_source'] ?? 'unknown') : 'pre_policy',
                ];
            }
        });

        return $findings;
    }
}
