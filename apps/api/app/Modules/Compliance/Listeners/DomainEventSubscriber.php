<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Accounting\Domain\Events\JournalEntryCreated;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Company\Domain\Events\CompanyUpdated;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Document\Domain\Events\DocumentLineDiscountStrippedAtConversion;
use App\Modules\Document\Domain\Events\DraftDocumentCreated;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineModified;
use App\Modules\Document\Domain\Events\DraftLineRemoved;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePaid;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Events\SalesOrderCancelled;
use App\Modules\Document\Domain\Events\SalesOrderCancelledV2;
use App\Modules\Document\Domain\Events\SalesOrderConfirmed;
use App\Modules\Identity\Domain\Events\RoleAssigned;
use App\Modules\Identity\Domain\Events\RoleRemoved;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationExpired;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\POS\Domain\Events\CashDrawerOperationRecorded;
use App\Modules\POS\Domain\Events\ManagerOverrideAuthorized;
use App\Modules\POS\Domain\Events\OrphanedShiftClosedByOperator;
use App\Modules\POS\Domain\Events\OrphanedShiftDeviceCloseApplied;
use App\Modules\POS\Domain\Events\ReceiptCreated;
use App\Modules\POS\Domain\Events\ReceiptDrafted;
use App\Modules\POS\Domain\Events\ReceiptPrinted;
use App\Modules\POS\Domain\Events\ReceiptVoided;
use App\Modules\POS\Domain\Events\ShiftClosed;
use App\Modules\POS\Domain\Events\ShiftOpened;
use App\Modules\POS\Domain\Events\TerminalActivatedAudit;
use App\Modules\POS\Domain\Events\TerminalClaimed;
use App\Modules\POS\Domain\Events\TerminalDeactivated;
use App\Modules\POS\Domain\Events\TerminalReleased;
use App\Modules\POS\Domain\Events\TerminalSoftwareUpdated;
use App\Modules\POS\Domain\Events\TerminalTrainingModeChanged;
use App\Modules\POS\Domain\Events\ZReportGenerated;
use App\Modules\Treasury\Domain\Events\BankStatementReconciled;
use App\Modules\Treasury\Domain\Events\BankStatementReopened;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentReceived;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\Events\InvoiceClosedWithTolerance;
use App\Modules\Treasury\Domain\Events\PaymentAllocated;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Events\ReconciliationCompleted;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use App\Shared\Contracts\SupportAccess\ImpersonationContextProvider;
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
        private readonly ImpersonationContextProvider $impersonationContext,
    ) {}

    /**
     * Handle JournalEntryCreated events.
     */
    public function handleJournalEntryCreated(JournalEntryCreated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'JournalEntry',
            aggregateId: $event->journalEntryId,
            eventType: $event->getEventName(),
            payload: $event->getAuditData(),
        );
    }

    /**
     * Handle JournalEntryPosted events.
     */
    public function handleJournalEntryPosted(JournalEntryPosted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'JournalEntry',
            aggregateId: $event->entryId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

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
     * Handle DocumentFullyPaid events (versioned successor to InvoicePaid).
     */
    public function handleDocumentFullyPaid(DocumentFullyPaid $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->documentId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'document_type' => $event->documentType,
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
     * Handle PaymentAllocated events.
     *
     * Audit-only policy: allocation changes financial settlement state but is
     * not currently a fiscal-event projection.
     */
    public function handlePaymentAllocated(PaymentAllocated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Payment',
            aggregateId: $event->paymentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditData(),
        );
    }

    /**
     * Handle ReconciliationCompleted events.
     *
     * Audit-only policy: reconciliation closes bank matching state but does
     * not author fiscal receipt/ledger payloads.
     */
    public function handleReconciliationCompleted(ReconciliationCompleted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'BankReconciliation',
            aggregateId: $event->reconciliationId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    /**
     * Handle RoleAssigned events.
     *
     * Privileged action — captures who granted whom which role, and when.
     * The audit_events row is the only durable record of this; the
     * model_has_roles row carries team_id alone (no actor, no timestamp).
     */
    public function handleRoleAssigned(RoleAssigned $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'User',
            aggregateId: $event->targetUserId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    /**
     * Handle RoleRemoved events.
     *
     * Privileged action — role revocation audit trail, mirroring RoleAssigned.
     */
    public function handleRoleRemoved(RoleRemoved $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'User',
            aggregateId: $event->targetUserId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    /**
     * Handle PaymentRefunded events.
     *
     * Privileged action — every refund (full or partial) must leave an audit
     * trail with the actor, the originating payment, and the amount. Without
     * this handler the PaymentRefunded events dispatched by
     * PaymentRefundService fell into the void with no audit_events row.
     */
    public function handlePaymentRefunded(PaymentRefunded $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Payment',
            aggregateId: $event->paymentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    /**
     * Handle PaymentReversed events.
     *
     * Privileged action — payment reversals (error/correction path) must be
     * audit-logged with the actor and amount, mirroring PaymentRefunded.
     */
    public function handlePaymentReversed(PaymentReversed $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Payment',
            aggregateId: $event->paymentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    /**
     * Handle ManagerOverrideAuthorized events.
     *
     * Privileged action — a successful manager-PIN verification at the
     * override gate (ManagerPinController::verify). The audit_events row is
     * the only timestamped record of who authorised whom; the downstream
     * receipt/shift columns carry the manager identity but not the moment of
     * authorisation, nor the failed attempts.
     */
    public function handleManagerOverrideAuthorized(ManagerOverrideAuthorized $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'User',
            aggregateId: $event->managerId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
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
     * Persist an unnumbered draft cancellation without fabricating an empty
     * document number. The stable draft reference is the audit-facing label.
     */
    public function handleSalesOrderCancelledV2(SalesOrderCancelledV2 $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->salesOrderId,
            eventType: $event->getEventName(),
            payload: [
                'document_number' => $event->documentNumber,
                'draft_reference' => $event->draftReference,
                'partner_id' => $event->partnerId,
                'cancellation_reason' => $event->cancellationReason,
                'cancelled_by' => $event->cancelledBy,
                'cancelled_at' => $event->cancelledAt,
            ],
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
     * Handle ReceiptDrafted events.
     *
     * Audit trail for the pre-seal lifecycle step — receipt created as
     * pending_seal before fiscal finalization.
     */
    public function handleReceiptDrafted(ReceiptDrafted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Receipt',
            aggregateId: $event->receiptId,
            eventType: $event->getEventName(),
            payload: [
                'receipt_number' => $event->receiptNumber,
                'cashier_id' => $event->cashierId,
                'total' => $event->total,
                'currency' => $event->currency,
                'terminal_id' => $event->terminalId,
                'posted_at' => $event->postedAt,
            ]
        );
    }

    /**
     * Handle ReceiptCreated events.
     *
     * NF525 TICKET event — fired after fiscal sealing; fiscalHash and
     * chainSequence are always non-null at this point.
     */
    public function handleReceiptCreated(ReceiptCreated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Receipt',
            aggregateId: $event->receiptId,
            eventType: $event->getEventName(),
            payload: [
                'receipt_number' => $event->receiptNumber,
                'total' => $event->total,
                'currency' => $event->currency,
                'fiscal_hash' => $event->fiscalHash,
                'chain_sequence' => $event->chainSequence,
                'terminal_id' => $event->terminalId,
                'posted_at' => $event->postedAt,
            ]
        );
    }

    /**
     * Handle ReceiptVoided events.
     *
     * NF525 ANNULATION event - every void with reason and actor.
     */
    public function handleReceiptVoided(ReceiptVoided $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Receipt',
            aggregateId: $event->receiptId,
            eventType: $event->getEventName(),
            payload: [
                'receipt_number' => $event->receiptNumber,
                'void_reason' => $event->voidReason,
                'voided_by' => $event->voidedBy,
                'voided_at' => $event->voidedAt,
            ]
        );
    }

    /**
     * Handle ReceiptPrinted events.
     *
     * NF525 DUPLICATA event - every receipt print/reprint must be logged.
     */
    public function handleReceiptPrinted(ReceiptPrinted $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Receipt',
            aggregateId: $event->receiptId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'user_id' => $event->userId,
                'print_type' => $event->printType,
                'copy_number' => $event->copyNumber,
                'print_method' => $event->printMethod,
                'printed_at' => $event->printedAt,
            ]
        );
    }

    /**
     * Handle ShiftOpened events.
     *
     * NF525 OUVERTURE_CAISSE event - shift openings for cash drawer audit trail.
     */
    public function handleShiftOpened(ShiftOpened $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Shift',
            aggregateId: $event->shiftId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'cashier_id' => $event->cashierId,
                'opening_balance' => $event->openingBalance,
                'opened_at' => $event->openedAt,
            ]
        );
    }

    /**
     * Handle ShiftClosed events.
     *
     * NF525 FERMETURE_CAISSE event - shift closings with variance for fraud detection.
     */
    public function handleShiftClosed(ShiftClosed $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Shift',
            aggregateId: $event->shiftId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'cashier_id' => $event->cashierId,
                'expected_cash' => $event->expectedCash,
                'actual_cash' => $event->actualCash,
                'variance' => $event->variance,
                'closed_at' => $event->closedAt,
            ]
        );
    }

    /**
     * Handle OrphanedShiftClosedByOperator events (LEDGER O-30 / Q7-OWES-1).
     *
     * The operator-authored counterpart to {@see ShiftClosed}, and deliberately
     * a DIFFERENT event type: `shift.closed` means the device closed its own
     * drawer after a count, while this means a human closed a shift whose
     * device is gone and whose cash nobody counted. Collapsing the two would
     * make the audit register unable to tell an auditor which of the two
     * happened.
     *
     * `release_audit_event_id` links back to the `terminal.released` row that
     * authorised the close, so the register carries the whole chain:
     * forced release → orphan → operator close.
     */
    public function handleOrphanedShiftClosedByOperator(OrphanedShiftClosedByOperator $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Shift',
            aggregateId: $event->shiftId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'terminal_code' => $event->terminalCode,
                'cashier_id' => $event->cashierId,
                'reason' => $event->reason,
                'closed_by' => $event->closedBy,
                'release_audit_event_id' => $event->releaseAuditEventId,
                'expected_cash' => $event->expectedCash,
                'counted_cash' => $event->countedCash,
                'variance' => $event->variance,
                'closed_at' => $event->closedAt,
            ]
        );
    }

    /**
     * Handle OrphanedShiftDeviceCloseApplied events (LEDGER O-30, gate r1).
     *
     * The device believed lost came back and closed its own shift, so its
     * counted drawer replaced the operator-derived pair. Carries BOTH sides of
     * the swap: an auditor comparing this row with the earlier
     * `shift.orphan_closed` one can see exactly which figures were in the
     * projection, when they changed, and which fiscal event changed them.
     */
    public function handleOrphanedShiftDeviceCloseApplied(OrphanedShiftDeviceCloseApplied $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Shift',
            aggregateId: $event->shiftId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'fiscal_event_id' => $event->fiscalEventId,
                'operator_id' => $event->operatorId,
                'superseded_expected_cash' => $event->supersededExpectedCash,
                'superseded_counted_cash' => $event->supersededCountedCash,
                'superseded_variance' => $event->supersededVariance,
                'device_expected_cash' => $event->deviceExpectedCash,
                'device_counted_cash' => $event->deviceCountedCash,
                'device_variance' => $event->deviceVariance,
                'device_closed_at' => $event->deviceClosedAt,
            ]
        );
    }

    /**
     * Handle ZReportGenerated events.
     *
     * NF525 RAPPORT_Z event - Z reports with hash chain for compliance.
     */
    public function handleZReportGenerated(ZReportGenerated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'ZReport',
            aggregateId: $event->zReportId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_id' => $event->terminalId,
                'z_number' => $event->zNumber,
                'fiscal_hash' => $event->fiscalHash,
                'generated_at' => $event->generatedAt,
            ]
        );
    }

    /**
     * Handle CashDrawerOperationRecorded events.
     *
     * NF525 cash movement events (DEPOT_ESPECES, RETRAIT_ESPECES, REMBOURSEMENT).
     */
    public function handleCashDrawerOperationRecorded(CashDrawerOperationRecorded $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'CashDrawerOperation',
            aggregateId: $event->operationId,
            eventType: $event->getEventName(),
            payload: [
                'shift_id' => $event->shiftId,
                'terminal_id' => $event->terminalId,
                'operation_type' => $event->operationType,
                'amount' => $event->amount,
                'user_id' => $event->userId,
                'recorded_at' => $event->recordedAt,
            ]
        );
    }

    /**
     * Handle TerminalActivatedAudit events.
     *
     * NF525 ACTIVATION_TERMINAL event - terminal activations for JET export.
     */
    public function handleTerminalActivatedAudit(TerminalActivatedAudit $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'activated_by' => $event->activatedBy,
            ]
        );
    }

    /**
     * Handle TerminalClaimed events (Q-7).
     *
     * Binding a device to a terminal binds it to that terminal's fiscal chain.
     * Until Q-7 this act wrote no audit row at all, so "prove nobody re-pointed
     * this till" could only be answered from `updated_at` and Horizon logs.
     */
    public function handleTerminalClaimed(TerminalClaimed $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'hardware_identifier' => $event->hardwareIdentifier,
                'claimed_by' => $event->claimedBy,
            ]
        );
    }

    /**
     * Handle TerminalReleased events (Q-7).
     *
     * The counterpart to a claim: `hardware_identifier` records the binding that
     * was BROKEN, since the column is null on the row afterwards.
     *
     * `forced` / `open_shift_id` (fix round F-1): a release is refused by
     * default while the terminal still has an OPEN shift, because no server
     * surface can close a v3 terminal's shift without the authoring device. An
     * operator who overrides that refusal knowingly ORPHANS the shift, and the
     * replacement device's shifts and Z reports will not project until it is
     * resolved. The audit row is the only place that record survives, so it
     * carries which shift was abandoned and the reason given for abandoning it.
     */
    public function handleTerminalReleased(TerminalReleased $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'hardware_identifier' => $event->hardwareIdentifier,
                'reason' => $event->reason,
                'released_by' => $event->releasedBy,
                'forced' => $event->forced,
                'open_shift_id' => $event->openShiftId,
            ]
        );
    }

    /**
     * Handle TerminalDeactivated events.
     *
     * NF525 DESACTIVATION_TERMINAL event - terminal deactivations for JET export.
     */
    public function handleTerminalDeactivated(TerminalDeactivated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'reason' => $event->reason,
                'deactivated_by' => $event->deactivatedBy,
            ]
        );
    }

    /**
     * Handle TerminalSoftwareUpdated events.
     *
     * NF525 MAJ_LOGICIEL event - software version changes for JET export.
     */
    public function handleTerminalSoftwareUpdated(TerminalSoftwareUpdated $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'previous_version' => $event->previousVersion,
                'new_version' => $event->newVersion,
            ]
        );
    }

    /**
     * Handle TerminalTrainingModeChanged events.
     *
     * NF525 training mode toggle - audit trail for training mode changes.
     */
    public function handleTerminalTrainingModeChanged(TerminalTrainingModeChanged $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Terminal',
            aggregateId: $event->terminalId,
            eventType: $event->getEventName(),
            payload: [
                'terminal_code' => $event->terminalCode,
                'enabled' => $event->enabled,
                'changed_by' => $event->changedBy,
            ]
        );
    }

    /**
     * Handle InvoiceClosedWithTolerance — A2 B2B close-with-writeoff audit trail.
     *
     * The journal entry alone records what changed in the GL, but does not
     * link it back to the user action. The audit row gives us actor + invoice
     * + writeoff amount in one place for compliance review.
     */
    public function handleInvoiceClosedWithTolerance(InvoiceClosedWithTolerance $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->invoiceId,
            eventType: $event->getEventName(),
            payload: [
                'invoice_id' => $event->invoiceId,
                'partner_id' => $event->partnerId,
                'amount_written_off' => $event->amountWrittenOff,
                'currency' => $event->currency,
                'gl_entry_id' => $event->glEntryId,
                'closed_by' => $event->closedBy,
                'occurred_at' => $event->occurredAtTimestamp->format(DATE_ATOM),
            ]
        );
    }

    /**
     * Handle DocumentLineDiscountStrippedAtConversion — Phase 4 / Task 14
     * audit trail for the auto-strip of sub-tolerance discounts at document
     * conversion. Mirrors the InvoiceClosedWithTolerance pattern: the
     * line/document mutation is observable in the data, but only this audit
     * row carries actor + original-discount + tolerance-margin + occurred-at
     * in one place for compliance review.
     */
    public function handleDocumentLineDiscountStrippedAtConversion(
        DocumentLineDiscountStrippedAtConversion $event,
    ): void {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'Document',
            aggregateId: $event->targetDocumentId,
            eventType: $event->getEventName(),
            payload: [
                'line_id' => $event->lineId,
                'source_line_id' => $event->sourceLineId,
                'source_document_id' => $event->sourceDocumentId,
                'target_document_id' => $event->targetDocumentId,
                'source_document_number' => $event->sourceDocumentNumber,
                'target_document_number' => $event->targetDocumentNumber,
                'source_type' => $event->sourceType,
                'target_type' => $event->targetType,
                'original_discount_amount' => $event->originalDiscountAmount,
                'tolerance_margin' => $event->toleranceMargin,
                'subtotal' => $event->subtotal,
                'currency_code' => $event->currencyCode,
                'user_id' => $event->userId,
                'stripped_at' => $event->strippedAt,
            ]
        );
    }

    /**
     * Handle RepositoryMovementRecorded events.
     *
     * Treasury spine (Task 5) — audit trail for every money movement written
     * to `repository_movements`. The ledger row is the durable record of
     * WHAT moved; this audit_events row is the durable record of WHO/WHEN
     * beyond what the append-only movement row itself carries.
     */
    public function handleRepositoryMovementRecorded(RepositoryMovementRecorded $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'RepositoryMovement',
            aggregateId: $event->movementId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    public function handleInstrumentEvent(
        InstrumentReceived|InstrumentDeposited|InstrumentCleared|InstrumentBounced|InstrumentTransferred $event,
    ): void {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'PaymentInstrument',
            aggregateId: $event->instrumentId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
        );
    }

    public function handleBankStatementEvent(BankStatementReconciled|BankStatementReopened $event): void
    {
        $this->persistEvent(
            event: $event,
            companyId: $event->companyId,
            aggregateType: 'BankStatement',
            aggregateId: $event->statementId,
            eventType: $event->getEventName(),
            payload: $event->getAuditPayload(),
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
            if ($this->impersonationContext->current() !== null) {
                throw $e;
            }

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
            // Accounting events (audit trail)
            JournalEntryCreated::class => 'handleJournalEntryCreated',
            JournalEntryPosted::class => 'handleJournalEntryPosted',

            // Fiscal events (compliance)
            InvoicePosted::class => 'handleInvoicePosted',
            InvoiceCancelled::class => 'handleInvoiceCancelled',
            InvoicePaid::class => 'handleInvoicePaid',
            DocumentFullyPaid::class => 'handleDocumentFullyPaid',
            DeliveryNoteConfirmed::class => 'handleDeliveryNoteConfirmed',
            PaymentRecorded::class => 'handlePaymentRecorded',
            PaymentRefunded::class => 'handlePaymentRefunded',
            PaymentReversed::class => 'handlePaymentReversed',
            PaymentAllocated::class => 'handlePaymentAllocated',
            ReconciliationCompleted::class => 'handleReconciliationCompleted',
            DocumentConverted::class => 'handleDocumentConverted',

            // Company events (compliance)
            CompanyUpdated::class => 'handleCompanyUpdated',

            // Identity events (privileged-action audit trail)
            RoleAssigned::class => 'handleRoleAssigned',
            RoleRemoved::class => 'handleRoleRemoved',

            // Sales order events (fraud detection)
            SalesOrderConfirmed::class => 'handleSalesOrderConfirmed',
            SalesOrderCancelled::class => 'handleSalesOrderCancelled',
            SalesOrderCancelledV2::class => 'handleSalesOrderCancelledV2',

            // Treasury events (audit trail for B2B close-with-writeoff)
            InvoiceClosedWithTolerance::class => 'handleInvoiceClosedWithTolerance',

            // Treasury spine (audit trail for money movements)
            RepositoryMovementRecorded::class => 'handleRepositoryMovementRecorded',
            BankStatementReconciled::class => 'handleBankStatementEvent',
            BankStatementReopened::class => 'handleBankStatementEvent',

            // Instrument portfolio lifecycle (custody + accounting transitions)
            InstrumentReceived::class => 'handleInstrumentEvent',
            InstrumentDeposited::class => 'handleInstrumentEvent',
            InstrumentCleared::class => 'handleInstrumentEvent',
            InstrumentBounced::class => 'handleInstrumentEvent',
            InstrumentTransferred::class => 'handleInstrumentEvent',

            // Document events (audit trail for Phase-4 conversion auto-strip)
            DocumentLineDiscountStrippedAtConversion::class => 'handleDocumentLineDiscountStrippedAtConversion',

            // Stock reservation events (fraud detection)
            ReservationCreated::class => 'handleReservationCreated',
            ReservationReleased::class => 'handleReservationReleased',
            ReservationExpired::class => 'handleReservationExpired',

            // Draft events (fraud detection)
            DraftDocumentCreated::class => 'handleDraftDocumentCreated',
            DraftLineAdded::class => 'handleDraftLineAdded',
            DraftLineModified::class => 'handleDraftLineModified',
            DraftLineRemoved::class => 'handleDraftLineRemoved',

            // POS events (NF525 compliance)
            ReceiptDrafted::class => 'handleReceiptDrafted',
            ReceiptCreated::class => 'handleReceiptCreated',
            ReceiptVoided::class => 'handleReceiptVoided',
            ReceiptPrinted::class => 'handleReceiptPrinted',
            ShiftOpened::class => 'handleShiftOpened',
            ShiftClosed::class => 'handleShiftClosed',
            OrphanedShiftClosedByOperator::class => 'handleOrphanedShiftClosedByOperator',
            OrphanedShiftDeviceCloseApplied::class => 'handleOrphanedShiftDeviceCloseApplied',
            ZReportGenerated::class => 'handleZReportGenerated',
            CashDrawerOperationRecorded::class => 'handleCashDrawerOperationRecorded',
            ManagerOverrideAuthorized::class => 'handleManagerOverrideAuthorized',

            // Terminal lifecycle events (NF525 compliance)
            TerminalActivatedAudit::class => 'handleTerminalActivatedAudit',
            TerminalClaimed::class => 'handleTerminalClaimed',
            TerminalDeactivated::class => 'handleTerminalDeactivated',
            TerminalReleased::class => 'handleTerminalReleased',
            TerminalSoftwareUpdated::class => 'handleTerminalSoftwareUpdated',
            TerminalTrainingModeChanged::class => 'handleTerminalTrainingModeChanged',
        ];
    }
}
