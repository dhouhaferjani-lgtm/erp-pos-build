<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Domain\Entities\SalesWithholdingTracking;
use Spatie\LaravelData\Data;

/**
 * Sales Withholding Tracking Data
 *
 * DTO for sales withholding tracking record responses.
 */
class SalesWithholdingTrackingData extends Data
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $companyId,
        public string $documentId,
        public ?string $paymentId,
        public string $customerId,
        public string $customerName,
        public string $invoiceAmount,
        public string $withholdingRate,
        public string $withholdingAmount,
        public string $expectedReceivable,
        public ?string $certificateNumber,
        public bool $certificateReceived,
        public ?string $certificateReceivedAt,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * Create from entity.
     */
    public static function fromEntity(SalesWithholdingTracking $tracking): self
    {
        return new self(
            id: $tracking->id,
            tenantId: $tracking->tenant_id,
            companyId: $tracking->company_id,
            documentId: $tracking->document_id,
            paymentId: $tracking->payment_id,
            customerId: $tracking->customer_id,
            customerName: $tracking->customer->name ?? 'Unknown',
            invoiceAmount: (string) $tracking->invoice_amount,
            withholdingRate: (string) $tracking->withholding_rate,
            withholdingAmount: (string) $tracking->withholding_amount,
            expectedReceivable: (string) $tracking->expected_receivable,
            certificateNumber: $tracking->certificate_number,
            certificateReceived: $tracking->certificate_received,
            certificateReceivedAt: $tracking->certificate_received_at?->toISOString(),
            notes: $tracking->notes,
            createdAt: $tracking->created_at->toISOString(),
            updatedAt: $tracking->updated_at->toISOString(),
        );
    }
}
