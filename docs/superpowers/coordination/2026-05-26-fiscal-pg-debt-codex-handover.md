# Codex handover — pre-existing fiscal PostgreSQL test debt (post DB-per-tenant flip)

**For:** the Codex session that has been building the fiscal track (paused before Phase 5).
**Discovered during:** T6 Phase 0b (row-level → database-per-tenant flip), branch
`feat/t6-phase0b-db-per-tenant`.
**Nature:** these failures are **pre-existing on `origin/dev`** (verified via clean-checkout
runs). Phase 0b did **not** introduce them and deliberately did **not** fix them (out of
gate scope). They matter more now because we are activating database-per-tenant.

---

## Context you need

- **The flip (T6 Phase 0b):** Stancl now uses `PostgreSQLDatabaseManager` (one physical
  PG database per tenant). It is gated by `TENANCY_DB_PER_TENANT`: OFF = today's shared-DB
  row-level behaviour (dev/tests default); ON = real per-tenant databases. Tenant-scoped
  migrations moved to `apps/api/database/migrations/tenant/`; cross-DB FKs were rewritten to
  plain UUIDs; a `central` connection was added.
- **Fiscal chain model (already yours):** `ReceiptHashService::verifyTerminalChain()`
  re-hashes the stored `fiscal_events.canonical_bytes` (NOT a recompute from POS models).
  `fiscal_events`, `pos_receipts`, `pos_receipt_lines`, the projections and bridges are all
  **tenant-scoped** (they live in each tenant database after the flip).
- **Repro harness:** `apps/api/phpunit-pgsql.xml` forces PostgreSQL. Local PG: role
  `houssamr`, db `autoerp_test`. New tool `Database\Seeders\TwoTenantIsolationDemoSeeder`
  provisions two real per-tenant databases for manual local/staging exercise.

## Bucket 1 — PostgreSQL CHECK violation in the receipt projection (~36 tests)

**Symptom:** `SQLSTATE[23514] ... violates check constraint "pos_receipt_lines_sellable_xor"`.
The CHECK (`apps/api/database/migrations/tenant/2026_03_03_100000_add_composite_item_id_to_pos_receipt_lines.php`)
requires **exactly one** of `product_id` / `composite_item_id` to be non-null. The receipt
projection inserts a `pos_receipt_lines` row that satisfies **neither** (both null, or both
set). SQLite does not enforce the CHECK the same way, so it only surfaces on PostgreSQL.

**Failing path:** `App\Modules\POS\Application\Projections\PosCoreReceiptProjection` (≈ lines
315 / 575).
**Failing tests (non-exhaustive):** `PosCoreReceiptProjectionTest`, `AccountChargeProjectionTest`,
`TreasuryAccountChargeBridgeTest`, `DocumentAccountChargeFactureBridgeTest`,
`TaskPhase3AccountChargeFullFlowTest`, `TreasuryReceiptBridgeTest`,
`TaskPhase2AccountPaymentFullFlowTest`.

**Repro:**
```
cd apps/api
php artisan test -c phpunit-pgsql.xml --filter="PosCoreReceiptProjectionTest"
```

**Question to resolve:** does the projection emit the correct `product_id` XOR
`composite_item_id` for every line type (sale, account-charge, account-payment), or is the
CHECK too strict for a legitimate line shape? Fix whichever is wrong — on PostgreSQL, which is
now the production-truth engine for per-tenant databases.

## Bucket 2 — fiscal full-flow + misc (11 SQLite failures)

- `Task33FiscalFullFlowVerificationTest`, `TaskPhase2AccountPaymentFullFlowTest`,
  `TaskPhase3AccountChargeFullFlowTest`: device-sync ingestion endpoint returns **422**
  (validation) instead of 200 — trace the request validation for the device envelope.
- `CompleteGLHashChainE2ETest::test_document_backed_entry_numbers_do_not_collide_with_same_second_dates`:
  entry-number suffix assertion (`INV-…` ends-with) fails.
- `BestEffortPayloadParserTest::test_returns_payload_without_defects_when_strict_parse_succeeds`:
  array-identity mismatch.
- `ConsoleCommandTenantContextTest::test_every_concrete_artisan_command_is_tenant_classified`:
  a concrete Artisan command is not tenant-classified (must extend `App\Console\TenantScopedCommand`
  or be allowlisted) — **especially relevant post-flip**: any command that touches tenant data
  must declare how it binds tenant context now that tenant data lives in per-tenant databases.

**Repro:**
```
cd apps/api
php artisan test --filter="Task33FiscalFullFlowVerificationTest|CompleteGLHashChainE2ETest|BestEffortPayloadParserTest|ConsoleCommandTenantContextTest"
```

## What we want from you

1. Root-cause each bucket and fix correctly — **do not edit tests merely to pass**; fix the
   production code if the projection/endpoint is wrong, and only adjust a test if it genuinely
   asserts an obsolete shape.
2. Treat PostgreSQL as the production engine (per-tenant DBs); ensure fixes hold under
   `TENANCY_DB_PER_TENANT=true`.
3. For any command flagged by `ConsoleCommandTenantContextTest`, classify its tenant-context
   binding explicitly in light of database-per-tenant.
4. **Write your analysis + plan to a file** at
   `apps/erp/docs/superpowers/reviews/2026-05-26-fiscal-pg-debt-codex-analysis.md` (do not
   return it inline), then implement against `origin/dev` (or rebased on the Phase 0b branch
   once it merges).

## Sequencing note

The T6 Phase 0b gate PR (`[T6-PHASE0-GATE]`) keeps `backend-test-pgsql` (the fiscal-invariant
filter) on PR→main and adds a separate green `t6-phase0b-pgsql` job, so this debt does **not**
block the flip. Coordinate so your fiscal fixes land on top of (or after) the flip, targeting
`database/migrations/tenant/` for any new migration.
