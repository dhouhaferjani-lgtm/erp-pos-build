# Migration Topology Contract — 2026-05-24 Sprint

**Status:** Pre-sprint gate. Lands in T6 Phase 0 before any other track writes a migration.
**Authority:** This is a constitutional document for the sprint. Every track's spec defers to it. If a track's needs conflict with this contract, the contract wins.

---

## 1. The Constitutional Rule

> **NO cross-database foreign keys.**
> A row in any tenant database can never declare a FOREIGN KEY pointing at a row in another database — central or another tenant. References across the DB boundary are stored as UUIDs (plain columns) and resolved at the application layer through Stancl's tenant context.

This rule survives the Stancl `PostgreSQLSchemaManager` → `PostgreSQLDatabaseManager` flip in T6 Phase 1. Before the flip, every table is in one physical database and FKs across the row-level boundary technically work; after the flip, they cannot. **Designing for the post-flip world from day 1 means we never write a constraint we'll have to drop later.**

### Consequences (all sprint specs comply)

- Tenant tables do not declare FKs to `tenants`, `domains`, `plans`, `tenant_subscriptions`, `super_admins`, or anything in the central DB
- Central tables do not declare FKs to anything tenant-scoped (they can't — they don't know which tenant DB to look in)
- `tenant_id` columns on tenant tables are **optional and informational** post-flip (the DB itself identifies the tenant); keep them for audit + observability but they no longer enforce isolation
- Cross-tenant validation moves from "FK constraint" to "service-layer assertion under the current tenant context"

---

## 2. Central vs Tenant DB classification

### Central DB tables (live in the default connection, NOT replicated per tenant)

| Table | Owner | Reason |
|---|---|---|
| `tenants` | Tenant module | The directory of all tenants; obviously central |
| `domains` | Tenant module | Per-tenant subdomain → tenant_id resolution |
| `plans` | Tenant module | Subscription plan catalog |
| `tenant_subscriptions` | Billing module | Tenant subscription state (table name corrected from v3 per round-3 P2-1; verified at `apps/api/database/migrations/2025_12_01_193759_create_tenant_subscriptions_table.php:14`) |
| `super_admins` (if exists) | Auth | Super-admin users that span tenants — central only |
| **NOT** `users` | — | **TENANT-scoped** (corrected per round-2 B-2 + round-3 P2-3 line citations): `users` is per-tenant — verified at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:18` declares `tenant_id` column and line 39 declares `unique(['tenant_id', 'email'])`. Regular users belong in each tenant's DB. Only super-admins live central (separate `super_admins` table to be created in Phase 0 if not already present). |
| `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | Spatie | **TENANT-scoped (DECISION per round-3 P1-4 evidence):** Spatie config has teams enabled with `tenant_id` as team key (`apps/api/config/permission.php:95-134`); migration uses `tenant_id` in roles + model pivots (`apps/api/database/migrations/2025_11_29_231806_create_permission_tables.php:36-44, 61-66, 85-90`). All Spatie permission tables move to tenant DB; no central role catalog. |
| `failed_jobs`, `jobs`, `cache`, `sessions`, `password_reset_tokens` | Laravel infra | Cross-tenant infrastructure |
| Sentry / monitoring metadata if any | Observability | Cross-tenant |

### Tenant DB tables (replicated per tenant via Stancl)

Everything else. Specifically, the sprint touches:

| Table | Owning track | Notes |
|---|---|---|
| `companies` | existing | Tenant has many companies; FK-able within tenant DB |
| `locations` | existing → T1 adds `tax_id`, `branch_code`, `legal_name` | FK within tenant DB |
| `products`, `product_images` | existing → T2 may touch | FK within tenant DB |
| `product_attributes` (NEW) | T2 | FK within tenant DB |
| `product_attribute_values` (NEW) | T2 | FK within tenant DB |
| `product_variants` (NEW) | T2 | FK within tenant DB; parent_id → products |
| `product_variant_attribute_values` (NEW) | T2 | FK within tenant DB |
| `stock_levels` | existing → T2 adds `variant_id` | FK within tenant DB |
| `stock_movements` | existing → T2 adds `variant_id`, T1 adds `transfer_id` | FK within tenant DB |
| `stock_reservations` | existing → T2 adds `variant_id` | FK within tenant DB |
| `product_batches` | existing → T2 adds `variant_id` (unlocks the existing TODO) | FK within tenant DB |
| `inventory_batch_movements` | existing | join table batch ↔ movement; preserved |
| `stock_transfers` (NEW) | T1 | FK within tenant DB |
| `stock_transfer_lines` (NEW) | T1 | FK within tenant DB |
| `inter_company_pricing_strategies` (NEW) | T1 | Per-tenant config |
| `document_lines` | existing → T2 adds `variant_id` | FK within tenant DB |
| `pos_receipts`, `pos_receipt_lines` | existing → T2 adds `variant_id` | FK within tenant DB |
| `pos_receipt_line_batch_allocations` | existing → T2 adds `variant_id` | FK within tenant DB |
| `partners`, `party_contacts` | existing → T11 may add `customer_contacts` join | FK within tenant DB |
| `customer_contacts` (NEW) | T11 (next cycle implementation) | FK within tenant DB |
| `price_lists`, `price_list_items`, `partner_price_lists` | existing → T11 PricingStrategyResolver consumes | FK within tenant DB |
| `channels` (NEW) | T3 | FK within tenant DB |
| `channel_credentials` (NEW) | T3 | FK within tenant DB; encrypted_payload at rest |
| `channel_product_mappings` (NEW) | T3 | FK within tenant DB; references product/variant within same tenant |
| `channel_orders` (NEW) | T3 | FK within tenant DB |
| `channel_sync_operations` (NEW) | T3 | FK within tenant DB |
| `zones` (NEW) | T4 | Per-tenant taxonomy; FK within tenant DB |
| `zone_postal_ranges` (NEW) | T4 | FK within tenant DB |
| `location_service_zones` (NEW) | T4 | FK within tenant DB; references location + zone — **NO `tenant_id` FK** (DB boundary is the tenant) |
| `routing_rule_sets` (NEW) | T4 | FK within tenant DB |
| `routing_rules` (NEW) | T4 | FK within tenant DB |
| `routing_decisions` (NEW) | T4 | FK within tenant DB |
| `journal_entries`, `journal_lines`, `accounts`, `fiscal_periods` | existing | FK within tenant DB |
| `payment_methods`, `payments`, `payment_instruments`, `payment_repositories` | existing | FK within tenant DB |
| `fiscal_events`, `device_*` (POS fiscal Phase 1 in flight) | existing fiscal track | FK within tenant DB |

### Tables that need a `is_pre_warmed` flag (T6 pre-warm pool)

- `tenants` (central) — adds `is_pre_warmed`, `pre_warmed_at`, `claimed_at` for pool tracking

---

## 3. Migration directory structure (T6 Phase 0 deliverable)

Create:

```
apps/erp/apps/api/database/migrations/                  ← central migrations (existing path)
apps/erp/apps/api/database/migrations/tenant/           ← NEW — tenant migrations
```

T6 Phase 0 work:

1. Create `database/migrations/tenant/` directory
2. Identify every existing migration that creates/alters a tenant table per the classification above
3. **Move** those migrations into `database/migrations/tenant/` (preserve filenames + timestamps)
4. Verify central migrations remaining at `database/migrations/` are genuinely central per the classification
5. Update `apps/erp/apps/api/config/tenancy.php`:
   - Flip `pgsql => PostgreSQLDatabaseManager::class` (currently `PostgreSQLSchemaManager`)
   - Set `migration_parameters` path to `database_path('migrations/tenant')` (the config already references this — verify)
6. Run full test suite against the flipped configuration — every passing test today must continue to pass
7. Add a Stancl flip test that creates a tenant + verifies new database exists + verifies tenant migrations ran inside it
8. Document the gate completion in `apps/erp/docs/superpowers/coordination/2026-05-24-t6-phase0-gate-complete.md` so other tracks know it landed

**Sequencing rule:** No other track in the sprint may write a new migration until T6 Phase 0 is merged. Tracks may write spec drafts and start non-migration work in parallel.

---

## 4. Cross-DB reference patterns (what to do INSTEAD of FK)

### Pattern A — Tenant table needs to reference a central row

Store the UUID. Validate via service-layer lookup against the central connection.

```php
// In tenant schema
$table->uuid('plan_id')->nullable();
// NOT $table->foreignUuid('plan_id')->constrained('plans')...

// In application code
$plan = DB::connection('central')->table('plans')->find($planId);
if (!$plan) {
    throw new InvalidPlanReference(...);
}
```

**Phase 0 prerequisite (per round-2 B-3):** The `'central'` connection name does NOT exist in `apps/erp/apps/api/config/database.php` today. T6 Phase 0 MUST add it as part of the gate work — point it at a separate physical PG database `synerivia_central` (via `DB_CENTRAL_DATABASE` env var). The default `pgsql` connection becomes the per-tenant connection template (Stancl resolves the actual DB per request). Add the central connection schema definition before any Pattern A code can compile.

**Phase 0 also addresses (per round-2 B-1 + round-3 B-1): cross-DB FK declarations in tenant migrations.** Verified by syntax-independent grep:
- `grep -rln "constrained('tenants')\\|constrained(\"tenants\")"` returns 39 files
- `grep -rln "references('id')->on('tenants')\\|references(\"id\")->on(\"tenants\")"` returns additional files including `users` (`2025_11_30_000003_create_users_table.php:33-36`), `companies` (`2025_11_30_104000_create_companies_table.php:104-105`), `product_images` (`2025_12_29_155412_create_product_images_table.php:33-34`)
- Combined unique-file count: ~50 files (per round-3 B-1 confirmation)

These FK declarations will fail at migration time post-flip. Phase 0 work list must rewrite ALL of them (regardless of syntax form) to plain `->uuid('tenant_id')->index()`. **Phase 0 acceptance must use a syntax-independent FK audit grep** covering BOTH `constrained('tenants')` and `references('id')->on('tenants')` patterns. Clean-slate framing per user direction: no data preservation needed; rewrite freely.

### Pattern B — Central table needs to reference a tenant row

Don't. Central tables can't reference tenant rows because central doesn't know which tenant DB to look in. If the central concern needs tenant context, the data should be aggregated into central via an event (T7-style aggregator) — never via direct FK.

### Pattern C — Tenant table needs to reference another row in the same tenant DB

Use normal FK. The DB boundary IS the tenant.

```php
$table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
// Both stock_levels and locations are in the same tenant DB. FK works normally.
```

### Pattern D — Tenant table needs `tenant_id` for audit

Optional column. Not FK. Defaults to the current tenant on insert via Eloquent observer. Read-only for cross-tenant audit queries (e.g., super-admin sees "tenant X received Y events last week").

```php
$table->uuid('tenant_id')->nullable()->index();
// No ->constrained() — we don't FK to tenants table because tenants lives in central DB
```

---

## 5. Pool pre-warming compatibility

Pre-warmed tenant databases:
- Are created empty (just the tenant migrations run, no init seeders)
- Contain no `companies`, `users`, country-specific data
- Are claimable: at signup, `TenantClaimService` attaches the user, runs `TenantInitializationService::initializeForNewRegistration` (which seeds country-specific CoA + tax + payment methods)
- Stancl's `PostgreSQLDatabaseManager` handles the DB creation; we just queue it earlier via `tenant:ensure-pool`
- The pool tenants live in the central `tenants` table with `is_pre_warmed=true`, `status=PreProvisioned`

---

## 6. PgBouncer compatibility

PgBouncer in transaction mode (`POOL_MODE: transaction` per `docker-compose.staging.yml`) is compatible with DB-per-tenant if:

- `DB_PGBOUNCER=true` env var triggers `PDO::ATTR_EMULATE_PREPARES => true` (already in `config/database.php:100`)
- Long-running operations (migrations, backups, restores, pool pre-warming) connect directly to Postgres, **bypassing PgBouncer**, to avoid transaction-mode issues with multi-statement scripts
- Define an alternate connection `pgsql_direct` for these operations

---

## 7. Backfill plan for nullable `variant_id` additions (T2)

When T2 adds `variant_id` (nullable) to `stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `document_lines`, `pos_receipt_lines`, `pos_receipt_line_batch_allocations`:

1. Migrations add the column nullable; existing rows get `variant_id = NULL` automatically
2. Existing unique constraints must handle the null-distinct semantics. PostgreSQL treats NULL as distinct in default unique indexes, which **breaks the constraint we want** ("one stock row per location per product/variant combo"). Two options:
   - **Partial unique indexes:** one for `variant_id IS NULL`, one for `variant_id IS NOT NULL`
   - **COALESCE trick:** create a unique index on `COALESCE(variant_id, '00000000-0000-0000-0000-000000000000'::uuid)`
   - **Decision:** use partial unique indexes; cleaner and more idiomatic. Document both indexes in the T2 migration.
3. No data backfill required. Non-variant products keep working with `variant_id = NULL`. Variant products created after migration have variant rows from inception.
4. Existing tests for non-variant flows must continue to pass — T2 adversarial review explicitly verifies this.

---

## 8. Shared reference data — RESOLVED (per round-3 B-2 + clean-slate guidance)

**Decision:** all reference data tables live in EVERY tenant DB, seeded via `TenantInitializationService` at tenant claim time. NOT central.

**Rationale:** clean-slate move to multi-DB means no data migration burden. Per-tenant seeding is simpler than Pattern A lookups for tables that change rarely (countries, country_tax_rates, etc.). It also means tenant tables can FK to these reference tables normally within the same DB.

**Reference tables to seed per-tenant (verified in code):**
| Table | Current location | Phase 0 action |
|---|---|---|
| `countries` | `apps/api/database/migrations/2025_11_30_*_create_countries_table.php` | Move to `tenant/`; seed via `CountrySeeder` |
| `country_tax_rates` | `2025_12_01_192545_create_country_tax_rates_table.php` (FK on countries.code) | Move to `tenant/`; FK intra-tenant; seed |
| `country_payment_settings` | `2025_12_02_*` | Move to `tenant/`; seed |
| `tax_configurations` | `2025_12_30_100000_create_tax_configurations_table.php` (FK on countries.code) | Move to `tenant/`; FK intra-tenant; seed via existing `TunisiaTaxConfigurationSeeder` etc. |

`TenantInitializationService` already seeds CoA + tax_configurations per country. Phase 0 extends it to seed `countries` FIRST (so dependent tables have FK targets), then `country_tax_rates`, etc.

## 9. Tenant identification architecture (NEW per user direction)

Every tenant has its own physical PG database. The API must resolve **which DB to open** for each request. Resolution mechanisms:

### Web ERP — subdomain-based via `domains` table (corrected per round-4 B-2)

- URL pattern: `{tenant_slug}.synerivia.tn` (e.g., `nenupharma.synerivia.tn`)
- Stancl's `InitializeTenancyByDomain` middleware resolves tenant by **querying the `domains` table** (verified at Stancl's `DomainTenantResolver.php:32-43`) — NOT by parsing the subdomain string directly
- The central `domains` table maps `domain` (e.g., `nenupharma.synerivia.tn`) → `tenant_id`
- **Phase 0 work item (NEW per round-4 B-2):** `AuthController::register()` must create a `domains` row alongside the tenant row. Currently only the CLI `CreateTenantCommand:89` does this; web signup does not. Without this fix, subdomain login will 404.
- Each tenant DB is named `tenant_{slug}` per the computed return value of `Tenant::getDatabaseName()` at `apps/api/app/Modules/Tenant/Domain/Tenant.php` (this is a method, not a column on the `tenants` table)

### Tauri desktop POS — explicit tenant_id

Desktop apps have no subdomain. Resolution via:
- First-time login screen prompts for THREE fields: `tenant_id` (the tenant's slug) + `email` + `password`
- API endpoint: `POST /api/v1/auth/login` with body `{tenant_id, email, password}` (current `LoginRequest` accepts only email/password/device — needs rewrite as part of Phase 0 work item 8)
- API resolves `tenant_id` slug against central `tenants.slug` → bind tenant context → validate email/password against `users` in that tenant's DB
- On successful login, store `tenant_id` + session token in Tauri local storage (existing `@tauri-apps/plugin-store`)
- Subsequent requests carry tenant identifier via HTTP header; API uses a tenancy-resolver middleware

**Stancl middleware reality (corrected per round-4 codex BLOCKER B-1):**

Installed Stancl `v3.10.0` ships these defaults that DON'T match our v4 sketch:
- `InitializeTenancyByRequestData` middleware reads header `X-Tenant` (NOT `X-Tenant-ID`) and resolves via `tenancy()->find($payload)` which expects the tenant PRIMARY KEY (NOT slug)
- `RequestDataTenantResolver.php:21-29` confirms PK-based lookup

Phase 0 has two implementation paths; pick one explicitly:
- **Option A (recommended — slug-based UX preserved):** write a custom `SlugTenantResolver` that looks up by `tenants.slug` instead of PK, register it as Stancl's tenant resolver for the request-data middleware, and configure the header name to `X-Tenant-ID`. ~0.5 PD additional work in Phase 0.
- **Option B (use Stancl defaults — simpler but worse UX):** use tenant UUID instead of slug. User remembers a UUID instead of "nenupharma". Header is `X-Tenant`, payload is UUID. Zero custom code.

**v5 commits to Option A** — the slug UX matches the user's direction. T6 Phase 0 work item 8 includes writing the custom resolver.

### Web ERP — domain row creation at signup

`InitializeTenancyByDomain` resolves via the central `domains` table — it expects a full domain row, e.g., `nenupharma.synerivia.tn`. `DomainTenantResolver` queries `domains.domain` with the full hostname.

Decision per round-4 P1-2: use FULL-DOMAIN rows (not slug extraction). Signup creates a `domains` row with `domain = "{slug}.synerivia.tn"`. Phase 0 work item 8 (AuthController::register rewrite) includes this. If wildcard SSL is configured for `*.synerivia.tn`, the only setup per tenant is the domains row insert.

### Future mobile app — same as Tauri

- Same first-time login UX: `tenant_id` + `email` + `password`
- Same header-based subsequent requests
- Local secure storage caches `tenant_id`

### Central DB tenant directory

The central `tenants` table fields (corrected per round-4: `database_name` is NOT a column — it's the return value of `Tenant::getDatabaseName()` method which composes `tenant_{slug}` at runtime):

- `slug` (unique, indexed) — the user-facing tenant_id (verify exists in current `tenants` table migration; add if missing)
- `status` (Active, Suspended, PreProvisioned, Pending, Archived)
- `created_at`, `updated_at`
- Subscription state lives in the separate `tenant_subscriptions` table referenced by Pattern A (UUID, no FK across DB boundary post-flip)
- Companion `domains` table maps `domain` → `tenant_id` (this is the table Stancl actually queries for resolution)
- Companion `super_admins` table for platform users — VERIFIED EXISTS at `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php` (corrected per round-4: NO need to "create if not present"; it's there)

When user enters `tenant_id` at Tauri login, API queries central `tenants` table → resolves database connection → opens tenant DB context → validates credentials. **Login failure modes:**
- Unknown `tenant_id`: 404 with "tenant not found" (avoid revealing whether the tenant exists vs the email)
- Tenant in `Suspended` / `Archived` status: 403 with clear message
- Tenant in `PreProvisioned` (unclaimed pool): same as not found for security
- Bad email/password against valid tenant: 401 with generic "invalid credentials"

### Slug format

`tenant_id` slug rules (per existing `tenants` table conventions):
- Lowercase a-z, 0-9, hyphens only
- 3-32 characters
- Globally unique
- Generated at tenant signup (auto-suggest from company name, user can customize)
- Examples: `nenupharma`, `acme-pharma`, `client-001`

### Stancl middleware references

- `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` (web ERP)
- `Stancl\Tenancy\Middleware\InitializeTenancyByRequestData` (Tauri, mobile, API clients)
- `Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain` (if mixing patterns)

Phase 0 work includes wiring both middleware in `app/Http/Kernel.php` route middleware group for the appropriate route groups (web vs api/desktop).

---

## 10. Enforcement

This contract is enforced via:

1. **Code review** — every new migration in the sprint asserts central-vs-tenant placement explicitly in the migration's docblock
2. **Linter / static check** (post-sprint nice-to-have): a custom phpstan rule that fails if a tenant migration creates a FK to a known-central table
3. **Test** — T6 Phase 0 adds an integration test that asserts no FK exists from any tenant table to a central table

---

## 11. References

- `apps/erp/apps/api/config/tenancy.php` — current Stancl config (line 84: `PostgreSQLSchemaManager::class` is the pre-flip default)
- `apps/erp/docker-compose.staging.yml` — PgBouncer transaction-mode config
- `apps/erp/apps/api/config/database.php:100` — `DB_PGBOUNCER` env handling
- Stancl tenancy v3 docs — `PostgreSQLDatabaseManager` semantics
- Memory: `feedback_sweep_audit_trail_anchoring.md` — tenant-isolation enforcement patterns
