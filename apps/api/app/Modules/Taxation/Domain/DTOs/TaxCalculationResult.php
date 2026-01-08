<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

readonly class TaxCalculationResult
{
    /**
     * @param  CalculatedTax[]  $taxes
     */
    public function __construct(
        public array $taxes,
        public string $subtotal,
        public string $lineItemsTaxTotal,
        public string $documentTaxTotal,
        public string $totalTax,
        public string $total,
        public ?array $exemptionInfo = null,
    ) {}

    /**
     * Alias for lineItemsTaxTotal (for backward compatibility with tests)
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'lineTaxAmount' => $this->lineItemsTaxTotal,
            'stampDutyAmount' => $this->documentTaxTotal,
            'totalTaxAmount' => $this->totalTax,
            'taxDetails' => $this->taxes,
            default => null,
        };
    }

    public function toArray(): array
    {
        return [
            'taxes' => array_map(fn ($t) => $t->toArray(), $this->taxes),
            'subtotal' => $this->subtotal,
            'line_items_tax_total' => $this->lineItemsTaxTotal,
            'document_tax_total' => $this->documentTaxTotal,
            'total_tax' => $this->totalTax,
            'total' => $this->total,
            'exemption_info' => $this->exemptionInfo,
        ];
    }

    public function hasExemptionWarnings(): bool
    {
        return $this->exemptionInfo !== null
            && ! empty($this->exemptionInfo['warnings']);
    }
}
