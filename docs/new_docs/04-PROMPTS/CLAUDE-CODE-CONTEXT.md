# Claude Code Context Document

**Purpose:** Provide this document at the start of any Claude Code session for consistent context.

---

## Project Overview

You are working on a multi-product ERP platform with two applications:
- **Otospex**: Automotive-focused ERP (mechanics, body shops, parts retailers)
- **IziPOS**: Generic retail/F&B ERP (para-pharmacy, coffee shops, restaurants)

Both products share a single codebase (~85% shared) with product-specific modules loaded based on the `APP_PRODUCT` environment variable.

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12 |
| Frontend | React (TypeScript) |
| Mobile | React Native |
| Desktop POS | Tauri 2 |
| Database | PostgreSQL 16+ — database-per-tenant via Stancl `PostgreSQLDatabaseManager` (T6 Phase 0b, 2026-05-28) |
| Testing | PHPUnit, Playwright |

---

## Architecture

### Hexagonal Architecture
```
App/Modules/{ModuleName}/
├── Domain/              # Entities, Value Objects, Repositories (interfaces)
├── Application/         # DTOs, Services, Events, Commands
├── Infrastructure/      # Repository implementations, external services
└── Presentation/        # Controllers, routes.php
```

### Multi-Tenancy + Multi-Company
```
Tenant (Organization) → owns → Company (Legal Entity)
```
- Tenants are isolated by **database-per-tenant** (Stancl `PostgreSQLDatabaseManager`). One `synerivia_central` DB holds the tenant directory + auth; each tenant gets a physical `tenant_<uuid>` DB. Stancl `DatabaseTenancyBootstrapper` swaps the default Laravel connection per request.
- A tenant can have multiple companies across different countries
- Company model: `App\Modules\Company\Domain\Company`

### Module System
- Modules are loaded dynamically based on `APP_PRODUCT`
- Core modules always load (Identity, Company, etc.)
- Product-specific modules only load for their product
- Configuration: `config/products.php`

---

## Critical Conventions

### Naming
- Credit notes are `CreditNote`, NOT `CreditMemo`
- Company model is at `App\Modules\Company\Domain\Company`
- Controllers go in `Presentation/Controllers/`
- Routes go in `Presentation/routes.php`

### Code Patterns
- **Repositories**: Always use interfaces in Domain, implementations in Infrastructure
- **DTOs**: Use for all data transfer between layers
- **Events**: All significant actions emit domain events
- **Hash Chains**: Financial transactions must maintain cryptographic hash chains

### Database
- Use migrations for all schema changes
- **Database-per-tenant** (Stancl). Central migrations land in `apps/api/database/migrations/`; per-tenant migrations land in `apps/api/database/migrations/tenant/` and run via `tenants:migrate` or the rolling `tenant:migrate-rolling` runner.
- Many tenant-DB tables still carry a `tenant_id` column for defense-in-depth scoping (kept from the pre-flip row-level era), but it is not an enforced FK across databases.
- Soft deletes where appropriate
- Use UUIDs for external-facing IDs, integers for internal

### Frontend
- Use TypeScript strictly
- React Query for server state
- Zustand for client state
- Tailwind CSS for styling

---

## Transaction Boundaries

### Pessimistic Locking Required For:
- Money/payment operations
- Inventory movements
- Compliance-critical state changes (document status)
- Sequential number generation

### Optimistic Updates OK For:
- UI feedback on non-critical operations
- Draft/temporary data
- Search/filter operations

---

## Current Module Status

### Core (Ready)
- Identity, Company, Communication, Media

### Shared Business (Ready)
- Product, Partner, Document, Inventory, Treasury, Accounting

### Otospex-Specific (Ready)
- Vehicle, Workshop

### IziPOS-Specific (In Development)
- POS (building now)
- Menu, Tables, Recipe (planned)

---

## Hash Chain Implementation

For fiscal compliance, transactions must form a tamper-evident chain:

```
Transaction N: {
  terminal_id,
  sequence_number,          // Per-terminal, starts at 1
  timestamp,
  transaction_data,
  previous_hash,            // HASH of transaction N-1
  signature                 // HASH of all above fields
}
```

Receipt numbers: `{TERMINAL_CODE}-{SEQUENCE}` (e.g., `POS001-00042`)
- Each terminal has its own sequence (no pre-allocation, counts forever)
- Each terminal has its own hash chain

---

## Testing Requirements

- All new features need unit tests
- Integration tests for API endpoints
- Use PHPStan for static analysis
- Feature tests should test both Otospex and IziPOS contexts where relevant

```php
// Test product context switching
public function test_feature_in_otospex(): void
{
    config(['app.product' => 'otospex']);
    // ...
}

public function test_feature_in_izipos(): void
{
    config(['app.product' => 'izipos']);
    // ...
}
```

---

## Before You Start Coding

1. **Understand the task scope** - Ask clarifying questions if needed
2. **Check existing code** - Don't duplicate what exists
3. **Follow patterns** - Look at similar modules for conventions
4. **Consider both products** - Will this change affect Otospex? IziPOS? Both?
5. **Plan migrations** - Database changes need migration files

---

## Quality Checklist (Before Submitting)

- [ ] Code follows hexagonal architecture
- [ ] Appropriate tests written
- [ ] PHPStan passes (level 8)
- [ ] Routes registered in module's routes.php
- [ ] DTOs used for data transfer
- [ ] Events emitted for significant actions
- [ ] Hash chain maintained (if financial transaction)
- [ ] Works in both product contexts (if shared module)

---

## Useful Commands

```bash
# Run tests
php artisan test

# Run PHPStan
./vendor/bin/phpstan analyse

# Create migration
php artisan make:migration create_table_name

# Fresh database with seeds
php artisan migrate:fresh --seed

# Clear caches
php artisan optimize:clear
```

---

## Getting Help

If you're unsure about:
- **Architecture decisions** → Ask before implementing
- **Existing patterns** → Search codebase for similar implementations
- **Product-specific behavior** → Check config/products.php
- **Database schema** → Review existing migrations
