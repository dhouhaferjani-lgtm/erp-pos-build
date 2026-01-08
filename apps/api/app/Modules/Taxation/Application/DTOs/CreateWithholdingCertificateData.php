<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;

/**
 * Create Withholding Certificate Data DTO
 *
 * Data transfer object for creating a new withholding certificate.
 */
readonly class CreateWithholdingCertificateData
{
    /**
     * @param  numeric-string  $grossAmount
     * @param  float|null  $manualRatePercentage  Manual rate override (as percentage, e.g., 5.0 for 5%)
     */
    public function __construct(
        public string $companyId,
        public WithholdingDirection $direction,
        public string $partnerId,
        public ?string $documentId,
        public ?string $paymentId,
        public string $currency,
        public string $grossAmount,
        public ?TransactionType $transactionType,
        public ?float $manualRatePercentage,
        public ?string $overrideReason,
    ) {}

    /**
     * Create from request data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            companyId: $data['company_id'],
            direction: WithholdingDirection::from($data['direction']),
            partnerId: $data['partner_id'],
            documentId: $data['document_id'] ?? null,
            paymentId: $data['payment_id'] ?? null,
            currency: $data['currency'],
            grossAmount: $data['gross_amount'],
            transactionType: isset($data['transaction_type'])
                ? TransactionType::from($data['transaction_type'])
                : null,
            manualRatePercentage: $data['manual_rate_percentage'] ?? null,
            overrideReason: $data['override_reason'] ?? null,
        );
    }

    /**
     * Check if this is a manual override (user specified rate).
     */
    public function isManualOverride(): bool
    {
        return $this->manualRatePercentage !== null;
    }
}
