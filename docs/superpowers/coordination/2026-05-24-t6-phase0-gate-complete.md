# T6 Phase 0 GATE — Complete (Phase 0b: database-per-tenant flip)

**Status:** Phase 0b implemented on `feat/t6-phase0b-db-per-tenant`. PR `[T6-PHASE0-GATE]`.
**Predecessor:** Phase 0a (`feat/t6-phase0a-auth`, merged to dev) delivered the email-first
identity rewrite, the central identity index, the `ResolveTenancy` middleware, and the
`TenancyResolver` with fail-closed logic gated behind `TENANCY_DB_PER_TENANT` (default off).
**This phase (0b):** flips Stancl from `PostgreSQLSchemaManager` → `PostgreSQLDatabaseManager`,
moves tenant-scoped migrations into `database/migrations/tenant/`, rewrites cross-DB FKs to
plain UUIDs, adds the `central` connection, pins Sanctum's PAT to central, and turns the
0a compat skip fail-closed in DB mode.

> **Sequencing rule still in force:** no other sprint track may write a NEW migration until
> this gate merges. After merge, tenant-scoped migrations go in `database/migrations/tenant/`,
> central ones in `database/migrations/`. See the
> [migration topology contract](2026-05-24-migration-topology-contract.md).

---

## What landed

### 1. `central` connection (`config/database.php`)
A pgsql connection whose `database` comes from `DB_CENTRAL_DATABASE`. Never swapped by Stancl,
so `DB::connection('central')` (Pattern A) and the central-pinned Sanctum PAT always reach the
central database. Production sets `DB_CONNECTION=central` so the app default AND Stancl's
`tenancy.database.central_connection` (`env('DB_CONNECTION','central')`) resolve to the same
connection (round-6 S-4 option b). `.env.example` updated.

### 2. Stancl manager flip (`config/tenancy.php`)
Removed the duplicate `'pgsql' =>` key (PHP kept the last — `PostgreSQLSchemaManager`). Now
`'pgsql' => PostgreSQLDatabaseManager::class` (one physical database per tenant,
`tenant_<slug>`). `migration_parameters.--path` already targeted `migrations/tenant`.

### 3. Migration topology — central vs tenant
- **358 migrations** now live in `database/migrations/tenant/` (the contract's "~141" estimate
  was low — it did not account for the many ALTER/trigger/backfill migrations on tenant tables;
  every migration that creates **or alters** a tenant table must run inside the tenant DB).
- **17 migrations** remain central:
  `cache`, `jobs`/`job_batches`/`failed_jobs`, `personal_access_tokens`, `tenants` (+ 3 alters),
  `plans`, `subscription_plans`-file (also creates `plans` — see note), `tenant_subscriptions`
  (×2 migrations), `super_admins`, `admin_audit_logs`, `signup_tracking`, `loyalty_registry`,
  `central_identities`.
- **Reclassified to central by judgment** (not in the contract's explicit list, decided here):
  - `admin_audit_logs` — super-admin audit; FKs `super_admins` + `tenants` (both central).
  - `signup_tracking` — pre-tenant signup funnel; FKs `tenants` (central).
  - `loyalty_registry` — global entity-type registry; no tenant scoping at all.
- **`email_verification_tokens` + `password_reset_tokens`** moved tenant-side (topology §9.5).

### 4. Cross-DB FK rewrites (constitutional rule §1)
77 FK declarations across 71 tenant migrations rewritten from `->constrained('tenants')`,
implicit `->constrained()` on central-implying columns, and separate
`->foreign(...)->references('id')->on('<central>')` statements → plain `uuid()` columns (Form 1)
or removed constraint statements (Form 2). Existing explicit indexes preserved; no new indexes
added (avoids index-name collisions). Verified by `CrossDbForeignKeyAuditTest` (syntax-independent,
runs in CI forever) and a grep audit: **zero** cross-DB FK to a central table in `tenant/`.

`migrate_tenant_data_to_companies` (a legacy data bridge that reads the central `tenants` table)
is guarded with `Schema::hasTable('tenants')` so it no-ops inside a tenant database.

### 5. Sanctum central PAT
`App\Modules\Identity\Infrastructure\CentralPersonalAccessToken` uses Stancl's `CentralConnection`
trait (resolves `tenancy.database.central_connection`) rather than a hard-coded `'central'`,
registered via `Sanctum::usePersonalAccessTokenModel`. Production → reads the central database
after tenancy init; the test suite → resolves to the default connection so RefreshDatabase
transaction isolation is preserved (a hard-coded second connection to the same physical DB would
leak token rows across tests). `CentralPersonalAccessTokenPhase0bTest` (previously skipped) now
proves bearer auth reads the PAT from central while the `tokenable` User resolves tenant-side.

### 6. Fail-open → fail-closed
`TENANCY_DB_PER_TENANT=true` set in `.env.example`. The test suites force it `false`
(single-connection compat); the fail-closed tests opt in via
`config(['tenancy_resolver.db_per_tenant' => true])`.

### 7. Pre-existing PG failures fixed + tests updated for the flip
All confirmed pre-existing on `origin/dev` via a clean-checkout PG run (except the device-FK test,
which this PR's FK removal necessitates):
- `PlanLimitsService::getUsage()` counted `locations.tenant_id` (the column does not exist —
  `locations` is company-scoped). Now counts through the tenant's companies. New
  `PlanLimitsServiceTest`. (pre-existing)
- `PendingSealMigrationTest::test_pos_terminals_has_fiscal_schema_version_default_2` asserted the
  raw default `'2'` but PostgreSQL reports `'2'::smallint`; the assertion now extracts the numeric
  value. (pre-existing)
- `FiscalEventQuarantineTableTest::test_model_casts_raw_envelope...` used `assertSame` on a decoded
  `jsonb` payload; PostgreSQL does not preserve `jsonb` key order → switched to `assertEquals`. (pre-existing)
- `FiscalEventsImmutabilityTest::test_forbidden_column_update_raises` updated `current_hash` to its
  EXISTING value, so the trigger's `IS DISTINCT FROM` guard correctly saw no change and did not
  raise → now updates to a different value. (pre-existing)
- `ReceiptChainRebuildTest::test_pos_receipts_mirror_tamper...` ("the fiscal-sealed receipt test")
  deliberately tampers a sealed receipt's `total`; the immutability trigger + `pos_receipts_totals`
  CHECK (correctly) block it → the test now disables both for the single out-of-band-corruption
  tamper via transactional DDL (rolled back by RefreshDatabase). (pre-existing)
- `DeviceLossIncidentTest::test_tenant_terminal_company_fks_exist_on_postgres` asserted
  `device_loss_incidents_tenant_id_fk` exists — removed, since this PR drops that cross-DB FK.
  (necessitated by the flip)
- `CreatesChannelSchema` (T3 channel-test trait) globbed `migrations/tenant/2026_05_24_12000*_*.php`;
  after the move that also matched `add_fiscal_event_linkage_to_pos_z_reports` → tightened to
  `*_create_channel*`. Two tests that `require`/read a migration by its old central path
  (`FiscalChainContextInfrastructureChokepointTest`, `BackfillOwnershipHistoryTest`) updated to the
  `tenant/` path. (necessitated by the move)

### ⚠️ Pre-existing PG gate debt NOT addressed (out of scope, flagged for the fiscal team)
The `backend-test-pgsql` fiscal-invariant filter has **~36 pre-existing failures on `origin/dev`**
(confirmed via clean-checkout PG run): the projection/bridge tests (`PosCoreReceiptProjectionTest`,
`AccountChargeProjectionTest`, `TreasuryAccountChargeBridgeTest`, `DocumentAccountChargeFactureBridgeTest`,
`TaskPhase3AccountChargeFullFlowTest`, `TreasuryReceiptBridgeTest`, …) all fail on PostgreSQL with
`pos_receipt_lines_sellable_xor` CHECK violations — the projection inserts a line that satisfies
neither `product_id`-only nor `composite_item_id`-only. This is unrelated to the topology flip
(the migrations are byte-identical to dev) and is left for the fiscal team. To avoid this debt
masking the Phase 0b signal, the new CI gate (below) is a SEPARATE job, and `backend-test-pgsql`
is unchanged (still PR→main only).

### 8. Test harness
- `phpunit-pgsql.xml` forces `DB_CONNECTION=pgsql`; connection params from the environment.
- `AppServiceProvider` registers `database/migrations/tenant/` in the **testing environment only**
  (`loadMigrationsFrom`) so RefreshDatabase rebuilds the full schema on a single connection while
  production keeps tenant migrations out of the default `migrate` path.
- `TenantStanclFlipTest` (PG-only, no RefreshDatabase) creates a real tenant database, asserts only
  tenant migrations ran inside it, central tables are absent, and `DB::connection('central')` is
  reachable from tenant context.
- `TenantDatabaseIsolationTest` (PG-only) provisions **two** real tenant databases, seeds a distinct
  company in each, and proves **no cross-tenant leakage** (each tenant DB contains exactly its own
  row; neither can see the other's). `Database\Seeders\TwoTenantIsolationDemoSeeder` is the reusable
  dev/staging equivalent (`TENANCY_DB_PER_TENANT=true php artisan db:seed --class=…`), usable against
  Docker/staging — including to exercise the pre-existing fiscal PG debt (see the Codex handover doc
  `2026-05-26-fiscal-pg-debt-codex-handover.md`).
- **CI:** a new `t6-phase0b-pgsql` job (`.github/workflows/ci.yml`) runs the Phase 0b PG tests via
  `phpunit-pgsql.xml` on **PR→dev and PR→main** (the flip must be proven on PostgreSQL before merge
  to dev). It is intentionally SEPARATE from `backend-test-pgsql` so it is not red on that filter's
  pre-existing fiscal debt. Making it a required check is a branch-protection setting for the team.

### 9. Stancl bootstrap wiring (required for the flip to actually switch databases)
The app previously had **no** `TenancyInitialized -> BootstrapTenancy` event wiring, so
`tenancy()->initialize()` set `initialized=true` but never swapped the connection (invisible while
every tenant shared one schema). A new minimal `App\Providers\TenancyServiceProvider` registers
`TenancyInitialized -> BootstrapTenancy` and `TenancyEnded -> RevertToCentralContext` — and only
those (NOT `TenantCreated -> CreateDatabase`, which would attempt `CREATE DATABASE` for every Tenant
row, including inside RefreshDatabase transactions). The swap is gated at request time by
`TenancyResolver` (only initializes when the tenant DB exists; fails closed otherwise in DB mode),
so the single-connection compat suite never triggers a swap. The legacy schema-era
`ResetTenantCommand` (`migrate --path=database/migrations`) is now inconsistent with the flip and
should be reworked for database-per-tenant (its test already skips on non-Postgres).

---

## Notes / follow-ups for reviewers

- **`subscription_plans` migration creates `plans`** (filename is misleading) — there are two
  migrations that create `plans` and two that create `tenant_subscriptions`. Both pairs are
  classified central. Confirm the live/duplicate handling (the suite currently migrates clean, so
  one of each pair is guarded or idempotent). The contract's claimed separate `subscription_plans`
  TABLE does not exist.
- **`billing_*` tables (`billing_invoices/invoice_items/payments/refunds`)** were kept tenant-side
  (constitutional default) with Pattern-A UUID pointers to `tenant_subscriptions`/`super_admins`.
  They reference ONLY central tables + each other (no FK to tenant tables), so they are a
  defensible **central** candidate (platform billing managed by super-admins). Flagged for a
  product decision; not blocking the flip.
- **Central model connection pinning:** `Tenant` auto-pins via Stancl's `CentralConnection` trait;
  `CentralIdentity` is intentionally NOT pinned because it is queried *before* tenancy init (when
  `default=central` in production). Other central-table models (`Plan`, `TenantSubscription`,
  `SuperAdmin`, billing) are not pinned — acceptable while accessed in central context, but worth a
  follow-up audit once request-time tenancy is exercised end to end.
- **`backup_before_phase0.sql`** is a pre-existing tracked artifact on `dev` (not introduced here).
