# Migration Topology Contract — 2026-05-24 Sprint

**Status:** Pre-sprint gate. Lands in T6 Phase 0 before any other track writes a migration. (v7 — r7 Codex auth-review fixes applied: §9.1 identity-index full lifecycle, §9.4 grep-based route coverage + central Sanctum PAT model, §9.5 signed-`tenant_id` links + invitation row.)
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
| `super_admins` | Auth | Super-admin users that span tenants — central only. **Verified exists** at `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-28` (round-5 P2-2: no "create if not present"). |
| `central_identities` (NEW v6) | Tenant / Identity-central | Email → tenant(s) lookup index for email-first login (§9.1). Pointers only, NO credentials. NO FK to `tenants` (Pattern A). |
| **NOT** `users` | — | **TENANT-scoped** (corrected per round-2 B-2 + round-3 P2-3 line citations): `users` is per-tenant — verified at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:18` declares `tenant_id` column and line 39 declares `unique(['tenant_id', 'email'])`. Regular users belong in each tenant's DB. Only super-admins live central. |
| `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | Spatie | **TENANT-scoped (DECISION per round-3 P1-4 evidence):** Spatie config has teams enabled with `tenant_id` as team key (`apps/api/config/permission.php:95-134`); migration uses `tenant_id` in roles + model pivots (`apps/api/database/migrations/2025_11_29_231806_create_permission_tables.php:36-44, 61-66, 85-90`). All Spatie permission tables move to tenant DB; no central role catalog. |
| `failed_jobs`, `jobs`, `cache`, `sessions` | Laravel infra | Cross-tenant infrastructure |
| `personal_access_tokens` (Sanctum) | Identity-central | **CENTRAL (v6 — §9.4).** Must be central: the token → tenant resolution happens *before* tenancy is initialized, so the token row must be readable on the central connection. Polymorphic `tokenable` (User) is type+id columns only — no cross-DB FK. **Tenant source = the EXISTING `tenant:<uuid>` token ability** (minted today at `AuthController.php:222,379`), NOT a new column — reuse it (round-6 P1-2; do not build a parallel mechanism). |
| **NOT** `password_reset_tokens`, `email_verification_tokens` | — | **TENANT-scoped (v6 — §9.5):** both FK/relate to tenant `users`; password reset + email verification run inside tenant context after the central-index resolves the tenant. Reclassified from central in v6. |
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

**Phase 0 prerequisite (per round-2 B-3):** The `'central'` connection name does NOT exist in `apps/erp/apps/api/config/database.php` today. T6 Phase 0 MUST add it as part of the gate work — point it at a separate physical PG database `synerivia_central` (via `DB_CENTRAL_DATABASE` env var). The default `pgsql` connection becomes the per-tenant connection template (Stancl resolves the actual DB per request). Add the central connection schema definition before any Pattern A code can compile. **Note (round-6 S-4):** Stancl already expects a connection named `central` — `config/tenancy.php:51` is `'central_connection' => env('DB_CONNECTION', 'central')`. Align them: either add a `central` connection in `database.php` AND keep it as Stancl's `central_connection`, or set `DB_CONNECTION=central`, so "the app's central" and "Stancl's central" are the same connection.

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
| `countries` | `apps/api/database/migrations/2025_12_01_192409_create_countries_table.php` | Move to `tenant/`; seed via `CountriesSeeder` (`database/seeders/CountriesSeeder.php:10`) — corrected filename + seeder class per round-5 P2-3 |
| `country_tax_rates` | `2025_12_01_192545_create_country_tax_rates_table.php` (FK on countries.code) | Move to `tenant/`; FK intra-tenant; seed |
| `country_payment_settings` | `2025_12_10_100000_create_country_payment_settings_table.php` | Move to `tenant/`; seed — corrected filename per round-5 P2-3 |
| `tax_configurations` | `2025_12_30_100000_create_tax_configurations_table.php` (FK on countries.code) | Move to `tenant/`; FK intra-tenant; seed via existing `TunisiaTaxConfigurationSeeder` etc. |

`TenantInitializationService` already seeds CoA + tax_configurations per country. **Phase 0** (not Phase 1A — round-5 P1-2) extends it to seed `countries` FIRST (so dependent tables have FK targets), then `country_tax_rates`, then `country_payment_settings`.

## 9. Tenant identification & authentication architecture (v6 — email-first + central identity index)

**Decision (locked per user direction + industry research, v6):** authentication is **email-first**, backed by a thin **central identity index**. Subdomain resolution is an *optional shortcut*, not the required entry point. The canonical rule: **authentication is global; authorization is tenant-scoped** (WorkOS / Auth0 / Wristband / Clerk consensus). The same email may belong to more than one tenant; a single email is **not** a tenant boundary.

This model directly resolves round-5 B-1 (the pre-auth identity flows that assumed a global `users` table) and shrinks round-5 P1-1 (the Stancl request-data middleware no longer carries the auth path — see "Subsequent requests" below).

### 9.1 Central identity index (NEW central table)

A lightweight central lookup table maps an email to the tenant(s) it belongs to. It stores **pointers only — never credentials**.

```php
// central DB — Tenant module or new Identity-central module
Schema::create('central_identities', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->string('email')->index();          // NOT globally unique — same email may map to many tenants
    $t->uuid('tenant_id');                  // plain UUID, NO FK (Pattern A — central row, but cross-row resolution at app layer)
    $t->uuid('user_id')->nullable();        // informational: the tenant-side users.id; resolved post-tenancy-init
    $t->timestamps();
    $t->unique(['email', 'tenant_id']);     // one index row per (email, tenant) membership
});
```

- **Credentials stay tenant-side.** Password hashes live only in each tenant's `users` table. The index answers "which tenant DB(s) do I open for this email?" — nothing more.
- **Kept in sync** by an **`IdentityIndexService` (the single writer)** invoked from `AuthController::register`, **`UserController::store` (admin-invited users — `UserController.php:171-184`)**, email change via **`UserController::update`** (`UserController.php:232-279`), and **`UserController::destroy` deactivation (`:340-346`)** — register alone is insufficient (r7 P1), or invited users never enter the index and lose email-first login / recovery. A `tenant:reconcile-identities` command backfills/repairs.
- **Cross-DB write ordering (round-6 P2-6 — no atomic transaction is possible).** Today `register` does tenant+user creation in one `DB::transaction` on the default connection (`AuthController.php:264-389`); post-flip the central `central_identities` row and the tenant-DB `users` row live in **two different databases**, so they cannot share one transaction. Required ordering + compensation: write central `tenants` + `central_identities` FIRST → `tenancy()->initialize()` → create the tenant-DB `users` row; **on tenant-DB failure, delete/mark the central rows**; the reconcile command sweeps any stragglers. This is **eventual-consistency by design** — state it so the implementer doesn't reach for a cross-DB transaction.
- This is the "central account index with tenant_id + email" round-5's reviewer named as an acceptable B-1 fix.

### 9.2 Web ERP login — email-first with live org picker (Balanced)

1. User lands on a single global login route (no subdomain required). Enters **email**.
2. API looks up `central_identities` by email:
   - **0 tenants:** return a generic "no organizations found — check your email or contact your administrator." (Does not confirm/deny the email; rate-limited.)
   - **1 tenant:** proceed straight to the password step, binding that tenant context.
   - **>1 tenants:** return the list of tenants for that email; the user picks the active organization (the **Balanced** enumeration stance — for a valid email we show memberships in-browser; accepted tradeoff for non-tech-savvy UX, paired with rate-limiting).
3. With the tenant chosen, the API calls `tenancy()->initialize($tenant)` and validates email + password against the `users` table **inside that tenant's DB**.
4. **"Find my organization"** link on the login screen: enter email → we **email** the list of organizations / sign-in links (privacy-preserving recovery path, regardless of the live-picker stance).

**Subdomain shortcut (optional):** `{tenant_slug}.synerivia.tn` still works for users who bookmark it — Stancl's `InitializeTenancyByDomain` resolves it via the central `domains` table (full-host lookup, verified at `DomainTenantResolver.php:32-43`). Signup creates the `domains` row (`domain = "{slug}.synerivia.tn"`); previously only `CreateTenantCommand:86-94` did this. With wildcard SSL on `*.synerivia.tn`, the only per-tenant setup is the `domains` insert. When present, the subdomain skips steps 1–2.

**Drop global email-availability (`check-email`):** with same-email-across-tenants allowed, email uniqueness is **per-tenant** (`users.unique(tenant_id, email)` already enforces this). The current public `AuthController::checkEmail` (`AuthController.php:422-427`, called by `apps/web/src/features/auth/components/AccountStep.tsx:23-26`) does a *global* `User::where('email')` — that is now conceptually obsolete. Phase 0 **removes** it; the React registration step drops the call (email collision is detected per-tenant at submit).

### 9.3 POS (Tauri) & future mobile — device-bound to one tenant

A physical terminal belongs to **one shop / one tenant**. So:
- **First-time device setup** (manager): enter the **organization code** (`tenant_id` slug) **once** → device resolves + caches `tenant_id` in `@tauri-apps/plugin-store`.
- **Every shift after that:** cashier enters only **email + password (or PIN)**. No org-code per login. The cached `tenant_id` is sent with the login request.
- Login endpoint `POST /api/v1/auth/login` accepts `{tenant_id, email, password}`; current `LoginRequest` (`apps/api/app/Modules/Identity/Presentation/Requests/LoginRequest.php:25-35`) accepts only email/password/device — Phase 0 adds the optional `tenant_id`.
- This keeps the org-code one-time and out of the cashier's daily flow. Mobile follows the same pattern (one tenant per install).

### 9.4 Subsequent (authenticated) requests — token-bound tenant, NOT a client header

⚠️ **Reality check (round-6 P1-1): there is NO request-time tenancy layer in the app today.** `tenancy()->initialize()` is called in exactly one place — `app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:75` (a console command). No Stancl identification middleware is on the HTTP path. So Phase 0 **builds the request-time tenancy resolver from scratch** and registers it (in `bootstrap/app.php`) on the global `api` group **and** the Identity `web` group — covering **every `auth:sanctum` route surface** (r7 P2: there are MORE than 11 — root `routes/api.php:49`, Product, POS, Document, Accounting, and other route-provider-loaded modules; audit by `grep -rn "auth:sanctum"`, not a fixed file count), not just `/api/v1/auth/*`. Every authenticated route that loads a tenant-DB `User` without prior `tenancy()->initialize()` breaks after the flip. **This is the dominant cost driver of Phase 0** (see effort note).

The resolver runs **before `auth:sanctum`** and binds tenant by auth mode (two tenant sources, one middleware with two branches):

- **Bearer token (POS / mobile / API clients):** `personal_access_tokens` is central (§2). The existing token-minting flow already stamps a **`tenant:<uuid>` ability** on every token (`AuthController.php:222` login, `:379` register). The resolver reads the PAT's abilities on the **central** connection → extracts the `tenant:` claim → `tenancy()->initialize($tenant)`. Then `auth:sanctum` resolves the `tokenable` `User` **in tenant context** (verified: Sanctum `Guard::__invoke` lazy-loads the morphTo `tokenable` at `vendor/laravel/sanctum/src/Guard.php:50`, which fires *after* tenancy init → hits the tenant DB). **⚠️ PAT row lookup must be pinned to central (r7 B2):** Sanctum's own `PersonalAccessToken::findToken()` runs *after* tenancy init too, so on the default (now tenant) connection it would miss the central PAT. Phase 0 MUST register `CentralPersonalAccessToken extends Sanctum\PersonalAccessToken` (`$connection='central'`) via `Sanctum::usePersonalAccessTokenModel(...)` so the token row is read from central while only the `tokenable` resolves tenant-side.
- **Web SPA cookie (back-office):** `AuthController::login` uses `Auth::attempt()` + `session()->regenerate()` (`AuthController.php:186,196`) — stateful cookie auth, so the request carries a **`TransientToken`, not a PAT** (`Guard.php:32-38`); there is no `personal_access_tokens` row to read (round-6 P2-1). Therefore **the tenant is stamped into the session at login**, and the resolver's cookie branch reads `tenant_id` from the session → `tenancy()->initialize()`. (Alternative: switch web to bearer tokens — but session-stamping is the smaller change.)

**Reconcile with the existing claim guard (round-6 P1-2 — do NOT build a parallel mechanism):** `EnforceTokenTenantClaim` (`Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`) already reads `currentAccessToken()->abilities`, extracts the `tenant:` claim, and rejects mismatches (`TOKEN_TENANT_MISMATCH`, 401) — but it runs **after** `auth:sanctum` (`routes.php:43`) and only *guards*; it does not *initialize* tenancy. v6 keeps it as the post-auth guard (belt-and-suspenders) and adds the **new pre-auth resolver** described above. **The single source of truth for a bearer request's tenant is the `tenant:<uuid>` ability** — both middlewares read it; we do not add a second binding (no `tenant_id` column on `personal_access_tokens`).

**The client never sends `X-Tenant-ID`** — the tenant travels with the token ability (bearer) or the session (cookie), server-side. **Stancl's `InitializeTenancyByRequestData` is NOT on the auth path** (round-5 P1-1 dissolved): its `X-Tenant` default header / PK-based `RequestDataTenantResolver` / `RequestDataTenantResolver`-typed constructor are irrelevant unless we ever expose pre-token clients that self-declare a tenant; we don't this sprint.

### 9.5 Pre-auth identity flows (round-5 B-1 — all owned by Phase 0)

Every pre-auth path resolves tenant context **before** touching `users`:

| Flow | v6 behavior |
|---|---|
| `check-email` (registration) | **Removed** — email uniqueness is per-tenant; collision surfaces at register submit. |
| email verification (`verify-email`) | **The link embeds a signed `tenant_id` (r7 B1 — a token-only link cannot pick a tenant DB)**; resolver `tenancy()->initialize()` → look up `EmailVerificationToken` + user in-tenant. Update `VerifyEmailNotification.php:39-45` + `VerifyEmailRequest.php:25-29` to carry/validate it. `email_verification_tokens` reclassified **tenant-side** (it FKs `users` — `2025_12_21_125019_*:16-26`). |
| `forgot-password` / `reset-password` | forgot-password resolves tenant (email + chosen org if >1) via `central_identities` and issues a reset link **carrying a signed `tenant_id` (r7 B1)**; on click the resolver `tenancy()->initialize()` **BEFORE invoking the `Password` broker** → issue/consume the reset token in tenant context. Update `ResetPasswordPage.tsx:25-55` + `ResetPasswordRequest.php:21-28` to round-trip the qualifier. `password_reset_tokens` is **email-keyed (string PK), NO FK to `users`** (`2025_11_30_160000_create_password_reset_tokens_table.php` — round-6 P2-2 corrected the earlier "FK to users" rationale); reclassify **tenant-side** because the reset must run under tenant context. Because `tenancy()->initialize()` swaps the default connection, Laravel's `users` broker provider + its `DatabaseTokenRepository` both bind to the tenant connection automatically — **no custom broker class needed IF tenancy is initialized first** (round-6 P2-3); validate this in Phase 0, else add a per-tenant broker repository binding. Generic "if that email is registered, we've sent a link" responses. |
| `register` | Creates the central `tenants` row + the `domains` row + a `central_identities` row (via `IdentityIndexService`), then `tenancy()->initialize()` and creates the first `users` row **inside the tenant DB** (current register does it all on the default connection in one transaction — `AuthController.php:264-389`). |
| user invitation (`UserController::store`) | Admin invites a user → `IdentityIndexService` writes the `central_identities` row + the set-password link **carries a signed `tenant_id` (r7 B1)** — current `UserInvitation.php:48-56` sends only token+email, which cannot pick a tenant DB post-flip. |

### 9.6 Central DB tenant directory

The central `tenants` table (`database_name` is **not** a column — it's `Tenant::getDatabaseName()` returning `tenant_{slug}` at `Tenant.php:248-250`):
- `slug` (unique, indexed) — the user-facing organization code
- `status` (Active, Suspended, PreProvisioned, Pending, Archived)
- `created_at`, `updated_at`
- Subscription state in the separate `tenant_subscriptions` table (Pattern A, UUID, no cross-DB FK)
- Companion `domains` table maps `domain` → `tenant_id` (what Stancl queries for the optional subdomain path)
- Companion `super_admins` table for platform users — **verified exists** at `apps/api/database/migrations/2025_12_01_194614_create_super_admins_table.php:14-28`
- NEW: `central_identities` (§9.1)

### 9.7 Login failure modes (round-5 P2-5 — generic, enumeration-aware)

The Balanced stance accepts showing org memberships for a *valid* email (the live picker). Everything else is generic:
- **Unknown email** (0 index rows): generic "no organizations found — check your email"; rate-limited. Does not distinguish "no such email" from "email exists but you mistyped."
- **Unknown `tenant_id`** (POS/explicit): generic "sign-in failed" — **same body** as bad credentials, so slugs aren't enumerable via the API. (Subdomain slugs are public by nature; the *API* still returns generic.)
- **Suspended / Archived** tenant: 403 with a clear operator-facing message (only reachable once a valid credential pair is known).
- **PreProvisioned** (unclaimed pool): treated as sign-in failed.
- **Bad email/password** against a valid tenant: generic "invalid credentials" (401).

### 9.8 Slug (organization code) format

- Lowercase `a-z`, `0-9`, hyphens; 3–32 chars; globally unique
- Auto-suggested from company name at signup (`Str::slug()` used today at `AuthController.php:272` — note it permits input that needs normalizing to the a-z/0-9/hyphen rule); user may customize
- Format enforced at the application layer; uniqueness already enforced by the `tenants` DB index
- Examples: `acme-pharma`, `client-001`

### 9.9 Stancl middleware wiring (round-5 P1-1)

- This Laravel 12 app has **no `app/Http/Kernel.php`** — middleware is configured in **`apps/api/bootstrap/app.php:41-67`**. All v6 middleware wiring goes there.
- Identity auth routes live under the **`web` route group** at `apps/api/app/Modules/Identity/routes.php:21-43` (public pre-auth routes at `:21-40`). Any middleware/route changes must cover this group, not only the `api` group, or `/api/v1/auth/*` is missed.
- `Stancl\Tenancy\Middleware\InitializeTenancyByDomain` — used **only** for the optional subdomain web path.
- The authenticated API uses our **token-bound tenant middleware** (§9.4), not Stancl's request-data middleware.
- If a slug-based subdomain resolver is ever needed, it must `extends RequestDataTenantResolver` and be container-bound to that type (the shipped middleware constructor is typed `RequestDataTenantResolver` at `InitializeTenancyByRequestData.php:30`) — but §9.4 means we don't need it for this sprint.

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
