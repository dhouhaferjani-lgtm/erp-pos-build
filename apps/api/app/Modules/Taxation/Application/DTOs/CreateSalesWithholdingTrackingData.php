<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

/**
 * Create Sales Withholding Tracking Data
 *
 * DTO for creating a new sales withholding tracking record.
 */
readonly class CreateSalesWithholdingTrackingData
{
    public function __construct(
        public string $documentId,
        public string $customerId,
        public string $invoiceAmount,
        public string $withholdingRate,
        public string $withholdingAmount,
        public string $expectedReceivable,
        public ?string $paymentId = null,
        public ?string $notes = null,
    ) {}

    /**
     * Create from array (typically from request).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            documentId: $data['document_id'],
            customerId: $data['customer_id'],
            invoiceAmount: $data['invoice_amount'],
            withholdingRate: $data['withholding_rate'],
            withholdingAmount: $data['withholding_amount'],
            expectedReceivable: $data['expected_receivable'],
            paymentId: $data['payment_id'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * Convert to array for repository.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'customer_id' => $this->customerId,
            'invoice_amount' => $this->invoiceAmount,
            'withholding_rate' => $this->withholdingRate,
            'withholding_amount' => $this->withholdingAmount,
            'expected_receivable' => $this->expectedReceivable,
            'payment_id' => $this->paymentId,
            'notes' => $this->notes,
            'certificate_received' => false,
        ];
    }
}
