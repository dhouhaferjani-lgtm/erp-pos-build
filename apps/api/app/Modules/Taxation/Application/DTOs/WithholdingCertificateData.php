<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use Carbon\Carbon;

/**
 * Withholding Certificate Data DTO
 *
 * Data transfer object for withholding certificate entity.
 */
readonly class WithholdingCertificateData
{
    /**
     * @param  numeric-string  $grossAmount
     * @param  numeric-string  $withholdingRate
     * @param  numeric-string  $withholdingAmount
     * @param  numeric-string  $netAmount
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $companyId,
        public string $certificateNumber,
        public int $year,
        public WithholdingDirection $direction,
        public string $partnerId,
        public ?string $documentId,
        public ?string $paymentId,
        public string $currency,
        public string $grossAmount,
        public string $withholdingRate,
        public string $withholdingAmount,
        public string $netAmount,
        public ?string $withholdingRuleId,
        public ?string $overrideReason,
        public ?string $tejReference,
        public ?Carbon $tejSubmittedAt,
        public ?string $certificateMediaId,
        public CertificateStatus $status,
        public ?string $hash,
        public ?string $previousHash,
        public ?int $chainSequence,
        public ?Carbon $issuedAt,
        public ?string $issuedBy,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    ) {}

    /**
     * Create from entity.
     */
    public static function fromEntity(WithholdingCertificate $certificate): self
    {
        return new self(
            id: $certificate->id,
            tenantId: $certificate->tenant_id,
            companyId: $certificate->company_id,
            certificateNumber: $certificate->certificate_number,
            year: $certificate->year,
            direction: $certificate->direction,
            partnerId: $certificate->partner_id,
            documentId: $certificate->document_id,
            paymentId: $certificate->payment_id,
            currency: $certificate->currency,
            grossAmount: $certificate->gross_amount,
            withholdingRate: $certificate->withholding_rate,
            withholdingAmount: $certificate->withholding_amount,
            netAmount: $certificate->net_amount,
            withholdingRuleId: $certificate->withholding_rule_id,
            overrideReason: $certificate->override_reason,
            tejReference: $certificate->tej_reference,
            tejSubmittedAt: $certificate->tej_submitted_at,
            certificateMediaId: $certificate->certificate_media_id,
            status: $certificate->status,
            hash: $certificate->hash,
            previousHash: $certificate->previous_hash,
            chainSequence: $certificate->chain_sequence,
            issuedAt: $certificate->issued_at,
            issuedBy: $certificate->issued_by,
            createdAt: $certificate->created_at,
            updatedAt: $certificate->updated_at,
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
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'certificate_number' => $this->certificateNumber,
            'year' => $this->year,
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'partner_id' => $this->partnerId,
            'document_id' => $this->documentId,
            'payment_id' => $this->paymentId,
            'currency' => $this->currency,
            'gross_amount' => $this->grossAmount,
            'withholding_rate' => $this->withholdingRate,
            'withholding_amount' => $this->withholdingAmount,
            'net_amount' => $this->netAmount,
            'withholding_rule_id' => $this->withholdingRuleId,
            'override_reason' => $this->overrideReason,
            'tej_reference' => $this->tejReference,
            'tej_submitted_at' => $this->tejSubmittedAt?->toIso8601String(),
            'certificate_media_id' => $this->certificateMediaId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'hash' => $this->hash,
            'previous_hash' => $this->previousHash,
            'chain_sequence' => $this->chainSequence,
            'issued_at' => $this->issuedAt?->toIso8601String(),
            'issued_by' => $this->issuedBy,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
    }
}
