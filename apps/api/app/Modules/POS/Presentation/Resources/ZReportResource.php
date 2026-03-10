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
            'shift_id' => $this->shift_id,
            'z_number' => $this->z_number,
            'fiscal_hash' => $this->fiscal_hash,
            'previous_z_hash' => $this->previous_z_hash,
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at->toIso8601String(),

            // Helper methods
            'is_first_z_report' => $this->isFirstZReport(),
            'formatted_z_number' => $this->getFormattedZNumber(),

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
