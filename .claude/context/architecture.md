# Architecture Reference

## Hexagonal Architecture (Ports & Adapters)

```
APPLICATION LAYER  — Commands, Queries, DTOs, Application Services
DOMAIN LAYER       — Entities, Value Objects, Events, Domain Services, Repository interfaces
INFRASTRUCTURE     — Eloquent Repositories, External APIs, File Storage, Queue Workers
PRESENTATION       — Controllers, Requests, Resources
```

**Rules:**
- Domain layer has ZERO dependencies on infrastructure
- All external dependencies are injected via interfaces
- Business logic lives in Domain Services, not Controllers
- Controllers are thin: validate -> dispatch -> respond

## Module Directory Structure

```
app/Modules/{ModuleName}/
├── Domain/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Events/
│   ├── Services/
│   └── Repositories/       # Interfaces only
│   # Note: Models can live at Domain/ root (e.g., Domain/Payment.php)
│   # or in Domain/Entities/ — both patterns are used in the codebase.
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

## Cross-Module Communication

**Only allowed via:**
- Interfaces in `Shared/Contracts/`
- Events (for async communication)
- The module's public Service class

**FORBIDDEN:** Importing models/entities directly across modules.

## Multi-Tenancy (Schema-Based)

Each tenant gets a PostgreSQL schema (`tenant_acme`, `tenant_garage42`).
`public` schema holds shared lookup data and tenant registry.

## CQRS Light

- **Commands** modify state through domain layer
- **Queries** read directly from optimized read models (PostgreSQL views/materialized views)
- Journal entries serve as the financial read model

## Transaction Boundaries

### Operations Requiring Pessimistic Locking

| Operation | Lock Target | Why |
|-----------|-------------|-----|
| Stock adjustment | `stock_levels` row | Prevent overselling |
| Invoice posting | `documents` + `sequences` | Sequential numbering |
| Payment recording | `payment_instruments` + `invoices` | Accurate balances |
| Instrument custody transfer | `payment_instruments` | Track location |
| Period closing | `fiscal_periods` | Prevent backdating |

### Atomic Transaction Pattern

```php
DB::transaction(function () {
    // 1. Acquire locks (lockForUpdate)
    // 2. Validate business rules
    // 3. Create event (with hash if fiscal)
    // 4. Update state
});
```

### Frontend UI Patterns

| Operation | UI Pattern | Why |
|-----------|------------|-----|
| Customer/quote update | Optimistic | Low risk, can retry |
| Invoice post | **Pessimistic** (spinner + server confirm) | Fiscal, irreversible |
| Payment record | **Pessimistic** | Money movement |
| Stock adjustment | **Pessimistic** | Inventory accuracy |

## Database Design

**Single `documents` table** for quotes, orders, invoices, credit notes, delivery notes (distinguished by `type` enum). Subtype tables for compliance-critical fields.

**Separate `journal_entries`** for accounting truth (never edited directly, only reversals).

**Universal `payment_methods`** configurable via boolean switches (`is_physical`, `has_maturity`, `requires_third_party`, etc.) with country presets.

## API Design

```
GET    /api/v1/{resource}              # List with filters
GET    /api/v1/{resource}/{id}         # Single item
POST   /api/v1/{resource}              # Create
PATCH  /api/v1/{resource}/{id}         # Update
DELETE /api/v1/{resource}/{id}         # Delete (drafts only)
POST   /api/v1/{resource}/{id}/post    # State transitions
```

Response: `{ "data": {...}, "meta": {...} }`
Error: `{ "error": { "code": "...", "message": "...", "details": {...} } }`
