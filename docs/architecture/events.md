# Event-Driven Architecture Guide

## Overview

AutoERP implements an **event-driven architecture** where all critical business operations emit domain events. This provides:

1. **Audit Trail** - Complete history of all business operations
2. **Fraud Detection** - Data for anomaly detection and compliance
3. **Fiscal Compliance** - Hash chains and sequential numbering
4. **Module Decoupling** - Asynchronous cross-module communication
5. **Future Event Sourcing** - Foundation for full event sourcing

## Event Hierarchy

### Base Event Class

All domain events extend `DomainEvent`:

```php
namespace App\Shared\Domain;

abstract class DomainEvent
{
    public function __construct()
    {
        $this->eventId = Str::uuid()->toString();
        $this->occurredAt = now()->toIso8601String();
    }
}
```

### Event Types

#### 1. Fiscal Events (Compliance-Critical)

Events that are part of the fiscal hash chain:

```php
namespace App\Modules\Document\Domain\Events;

final class InvoicePosted extends DomainEvent
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $documentDate,
        public readonly string $total,
        public readonly string $fiscalHash,        // SHA-256 hash
        public readonly int $chainSequence,        // Position in chain
        public readonly ?string $previousHash,     // Previous document hash
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }
}
```

**Characteristics:**
- Immutable (readonly properties)
- Part of hash chain
- Never deleted or modified
- Stored permanently for compliance

#### 2. Audit Events (Operations Tracking)

Events for business operations that need audit trail but not fiscal compliance:

```php
namespace App\Modules\Inventory\Domain\Events;

final class StockMoved extends DomainEvent
{
    public function __construct(
        public readonly string $movementId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $fromLocationId,
        public readonly string $toLocationId,
        public readonly string $quantity,
        public readonly string $reason,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }
}
```

**Characteristics:**
- Immutable (readonly properties)
- Not part of hash chain
- Enable operational audit
- Support fraud detection

#### 3. Integration Events (Cross-Module Communication)

Events for asynchronous module communication:

```php
namespace App\Modules\Treasury\Domain\Events;

final class PaymentAllocated extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $invoiceId,
        public readonly string $allocatedAmount,
        public readonly string $remainingAmount,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }
}
```

## Event Catalog

### Document Module Events

| Event | Type | Purpose | Dispatched When |
|-------|------|---------|----------------|
| `InvoicePosted` | Fiscal | Invoice finalized for GL | Invoice status → Posted |
| `CreditNotePosted` | Fiscal | Credit note finalized | Credit note status → Posted |
| `DocumentConverted` | Audit | Track document lifecycle | Quote → Order, Order → Invoice |
| `SalesOrderConfirmed` | Audit | Order ready for fulfillment | Sales order status → Confirmed |
| `PurchaseOrderConfirmed` | Audit | PO sent to supplier | Purchase order status → Confirmed |
| `ReturnNoteConfirmed` | Audit | Return authorized | Return note status → Confirmed |

### Inventory Module Events

| Event | Type | Purpose | Dispatched When |
|-------|------|---------|----------------|
| `StockMoved` | Audit | Track inventory movements | After stock movement record created |
| `StockReserved` | Audit | Track order reservations | Sales order confirmed |
| `StockReleased` | Audit | Track reservation releases | Order cancelled/delivered |
| `CountingStarted` | Audit | Inventory count initiated | Counting session created |
| `CountingCompleted` | Audit | Count finalized | All items counted and reconciled |

### Treasury Module Events

| Event | Type | Purpose | Dispatched When |
|-------|------|---------|----------------|
| `PaymentRecorded` | Fiscal | Payment received/made | Payment created |
| `PaymentAllocated` | Audit | Payment matched to invoice | Allocation record created |

### Accounting Module Events

| Event | Type | Purpose | Dispatched When |
|-------|------|---------|----------------|
| `JournalEntryCreated` | Fiscal | GL entry recorded | Journal entry posted |

## Event Dispatch Patterns

### Pattern 1: Fiscal Event with Hash Chain

```php
public function postInvoice(Document $invoice): void
{
    DB::transaction(function () use ($invoice) {
        // 1. Validate
        if (!$invoice->canBePosted()) {
            throw new InvoiceNotConfirmedException();
        }

        // 2. Get previous hash for chain
        $previousHash = $this->hashService->getPreviousHash(
            tenantId: $invoice->tenant_id,
            documentType: DocumentType::Invoice
        );

        // 3. Calculate new hash
        $hash = $this->hashService->calculateHash($invoice, $previousHash);
        $sequence = $this->hashService->getNextSequence();

        // 4. Create and dispatch event FIRST
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentDate: $invoice->document_date->toDateString(),
            total: $invoice->total,
            fiscalHash: $hash,
            chainSequence: $sequence,
            previousHash: $previousHash,
            occurredAt: now()->toIso8601String(),
        );

        event($event);

        // 5. Update state
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_hash' => $hash,
            'chain_sequence' => $sequence,
            'previous_hash' => $previousHash,
        ]);

        // 6. Side effects
        $this->ledgerService->recordInvoice($invoice);
    });
}
```

### Pattern 2: Audit Event (No Hash Chain)

```php
public function moveStock(
    Product $product,
    Location $from,
    Location $to,
    string $quantity,
    string $reason
): void {
    DB::transaction(function () use ($product, $from, $to, $quantity, $reason) {
        // 1. Create movement record
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'quantity' => $quantity,
            'reason' => $reason,
        ]);

        // 2. Dispatch event
        event(new StockMoved(
            movementId: $movement->id,
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            fromLocationId: $from->id,
            toLocationId: $to->id,
            quantity: $quantity,
            reason: $reason,
            occurredAt: now()->toIso8601String(),
        ));

        // 3. Update stock levels
        $this->updateStockLevels($product, $from, $to, $quantity);
    });
}
```

### Pattern 3: Integration Event (Cross-Module)

```php
public function allocatePayment(Payment $payment, Document $invoice, string $amount): void
{
    DB::transaction(function () use ($payment, $invoice, $amount) {
        // 1. Create allocation record
        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'allocated_amount' => $amount,
        ]);

        // 2. Dispatch event for other modules to react
        event(new PaymentAllocated(
            paymentId: $payment->id,
            invoiceId: $invoice->id,
            allocatedAmount: $amount,
            remainingAmount: bcsub($invoice->total, $invoice->paid_amount, 2),
            occurredAt: now()->toIso8601String(),
        ));

        // Accounting module listens and creates GL entries
        // Document module listens and updates payment status
    });
}
```

## Event Listeners

### Synchronous Listeners

For immediate side effects within the same transaction:

```php
namespace App\Modules\Accounting\Listeners;

class InvoicePostedListener
{
    public function __construct(
        private GeneralLedgerService $ledgerService,
    ) {}

    public function handle(InvoicePosted $event): void
    {
        // This runs in the same transaction as the event dispatch
        $invoice = Document::find($event->invoiceId);
        $this->ledgerService->recordInvoice($invoice);
    }
}
```

**Register in EventServiceProvider:**
```php
protected $listen = [
    InvoicePosted::class => [
        InvoicePostedListener::class,  // Synchronous
    ],
];
```

### Queued Listeners

For non-critical side effects that can be async:

```php
namespace App\Modules\Communication\Listeners;

class SendInvoiceEmailListener implements ShouldQueue
{
    use Queueable;

    public function handle(InvoicePosted $event): void
    {
        // This runs asynchronously via queue
        $invoice = Document::find($event->invoiceId);
        Mail::to($invoice->partner->email)->send(new InvoiceMail($invoice));
    }
}
```

## Event Immutability Rules

### CRITICAL: Never Modify Events

**Events are immutable by design:**

1. **All properties are `readonly`**
2. **No setters allowed**
3. **No mutable collections**
4. **String/int/bool primitives only** (or value objects)

**Wrong:**
```php
// ❌ WRONG - Mutable array
final class InvoicePosted extends DomainEvent
{
    public function __construct(
        public array $lineItems,  // Mutable!
    ) {}
}
```

**Right:**
```php
// ✅ CORRECT - Immutable value object
final class InvoicePosted extends DomainEvent
{
    public function __construct(
        public readonly InvoiceLineCollection $lineItems,  // Immutable
    ) {}
}
```

### Event Versioning

When requirements change, **create a new version** instead of modifying:

```php
// Original event (never modify)
final class PaymentRecorded extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $amount,
    ) {}
}

// New version with additional data
final class PaymentRecordedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $amount,
        public readonly string $paymentMethodId,  // New field
        public readonly string $instrumentId,     // New field
    ) {}
}
```

**Migration Strategy:**
1. Create V2 event
2. Dispatch both V1 and V2 for transition period
3. Update listeners gradually
4. Eventually deprecate V1

## Event Storage

### Future: Event Store

Events will be persisted to TimescaleDB for:
- Audit queries (who did what when)
- Fraud detection patterns
- Event replay for debugging
- Compliance reporting

**Planned Schema:**
```sql
CREATE TABLE domain_events (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    event_data JSONB NOT NULL,
    occurred_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL
);

-- TimescaleDB hypertable for time-series queries
SELECT create_hypertable('domain_events', 'occurred_at');
```

### Current: Database Triggers

Fiscal events are currently captured via database triggers on `documents` table for immutability enforcement.

## Testing Events

### Unit Test: Event Structure

```php
public function test_invoice_posted_event_has_correct_structure(): void
{
    $event = new InvoicePosted(
        invoiceId: 'inv-123',
        tenantId: 'tenant-1',
        companyId: 'company-1',
        documentNumber: 'INV-2025-001',
        documentDate: '2025-01-15',
        total: '1000.00',
        fiscalHash: hash('sha256', 'test'),
        chainSequence: 1,
        previousHash: null,
        occurredAt: now()->toIso8601String(),
    );

    $this->assertEquals('inv-123', $event->invoiceId);
    $this->assertNotNull($event->fiscalHash);
    $this->assertIsInt($event->chainSequence);
}
```

### Feature Test: Event Dispatch

```php
public function test_invoice_posting_dispatches_event(): void
{
    Event::fake([InvoicePosted::class]);

    $invoice = Document::factory()->invoice()->confirmed()->create();

    $this->postJson("/api/v1/invoices/{$invoice->id}/post");

    Event::assertDispatched(InvoicePosted::class, function ($event) use ($invoice) {
        return $event->invoiceId === $invoice->id
            && $event->fiscalHash !== null
            && $event->chainSequence > 0;
    });
}
```

### Integration Test: Event Listener

```php
public function test_invoice_posted_creates_journal_entry(): void
{
    $invoice = Document::factory()->invoice()->confirmed()->create();

    $this->postJson("/api/v1/invoices/{$invoice->id}/post");

    // Verify listener created GL entry
    $this->assertDatabaseHas('journal_entries', [
        'source_type' => 'Document',
        'source_id' => $invoice->id,
        'entry_type' => 'invoice',
    ]);
}
```

## Event Best Practices

### 1. Name Events in Past Tense

```php
// ✅ CORRECT
InvoicePosted
StockMoved
PaymentAllocated

// ❌ WRONG
PostInvoice
MoveStock
AllocatePayment
```

### 2. Include All Context

Events should be self-contained - no need to query database:

```php
// ✅ CORRECT - Complete context
final class OrderConfirmed extends DomainEvent
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $orderNumber,
        public readonly string $customerId,
        public readonly string $customerName,     // Include for reporting
        public readonly string $total,
        public readonly int $lineItemCount,
        public readonly string $occurredAt,
    ) {}
}
```

### 3. Dispatch at Transaction Boundaries

```php
// ✅ CORRECT - Event inside transaction
DB::transaction(function () {
    // Update state
    $invoice->update(['status' => 'posted']);

    // Dispatch event
    event(new InvoicePosted(...));

    // Side effects
    $this->ledgerService->record($invoice);
});

// ❌ WRONG - Event outside transaction
DB::transaction(function () {
    $invoice->update(['status' => 'posted']);
});
event(new InvoicePosted(...));  // Could fail before event!
```

### 4. One Event Per Business Operation

```php
// ✅ CORRECT - Separate events
event(new OrderConfirmed(...));
event(new StockReserved(...));

// ❌ WRONG - One big event
event(new OrderConfirmedAndStockReserved(...));
```

## Migration Path

### Phase 1: Current State ✅
- Events dispatched for critical operations
- Listeners handle synchronous side effects
- No event store yet

### Phase 2: Event Store (Planned)
- All events persisted to TimescaleDB
- Queryable audit trail
- Event replay capability

### Phase 3: Event Sourcing (Future)
- Aggregate state rebuilt from events
- CQRS with separate read models
- Event-sourced aggregates

## References

- See `docs/modules/architecture.md` for module structure
- See `docs/api/testing.md` for event testing patterns
- See CLAUDE.md for immutability rules
