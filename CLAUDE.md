# AutoERP - Master Architecture Document

> **Claude Code: Read this entire document before starting any task.**
> This is the single source of truth for the AutoERP project.

---

## Agent Operational Protocol

**CRITICAL: Follow these rules for every task. Violations will cause system failures.**

### 1. No Placeholder Code
Never leave comments like `// TODO: implement logic` or `// Add validation here`. 
Write the complete implementation or explicitly fail the task and explain why.

### 2. Test-First Development (TDD)
For every feature:
1. Write the test FIRST (PHPUnit for backend, Vitest for frontend)
2. Run the test — it MUST fail (red)
3. Write the minimum implementation to pass
4. Run the test — it MUST pass (green)
5. Refactor if needed
6. Run verification again

### 3. Strict Typing — No Exceptions
- **PHP:** No `mixed` type unless interfacing with external untyped libraries. Use DTOs.
- **TypeScript:** No `any` type. Ever. Use `unknown` + type guards if type is truly unknown.
- **JSONB columns:** Must have a corresponding PHP DTO. Never access via `$model->payload['key']`.

### 4. One Task at a Time
Do not modify files outside the scope of the current TASKS.md item.
If you discover a needed change in another module, note it and continue with current task.

### 5. Verification is Law
If the verification command fails, do NOT mark the task complete.
Debug immediately. The task is not done until verification passes.

### 6. Module Boundaries are Sacred
Cross-module communication ONLY via:
- Interfaces in `Shared/Contracts/`
- Events (for async communication)
- The module's public Service class

**FORBIDDEN:** Importing `app/Modules/Inventory/Models/Stock` directly into `app/Modules/Sales/`.

### 7. Types Flow from Backend
Never manually edit TypeScript interfaces for Domain Entities.
Run `php artisan typescript:transform` after modifying PHP DTOs.
The generated types in `packages/shared/types/` are the source of truth.

### 8. Events are Immutable Forever
Once an Event class exists and has been used (even in tests):
- Never rename it
- Never change its payload structure
- Never delete it

If requirements change, create a new version: `InvoicePostedV2`, `PaymentRecordedV2`.

### 9. Enums for All Status/Type Columns
Never use magic strings. Every status, type, or code column must reference a PHP Enum.
```php
// WRONG
$payment->status = 'completed';

// RIGHT
$payment->status = PaymentStatus::Completed;
```

### 10. Pre-Flight Before Commit
Run `./scripts/preflight.sh` before considering any task complete:
```bash
./scripts/preflight.sh
# Runs: PHPStan, Pint, PHPUnit, TypeScript check, ESLint
```

### 11. No Hardcoded Strings in Frontend
**ALL user-facing text in the frontend MUST use translation keys. Never hardcode text.**
```tsx
// WRONG - Hardcoded text
<Button>Save</Button>
<h1>Chart of Accounts</h1>
<p>No data found</p>

// RIGHT - Translation keys
const { t } = useTranslation();
<Button>{t('common.save')}</Button>
<h1>{t('finance.chartOfAccounts.title')}</h1>
<p>{t('common.noData')}</p>
```

### 12. Module Routes MUST Follow Middleware Pattern
**CRITICAL: All module route files MUST use the exact same middleware pattern as Identity module.**

When creating or modifying route files in `app/Modules/*/routes.php`:

```php
// CORRECT - Use this pattern exactly
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Your routes here
});

// WRONG - Missing 'api' middleware
Route::prefix('api/v1')->middleware(['auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // This will cause 401 Unauthorized errors
});

// WRONG - Missing SetPermissionsTeam
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    // This will cause permission/team context issues
});
```

**Why this matters:**
- The `'api'` middleware is required for proper API middleware stack initialization
- The `SetPermissionsTeam` middleware sets up the user's permissions and company context
- Missing either will cause 401 Unauthorized or permission errors

**Before completing any module with routes:**
1. Compare your routes.php with `app/Modules/Identity/routes.php`
2. Ensure exact middleware pattern match
3. Run `php artisan route:clear` after changes
4. Test the endpoint with actual authentication

### 13. Constructor Injection is the ONLY Acceptable DI Pattern
**CRITICAL: All dependencies MUST be injected via constructor. NEVER use `app()` helper in controllers or services.**

**✅ CORRECT:**
```php
class InvoiceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function confirm(string $id): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();
        $result = $this->taxCalculationService->calculate($document);
        // ...
    }
}
```

**❌ WRONG:**
```php
class InvoiceController extends Controller
{
    public function confirm(string $id): JsonResponse
    {
        $companyId = app(CompanyContext::class)->getCompanyId(); // ❌ NO!
        // ...
    }
}
```

**Why:**
- Constructor signature documents all dependencies
- Easy to test - can mock dependencies
- Type-safe with IDE support
- Laravel auto-resolves via service container

**When adding dependencies:**
- Add to constructor with `private readonly`
- Never use `app()` to resolve services
- Update tests if controller instantiated directly (rare)

**See:** [docs/conventions/07-DEPENDENCY-INJECTION.md](docs/conventions/07-DEPENDENCY-INJECTION.md) for complete guide with examples, common mistakes, and pre-commit checklist.

### 14. Frontend API Response Handling Pattern
**CRITICAL: This is the #1 recurring mistake when adding new features. Follow this pattern exactly.**

#### Understanding the Response Flow

**Backend Response Structure (Laravel):**
```php
// All controllers return this format:
return response()->json([
    'data' => $yourData,  // ← Your actual data
]);
```

**Frontend API Helpers (apps/web/src/lib/api.ts):**
```typescript
// These helpers AUTOMATICALLY unwrap the response
export async function apiGet<T>(url: string): Promise<T> {
  const response = await api.get<ApiResponse<T>>(url)
  return response.data.data  // ← Already unwrapped here!
}

export async function apiPost<T>(url: string, data?: unknown): Promise<T> {
  const response = await api.post<ApiResponse<T>>(url, data)
  return response.data.data  // ← Already unwrapped here!
}

// Same for apiPut, apiPatch, apiDelete
```

#### The Correct Pattern for Feature API Clients

**✅ CORRECT - Direct return (data is already unwrapped):**
```typescript
// apps/web/src/features/yourFeature/api/yourApi.ts

export interface YourDataResponse {
  id: number
  name: string
  // ... your fields
}

// Single item endpoint
export async function fetchItem(id: number): Promise<YourDataResponse> {
  return apiGet<YourDataResponse>(`/items/${id}`)
  // ✅ apiGet already returns the unwrapped data
}

// List endpoint
export async function fetchItems(): Promise<YourDataResponse[]> {
  return apiGet<YourDataResponse[]>('/items')
  // ✅ apiGet already returns the unwrapped array
}

// Create endpoint
export async function createItem(data: CreateInput): Promise<YourDataResponse> {
  return apiPost<YourDataResponse>('/items', data)
  // ✅ apiPost already returns the unwrapped data
}
```

**❌ WRONG - Trying to unwrap again (causes undefined errors):**
```typescript
// DO NOT DO THIS!

export interface ItemsResponse {
  data: YourDataResponse[]  // ❌ Don't define a wrapper type
}

export async function fetchItems(): Promise<YourDataResponse[]> {
  const response = await apiGet<ItemsResponse>('/items')
  return response.data  // ❌ response is already the array, .data is undefined!
}

// DO NOT DO THIS!
export async function fetchItem(id: number): Promise<YourDataResponse> {
  const response = await apiGet<{ data: YourDataResponse }>(`/items/${id}`)
  return response.data  // ❌ response is already the object, .data is undefined!
}
```

#### Special Cases

**Paginated Responses (using cursor pagination):**
```typescript
// Backend returns:
// { data: [...], meta: {...}, links: {...} }

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    per_page: number
    has_more: boolean
  }
  links: {
    next: string | null
    prev: string | null
  }
}

export async function fetchPaginated(): Promise<PaginatedResponse<YourDataResponse>> {
  // ✅ apiGet unwraps outer { data: ... } but keeps the pagination structure
  return apiGet<PaginatedResponse<YourDataResponse>>('/items')
}
```

**Empty Responses (DELETE requests):**
```typescript
export async function deleteItem(id: number): Promise<void> {
  return apiDelete(`/items/${id}`)
  // ✅ No type parameter needed for void responses
}
```

#### Checklist When Creating New Feature API Clients

Before writing any fetch function:
1. ✅ Check `apps/web/src/lib/api.ts` - the helpers unwrap `response.data.data`
2. ✅ Your function should return `apiGet<YourType>()` directly
3. ✅ Do NOT create wrapper interfaces like `{ data: YourType }`
4. ✅ Do NOT access `.data` on the response from apiGet/apiPost/apiPut/apiDelete
5. ✅ The type parameter to `apiGet<T>` should be your actual data type, not a wrapper

#### Quick Reference

```typescript
// ✅ ALWAYS DO THIS:
return apiGet<Item>('/items/1')           // Single item
return apiGet<Item[]>('/items')           // Array of items
return apiPost<Item>('/items', data)      // Create
return apiPut<Item>('/items/1', data)     // Update (full)
return apiPatch<Item>('/items/1', data)   // Update (partial)
return apiDelete('/items/1')              // Delete

// ❌ NEVER DO THIS:
const response = await apiGet<{data: Item}>('/items/1')
return response.data  // ❌ Causes undefined errors!
```

---

## Executive Summary

AutoERP is a compliance-ready, tamper-proof ERP system for automotive service businesses (mechanics, body shops, car glass specialists, quick service centers) with planned expansion to auto parts retailers, wholesalers, and eventually other verticals.

**Key Differentiators:**
- Event-sourced from day one (not bolted on later)
- Two-tier hash chain for compliance AND fraud detection
- Multi-country compliance architecture (France, Tunisia, UK, Italy, North Africa)
- Universal payment method system supporting all payment types
- AI-agent ready for future automation
- Multi-tenant with schema-based isolation

**Target Markets (Priority Order):**
1. Automotive service businesses (no NF525 required for B2B invoicing)
2. Auto parts retailers (requires NF525 for cash register)
3. Generic retail/service (future: Boss ERP spin-off)

---

## Tech Stack (FINAL - DO NOT CHANGE)

| Layer | Technology | Version | Notes |
|-------|------------|---------|-------|
| **Backend** | Laravel | 12.x | PHP 8.3+ strict types |
| **Database** | PostgreSQL | 16+ | Schema-based multi-tenancy |
| **Cache/Queue** | Redis | 7+ | Laravel Horizon for queue management |
| **Search** | Meilisearch | Latest | Parts/catalog fast search |
| **Frontend Web** | React + Vite | 18+ | TypeScript strict mode |
| **State Management** | TanStack Query | 5+ | Server state |
| **Client State** | Zustand | 4+ | Minimal client state only |
| **Styling** | Tailwind CSS | 4+ | Custom design system |
| **Mobile** | React Native + Expo | Latest | TypeScript |
| **Desktop POS** | Tauri 2 | Latest | Offline-capable where legal |
| **Time-series** | TimescaleDB | Latest | PostgreSQL extension for audit logs |

---

## Architecture Principles

### 1. Hexagonal Architecture (Ports & Adapters)

```
┌─────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER                        │
│  Commands, Queries, DTOs, Application Services              │
├─────────────────────────────────────────────────────────────┤
│                      DOMAIN LAYER                           │
│  Entities, Value Objects, Domain Events, Domain Services    │
│  Aggregates, Repositories (interfaces only)                 │
├─────────────────────────────────────────────────────────────┤
│                   INFRASTRUCTURE LAYER                      │
│  Eloquent Repositories, External APIs, File Storage         │
│  Queue Workers, Event Store Implementation                  │
└─────────────────────────────────────────────────────────────┘
```

**Rules:**
- Domain layer has ZERO dependencies on infrastructure
- All external dependencies are injected via interfaces
- Business logic lives in Domain Services, not Controllers
- Controllers are thin: validate → dispatch → respond

### 2. Event Sourcing with Hash Chains

**Two-Tier Approach:**

**Tier 1: Fiscal Chain (Compliance)**
- Posted invoices, credit notes, payments, fiscal closings
- SHA-256 hash chain with previous_hash reference
- Required for NF525/ZATCA compliance
- Separate chains per document type per tenant

**Tier 2: Audit Log (Fraud Detection)**
- ALL domain events (quotes, orders, drafts, stock, user actions)
- Individual event hashes (not chained to fiscal documents)
- Enables anomaly detection and operational audit
- Stored in TimescaleDB for time-series queries

```php
// CRITICAL: Event-first pattern
DB::transaction(function () use ($invoice) {
    // 1. Create the event with hash chain
    $event = new InvoicePosted($invoice);
    $event->hash = $this->calculateHash($event, $previousHash);
    $this->eventStore->append($event);
    
    // 2. Update read model state
    $invoice->update(['status' => 'posted']);
    
    // 3. Create GL entries
    $this->ledger->record($invoice);
});
```

### 3. Multi-Tenancy (Schema-Based)

Each tenant gets their own PostgreSQL schema:
- `tenant_acme` → All ACME's tables
- `tenant_garage42` → All Garage42's tables
- `public` → Shared lookup data, tenant registry

**Benefits:**
- Data isolation at database level
- Easy backup/restore per tenant
- Migration path to dedicated databases for large clients

### 4. CQRS Light

- **Commands** modify state through domain layer
- **Queries** read directly from optimized read models
- No separate read database (yet) - use PostgreSQL views and materialized views
- Journal entries serve as the financial read model

---

## Module Structure

```
app/
├── Modules/
│   ├── Identity/           # Users, roles, permissions, devices
│   ├── Tenant/             # Multi-tenancy, subscriptions
│   ├── Catalog/            # Products, categories, pricing
│   ├── Vehicle/            # Vehicles, VIN decoding, history
│   ├── Partner/            # Customers, suppliers, contacts
│   ├── Workshop/           # Work orders, labor, scheduling
│   ├── Sales/              # Documents (quotes, orders, invoices)
│   ├── Inventory/          # Stock, locations, movements
│   ├── Treasury/           # Payments, instruments, repositories
│   ├── Accounting/         # Journal entries, GL, reconciliation
│   ├── Communication/      # SMS, email, notifications
│   └── Media/              # Files, images, documents
├── Shared/
│   ├── Domain/             # Base classes, interfaces
│   ├── Infrastructure/     # Common infrastructure
│   └── Application/        # Shared DTOs, commands
```

Each module follows:
```
Module/
├── Domain/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Events/
│   ├── Services/
│   └── Repositories/       # Interfaces only
├── Application/
│   ├── Commands/
│   ├── Queries/
│   ├── DTOs/
│   └── Services/
├── Infrastructure/
│   ├── Repositories/       # Eloquent implementations
│   ├── Providers/
│   └── External/           # API clients
└── Presentation/
    ├── Controllers/
    ├── Requests/
    └── Resources/
```

---

## Database Design

### Core Principle: Unified Documents + Separate Ledger

```
┌─────────────────────────────────────────────────────────────┐
│                    OPERATIONAL LAYER                        │
├─────────────────────────────────────────────────────────────┤
│  documents (unified header)                                 │
│  document_lines (unified lines)                             │
│  invoice_metadata (compliance-critical fields)              │
│  delivery_metadata (DDT, shipping)                          │
│  quote_metadata (validity, conversion)                      │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    ACCOUNTING LAYER                         │
├─────────────────────────────────────────────────────────────┤
│  journal_entries (header)                                   │
│  journal_lines (debits/credits)                             │
│  accounts (chart of accounts)                               │
│  fiscal_years / periods                                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    TREASURY LAYER                           │
├─────────────────────────────────────────────────────────────┤
│  payment_methods (universal configuration)                  │
│  payment_repositories (safes, registers, bank accounts)     │
│  payment_instruments (physical: checks, vouchers)           │
│  payments (actual payment records)                          │
│  payment_allocations (invoice ↔ payment mapping)            │
└─────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **Single `documents` table** for quotes, orders, invoices, credit notes, delivery notes
   - Distinguished by `type` enum
   - Shared columns for common fields
   - JSONB `payload` for flexible/optional data
   - Small subtype tables for compliance-critical fields

2. **Separate `journal_entries`** for accounting truth
   - Created when documents are posted
   - Never edit directly - only reversals
   - Source of truth for financial reports

3. **Universal payment methods** configurable via switches
   - `is_physical`, `has_maturity`, `requires_third_party`
   - `is_push`, `has_deducted_fees`, `is_restricted`
   - Country presets for Tunisia, France, Gulf, Africa

---

## Transaction Boundaries

### Critical Operations Requiring Pessimistic Locking

| Operation | Lock Target | Why |
|-----------|-------------|-----|
| Stock adjustment | `stock_levels` row | Prevent overselling |
| Invoice posting | `documents` + `sequences` | Sequential numbering |
| Payment recording | `payment_instruments` + `invoices` | Accurate balances |
| Instrument custody transfer | `payment_instruments` | Track location |
| Period closing | `fiscal_periods` | Prevent backdating |

### Atomic Transaction Pattern

```php
// ALWAYS use this pattern for financial operations
DB::transaction(function () {
    // 1. Acquire locks
    $stock = StockLevel::where('product_id', $productId)
        ->where('location_id', $locationId)
        ->lockForUpdate()
        ->first();
    
    // 2. Validate
    if ($stock->quantity < $requestedQty) {
        throw new InsufficientStockException();
    }
    
    // 3. Create event (with hash if fiscal)
    $event = new StockReserved(...);
    $this->eventStore->append($event);
    
    // 4. Update state
    $stock->decrement('quantity', $requestedQty);
    $stock->increment('reserved', $requestedQty);
});
```

### Frontend Implications

| Operation Type | UI Pattern | Why |
|----------------|------------|-----|
| Customer update | Optimistic | Low risk, can retry |
| Quote save | Optimistic | Draft, not fiscal |
| Invoice post | **Pessimistic** | Fiscal, irreversible |
| Payment record | **Pessimistic** | Money movement |
| Stock adjustment | **Pessimistic** | Inventory accuracy |

**Pessimistic Pattern (React Query):**
```typescript
const postInvoice = useMutation({
  mutationFn: (id) => api.post(`/invoices/${id}/post`),
  onMutate: () => setIsPosting(true),  // Show spinner
  onSuccess: () => {
    queryClient.invalidateQueries(['invoice', id]);
    toast.success('Invoice posted');
  },
  onError: (error) => {
    toast.error(error.message);  // Show server error
  },
  onSettled: () => setIsPosting(false),
});
```

---

## Compliance Architecture

### Hash Chain Implementation

```php
class FiscalHashService
{
    public function calculateHash(FiscalDocument $doc, ?string $previousHash): string
    {
        $data = $this->serializeForHashing($doc);
        $payload = $previousHash . '|' . $data;
        return hash('sha256', $payload);
    }
    
    public function verifyChain(string $tenantId, string $documentType): bool
    {
        $documents = FiscalDocument::where('tenant_id', $tenantId)
            ->where('type', $documentType)
            ->where('status', 'posted')
            ->orderBy('chain_sequence')
            ->get();
        
        $previousHash = null;
        foreach ($documents as $doc) {
            $expected = $this->calculateHash($doc, $previousHash);
            if ($expected !== $doc->hash) {
                return false;  // Chain broken!
            }
            $previousHash = $doc->hash;
        }
        return true;
    }
}
```

### NF525 Readiness Checklist

When we add POS functionality:
- [ ] Z-reports (daily closings with grand totals)
- [ ] Perpetual totals (cumulative across periods)
- [ ] Receipt chaining (separate from invoice chain)
- [ ] Technical event log (JET)
- [ ] Duplicate/reprint tracking
- [ ] Digital signature (RSA 2048 or ECDSA 256)

### E-Invoicing (Factur-X)

For French B2B invoices:
- Generate Factur-X XML (EN 16931 compliant)
- Embed in PDF/A-3
- Submit to PDP (Plateforme de Dématérialisation Partenaire)
- Track submission status and responses

---

## API Design

### RESTful Conventions

```
GET    /api/v1/documents              # List with filters
GET    /api/v1/documents/{id}         # Single document
POST   /api/v1/documents              # Create draft
PATCH  /api/v1/documents/{id}         # Update draft
DELETE /api/v1/documents/{id}         # Delete draft only

POST   /api/v1/documents/{id}/confirm # Transition to confirmed
POST   /api/v1/documents/{id}/post    # Post (creates GL entries)
POST   /api/v1/documents/{id}/cancel  # Cancel with reversal
```

### Response Format

```json
{
  "data": { ... },
  "meta": {
    "timestamp": "2025-11-29T12:00:00Z",
    "request_id": "uuid"
  }
}
```

### Error Format

```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "Not enough stock for product X",
    "details": {
      "product_id": "uuid",
      "requested": 10,
      "available": 5
    }
  },
  "meta": { ... }
}
```

---

## Testing Strategy

### Test Pyramid

1. **Unit Tests** (70%)
   - Domain logic, value objects, services
   - No database, no HTTP
   - Fast, isolated

2. **Integration Tests** (20%)
   - Repository implementations
   - Database transactions
   - Event store operations

3. **E2E Tests** (10%)
   - Critical user journeys
   - Playwright for web
   - Focus on happy paths + key error cases

### Critical Test Scenarios

- [ ] Invoice posting creates correct GL entries
- [ ] Hash chain remains valid after 1000 documents
- [ ] Concurrent stock adjustments don't oversell
- [ ] Payment allocation matches invoice totals
- [ ] Document conversion (quote → order → invoice) preserves data

---

## Quality Gates

Before ANY commit:

```bash
# Backend
composer test              # PHPUnit
./vendor/bin/phpstan      # Static analysis (level 8)
./vendor/bin/pint         # Code style

# Frontend
pnpm test                 # Vitest
pnpm lint                 # ESLint
pnpm typecheck           # TypeScript
```

CI Pipeline must pass:
- All tests green
- PHPStan level 8 (no errors)
- TypeScript strict (no errors)
- Code coverage > 80% for domain layer

---

## Getting Started

### First Time Setup

```bash
# Clone and install
git clone <repo>
cd autoerp
pnpm install              # Root dependencies
cd apps/api && composer install
cd ../web && pnpm install

# Start services
docker compose up -d

# Setup database
cd apps/api
php artisan migrate
php artisan db:seed

# Start dev servers
pnpm dev                  # From root - starts all
```

### Claude Code Workflow

1. Read this CLAUDE.md completely
2. Read TASKS.md for current progress
3. Run `git status` to see current state
4. Run verification commands before starting
5. Complete ONE task at a time
6. Run verification after each task
7. Commit with conventional commit message
8. Update TASKS.md status

---

## Reference Documents

### Coding Conventions (REQUIRED READING)
| Document | Purpose | When to Read |
|----------|---------|--------------|
| **[docs/conventions/README.md](docs/conventions/README.md)** | **Conventions index** | **Start here for all development** |
| [docs/conventions/01-API-RESPONSES.md](docs/conventions/01-API-RESPONSES.md) | API response patterns | Creating/modifying controllers |
| [docs/conventions/02-NAVIGATION-ROUTING.md](docs/conventions/02-NAVIGATION-ROUTING.md) | Adding pages to dashboard | Adding new features |
| [docs/conventions/03-AUTHORIZATION.md](docs/conventions/03-AUTHORIZATION.md) | Permission system | Protecting routes/endpoints |
| [docs/conventions/04-FRONTEND-TYPES.md](docs/conventions/04-FRONTEND-TYPES.md) | Type generation from DTOs | Working with types |
| [docs/conventions/05-REACT-QUERY.md](docs/conventions/05-REACT-QUERY.md) | Data fetching patterns | Creating queries/mutations |
| [docs/conventions/06-FORMS.md](docs/conventions/06-FORMS.md) | Form validation | Building forms |
| [docs/conventions/07-DEPENDENCY-INJECTION.md](docs/conventions/07-DEPENDENCY-INJECTION.md) | Constructor injection (ONLY pattern) | Creating controllers/services |

### Architecture & Domain Documentation
| Document | Purpose |
|----------|---------|
| `TASKS.md` | Current sprint tasks and progress |
| `docs/DATABASE.md` | Complete schema documentation |
| `docs/TREASURY.md` | Payment method configuration |
| `docs/IMPORTS.md` | Data import patterns |
| `docs/FRONTEND.md` | React patterns and components |
| `docs/DESIGN-SYSTEM.md` | Visual design tokens |

---

## Internationalization (i18n) Conventions

### Supported Languages
- **English (en)** - Default fallback
- **French (fr)** - Primary target market
- **Arabic (ar)** - RTL support ready (future)

### Frontend i18n (React)

**Library:** `react-i18next` with `i18next-browser-languagedetector`

**Translation Files Location:**
```
apps/web/src/locales/
├── en/
│   ├── common.json      # Shared UI strings
│   ├── auth.json        # Authentication
│   ├── sales.json       # Partners, documents, quotes
│   ├── inventory.json   # Products, stock
│   ├── treasury.json    # Payments, instruments
│   └── validation.json  # Form validation
├── fr/
│   └── (same structure)
└── ar/
    └── (same structure)
```

**Usage Pattern:**
```typescript
import { useTranslation } from 'react-i18next'

function Component() {
  const { t } = useTranslation(['common', 'sales'])

  return (
    <div>
      <h1>{t('common:appName')}</h1>
      <label>{t('sales:partners.name')}</label>
      <span>{t('validation:required')}</span>
    </div>
  )
}
```

**RTL-Ready CSS Rules:**
- Use logical Tailwind properties:
  - `ms-*` / `me-*` instead of `ml-*` / `mr-*`
  - `ps-*` / `pe-*` instead of `pl-*` / `pr-*`
  - `start-*` / `end-*` instead of `left-*` / `right-*`
  - `text-start` / `text-end` instead of `text-left` / `text-right`
  - `border-s-*` / `border-e-*` instead of `border-l-*` / `border-r-*`
  - `rounded-s-*` / `rounded-e-*` instead of `rounded-l-*` / `rounded-r-*`

**Language Switching:**
```typescript
import { languages } from '../lib/i18n'

// Change language
i18n.changeLanguage('fr')

// Get current language info
const currentLang = languages.find(l => l.code === i18n.language)
```

### Backend i18n (Laravel)

**Translation Files Location:**
```
apps/api/lang/
├── en/
│   ├── validation.php   # Form validation messages
│   ├── auth.php         # Authentication messages
│   └── messages.php     # API responses
└── fr/
    └── (same structure)
```

**Locale Detection Middleware:**
- `SetLocale` middleware reads `Accept-Language` or `X-Language` headers
- Priority: `X-Language` header > `Accept-Language` header > default locale
- Response includes `Content-Language` header

**Usage in Controllers:**
```php
// Using translations
return response()->json([
    'message' => __('messages.created', ['resource' => 'Partner']),
]);

// Validation messages are automatically translated
$request->validate([
    'email' => 'required|email', // Error uses lang/fr/validation.php
]);
```

### Key Naming Convention

Follow this hierarchical structure for translation keys:
```
common.save
common.cancel
common.delete
common.edit
common.search
common.noData
common.loading
common.error

finance.chartOfAccounts.title
finance.chartOfAccounts.addAccount
finance.ledger.title
finance.reports.trialBalance
finance.reports.profitLoss

sales.invoices.title
sales.invoices.create
sales.quotes.title

inventory.products.title
inventory.stockLevels.title

settings.company.title
settings.subscription.title
```

### When Creating New Components

1. Identify all user-facing text
2. Add keys to `en/translation.json` first
3. Use `t('key')` in component
4. Add French translation to `fr/translation.json`
5. Add Arabic translation to `ar/translation.json` (can be placeholder initially)

### Adding New Translations

1. **Add key to English first** (`en/*.json` or `en/*.php`)
2. **Add French translation** (`fr/*.json` or `fr/*.php`)
3. **Use descriptive keys:** `partners.form.emailLabel` not `email`
4. **Group by feature:** Keep related strings in the same namespace

### Pre-Commit i18n Checklist

Before committing frontend code, verify:
- [ ] No hardcoded user-facing text in TSX files
- [ ] All new keys added to en/translation.json
- [ ] French translations added to fr/translation.json

### Testing i18n

- Switch to Arabic to test RTL layout (even without translations)
- Verify all user-facing strings use translation keys
- Check that dynamic content (dates, numbers) is properly localized

---

*Document Version: 2.1*
*Last Updated: November 2025*
*Stack: Laravel 12 + React + PostgreSQL*
