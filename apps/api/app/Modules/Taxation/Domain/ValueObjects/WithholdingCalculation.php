<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\ValueObjects;

use App\Modules\Taxation\Domain\Enums\TransactionType;

/**
 * Withholding Calculation Value Object
 *
 * Immutable representation of a withholding tax calculation result.
 * Contains all amounts and metadata needed to create a certificate.
 */
final readonly class WithholdingCalculation
{
    /**
     * @param  numeric-string  $grossAmount  Amount before withholding
     * @param  numeric-string  $withholdingRate  Withholding rate (e.g., 0.0500 for 5%)
     * @param  numeric-string  $withholdingAmount  Amount withheld
     * @param  numeric-string  $netAmount  Amount after withholding
     * @param  string  $currency  Currency code (ISO 4217)
     * @param  string|null  $ruleId  ID of applied rule (null if manual)
     * @param  string|null  $ruleCode  Code of applied rule (for display)
     * @param  string|null  $ruleName  Name of applied rule (for display)
     * @param  TransactionType|null  $transactionType  Type of transaction
     * @param  string|null  $overrideReason  Reason for manual override
     */
    public function __construct(
        public string $grossAmount,
        public string $withholdingRate,
        public string $withholdingAmount,
        public string $netAmount,
        public string $currency,
        public ?string $ruleId = null,
        public ?string $ruleCode = null,
        public ?string $ruleName = null,
        public ?TransactionType $transactionType = null,
        public ?string $overrideReason = null,
    ) {}

    /**
     * Calculate withholding from gross amount and rate.
     *
     * @param  numeric-string  $grossAmount
     * @param  numeric-string  $withholdingRate  As decimal (e.g., 0.0500 for 5%)
     */
    public static function calculate(
        string $grossAmount,
        string $withholdingRate,
        string $currency,
        ?TransactionType $transactionType = null
    ): self {
        $withholdingAmount = bcmul($grossAmount, $withholdingRate, 3);
        $netAmount = bcsub($grossAmount, $withholdingAmount, 3);

        return new self(
            grossAmount: $grossAmount,
            withholdingRate: $withholdingRate,
            withholdingAmount: $withholdingAmount,
            netAmount: $netAmount,
            currency: $currency,
            transactionType: $transactionType,
        );
    }

    /**
     * Create calculation with applied rule metadata.
     */
    public function withRule(string $ruleId, string $ruleCode, string $ruleName): self
    {
        return new self(
            $this->grossAmount,
            $this->withholdingRate,
            $this->withholdingAmount,
            $this->netAmount,
            $this->currency,
            $ruleId,
            $ruleCode,
            $ruleName,
            $this->transactionType,
            $this->overrideReason,
        );
    }

    /**
     * Create calculation with override reason.
     */
    public function withOverride(string $reason): self
    {
        return new self(
            $this->grossAmount,
            $this->withholdingRate,
            $this->withholdingAmount,
            $this->netAmount,
            $this->currency,
            null,
            null,
            null,
            $this->transactionType,
            $reason,
        );
    }

    /**
     * Check if this is a manual override (no rule applied).
     */
    public function isManualOverride(): bool
    {
        return $this->ruleId === null;
    }

    /**
     * Get withholding rate as percentage (e.g., 0.0500 becomes 5.00).
     *
     * @return numeric-string
     */
    public function getRateAsPercentage(): string
    {
        return bcmul($this->withholdingRate, '100', 2);
    }

    /**
     * Validate that amounts are internally consistent.
     */
    public function validate(): bool
    {
        // Check: gross - withholding = net
        $calculatedNet = bcsub($this->grossAmount, $this->withholdingAmount, 3);
        if (bccomp($calculatedNet, $this->netAmount, 3) !== 0) {
            return false;
        }

        // Check: gross * rate = withholding
        $calculatedWithholding = bcmul($this->grossAmount, $this->withholdingRate, 3);
        if (bccomp($calculatedWithholding, $this->withholdingAmount, 3) !== 0) {
            return false;
        }

        return true;
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
            'rate_percentage' => $this->getRateAsPercentage(),
            'rule_id' => $this->ruleId,
            'rule_code' => $this->ruleCode,
            'rule_name' => $this->ruleName,
            'transaction_type' => $this->transactionType?->value,
            'override_reason' => $this->overrideReason,
            'is_manual_override' => $this->isManualOverride(),
        ];
    }
}
