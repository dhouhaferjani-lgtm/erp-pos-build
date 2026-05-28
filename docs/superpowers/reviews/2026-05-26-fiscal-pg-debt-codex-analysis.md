# Fiscal PostgreSQL Debt Analysis And Plan

Date: 2026-05-26
Branch/worktree: `feat/t6-phase0b-db-per-tenant` at `/Users/houssamr/Projects/syneriva/apps/erp.t6-phase0b`

## Reproduction Evidence

Commands run:

```bash
cd apps/api
php artisan test -c phpunit-pgsql.xml --filter="PosCoreReceiptProjectionTest"
php artisan test --filter="ConsoleCommandTenantContextTest|BestEffortPayloadParserTest|CompleteGLHashChainE2ETest|Task33FiscalFullFlowVerificationTest|TaskPhase2AccountPaymentFullFlowTest|TaskPhase3AccountChargeFullFlowTest"
TENANCY_DB_PER_TENANT=true php artisan db:seed --class="Database\\Seeders\\TwoTenantIsolationDemoSeeder"
TENANCY_DB_PER_TENANT=true php artisan db:seed --force --class="Database\\Seeders\\TwoTenantIsolationDemoSeeder"
```

Observed:

- The exact seeder command cancels under this worktree because Laravel sees production mode. With `--force`, it reaches the seeder and reports that the active local connection is SQLite, not PostgreSQL. PostgreSQL itself is reachable locally on `127.0.0.1:5432`/`5433`; the worktree has no `.env`, so ad hoc Artisan commands default to the app's cached/default SQLite shape unless explicit DB env is provided.
- PostgreSQL `PosCoreReceiptProjectionTest` has 20 failures. The dominant root failure is `SQLSTATE[23514]` on `pos_receipt_lines_sellable_xor`: the projector writes `product_id = null` and `composite_item_id = null` for canonical line snapshots such as `product_id = "prod-default"` or for UUIDs that do not resolve to a live same-tenant `products` FK.
- One PostgreSQL projection test fails earlier on a correct production invariant: it updates `fiscal_events.terminal_id`, and the immutability trigger rejects it. That test should be rewritten to create an event whose terminal is absent before projection, not to mutate an immutable fiscal row after insert.
- SQLite debt filter has 11 failures:
  - `BestEffortPayloadParserTest` now reports defects for missing `chain_context` and missing `approval_references`. The parser and DTO contract evolved; the fixture is stale unless the production parser is supposed to accept legacy bytes.
  - `CompleteGLHashChainE2ETest::test_document_backed_entry_numbers_do_not_collide_with_same_second_dates` expects the full UUID suffix, but production now writes a shortened deterministic suffix (`INV-YYYYMMDDHHMMSS-<12 hex>`). The test assertion is stale if the shortened suffix is the intended production format.
  - The Phase 2/3/Task33 full-flow sync tests all receive 422 from `POST /api/v1/pos/sync/fiscal-events`. The endpoint now requires `chain_context` in `FiscalEventEnvelope::fromArray()` and the strict canonical parser requires it in the canonical envelope; the local sealed-envelope builders omit it.
  - `ConsoleCommandTenantContextTest` flags `App\Modules\Tenant\Application\Commands\ReconcileIdentitiesCommand` as unclassified.

## Root Cause

### 1. Receipt-line CHECK is too strict for fiscal projections

`PosCoreReceiptProjection::writeLines()` deliberately treats canonical `line_items[].product_id` as an audit-stable device snapshot, not as a guaranteed live server FK. The method resolves a same-tenant UUID to `products.id`; if no row exists, it preserves the canonical product identifier in `fiscal_events.payload` and writes `pos_receipt_lines.product_id = null`.

That behavior is production-correct for deleted products, ad hoc/service lines, imported legacy product ids, and cross-tenant spoof defense. The PostgreSQL constraint in `database/migrations/tenant/2026_03_03_100000_add_composite_item_id_to_pos_receipt_lines.php` predates the fiscal-event projection and requires exactly one live FK (`product_id XOR composite_item_id`). Under database-per-tenant, PostgreSQL is the truth engine, so the constraint must be relaxed for snapshot-only projected lines while preserving the invalid "both live FKs" rejection.

Target constraint: allow either exactly one live sellable FK or neither live FK when immutable snapshot columns (`product_code`, `product_name`) are present; reject both live FKs.

### 2. Projection test mutates immutable fiscal state

The failing terminal-not-found test updates `fiscal_events.terminal_id` after insert. PostgreSQL immutability triggers correctly reject that. The production code should keep the trigger. The test should construct the event with a missing terminal id before persistence, or assert a supported failure path without mutating immutable columns.

### 3. Full-flow sync fixtures lag the envelope contract

The production ingestion DTO and strict parser now require `chain_context`; `SaleReceiptPayload` now requires `approval_references`. The full-flow tests build canonical bytes without those keys. For production code, the question is whether deployed device clients can still send the older shape. If yes, the endpoint needs a versioned compatibility/upcaster before strict parsing. If no, the fixtures should be updated because the old shape is no longer a valid fiscal event.

Given Phase 4+ fiscal work treats `chain_context` as a core chain key and approval references as part of the canonical sale receipt payload, I will not weaken production validation. I will update only fixture/builders where the failing assertion is tied to an obsolete pre-contract payload shape.

### 4. `ReconcileIdentitiesCommand` is genuinely unclassified

The command reconciles central identities by iterating tenants and reading tenant users. In database-per-tenant mode, users live in tenant databases; a direct `User::query()->where('tenant_id', ...)` from the central/default connection is unsafe. This command should be a tenant-iterating command that initializes each tenant before reading `users`, and uses the central connection/model for `central_identities`.

## Implementation Plan

1. Add a PostgreSQL regression test proving snapshot-only POS receipt lines are legal while "both product and composite FK" remains illegal.
2. Add a tenant migration that replaces `pos_receipt_lines_sellable_xor` with a PostgreSQL constraint that rejects both live FKs but permits snapshot-only projected lines.
3. Update `PosCoreReceiptProjectionTest::test_terminal_not_found_fails_closed_without_crashing` to create the missing-terminal event without mutating `fiscal_events` after insert.
4. Classify and harden `ReconcileIdentitiesCommand` as a per-tenant iterator for database-per-tenant mode. Keep central identity writes on the central model/connection and initialize/end tenancy around tenant user reads.
5. Update stale fiscal fixture builders for the current canonical contract (`chain_context` and `approval_references`) where tests are asserting obsolete payload shape rather than production behavior.
6. Re-run targeted PostgreSQL projection tests, the SQLite debt filter, PHPStan/Pint on touched backend files, and the DB-per-tenant seeder with explicit PostgreSQL env if local credentials permit.

## Verification Targets

```bash
cd apps/api
php artisan test -c phpunit-pgsql.xml --filter="PosCoreReceiptProjectionTest"
php artisan test --filter="ConsoleCommandTenantContextTest|BestEffortPayloadParserTest|CompleteGLHashChainE2ETest|Task33FiscalFullFlowVerificationTest|TaskPhase2AccountPaymentFullFlowTest|TaskPhase3AccountChargeFullFlowTest"
TENANCY_DB_PER_TENANT=true DB_CONNECTION=central DB_DATABASE=autoerp_test DB_CENTRAL_DATABASE=autoerp_test php artisan db:seed --force --class="Database\\Seeders\\TwoTenantIsolationDemoSeeder"
./vendor/bin/pint --dirty
./vendor/bin/phpstan analyse
```
