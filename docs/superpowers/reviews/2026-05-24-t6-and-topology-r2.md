# Adversarial Review (Round 2) — T6 + Topology Contract
## Reviewer: Claude general-purpose
## Date: 2026-05-24

## Verdict (per document)
- Migration Topology Contract: **NEEDS-REVISION**
- T6 Tenant Provisioning: **NEEDS-REVISION**

## Summary

Both documents materially improve on the round-1 reviewed v1 specs: file paths are now correct, the topology constraint is explicit, and Phase 0 has been carved out as a true gate. The round-1 BLOCKER B-3 ("DB-per-tenant flip collides with every tenant migration") is partially addressed by formalizing the Phase 0 gate, but the actual mechanical work of the flip is materially under-specified. P1-6 (wrong paths) is resolved. P2-4 (effort estimate) is moved from 7 PD to 10 PD but is still optimistic given what I uncovered.

The single biggest gap is this: **moving migration files is not the flip.** There are 40+ tenant migrations that declare `->constrained('tenants')` and 1,705 references to `tenant_id` across the codebase, including FK constraints to the central `tenants` table from tables like `users`, `accounts`, `products`, `partners`, etc. When `PostgreSQLDatabaseManager` runs those migrations inside the freshly-created tenant DB, every `->constrained('tenants')` will FAIL because the `tenants` table does not exist there. The Phase 0 spec says "move the files" but does not say "and rewrite 40 migration files to drop the central FKs and keep `tenant_id` as a bare UUID column." Without that explicit list, the agent will either (a) move files and watch tests fail catastrophically, or (b) silently invent a fix that produces silently incorrect schemas. Either path corrupts the topology contract's "constitutional" promise.

Second, both docs have unaddressed architectural decisions that bite at first run: (1) the `users` table currently holds per-tenant users with `unique(tenant_id, email)` and FK to central `tenants` — the contract classifies `users` as central but does not say what happens to the per-tenant users that obviously can't live there; (2) the `central` connection that `tenancy.php:51` and the contract Section 4 Pattern A both reference does not actually exist in `database/connections` (the default is `pgsql`); (3) `phpunit.xml:40` pins `DB_CONNECTION=sqlite` and `backend-test-pgsql` only runs on PR→main — so the flip integration test that the spec promises cannot run on PR→dev where Phase 0 will land. None of these are showstoppers individually, but together they mean the agent cannot execute Phase 0 from the spec alone without making non-trivial design calls.

These docs are close. They need a concrete migration-rewrite checklist, an explicit per-tenant-vs-central decision for `users`, an explicit `central` connection definition (or rename), and a CI gate for the flip test before they can be marked execute-ready.

## Findings

### BLOCKER

#### [B-1] Phase 0 does not specify what to do with `->constrained('tenants')` and 40 other central-FK references in tenant migrations
**Where:** T6 spec §3 "Phase 0 GATE", item 2; Topology Contract §3
**Claim:** "Move those migrations into `database/migrations/tenant/` (preserve filenames + timestamps)" — the implication is that the moves are mechanical.
**Reality:** I ran `grep -rn "->constrained('tenants')" apps/api/database/migrations/` and got **40 hits**. Every one of these is in a table the topology contract classifies as tenant-scoped (e.g. `2025_11_30_080002_create_document_sequences_table.php:15`, `2025_11_30_090000_create_accounts_table.php:15`, `2026_01_05_150000_create_product_batches_table.php:18`, `2025_11_30_052119_create_partners_table.php:18`, etc.). When `PostgreSQLDatabaseManager::createDatabase()` creates an empty tenant DB and Stancl runs the tenant migrations inside it, `->constrained('tenants')` will fail because the `tenants` table only exists in the central DB. The spec's "move files" step is therefore a no-op at best and a Phase 0 test failure at worst.

In addition, `2025_11_30_110000_create_inventory_tables.php:18,34` declares `foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete()` on both `stock_levels` and `stock_movements`; `2025_11_30_052119_create_partners_table.php:18` does the same for partners; `2025_11_30_090000_create_accounts_table.php:15` for accounts; etc. Each FK must be DROPPED and the column kept as a bare UUID per the contract (§1, §4 Pattern D). That's 40 migration files to rewrite — none of which is listed in the Phase 0 deliverables.

**Impact:** The Phase 0 PR will either fail loudly on the flip integration test (best case — the agent stops and asks) or silently apply mismatched schemas (worst case — the agent invents a `Schema::dropForeign` strategy with no consensus, leaving tenant DBs internally inconsistent for the rest of the sprint).
**Suggested fix:** Add a concrete deliverable to Phase 0:
> "For each of the 40 tenant migrations that declare `->constrained('tenants')` or `->constrained('users')` where the constraint targets a central table per the topology contract, REWRITE the migration to declare the column as a bare `$table->uuid('tenant_id')` (or `nullable()->index()` per Pattern D) WITHOUT the FK constraint. Enumerate the 40 files in this PR's description and audit each move."

The list of 40 files I found (each must be reviewed; this is just from `->constrained('tenants')`):
```
2025_11_30_052119_create_partners_table.php
2025_11_30_052910_create_products_table.php
2025_11_30_070000_create_vehicles_table.php
2025_11_30_080002_create_document_sequences_table.php
2025_11_30_090000_create_accounts_table.php
2025_11_30_100000_create_journal_entries_table.php
2025_11_30_110000_create_inventory_tables.php  (BOTH stock_levels AND stock_movements)
2025_12_01_201012_create_price_lists_table.php
2025_12_12_120000_create_service_categories_table.php
2025_12_12_120001_create_services_table.php
2025_12_14_000001_create_document_attachments_table.php
2026_01_05_150000_create_product_batches_table.php
2026_01_08_190429_create_pos_terminals_table.php
2026_01_09_095045_create_units_table.php
2026_01_09_111456_create_sales_withholding_tracking_table.php
2026_02_20_100001_create_menus_table.php
2026_03_02_200003_create_coupons_table.php
2026_04_19_100001_create_vehicle_ownership_history_table.php
2026_04_19_100002_create_vehicle_mileage_readings_table.php
2026_04_19_110001_create_workshop_service_bundles_table.php
2026_04_19_110003_create_workshop_service_bundle_vehicle_applicabilities_table.php
2026_04_19_120001_create_workshop_technician_profiles_table.php
2026_04_19_120004_create_workshop_technician_time_off_table.php
2026_04_19_130002_create_workshop_work_order_lines_table.php
2026_04_19_130006_create_workshop_work_order_sequences_table.php
2026_04_19_140002_create_scheduling_configs_table.php
2026_04_19_140005_create_scheduling_appointment_status_transitions_table.php
2026_04_19_140006_create_scheduling_appointment_sequences_table.php
... (and 12 more found via grep, including the `.bak` files which should be deleted not moved)
```
Plus all tables with `->constrained()` (no explicit target) where the inferred target is `tenants` (e.g. `2025_11_30_052910_create_products_table.php:18`, `2025_11_30_090000_create_accounts_table.php:15`).

#### [B-2] `users` table classification is unresolved; topology contract is internally inconsistent
**Where:** Topology Contract §2 row 4 (central tables) — `users (central admin only) - Auth - Super-admin users that span tenants`
**Claim:** `users` lives in central, holding only super-admin / cross-tenant users.
**Reality:** The actual `users` migration (`apps/api/database/migrations/2025_11_30_000003_create_users_table.php:18-43`) creates a table that:
- Has `tenant_id UUID NOT NULL` with `->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade')`
- Has `unique(['tenant_id', 'email'])` — clearly designed for per-tenant users
- Has 1,705 references to `tenant_id` across the codebase (including login flows, JWT scoping, RBAC seeding, observers)

Separately, `super_admins` is its own central table (`2025_12_01_194614_create_super_admins_table.php`).

So `users` is **not** what the contract claims it is. It's the per-tenant users table. Post-flip there are two paths and the contract doesn't pick one:
1. Move `users` to tenant DBs (each tenant has its own `users` with their own emails, RBAC, etc.). The Sanctum personal_access_tokens, Spatie role tables, etc. must be classified separately for central (super-admin role catalog) vs tenant (per-tenant role assignments).
2. Keep `users` central, change `unique(tenant_id, email)` semantics, but then how does per-tenant RBAC + Sanctum work?

Either choice has large downstream impact (auth flow, RBAC seeding in `TenantInitializationService`, the `TenantObserver` token-revocation chunked query, the BindsTenantContext trait, every `apiGet`/`apiPost` test).

**Impact:** Phase 0 cannot ship without resolving this. If `users` is in `database/migrations/` after Phase 0 ("central"), every tenant DB created post-flip has no `users` table and login breaks. If `users` is in `database/migrations/tenant/`, then the central super-admin login has no `users` table and the super-admin dashboard breaks.
**Suggested fix:** Explicitly decide in the topology contract:
- Per-tenant `users` (with tenant_id stripped of the FK per Pattern D, in tenant migrations).
- Central `central_users` or `super_admins` table only for super-admins.
- Auth middleware switches connection based on subdomain → tenant ID resolution → tenant DB user lookup.

Then enumerate the migration moves for `users`, `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` (currently classified as central in the contract; some of these are per-tenant RBAC assignments per Spatie's team-mode setup with `setPermissionsTeamId($user->tenant_id)` in `TenantInitializationService.php:123`).

#### [B-3] `central` connection referenced in contract and tenancy.php does not exist in `database/connections`
**Where:** Topology Contract §4 Pattern A example: `DB::connection('central')->table('plans')->find($planId)`; `apps/api/config/tenancy.php:51` (`'central_connection' => env('DB_CONNECTION', 'central')`)
**Claim:** Implicit: there is a `central` connection that bridges to the central DB after the flip.
**Reality:** `apps/api/config/database.php:33-119` defines `sqlite`, `mysql`, `pgsql`, `sqlsrv`. There is **no `central` connection**. `apps/api/.env.example:1` sets `DB_CONNECTION=pgsql`, so `env('DB_CONNECTION', 'central')` resolves to `pgsql`, and the Stancl bootstrapper happily picks `pgsql` as the central template (verified at `vendor/stancl/tenancy/src/Database/DatabaseManager.php:54` and `vendor/stancl/tenancy/src/DatabaseConfig.php:104`).

After the flip, when Stancl swaps the default connection from `pgsql` (central role) to the per-tenant connection (also `pgsql`-derived but with `database` set to `tenant_{slug}`), code that wants to write to the central DB cannot just do `DB::connection('central')->...` because no such connection exists. The Pattern A example in the contract will fail with `InvalidArgumentException: Database connection [central] not configured.`

**Impact:** The contract's prescribed pattern for cross-DB reads (which T3 channel routing, T6 pool claim, T11 partner-price-list resolution all need) is broken from day 1.
**Suggested fix:** Either:
- (a) Add a true `central` connection to `database/connections` that is a duplicate of `pgsql` but never gets swapped by Stancl's bootstrapper, and reference it via `DB::connection('central')->...`. Phase 0 must add this connection BEFORE the flip.
- (b) Document that the central connection name is `pgsql` and update Pattern A example to `DB::connection(config('tenancy.database.central_connection'))->...` — but this is brittle because after the bootstrapper swap, `pgsql` *is* the tenant connection.
- Option (a) is correct. Spell it out in the Topology Contract §4 and add to Phase 0 deliverables.

#### [B-4] Phase 0 flip integration test cannot run in the CI pipeline that gates PR→dev
**Where:** T6 spec §3 item 5 — "Add Stancl flip integration test"; §6 — `Stancl flip integration test passes (added in Phase 0)`
**Claim:** A new integration test creates a tenant + verifies new database exists + verifies tenant migrations ran inside it.
**Reality:** `apps/api/phpunit.xml:40` pins `<env name="DB_CONNECTION" value="sqlite"/>` for the default test suite. The Postgres-fronted test job (`.github/workflows/ci.yml:233 backend-test-pgsql`) only runs on `PR→main`, `push→main`, or `workflow_dispatch` (line 241 `if:` clause). PR→dev runs the SQLite suite only.

A flip test must (a) call `PostgreSQLDatabaseManager::createDatabase()` which does `CREATE DATABASE ... WITH TEMPLATE=template0` — this is a cluster-level Postgres operation that SQLite simply cannot perform, and (b) run inside a Postgres test infrastructure with sufficient privileges. The current `backend-test-pgsql` runs with `POSTGRES_USER=autoerp` but `autoerp` is the database owner only of `autoerp_test` — it may or may not have `CREATEDB` privilege.

**Impact:** Phase 0 PR lands on `dev` where the flip test is silently skipped. The test only runs when promoting `dev → main`, at which point Phase 0 is already merged. By then, downstream tracks (T1, T2) have written tenant migrations into the new directory based on the unverified assumption that the flip works.
**Suggested fix:** Spec must add:
1. A new CI job (or extend `backend-test-pgsql` filter) that runs the flip test on PR→dev for `apps/api/database/migrations/**` or `apps/api/config/tenancy.php` changes.
2. The Postgres service container must `GRANT CREATEDB ON DATABASE ...` to `autoerp` so `CREATE DATABASE` succeeds, or run the test under the `postgres` superuser.
3. The test must clean up the created tenant DBs (otherwise each CI run leaves orphan databases).
4. Document the test's resource cost (each test creates + drops a real PG database, takes seconds not milliseconds).

#### [B-5] POS fiscal Phase 1 (in flight) actively touches tenant migrations Phase 0 wants to move
**Where:** T6 spec §11 "Coordination notes" — `Phase 0 BLOCKS every other track that writes migrations`
**Claim:** Phase 0 blocks new migrations from the sprint; existing fiscal work is not in scope.
**Reality:** Per memory `project_pos_fiscal_event_engine.md`, the POS Fiscal Event Engine Phase 1 is "EXECUTION STARTED — Task 1 shipped at `525c9f92`" on a separate feature branch. That sprint includes Tasks 7–13/28–30 which are "schema-destructive" per the memory — i.e., they create/alter tenant migrations. The fiscal migration `2026_05_14_100002_create_fiscal_events_immutability.php` is on `main` already, and there are 50+ POS/fiscal migrations in `database/migrations/`. The fiscal sprint is producing more.

The T6 spec says Phase 0 blocks the productization sprint but does not address coordination with the in-flight fiscal sprint. If fiscal lands a new tenant migration (e.g. for `device_attestation_chain`) into `database/migrations/` after Phase 0 has moved its peers into `database/migrations/tenant/`, the fiscal migration ends up in the wrong directory and breaks new tenant DBs.

**Impact:** Either Phase 0 stalls until fiscal Phase 1 freezes its migrations, or fiscal Phase 1 mid-flight is reclassified onto the tenant migration directory by Phase 0, causing rebase conflicts on the active fiscal branch.
**Suggested fix:** T6 spec §11 must add a coordination note with the fiscal sprint owner: either Phase 0 waits until fiscal Phase 1 is at a quiescence point (Task N gate), or fiscal Phase 1 reviewer commits to placing new migrations under `database/migrations/tenant/` post-gate. Pick one explicitly.

### P1

#### [P1-1] Tunisia seeders listed as "production" are NOT consumed by `TenantInitializationService` (and thus not by the proposed pool-claim flow)
**Where:** T6 spec §2 item 7 — `TunisiaChartOfAccountsSeeder.php + TunisiaTaxConfigurationSeeder.php + TunisiaStampDutySeeder.php + TunisianParapharmacySeeder.php + TunisiaWithholdingRulesSeeder.php (PRODUCTION; consume in pool-claim init)`
**Claim:** All 5 Tunisia seeders are wired into the production init flow and will be consumed in pool-claim.
**Reality:** Reading `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:14-19, 138-149, 199-224`: only `TunisiaChartOfAccountsSeeder` (line 143) and `TunisiaTaxConfigurationSeeder` (line 219) are imported and used. `TunisiaStampDutySeeder`, `TunisianParapharmacySeeder`, `TunisiaWithholdingRulesSeeder` exist as files but are not called from the registration flow. The first two are likely called separately (manual ops, smart-payment seeder, etc.).

**Impact:** A Phase 1 implementer reading the spec will assume pool-claim must invoke all 5 seeders to match "production", which (a) duplicates work already gated by `TenantInitializationService` and (b) may produce inconsistent state in pre-warmed tenants that go through a different init path.
**Suggested fix:** Reword §2 item 7 to: `TunisiaChartOfAccountsSeeder.php + TunisiaTaxConfigurationSeeder.php (PRODUCTION; wired into TenantInitializationService::seedChartOfAccounts and ::seedTaxConfigurations). TunisiaStampDutySeeder, TunisianParapharmacySeeder, TunisiaWithholdingRulesSeeder exist as auxiliary seeders not in the registration init flow — pool-claim must call TenantInitializationService::initializeForNewRegistration and nothing else.`

#### [P1-2] `tenancy.php` has a duplicate `'pgsql'` key in `managers` array — flipping line 84 is moot until the bug is fixed
**Where:** `apps/api/config/tenancy.php:67-85`; T6 spec §3 item 3 "Flip `pgsql => PostgreSQLDatabaseManager::class`"
**Claim:** The flip is a one-line change at line 84.
**Reality:** Reading the actual file:
```php
'managers' => [
    'sqlite' => SQLiteDatabaseManager::class,
    'mysql' => MySQLDatabaseManager::class,
    'pgsql' => PostgreSQLDatabaseManager::class,        // line 72 — currently set to DatabaseManager
    // ...
    'pgsql' => PostgreSQLSchemaManager::class,           // line 84 — overrides line 72
],
```
There are TWO `'pgsql' =>` keys in the same array. PHP keeps the last one, so the effective manager is `PostgreSQLSchemaManager` (the spec's claim is correct in outcome). But "flip line 84" is not enough — the duplicate key on line 72 also needs to be addressed. A naive flip of line 84 to `PostgreSQLDatabaseManager` leaves the file with two identical keys, both `PostgreSQLDatabaseManager`, which is correct in outcome but is dead code and confusing.

**Impact:** Code review of the Phase 0 PR will flag this as a sloppy duplicate; reviewer (Opus per workflow) will reject for not cleaning up the duplicate.
**Suggested fix:** Phase 0 deliverable should explicitly say: "Remove the duplicate `'pgsql' => PostgreSQLDatabaseManager::class` line at line 72 of `tenancy.php` and set the line 84 entry to `PostgreSQLDatabaseManager::class`. The final managers array must have exactly one `'pgsql'` key."

#### [P1-3] Topology Contract §2 omits several existing central tables and ignores `super_admins`
**Where:** Topology Contract §2 central tables list (lines 28–37)
**Claim:** The listed central tables are complete.
**Reality:** I found additional tables that should be classified but aren't mentioned:
- `super_admins` (`2025_12_01_194614_create_super_admins_table.php`) — clearly central
- `admin_audit_logs` (`2025_12_01_194632_create_admin_audit_logs_table.php`) — clearly central
- `countries` (`2025_12_01_192409_create_countries_table.php`) — open question in §8 but unresolved
- `country_tax_rates` (`2025_12_01_192545_create_country_tax_rates_table.php`) — likely central (shared reference data)
- `country_payment_settings` (`2025_12_10_100000_create_country_payment_settings_table.php`) — likely central
- `personal_access_tokens` (`2025_11_29_234638_create_personal_access_tokens_table.php`) — Sanctum tokens, polymorphic FK to users; classification depends on whether `users` is central or per-tenant
- `stored_events` and `snapshots` (event sourcing infrastructure) — classification depends on whether event sourcing is per-tenant
- `audit_events` — per memory and code, has `tenant_id` (`2025_11_30_140001_add_company_id_to_audit_events.php` is post-create), so per-tenant
- `tax_configurations` — note that `TenantInitializationService:208` queries `countries` by FK; if `countries` is central but `tax_configurations` is tenant, the FK breaks post-flip
- `plans` vs `subscription_plans` — there are TWO migrations: `2025_12_01_193629_create_plans_table.php` and `2025_12_16_100000_create_subscription_plans_table.php`. The contract lists `plans` only

**Impact:** Phase 0 cannot make the central-vs-tenant call for these without explicit guidance, and the agent will silently invent classifications.
**Suggested fix:** Topology Contract §2 must enumerate every existing migration and place it in central or tenant. The §8 "Open question" about shared reference data must be resolved BEFORE Phase 0, not parked — the answer determines whether `countries` is central (with cross-DB FK problem for `tax_configurations`) or duplicated per tenant.

#### [P1-4] PgBouncer `pgsql_direct` design is named but not specified
**Where:** Topology Contract §6 "Define an alternate connection `pgsql_direct` for these operations"; T6 spec §4 "add an alternate `pgsql_direct` connection in `config/database.php`"
**Claim:** Add a `pgsql_direct` connection that bypasses PgBouncer for backups/restores/migrations.
**Reality:** Neither doc specifies:
- What env vars `pgsql_direct` should consume (`DB_HOST_DIRECT`? Or reuse `DB_HOST` but skip pgbouncer port?)
- Whether the staging compose needs a new exposed Postgres port (currently only `pgbouncer:5432` is wired in `docker-compose.staging.yml:131-144`; the underlying `postgres` service may be on a non-published port)
- Whether `PDO::ATTR_EMULATE_PREPARES` should be `false` for `pgsql_direct` (it should — that's the whole point)
- Whether `pgsql_direct` needs `CREATEDB` privilege (it does — the tenant manager creates databases through it)
- Whether `pgsql_direct` should use a different DB user (probably yes for security: PgBouncer can be authenticated as a low-priv user; CREATEDB needs a higher-priv user)

**Impact:** The Phase 1 backup implementer cannot wire `pgsql_direct` from the spec alone and will guess at env vars, security model, and exposed ports.
**Suggested fix:** Topology Contract §6 must specify the full `pgsql_direct` connection config block as a code sample, including env var names, the rationale for `EMULATE_PREPARES=false`, the privilege model, and the docker-compose change to expose direct Postgres for backup ops.

#### [P1-5] Phase 0 acceptance criterion 2 is undefined and likely false
**Where:** T6 spec §3 acceptance criteria, item 2: `All tenant-scoped existing migrations moved into it (verify via `grep -r "tenant_id\|company_id" database/migrations/` returning empty for non-platform tables)`
**Claim:** A grep for `tenant_id` or `company_id` in central migrations should return empty.
**Reality:** This grep does NOT distinguish between (a) migrations that CREATE tenant-scoped tables (which should move) and (b) migrations that ALTER central tables to add a `tenant_id`/`company_id` reference (e.g., `2025_11_30_130000_add_company_id_to_existing_tables.php`, `2025_11_30_131000_add_company_id_to_stock_tables.php`, `2025_11_30_134000_make_company_id_required.php`). These ALTER migrations target tables that are themselves tenant-scoped, but moving them creates a sequencing nightmare: the original CREATE migration in `tenant/` must run before the ALTER, but tenant migrations are timestamped relative to central migrations and may interleave.

Also, the grep can produce false positives in comments, docblocks, `down()` migrations that drop columns conditionally, etc.

**Impact:** The acceptance check is a false-confidence signal; a passing grep does not mean Phase 0 is correct.
**Suggested fix:** Replace the grep with an explicit enumeration test: a PHPUnit test that asserts each migration in `database/migrations/` and `database/migrations/tenant/` matches the topology contract's classification. Generate the classification list as a test fixture and let CI fail if a new migration lands in the wrong place.

#### [P1-6] Race-safety design for pool claim is named but not specified to a level that prevents Stancl race conditions
**Where:** T6 spec §4 "Race safety: claim operation uses `SELECT FOR UPDATE` on a pre-warmed tenant row + atomic transition `PreProvisioned → Active`"
**Claim:** `SELECT FOR UPDATE` is sufficient race safety.
**Reality:** Stancl tenancy initialization is async — `tenancy()->initialize($tenant)` swaps connection state on the current PHP process. Two concurrent signups racing for a pre-warmed tenant via `SELECT FOR UPDATE` work for the central tenants table row update, but the danger is what happens between:
1. Row locked / status updated to `Active`
2. `tenancy()->initialize($claimedTenant)` swaps connection
3. `TenantInitializationService::initializeForNewRegistration` runs (creates `companies` row, chart of accounts, etc.) inside the tenant DB
4. Commit

If step 4 fails (e.g., a seeder errors out), the central row is already `Active` but the tenant DB is half-seeded. The spec doesn't specify rollback: does the tenant get returned to `PreProvisioned`? Marked `Failed`? Burned and replaced?

Also, `SELECT FOR UPDATE` on the central tenants table happens on `pgsql` connection. After `tenancy()->initialize()` swaps to the tenant connection, the lock is in a different transaction. If the central tenants update was not yet committed when the tenant DB init runs and the latter throws, who rolls back what?

**Impact:** Concurrent claim test required by acceptance criterion 6 ("two simultaneous claims grab two different pool tenants, not the same one") only covers the happy case. The real failure mode is partial-init followed by claim retry, which can produce double-claimed-but-mid-flight tenants or orphan tenant DBs with no central row.
**Suggested fix:** Spec the full lifecycle:
- Outer transaction on central connection: `SELECT FOR UPDATE` on a row WHERE status=PreProvisioned LIMIT 1; UPDATE to status='Claiming'; commit immediately.
- Run `TenantInitializationService` under tenant context; on success, transition `Claiming → Active`; on failure, transition `Claiming → ClaimFailed` and replenish pool with a fresh tenant.
- Add `Claiming` and `ClaimFailed` to `TenantStatus` enum (currently the spec only mentions adding `PreProvisioned`).
- Add a janitor job that re-claims `ClaimFailed` rows after manual review and burns orphan tenant DBs (where central row is `ClaimFailed` and DB exists).

### P2

#### [P2-1] T6 spec §1 claim about `TenantInitializationService` line range is correct in count, slightly misleading in content
**Where:** T6 spec §1 line 16 — `TenantInitializationService is PRODUCTION (... :1-252): idempotent, country-aware (Tunisia + France), seeds CoA + tax + payment methods + role assignment + trial subscription`
**Claim:** The service is "idempotent".
**Reality:** I read the full file. The service is partially idempotent:
- `seedTaxConfigurations` uses `updateOrCreate` per the docblock (line 203): "idempotent"
- `assignDefaultRoles` calls `$user->assignRole('admin')` which Spatie makes idempotent at the assignment level
- BUT `createTrialSubscription` (lines 83-111) calls `TenantSubscription::create([...])` — this is NOT idempotent; calling twice creates two subscriptions
- `seedChartOfAccounts` (line 138-149) instantiates a new seeder and calls `->run($company->id, $company->tenant_id)` — idempotency depends on the seeder's internals (not verified)
- `seedPaymentRepositories` (line 176-180) similar dependency

**Impact:** Pool claim that retries on partial failure (which is what [P1-6] proposes) may double-create subscriptions or duplicate seed data depending on which call already ran.
**Suggested fix:** Add an explicit acceptance criterion under §6 "Pre-warm pool": `Pool claim is idempotent end-to-end. Calling claim twice for the same already-claimed tenant must produce no duplicate rows in tenant_subscriptions, accounts, payment_methods, payment_repositories, or any other init-time table.` Then verify each seeder is idempotent and fix the ones that aren't.

#### [P2-2] Topology Contract §7 partial-unique-index claim is correct but `COALESCE` alternative is the wiser fallback for SQLite
**Where:** Topology Contract §7 "Backfill plan for nullable `variant_id` additions (T2)"
**Claim:** Use partial unique indexes; "cleaner and more idiomatic."
**Reality:** SQLite (which the test suite uses per `phpunit.xml:40`) supports partial indexes since 3.8.0, but Laravel's `unique()->where(...)` requires raw SQL or a workaround. PostgreSQL handles partial indexes natively; SQLite handles them via DDL. The migration must use raw SQL for the partial index, which won't run on the SQLite test path.

The COALESCE trick is more portable (works as a regular unique index on a computed expression on both Postgres and SQLite), at the cost of using a sentinel UUID — which is a code smell but a pragmatic one.

**Impact:** When T2 lands its partial-index migration, the SQLite test suite will either fail the index creation or silently skip the uniqueness check, leaving variants of the same product un-deduplicated in tests.
**Suggested fix:** Topology Contract §7 should note that partial indexes require raw SQL on Laravel's Blueprint API and that the test suite's SQLite path needs a parallel `DB::statement` per database driver. Or pivot to COALESCE with a documented sentinel UUID constant in code.

#### [P2-3] Topology Contract §3 step 6 ("Run full test suite against the flipped configuration — every passing test today must continue to pass") is not actionable
**Where:** Topology Contract §3 step 6
**Claim:** Run the test suite after the flip and every passing test still passes.
**Reality:** As [B-4] established, the test suite runs on SQLite. SQLite cannot create databases per tenant — `PostgreSQLDatabaseManager` is a no-op or error there. The flip configuration is `pgsql => PostgreSQLDatabaseManager::class`, but on SQLite the `sqlite => SQLiteDatabaseManager::class` is what runs. So "every passing test passes" only verifies that the SQLite path still works — which is trivially true because the flip changes the `pgsql` manager, not the `sqlite` manager.

**Impact:** The verification step is meaningless for the actual change being made.
**Suggested fix:** Step 6 must be: "Run the test suite against `DB_CONNECTION=pgsql` configured to use a real Postgres instance (via the `backend-test-pgsql` CI job, extended to run on PR→dev per [B-4]). Every test that previously passed against `pgsql` (excluding fiscal hash-chain immutability tests, which are independently gated) must continue to pass."

#### [P2-4] Phase 1+ effort estimate of 7 PD (still) under-counts the backup/restore work
**Where:** T6 spec §1 "Estimated effort: Phase 0 ~3 PD + Phase 1+ ~7 PD = ~10 PD total (revised upward from v1's 7 PD per P2-4 finding)"
**Claim:** Phase 1+ is 7 PD: pool (3 PD) + backup (3 PD) + monitoring/runbooks (1 PD).
**Reality:** Backup automation alone (Phase 1B per §10 workflow) is 3 PD including pg_dump per tenant + S3 + retention + restore + dry-run. Realistic breakdown:
- Wire `pgsql_direct` connection + docker-compose changes + secret rotation for the higher-priv user → 1 PD (and it's blocked on the unspecified §6 design per [P1-4])
- pg_dump streaming to S3 with tenant-specific KMS keys (per the contract's "Secrets" requirement) → 1.5 PD
- Retention enforcement (S3 lifecycle policy via Terraform/CDK, or in-app cron — pick one) → 0.5 PD
- Restore command with dry-run + `--confirm` + targeting safety + decryption → 1.5 PD
- Restore drill on staging clone + runbook → 1 PD
- Concurrent claim test + race safety per [P1-6] → 1 PD
- Total: ~6 PD for backup + restore alone, plus 3 PD pool, plus 1 PD monitoring = 10 PD for Phase 1+, not 7 PD.

**Impact:** Sprint timeline overshoots by 3 PD if Phase 1+ is committed as 7 PD.
**Suggested fix:** Revise estimate to: Phase 0 ~4 PD (added migration rewrites per [B-1]), Phase 1A pool ~4 PD (added race-safe lifecycle per [P1-6]), Phase 1B backup ~5 PD (added `pgsql_direct` spec per [P1-4]), Phase 1C monitoring ~1 PD = **~14 PD total**. This is approximately double the original v1 estimate, reflecting the actual breadth of work for a SaaS-ops gate.

#### [P2-5] T6 spec §2 item 9 PgBouncer config citation is correct line range but spec claim of "pool size 20, max 200" is dangerous to assume going forward
**Where:** T6 spec §2 item 9 — `docker-compose.staging.yml:124-144 — PgBouncer service config (transaction mode, pool 20, max 200)`
**Claim:** PgBouncer is configured with DEFAULT_POOL_SIZE=20 and MAX_CLIENT_CONN=200.
**Reality:** Confirmed at lines 133-135. But this is the CURRENT staging config for single-tenant test load. Post-flip with DB-per-tenant and a pool of 10 pre-warmed databases (each opening connections via Stancl bootstrappers), the connection math changes: each tenant connection is a separate pool in PgBouncer's bookkeeping. Pool 20 across 10 tenants is 2 connections per tenant — well below sustainable.

The Phase 1+ "Max pool sizing recommendation documented per tenant volume" acceptance criterion (§6) handwaves the recompute.

**Impact:** First tenant beyond the pool-of-3 will exhaust connections and silently queue; symptoms will be confused as application-level slowness.
**Suggested fix:** Phase 1+ acceptance criterion must include a concrete formula: `MAX_CLIENT_CONN = (expected_tenant_count) * (per_tenant_pool_size) + central_connection_headroom`, with `central_connection_headroom = 20` (for the central queries the pool claim service issues). Document the formula in the runbook AND in `docker-compose.staging.yml` comments. Currently the spec only says "documented" with no formula.

### SUGGESTION

#### [S-1] Topology Contract §9 enforcement step 3 should be a hard CI gate not "post-sprint nice-to-have"
**Where:** Topology Contract §9 item 2 — `Linter / static check (post-sprint nice-to-have)`
**Suggestion:** A custom PHPStan rule to reject `->constrained('tenants')` in tenant migrations is small (<100 LOC) and prevents the entire class of regression that B-1 documents. It should land in Phase 0, not "post-sprint." Even a simple grep-in-CI guard is a fraction of a PD.

#### [S-2] Topology Contract should add Pattern E for tenant-DB ID generation
**Where:** Topology Contract §4 Patterns A-D
**Suggestion:** Add Pattern E for "How do you generate IDs in tenant DB?" — currently UUIDs are generated by Laravel's `HasUuids` trait, which is fine, but tenant DBs may need sequence-based IDs for compliance reasons (e.g., the document sequence table currently has `tenant_id` as part of its uniqueness; post-flip it's per-tenant-DB which changes the uniqueness model). Document the ID-generation semantics now to avoid sprint-level confusion.

#### [S-3] T6 spec §10 workflow recommendation should label Phase 0 as "Opus lead with Codex tactical" not "Codex with Opus review"
**Where:** T6 spec §10 — `Phase 0 (Codex with Opus review, ~3 PD): Migration moves + Stancl flip + flip test + gate marker. Codex executes mechanically; Opus reviews migration classification correctness.`
**Suggestion:** Phase 0 is not mechanical — it involves the classification decisions in [B-2], the connection design in [B-3], the migration rewrites in [B-1]. Opus should lead the design pass; Codex should execute the file moves after the design is locked. The current wording invites Codex to start rewriting migrations from the under-specified plan.

#### [S-4] Both docs should explicitly state Stancl `template_tenant_connection` is `null` and why
**Where:** Topology Contract §3, T6 spec §3
**Suggestion:** `tenancy.php:57` sets `template_tenant_connection => null`. Post-flip with DB-per-tenant, the template is inferred from `central_connection` (`vendor/stancl/tenancy/src/DatabaseConfig.php:104`). Document this fallback chain so the reader doesn't think they need to set `template_tenant_connection` to something.

## Adversarial Review Checklist verification

### T6 Spec §3 Phase 0 checklist

| Item | Status | Evidence |
|---|---|---|
| Walk every file in `database/migrations/` — confirm it's central per the topology contract | **FAIL** | I sampled and found 40 files with `->constrained('tenants')` — these are tenant migrations that are still in the central directory. The spec's "move files" step doesn't address the FK rewrite. |
| Walk every file in `database/migrations/tenant/` — confirm tenant-scoped per the contract | **FAIL** | Directory doesn't exist. Pre-flip state. |
| Open `tenancy.php` after flip — confirm `pgsql => PostgreSQLDatabaseManager::class` | **PARTIAL** | The file has a duplicate `'pgsql'` key (line 72 and 84). Cleaning up both is needed (per [P1-2]). |
| Run the flip integration test in CI — confirm it actually opens a new connection to the new DB | **FAIL** | Test cannot run on PR→dev per [B-4]. |
| Grep all tenant migrations for `->constrained('tenants')` / `('plans')` / `('domains')` — should return zero hits | **FAIL** | 40 hits today; spec doesn't say to rewrite. |
| Confirm `BindsTenantContext` trait still works with the new manager | **PARTIAL** | Trait reads tenant by ID from central (`Tenant::find($this->tenantId)`) which still works; but its `->run($fn)` closure executes against the tenant connection, which post-flip is a different DB. Untested for DB-manager path. |
| Confirm Horizon + Sentry breadcrumbs still capture tenant context | **PARTIAL** | Stancl's `QueueTenancyBootstrapper` carries tenant ID in the queue payload (verified at `vendor/stancl/tenancy/src/Bootstrappers/QueueTenancyBootstrapper.php`). Sentry breadcrumbs reading tenant context are not addressed in the spec. |

### T6 Spec §7 Phase 1+ ops checklist

| Item | Status | Evidence |
|---|---|---|
| Race condition under load (50 concurrent claims vs 10-tenant pool) | **PARTIAL** | Spec says SELECT FOR UPDATE, but [P1-6] shows the failure modes are not specified. |
| Backup tenant context (pg_dump targets the right DB) | **PARTIAL** | Spec says use `pgsql_direct` but `pgsql_direct` is not defined (per [P1-4]). |
| Restore destination safety (cannot overwrite different tenant's DB) | **PARTIAL** | Spec says dry-run default + `--confirm`. Implementation safety mechanism unspecified (does the dry-run still load the dump and check target DB name? Does `--confirm` require typing the tenant ID?). |
| Secrets (S3 creds never logged in Sentry) | **PARTIAL** | Spec says env vars but no log scrubbing rule. |
| PgBouncer interaction (verify backup/restore go through `pgsql_direct`) | **PARTIAL** | Per [P1-4] the connection isn't designed yet. |
| Pre-warm idempotency (`tenant:ensure-pool` twice in 5s) | **PARTIAL** | Spec asserts idempotency but doesn't say how (file lock? Cache::lock? DB advisory lock?). |
| Country-specific init at claim | **FAIL** | Per [P1-1] the spec misstates which Tunisia seeders are wired in production. |
| Stancl event handling (deleted tenant drops DB cleanly) | **NOT SPECIFIED** | T6 §8 lists Out of Scope: "GDPR data portability / right-to-be-forgotten — defer", but tenant deletion itself is in the existing Tenant Observer's `deleting` hook (revokes tokens but doesn't address the new DB-per-tenant drop semantics). |
| Performance (pool replenishment uses queue jobs; doesn't block signup thread) | **PARTIAL** | Spec says scheduled hourly + invoked post-claim. Doesn't say the post-claim invocation is async/queued. |
| Monitoring (pool low-watermark alerts fire when pool < min) | **PARTIAL** | Spec says "wired to Sentry" — Sentry is for errors, not for low-watermark alerts. Use a real metric pipeline (Horizon dashboard exposed metric, or a dedicated Prometheus exporter). |

## Round-1 finding resolution check

### B-3 (Round-1 BLOCKER): T6's DB-per-tenant migration plan collides with every tenant-scoped migration in the sprint
**v1 problem:** T6 v1 said Phase 1 moves tenant-scoped migrations into `database/migrations/tenant/` and flips manager in ~2 PD, no pre-sprint gate.
**v2 fix:** T6 v2 splits into Phase 0 (pre-sprint gate, ~3 PD) + Phase 1+ ops (~7 PD = revised to ~10 PD total). Migration moves are now an explicit Phase 0 gate. Other tracks blocked until merged.
**Verdict:** **PARTIALLY RESOLVED.** The sequencing problem is fixed. The mechanical problem (40 FK rewrites, classification of `users` table, definition of `central` connection) is NOT resolved — see [B-1], [B-2], [B-3] above.

### P1-6 (Round-1 P1): T6 Section 2 has more non-existent "verified" paths
**v1 problem:** Cited country seeders under `app/Modules/Country/Seeders` (wrong) and monitoring under `app/Http/Controllers/Monitoring/MonitoringController.php` (wrong).
**v2 fix:** §2 item 7 now cites `apps/api/database/seeders/Tunisia*.php` (correct). §2 item 11 now cites `apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php` (correct, verified at the path).
**Verdict:** **RESOLVED on paths.** But §2 item 7 introduces a new factual error — see [P1-1] (lists 5 seeders as production-wired when only 2 are consumed by the init service).

### P2-4 (Round-1 P2): T6 effort estimate is materially under-scoped
**v1 problem:** 7 PD for the whole T6 including DB-per-tenant flip, pool, backup, restore, dashboards, runbooks.
**v2 fix:** Revised to 10 PD (Phase 0 = 3 PD + Phase 1+ = 7 PD).
**Verdict:** **PARTIALLY RESOLVED.** The revision moves in the right direction but is still optimistic — see [P2-4] above for the recomputed ~14 PD budget.

## What's solid

1. **Topology contract is the right level of abstraction.** Explicitly forbidding cross-DB FKs from day 1 and committing to UUID-as-bare-column references is a sound architectural call. The 4 patterns (A-D) are clearly named and easy to reference from other specs.

2. **Phase 0 as pre-sprint gate is the correct structural fix.** The round-1 finding that you cannot run other tracks' migration work in parallel with the manager flip is fully internalized. The "no other track writes migrations until Phase 0 merges" rule is the right discipline.

3. **PgBouncer compatibility addressed at the architectural level.** Identifying that long-running ops need to bypass PgBouncer (per the `DB_PGBOUNCER=true → EMULATE_PREPARES=true` quirk) is the right call. The execution detail is what's missing (per [P1-4]) but the principle is sound.

4. **TenantInitializationService correctly identified as untouched.** Phase 1 pool-claim composes on top of an existing production-grade service rather than rewriting it. This is the right boundary.

5. **Race-safety acceptance criterion is explicit.** Calling out concurrent-claim test as a Phase 1 acceptance check (§7 "Race condition under load") is the right paranoia level. The implementation needs more spec (per [P1-6]) but the test surface is right.

## Risks the docs missed

1. **The `domains` table and Stancl subdomain identification.** `domains` is in central and resolves `{slug}.example.com → tenant_id`. After the flip, the domain identification middleware works (central read), but the per-tenant DB connection is opened lazily. If a request hits a 404 (e.g., bot scanning), the central `domains` lookup runs but the tenant DB is never opened. This is fine for read path but matters for rate limiting per tenant — Stancl assumes tenant context is bound. The spec doesn't address middleware-layer behavior under DB-per-tenant.

2. **Filament admin (super-admin dashboard) tenant context.** The proposed pool/backup admin widgets need to read from central (for pool status) AND iterate tenants (for backup audit log per tenant). The latter requires opening each tenant DB in turn — at scale (>100 tenants) this is slow. Spec doesn't address pagination/streaming for cross-tenant admin queries.

3. **Migrations against pre-warmed tenants when the tenant migration set changes.** Phase 1 pool pre-warms tenants with the CURRENT tenant migration set. If T2 lands a new tenant migration after Phase 1 pool replenishment but before a claim happens, the pre-warmed tenant is on a stale schema. The pool needs a "migrate-existing-pre-warmed-tenants" job or a "burn-stale-pool-tenants-when-migration-version-changes" rule. Neither is in the spec.

4. **Restore destination cross-tenant safety in adversarial cases.** The spec says "cannot overwrite different tenant's DB" but doesn't address: backup file gets renamed in S3 (or its prefix gets corrupted), `--confirm` is given for the wrong tenant ID, the restore command's tenant lookup hits a stale cache. Defensive coding (check backup metadata matches target tenant, refuse if mismatch) is not specified.

5. **Cost / quota for tenant DB count in Postgres.** Postgres has practical limits on database count per cluster (commonly 1000s but degrades). Spec doesn't set a max tenant count per cluster or a horizontal-sharding plan. For an MRR-positive SaaS this matters at the 6-month horizon.

6. **`countries` is FK'd from `tax_configurations` (verified at `TenantInitializationService.php:208` checking `countries.code`).** Post-flip, if `countries` is central but `tax_configurations` is tenant, the existing FK breaks. The topology contract §8 punts this as "open question" but it's a real Phase 0 collision.

7. **Stancl's `central_connection` config key uses a non-existent default.** `tenancy.php:51` sets `'central_connection' => env('DB_CONNECTION', 'central')` — but `'central'` is not a defined connection. Today this happens to resolve to `pgsql` (the actual default), but it's a footgun: anyone setting `DB_CONNECTION=` (empty) gets a bootstrap crash. Phase 0 should fix the default to `'pgsql'` or define a `central` connection.

8. **Test fixtures may directly insert tenants/users without using factories.** Some tests (per `tests/Feature/Tenant/TenantInitializationTest.php:30`) seed via `RolesAndPermissionsSeeder` then create tenant rows directly. Post-flip, any test that creates a Tenant model triggers `PostgreSQLDatabaseManager::createDatabase` (a real PG call), which a SQLite-only test cannot do. The spec's Phase 0 acceptance "every passing test today must continue to pass" must contend with this.
