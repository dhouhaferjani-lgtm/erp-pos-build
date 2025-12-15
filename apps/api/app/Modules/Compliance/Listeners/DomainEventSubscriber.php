<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Compliance\Services\AuditService;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePaid;
use App\Modules\Document\Domain\Events\InvoicePosted;
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
     * Persist an event to the audit log.
     *
     * @param array<string, mixed> $payload
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
            InvoicePosted::class => 'handleInvoicePosted',
            InvoiceCancelled::class => 'handleInvoiceCancelled',
            InvoicePaid::class => 'handleInvoicePaid',
            DeliveryNoteConfirmed::class => 'handleDeliveryNoteConfirmed',
            PaymentRecorded::class => 'handlePaymentRecorded',
            DocumentConverted::class => 'handleDocumentConverted',
        ];
    }
}
