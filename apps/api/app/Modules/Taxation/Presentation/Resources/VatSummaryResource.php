<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for VatSummaryData DTO responses.
 *
 * This resource wraps the array output from VatSummaryData::toArray().
 */
class VatSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'output_vat' => $data['output_vat'],
            'input_vat' => $data['input_vat'],
            'net_vat' => $data['net_vat'],
            'credit_brought_forward' => $data['credit_brought_forward'],
            'credit_carried_forward' => $data['credit_carried_forward'],
            'amount_payable' => $data['amount_payable'],
            'special_items' => $data['special_items'],
            'declaration' => $data['declaration'],
        ];
    }
}
