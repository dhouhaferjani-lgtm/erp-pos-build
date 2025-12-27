<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a draft document is created (auto-save or explicit creation).
 *
 * This is an operational event (NOT fiscal) used for fraud detection and user behavior tracking.
 * Captures the initial state of a draft document before any lines are added.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create DraftDocumentCreatedV2.
 */
final class DraftDocumentCreated extends DomainEvent
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $userId,
        public readonly string $documentType,
        public readonly ?string $partnerId = null,
        public readonly ?string $partnerName = null,
        public readonly ?string $createdAt = null,
    ) {
        parent::__construct($documentId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'draft.document.created';
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
            'document_type' => $this->documentType,
            'partner_id' => $this->partnerId,
            'partner_name' => $this->partnerName,
            'user_id' => $this->userId,
            'created_at' => $this->createdAt ?? now()->toIso8601String(),
        ];
    }
}
