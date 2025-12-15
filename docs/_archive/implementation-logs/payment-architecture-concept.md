# Customer Receivables & Payment Architecture

> **Reference Document for Claude Code**
> **Type:** Conceptual Architecture
> **Domain:** Treasury / Accounts Receivable

---

## Core Principle: Persisted, Auditable, No Virtual Calculations

Every balance, every allocation, every status change must be:
1. **Persisted** — Stored in database, not calculated on-the-fly
2. **Auditable** — Has an event/ledger trail showing how it got there
3. **Traceable** — Can be linked to source transactions

**WHY:** Compliance requirements (NF525, ZATCA), multi-user consistency, report accuracy.

---

## Customer Account Model

A customer (Partner) has two key balances:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           PARTNER ACCOUNT                                    │
│                                                                             │
│  ┌─────────────────────────────────┐   ┌─────────────────────────────────┐ │
│  │      CREDIT BALANCE             │   │      RECEIVABLE BALANCE         │ │
│  │      (Money they gave us        │   │      (Money they owe us         │ │
│  │       in advance)               │   │       from invoices)            │ │
│  │                                 │   │                                 │ │
│  │  Example: +500 EUR              │   │  Example: +1,200 EUR            │ │
│  │  (Customer pre-paid)            │   │  (Open invoices total)          │ │
│  └─────────────────────────────────┘   └─────────────────────────────────┘ │
│                                                                             │
│  NET POSITION = Receivable - Credit = 1,200 - 500 = 700 EUR (they owe us) │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Balance Update Rules

| Event | Credit Balance | Receivable Balance |
|-------|----------------|-------------------|
| Invoice posted | — | +amount |
| Invoice paid (full) | — | -amount |
| Invoice paid (partial) | — | -paid_amount |
| Advance payment received | +amount | — |
| Credit applied to invoice | -amount | -amount |
| Credit note issued | +amount | -amount |
| Refund given | -amount | — |

**CRITICAL:** These balances are updated via domain events, not recalculated.

---

## Payment Types

```php
enum PaymentType: string
{
    // Money coming IN
    case DocumentPayment = 'document_payment';  // Paying specific invoice(s)
    case AdvancePayment = 'advance_payment';    // Pre-payment, adds to credit
    
    // Money going OUT
    case Refund = 'refund';                     // Giving money back
    case Change = 'change';                     // Cash change (not tracked in balance)
    
    // Internal movement (no cash movement)
    case CreditApplication = 'credit_application';  // Using credit to pay invoice
}
```

---

## Payment Flows

### Flow 1: Simple Invoice Payment

```
User: "Record $800 payment against Invoice INV-001"

1. Create Payment:
   - type: document_payment
   - amount: 800
   - partner_id: X
   - method: cash
   - repository: main_register

2. Create PaymentAllocation:
   - payment_id: [new payment]
   - document_id: INV-001
   - amount: 800

3. Update Document:
   - amount_paid += 800
   - amount_residual = total - amount_paid
   - payment_status = (residual == 0) ? 'paid' : 'partial'

4. Fire Events:
   - PaymentRecorded
   - DocumentPaymentReceived

5. Update Partner (via event listener):
   - receivable_balance -= 800 (if invoice was open)

6. Create CustomerLedger entry:
   - entry_type: invoice_payment
   - document_ref: INV-001
   - amount: -800 (reduces what they owe)
   - balance_after: [new receivable balance]
```

### Flow 2: Advance Payment (Customer Pre-pays)

```
User: "Customer gives us $500 as advance/deposit"

1. Create Payment:
   - type: advance_payment
   - amount: 500
   - partner_id: X
   - method: cash
   - repository: main_register
   - document_id: NULL (no specific invoice)

2. NO PaymentAllocation (no document to allocate to)

3. Update Partner:
   - credit_balance += 500

4. Fire Events:
   - PaymentRecorded
   - CustomerCreditReceived

5. Create CustomerLedger entry:
   - entry_type: advance_received
   - document_ref: NULL
   - amount: +500 (they have credit)
   - balance_after: [new credit balance]
```

### Flow 3: Overpayment (Customer Pays More Than Due)

```
User: "Invoice is $800, customer pays $1,000 cash"

STEP 1: System detects overpayment ($200 excess)

STEP 2: User must choose:
  ├── Option A: "Give change" (DEFAULT for cash)
  │   → Only record $800 payment
  │   → $200 is physical change, no system record needed
  │
  └── Option B: "Apply excess to customer balance"
      → Record $1,000 payment
      → $800 allocated to invoice
      → $200 becomes customer credit

If Option B chosen:

1. Create Payment:
   - type: document_payment (primary purpose)
   - amount: 1000
   
2. Create PaymentAllocations:
   - allocation 1: document_id=INV-001, amount=800
   - allocation 2: document_id=NULL, amount=200, type=credit_addition

3. Update Document:
   - amount_paid = 800
   - payment_status = 'paid'

4. Update Partner:
   - receivable_balance -= 800
   - credit_balance += 200

5. Create CustomerLedger entries:
   - entry 1: invoice_payment, -800
   - entry 2: overpayment_credit, +200
```

### Flow 4: Apply Customer Credit to Invoice

```
User: "Customer has $500 credit, apply it to Invoice INV-002 ($300)"

1. Create Payment:
   - type: credit_application
   - amount: 300
   - partner_id: X
   - method: NULL (no physical payment method)
   - repository: NULL (no cash movement)

2. Create PaymentAllocation:
   - payment_id: [new payment]
   - document_id: INV-002
   - amount: 300
   - allocation_type: credit_used

3. Update Document:
   - amount_paid += 300
   - payment_status = 'paid' (if fully paid)

4. Update Partner:
   - credit_balance -= 300
   - receivable_balance -= 300

5. Fire Events:
   - CreditApplied

6. Create CustomerLedger entry:
   - entry_type: credit_applied
   - document_ref: INV-002
   - amount: -300 (credit used)
   - balance_after: 200 (remaining credit)
```

### Flow 5: Apply Credit to Multiple Invoices (FIFO)

```
User: "Apply all credit ($500) to open invoices, oldest first"

Open invoices (by date):
- INV-001: $200 due (Jan 1)
- INV-002: $150 due (Jan 15)
- INV-003: $400 due (Feb 1)

System applies $500 credit:
1. INV-001: Apply $200 → fully paid, remaining credit: $300
2. INV-002: Apply $150 → fully paid, remaining credit: $150
3. INV-003: Apply $150 → partial ($250 still due), remaining credit: $0

Creates 3 credit_application payments with allocations.
```

### Flow 6: Manual Credit Allocation

```
User: "Apply $300 credit specifically to INV-003"

Same as Flow 4, but user explicitly selects target invoice.
UI shows list of open invoices with checkboxes and amount inputs.
```

---

## Data Model Requirements

### Partner (Customer)

```php
// These fields MUST be persisted, not calculated
Schema::table('partners', function (Blueprint $table) {
    $table->decimal('credit_balance', 15, 2)->default(0);      // Money they pre-paid
    $table->decimal('receivable_balance', 15, 2)->default(0);  // Money they owe (invoices)
});
```

### CustomerLedger (Audit Trail)

```php
Schema::create('customer_ledger_entries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id');
    $table->foreignId('partner_id');
    
    // What happened
    $table->string('entry_type'); // invoice_posted, payment_received, credit_applied, refund, etc.
    
    // Reference
    $table->foreignId('document_id')->nullable();   // Invoice if applicable
    $table->foreignId('payment_id')->nullable();    // Payment if applicable
    $table->string('reference')->nullable();        // Human-readable ref
    
    // Money movement
    $table->decimal('debit', 15, 2)->default(0);    // Increases receivable
    $table->decimal('credit', 15, 2)->default(0);   // Decreases receivable
    
    // Running balance (PERSISTED at time of entry)
    $table->decimal('receivable_balance_after', 15, 2);
    $table->decimal('credit_balance_after', 15, 2);
    
    $table->text('notes')->nullable();
    $table->foreignId('created_by');
    $table->timestamps();
    
    $table->index(['partner_id', 'created_at']);
});
```

### Payment

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id');
    $table->foreignId('partner_id');
    
    // Type
    $table->string('payment_type'); // document_payment, advance_payment, refund, credit_application
    
    // Method & Repository (nullable for credit_application)
    $table->foreignId('payment_method_id')->nullable();
    $table->foreignId('payment_repository_id')->nullable();
    
    // Amount
    $table->decimal('amount', 15, 2);
    $table->string('currency', 3);
    
    // Dates
    $table->date('payment_date');
    $table->date('due_date')->nullable();  // For post-dated checks
    
    // Status
    $table->string('status'); // pending, completed, voided
    
    // Reference
    $table->string('reference')->nullable();
    $table->string('check_number')->nullable();
    $table->text('notes')->nullable();
    
    // Audit
    $table->foreignId('created_by');
    $table->foreignId('voided_by')->nullable();
    $table->timestamp('voided_at')->nullable();
    $table->string('void_reason')->nullable();
    
    $table->timestamps();
    $table->softDeletes();
});
```

### PaymentAllocation

```php
Schema::create('payment_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_id');
    $table->foreignId('document_id')->nullable();  // NULL for advance payments
    
    // Allocation type
    $table->string('allocation_type'); // invoice_payment, credit_addition, credit_used
    
    // Amount allocated
    $table->decimal('amount', 15, 2);
    
    $table->timestamps();
    
    $table->index(['document_id']);
    $table->index(['payment_id']);
});
```

### Document Payment Fields

```php
Schema::table('documents', function (Blueprint $table) {
    $table->decimal('amount_paid', 15, 2)->default(0);
    $table->decimal('amount_residual', 15, 2)->storedAs('total_amount - amount_paid');
    // OR persisted and updated via event:
    // $table->decimal('amount_residual', 15, 2)->default(0);
    
    $table->string('payment_status')->default('unpaid'); // unpaid, partial, paid
});
```

---

## Event Flow

```
PaymentRecorded
    ├── UpdateDocumentPaymentStatus (if document_payment)
    ├── UpdatePartnerReceivableBalance (if document_payment)
    ├── UpdatePartnerCreditBalance (if advance_payment)
    ├── CreateCustomerLedgerEntry
    └── PostToAccounting (journal entry)

CreditApplied
    ├── UpdateDocumentPaymentStatus
    ├── UpdatePartnerReceivableBalance
    ├── UpdatePartnerCreditBalance
    └── CreateCustomerLedgerEntry

PaymentVoided
    ├── ReverseDocumentPaymentStatus
    ├── ReversePartnerBalances
    ├── CreateReversalLedgerEntry
    └── ReverseJournalEntry
```

---

## UI Requirements

### Payment Modal Must Show:

```
┌─────────────────────────────────────────────────────────────────┐
│  Record Payment                                          [X]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Invoice: INV-2025-001                                         │
│  Customer: ACME Corp                                           │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Invoice Total      │  Already Paid  │  Remaining Due   │   │
│  │  €1,000.00          │  €200.00       │  €800.00         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Customer Credit Balance: €150.00                       │   │
│  │  [Apply Credit to This Invoice]                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Payment Method: [Cash ▼]                                      │
│  Repository:     [Main Register ▼]                             │
│  Amount:         [€800.00]                                     │
│  Date:           [2025-01-20]                                  │
│                                                                 │
│  ─────────────────────────────────────────────────────────────│
│  │ If amount > remaining:                                    │ │
│  │ ○ Give change (do not record excess)    [DEFAULT]        │ │
│  │ ○ Add excess to customer credit balance                  │ │
│  ─────────────────────────────────────────────────────────────│
│                                                                 │
│                           [Cancel]  [Record Payment]            │
└─────────────────────────────────────────────────────────────────┘
```

### Customer Page Must Show:

```
┌─────────────────────────────────────────────────────────────────┐
│  ACME Corp                                                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Balances                                                       │
│  ┌────────────────────────┬────────────────────────┐           │
│  │  Open Invoices         │  Credit Balance        │           │
│  │  €2,500.00             │  €150.00               │           │
│  │  (5 invoices)          │  [Apply to Invoices]   │           │
│  └────────────────────────┴────────────────────────┘           │
│                                                                 │
│  Transaction History                                            │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Date       │ Type              │ Ref      │ Amount     │   │
│  ├────────────┼───────────────────┼──────────┼────────────│   │
│  │ 2025-01-20 │ Invoice Payment   │ INV-005  │ -€500.00   │   │
│  │ 2025-01-15 │ Invoice Posted    │ INV-005  │ +€500.00   │   │
│  │ 2025-01-10 │ Advance Payment   │ ADV-001  │ +€150.00   │   │
│  │ 2025-01-05 │ Credit Applied    │ INV-003  │ -€200.00   │   │
│  │ ...        │ ...               │ ...      │ ...        │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Implementation Order

Based on dependencies:

1. **CustomerLedger model + migration** (audit trail first)
2. **Partner balance fields** (credit_balance, receivable_balance)
3. **PaymentAllocation model** (if doesn't exist)
4. **Payment model updates** (payment_type enum)
5. **Document payment fields** (amount_paid, payment_status)
6. **Event handlers** to update balances
7. **Payment service** with all flows
8. **Payment modal** (finally, the UI)

---

## Questions for Audit

The pre-flight audit should answer:

1. Does CustomerLedger (or equivalent) exist?
2. Are Partner balances persisted or calculated?
3. Does PaymentAllocation exist and how does it work?
4. What payment types are currently supported?
5. Are there events for payment recording?
6. How are document payment statuses updated currently?

**Get these answers before implementing anything.**
