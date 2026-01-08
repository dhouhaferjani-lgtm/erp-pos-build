<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use Carbon\Carbon;

/**
 * Withholding Rule Data DTO
 *
 * Data transfer object for withholding tax rule entity.
 */
readonly class WithholdingRuleData
{
    /**
     * @param  numeric-string|null  $minAmount
     * @param  numeric-string  $rate
     */
    public function __construct(
        public string $id,
        public string $countryCode,
        public ?string $companyId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?TransactionType $transactionType,
        public ?PartnerTaxStatus $partnerTaxStatus,
        public ?string $minAmount,
        public string $rate,
        public Carbon $effectiveFrom,
        public ?Carbon $effectiveTo,
        public bool $isActive,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    ) {}

    /**
     * Create from entity.
     */
    public static function fromEntity(WithholdingTaxRule $rule): self
    {
        return new self(
            id: $rule->id,
            countryCode: $rule->country_code,
            companyId: $rule->company_id,
            code: $rule->code,
            name: $rule->name,
            description: $rule->description,
            transactionType: $rule->transaction_type,
            partnerTaxStatus: $rule->partner_tax_status,
            minAmount: $rule->min_amount,
            rate: $rule->rate,
            effectiveFrom: $rule->effective_from,
            effectiveTo: $rule->effective_to,
            isActive: $rule->is_active,
            createdAt: $rule->created_at,
            updatedAt: $rule->updated_at,
        );
    }

    /**
     * Get rate as percentage.
     */
    public function getRateAsPercentage(): float
    {
        return (float) bcmul($this->rate, '100', 2);
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->countryCode,
            'company_id' => $this->companyId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'transaction_type' => $this->transactionType?->value,
            'transaction_type_label' => $this->transactionType?->label(),
            'partner_tax_status' => $this->partnerTaxStatus?->value,
            'partner_tax_status_label' => $this->partnerTaxStatus?->label(),
            'min_amount' => $this->minAmount,
            'rate' => $this->rate,
            'rate_percentage' => $this->getRateAsPercentage(),
            'effective_from' => $this->effectiveFrom->toDateString(),
            'effective_to' => $this->effectiveTo?->toDateString(),
            'is_active' => $this->isActive,
            'is_global' => $this->companyId === null,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }
}
