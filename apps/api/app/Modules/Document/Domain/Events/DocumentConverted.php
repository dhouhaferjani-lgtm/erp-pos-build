<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a document is converted to another type.
 *
 * This event is dispatched for all document lifecycle conversions:
 * - Quote → Sales Order
 * - Sales Order → Invoice
 * - Sales Order → Delivery Note
 * - Delivery Notes → Invoice (consolidation)
 *
 * This event is important for audit trail and compliance tracking.
 * It enables tracing the full document lifecycle for any transaction.
 */
final class DocumentConverted extends DomainEvent
{
    /**
     * @param  string  $sourceDocumentId  The ID of the source document
     * @param  string  $targetDocumentId  The ID of the created document
     * @param  string  $companyId  Company ID for multi-tenancy
     * @param  string  $tenantId  Tenant ID for multi-tenancy
     * @param  string  $sourceDocumentNumber  The source document number for audit
     * @param  string  $targetDocumentNumber  The target document number for audit
     * @param  string  $sourceType  The type of the source document (quote, sales_order, delivery_note)
     * @param  string  $targetType  The type of the created document (sales_order, invoice, delivery_note)
     * @param  string|null  $userId  The user who performed the conversion
     * @param  string  $convertedAt  ISO timestamp of conversion
     * @param  bool  $isPartial  Whether this was a partial conversion
     * @param  array<string, mixed>  $metadata  Additional metadata about the conversion
     */
    public function __construct(
        public readonly string $sourceDocumentId,
        public readonly string $targetDocumentId,
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $sourceDocumentNumber,
        public readonly string $targetDocumentNumber,
        public readonly string $sourceType,
        public readonly string $targetType,
        public readonly ?string $userId,
        public readonly string $convertedAt,
        public readonly bool $isPartial = false,
        public readonly array $metadata = [],
    ) {
        parent::__construct($targetDocumentId);
    }

    /**
     * Get the data to be used for logging purposes.
     *
     * @return array<string, mixed>
     */
    public function getHashableData(): array
    {
        return [
            'source_document_id' => $this->sourceDocumentId,
            'target_document_id' => $this->targetDocumentId,
            'source_document_number' => $this->sourceDocumentNumber,
            'target_document_number' => $this->targetDocumentNumber,
            'source_type' => $this->sourceType,
            'target_type' => $this->targetType,
            'converted_at' => $this->convertedAt,
            'is_partial' => $this->isPartial,
        ];
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'document.converted';
    }
}
