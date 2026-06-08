<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\DTOs\CalculatedTax;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxCalculationResult
 */
class DocumentTaxBreakdownResource extends JsonResource
{
    public function __construct(
        TaxCalculationResult $resource,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaxCalculationResult $resource */
        $resource = $this->resource;

        // Precision (Phase 6.1): the TaxCalculationResult DTO carries no currency
        // field, so the zero discount must be formatted at the request-bound
        // company's scale to stay consistent with the sibling monetary strings
        // (e.g. TND → '0.000', EUR → '0.00'). Approach (b): resolve via the
        // request-bound CurrencyScaleResolver (no-arg getScale() is valid
        // in-request); there is no currency code reachable at the call site.
        $scale = $this->scaleResolver->getScale();

        return [
            'subtotal' => $resource->subtotal,
            'discount' => CurrencyScale::bcformat('0', $scale),
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
