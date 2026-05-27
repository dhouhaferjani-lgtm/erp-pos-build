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

## Verification & remaining work - 2026-05-26

Worktree/branch used for this pass:

- `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-pg-debt`
- branch `fix/fiscal-pg-debt`
- recovered tip initially verified as containing the expected 11 files from the earlier fiscal PostgreSQL pass: fiscal `bytea` stream accessors, `AccountingService`, `allow_pos_receipt_fk_cleanup`, `ReconcileIdentitiesCommand`, projection/full-flow/parser test updates.

Verification results:

- PASS - Bucket 1 PostgreSQL fiscal projection slice:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=houssamr DB_PASSWORD= php artisan test -c phpunit-pgsql.xml --filter="PosCoreReceiptProjectionTest|AccountChargeProjectionTest|TreasuryAccountChargeBridgeTest|DocumentAccountChargeFactureBridgeTest|TaskPhase3AccountChargeFullFlowTest|TreasuryReceiptBridgeTest|TaskPhase2AccountPaymentFullFlowTest"`
  Result: 421 assertions, warnings only.
- PASS - Bucket 2 SQLite debt filter:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test --filter="Task33FiscalFullFlowVerificationTest|CompleteGLHashChainE2ETest|BestEffortPayloadParserTest|ConsoleCommandTenantContextTest"`
  Result: 95 assertions, warnings only.
- PASS - targeted PostgreSQL broad-regression fixes:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=houssamr DB_PASSWORD= php artisan test -c phpunit-pgsql.xml --filter="GeneralLedgerServicePOSToleranceTest|HandlesDocumentsTest::test_attach_vehicle_context_deletes_when_null|HandlesDocumentsTest::test_attach_vehicle_context_skips_when_not_explicitly_provided|DocumentCacheValidationTest::test_detects_manually_corrupted_cache|DocumentCacheValidationTest::test_repair_fixes_inconsistencies|FacturXEligibilityTest::test_posted_invoices_are_eligible"`
  Result: 32 assertions, warnings only.
- PASS - PostgreSQL `EnrichmentReviewServiceTest` after UUID fixture correction:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=houssamr DB_PASSWORD= php artisan test -c phpunit-pgsql.xml --filter="EnrichmentReviewServiceTest"`
  Result: 32 assertions, warnings only.
- PASS - full SQLite suite:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan test`
  Result: 698 passed, 3 incomplete, 6018 warnings, 28107 assertions.
- PASS - database-per-tenant PostgreSQL seeder:
  `APP_ENV=local APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=central DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_CENTRAL_DATABASE=autoerp_test DB_USERNAME=houssamr DB_PASSWORD= CACHE_STORE=array TENANCY_DB_PER_TENANT=true php artisan db:seed --class="Database\\Seeders\\TwoTenantIsolationDemoSeeder" --force`
  Result: provisioned separate tenant databases for `demo-tenant-a` and `demo-tenant-b`; seeder reported no cross-tenant leak.
- FAIL - full PostgreSQL suite:
  `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=houssamr DB_PASSWORD= php artisan test -c phpunit-pgsql.xml --stop-on-failure`
  Result: 39 failures after 396 passed tests, 1079 warnings, 4563 assertions. Remaining failures are outside the fiscal projection buckets and cluster around existing PostgreSQL fixture/constraint mismatches in POS and one product scale assertion.

Closed in this pass:

- `AccountChargePayload` and `AccountPaymentPayload` now preserve their original source payload when hydrated from a database array. This keeps replay comparison stable across PostgreSQL `jsonb` key ordering without weakening typed access.
- `DocumentFactory::posted()` now supplies valid fiscal core fields (`fiscal_hash`, `chain_sequence`) so sealed document fixtures satisfy the PostgreSQL fiscal CHECK constraint.
- `DocumentCacheValidationService::findInconsistencies()` now normalizes raw database numeric values to two-decimal report scale. PostgreSQL stores the column with three-decimal scale, while the service contract reports currency-scale values.
- PostgreSQL-incompatible fixture IDs were corrected to UUIDs where the real schema uses UUID columns: POS tolerance GL `source_id`, document vehicle context `vehicle_id`, product enrichment `tracking_id`, and product `platform_submission_id`.
- `FacturXEligibilityTest` posted-invoice fixture now includes fiscal hash and sequence because an explicitly sealed document must carry fiscal core.
- `DocumentCacheValidationTest` now distinguishes persisted column scale (`80.000`) from service report scale (`80.00`).

Remaining full-PG blockers:

- `Tests\Unit\POS\GrandtotalServiceTest`, `ReportGenerationServiceTest`, `ZReportHashServiceTest`: PostgreSQL rejects stale POS fixtures that violate current fiscal constraints, including `pos_grandtotal_events.fiscal_hash` format, `pos_receipts_void_logic`, sealed receipt immutability, and `pos_shifts_one_open_per_terminal`.
- `Tests\Unit\POS\ReceiptPaymentServiceTest`, `ReceiptReturnServiceTest`, `ReceiptVoidServiceTest`, `ReportGenerationIdempotencyTest`, `HeldOrderServiceTest`: the broad suite still has PostgreSQL-only failures in POS paths outside the fiscal event ingestion/projection bucket.
- `Tests\Unit\Product\ProductServiceUpsertTest::test_upsert_sets_tax_rate`: PostgreSQL returns stored decimal scale as `19.00`, while the test expects SQLite-style `19`.

Decision:

- Do not open the PR yet. The requested fiscal buckets and full SQLite suite are green, and the database-per-tenant seeder passes, but the full PostgreSQL suite is not green.
- `./scripts/preflight.sh` was not run because the full PostgreSQL verification gate already fails on the broad POS/product blockers above; running preflight would not produce a mergeable result until those are resolved or explicitly scoped out.
