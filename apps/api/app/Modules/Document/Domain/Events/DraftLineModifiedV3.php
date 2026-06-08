<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a line item in a draft document is modified — version 3.
 *
 * Supersedes DraftLineModifiedV2 which lacked the variant dimension. Adds
 * variantId, variantName, and variantSku. V1, V2, and V3 are all fired
 * together (dual-dispatch) so existing listeners are not broken.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create DraftLineModifiedV4.
 */
final class DraftLineModifiedV3 extends DomainEvent
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $userId,
        public readonly string $lineId,
        public readonly string $productId,
        public readonly array $oldValues,
        public readonly array $newValues,
        public readonly ?string $description,
        public readonly ?string $notes,
        public readonly ?string $variantId,
        public readonly ?string $variantName = null,
        public readonly ?string $variantSku = null,
        public readonly ?string $modifiedAt = null,
    ) {
        parent::__construct($documentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'draft.line.modified.v3';
    }

    /**
     * Get payload for audit logging.
     *
     * @return array<string, mixed>
     */
    public function getAuditPayload(): array
    {
        return [
            'document_id' => $this->documentId,
            'line_id' => $this->lineId,
            'product_id' => $this->productId,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'description' => $this->description,
            'notes' => $this->notes,
            'variant_id' => $this->variantId,
            'variant_name' => $this->variantName,
            'variant_sku' => $this->variantSku,
            'user_id' => $this->userId,
            'modified_at' => $this->modifiedAt ?? now()->toIso8601String(),
        ];
    }
}
