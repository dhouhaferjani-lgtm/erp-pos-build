<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Services\DocumentTaxCalculationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentTaxCalculationResult
 */
class DocumentTaxBreakdownResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'line_tax_amount' => $this->lineTaxAmount,
            'stamp_duty_amount' => $this->stampDutyAmount,
            'total_tax_amount' => $this->totalTaxAmount,
            'total' => $this->total,
            'tax_details' => array_map(function ($detail) {
                return [
                    'tax_type' => $detail->taxType->value,
                    'tax_name' => $detail->taxName,
                    'tax_base' => $detail->taxBase,
                    'tax_rate' => $detail->taxRate,
                    'tax_amount' => $detail->taxAmount,
                    'is_stamp_duty' => $detail->isStampDuty,
                ];
            }, $this->taxDetails),
        ];
    }
}
