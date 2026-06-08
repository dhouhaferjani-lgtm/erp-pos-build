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

## Multi-Tenancy (Database-Per-Tenant)

> Flipped from row-level to database-per-tenant on 2026-05-28 (T6 Phase 0b,
> PRs #141–#146 + #148). Anywhere you see "schema-based" or "row-level
> multi-tenancy" in older docs, treat it as stale.

**Two named Laravel connections, one PostgreSQL cluster:**

| Connection | Database | Holds | Swapped per request? |
|---|---|---|---|
| `central` | `synerivia_central` | tenants, domains, plans, tenant_subscriptions, super_admins, central_identities, personal_access_tokens, tenant_backups | Never |
| `tenant` (default in prod) | `tenant_<tenant-uuid>` | every tenant-scoped table (users, products, documents, stock_*, fiscal events, etc.) | Yes — Stancl `DatabaseTenancyBootstrapper` swaps the default connection mid-request to the resolved tenant's DB |

**Migration topology:**

- `apps/api/database/migrations/` — CENTRAL migrations (run by `php artisan migrate`).
- `apps/api/database/migrations/tenant/` — TENANT migrations (run by `tenants:migrate` per tenant DB, and by `tenant:migrate-rolling` to walk every tenant). Stancl `MigrateDatabase` job runs these on signup.

**Pinned-connection models:** `CentralPersonalAccessToken`, `Tenant`, `CentralIdentity`, and friends declare `protected $connection = 'central'` so `auth:sanctum` lookups, identity-index reads, and tenant directory queries always reach central even after the per-request swap.

**Lifecycle:**

- Signup → `CreateDatabase` → `MigrateDatabase` → reference-data seed → tenant verification email (under re-entered tenant context).
- Deprovision → `TenantDeprovisioningService::deprovision()` drops the physical DB + removes central directory rows. `suspend()` only flips status (DB survives, reversible).
- Rolling migrations → `tenant:migrate-rolling` applies pending tenant migrations one DB at a time.
- Backup / restore → `tenant:backup [slug|--all]` (pg_dump custom format → local disk, metadata in central `tenant_backups`); `tenant:restore <slug> <file>` is the drop+recreate counterpart with sha256 verification.
- Monitoring → `tenant:status` CLI + `GET /api/v1/admin/monitoring/tenants` JSON.

**Connection pooling (production):**

PgBouncer in `POOL_MODE=transaction` (the only mode compatible with per-request DB switching — session-mode would pin a request to one backend DB). Set `DB_PGBOUNCER=true` to enable PDO emulate-prepares, which is required in transaction-pool mode. CREATE / DROP DATABASE (signup, deprovision, restore) MUST bypass PgBouncer — see `docs/operations/POST-FLIP-OPS.md`.

**Resolver mode flag:** `TENANCY_DB_PER_TENANT=true` makes the pre-auth resolver fail closed (503) when a tenant's database is unreachable. Defaults to true in any environment running real per-tenant DBs.

**Greenfield migration:** there was no production tenant data at flip time (owner confirmed 2026-05-25), so no data partitioning, no row→DB carve-out, no legacy fiscal-chain re-verification.

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
