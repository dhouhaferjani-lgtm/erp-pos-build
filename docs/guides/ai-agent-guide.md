# AI Agent Guide

> Essential guidelines for AI agents working on the AutoERP codebase.

---

## Quick Reference

**Before starting any task:**
1. Read `CLAUDE.md` in the project root (master rules)
2. Check this document for patterns and conventions
3. Review relevant architecture docs based on your task

---

## Critical Rules (From CLAUDE.md)

### 1. No Placeholder Code
Never leave comments like `// TODO: implement` or `// Add validation here`.
Write complete implementations or explicitly explain why the task cannot be completed.

### 2. Strict Typing
- **PHP**: No `mixed` type. Use DTOs for complex data.
- **TypeScript**: No `any` type. Use `unknown` + type guards.

### 3. Module Boundaries
Cross-module communication ONLY via:
- Interfaces in `Shared/Contracts/`
- Events (for async communication)
- The module's public Service class

**FORBIDDEN**: Direct imports from another module's internal classes.

### 4. Events are Immutable
Once an Event class exists and has been used:
- Never rename it
- Never change its payload structure
- Never delete it

If requirements change, create a new version: `InvoicePostedV2`.

### 5. No Hardcoded Strings in Frontend
ALL user-facing text must use translation keys:
```tsx
// WRONG
<Button>Save</Button>

// RIGHT
const { t } = useTranslation();
<Button>{t('common.save')}</Button>
```

### 6. Pre-Flight Before Commit
```bash
./scripts/preflight.sh
# Runs: PHPStan, Pint, PHPUnit, TypeScript check, ESLint
```

---

## Project Structure

```
apps/erp/
├── apps/
│   ├── api/                    # Laravel 12 backend
│   │   ├── app/
│   │   │   ├── Modules/        # 28 domain modules
│   │   │   ├── Shared/         # Shared infrastructure
│   │   │   └── Http/           # Legacy controllers
│   │   ├── database/migrations/
│   │   └── routes/
│   │
│   ├── web/                    # React 19 frontend
│   │   ├── src/
│   │   │   ├── features/       # 22 feature modules
│   │   │   ├── components/     # Shared UI components
│   │   │   ├── hooks/          # Global hooks
│   │   │   ├── stores/         # Zustand stores
│   │   │   ├── lib/            # Utilities (api.ts, i18n.ts)
│   │   │   ├── locales/        # i18n translations (en/, fr/)
│   │   │   └── routes/         # Routing configuration
│   │   └── e2e/                # Playwright tests
│   │
│   └── mobile/                 # React Native (in development)
│
├── packages/
│   └── shared/types/           # Generated TypeScript types
│
└── docs/
    └── new-docs/               # This documentation
```

---

## Backend Patterns

### Module Structure
Each module follows hexagonal architecture:

```
Module/
├── Domain/
│   ├── Entities/           # Eloquent models (NOT just data containers)
│   ├── ValueObjects/       # Immutable value types
│   ├── Events/             # Domain events
│   ├── Services/           # Domain services (business rules)
│   └── Enums/              # PHP 8.1 backed enums
├── Application/
│   ├── DTOs/               # Spatie Laravel Data
│   └── Services/           # Application services (use cases)
├── Infrastructure/
│   └── Providers/          # Service providers
└── Presentation/
    ├── Controllers/        # HTTP handlers
    ├── Requests/           # Form request validation
    ├── Resources/          # API resources
    └── routes.php          # Module routes
```

### Creating a New Endpoint

1. **Define Route** in `Module/Presentation/routes.php`:
```php
Route::get('/resource', [ResourceController::class, 'index'])
    ->middleware('can:resource.view')
    ->name('resource.index');
```

2. **Create Form Request** for validation:
```php
class CreateResourceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

3. **Create Controller** (keep thin):
```php
class ResourceController extends Controller
{
    public function store(CreateResourceRequest $request): JsonResponse
    {
        $dto = CreateResourceData::from($request->validated());
        $result = $this->service->create($dto);
        return response()->json(['data' => ResourceData::from($result)], 201);
    }
}
```

4. **Create DTO** with Spatie Laravel Data:
```php
class CreateResourceData extends Data
{
    public function __construct(
        public string $name,
        public float $amount,
    ) {}
}
```

5. **Implement Service** with business logic:
```php
class ResourceService
{
    public function create(CreateResourceData $data): Resource
    {
        return DB::transaction(function () use ($data) {
            // Business logic here
            $resource = Resource::create([...]);
            event(new ResourceCreated($resource));
            return $resource;
        });
    }
}
```

### Pessimistic Locking Pattern
Use for all financial/inventory operations:

```php
DB::transaction(function () use ($productId, $locationId, $quantity) {
    // 1. Acquire lock
    $stock = StockLevel::where('product_id', $productId)
        ->where('location_id', $locationId)
        ->lockForUpdate()
        ->first();

    // 2. Validate
    if ($stock->quantity < $quantity) {
        throw new InsufficientStockException();
    }

    // 3. Modify
    $stock->decrement('quantity', $quantity);

    // 4. Create movement record
    StockMovement::create([...]);
});
```

### Hash Chain Pattern
For fiscal documents (invoices, credit notes):

```php
public function post(Document $document): void
{
    $previousHash = $this->getLastHash($document->company_id, $document->type);

    $document->hash = $this->calculateHash($document, $previousHash);
    $document->previous_hash = $previousHash;
    $document->chain_sequence = $this->getNextSequence();
    $document->status = DocumentStatus::Posted;

    $document->save();

    // Create GL entries
    $this->generalLedgerService->createEntry($document);
}
```

---

## Frontend Patterns

### Feature Structure
```
features/
├── feature-name/
│   ├── api/                # API calls (TanStack Query)
│   │   ├── index.ts        # Exports
│   │   └── queries.ts      # useQuery/useMutation hooks
│   ├── components/         # Feature-specific components
│   ├── hooks/              # Feature-specific hooks
│   ├── pages/              # Page components
│   └── types.ts            # TypeScript types
```

### API Layer Pattern
```typescript
// features/invoices/api/queries.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export const invoiceKeys = {
  all: ['invoices'] as const,
  lists: () => [...invoiceKeys.all, 'list'] as const,
  list: (filters: Filters) => [...invoiceKeys.lists(), filters] as const,
  details: () => [...invoiceKeys.all, 'detail'] as const,
  detail: (id: string) => [...invoiceKeys.details(), id] as const,
};

export function useInvoices(filters: Filters) {
  return useQuery({
    queryKey: invoiceKeys.list(filters),
    queryFn: () => api.get('/invoices', { params: filters }),
  });
}

export function usePostInvoice() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => api.post(`/invoices/${id}/post`),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: invoiceKeys.lists() });
    },
  });
}
```

### i18n Pattern
```typescript
// Always use translation keys
import { useTranslation } from 'react-i18next';

function MyComponent() {
  const { t } = useTranslation(['common', 'invoices']);

  return (
    <div>
      <h1>{t('invoices:title')}</h1>
      <Button>{t('common:save')}</Button>
      <p>{t('common:noData')}</p>
    </div>
  );
}
```

Translation files are in `apps/web/src/locales/{en,fr}/`:
- `common.json` - Shared strings (save, cancel, delete, etc.)
- `auth.json` - Authentication
- `sales.json` - Partners, documents
- `inventory.json` - Products, stock
- `treasury.json` - Payments
- `finance.json` - Accounting

### Permission Guards
```typescript
// In routes/index.tsx
{
  path: 'invoices',
  element: (
    <RequirePermission permission="invoices.view">
      <InvoiceListPage />
    </RequirePermission>
  ),
}

// In components
import { usePermissions } from '@/hooks/usePermissions';

function MyComponent() {
  const { can } = usePermissions();

  return (
    <>
      {can('invoices.create') && <CreateButton />}
    </>
  );
}
```

### RTL-Ready CSS (Tailwind)
Use logical properties for Arabic support:
```tsx
// WRONG
<div className="ml-4 pl-2 text-left border-l-2">

// RIGHT
<div className="ms-4 ps-2 text-start border-s-2">
```

Mapping:
- `ml-*` / `mr-*` → `ms-*` / `me-*`
- `pl-*` / `pr-*` → `ps-*` / `pe-*`
- `left-*` / `right-*` → `start-*` / `end-*`
- `text-left` / `text-right` → `text-start` / `text-end`

---

## Common Tasks

### Adding a New Field to an Entity

1. **Create Migration**:
```bash
cd apps/api
php artisan make:migration add_new_field_to_documents
```

2. **Update Entity** (`Domain/Entities/Document.php`):
```php
protected $fillable = [
    // ... existing
    'new_field',
];

protected $casts = [
    'new_field' => 'decimal:2',
];
```

3. **Update DTO** (`Application/DTOs/DocumentData.php`):
```php
public ?float $new_field;
```

4. **Generate TypeScript Types**:
```bash
php artisan typescript:transform
```

5. **Update Frontend** types if needed (check `packages/shared/types/`)

### Adding a New API Endpoint

1. Add route in `Module/Presentation/routes.php`
2. Create FormRequest if needed
3. Add controller method
4. Add/update DTOs
5. Update frontend API layer
6. Add translation keys if user-facing

### Adding a New Page

1. Create page component in `features/{feature}/pages/`
2. Add route in `routes/index.tsx`
3. Add translation keys in `locales/{en,fr}/{namespace}.json`
4. Add permission guard if needed

### Working with Documents

The `documents` table is unified for all document types. Key points:
- Type is determined by `DocumentType` enum
- Status transitions are controlled by domain logic
- Posted invoices/credit notes have fiscal hash chain
- Conversions (Quote → Order → Invoice) maintain chain via `source_document_id`

### Working with Payments

Universal payment system with configurable methods:
- Payment methods have "switches" defining behavior
- Physical instruments (checks) track custody in repositories
- Allocations link payments to invoices
- Smart payment handles tolerance and auto-allocation

### Working with Inventory

Stock operations require pessimistic locking:
- Always lock `stock_levels` row before modification
- Create `stock_movements` for audit trail
- Weighted average cost is recalculated on receipt

---

## Testing

### Backend (PHPUnit)
```bash
cd apps/api

# Run all tests
php artisan test

# Run specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific file
php artisan test tests/Feature/InvoiceTest.php
```

### Frontend (Vitest)
```bash
cd apps/web

# Run tests
pnpm test

# Watch mode
pnpm test:watch

# Coverage
pnpm test:coverage
```

### E2E (Playwright)
```bash
cd apps/web

# Run E2E tests
pnpm e2e

# UI mode
pnpm e2e:ui
```

---

## Debugging Tips

### Backend
- Check Laravel logs: `storage/logs/laravel.log`
- Use `dd()` or `dump()` for quick debugging
- Check Horizon dashboard for queue issues: `/horizon`

### Frontend
- React Query Devtools (enabled in dev)
- Check Network tab for API responses
- Console for TypeScript/runtime errors

### Database
- Use `php artisan tinker` for quick queries
- Check PostgreSQL logs for query issues
- Use `EXPLAIN ANALYZE` for slow queries

---

## Enums Reference

### Document Module
```php
DocumentType::Quote
DocumentType::SalesOrder
DocumentType::Invoice
DocumentType::CreditNote
DocumentType::DeliveryNote
DocumentType::PurchaseOrder

DocumentStatus::Draft
DocumentStatus::Confirmed
DocumentStatus::Posted
DocumentStatus::Cancelled
```

### Inventory Module
```php
MovementType::Receipt
MovementType::Issue
MovementType::Transfer
MovementType::Adjustment

CountingStatus::Draft
CountingStatus::Scheduled
CountingStatus::Count1InProgress
CountingStatus::Count1Completed
// ... through Count3
CountingStatus::PendingReview
CountingStatus::Finalized
CountingStatus::Cancelled
```

### Treasury Module
```php
PaymentType::Incoming
PaymentType::Outgoing

PaymentStatus::Pending
PaymentStatus::Recorded
PaymentStatus::PartiallyAllocated
PaymentStatus::Allocated
PaymentStatus::Reversed

InstrumentStatus::Received
InstrumentStatus::Used
InstrumentStatus::Deposited
InstrumentStatus::Cleared
InstrumentStatus::Bounced
```

### Accounting Module
```php
AccountType::Asset
AccountType::Liability
AccountType::Equity
AccountType::Revenue
AccountType::Expense

JournalEntryStatus::Draft
JournalEntryStatus::Posted
JournalEntryStatus::Reversed
```

---

## Security Considerations

1. **Input Validation**: Always use FormRequest classes
2. **Authorization**: Use middleware and policies
3. **SQL Injection**: Use Eloquent, never raw SQL with user input
4. **XSS**: React escapes by default, but avoid `dangerouslySetInnerHTML`
5. **CSRF**: Handled by Sanctum for SPA
6. **Rate Limiting**: Applied via middleware

---

## Performance Considerations

1. **Eager Loading**: Use `with()` to prevent N+1 queries
2. **Pagination**: Always paginate large lists
3. **Caching**: Use Redis for expensive computations
4. **Indexes**: Check existing indexes before adding queries
5. **Transactions**: Keep them as short as possible

---

## Related Documentation

- [Architecture Overview](../architecture/overview.md)
- [Backend Architecture](../architecture/backend.md)
- [Frontend Architecture](../architecture/frontend.md)
- [Database Schema](../architecture/database.md)
- [Module Reference](../modules/README.md)
- [API Reference](../api/README.md)
