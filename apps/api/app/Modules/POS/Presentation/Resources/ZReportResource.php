<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\ZReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ZReport
 */
final class ZReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'terminal_id' => $this->terminal_id,
            'terminal_name' => $this->whenLoaded('terminal', fn () => $this->terminal->name),
            'location_id' => $this->whenLoaded('terminal', fn () => $this->terminal->location_id),
            'location_name' => $this->whenLoaded('terminal', fn () => $this->terminal->relationLoaded('location') ? $this->terminal->location->name : null),
            'shift_id' => $this->shift_id,
            'z_number' => $this->z_number,
            'fiscal_hash' => $this->fiscal_hash,
            'previous_z_hash' => $this->previous_z_hash,
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at->toIso8601String(),

            // Helper methods
            'is_first_z_report' => $this->isFirstZReport(),
            'formatted_z_number' => $this->getFormattedZNumber(),
            'was_reused' => (bool) ($this->getAttribute('was_reused') ?? false),

            // Report data (parsed from JSONB)
            'sales_count' => $this->getSalesCount(),
            'gross_sales' => $this->getGrossSales(),
            'opening_cash' => $this->getOpeningCash(),
            'expected_cash' => $this->getExpectedCash(),
            'actual_cash' => $this->getActualCash(),
            'variance' => $this->getVariance(),
            'has_variance' => $this->hasVariance(),

            // Full report data for detailed view
            'report_data' => $this->report_data,

            // B-6(ii) — DERIVED (never signed) refund-VAT disclosure. Present only
            // on the detail endpoint, which is the only caller that attaches it;
            // null on the list, where deriving per row would be an N+1 for a
            // figure no list row renders.
            'refund_vat_disclosure' => $this->getAttribute('refund_vat_disclosure'),

            // Per-tender cash count rows (only when relation is eager-loaded)
            'counts' => $this->whenLoaded('counts', fn () => CashCountResource::collection($this->counts)),

            // Cash-count metadata from shift (whenLoaded to avoid N+1)
            'variance_severity' => $this->whenLoaded('shift', fn () => $this->shift->variance_severity),
            'variance_reason' => $this->whenLoaded('shift', fn () => $this->shift->notes),
            'blind_count_used' => $this->whenLoaded('shift', fn () => (bool) $this->shift->blind_count_used),
            'manager_override_by_name' => $this->whenLoaded('shift', function () {
                return $this->shift->managerOverride?->name;
            }),

            // Variance and tolerance summaries stamped into report_data by ReportGenerationService
            'variance_summary' => $this->report_data['variance_summary'] ?? null,
            'tolerance_summary' => $this->report_data['tolerance_summary'] ?? null,

            // Relationships (when loaded)
            'terminal' => $this->whenLoaded('terminal'),
            'shift' => $this->whenLoaded('shift'),
            'generated_by_user' => $this->whenLoaded('generatedBy', function () {
                return [
                    'id' => $this->generatedBy->id,
                    'name' => $this->generatedBy->name,
                    'email' => $this->generatedBy->email,
                ];
            }),
        ];
    }
}
