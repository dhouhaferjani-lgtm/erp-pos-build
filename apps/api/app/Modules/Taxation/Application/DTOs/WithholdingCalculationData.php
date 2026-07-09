<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\ValueObjects\WithholdingCalculation;

/**
 * Withholding Calculation Data DTO
 *
 * Data transfer object for withholding calculation results.
 */
readonly class WithholdingCalculationData
{
    /**
     * @param  numeric-string  $grossAmount
     * @param  numeric-string  $withholdingRate
     * @param  numeric-string  $withholdingAmount
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $ratePercentage
     */
    public function __construct(
        public string $grossAmount,
        public string $withholdingRate,
        public string $withholdingAmount,
        public string $netAmount,
        public string $currency,
        public string $ratePercentage,
        public ?string $ruleId,
        public ?string $ruleCode,
        public ?string $ruleName,
        public ?TransactionType $transactionType,
        public ?string $overrideReason,
        public bool $isManualOverride,
    ) {}

    /**
     * Create from domain value object.
     */
    public static function fromValueObject(WithholdingCalculation $calculation): self
    {
        return new self(
            grossAmount: $calculation->grossAmount,
            withholdingRate: $calculation->withholdingRate,
            withholdingAmount: $calculation->withholdingAmount,
            netAmount: $calculation->netAmount,
            currency: $calculation->currency,
            ratePercentage: $calculation->getRateAsPercentage(),
            ruleId: $calculation->ruleId,
            ruleCode: $calculation->ruleCode,
            ruleName: $calculation->ruleName,
            transactionType: $calculation->transactionType,
            overrideReason: $calculation->overrideReason,
            isManualOverride: $calculation->isManualOverride(),
        );
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gross_amount' => $this->grossAmount,
            'withholding_rate' => $this->withholdingRate,
            'withholding_amount' => $this->withholdingAmount,
            'net_amount' => $this->netAmount,
            'currency' => $this->currency,
            'rate_percentage' => $this->ratePercentage,
            'rule_id' => $this->ruleId,
            'rule_code' => $this->ruleCode,
            'rule_name' => $this->ruleName,
            'transaction_type' => $this->transactionType?->value,
            'override_reason' => $this->overrideReason,
            'is_manual_override' => $this->isManualOverride,
        ];
    }
}
