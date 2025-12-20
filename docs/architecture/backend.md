# Backend Architecture

> Complete backend documentation for the Laravel API at `apps/api/`.

---

## Overview

- **Framework**: Laravel 12.x (PHP 8.2+)
- **Architecture**: Hexagonal (Ports & Adapters)
- **Total Modules**: 22
- **Total PHP Files**: 300+
- **Migrations**: 90

---

## Module Structure

Each module follows this structure:

```
Module/
├── Domain/
│   ├── Entities/           # Eloquent models with business logic
│   ├── ValueObjects/       # Immutable value types
│   ├── Events/             # Domain events for event sourcing
│   ├── Services/           # Domain services (business rules)
│   ├── Enums/              # PHP 8.1 backed enums
│   └── Exceptions/         # Domain-specific exceptions
├── Application/
│   ├── DTOs/               # Data transfer objects (Spatie Data)
│   └── Services/           # Application services (use cases)
├── Infrastructure/
│   ├── Providers/          # Service providers
│   └── External/           # External API clients
└── Presentation/
    ├── Controllers/        # HTTP request handlers
    ├── Requests/           # Form request validation
    ├── Resources/          # API resources
    └── routes.php          # Module routes
```

---

## Module Inventory

### Identity Module (18 files)
**Purpose**: Authentication, users, devices, RBAC

**Entities**:
- `User` - Core user entity with status enum
- `Device` - User devices for session management

**Enums**:
- `UserStatus` (pending_verification, active, suspended)

**DTOs**:
- `AuthUserData`, `LoginData`, `LoginResponseData`, `UserData`

**Controllers**:
- `AuthController` - Login, logout, token management
- `UserController` - User CRUD
- `RoleController` - Role management

**Middleware**:
- `SetPermissionsTeam` - Multi-tenant permission binding

---

### Company Module (27 files)
**Purpose**: Companies, locations, fiscal configuration

**Entities**:
- `Company` - Tenant company with fiscal settings
- `Location` - Physical locations (warehouses, shops)
- `UserCompanyMembership` - User-company associations
- `FiscalYear`, `FiscalPeriod` - Accounting periods
- `CompanyHashChain` - Fiscal chain tracking
- `CompanySequence` - Document numbering

**Enums** (15 total):
- `CompanyStatus`, `LocationType`
- `MembershipRole`, `MembershipStatus`
- `SequenceType`, `HashChainType`
- `VerificationStatus`, `VerificationTier`
- `PeriodStatus`

**Key Fields on Company**:
```php
// Document sequences
'invoice_prefix', 'invoice_next_number'
'quote_prefix', 'quote_next_number'
'sales_order_prefix', 'sales_order_next_number'

// Inventory costing
'inventory_costing_method' // weighted_average, fifo, lifo
'default_target_margin'
'default_minimum_margin'
'allow_below_cost_sales'
```

---

### Document Module (34 files) - UNIFIED DOCUMENT SYSTEM
**Purpose**: All trade documents (quotes, orders, invoices, etc.)

**Entities**:
- `Document` - Master unified document
- `DocumentLine` - Line items
- `DocumentSequence` - Sequential numbering
- `DocumentAdditionalCost` - Freight, fees (landed cost)

**Enums**:
- `DocumentType` - quote, sales_order, invoice, credit_note, delivery_note, purchase_order
- `DocumentStatus` - draft, confirmed, posted, cancelled
- `FiscalStatus` - draft, approved, posted
- `FiscalCategory` - revenue, expense
- `CreditNoteReason` - reason codes

**Domain Services**:
- `DocumentPostingService` - Convert to GL entries
- `DocumentConversionService` - Quote→Order→Invoice workflow
- `DocumentNumberingService` - Sequential numbering with hash chains
- `DeliveryNoteService` - Delivery tracking
- `RefundService` - Credit notes and reversals

**Application Services**:
- `DocumentPdfService` - PDF generation
- `CreditNoteService` - Credit note creation
- `ArApOpeningService` - Opening balance documents

**Domain Events**:
- `InvoicePosted` - Triggers GL entry, hash chain
- `InvoicePaid` - Updates payment status
- `InvoiceCancelled` - Reversal entry
- `DeliveryNoteConfirmed` - DDT confirmation
- `DocumentConverted` - Workflow transition

---

### Accounting Module (30 files)
**Purpose**: Chart of accounts, journal entries, GL

**Entities**:
- `Account` - Chart of accounts
- `JournalEntry` - Accounting entries (header)
- `JournalLine` - Debit/credit lines
- `OpeningBalanceBatch` - Import batches

**Enums**:
- `AccountType` - asset, liability, equity, revenue, expense
- `SystemAccountPurpose` - accounts_receivable, sales_revenue, cash, bank, etc.
- `JournalEntryStatus` - draft, posted, reversed

**Domain Services**:
- `GeneralLedgerService` - GL entry creation
- `DoubleEntryValidator` - Debit=Credit validation

**Application Services**:
- `ChartOfAccountsService` - COA management
- `AccountingOpeningService` - Opening balance setup
- `PartnerBalanceService` - AR/AP tracking

**System Account Purposes**:
```php
'accounts_receivable'      // 411 - Customer invoices
'accounts_payable'         // 401 - Supplier invoices
'sales_revenue'            // 701 - Product sales
'cash'                     // 531 - Cash payments
'bank'                     // 512 - Bank transfers
'customer_advance'         // 419 - Advance payments
'payment_tolerance_expense'// 658 - Write-off underpayments
'payment_tolerance_income' // 758 - Write-off overpayments
```

---

### Inventory Module (36 files)
**Purpose**: Stock management, movements, physical counting

**Entities**:
- `StockLevel` - Current quantities by product/location
- `StockMovement` - Movement history
- `InventoryCounting` - Physical count sessions
- `InventoryCountingItem` - Count items
- `InventoryCountingAssignment` - User assignments
- `InventoryCountingEvent` - Event log
- `InventoryCounterMetrics` - Counter performance

**Enums**:
- `MovementType` - receipt, issue, transfer, adjustment
- `CountingStatus` - draft, scheduled, count_1_in_progress, ..., finalized, cancelled
- `CountingScopeType` - full_inventory, product_location, category
- `CountingExecutionMode` - parallel, sequential
- `ItemResolutionMethod` - auto_all_match, auto_counters_agree, third_count_decisive, manual_override

**Domain Services**:
- `StockAdjustmentService` - Pessimistic locking for movements

**Application Services**:
- `GoodsReceiptService` - PO receipt processing
- `InventoryCountingService` - Count session management
- `CountingReconciliationService` - Variance analysis
- `WeightedAverageCostService` - Cost calculations
- `LandedCostService` - Freight allocation (NEW)

**Counting Workflow**:
```
draft → scheduled → count_1_in_progress → count_1_completed
    → count_2_in_progress → count_2_completed
    → count_3_in_progress → count_3_completed
    → pending_review → finalized
```

---

### Treasury Module (32 files)
**Purpose**: Universal payment system

**Entities**:
- `Payment` - Payment records
- `PaymentMethod` - Configuration (cash, check, bank, etc.)
- `PaymentInstrument` - Physical instruments (checks, vouchers)
- `PaymentRepository` - Payment storage (safe, register, account)
- `PaymentAllocation` - Invoice↔Payment mapping
- `BankReconciliation` - Bank statement matching
- `CountryPaymentSettings` - Country-specific rules

**Enums**:
- `PaymentType` - incoming, outgoing
- `PaymentStatus` - pending, recorded, partially_allocated, allocated, reversed
- `RepositoryType` - safe, cash_register, bank_account, virtual
- `InstrumentStatus` - received, used, deposited, cleared, bounced

**Payment Method Switches**:
```php
'is_physical'          // Needs storage (check, voucher)
'has_maturity'         // Has due date (PDC, traite)
'requires_third_party' // Bank/gateway processing
'is_push'              // Client sends (vs pull like direct debit)
'has_deducted_fees'    // Fees taken from amount
'is_restricted'        // Limited use (meal voucher)
```

**Domain Services**:
- `PaymentRefundService` - Refund/reversal logic
- `MultiPaymentService` - Split payments

**Application Services**:
- `PaymentAllocationService` - Smart allocation (FIFO)
- `BankReconciliationService` - Bank matching
- `PaymentToleranceService` - Rounding tolerance

---

### Billing Module (28 files) - SaaS BILLING
**Purpose**: Subscription management, platform billing

**Entities**:
- `Plan` - Subscription plans with limits
- `PlanLimits` - Usage limits (users, products, invoices)
- `TenantSubscription` - Active subscriptions
- `BillingInvoice` - Platform invoices
- `BillingPayment` - Payments received
- `BillingRefund` - Refunds

**Enums**:
- `SubscriptionStatus` - trial, active, expired, suspended
- `InvoiceStatus` - draft, pending, sent, paid, overdue
- `PaymentStatus` - pending, processing, succeeded, failed
- `PaymentProviderCode` - manual, stripe

**Contracts**:
- `PaymentProviderInterface` - Provider abstraction

**Infrastructure**:
- `StripePaymentProvider` - Stripe implementation
- `ManualPaymentProvider` - Offline payments

**Notifications**:
- `InvoicePaidNotification`
- `PaymentSucceededNotification`
- `PaymentFailedNotification`
- `SubscriptionCancelledNotification`

---

## Shared Infrastructure

Location: `app/Shared/`

### Domain Layer Abstractions
```php
// Base entity with identity-based equality
abstract class Entity {
    public function equals(Entity $other): bool;
}

// Aggregate root for event sourcing
abstract class AggregateRoot extends \Spatie\EventSourcing\AggregateRoots\AggregateRoot {
}

// Value object base
abstract class ValueObject {
    abstract public function equals(ValueObject $other): bool;
}
```

### Contracts
```php
interface RepositoryInterface {
    public function find(string $id): ?object;
    public function save(object $entity): void;
    public function delete(object $entity): void;
}
```

### Application DTOs
```php
class PaginationData extends Data {
    public int $page;
    public int $perPage;
    public int $total;
    public int $totalPages;
    public bool $hasNextPage;
    public bool $hasPreviousPage;
}
```

---

## Configuration Files

| File | Purpose |
|------|---------|
| `config/tenancy.php` | Multi-tenancy (Stancl) |
| `config/event-sourcing.php` | Spatie event sourcing |
| `config/permission.php` | RBAC (Spatie) |
| `config/billing.php` | SaaS billing settings |
| `config/sanctum.php` | API authentication |
| `config/horizon.php` | Queue monitoring |
| `config/sentry.php` | Error tracking |

---

## Key Patterns

### Transaction Boundaries
```php
// Stock adjustment with pessimistic locking
DB::transaction(function () use ($productId, $locationId, $quantity) {
    $stock = StockLevel::where('product_id', $productId)
        ->where('location_id', $locationId)
        ->lockForUpdate()
        ->first();

    if ($stock->quantity < $quantity) {
        throw new InsufficientStockException();
    }

    $stock->decrement('quantity', $quantity);
    StockMovement::create([...]);
});
```

### Hash Chain Implementation
```php
class DocumentPostingService {
    public function post(Document $document): void {
        $previousHash = $this->getLastHash($document->company_id, $document->type);
        $document->hash = $this->calculateHash($document, $previousHash);
        $document->previous_hash = $previousHash;
        $document->chain_sequence = $this->getNextSequence();
        $document->status = DocumentStatus::Posted;
        $document->save();

        // Create GL entries
        $this->generalLedgerService->createEntry($document);
    }
}
```

### Event Sourcing Pattern
```php
// Domain event
class InvoicePosted extends StoredEvent {
    public function __construct(
        public string $documentId,
        public string $companyId,
        public string $hash,
        public string $previousHash,
    ) {}
}

// Projector
class InvoiceProjector extends Projector {
    public function onInvoicePosted(InvoicePosted $event): void {
        // Update read model
    }
}
```

---

## Testing Strategy

### Unit Tests (70%)
- Domain services, value objects
- No database, no HTTP

### Integration Tests (20%)
- Repository implementations
- Database transactions
- Event store operations

### E2E Tests (10%)
- Critical user journeys
- Happy paths + key errors

### Running Tests
```bash
# All tests
php artisan test

# Specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# With coverage
php artisan test --coverage
```

---

## Quality Gates

```bash
# Run before commit
./scripts/preflight.sh

# Or individually
./vendor/bin/phpstan analyse --level=8
./vendor/bin/pint --test
php artisan test
```

---

## Related Documentation

- [Architecture Overview](./overview.md)
- [Database Schema](./database.md)
- [API Reference](../api/README.md)
- [Module Reference](../modules/README.md)
