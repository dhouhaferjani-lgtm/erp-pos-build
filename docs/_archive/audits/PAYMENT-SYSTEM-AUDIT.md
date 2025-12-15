# Payment System Audit Report

**Generated:** 2025-12-05
**Purpose:** Assess current state before implementing Smart Payment Modal

## Executive Summary

| Area | Status | Notes |
|------|--------|-------|
| Payment Model | ✅ GOOD | Well-structured with allocations, status enum |
| Payment Allocation | ✅ GOOD | Links payments to documents, supports splits |
| General Ledger | ⚠️ EXISTS BUT NOT INTEGRATED | Full GL module with JournalEntry, but never called! |
| Customer Balance | ⚠️ WORKAROUND | Computed from `documents.balance_due` + unallocated payments |
| Document Payment Status | ⚠️ PARTIAL | Has `balance_due`, uses `Paid` status, no `amount_paid` |
| Payment Methods | ✅ EXCELLENT | Universal switches, fee calculation built-in |
| Payment Repositories | ✅ GOOD | Unified model with type enum |
| Event Sourcing | ⚠️ PARTIAL | `PaymentRecorded` exists, but not fully integrated |

### Key Finding: GL Exists But Is Disconnected

A complete double-entry accounting module exists at `app/Modules/Accounting/` with:
- `Account` model (chart of accounts with balances)
- `JournalEntry` model (with hash chain for compliance)
- `JournalLine` model (debit/credit lines)
- `GeneralLedgerService` with `createFromInvoice()`, `createPaymentEntry()`, etc.

**BUT: The GL service is never called!** Document posting and payment recording don't create journal entries.

---

## Part 1: Core Models

### 1.1 Payment Model

**Location:** `apps/api/app/Modules/Treasury/Domain/Payment.php`

**Status:** ✅ GOOD

**Fields Present:**
- [x] `id`, `tenant_id`, `company_id`, `partner_id`
- [ ] `payment_type` - **MISSING** (no advance/deposit distinction)
- [x] `payment_method_id` (FK to PaymentMethod)
- [x] `repository_id` (FK to PaymentRepository)
- [x] `instrument_id` (FK to PaymentInstrument - for checks etc.)
- [x] `amount`, `currency`
- [x] `payment_date`
- [x] `reference`, `notes`
- [x] `status` (enum: pending, completed, failed, reversed)
- [x] `journal_entry_id` (for GL integration)
- [x] Timestamps, `created_by`

**Key Methods:**
```php
getAllocatedAmount(): string    // Sum of allocations
getUnallocatedAmount(): string  // Amount not yet allocated
```

**Issues:**
1. No `payment_type` enum to distinguish:
   - `document_payment` (normal invoice payment)
   - `advance` / `deposit` (prepayment)
   - `refund` (money returned)
   - `credit_application` (using account balance)

---

### 1.2 Payment Allocation Model

**Location:** `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php`

**Status:** ✅ GOOD

**Fields Present:**
- [x] `id`
- [x] `payment_id` (FK to Payment)
- [x] `document_id` (FK to Document)
- [x] `amount`
- [x] Timestamps

**Capabilities:**
- [x] One payment → multiple allocations (split across invoices)
- [x] One document → multiple allocations (partial payments)
- [x] Amount stored (not calculated)

**Issues:**
1. `document_id` is NOT nullable - cannot track unallocated advance payments
2. No `allocation_type` to distinguish invoice payment vs credit application

---

### 1.3 Customer Balance / Credit Model

**Location:** `apps/api/app/Modules/Partner/Domain/Partner.php`

**Status:** ⚠️ PARTIAL - GL EXISTS BUT NOT INTEGRATED

#### A. General Ledger Implementation (EXISTS)

A full double-entry accounting module exists at `apps/api/app/Modules/Accounting/`:

```
Accounting/
├── Domain/
│   ├── Account.php           # Chart of accounts
│   ├── JournalEntry.php      # Journal entries with hash chain
│   ├── JournalLine.php       # Debit/credit lines
│   ├── Enums/
│   │   ├── AccountType.php   # asset, liability, equity, revenue, expense
│   │   └── JournalEntryStatus.php  # draft, posted, reversed
│   └── Services/
│       ├── GeneralLedgerService.php  # Creates JE from invoices/payments
│       └── DoubleEntryValidator.php
```

**GeneralLedgerService has methods for:**
```php
createFromInvoice(Document $invoice)     // Debit AR, Credit Revenue + VAT
createFromCreditNote(Document $creditNote)  // Reverse of invoice
createPaymentEntry(...)                   // Debit Bank, Credit AR
postEntry(JournalEntry $entry)           // Add hash chain
```

#### B. CRITICAL GAP: GL Not Integrated!

**The GeneralLedgerService is NOT being called anywhere!**

In `DocumentController::post()`:
```php
// Current code - just updates status, NO GL entry created
$documentModel->update(['status' => DocumentStatus::Posted]);
```

In `PaymentController::store()`:
```php
// Current code - NO GL entry created when payment recorded
$payment = Payment::create([...]);
```

#### C. Subledger Gap

The `journal_lines` table does NOT have a `partner_id` field:
```sql
journal_lines:
  - journal_entry_id
  - account_id
  - debit / credit
  - description
  - line_order
  -- NO partner_id for subledger tracking!
```

This means even if GL was integrated, you couldn't query:
- "What is Customer ABC's AR balance?"
- "Show me all JE lines for Supplier XYZ"

**Options to compute customer balance:**

| Method | Status | Accuracy |
|--------|--------|----------|
| Sum of `documents.balance_due` per partner | ✅ Works now | Good for AR |
| Sum of unallocated payments per partner | ✅ Works now | Good for credits |
| GL subledger with `partner_id` on lines | ❌ Not implemented | Best practice |

#### D. Current Partner Model

**Current Partner Fields:**
```php
$fillable = [
    'tenant_id', 'company_id', 'name', 'type',
    'code', 'email', 'phone', 'country_code',
    'vat_number', 'notes'
];
```

**Missing:**
- [ ] `credit_balance` (persisted customer credit)
- [ ] `receivable_balance` (persisted open invoice total)

**Current Workaround:**
The `MultiPaymentService` has methods to calculate unallocated balance on-the-fly:
```php
getUnallocatedDepositBalance(string $partnerId, string $currency): string
getPartnerAccountBalance(string $partnerId, string $currency): array
```

---

### 1.4 Document Payment Status

**Location:** `apps/api/app/Modules/Document/Domain/Document.php`

**Status:** ⚠️ PARTIAL

**Fields Present:**
- [x] `balance_due` - persisted remaining balance
- [ ] `amount_paid` - **MISSING** (must calculate as `total - balance_due`)
- [x] `status` with `Paid` value (set when `balance_due = 0`)

**Payment Status Logic:**
```php
// In PaymentController::store()
if (bccomp($newBalance, '0.00', 2) === 0) {
    $document->status = DocumentStatus::Paid;
}
```

**Issues:**
1. No explicit `payment_status` enum (unpaid, partial, paid, overpaid)
2. Must derive payment status from `balance_due` vs `total`
3. Payment status embedded in main document status (mixing concerns)

---

## Part 2: Payment Methods & Repositories

### 2.1 Payment Methods

**Location:** `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php`

**Status:** ✅ EXCELLENT

**Universal Switches (as per CLAUDE.md spec):**
```php
'is_physical'           // Physical instrument (check, voucher)
'has_maturity'          // Has a due date
'requires_third_party'  // Needs bank processing
'is_push'               // Customer initiates payment
'has_deducted_fees'     // Fees deducted at source
'is_restricted'         // Limited use (food vouchers)
```

**Fee System:**
```php
'fee_type'    // none, fixed, percentage, mixed
'fee_fixed'   // Fixed fee amount
'fee_percent' // Percentage fee
```

**Built-in Methods:**
```php
calculateFee(string $amount): string
calculateNetAmount(string $amount): string
```

**Company-scoped:** Yes (`company_id` field present)

---

### 2.2 Payment Repositories

**Location:** `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php`

**Status:** ✅ GOOD

**Type Enum:** `RepositoryType`
- `cash_register`
- `safe`
- `bank_account`
- `virtual`

**Key Fields:**
```php
'code', 'name', 'type'
'bank_name', 'account_number', 'iban', 'bic'
'balance', 'last_reconciled_at', 'last_reconciled_balance'
'location_id', 'responsible_user_id', 'account_id'
'is_active'
```

**Issues:**
1. No linking table `payment_method_repositories` to define which methods can use which repositories
2. Must be implemented in business logic or frontend

---

### 2.3 Payment Instruments

**Location:** `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php`

**Status:** ✅ GOOD

**Purpose:** Track physical payment instruments (checks, vouchers, promissory notes)

**Lifecycle Status Enum:** `InstrumentStatus`
- `received` → `deposited` → `cleared`
- Or: `received` → `bounced`
- Or: `received` → `expired`/`cancelled`/`collected`

**Key Fields:**
```php
'reference', 'drawer_name', 'amount', 'currency'
'received_date', 'maturity_date', 'expiry_date'
'status', 'repository_id'
'bank_name', 'bank_branch', 'bank_account'
'deposited_at', 'deposited_to_id', 'cleared_at', 'bounced_at'
```

---

## Part 3: Current Payment Flow

### 3.1 Payment Routes

**Location:** `apps/api/app/Modules/Treasury/Presentation/routes.php`

**Available Endpoints:**

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/payments` | GET | List payments |
| `/payments/{payment}` | GET | Get single payment |
| `/payments` | POST | Create payment with allocations |
| `/payments/{payment}/refund` | POST | Full refund |
| `/payments/{payment}/partial-refund` | POST | Partial refund |
| `/payments/{payment}/reverse` | POST | Reverse payment |
| `/documents/{document}/split-payment` | POST | Split payment across methods |
| `/payments/deposit` | POST | Record advance/deposit |
| `/payments/{payment}/apply-deposit` | POST | Apply deposit to document |
| `/partners/{partner}/unallocated-balance/{currency}` | GET | Get credit balance |
| `/payments/on-account` | POST | Payment on account |

---

### 3.2 Payment Services

**Main Controllers:**
- `PaymentController` - Basic CRUD with allocation support
- `PaymentRefundController` - Refund operations
- `MultiPaymentController` - Advanced operations

**Service Layer:**
- `MultiPaymentService` - Split payments, deposits, account balance

**Key Capabilities:**
```php
// Split payment across multiple methods
createSplitPayment(Document $document, array $paymentSplits): array

// Record advance/deposit (unallocated)
recordDeposit(...): Payment

// Apply deposit to invoice
applyDepositToDocument(Payment $deposit, Document $document, string $amount): PaymentAllocation

// Calculate credit balance
getUnallocatedDepositBalance(string $partnerId, string $currency): string
```

---

### 3.3 Frontend Payment Components

**Existing Components:**
- `RecordPaymentModal` - Modal for recording invoice payments
- `PaymentForm` - Full payment form page
- `PaymentListPage` - List all payments
- `PaymentDetailPage` - Single payment view
- `SplitPaymentForm` - Split payment across methods

**RecordPaymentModal Features:**
- Pre-fills from invoice (partner, amount, reference)
- Partner field locked when from invoice
- Payment method selector
- Repository selector with "Add New" option
- Amount, date, reference, notes fields

---

## Part 4: Event Sourcing & Audit Trail

### 4.1 Payment Events

**Status:** ⚠️ PARTIAL IMPLEMENTATION

**Existing Events:**
- `PaymentRecorded` - Exists with hash calculation

**Location:** `apps/api/app/Modules/Treasury/Domain/Events/PaymentRecorded.php`

```php
class PaymentRecorded extends DomainEvent
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $tenantId,
        public readonly string $partnerId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $paymentMethodId,
        public readonly string $recordedAt,
    ) {}

    public function getHashableData(): array { ... }
}
```

**Missing Events:**
- [ ] `PaymentAllocated`
- [ ] `PaymentVoided`
- [ ] `PaymentReversed`
- [ ] `CreditApplied`
- [ ] `DepositRecorded`

**Issues:**
1. Events are defined but not dispatched in controllers/services
2. No listener to store events in `stored_events` table
3. No hash chain implementation for fiscal compliance

---

### 4.2 Event Store Infrastructure

**Tables:**
- `stored_events` - Spatie Event Sourcing (general events)
- `audit_events` - Custom audit trail with hash

**Audit Events Table Schema:**
```sql
id, tenant_id, user_id, event_type, aggregate_type, aggregate_id
payload (jsonb), metadata (jsonb), event_hash, occurred_at
```

**Integration Status:**
- Tables exist
- DomainEvent base class configured for Spatie
- Events not being dispatched in payment flow

---

### 4.3 Accounting Integration

**Status:** ⚠️ NOT IMPLEMENTED

**Payment Model Has:**
```php
'journal_entry_id' // FK to journal entry (nullable)
```

**Issues:**
1. Journal entries not created when payment is recorded
2. No double-entry accounting (Debit Bank, Credit Receivables)
3. GL integration is placeholder only

---

## Part 4B: General Ledger Deep Dive

### 4B.1 What Exists

A complete double-entry accounting module was implemented at `apps/api/app/Modules/Accounting/`:

**Directory Structure:**
```
Accounting/
├── Application/
│   └── DTOs/
│       ├── AccountData.php
│       ├── JournalEntryData.php
│       └── JournalLineData.php
├── Domain/
│   ├── Account.php                    # Chart of accounts model
│   ├── JournalEntry.php               # Journal entry model
│   ├── JournalLine.php                # Individual debit/credit lines
│   ├── Enums/
│   │   ├── AccountType.php            # asset, liability, equity, revenue, expense
│   │   └── JournalEntryStatus.php     # draft, posted, reversed
│   └── Services/
│       ├── GeneralLedgerService.php   # Main service for creating entries
│       └── DoubleEntryValidator.php   # Validates debits = credits
├── Presentation/
│   ├── Controllers/
│   │   ├── AccountController.php      # CRUD for accounts
│   │   └── JournalEntryController.php # CRUD for entries
│   ├── Requests/
│   │   ├── CreateAccountRequest.php
│   │   ├── UpdateAccountRequest.php
│   │   └── CreateJournalEntryRequest.php
│   └── routes.php                     # API endpoints
└── Providers/
    └── AccountingServiceProvider.php
```

### 4B.2 Account Model

**Location:** `apps/api/app/Modules/Accounting/Domain/Account.php`

**Key Fields:**
```php
'tenant_id', 'company_id', 'parent_id'  // Hierarchy
'code', 'name', 'type'                  // Identity
'description', 'is_active', 'is_system' // Metadata
'balance'                               // Running balance
```

**Account Types (AccountType enum):**
- `asset` - increases with debit
- `liability` - increases with credit
- `equity` - increases with credit
- `revenue` - increases with credit
- `expense` - increases with debit

**Standard Account Codes (from GeneralLedgerService):**
- `1200` - Accounts Receivable (AR)
- `2100` - VAT Payable
- `4000` - Sales Revenue

### 4B.3 JournalEntry Model

**Location:** `apps/api/app/Modules/Accounting/Domain/JournalEntry.php`

**Key Fields:**
```php
'tenant_id', 'company_id'
'entry_number'                // Sequential: JE-2025-000001
'entry_date', 'description'
'status'                      // draft, posted, reversed
'source_type', 'source_id'    // Links to invoice/payment
'hash', 'previous_hash'       // Hash chain for compliance!
'posted_at', 'posted_by'
'reversed_at', 'reversed_by', 'reversal_entry_id'
```

**Hash Chain Implementation:**
```php
// In GeneralLedgerService::calculateHash()
$data = json_encode([
    'entry_number' => $entry->entry_number,
    'entry_date' => $entry->entry_date->toDateString(),
    'description' => $entry->description,
    'lines' => $entry->lines->map(fn ($line) => [
        'account_id' => $line->account_id,
        'debit' => $line->debit,
        'credit' => $line->credit,
    ])->toArray(),
]);

return hash('sha256', $previousHash . '|' . $data);
```

### 4B.4 JournalLine Model

**Location:** `apps/api/app/Modules/Accounting/Domain/JournalLine.php`

**Current Fields:**
```php
'journal_entry_id'  // FK to entry
'account_id'        // FK to account
'debit', 'credit'   // Amounts (decimal 15,2)
'description'
'line_order'
```

**MISSING - No partner_id for subledger!**

This is critical: without `partner_id` on journal lines, we cannot:
- Query AR balance per customer
- Query AP balance per supplier
- Generate customer/supplier statements from GL

### 4B.5 GeneralLedgerService Methods

**Location:** `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

```php
/**
 * Create journal entry from a posted invoice.
 * Debit: Accounts Receivable (total)
 * Credit: Sales Revenue (subtotal)
 * Credit: VAT Payable (tax)
 */
public function createFromInvoice(Document $invoice, User $user): JournalEntry

/**
 * Create journal entry from a credit note (reverse of invoice).
 * Debit: Sales Revenue (subtotal)
 * Debit: VAT Payable (tax)
 * Credit: Accounts Receivable (total)
 */
public function createFromCreditNote(Document $creditNote, User $user): JournalEntry

/**
 * Create a payment journal entry.
 * Debit: Cash/Bank account
 * Credit: Accounts Receivable
 */
public function createPaymentEntry(
    string $companyId,
    string $amount,
    string $debitAccountId,   // Cash/Bank
    string $creditAccountId,  // AR
    string $description,
    User $user,
): JournalEntry

/**
 * Post a journal entry (make it permanent with hash).
 */
public function postEntry(JournalEntry $entry, User $user): void
```

### 4B.6 THE CRITICAL GAP: Not Integrated!

**The GeneralLedgerService exists but is NEVER CALLED.**

**In DocumentController::post() (line 524):**
```php
public function post(Request $request, DocumentType $type, string $document): JsonResponse
{
    // ... validation ...

    // THIS IS ALL IT DOES:
    $documentModel->update(['status' => DocumentStatus::Posted]);

    // MISSING: No call to GeneralLedgerService::createFromInvoice()!
}
```

**In PaymentController::store() (line 141-187):**
```php
$payment = DB::transaction(function () use (...) {
    $payment = Payment::create([...]);

    // Creates allocations, updates document.balance_due
    foreach ($allocations as $allocationData) {
        PaymentAllocation::create([...]);
        $document->balance_due = $newBalance;
        $document->save();
    }

    return $payment;

    // MISSING: No call to GeneralLedgerService::createPaymentEntry()!
    // MISSING: No link to journal_entry_id on payment!
});
```

### 4B.7 Database Schema for Accounting

**Migration:** `2025_11_30_090000_create_accounts_table.php`
```sql
CREATE TABLE accounts (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    parent_id UUID,           -- Self-referential for hierarchy
    code VARCHAR(20),         -- Unique per tenant
    name VARCHAR(255),
    type VARCHAR(20),         -- asset, liability, equity, revenue, expense
    description TEXT,
    is_active BOOLEAN DEFAULT true,
    is_system BOOLEAN DEFAULT false,
    balance DECIMAL(19,2) DEFAULT 0,
    timestamps
);
```

**Migration:** `2025_11_30_100000_create_journal_entries_table.php`
```sql
CREATE TABLE journal_entries (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    entry_number VARCHAR,      -- JE-2025-000001
    entry_date DATE,
    description TEXT,
    status VARCHAR DEFAULT 'draft',
    source_type VARCHAR,       -- 'invoice', 'payment', etc.
    source_id UUID,
    hash VARCHAR,              -- SHA-256 hash
    previous_hash VARCHAR,     -- Link to previous entry
    posted_at TIMESTAMP,
    posted_by UUID,
    reversed_at TIMESTAMP,
    reversed_by UUID,
    reversal_entry_id UUID,
    timestamps
);

CREATE TABLE journal_lines (
    id UUID PRIMARY KEY,
    journal_entry_id UUID NOT NULL,
    account_id UUID NOT NULL,
    debit DECIMAL(15,2) DEFAULT 0,
    credit DECIMAL(15,2) DEFAULT 0,
    description TEXT,
    line_order INTEGER DEFAULT 0,
    timestamps
    -- NOTE: NO partner_id column!
);
```

### 4B.8 Current Customer Balance Computation

Since GL is not integrated, customer balance is computed using two methods:

**Method 1: AR (What customer owes) - via documents**
```php
// Sum of unpaid invoice balances per partner
Document::where('partner_id', $partnerId)
    ->whereIn('type', ['invoice'])
    ->whereIn('status', ['posted', 'confirmed'])
    ->sum('balance_due');
```

**Method 2: Credit (Customer overpayment) - via payments**
```php
// In MultiPaymentService::getUnallocatedDepositBalance()
$payments = Payment::where('partner_id', $partnerId)
    ->where('currency', $currency)
    ->where('status', PaymentStatus::Completed)
    ->with('allocations')
    ->get();

$totalUnallocated = '0.00';
foreach ($payments as $payment) {
    $allocatedAmount = $payment->allocations->sum('amount');
    $unallocated = bcsub($payment->amount, (string) $allocatedAmount, 2);
    if (bccomp($unallocated, '0', 2) > 0) {
        $totalUnallocated = bcadd($totalUnallocated, $unallocated, 2);
    }
}
return $totalUnallocated;
```

### 4B.9 What Would Proper GL Integration Look Like?

**Option A: Integrate Existing GL (Recommended Short-term)**

1. In `DocumentController::post()`:
```php
$documentModel->update(['status' => DocumentStatus::Posted]);

// ADD: Create GL entry
$glService = app(GeneralLedgerService::class);
$journalEntry = $glService->createFromInvoice($documentModel, $user);
$glService->postEntry($journalEntry, $user);
```

2. In `PaymentController::store()`:
```php
$payment = Payment::create([...]);

// ADD: Create GL entry
$journalEntry = $glService->createPaymentEntry(
    $companyId,
    $paymentAmount,
    $repositoryAccountId,  // Debit: Cash/Bank
    $receivableAccountId,  // Credit: AR
    "Payment for {$document->document_number}",
    $user
);
$payment->update(['journal_entry_id' => $journalEntry->id]);
```

**Option B: Add Subledger Support (Recommended Long-term)**

1. Add `partner_id` to `journal_lines`:
```sql
ALTER TABLE journal_lines ADD COLUMN partner_id UUID;
CREATE INDEX idx_journal_lines_partner ON journal_lines(partner_id);
```

2. Update `GeneralLedgerService::createFromInvoice()`:
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $receivableAccount->id,
    'partner_id' => $invoice->partner_id,  // ADD THIS
    'debit' => $invoice->total ?? '0.00',
    'credit' => '0.00',
    'description' => 'Accounts receivable',
    'line_order' => $lineOrder++,
]);
```

3. Query customer balance from GL:
```php
// AR balance for customer from GL
JournalLine::where('account_id', $arAccountId)
    ->where('partner_id', $partnerId)
    ->selectRaw('SUM(debit) - SUM(credit) as balance')
    ->value('balance');
```

---

## Part 5: Database Schema

### 5.1 Payment Tables

**Migration:** `2025_11_30_120000_create_treasury_tables.php`

**Tables Created:**
1. `payment_repositories`
2. `payment_methods`
3. `payment_instruments`
4. `payments`
5. `payment_allocations`

**Key Observations:**

1. **payment_allocations.document_id** - NOT nullable, prevents advance payments
2. **payments.payment_method_id** - NOT nullable, but `MultiPaymentService` sets null for "on account"
3. No `payment_type` column on payments table
4. No partner balance columns

---

### 5.2 Partner Balance Fields

**Current Migration:** No balance fields on partners table

**Needed:**
```sql
ALTER TABLE partners ADD COLUMN credit_balance DECIMAL(15,2) DEFAULT 0;
ALTER TABLE partners ADD COLUMN receivable_balance DECIMAL(15,2) DEFAULT 0;
```

**Or Create:**
```sql
CREATE TABLE partner_ledger (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    partner_id UUID NOT NULL,
    entry_type VARCHAR(30) NOT NULL,  -- advance, credit_use, refund, payment
    amount DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    reference VARCHAR(100),
    document_id UUID,
    payment_id UUID,
    created_at TIMESTAMP
);
```

---

## Part 6: Gap Analysis

### Required Architecture vs Current State

```
REQUIRED:
┌──────────────────────────────┐
│  Partner                     │
│  - credit_balance ❌         │
│  - receivable_balance ❌     │
└──────────────────────────────┘
         │
         ├──────────────────────────────┐
         ▼                              ▼
┌────────────────────┐        ┌────────────────────┐
│  CustomerLedger ❌  │        │  Documents ✅      │
│  (Audit Trail)     │        │  - balance_due ✅   │
└────────────────────┘        │  - payment_status ❌│
                              └────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────┐
│  Payment ✅                                          │
│  - payment_type ❌ (advance/document/refund/credit) │
│  - status ✅                                         │
└─────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────┐
│  PaymentAllocation ⚠️                                │
│  - document_id NOT nullable ❌                       │
│  - allocation_type ❌                                │
└─────────────────────────────────────────────────────┘
```

### Checklist: What Exists vs What's Needed

| Component | Exists? | Notes |
|-----------|---------|-------|
| **Payment Model** | ✅ | |
| - payment_type enum | ❌ | Needed for advance vs document payment |
| - status with void | ✅ | Has `reversed` status |
| **PaymentAllocation Model** | ✅ | |
| - Links payment → document | ✅ | |
| - Supports multiple allocations | ✅ | Split supported |
| - document_id nullable | ❌ | Blocks advance payments |
| - allocation_type field | ❌ | Can't distinguish credit application |
| **CustomerLedger Model** | ❌ | **CRITICAL** for compliance |
| - entry_type enum | ❌ | |
| **Partner Balance Fields** | | |
| - credit_balance (persisted) | ❌ | Currently calculated on-the-fly |
| - receivable_balance (persisted) | ❌ | Not tracked |
| **Document Payment Fields** | | |
| - amount_paid (persisted) | ❌ | Must calculate from balance_due |
| - balance_due (persisted) | ✅ | Working correctly |
| - payment_status enum | ❌ | Uses document status instead |
| **Payment Methods** | ✅ | Excellent implementation |
| - type enum (cash/check/etc) | ⚠️ | Uses `code` not separate type |
| - universal switches | ✅ | All 6 switches present |
| **Payment Repositories** | ✅ | |
| - Unified model | ✅ | |
| - type enum | ✅ | |
| - Method → Repository filter | ❌ | No linking table |
| **Events** | | |
| - PaymentRecorded | ✅ | Defined, not dispatched |
| - PaymentAllocated | ❌ | Not defined |
| - CreditApplied | ❌ | Not defined |
| - PaymentVoided | ❌ | Not defined |
| **Event Dispatching** | ❌ | Events not used in flow |
| **GL Integration** | ❌ | journal_entry_id unused |

---

## Part 7: Recommendations

### 7.1 Strategic Decision: GL Integration

**This is the most important decision before proceeding.**

The system has a complete but disconnected General Ledger. You have three options:

| Option | Effort | Benefit | Risk |
|--------|--------|---------|------|
| **A: Ignore GL for now** | Low | Fast to ship modal | Technical debt, harder to integrate later |
| **B: Integrate existing GL** | Medium | Compliance-ready, audit trail | Requires chart of accounts setup per company |
| **C: GL + Subledger** | High | Full accounting, per-customer AR from GL | Significant refactor |

**Recommendation:** Start with **Option B** (integrate existing GL) when posting invoices and recording payments. This gives you:
- Hash chain for compliance (already implemented in GL)
- Audit trail via journal entries
- Foundation for financial reports (trial balance, P&L)

The subledger (Option C) can be added later when you need per-customer AR queries from the GL.

### 7.2 Critical Gaps (Must Fix Before Modal)

1. **Make `payment_allocations.document_id` nullable**
   - Required for advance payments that aren't allocated to any document
   - Simple migration + model update

2. **Add `payment_type` enum to Payment model**
   - Values: `document_payment`, `advance`, `refund`, `credit_application`
   - Allows filtering and different behavior per type

3. **RecordPaymentModal needs allocation support**
   - Current modal sends `invoice_id` but controller expects `allocations` array
   - Misalignment between frontend and backend API

### 7.3 GL Integration Steps (If Option B Chosen)

1. **Integrate GL into document posting:**
   ```php
   // In DocumentController::post()
   $glService = app(GeneralLedgerService::class);
   $journalEntry = $glService->createFromInvoice($documentModel, $user);
   $glService->postEntry($journalEntry, $user);
   ```

2. **Integrate GL into payment recording:**
   ```php
   // In PaymentController::store()
   $journalEntry = $glService->createPaymentEntry(...);
   $payment->update(['journal_entry_id' => $journalEntry->id]);
   ```

3. **Ensure chart of accounts exists:**
   - Seed accounts 1200 (AR), 2100 (VAT), 4000 (Revenue) per company
   - Link payment repositories to their GL accounts

### 7.4 Missing Infrastructure (Medium Priority)

1. **Partner balance persistence (if not using GL subledger)**
   - Option A: Add `credit_balance` to Partner model
   - Option B: Create `partner_ledger` table for audit trail
   - Either way, current on-the-fly calculation works but is slower

2. **Payment-Method-to-Repository linking**
   - Create `payment_method_repositories` table
   - Or add `allowed_repository_types` jsonb to payment_methods
   - Enables smart filtering in modal

### 7.5 Suggested Implementation Order

**Phase 1: Schema Fixes (Before Modal)**
1. Migration: make `payment_allocations.document_id` nullable
2. Migration: add `payment_type` to payments
3. Update models and validation

**Phase 2: GL Integration (Recommended)**
1. Add GL calls to `DocumentController::post()` for invoices
2. Add GL calls to `PaymentController::store()`
3. Seed chart of accounts for existing companies

**Phase 3: Smart Payment Modal**
1. Fix frontend/backend API mismatch
2. Add credit balance display
3. Implement apply-credit flow

**Phase 4: Enhancements (Future)**
1. Add `partner_id` to journal_lines for subledger
2. Customer statement generation from GL
3. Automatic reconciliation

---

## Summary

### What's Working Well
- Payment, PaymentAllocation, PaymentMethod, PaymentRepository models are solid
- Split payment and advance deposit logic exists in MultiPaymentService
- Document `balance_due` tracking works correctly
- RecordPaymentModal UI is functional

### The GL Situation
A complete General Ledger module exists with:
- Account model with chart of accounts
- JournalEntry with hash chain for compliance
- JournalLine for debit/credit entries
- GeneralLedgerService with methods for invoices, credit notes, payments

**BUT: It's never called!** Document posting and payment recording don't create journal entries.

### Customer Balance Today
| What | How | Works? |
|------|-----|--------|
| AR (what they owe) | Sum of `documents.balance_due` | ✅ Yes |
| Credit (overpayment) | Sum of unallocated payments | ✅ Yes |
| From GL | Query journal_lines | ❌ No (not integrated) |

### Recommended Next Steps
1. **Decide on GL integration** - Do it now or defer?
2. **Fix schema blockers** - nullable document_id, payment_type enum
3. **Fix API mismatch** - Frontend sends invoice_id, backend expects allocations[]
4. **Then build Smart Payment Modal**

### Key Files Reference
| Component | Location |
|-----------|----------|
| Payment Model | `apps/api/app/Modules/Treasury/Domain/Payment.php` |
| PaymentAllocation | `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php` |
| PaymentController | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` |
| MultiPaymentService | `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php` |
| GeneralLedgerService | `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` |
| JournalEntry | `apps/api/app/Modules/Accounting/Domain/JournalEntry.php` |
| DocumentController | `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php` |
| RecordPaymentModal | `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx` |
