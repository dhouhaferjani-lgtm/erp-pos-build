<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a line item is removed from a draft document — version 2.
 *
 * Supersedes DraftLineRemoved (V1) which lacked the variant dimension. Adds
 * variantId. Both V1 and V2 are fired together (dual-dispatch) so existing
 * listeners are not broken.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create DraftLineRemovedV3.
 */
final class DraftLineRemovedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $userId,
        public readonly string $lineId,
        public readonly string $productId,
        public readonly string $productName,
        public readonly float $quantity,
        public readonly float $lineTotal,
        public readonly ?string $variantId,
        public readonly ?string $removedAt = null,
    ) {
        parent::__construct($documentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'draft.line.removed.v2';
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
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'line_total' => $this->lineTotal,
            'variant_id' => $this->variantId,
            'user_id' => $this->userId,
            'removed_at' => $this->removedAt ?? now()->toIso8601String(),
        ];
    }
}
