<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\XReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin XReport
 */
final class XReportResource extends JsonResource
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
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at->toIso8601String(),

            // Snapshot data (parsed from JSONB)
            'sales_count' => $this->getSalesCount(),
            'gross_sales' => $this->getGrossSales(),
            'net_sales' => $this->getNetSales(),
            'tax_amount' => $this->getTaxAmount(),
            'refunds_count' => $this->getRefundsCount(),
            // v3-refund-chain-integration round-2 minor N-3: the device's
            // `XReportResponse` now REQUIRES `refunds_amount`, and the same
            // type models this server response, so omitting it made the
            // type lie. The accessor already existed on the model.
            'refunds_amount' => $this->getRefundsAmount(),
            'vat_breakdown' => $this->getVatBreakdown(),
            'payment_methods' => $this->getPaymentMethods(),

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
