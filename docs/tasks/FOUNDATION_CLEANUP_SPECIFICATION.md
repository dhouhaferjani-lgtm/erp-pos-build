# Foundation Cleanup Specification

**Version:** 1.0  
**Date:** December 24, 2025  
**Purpose:** Define the cleanup work needed before multi-vertical expansion  
**Products:** Otospex (Automotive) / IziPOS (Generic)

---

## 1. Business Model Clarification

### 1.1 Document Type Separation

The system correctly separates **Financial Documents** from **Operational Documents**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         FINANCIAL DOCUMENTS                                  │
│                    (Affect AR/AP and Revenue/Expense)                       │
│                                                                             │
│   Invoice ─────────────────────────────────────────────────────────────────│
│   │  • Creates AR (Debit Accounts Receivable, Credit Revenue)              │
│   │  • Fiscal hash chain for compliance                                     │
│   │  • Does NOT move stock directly                                         │
│   │                                                                         │
│   Credit Note ─────────────────────────────────────────────────────────────│
│   │  • Reverses AR (Debit Revenue, Credit Accounts Receivable)             │
│   │  • Fiscal hash chain for compliance                                     │
│   │  • Does NOT move stock directly                                         │
│   │                                                                         │
│   Supplier Invoice (Expense) ──────────────────────────────────────────────│
│      • Creates AP (Debit Expense/Inventory, Credit Accounts Payable)       │
│      • Fiscal hash chain for compliance                                     │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                        OPERATIONAL DOCUMENTS                                 │
│                    (Affect Physical Stock Levels)                           │
│                                                                             │
│   Delivery Note (Bon de Sortie) ───────────────────────────────────────────│
│   │  • Decreases stock (products leave warehouse)                          │
│   │  • Creates COGS GL entry (Debit COGS, Credit Inventory)                │
│   │  • Linked to Invoice lines (many-to-one possible)                      │
│   │  • Only for PRODUCTS, not services                                      │
│   │                                                                         │
│   Goods Receipt Note (Bon d'Entrée) ───────────────────────────────────────│
│   │  • Increases stock (products enter warehouse)                          │
│   │  • Creates Inventory GL entry (Debit Inventory, Credit AP/Clearing)    │
│   │  • Linked to Purchase Order / Supplier Invoice                         │
│   │                                                                         │
│   Transfer Exit Note (NEW) ────────────────────────────────────────────────│
│   │  • Decreases stock at source location                                  │
│   │  • Creates In-Transit entry                                             │
│   │  • For inter-location/warehouse movements                              │
│   │                                                                         │
│   Transfer Entry Note (NEW) ───────────────────────────────────────────────│
│      • Increases stock at destination location                             │
│      • Clears In-Transit entry                                              │
│      • Completes inter-location movement                                   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Document Relationships

```
                    SALES FLOW
                    
Quote ──────────▶ Order ──────────▶ Invoice
                    │                   │
                    │                   │ (links by line)
                    ▼                   ▼
              Delivery Note ◀──────────┘
                    │
                    ▼
            Stock Decremented
            + COGS GL Entry


                  PURCHASE FLOW

Purchase Order ──▶ Goods Receipt ──▶ Supplier Invoice
                        │                   │
                        │                   │
                        ▼                   ▼
                Stock Incremented      AP Created
                + Inventory GL


                  TRANSFER FLOW

Transfer Request ──▶ Exit Note ──▶ Entry Note
                        │              │
                        ▼              ▼
                   Stock Out      Stock In
                   (Location A)   (Location B)
                        │              │
                        └──▶ In-Transit ◀──┘
```

### 1.3 Key Business Rules

| Rule | Description |
|------|-------------|
| **Stock only moves on operational docs** | Invoice posting does NOT touch stock. Delivery Note confirmation does. |
| **Services have no delivery notes** | Service lines on invoices don't generate delivery notes |
| **Line-level tracking** | Delivery notes link to specific invoice lines, allowing partial deliveries |
| **Invoicing can follow delivery** | Delivery notes can be created first, invoiced later (batch invoicing) |
| **Year-end reconciliation** | All delivered items must have matching invoices by fiscal year end |
| **GL posting is automatic** | Invoice posting automatically creates GL entries (no manual button) |

### 1.4 Vehicle Data Strategy

**Key insight:** Vehicle data is for **business intelligence**, not accounting.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         VEHICLE DATA USAGE                                   │
│                                                                             │
│   CAPTURE POINT                    PURPOSE                                  │
│   ─────────────────────────────────────────────────────────────────────────│
│   Sales Order / POS               • Help find compatible parts              │
│                                   • Store vehicle context for the sale      │
│                                                                             │
│   Work Order (Workshop)           • Track service history                   │
│                                   • Mileage tracking                        │
│                                   • Warranty validation                     │
│                                                                             │
│   Customer Profile                • Vehicle ownership registry              │
│                                   • Quick lookup for returning customers    │
│                                                                             │
│   AGGREGATION (Background)        • Build fitment database                  │
│                                   • Calculate certainty scores              │
│                                   • Improve catalog accuracy                │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Where vehicle should NOT be:**
- ❌ Not on the Invoice model (financial doc)
- ❌ Not on the Delivery Note model (operational doc)
- ❌ Not required for GL entries

**Where vehicle SHOULD be:**
- ✅ On Order/Quote (captured during sales process)
- ✅ On Work Order (workshop context)
- ✅ On Customer record (ownership registry)
- ✅ In a `sale_vehicle_context` table (for analytics/fitment learning)

---

## 2. Current State Assessment

### 2.1 What's Already Working (Per User Confirmation)

| Feature | Status | Notes |
|---------|--------|-------|
| Delivery Notes per line | ✅ Implemented | Multiple delivery notes per invoice works |
| Service-only invoices | ✅ Implemented | No delivery notes created for services |
| Mixed invoices | ✅ Implemented | Only product lines get delivery notes |
| Invoice before/after delivery | ✅ Implemented | Both flows supported |
| Batch invoicing | ✅ Implemented | Can invoice multiple deliveries at once |
| Fiscal hash chain (Documents) | ✅ Implemented | NF525 compliant |
| Payment allocation | ✅ Implemented | FIFO, manual, tolerance handling |

### 2.2 What Needs Fixing (From Audit + Reviews)

| Issue | Description | Priority |
|-------|-------------|----------|
| **Vehicle coupling** | Document model imports Vehicle, has FK | 🔴 P0 |
| **GL not auto-created** | Invoice post doesn't auto-create GL | 🔴 P1 |
| **GL hash chain incomplete** | Missing `chain_sequence` field | 🔴 P1 |
| **Missing events (Accounting)** | No JournalEntryPosted, PeriodClosed | 🔴 P1 |
| **Missing events (Inventory)** | No StockConsumed, StockReturned | 🔴 P1 |
| **Interface documentation** | Service signatures don't match docs | 🟡 P2 |

### 2.3 What Needs Adding (New Features)

| Feature | Description | Priority |
|---------|-------------|----------|
| **Transfer Exit Note** | Stock out for inter-location transfer | 🟡 P2 |
| **Transfer Entry Note** | Stock in for inter-location transfer | 🟡 P2 |
| **Fitment learning** | Capture vehicle-part relationships from sales | 🟢 P3 |

---

## 3. Task Specifications

### 3.0 P0: Remove Vehicle Coupling from Document

**Objective:** Make Document module truly universal by removing Vehicle dependency.

**Current State:**
```php
// apps/api/app/Modules/Document/Domain/Document.php
use App\Modules\Vehicle\Domain\Vehicle;  // ❌ This import exists

// Document has vehicle_id FK
protected $fillable = [
    // ...
    'vehicle_id',  // ❌ This exists
];

public function vehicle(): BelongsTo
{
    return $this->belongsTo(Vehicle::class);  // ❌ This exists
}
```

**Target State:**
```php
// Document model - NO vehicle imports or relationships
// Vehicle context stored in JSONB payload or separate linking table
```

**Tasks:**

#### P0-TASK-001: Audit Vehicle Usage in Documents
```
1. Find all places where Document.vehicle_id is used
2. Find all places where Document->vehicle relationship is accessed
3. Document the use cases (display, filtering, reports)
4. Determine migration path for existing data
```

#### P0-TASK-002: Create Vehicle Context Strategy
```
Option A: Move to JSONB payload
- Store vehicle_id in Document.payload['vehicle_context']
- Pros: No migration, flexible
- Cons: Harder to query/index

Option B: Create linking table
- Create document_vehicle_context table
- Pros: Clean, queryable, can add certainty scores
- Cons: Extra join, migration needed

Option C: Move to Order only
- Vehicle is on Order, Invoice copies minimal context
- Pros: Matches business logic
- Cons: Need to trace back to order for vehicle details

RECOMMENDED: Option B for Otospex, Option A for IziPOS
- Otospex needs queryable vehicle data for fitment learning
- IziPOS rarely needs vehicle context
```

#### P0-TASK-003: Implement Migration
```
1. Create document_vehicle_context table (if Option B)
2. Migrate existing vehicle_id data to new location
3. Remove vehicle_id FK from documents table
4. Remove Vehicle import from Document model
5. Update any services that access Document->vehicle
6. Update frontend to fetch vehicle separately if needed
```

**Acceptance Criteria:**
- [ ] `grep -r "use App\\Modules\\Vehicle" app/Modules/Document` returns 0 results
- [ ] Document model has no vehicle_id column
- [ ] Existing data is preserved in new structure
- [ ] All tests pass

---

### 3.1 P1: Automatic GL Posting on Invoice

**Objective:** When an invoice is posted, GL entries are created automatically within the same transaction.

**Current State:**
- Invoice posting creates fiscal hash ✅
- Invoice posting dispatches InvoicePosted event ✅
- GL entry creation requires manual call ❌
- Tests manually call GeneralLedgerService ❌

**Target State:**
```php
// DocumentPostingService::post()
public function post(Document $document): void
{
    DB::transaction(function () use ($document) {
        // 1. Validate document is ready to post
        $this->validateForPosting($document);
        
        // 2. Lock document (pessimistic)
        $document = Document::lockForUpdate()->find($document->id);
        
        // 3. Generate document number if not set
        if (!$document->document_number) {
            $document->document_number = $this->numberingService->generate(...);
        }
        
        // 4. Create fiscal hash chain
        $this->fiscalHashService->createHash($document);
        
        // 5. AUTO-CREATE GL ENTRIES ← NEW
        $this->createGLEntries($document);
        
        // 6. Update status
        $document->status = DocumentStatus::POSTED;
        $document->posted_at = now();
        $document->save();
    });
    
    // 7. Dispatch event (AFTER commit)
    event(new InvoicePosted($document));
}

private function createGLEntries(Document $document): void
{
    if ($document->type === DocumentType::INVOICE) {
        // Debit: Accounts Receivable
        // Credit: Revenue (split by tax rate if needed)
        // Credit: Tax Payable (if applicable)
        $this->glService->createFromInvoice($document);
    }
    
    if ($document->type === DocumentType::CREDIT_NOTE) {
        // Reverse of invoice entries
        $this->glService->createFromCreditNote($document);
    }
    
    // Supplier invoices, etc.
}
```

**Tasks:**

#### P1-TASK-001: Inject GLService into DocumentPostingService
```
1. Add GeneralLedgerService dependency to DocumentPostingService
2. Ensure GLService methods exist: createFromInvoice(), createFromCreditNote()
3. Verify account lookups work (SystemAccountPurpose enum)
```

#### P1-TASK-002: Implement Auto GL Creation
```
1. Add createGLEntries() call inside the transaction
2. Handle different document types (Invoice, Credit Note, Supplier Invoice)
3. Ensure proper account mapping per document type
4. Handle multi-tax-rate scenarios (split revenue lines)
```

#### P1-TASK-003: Update Tests
```
1. Remove manual GL calls from DocumentGLIntegrationTest
2. Verify GL entries exist after document.post()
3. Test transaction rollback (if GL fails, invoice not posted)
```

#### P1-TASK-004: Add Configuration Option
```
// config/accounting.php
return [
    'auto_post_gl' => env('AUTO_POST_GL', true),
    
    // Future: per-country overrides
    'country_overrides' => [
        // 'DE' => ['auto_post_gl' => false],
    ],
];

// In DocumentPostingService
if (config('accounting.auto_post_gl', true)) {
    $this->createGLEntries($document);
}
```

**Acceptance Criteria:**
- [ ] Posting an invoice automatically creates GL entries
- [ ] GL entries are in same transaction (rollback together)
- [ ] InvoicePosted event fires AFTER commit
- [ ] Existing tests pass without manual GL calls
- [ ] Config option exists to disable (default: enabled)

---

### 3.2 P1: Complete GL Hash Chain

**Objective:** Ensure GL entries have the same compliance-grade hash chain as Documents.

**Current State (per Codex review):**
- Previous hash linking exists ✅
- SHA-256 hash calculation exists ✅
- `chain_sequence` field is NOT populated ❌

**Target State:**
```php
// JournalEntry should have:
$entry->hash;           // SHA-256 of entry data
$entry->previous_hash;  // Hash of previous entry
$entry->chain_sequence; // Sequential number for ordering
```

**Tasks:**

#### P1-TASK-005: Add chain_sequence Population
```
1. Update GeneralLedgerService::postEntry() to set chain_sequence
2. chain_sequence = previous entry's sequence + 1 (or 1 if genesis)
3. Add database index on (company_id, chain_sequence)
4. Verify pessimistic locking prevents race conditions
```

#### P1-TASK-006: Verify Hash Chain Integrity
```
1. Create HashChainVerificationService (or extend FiscalHashService)
2. Add method: verifyJournalEntryChain(Company $company): bool
3. Should detect any gaps or tampering
4. Add to compliance/audit reporting
```

**Acceptance Criteria:**
- [ ] All posted journal entries have chain_sequence populated
- [ ] chain_sequence is strictly sequential per company
- [ ] Hash chain can be verified programmatically
- [ ] No gaps in sequence after concurrent posting

---

### 3.3 P1: Add Missing Events

**Objective:** Enable event-driven architecture for all universal modules.

#### P1-TASK-007: Accounting Events
```php
// apps/api/app/Modules/Accounting/Domain/Events/

class JournalEntryPosted
{
    public function __construct(
        public JournalEntry $entry,
        public string $source_type,  // 'invoice', 'payment', 'adjustment'
        public ?string $source_id,
    ) {}
}

class FiscalPeriodClosed
{
    public function __construct(
        public FiscalPeriod $period,
        public User $closedBy,
    ) {}
}

// Dispatch in GeneralLedgerService::postEntry()
event(new JournalEntryPosted($entry, $sourceType, $sourceId));

// Dispatch in FiscalPeriodService::close()
event(new FiscalPeriodClosed($period, $user));
```

#### P1-TASK-008: Inventory Events
```php
// apps/api/app/Modules/Inventory/Domain/Events/

class StockConsumed
{
    public function __construct(
        public Product $product,
        public Location $location,
        public float $quantity,
        public string $source_type,  // 'delivery_note', 'adjustment'
        public string $source_id,
        public ?float $unit_cost,
    ) {}
}

class StockReceived
{
    public function __construct(
        public Product $product,
        public Location $location,
        public float $quantity,
        public string $source_type,  // 'goods_receipt', 'return', 'adjustment'
        public string $source_id,
        public ?float $unit_cost,
    ) {}
}

class StockTransferInitiated
{
    public function __construct(
        public Product $product,
        public Location $fromLocation,
        public Location $toLocation,
        public float $quantity,
        public string $transfer_id,
    ) {}
}

class StockTransferCompleted
{
    public function __construct(
        public string $transfer_id,
    ) {}
}
```

**Acceptance Criteria:**
- [ ] Events defined in Domain/Events folder for each module
- [ ] Events dispatched at appropriate points in services
- [ ] Events have all necessary data for listeners
- [ ] No breaking changes to existing functionality

---

### 3.4 P2: Stock Movement on Delivery Note (Verification)

**Objective:** Verify that stock deduction happens correctly when Delivery Note is confirmed.

**Expected Flow (per user confirmation, should already work):**
```
Delivery Note Created (Draft)
         │
         ▼
Delivery Note Confirmed
         │
         ├──▶ Stock Level Decreased
         │
         └──▶ COGS GL Entry Created (via PostCOGSOnInvoice listener)
```

**Tasks:**

#### P2-TASK-009: Audit Current Implementation
```
1. Trace DeliveryNote confirmation flow
2. Verify stock is actually decremented (not just COGS GL)
3. Document the exact service calls and events
4. Identify any gaps
```

#### P2-TASK-010: Create Integration Test
```php
// tests/Feature/Inventory/DeliveryNoteStockTest.php

public function test_confirming_delivery_note_decrements_stock(): void
{
    // Arrange
    $product = Product::factory()->create();
    $location = Location::factory()->create();
    StockLevel::factory()->create([
        'product_id' => $product->id,
        'location_id' => $location->id,
        'quantity' => 100,
    ]);
    
    $invoice = $this->createPostedInvoice([
        ['product_id' => $product->id, 'quantity' => 10],
    ]);
    
    $deliveryNote = DeliveryNote::factory()->create([
        'invoice_id' => $invoice->id,
        'status' => 'draft',
    ]);
    
    // Act
    $this->deliveryNoteService->confirm($deliveryNote);
    
    // Assert
    $this->assertDatabaseHas('stock_levels', [
        'product_id' => $product->id,
        'location_id' => $location->id,
        'quantity' => 90,  // 100 - 10
    ]);
    
    // Assert COGS GL entry created
    $this->assertDatabaseHas('journal_entries', [
        'source_type' => 'delivery_note',
        'source_id' => $deliveryNote->id,
    ]);
}
```

#### P2-TASK-011: Insufficient Stock Handling
```
1. Check if validation exists before confirmation
2. If stock < required, should confirmation fail or warn?
3. Implement based on business rule:
   - Strict mode: Throw InsufficientStockException
   - Warn mode: Allow but flag for review
   - Config: per-company setting
```

**Acceptance Criteria:**
- [ ] Confirming delivery note decrements stock
- [ ] COGS GL entry is created
- [ ] Integration test proves the full flow
- [ ] Insufficient stock is handled appropriately

---

### 3.5 P2: Transfer Notes (New Feature)

**Objective:** Support inter-location stock transfers with proper tracking.

**Business Flow:**
```
Location A                    In-Transit                    Location B
    │                             │                             │
    │  Exit Note Created          │                             │
    │  & Confirmed                │                             │
    ├────────────────────────────▶│                             │
    │                             │                             │
    │  Stock: -10                 │  In-Transit: +10            │
    │                             │                             │
    │                             │  Entry Note Created         │
    │                             │  & Confirmed                │
    │                             ├────────────────────────────▶│
    │                             │                             │
    │                             │  In-Transit: -10            │  Stock: +10
```

**Tasks:**

#### P2-TASK-012: Design Transfer Note Schema
```sql
-- Transfer header
stock_transfers (
    id UUID PRIMARY KEY,
    company_id UUID REFERENCES companies,
    from_location_id UUID REFERENCES locations,
    to_location_id UUID REFERENCES locations,
    status VARCHAR(20),  -- 'draft', 'in_transit', 'completed', 'cancelled'
    initiated_at TIMESTAMP,
    completed_at TIMESTAMP,
    initiated_by UUID REFERENCES users,
    completed_by UUID REFERENCES users,
    notes TEXT
);

-- Transfer lines
stock_transfer_lines (
    id UUID PRIMARY KEY,
    transfer_id UUID REFERENCES stock_transfers,
    product_id UUID REFERENCES products,
    quantity_sent DECIMAL(15,4),
    quantity_received DECIMAL(15,4),  -- Can differ if damaged
    unit_cost DECIMAL(15,4)
);
```

#### P2-TASK-013: Implement Transfer Service
```php
class StockTransferService
{
    public function initiate(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            // 1. Validate stock availability at source
            // 2. Decrement source location stock
            // 3. Increment in-transit (virtual location or flag)
            // 4. Update transfer status to 'in_transit'
            // 5. Create GL entry if perpetual inventory
        });
        
        event(new StockTransferInitiated($transfer));
    }
    
    public function complete(StockTransfer $transfer, array $receivedQuantities): void
    {
        DB::transaction(function () use ($transfer, $receivedQuantities) {
            // 1. Decrement in-transit
            // 2. Increment destination location stock
            // 3. Handle discrepancies (damaged, lost)
            // 4. Update transfer status to 'completed'
            // 5. Create GL entry if perpetual inventory
        });
        
        event(new StockTransferCompleted($transfer));
    }
}
```

**Acceptance Criteria:**
- [ ] Can create transfer between two locations
- [ ] Source stock decremented on initiation
- [ ] Destination stock incremented on completion
- [ ] In-transit tracking works
- [ ] Discrepancies (short receipt) are handled
- [ ] GL entries created for perpetual inventory

---

## 4. Verification Checklist

Before starting vertical modules, verify:

### Universal Module Isolation
```bash
# No Vehicle imports in Document module
grep -r "use App\\Modules\\Vehicle" app/Modules/Document
# Expected: 0 results

# No Workshop imports in universal modules
grep -r "use App\\Modules\\Workshop" app/Modules/Document
grep -r "use App\\Modules\\Workshop" app/Modules/Treasury
grep -r "use App\\Modules\\Workshop" app/Modules/Accounting
grep -r "use App\\Modules\\Workshop" app/Modules/Inventory
grep -r "use App\\Modules\\Workshop" app/Modules/Partner
# Expected: 0 results each
```

### GL Auto-Posting
```bash
# Run specific test
php artisan test --filter=DocumentGLIntegrationTest

# Verify no manual GL calls in posting flow
grep -r "createFromInvoice" app/Modules/Document
# Should only be in DocumentPostingService (auto-call)
```

### Hash Chain Integrity
```bash
# Run hash chain verification
php artisan compliance:verify-hash-chains --module=documents
php artisan compliance:verify-hash-chains --module=journal-entries
```

### Event Coverage
```bash
# List all events
find app/Modules/*/Domain/Events -name "*.php" | wc -l
# Expected: 15+ events

# Verify events are dispatched
grep -r "event(new" app/Modules/Document/Domain/Services
grep -r "event(new" app/Modules/Accounting/Domain/Services
grep -r "event(new" app/Modules/Inventory/Domain/Services
```

---

## 5. Timeline Estimate

| Phase | Tasks | Effort | Parallelizable |
|-------|-------|--------|----------------|
| **P0** | Vehicle decoupling | 2-3 days | No (must be first) |
| **P1** | GL auto-post + hash chain + events | 5-7 days | Yes (3 streams) |
| **P2** | Verification + transfers | 5-7 days | Yes (with verticals) |

**Total before verticals:** ~1.5-2 weeks

**With AI agents working in parallel:**
- P0: 1-2 days (single focus)
- P1: 2-3 days (parallel streams)
- P2: Can overlap with vertical work

---

## 6. Success Criteria

Universal modules are "frozen" when:

1. ✅ Zero imports from vertical modules
2. ✅ GL entries auto-created on document posting
3. ✅ Hash chains complete for documents AND journal entries
4. ✅ Events exist for all significant state changes
5. ✅ Integration tests prove key flows work
6. ✅ Stock movements tied to operational documents (not invoices)

After these criteria are met, vertical modules (Vehicle, Workshop, Menu, Recipe, POS) can safely build on top without risk of breaking the foundation.

---

*Document Version: 1.0*
*Last Updated: December 24, 2025*
