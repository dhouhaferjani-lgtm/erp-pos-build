<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Company\Domain\Events\CompanyUpdated;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Events\DraftDocumentCreated;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineModified;
use App\Modules\Document\Domain\Events\DraftLineRemoved;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePaid;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Document\Domain\Events\SalesOrderConfirmed;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationExpired;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Shared\Domain\Events\DomainEvent;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Subscriber that captures all domain events and persists them to the audit log.
 *
 * This is the central wiring point that connects domain events to the audit
 * infrastructure. Every fiscal and business event flows through here to ensure
 * complete audit trail for compliance and fraud detection.
 *
 * The subscriber extracts:
 * - companyId from each event (required for multi-tenancy)
 * - eventType from the event's getEventName() method
 * - aggregateType/aggregateId for entity identification
 * - Full event payload for audit trail
 */
final class DomainEventSubscriber
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Handle InvoicePosted events.
     */
    public function handleInvoicePosted(InvoicePosted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->invoiceId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'document_type' => $event->documentType,
                'partner_id' => $event->partnerId,
                'total' => $event->total,
                'currency' => $event->currency,
                'fiscal_hash' => $event->fiscalHash,
                'chain_sequence' => $event->chainSequence,
                'posted_at' => $event->postedAt,
            ]
        );
    }

    /**
     * Handle InvoiceCancelled events.
     */
    public function handleInvoiceCancelled(InvoiceCancelled $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->invoiceId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'document_type' => $event->documentType,
                'original_fiscal_hash' => $event->originalFiscalHash,
                'cancelled_at' => $event->cancelledAt,
            ]
        );
    }

    /**
     * Handle InvoicePaid events.
     */
    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->invoiceId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'partner_id' => $event->partnerId,
                'total_paid' => $event->totalPaid,
                'paid_at' => $event->paidAt,
            ]
        );
    }

    /**
     * Handle DeliveryNoteConfirmed events.
     */
    public function handleDeliveryNoteConfirmed(DeliveryNoteConfirmed $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->deliveryNoteId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'partner_id' => $event->partnerId,
                'total' => $event->total,
                'currency' => $event->currency,
                'fiscal_hash' => $event->fiscalHash,
                'chain_sequence' => $event->chainSequence,
                'confirmed_at' => $event->confirmedAt,
            ]
        );
    }

    /**
     * Handle PaymentRecorded events.
     */
    public function handlePaymentRecorded(PaymentRecorded $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Payment',
            aggregateId: $event->paymentId,
            eventType: $event->getEventName(),
            payload: [
                'partner_id' => $event->partnerId,
                'amount' => $event->amount,
                'currency' => $event->currency,
                'payment_method_id' => $event->paymentMethodId,
                'recorded_at' => $event->recordedAt,
            ]
        );
    }

    /**
     * Handle DocumentConverted events.
     *
     * This captures all document lifecycle conversions:
     * - Quote → Sales Order
     * - Sales Order → Invoice
     * - Sales Order → Delivery Note
     * - Delivery Notes → Invoice (consolidation)
     */
    public function handleDocumentConverted(DocumentConverted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->targetDocumentId,
            eventType: $event->getEventName(),
            payload: [
                'source_document_id' => $event->sourceDocumentId,
                'target_document_id' => $event->targetDocumentId,
                'source_document_number' => $event->sourceDocumentNumber,
                'target_document_number' => $event->targetDocumentNumber,
                'source_type' => $event->sourceType,
                'target_type' => $event->targetType,
                'user_id' => $event->userId,
                'is_partial' => $event->isPartial,
                'converted_at' => $event->convertedAt,
                'metadata' => $event->metadata,
            ]
        );
    }

    /**
     * Handle DraftDocumentCreated events.
     *
     * This captures when users start creating documents (auto-save or explicit).
     * Critical for fraud detection - tracks all document creation attempts,
     * even if never completed.
     */
    public function handleDraftDocumentCreated(DraftDocumentCreated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->documentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload()
        );
    }

    /**
     * Handle DraftLineAdded events.
     *
     * This captures when users add items to drafts.
     * Essential for fraud detection - tracks which products are being
     * added to drafts that may never be completed.
     */
    public function handleDraftLineAdded(DraftLineAdded $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'DocumentLine',
            aggregateId: $event->lineId ?? $event->documentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload()
        );
    }

    /**
     * Handle DraftLineModified events.
     *
     * This captures changes to draft line items.
     * Useful for detecting unusual editing patterns.
     */
    public function handleDraftLineModified(DraftLineModified $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'DocumentLine',
            aggregateId: $event->lineId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload()
        );
    }

    /**
     * Handle DraftLineRemoved events.
     *
     * This captures when users remove items from drafts.
     * Useful for detecting "add then remove" suspicious patterns.
     */
    public function handleDraftLineRemoved(DraftLineRemoved $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'DocumentLine',
            aggregateId: $event->lineId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload()
        );
    }

    /**
     * Handle SalesOrderConfirmed events.
     *
     * This captures when sales orders are confirmed and stock is reserved.
     * Essential for fraud detection - tracks order confirmation patterns.
     */
    public function handleSalesOrderConfirmed(SalesOrderConfirmed $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->salesOrderId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'partner_id' => $event->partnerId,
                'total' => $event->total,
                'currency' => $event->currency,
                'lines_count' => count($event->lines),
                'confirmed_by' => $event->confirmedBy,
                'confirmed_at' => $event->confirmedAt,
            ]
        );
    }

    /**
     * Handle SalesOrderCancelled events.
     *
     * This captures when sales orders are cancelled.
     * Essential for fraud detection - frequent cancellations may indicate issues.
     */
    public function handleSalesOrderCancelled(SalesOrderCancelled $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->salesOrderId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'partner_id' => $event->partnerId,
                'cancellation_reason' => $event->cancellationReason,
                'cancelled_by' => $event->cancelledBy,
                'cancelled_at' => $event->cancelledAt,
            ]
        );
    }

    /**
     * Handle ReservationCreated events.
     *
     * This captures when stock is reserved for orders, carts, etc.
     * Essential for fraud detection - tracks reservation patterns and high-value holds.
     */
    public function handleReservationCreated(ReservationCreated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'StockReservation',
            aggregateId: $event->reservationId,
            eventType: $event->getEventName(),
            payload: [
                'product_id' => $event->productId,
                'location_id' => $event->locationId,
                'quantity' => $event->quantity,
                'source_type' => $event->sourceType,
                'source_id' => $event->sourceId,
                'source_line_id' => $event->sourceLineId,
                'expires_at' => $event->expiresAt,
                'priority' => $event->priority,
                'created_by' => $event->createdBy,
                'created_at' => $event->createdAt,
            ]
        );
    }

    /**
     * Handle ReservationReleased events.
     *
     * This captures when stock reservations are released.
     * Tracks release reasons for fraud detection (expired, manual releases are suspicious).
     */
    public function handleReservationReleased(ReservationReleased $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'StockReservation',
            aggregateId: $event->reservationId,
            eventType: $event->getEventName(),
            payload: [
                'product_id' => $event->productId,
                'location_id' => $event->locationId,
                'quantity' => $event->quantity,
                'source_type' => $event->sourceType,
                'source_id' => $event->sourceId,
                'release_reason' => $event->releaseReason,
                'released_by' => $event->releasedBy,
                'released_at' => $event->releasedAt,
            ]
        );
    }

    /**
     * Handle ReservationExpired events.
     *
     * This captures when stock reservations expire due to timeout.
     * CRITICAL for fraud detection - expired reservations are suspicious behavior.
     */
    public function handleReservationExpired(ReservationExpired $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'StockReservation',
            aggregateId: $event->reservationId,
            eventType: $event->getEventName(),
            payload: [
                'product_id' => $event->productId,
                'location_id' => $event->locationId,
                'quantity' => $event->quantity,
                'source_type' => $event->sourceType,
                'source_id' => $event->sourceId,
                'original_expires_at' => $event->originalExpiresAt,
                'expired_at' => $event->expiredAt,
            ]
        );
    }

    /**
     * Handle CompanyUpdated events.
     *
     * This captures company setting changes, especially tax_status changes.
     * CRITICAL for compliance - tracks who changed tax status and when for audit trail.
     */
    public function handleCompanyUpdated(CompanyUpdated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Company',
            aggregateId: $event->companyId,
            eventType: $event->getEventName(),
            payload: [
                'changes' => $event->changes,
                'updated_by' => $event->userId,
                'updated_at' => $event->updatedAt,
                'full_snapshot' => $event->attributes,
                'change_count' => count($event->changes),
                'fields_changed' => array_keys($event->changes),
            ]
        );
    }

    /**
     * Persist an event to the audit log.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistEvent(
        DomainEvent $event,
        string $companyId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
    ): void {
        try {
            $authId = Auth::id();
            $userId = $authId !== null ? (string) $authId : null;

            $this->auditService->record(
                companyId: $companyId,
                userId: $userId,
                eventType: $eventType,
                aggregateType: $aggregateType,
                aggregateId: $aggregateId,
                payload: $payload,
                metadata: [
                    'event_class' => $event::class,
                    'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.u'),
                ]
            );

            Log::debug('Audit event persisted', [
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
            ]);
        } catch (\Throwable $e) {
            // Log the error but don't fail the main operation
            // Audit logging should not break business operations
            Log::error('Failed to persist audit event', [
                'event_type' => $eventType,
                'aggregate_id' => $aggregateId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            // Fiscal events (compliance)
            InvoicePosted::class => 'handleInvoicePosted',
            InvoiceCancelled::class => 'handleInvoiceCancelled',
            InvoicePaid::class => 'handleInvoicePaid',
            DeliveryNoteConfirmed::class => 'handleDeliveryNoteConfirmed',
            PaymentRecorded::class => 'handlePaymentRecorded',
            DocumentConverted::class => 'handleDocumentConverted',

            // Company events (compliance)
            CompanyUpdated::class => 'handleCompanyUpdated',

            // Sales order events (fraud detection)
            SalesOrderConfirmed::class => 'handleSalesOrderConfirmed',
            SalesOrderCancelled::class => 'handleSalesOrderCancelled',

            // Stock reservation events (fraud detection)
            ReservationCreated::class => 'handleReservationCreated',
            ReservationReleased::class => 'handleReservationReleased',
            ReservationExpired::class => 'handleReservationExpired',

            // Draft events (fraud detection)
            DraftDocumentCreated::class => 'handleDraftDocumentCreated',
            DraftLineAdded::class => 'handleDraftLineAdded',
            DraftLineModified::class => 'handleDraftLineModified',
            DraftLineRemoved::class => 'handleDraftLineRemoved',
        ];
    }
}
