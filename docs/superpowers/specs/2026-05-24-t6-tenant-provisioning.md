# T6 — Tenant Provisioning & SaaS Ops (Phase 0 GATE + Phase 1+ Ops)

**Track:** T6 (P0 — Phase 0 is the **pre-sprint gate**, Phase 1+ runs parallel in Wave 1)
**Date:** 2026-05-24 (v7 — r7 Codex auth-review fixes applied: tenant-qualified verify/reset/invitation links [B1], central Sanctum PAT model [B2], identity-index full user lifecycle [P1], grep-based route coverage [P2], TenantInitializationService "extended-not-untouched" [P1]. r8 Codex spot-check = APPROVE-WITH-MINOR-EDITS; the one minor edit — naming `UserController::update` as the email-change path — is folded in.)
**Recommended workflow:** Codex throughout for migration moves + config flip + pre-warm pool jobs + backup automation. Opus design review on Stancl migration topology, pre-warm race safety, AND the email-first Identity/auth refactor (highest-risk surface per round-5).
**Estimated effort:** Phase 0 **~12–15 PD** (12 is the floor per round-6 P2-5; covers 50+ FK rewrites, reference-data tenant-side seeding, central connection, Spatie placement, phpunit/PG strategy, fiscal coordination, AND the full email-first Identity rewrite — login/register/verify/reset + central identity index + the request-time tenancy resolver built from scratch and wired across every `auth:sanctum` route surface [grep-verified, not a fixed count — r7 P2; the dominant cost driver, round-6 P1-1] + POS device-setup + web email-first UI) + Phase 1+ OPS ~7 PD = **~19–22 PD total**. Target completion in <10 calendar days via parallel Opus + Codex sessions (Phase 0 is the long pole; Phase 1+ parallelizes after the gate).
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)

---

## 1. Purpose

Current state (verified by reading code):

- `TenantInitializationService` is PRODUCTION (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:1-252`): idempotent, country-aware (Tunisia + France), seeds CoA + tax + payment methods + role assignment + trial subscription
- Stancl is wired with `PostgreSQLSchemaManager` (`config/tenancy.php:84`) but it is **effectively unused for isolation** — tenancy today is **row-level** (`tenant_id`/`company_id` columns + query scoping in one shared database; no per-schema separation actually runs, which is why the tenant-isolation sweep was needed). NOT schema-per-tenant. (Also greenfield: no production tenant data, so the flip is a clean-slate code switch with no data migration.)
- PgBouncer is wired in `docker-compose.staging.yml:124-144` (transaction mode, pool size 20, max 200 connections)
- Sentry + Horizon production, MonitoringController at `apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php` (corrected from v1 — was wrong path)
- Country seeders at `apps/api/database/seeders/Tunisia*.php` (corrected from v1 — were wrong namespace)

What's missing to ship SaaS at scale:
- **Phase 0 GATE:** flip Stancl from `PostgreSQLSchemaManager` to `PostgreSQLDatabaseManager` + move tenant-scoped migrations + verify all tests pass
- **Phase 1 OPS:** pre-warm pool (5–10 ready databases for instant signup) + backup automation + restore drill + monitoring runbook

**Phase 0 is the pre-sprint gate.** Until it merges, no other track in the sprint can write a new migration (because we don't know whether the new migration goes in `database/migrations/` or `database/migrations/tenant/`).

---

## 2. Architecture grounding (verified file paths)

Read before writing code:

1. `apps/erp/apps/api/config/tenancy.php` (line 84: `pgsql => PostgreSQLSchemaManager::class` — the flip target is `PostgreSQLDatabaseManager::class`)
2. `apps/erp/apps/api/config/tenancy.php` (lines 39–45: active bootstrappers: DatabaseTenancyBootstrapper, CacheTenancyBootstrapper, FilesystemTenancyBootstrapper, QueueTenancyBootstrapper; line 44 RedisTenancyBootstrapper commented out)
3. `apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:1-252` — production-grade init service. **Phase 0 extends it** for reference-data seeding (§3 deliverable 9 + §4 seeding subsection); its core per-country init flow is then consumed by the Phase 1 pool-claim flow. (round-5 P1-2: it IS modified in Phase 0, contrary to the v5 note here.)
4. `apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php` — `TenantWithDatabase` interface, `HasDatabase`, `HasDomains`, `HasUuids`, `getDatabaseName()` returns `tenant_{slug}`
5. `apps/erp/apps/api/app/Modules/Tenant/Domain/Enums/TenantStatus.php:1-16` — current cases: Active, Suspended, Pending, Archived. ADD `PreProvisioned` here.
6. `apps/erp/apps/api/tests/Feature/Tenant/TenantInitializationTest.php:1-125` — existing test patterns for idempotency
7. `apps/erp/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` + `TunisiaTaxConfigurationSeeder.php` + `TunisiaStampDutySeeder.php` + `TunisianParapharmacySeeder.php` + `TunisiaWithholdingRulesSeeder.php` (PRODUCTION; consume in pool-claim init)
8. `apps/erp/apps/api/config/database.php` (lines 87–103: pgsql config; line 100 `DB_PGBOUNCER` env handling triggers `PDO::ATTR_EMULATE_PREPARES => true`)
9. `apps/erp/docker-compose.staging.yml:124-144` — PgBouncer service config (transaction mode, pool 20, max 200)
10. `apps/erp/apps/api/app/Jobs/Concerns/BindsTenantContext.php` — pattern for tenant context in queue jobs
11. `apps/erp/apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php` (lines 27–93: health checks + system metrics endpoints — CORRECTED path from v1)
12. `apps/erp/apps/api/config/sentry.php` + `config/horizon.php` — production-wired monitoring
13. `apps/erp/apps/api/database/migrations/` — central migrations directory (existing)
14. `apps/erp/apps/api/database/migrations/tenant/` — **CURRENTLY MISSING** — Phase 0 creates this

**Constraints from CLAUDE.md:** hexagonal, constructor injection only, strict typing, enums for status, TDD, PHPStan level 8, table naming per `claude/database-topology.md`.

---

## 3. Phase 0 GATE — migration topology + Stancl flip (~12–15 PD)

### Deliverables (must all merge together)

**Phase 0 scope (round-5 reality check applied):** this is genuinely 2+ focused engineering weeks of work — clean-slate framing means no data migration burden, but ~50 cross-DB FK rewrites + reference-data tenant-side seeding + central connection setup + Spatie placement + phpunit/PG strategy + fiscal coordination + the full email-first Identity rewrite (§9 + deliverable 8). Phase 0 effort: ~12 PD with one focused Codex+Opus pair (round-5's independent range was 12–15 PD).

1. **Create `apps/erp/apps/api/database/migrations/tenant/` directory**
2. **Identify and move every existing migration that creates/alters a tenant table per the [migration topology contract](../coordination/2026-05-24-migration-topology-contract.md). Specifically (non-exhaustive — verify against the contract table):**
   - All inventory tables (`stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `inventory_batch_movements`, `inventory_batch_stock`)
   - All product/catalog tables
   - All document/POS receipt tables (including `pos_receipt_line_batch_allocations` — note `pos_` prefix)
   - All accounting/treasury tables
   - All partner/contact tables
   - All company/location tables
   - **`users` table** (corrected per round-2 B-2: it has `tenant_id` + `unique(tenant_id, email)`, so it's TENANT-scoped, NOT central as v1 said)
   - Spatie permission tables (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`) — verify each: if `model_id` resolves to tenant `users`, they follow to tenant DB
   - Any other tenant-scoped table
   - **⚠️ Duplicate central tables (round-6 P2-4):** two `tenant_subscriptions` migrations exist (`2025_12_01_193759` + `2025_12_16_100001`) and two plan tables (`plans` `2025_12_01_193629` + `subscription_plans` `2025_12_16_100000`). Phase 0 must confirm which is live and classify both as central (or consolidate) — the "walk every migration" audit will otherwise trip over the duplicates.
3. **Rewrite ALL tenant migrations that declare cross-DB FK to `tenants`** — syntax-independent audit (per round-3 B-1). Run BOTH:
   - `grep -rln "constrained('tenants')\|constrained(\"tenants\")" database/migrations/` (39 files)
   - `grep -rln "references('id')->on('tenants')\|references(\"id\")->on(\"tenants\")" database/migrations/` (additional files including `users`, `companies`, `product_images`)
   - Combined unique-file count: ~50 files. Each rewritten to plain `->uuid('tenant_id')->index()` (no FK — DB boundary post-flip can't enforce it). Mechanical but high-volume; ~2 PD on its own.
4. **Add the `central` connection to `config/database.php`** (per round-2 B-3 + topology contract Pattern A) — point at same Postgres host as default `pgsql`, with fixed `DB_CENTRAL_DATABASE` env var (e.g., `synerivia_central`). The default `pgsql` connection becomes the per-tenant connection (Stancl resolves it).
5. **Update `tenancy.php`:**
   - Flip `pgsql => PostgreSQLDatabaseManager::class`
   - Verify `migration_parameters` path correctly references `database_path('migrations/tenant')`
   - **Fix the duplicate `'pgsql' =>` key** — `PostgreSQLDatabaseManager` at `config/tenancy.php:72` and `PostgreSQLSchemaManager` at `:84` (last wins → Schema is effective today; round-6 S-3 corrected the line range)
6. **Test suite execution against flipped config:**
   - **Constraint (per round-2 B-4):** existing `phpunit.xml:40` pins SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). SQLite cannot host the partial-unique-index strategy and cannot exercise PostgreSQLDatabaseManager. The flip integration test MUST run against real Postgres.
   - **Option A:** Add a `phpunit-pgsql.xml` config + CI run on every PR (not just PR→main). The existing `backend-test-pgsql` is a **job inside `ci.yml`** (line ~275, gated `github.base_ref == 'main'`), NOT a standalone workflow (round-6 S-2); reconfigure it to include PR→dev.
   - **Option B:** Make the existing test suite optionally Postgres via env override + run the full suite against PG in CI.
   - Phase 0 PR MUST run the chosen option's PG test suite + show green before merge.
7. **Add Stancl flip integration test (PG-only):** create tenant → verify new database exists → verify tenant migrations ran inside it → verify central tables untouched → verify Pattern A `DB::connection('central')->table('plans')->find($id)` works from inside a tenant context
8. **Rewrite the ENTIRE Identity pre-auth surface for email-first multi-tenant (per round-5 B-1 + topology §9):** the current `AuthController` assumes a global `users` table across login, register, check-email, verify-email, forgot-password, and reset-password. Moving `users` tenant-side breaks ALL of them, not just login/register. The v6 architecture (topology contract §9) is **email-first + central identity index**. The rewrite:
   - **Central identity index:** create `central_identities` (central DB) per topology §9.1 — `email`, `tenant_id` (UUID, no FK), `user_id` (nullable, informational), `unique(email, tenant_id)`. Pointers only, no credentials. Add a `tenant:reconcile-identities` command to backfill/repair.
   - **Index maintenance — full user lifecycle, not just register (r7 P1):** introduce an `IdentityIndexService` (central) that is the single writer of `central_identities`, invoked from **every** path that creates/changes/removes a tenant user — `AuthController::register`, **`UserController::store` (admin-invited users — `Identity/Presentation/Controllers/UserController.php:171-184`)**, email change via **`UserController::update`** (`UserController.php:232-279`), and **`UserController::destroy` deactivation (`:340-346`)**. If only `register` writes the index, invited users never appear in it and cannot do email-first login / org recovery / tenant-qualified reset. Acceptance must cover invited-user login and deactivation.
   - **`login` (email-first):** `POST /api/v1/auth/login`. Web: enter email → look up `central_identities` → 1 tenant continues, >1 returns the org picker list (Balanced), 0 returns a generic message (§9.7). POS/mobile: body carries the device-cached `{tenant_id, email, password}`. In all cases resolve tenant → `tenancy()->initialize($tenant)` → validate against `users` in-tenant. `LoginRequest` (`Identity/Presentation/Requests/LoginRequest.php:25-35`) gains an optional `tenant_id`.
   - **`register`:** create the central `tenants` row + the `domains` row (`domain = "{slug}.synerivia.tn"`; previously only `CreateTenantCommand:86-94` did this) + a `central_identities` row, then `tenancy()->initialize()` and create the first `users` row INSIDE the tenant DB (current register does it all on the default connection — `AuthController.php:264-389`).
   - **Remove `check-email`:** delete the global `AuthController::checkEmail` (`:422-427`) and remove the **whole debounce-validation block** in `apps/web/src/features/auth/components/AccountStep.tsx` (the mutation `:23-29`, its `onSuccess` `emailCheckError` handler, the `emailCheckError` state `:22`, the debounce ref, and the `.mutate()` call + dep array `:52,:56`) — not just one line (round-6 S-1; confirmed the only caller across `apps/web` + `apps/pos`). Email uniqueness is per-tenant (`users.unique(tenant_id,email)`); collision surfaces at register submit.
   - **Tenant-qualify every emailed link (r7 B1 — token-only links cannot pick a tenant DB):** verification, password-reset, AND user-invitation links currently carry only `?token=` (+`email`) — see `VerifyEmailNotification.php:39-45`, `ResetPasswordPage.tsx:25-55`, `UserInvitation.php:48-56`. After the token tables move tenant-side, a token-only link cannot know which tenant DB to open, and the same email may exist in multiple tenants. **Mechanism (chosen, mandatory): embed a signed `tenant_id` in all three link types** (Laravel signed URL or an equivalent tamper-proof param), so the pre-auth resolver initializes that tenant BEFORE the token lookup. This is the single mechanism — do NOT also add `tenant_id` columns to the token tables. Files to update: `VerifyEmailNotification`, `UserInvitation`, the forgot-password link builder, `VerifyEmailRequest` (`:25-29`), `ResetPasswordRequest` (`:21-28`), and the React `ResetPasswordPage` / verify-email pages to round-trip the qualifier.
   - **`verify-email`:** link carries the signed `tenant_id` → resolver `tenancy()->initialize()` → look up `EmailVerificationToken` + user in-tenant (`EmailVerificationService.php:39-60`). Reclassify `email_verification_tokens` tenant-side.
   - **`forgot-password` / `reset-password`:** forgot-password resolves tenant (email + org pick if >1) and issues a reset link **carrying the signed `tenant_id`**; on click, resolver `tenancy()->initialize()` BEFORE invoking the Laravel `Password` broker (`config/auth.php:73-77,103-107`), which then binds to the tenant connection automatically (no custom broker class IF tenancy is initialized first — validate). Reclassify `password_reset_tokens` tenant-side. Generic "if registered, we've sent a link" responses (§9.7).
   - **Build the request-time tenancy resolver from scratch (round-6 P1-1 — the dominant cost driver):** there is NO request-time tenancy layer today — `tenancy()->initialize()` is called only in `ResetTenantCommand.php:75` (console). Phase 0 writes a new middleware that runs **before `auth:sanctum`**, resolves the tenant, calls `tenancy()->initialize()`, and is registered on the global `api` group + the Identity `web` group in `bootstrap/app.php` — covering **every `auth:sanctum` route surface**, not just `/api/v1/auth/*`. **Do NOT rely on a fixed file count (r7 P2): there are MORE than 11** — root `routes/api.php:49`, Product, POS, Document, Accounting, and other route-provider-loaded modules. Acceptance is **grep-based** (below), not "audit 11 files." After the flip, any authenticated route that loads a tenant-DB User without prior tenancy init breaks.
   - **Tenant source, two branches (round-6 P1-2 + P2-1 — reuse, don't duplicate):** (a) **bearer** (POS/mobile/API): read the existing `tenant:<uuid>` ability already minted on every token (`AuthController.php:222,379`) from the **central** `personal_access_tokens` table; (b) **web SPA cookie**: `Auth::attempt()`+session means a `TransientToken` with NO PAT row (`AuthController.php:186`) — so **stamp tenant_id in the session at login** and read it in the resolver's cookie branch. Keep the existing `EnforceTokenTenantClaim` (`Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`) as the *post-auth* guard; the new middleware is the *pre-auth* resolver. Single source of truth for a bearer request = the `tenant:<uuid>` ability (no new `personal_access_tokens.tenant_id` column).
   - **Pin Sanctum's PAT model to the central connection (r7 B2 — otherwise `auth:sanctum` reads PAT from the WRONG DB):** the pre-auth resolver reads the bearer token from central to extract the tenant claim and inits tenancy — but `auth:sanctum` then runs its OWN `PersonalAccessToken::findToken()`, which after tenancy init uses the **default (now tenant)** connection and misses the central PAT row. Phase 0 MUST add `CentralPersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken` with `protected $connection = 'central';`, register it via `Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class)` (in a service provider), and add a PG integration test asserting bearer auth reads the PAT row from **central** while the `tokenable` `User` resolves in **tenant** context. Without this, all bearer auth breaks after the flip.
   - **Middleware wiring (round-5 P1-1):** this Laravel 12 app has NO `app/Http/Kernel.php` — wire all middleware in `apps/api/bootstrap/app.php:41-67`. Identity auth routes are under the **`web` route group** (`Identity/routes.php:21-43`); changes must cover that group AND all other module groups, not only `api`, or authenticated routes miss tenancy init.
   - **Subdomain (optional):** keep `InitializeTenancyByDomain` for the `{slug}.synerivia.tn` shortcut only.
   - **Clients:** POS first-time setup captures the org-code once (device-bound, §9.3); cashiers then log in with email/PIN. Web login screen becomes email-first with an org picker + "Find my organization" (emails the list).
   - **Estimated effort:** 5-7 PD on its own (controller + central index + broker reconfig + token-bound middleware + tests + POS device-setup flow + web email-first login UI + register flow update).
9. **Reference-data tenant-side seeding — IN PHASE 0 (per round-5 P1-2; resolves the round-4 P1-3 contradiction):** the reference tables (`countries`, `country_tax_rates`, `country_payment_settings`, `tax_configurations`) move tenant-side as part of the migration moves above, so the seeders MUST be ready to populate them at the first claim — this is Phase 0 work, not Phase 1A. **Phase 0 extends** `TenantInitializationService::initializeForNewRegistration` to seed `countries` FIRST (currently tax config no-ops if `countries` is empty — `TenantInitializationService.php:207-214`), then `country_tax_rates` + `country_payment_settings`, then the existing per-country seeders. (Full detail in §4 "Reference-data seeding" — that subsection is Phase 0 scope despite living near the Phase 1 ops text.)
10. **Coordinate with in-flight fiscal Phase 1 (per round-2 R2-B1 + R2-P1-2 + B-5):**
   - Fiscal Phase 1 has already committed 3 migrations at `database/migrations/2026_05_14_100001_*`, `..._100002_*`, `..._100003_*`. These ARE tenant-scoped (`fiscal_events`, `fiscal_events_immutability_*`, `fiscal_event_projections`). **They MUST be moved into `database/migrations/tenant/` as part of the Phase 0 PR.**
   - Fiscal Phase 1's still-pending Tasks 11 / 12 / 21 / 22 produce more migrations against tenant tables. **The fiscal session MUST pause new migration work during the Phase 0 PR window.** After Phase 0 lands, fiscal Phase 1 resumes targeting `database/migrations/tenant/` directly.
   - Get explicit sign-off from the fiscal session owner before the Phase 0 PR opens for merge.
11. **Document gate completion:** write `apps/erp/docs/superpowers/coordination/2026-05-24-t6-phase0-gate-complete.md` so other tracks know it landed
12. **PR title prefix:** `[T6-PHASE0-GATE]` so reviewers know this blocks everything

### Acceptance criteria

- [ ] `database/migrations/tenant/` exists
- [ ] All tenant-scoped existing migrations moved into it (verify via `grep -r "tenant_id\|company_id" database/migrations/` returning empty for non-platform tables)
- [ ] `tenancy.php` flipped to `PostgreSQLDatabaseManager`
- [ ] `composer test` and `./vendor/bin/phpstan` clean
- [ ] New integration test passes: `TenantStancl FlipTest::test_creates_tenant_database_and_runs_tenant_migrations`
- [ ] No FK in any tenant migration points to a central table. **Syntax-independent audit (round-4 S-1 / round-5):** both `grep -rn "constrained('tenants')\|constrained(\"tenants\")"` AND `grep -rn "references('id')->on('tenants')\|references(\"id\")->on(\"tenants\")"` over `database/migrations/tenant/` return zero hits (also check `plans`, `domains`).
- [ ] **Identity surface (round-5 B-1):** `central_identities` created; `check-email` removed (route + `AccountStep.tsx` call); `email_verification_tokens` + `password_reset_tokens` migrations moved to `tenant/`; PG integration tests green for register (creates tenant + domains + identity rows + in-tenant user), email-first login (1-tenant + multi-tenant picker + unknown-email generic), token-authenticated request resolves tenant from the bound token (no `X-Tenant-ID` sent), verify-email in-tenant, forgot/reset password in-tenant.
- [ ] **Middleware wired in `bootstrap/app.php`** (not `app/Http/Kernel.php`), covering the Identity `web` route group.
- [ ] **Route coverage is grep-verified, not count-based (r7 P2):** every `auth:sanctum` usage (`grep -rn "auth:sanctum" apps/api/app apps/api/routes`) sits under a group that runs the pre-auth tenancy resolver first; no authenticated surface is left without tenancy init.
- [ ] **Tenant-qualified links (r7 B1):** verify-email, password-reset, and user-invitation links each embed a signed `tenant_id`; `VerifyEmailRequest`/`ResetPasswordRequest`/notifications/React pages round-trip it; PG tests prove a clicked link initializes the correct tenant before the (tenant-side) token lookup, for a multi-tenant email.
- [ ] **Central Sanctum PAT model (r7 B2):** `CentralPersonalAccessToken` (`$connection='central'`) registered via `Sanctum::usePersonalAccessTokenModel`; PG test asserts bearer auth reads the PAT from central while `tokenable` resolves in tenant context.
- [ ] **Identity index full lifecycle (r7 P1):** `IdentityIndexService` writes `central_identities` from register **and** `UserController::store` (invited) / `UserController::update` (email change) / `destroy`; PG tests cover invited-user email-first login and deactivation.
- [ ] Gate-complete marker file created and merged

### Adversarial review checklist (Phase 0)

**Reviewer instruction:** *"Verify against actual code at cited paths. Pay special attention to: (1) every migration in `database/migrations/` (not tenant/) — is it genuinely central? (2) the Stancl flip — does the test ACTUALLY create a new database or is it just running schemas? (3) cross-DB FK check — are there ANY FK declarations in tenant migrations pointing at central tables like `tenants`, `domains`, `plans`?"*

- [ ] Walk every file in `database/migrations/` — confirm it's central per the topology contract
- [ ] Walk every file in `database/migrations/tenant/` — confirm tenant-scoped per the contract
- [ ] Open `tenancy.php` after flip — confirm `pgsql => PostgreSQLDatabaseManager::class`
- [ ] Run the flip integration test in CI — confirm it actually opens a new connection to the new DB
- [ ] Grep all tenant migrations for `->constrained('tenants')`, `->constrained('plans')`, `->constrained('domains')` — should return zero hits
- [ ] Confirm `BindsTenantContext` trait still works with the new manager
- [ ] Confirm Horizon + Sentry breadcrumbs still capture tenant context

---

## 4. Phase 1 OPS — pre-warm pool + backup + monitoring (~7 PD)

Runs in Wave 1 (parallel with all other Wave 1 tracks), AFTER Phase 0 gate merges.

### Expand TenantInitializationService seeding — ⚠️ PHASE 0 WORK (detail for §3 deliverable 9)

**Phase placement (round-5 P1-2 — contradiction fully resolved):** this subsection is documented here next to the ops text for continuity, but it is **Phase 0 scope** — it is listed as Phase 0 deliverable 9 in §3, and its acceptance is part of the Phase 0 gate. It cannot be deferred to Phase 1A: the reference-data tables move tenant-side during Phase 0's migration moves, so the seeders must be ready to populate them at the first claim immediately after the flip. Every "extends" below means **Phase 0 extends**.



Current `TenantInitializationService` (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:14-19, 53-69, 138-148, 205-223`) imports and calls: `TunisiaChartOfAccountsSeeder`, `FranceChartOfAccountsSeeder`, `GenericChartOfAccountsSeeder`, `PaymentMethodSeeder`, `PaymentRepositorySeeder`, `TunisiaTaxConfigurationSeeder`. **It does NOT call `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, `TunisiaWithholdingRulesSeeder`** despite all three existing as seeder files.

Phase 0 extends `TenantInitializationService::initializeForNewRegistration` to:
1. Seed `countries` table FIRST (new — currently tax config no-ops if `countries` empty per round-3 P1-3 evidence at TenantInitializationService.php:207-214)
2. Seed `country_tax_rates` + `country_payment_settings`
3. Then call existing per-country seeders (CoA, tax config, payment methods)
4. ADD calls to `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, `TunisiaWithholdingRulesSeeder` when country=TN
5. Idempotency preserved (each seeder uses `updateOrCreate` or equivalent)

Acceptance: Tunisian signup produces tenant with COMPLETE TN compliance (CoA + TVA + stamp duty + withholding rules + parapharmacy SKU seeds if applicable).

### Pre-warm pool

**Schema changes (central DB):**
- `TenantStatus` enum: ADD `PreProvisioned` case
- `tenants` table: ADD `is_pre_warmed` (bool, default false), `pre_warmed_at` (timestamp, nullable), `claimed_at` (timestamp, nullable)

**Services:**

```php
TenantPreWarmService
  ::ensurePool(int $minSize = 5, int $maxSize = 10): int  // returns count newly created
  ::list(): Collection<Tenant>  // pre-warmed available

TenantClaimService
  ::claim(ClaimTenantCommand): Tenant  // assigns pre-warmed tenant to user; runs TenantInitializationService::initializeForNewRegistration with country-specific seeders
  ::createOnDemand(CreateTenantCommand): Tenant  // fallback when pool empty
```

**Race safety:** claim operation uses `SELECT FOR UPDATE` on a pre-warmed tenant row + atomic transition `PreProvisioned → Active`. No two concurrent claims can grab the same pool tenant.

**Commands:**
- `php artisan tenant:ensure-pool` — scheduled hourly + invoked from signup post-claim hook

### Backup automation

**Service:**

```php
BackupService
  ::backupTenant(UUID $tenantId): BackupResult  // pg_dump via direct connection (bypass PgBouncer for multi-statement safety) + S3 upload
  ::backupAll(): Collection<BackupResult>
  ::restore(UUID $tenantId, string $backupId): RestoreResult  // requires --confirm flag; defaults to dry-run
  ::listBackups(UUID $tenantId): Collection<BackupMetadata>
```

**Direct DB connection for backups:** add an alternate `pgsql_direct` connection in `config/database.php` that bypasses PgBouncer (per topology contract section 6). Backup + restore + migrations use this connection.

**Naming:** `tenant-backups/{tenant_id}/{YYYY-MM-DD-HHmmss}.dump`
**Retention:** daily for 30 days, weekly for 12 weeks, monthly for 12 months
**Failure alerts:** via existing Sentry channel
**Metrics:** backup size + duration via existing Horizon dashboard

**Commands:**
- `php artisan tenant:backup-all` — scheduled daily 02:00
- `php artisan tenant:restore {tenant_id} {backup_id}` — manual, --confirm required

### Monitoring + runbook

- Pool low-watermark alert wired to Sentry
- New super-admin dashboard widget: pool status (current size, recent claims, recent creates)
- New super-admin dashboard widget: backup audit log (last backup per tenant, success/failure, retention status)
- `apps/erp/docs/runbooks/2026-05-24-saas-onboarding.md` — first-tenant manual checklist
- `apps/erp/docs/runbooks/2026-05-24-tenant-restore.md` — disaster recovery runbook
- Restore drill: spin up clone tenant in staging, full restore in < 10 minutes

---

## 5. Generic-ness checklist

- [ ] Zero client names anywhere
- [ ] Pool size configurable via env (`TENANT_POOL_MIN`, `TENANT_POOL_MAX`)
- [ ] Backup S3 bucket + retention configurable via env (`BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, retention policy)
- [ ] Country-specific init handled by existing `TenantInitializationService` — adding new country = new seeder, no code change
- [ ] Pre-warm works for any country (template DB country-agnostic; country-specific seeding at claim time)
- [ ] PgBouncer bypass rule documented + enforced via separate connection

---

## 6. Acceptance criteria (Phase 1+ ops, after gate)

### Pre-warm pool

- [ ] `tenant:ensure-pool` creates pre-warmed tenants up to configured max
- [ ] Pre-warmed tenants have empty databases with all tenant migrations run, but no company/user/init data
- [ ] `TenantClaimService::claim` claims a pre-warmed tenant + runs country-specific init within < 3 seconds
- [ ] When pool exhausted, fallback creates on-demand + emits warning metric
- [ ] Pool replenishment triggered on signup (post-claim hook) AND scheduled hourly
- [ ] Concurrent claim test: two simultaneous claims grab two different pool tenants, not the same one

### Backup automation

- [ ] `tenant:backup-all` runs pg_dump per tenant via `pgsql_direct` connection
- [ ] Uploads to S3 with correct naming convention
- [ ] Retention policy applied (daily 30d, weekly 12w, monthly 12m)
- [ ] Failure alerts fire via Sentry
- [ ] Metrics visible in Horizon dashboard

### Restore drill

- [ ] `tenant:restore` against clone tenant in staging — full restore < 10 minutes
- [ ] Dry-run mode is default
- [ ] `--confirm` flag required to actually restore
- [ ] Runbook documented at `apps/erp/docs/runbooks/2026-05-24-tenant-restore.md`

### PgBouncer compatibility

- [ ] `DB_PGBOUNCER=true` in staging — confirm `PDO::ATTR_EMULATE_PREPARES => true` applied
- [ ] All integration tests pass against PgBouncer-fronted connection
- [ ] Direct connection (`pgsql_direct`) bypasses PgBouncer for backup/restore/migrations
- [ ] Max pool sizing recommendation documented per tenant volume

### Tests

- [ ] Feature test: pre-warm pool creation, claim, fallback on empty pool, concurrent claim safety
- [ ] Feature test: backup happy path against dockerized PG
- [ ] Feature test: restore happy path
- [ ] Stancl flip integration test passes (added in Phase 0)

---

## 7. Adversarial review checklist (Phase 1+ ops)

**Reviewer instruction:** *"Verify findings against actual code. Pay special attention to: (1) pool race condition — what happens with 50 concurrent signups vs. a 10-tenant pool? (2) backup safety — does pg_dump actually run against the per-tenant DB or the central one? (3) restore safety — is it impossible to accidentally restore over a live tenant's data?"*

- [ ] **Race condition under load:** test 50 concurrent claim requests against a 10-tenant pool — verify only 10 claims succeed + 40 fall back to on-demand creation; no duplicates, no exceptions
- [ ] **Backup tenant context:** trace `BackupService::backupTenant($tenantId)` — confirm it binds tenant context via `tenancy()->initialize($tenant)` and pg_dump targets the right database
- [ ] **Restore destination:** confirm restore can NEVER overwrite a different tenant's DB; dry-run default enforced
- [ ] **Secrets:** backup S3 credentials are env vars; never logged in Sentry or Horizon
- [ ] **PgBouncer interaction:** verify backup/restore go through `pgsql_direct`, not the pooled connection
- [ ] **Pre-warm idempotency:** running `tenant:ensure-pool` twice in 5 seconds doesn't double-create tenants
- [ ] **Country-specific init at claim:** Tunisia signup correctly seeds TN CoA + TVA + stamp duty; France signup seeds FR CoA; unknown country uses generic fallback
- [ ] **Stancl event handling:** when tenant deleted (rare), DB drops cleanly
- [ ] **Performance:** pool replenishment uses queue jobs; doesn't block signup request thread
- [ ] **Monitoring:** pool low-watermark alerts actually fire when pool < min

---

## 8. Out of scope

- Region-aware backup (multi-region S3) — single region for now
- Cross-region disaster recovery — defer
- GDPR data portability / right-to-be-forgotten — defer
- Multi-tenant database sharding (one PG cluster per env) — defer
- Tenant plan-change migration flows — defer (existing subscription module handles)
- Custom super-admin dashboard tech decision (React vs Filament) — parked

---

## 9. Reading order

1. This spec end to end
2. Migration topology contract: `apps/erp/docs/superpowers/coordination/2026-05-24-migration-topology-contract.md`
3. `apps/erp/CLAUDE.md` + `claude/deploy-runbook.md`
4. The 14 file paths in Section 2
5. Stancl/tenancy v3 docs for `PostgreSQLDatabaseManager` semantics
6. Memory: `project_production_setup.md` for Dokploy/AX42 deployment context

---

## 10. Workflow recommendation

**Phase 0 (Codex with Opus review, ~12–15 PD):** Migration moves + 50+ FK rewrites (syntax-independent) + Stancl flip + central connection + reference-data tenant-side seeding + Spatie classification + phpunit/PG strategy + fiscal coordination + the full email-first Identity rewrite (login/register/verify/reset + `central_identities` + the request-time tenancy resolver built from scratch and wired across every `auth:sanctum` route surface [grep-verified per r7 P2; bearer-ability + web-session branches] + remove `check-email`) + create `domains` row at signup + POS device-setup org-code capture + web email-first login UI. Flip test + gate marker. Codex executes mechanically; **Opus reviews migration classification + the entire Identity/auth refactor** (the highest-risk surface per rounds 5–6).

**Phase 1A (Codex, ~3 PD):** Pre-warm pool — TenantStatus::PreProvisioned + columns + `TenantPreWarmService` + `TenantClaimService` + `tenant:ensure-pool` command + concurrent claim test.

**Phase 1B (Codex, ~3 PD):** Backup automation — `BackupService` + `pgsql_direct` connection + S3 integration + retention logic + restore command + dry-run mode + restore drill.

**Phase 1C (Codex, ~1 PD):** Runbooks + super-admin dashboard widgets + tests + i18n.

Adversarial review per phase: chunked Codex headless (each phase < 800 LOC).

---

## 11. Coordination notes

- **Phase 0 BLOCKS** every other track that writes migrations. Phase 0 must merge before T1, T2, T3, T4 ship any migration.
- **Phase 1+** runs in parallel with other Wave 1 tracks; no POS touch needed
- **Reuses:** Sentry, Horizon, existing `DB_PGBOUNCER` config wiring, country seeders at `database/seeders/`. ⚠️ **`TenantInitializationService` is EXTENDED in Phase 0 (r7 P1), NOT "untouched"** — deliverable 9 adds countries / country_tax_rates / country_payment_settings + TN compliance seeders so a freshly-claimed tenant DB has complete tax config at first signup.
- **Owns:** tenant migrations directory structure (other tracks place migrations in `database/migrations/tenant/` if tenant-scoped, `database/migrations/` if central — see contract)
- **No Tauri POS changes**
