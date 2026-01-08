# Module Architecture Guide

## Overview

AutoERP follows a **modular monolith** architecture with strict module boundaries and hexagonal (ports & adapters) design within each module. This guide documents the established patterns and conventions for module development.

## Core Principles

### 1. Module Boundaries are Sacred

Each module is self-contained with clear public interfaces:

```
app/Modules/
├── Identity/              # Authentication, users, permissions
├── Tenant/                # Multi-tenancy management
├── Company/               # Company management, locations
├── Partner/               # Customers and suppliers
├── Product/               # Products and categories
├── Service/               # Service catalog
├── Vehicle/               # Vehicle management (OPTIONAL - decoupled)
├── Document/              # Documents (quotes, orders, invoices, etc.)
├── Inventory/             # Stock levels, movements, counting
├── Treasury/              # Payments, instruments, repositories
├── Accounting/            # General ledger, journal entries
├── Communication/         # Email, SMS, notifications
└── Media/                 # File storage and attachments
```

### 2. Module Structure (Hexagonal Architecture)

Every module follows this consistent structure:

```
ModuleName/
├── Domain/
│   ├── EntityName.php              # Eloquent models (domain entities)
│   ├── Events/                     # Domain events (immutable)
│   │   └── EntityActionName.php
│   ├── Services/                   # Domain services (business logic)
│   │   └── EntityBusinessService.php
│   ├── Enums/                      # Type-safe enumerations
│   │   └── EntityStatus.php
│   └── Observers/                  # Model lifecycle hooks
│       └── EntityObserver.php
├── Application/
│   ├── DTOs/                       # Data Transfer Objects
│   │   └── EntityData.php
│   └── Services/                   # Application services (orchestration)
│       └── EntityApplicationService.php
├── Infrastructure/
│   └── External/                   # Third-party integrations
├── Presentation/
│   ├── Controllers/                # HTTP controllers (thin)
│   │   └── EntityController.php
│   ├── Requests/                   # Form request validation
│   │   ├── CreateEntityRequest.php
│   │   └── UpdateEntityRequest.php
│   └── Resources/                  # API response resources (optional)
│       └── EntityResource.php
├── Providers/
│   └── ModuleServiceProvider.php   # Module registration
├── Listeners/                      # Event listeners
│   └── HandleEntityEvent.php
└── routes.php                      # Module routes
```

### 3. Cross-Module Communication

Modules communicate ONLY via:

1. **Service Interfaces** (defined in `Shared/Contracts/`)
2. **Domain Events** (asynchronous)
3. **Public Service Methods** (on Application Services)

**FORBIDDEN:**
```php
// ❌ WRONG - Direct model access across modules
use App\Modules\Inventory\Domain\StockLevel;
$stock = StockLevel::where('product_id', $productId)->first();

// ✅ CORRECT - Via interface
use App\Shared\Contracts\InventoryServiceInterface;
$stock = $this->inventoryService->getStockLevel($productId, $locationId);
```

### 4. Domain Events (Event-Driven Architecture)

Events are **immutable** and dispatched at transaction boundaries:

**Event Naming Convention:**
- `EntityPastTenseAction` (e.g., `InvoicePosted`, `PaymentRecorded`, `StockMoved`)

**Event Structure:**
```php
namespace App\Modules\ModuleName\Domain\Events;

use App\Shared\Domain\DomainEvent;

final class EntityActionName extends DomainEvent
{
    public function __construct(
        public readonly string $entityId,
        public readonly string $tenantId,
        public readonly string $companyId,
        // ... other immutable properties
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }
}
```

**Event Dispatch Pattern:**
```php
DB::transaction(function () use ($invoice) {
    // 1. Validate business rules
    if (!$invoice->canBePosted()) {
        throw new InvoiceNotConfirmedException();
    }

    // 2. Create event FIRST (with fiscal hash if applicable)
    $event = new InvoicePosted(
        invoiceId: $invoice->id,
        tenantId: $invoice->tenant_id,
        companyId: $invoice->company_id,
        // ...
        occurredAt: now()->toIso8601String(),
    );

    // 3. Dispatch event
    event($event);

    // 4. Update state
    $invoice->update(['status' => DocumentStatus::Posted]);

    // 5. Side effects (GL entries, etc.)
    $this->ledgerService->recordInvoice($invoice);
});
```

### 5. Type Safety

**Strict Typing Rules:**
- All PHP files use `declare(strict_types=1);`
- No `mixed` types (use DTOs or Union types)
- All database columns have corresponding DTO properties
- JSONB columns MUST have a DTO class

**Enum Usage:**
```php
// ✅ CORRECT - Type-safe status
$document->status = DocumentStatus::Posted;

// ❌ WRONG - Magic strings
$document->status = 'posted';
```

## Module Dependencies

### Dependency Rules

1. **Identity** ← No dependencies (leaf module)
2. **Tenant** ← Identity
3. **Company** ← Tenant, Identity
4. **Partner** ← Company, Tenant
5. **Product** ← Company, Tenant
6. **Vehicle** ← Company, Partner (OPTIONAL - soft reference only)
7. **Document** ← Partner, Product, Company
8. **Inventory** ← Product, Document, Company (via Location)
9. **Treasury** ← Document, Partner, Company
10. **Accounting** ← Document, Treasury, Company
11. **Communication** ← Document, Partner
12. **Media** ← Document

### Optional Module Pattern (Vehicle Example)

The Vehicle module demonstrates how to create optional modules:

1. **Soft References** - No foreign key constraints
2. **Snapshot Data** - Store vehicle data at time of service
3. **Polymorphic Linking** - Use linking tables instead of direct FKs

```php
// DocumentVehicleContext - linking table (soft reference)
class DocumentVehicleContext extends Model
{
    // Stores snapshot of vehicle data at time of service
    protected $fillable = [
        'document_id',
        'vehicle_id',        // Soft reference (no FK)
        'vin',               // Snapshot
        'mileage_km',        // Snapshot
        'license_plate',     // Snapshot
        // ...
    ];
}
```

## Controller Patterns

### Type-Specific Controllers

Split controllers by document/entity type for maintainability:

**Before (Monolithic):**
```php
// DocumentController - 1,247 lines, handles all document types
class DocumentController {
    public function index() { /* 150 lines */ }
    public function store() { /* 200 lines */ }
    // ...
}
```

**After (Type-Specific):**
```php
// QuoteController - 150 lines, handles only quotes
// InvoiceController - 180 lines, handles only invoices
// SalesOrderController - 160 lines, handles only sales orders
```

### Shared Controller Concerns (Traits)

Extract common logic into traits:

```php
trait HandlesDocuments
{
    protected function baseQuery(): Builder
    {
        return Document::query()
            ->where('company_id', $this->companyContext->getCompanyId())
            ->with(['partner', 'lines.product']);
    }

    protected function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->partner_id, fn($q, $id) => $q->where('partner_id', $id));
    }
}
```

## Service Layer Patterns

### Domain Services vs Application Services

**Domain Service** - Pure business logic, no infrastructure concerns:
```php
namespace App\Modules\Document\Domain\Services;

class DeliveryNoteService
{
    public function canConfirm(Document $deliveryNote): bool
    {
        // Business rules only
        return $deliveryNote->status === DocumentStatus::Draft
            && $deliveryNote->lines->isNotEmpty();
    }
}
```

**Application Service** - Orchestration, transactions, event dispatch:
```php
namespace App\Modules\Document\Application\Services;

class DocumentPostingService
{
    public function __construct(
        private GeneralLedgerService $ledger,
        private FiscalHashService $hashService,
    ) {}

    public function postInvoice(Document $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            // Orchestrate multiple operations
            $hash = $this->hashService->calculateHash($invoice);
            event(new InvoicePosted(...));
            $invoice->update(['status' => DocumentStatus::Posted]);
            $this->ledger->recordInvoice($invoice);
        });
    }
}
```

### Strategy Pattern for Polymorphic Behavior

Use Strategy + Registry for type-specific operations:

```php
// Strategy Interface
interface DocumentConverterStrategy
{
    public function supports(DocumentType $from, DocumentType $to): bool;
    public function convert(Document $source): Document;
}

// Concrete Strategy
class QuoteToOrderConverter implements DocumentConverterStrategy
{
    public function supports(DocumentType $from, DocumentType $to): bool
    {
        return $from === DocumentType::Quote
            && $to === DocumentType::SalesOrder;
    }

    public function convert(Document $quote): Document
    {
        // Type-specific conversion logic
    }
}

// Registry
class DocumentConversionRegistry
{
    private array $converters = [];

    public function register(DocumentConverterStrategy $converter): void
    {
        $this->converters[] = $converter;
    }

    public function getConverter(DocumentType $from, DocumentType $to): DocumentConverterStrategy
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($from, $to)) {
                return $converter;
            }
        }
        throw new ConversionNotSupportedException();
    }
}
```

## Testing Strategy

### Test Organization

```
tests/
├── Unit/                          # Domain logic, no DB/HTTP
│   └── ModuleName/
│       └── ServiceNameTest.php
├── Feature/                       # Integration tests with DB
│   └── ModuleName/
│       ├── EntityCrudTest.php
│       └── EntityWorkflowTest.php
└── E2E/                          # End-to-end user journeys
    └── CompleteOrderCycleTest.php
```

### Test Pyramid
- **70% Unit Tests** - Domain services, value objects
- **20% Feature Tests** - API endpoints, database operations
- **10% E2E Tests** - Critical user workflows

## Key Architectural Achievements

### 1. Vehicle Module Decoupling ✅
- Core modules have zero vehicle dependencies
- Vehicle data stored as snapshots (immutable)
- Soft references via linking table

### 2. Multi-Company Support ✅
- All transactional data scoped to `company_id`
- `SetPermissionsTeam` middleware ensures context
- `UserCompanyMembership` for multi-company users

### 3. Event-Driven Architecture ✅
- All critical operations emit domain events
- Events enable audit trail and fraud detection
- Foundation for future event sourcing

### 4. Fiscal Compliance ✅
- SHA-256 hash chains for invoices/credit notes
- Immutability triggers for posted documents
- Sequential numbering per document type

### 5. Stock Reservation System ✅
- Pessimistic locking for concurrency
- Automatic reservation on order confirmation
- Release on delivery or cancellation

## Migration Guide

### Adding a New Module

1. Create module structure:
```bash
mkdir -p app/Modules/NewModule/{Domain,Application,Infrastructure,Presentation}
```

2. Create ServiceProvider:
```php
namespace App\Modules\NewModule\Providers;

class NewModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
```

3. Register in `bootstrap/providers.php`:
```php
return [
    // ...
    App\Modules\NewModule\Providers\NewModuleServiceProvider::class,
];
```

4. Follow module structure conventions
5. Add tests FIRST (TDD approach)
6. Document public interfaces in `Shared/Contracts/`

## References

- See `CLAUDE.md` for coding standards
- See `docs/architecture/events.md` for event patterns
- See `docs/api/testing.md` for testing conventions
- See `docs/architecture/multi-tenancy.md` for multi-company patterns
