<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

readonly class VatAggregation
{
    /**
     * @param  numeric-string  $taxRate
     * @param  numeric-string  $baseAmount
     * @param  numeric-string  $vatAmount
     */
    public function __construct(
        public string $direction,
        public string $taxRate,
        public string $baseAmount,
        public string $vatAmount,
        public int $documentCount,
        public bool $isRecoverable,
        public ?string $taxConfigurationId = null,
    ) {}

    /** @return array<string, string|int|bool|null> */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'tax_rate' => $this->taxRate,
            'base_amount' => $this->baseAmount,
            'vat_amount' => $this->vatAmount,
            'document_count' => $this->documentCount,
            'is_recoverable' => $this->isRecoverable,
            'tax_configuration_id' => $this->taxConfigurationId,
        ];
    }
}
