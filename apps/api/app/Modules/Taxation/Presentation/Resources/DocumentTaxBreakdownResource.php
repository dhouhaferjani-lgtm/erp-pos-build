<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\DTOs\CalculatedTax;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxCalculationResult
 */
class DocumentTaxBreakdownResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaxCalculationResult $resource */
        $resource = $this->resource;

        return [
            'line_tax_amount' => $resource->lineItemsTaxTotal,
            'stamp_duty_amount' => $resource->documentTaxTotal,
            'total_tax_amount' => $resource->totalTax,
            'total' => $resource->total,
            'tax_details' => array_map(function (CalculatedTax $detail) {
                return [
                    'tax_type' => $detail->type->value,
                    'tax_name' => $detail->name,
                    'tax_base' => $detail->base,
                    'tax_rate' => $detail->rate,
                    'tax_amount' => $detail->amount,
                    'is_stamp_duty' => $detail->isStampDuty,
                ];
            }, $resource->taxes),
        ];
    }
}
