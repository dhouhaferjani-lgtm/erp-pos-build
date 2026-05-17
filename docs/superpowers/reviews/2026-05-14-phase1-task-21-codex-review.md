# Codex Review — Phase 1 Task 21 (`77f4674f0`)

**Verdict: BLOCK**

Task 21 is not safe to merge as-is. The new `PosCoreReceiptProjection` is production-tagged and can now create `pos_receipts` from fiscal events, but the legacy receipt-sync and server-payment write paths remain live. The projector also consumes a different `payment_lines[]` schema than the Task 16 parser / Task 19 ingestor accept, so a valid ingested `SALE_RECEIPT` can be persisted and queued for projection, then fail when the projector reads it.

| Severity | ID | File:Line | Description | Required Fix |
|---|---|---|---|---|
| BLOCKER | T21-B1 | `apps/api/app/Modules/POS/routes.php:85`, `apps/api/app/Modules/POS/routes.php:102`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:54` | The commit production-tags the new fiscal-event projector while retaining live legacy receipt write routes. This creates the two-model coexistence D8 forbids. | Retire/reject `/pos/receipts/sync` and new-sale server payment/finalization paths before making the projector active, or gate the projector until the §14.1/§14.2 cleanup lands. |
| BLOCKER | T21-B2 | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:408`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:410`, `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:646`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:810` | Payment-line schema drift: parser/ingestor accept `{method, amount, tendered, change}` while the projector requires `payment_method_id` and `method_code`. A valid outbox event reaches projection and fails with `InvalidArgumentException`. | Make the canonical DTO/parser/projector use one exact schema, then add an ingest-to-projector regression using that schema. |
| BLOCKER | T21-B3 | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:111`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:185`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:217`, `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:64` | The idempotency check is check-then-insert against a UNIQUE column, with no `ON CONFLICT ON CONSTRAINT` or idempotent `QueryException` handling. Concurrent replay can still surface a PG unique violation. | Insert the receipt row through a PG-safe `INSERT ... ON CONFLICT ON CONSTRAINT pos_receipts_fiscal_event_id_unique DO NOTHING RETURNING id`, or otherwise scope duplicate handling outside an aborted transaction. |
| BLOCKER | T21-B4 | `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:294`, `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:316` | The Task 21 test matrix covers one cash payment only. It does not cover split payments, voucher tender/redemption, voucher+stock, or a product-backed line that actually decrements stock, despite those being projector-owned effects. | Add discriminated business-effect fixtures for split tenders, voucher tender/redemption, stock movement, and combined voucher+stock. |
| P1 | T21-P1 | `.github/workflows/ci.yml:340`, `.github/workflows/ci.yml:366`, `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:115` | `PosCoreReceiptProjectionTest` is not in the PG merge-gate filter even though it writes `pos_receipts` / child rows under PG-only FKs, CHECKs, triggers, and BYTEA behavior. | Add `PosCoreReceiptProjectionTest` to the PG filter with an explanatory comment. |
| P1 | T21-P2 | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:197`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:198`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:675`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:116` | The all-zero placeholder hashes are DB-valid because the original PG CHECK only enforces length, but they are auditor-confusing in hash-named NF525 columns. | Replace the sentinel with real section hashes derived from canonical projection data, or explicitly deprecate/rename/export-hide these columns before audited output uses them. |
| P3 | T21-P3 | `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:37`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:74` | Stale docblocks still say the production tagged projector set is empty until Tasks 21/22, but Task 21 now tags `pos_core_receipt`. | Update the comments so future reviewers do not reason from a false production state. |

---

## Findings

### T21-B1 — Legacy new-sale write paths coexist with the active fiscal-event projector

**File:line:** `apps/api/app/Modules/POS/routes.php:85`, `apps/api/app/Modules/POS/routes.php:102`, `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:54`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:505`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:54`

Task 21 registers `PosCoreReceiptProjection` in production (`POSServiceProvider.php:44-47`), so fiscal-event ingestion can now create `pos_receipts`, lines, VAT rows, payments, voucher effects, and stock effects from `SALE_RECEIPT` events. At the same time, the old receipt sync route is still live (`routes.php:85` → `SyncController::syncReceipts()` → `ReceiptSyncService::syncBatch()`), and the server payment/finalization route is still live (`routes.php:102` → `ReceiptController::storePayments()` → `ReceiptPaymentService::processReceiptPayments()`).

That is exactly the coexistence the approved source-of-truth forbids. SoT D8 says there is one fiscal pattern and no two-model coexistence (`docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:18`, `:261`). Spec v7 says `/pos/receipts/sync` is retired (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:590`, `:619-625`) and backend new-sale authoring routes are retired/rejected (`:604`, `:644-645`). The commit docblock explicitly says the legacy endpoint continues to work until Task 25 (`ReceiptSyncService.php:54-60`, `ReceiptPaymentService.php:44-50`), but the spec does not permit an active projector plus a still-live new-sale legacy writer.

**Required fix:** either land the §14.1/§14.2 route/controller/client cleanup before this projector is production-active, or keep the projector untagged/gated until those paths are retired. A deprecation docblock is not enough.

### T21-B2 — Projector payment-line schema drifts from the parser/ingestor contract

**File:line:** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:408`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:410`, `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:646`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:810`, `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php:473`, `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:108`

The accepted canonical payload shape is not the shape the projector consumes. `SaleReceiptPayload` documents `payment_lines` as `{payment_method_id, amount, tendered, change}` (`SaleReceiptPayload.php:18`) and its registry test uses that shape (`FiscalEventPayloadRegistryTest.php:108-110`). The Task 19/20 ingestion fixtures go further and use `{method, amount, tendered, change}` with no `payment_method_id` or `method_code` (`OutboxIngestorTest.php:810-811`, `FiscalEventIngestionEndpointTest.php:473-474`). `StrictCanonicalParser` validates only monetary fields inside `payment_lines[]` (`StrictCanonicalParser.php:646`) and does not require `payment_method_id` or `method_code`.

`PosCoreReceiptProjection`, however, requires both `payment_method_id` and `method_code` (`PosCoreReceiptProjection.php:408-410`). Therefore an event shaped like the current Outbox/Ingestion tests passes parser validation, is stored in `fiscal_events`, gets a pending projection row, and then fails in the projector with `InvalidArgumentException` from `FiscalPayloadArrayGuards::requireString()` (`FiscalPayloadArrayGuards.php:39-49`, `:116-123`). It does not silently insert wrong data, but it does break the projection pipeline for a schema the server currently accepts as valid.

**Required fix:** lock one exact canonical payment-line schema across `SaleReceiptPayload`, `StrictCanonicalParser`, ingestion fixtures, endpoint fixtures, and `PosCoreReceiptProjection`. If POS-core needs mirrored `payment_methods.id`, the canonical payload contract must require and validate it before the event is admitted as parsed.

### T21-B3 — Receipt insert idempotency is still check-then-insert, not a PG-safe conflict primitive

**File:line:** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:111`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:132`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:185`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:217`, `apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:64`

The migration makes `pos_receipts.fiscal_event_id` nullable and UNIQUE (`2026_05_14_100005...php:64-65`), but the projector uses two `exists()` checks followed by Eloquent `save()` (`PosCoreReceiptProjection.php:111-132`, `:185-217`). There is no lock, no `ON CONFLICT ON CONSTRAINT`, and no `QueryException` path that re-reads the existing receipt as an idempotent replay.

The inner check reduces the race window but does not close it. Two workers can both pass the transaction-local `exists()` check, then the second insert hits `pos_receipts_fiscal_event_id_unique`. On PostgreSQL, that aborts the transaction. This violates the standing pattern already enforced in Task 19 for UNIQUE-backed inserts.

**Required fix:** use a single insert primitive that targets `pos_receipts_fiscal_event_id_unique`, such as `INSERT ... ON CONFLICT ON CONSTRAINT pos_receipts_fiscal_event_id_unique DO NOTHING RETURNING id`, and branch on the returned row. If Eloquent stays, duplicate handling must happen outside an aborted transaction and must be constrained to the named idempotency key.

### T21-B4 — Business-effect coverage misses the variants this projector owns

**File:line:** `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:294`, `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:316`

The Task 21 test fixture uses a single cash payment line (`PosCoreReceiptProjectionTest.php:294-300`) and a line without `product_id` (`:306-314`). That means the tests do not exercise split payments, voucher tendering/redemption, stock decrement, or the combined voucher+stock path, even though the projector claims ownership of `pos_receipt_payments`, voucher redemption, and stock movement (`PosCoreReceiptProjection.php:381-387`, `:491-525`, `:537-636`).

This is not just a cosmetic gap. The code has separate branches for instrument-required voucher tenders, `PaymentInstrumentKind::from()`, `VoucherRedemptionService::redeem()`, `StockLevel::lockForUpdate()`, insufficient stock logging, and stock movement creation. A single cash/no-product fixture cannot detect schema drift or transaction rollback bugs in those branches.

**Required fix:** add fixtures covering split payments, store-voucher payment with ledger redemption, product-backed stock decrement, and a combined voucher+stock sale. Include assertions for rollback when a voucher redemption fails after receipt/payment rows have been staged.

### T21-P1 — Projector test is missing from the PG merge gate

**File:line:** `.github/workflows/ci.yml:340`, `.github/workflows/ci.yml:366`, `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php:115`

The PG merge-gate filter includes Task 19/20 tests but not `PosCoreReceiptProjectionTest` (`ci.yml:340-366`). This test writes the tables whose production behavior depends on PostgreSQL-only DDL: `pos_receipts.fiscal_event_id` FK (`2026_05_14_100005...php:73-77`), BYTEA round-trip (`PosCoreReceiptProjectionTest.php:130-134`), receipt CHECK constraints (`2026_01_08_190637...php:113-121`), and the immutability trigger on sealed receipts. SQLite coverage is useful, but it is not the production truth for this projector.

**Required fix:** add `PosCoreReceiptProjectionTest` to the PG merge-gate filter and document that it covers the Task 21 projection-row write path under production constraints.

### T21-P2 — All-zero section-hash sentinel is accepted but audit-hostile

**File:line:** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:197`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:198`, `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:675`, `apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php:116`

`placeholderHash()` returns `str_repeat('0', 64)` and writes it to `vat_breakdown_hash` and `payment_methods_hash`. The original PG constraint is named `pos_receipts_hash_length` and only checks `length(...) = 64` (`2026_01_08_190637...php:116-120`); there is no regex constraint on those two columns. The sentinel is therefore DB-valid. It would also pass a lowercase-hex regex if one existed.

The problem is audit semantics. These columns are still named and commented as NF525 hash inputs (`2026_01_08_190637...php:127-129`, plus the VAT/payment table comments at `2026_01_08_190639...php:64` and `2026_01_08_190640...php:65`). Persisting an all-zero value in hash-named audit columns is likely to be read as a real hash or a broken hash chain unless every export/report path is reworked first.

**Required fix:** either calculate deterministic section hashes from the canonical projection data, or explicitly deprecate these columns in schema comments and ensure Nf525/JET/export readers use `fiscal_events.current_hash` and canonical bytes instead.

### T21-P3 — Stale comments still describe a pre-Task-21 registry state

**File:line:** `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php:37`, `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php:74`

`FiscalEventProjectionRegistry` still says the container registry is empty until Tasks 21/22 (`FiscalEventProjectionRegistry.php:37-39`). `OutboxIngestorTest` has the same stale class comment (`OutboxIngestorTest.php:74-80`), even though its `setUp()` correctly isolates the registry from the now-tagged production projector (`:135-150`). The implementation comments in `POSServiceProvider` are current; these two comments are not.

**Required fix:** update the comments to say the production set now includes `pos_core_receipt`, and tests override the registry when they need deterministic fake projectors.

---

## C1-C5 Adjudication

**C1 — Legacy coexistence:** BLOCKER. Spec v7 does not permit "retained legacy + deprecation docblock" for new-sale `SALE_RECEIPT` authoring once the fiscal-event projector is active. `/pos/receipts/sync` is still routed (`routes.php:85`) and the server payment/finalization route is still routed (`routes.php:102`). `ReceiptSyncService` and `ReceiptPaymentService` explicitly say those surfaces continue working until Task 25 (`ReceiptSyncService.php:54-60`, `ReceiptPaymentService.php:44-50`). This produces concurrent legacy and fiscal-event write paths; see T21-B1.

**C2 — Zero-sentinel hashes:** DB-valid. The original PG CHECK is length-only (`pos_receipts_hash_length`) and does not regex-check `vat_breakdown_hash` or `payment_methods_hash` (`2026_01_08_190637_create_pos_receipts_table.php:116-120`). `str_repeat('0', 64)` passes. I still raised T21-P2 because the value is auditor-confusing in hash-named NF525 columns.

**C3 — Payment-line schema asymmetry:** BLOCKER. An event with `{method, amount, tendered, change}` passes the parser because `StrictCanonicalParser` only validates `amount`, `tendered`, and `change` as money strings (`StrictCanonicalParser.php:646`), and the Outbox/Endpoint tests use exactly that shape (`OutboxIngestorTest.php:810-811`, `FiscalEventIngestionEndpointTest.php:473-474`). The projector then fails loudly with `InvalidArgumentException` when requiring `payment_method_id` / `method_code`; see T21-B2.

**C4 — Receipt number UNIQUE:** The scoped UNIQUE exists on `(tenant_id, company_id, location_id, receipt_number)` (`2026_03_24_100000_scope_receipt_number_unique_to_tenant.php:25-27`). The legacy server generator uses `{location_code}-{terminal_code}-{year}-{sequence}` (`ReceiptCreationService.php:790-796`), while the projector uses `FE-{terminalCode}-{year}-{sequence}` (`PosCoreReceiptProjection.php:284-289`). A default legacy server-created receipt is unlikely to collide with the new `FE-...` format, but the still-live sync path accepts client-supplied `receipt_number` (`ReceiptSyncService.php:492`), so coexistence keeps collision risk alive.

**C5 — HasUuids deterministic-id:** Clean. Laravel 12's `HasUuids` uses `HasUniqueStringIds`; `uniqueIds()` only marks the key for unique IDs (`HasUniqueStringIds.php:39-42`) and generation happens only for empty unique ID columns in Eloquent's normal unique-id path. The trait source does not unconditionally override an already assigned `$receipt->id`. The `new Receipt(...); $receipt->id = $receiptId; $receipt->save();` pattern is safe on this point.

---

## V1-V5 Verification

**V1 — Registry / payload / projector field names:** Not clean. `FiscalEventPayloadRegistry` maps `SALE_RECEIPT` to `SaleReceiptPayload` (`FiscalEventPayloadRegistry.php:37-39`), whose `fromArray()` requires only top-level `payment_lines` (`SaleReceiptPayload.php:49-66`) and whose tests allow `payment_method_id + tendered/change` but no `method_code` (`FiscalEventPayloadRegistryTest.php:108-110`). The ingestor fixtures use `method` instead of `payment_method_id`. The projector requires `payment_method_id` and `method_code`. This is T21-B2.

**V2 — `fiscal_event_id` UNIQUE / race backstop:** Partially clean schema, not clean code. The column is nullable and UNIQUE (`2026_05_14_100005...php:64-65`), and the FK is PG-only (`:73-77`). The projector does not use `ON CONFLICT` and does not catch a duplicate insert as idempotent re-arrival; see T21-B3.

**V3 — Provider tag wiring:** Clean. `POSServiceProvider::register()` calls `$this->app->tag([PosCoreReceiptProjection::class], FiscalEventProjector::class)` (`POSServiceProvider.php:44-47`), and `FiscalServiceProvider` constructs the singleton registry from `$app->tagged(FiscalEventProjector::class)` (`FiscalServiceProvider.php:28-33`). The Task 21 test asserts the tag (`PosCoreReceiptProjectionTest.php:205-221`).

**V4 — PG merge-gate filter:** Not clean. `PosCoreReceiptProjectionTest` is absent from `.github/workflows/ci.yml:365-366`. Raised as T21-P1.

**V5 — `app()` helper usage:** Clean in the diff. `git show 77f4674f0 -- '*.php' | rg -n "\bapp\s*\("` returned no matches. `$this->app` and `$app->tagged()` are container objects, not the forbidden helper.

---

## Standing Pattern Checks

- **PHP array casts instead of guards:** The new projector uses `FiscalPayloadArrayGuards` for canonical payload reads. The retained legacy services still contain old cast patterns, but I did not raise them separately because T21-B1 requires retiring those live legacy paths rather than modernizing them.
- **Free-form string fields without regex validation:** Not clean for `payment_lines[]` item keys. The parser does not require exact item-key shape for payment lines; this is part of T21-B2.
- **`try/catch (Throwable)` missing on resolver/downstream calls:** No direct `catch (Throwable)` issue found in the projector diff. Terminal/user/payment-method lookups catch `QueryException`; `VoucherRedemptionService::redeem()` is allowed to abort and roll back the projection transaction.
- **`ON CONFLICT ON CONSTRAINT` missing for UNIQUE-constrained inserts:** Violated for `pos_receipts.fiscal_event_id`; see T21-B3.
- **Plan-vs-code field name drift:** Violated for payment-line fields; see T21-B2.
- **Stale docblocks:** Violated; see T21-P3.
- **Test matrix missing variants:** Violated; see T21-B4.

---

## Verification I Ran

```bash
pwd && git status --short && git show --stat --oneline 77f4674f0
git show --name-only --format=fuller 77f4674f0
git show --unified=80 -- apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php
rg --files docs/superpowers/reviews | sort | tail -n 20
rg -n "D8|two-model|coexist|legacy|canonical|5\.0|7\.4|14\.1|14\.3|18" docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md docs/superpowers/coordination/2026-05-14-pos-fiscal-engine-session-handoff.md
sed -n '1,220p' docs/superpowers/reviews/2026-05-14-phase1-task-20-codex-review.md
sed -n '1,220p' docs/superpowers/reviews/2026-05-14-phase1-task-19-codex-review.md
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php | sed -n '660,745p'
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php | sed -n '280,315p'
nl -ba apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php | sed -n '1,240p'
nl -ba apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php | sed -n '240,520p'
nl -ba apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php | sed -n '520,720p'
rg -n "class FiscalEventPayloadRegistry|SaleReceiptPayload|PAYLOAD_KEYS|payment_lines|payment_methods_hash|vat_breakdown_hash|fiscal_event_id|receipt_number|unique|unique\(" apps/api/app apps/api/database packages -g '*.php'
rg --files apps/api/app apps/api/database | rg 'FiscalEventPayloadRegistry|StrictCanonicalParser|create_pos_receipts|pos_receipts_table|FiscalPayloadArrayGuards|HasUuids.php$'
rg -n "class StrictCanonicalParser|PAYLOAD_KEYS|payment_lines|method_code|tendered|change|voucher_redemptions|class FiscalEventPayloadRegistry|SaleReceiptPayload::fromArray|fromArray\(.*SaleReceipt" apps/api/app apps/api/tests
rg -n "create\('pos_receipts'|Schema::create\('pos_receipts'|vat_breakdown_hash|payment_methods_hash|CHECK|receipt_number.*unique|unique\(\['terminal_id'|unique\(\['tenant_id'.*receipt_number" apps/api/database/migrations
nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php | sed -n '1,220p'
nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php | sed -n '1,180p'
nl -ba apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php | sed -n '1,260p'
nl -ba apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php | sed -n '260,520p'
nl -ba apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php | sed -n '520,860p'
rg --files apps/api/vendor | rg '/HasUuids\.php$|/HasUniqueStringIds\.php$'
nl -ba apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUuids.php
nl -ba apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUniqueStringIds.php | sed -n '1,180p'
nl -ba apps/api/database/migrations/2026_01_08_190637_create_pos_receipts_table.php | sed -n '1,150p'
nl -ba apps/api/database/migrations/2026_03_24_100000_scope_receipt_number_unique_to_tenant.php | sed -n '1,70p'
nl -ba apps/api/database/migrations/2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php | sed -n '1,115p'
nl -ba .github/workflows/ci.yml | sed -n '330,380p'
git show 77f4674f0 -- '*.php' | rg -n "\bapp\s*\(" || true
rg -n "app\s*\(|->tagged\(|tag\(" apps/api/app/Modules/Fiscal apps/api/app/Modules/POS apps/api/tests/Feature/Fiscal -g '*.php'
nl -ba apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php | sed -n '1,220p'
nl -ba apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php | sed -n '1,160p'
nl -ba apps/api/app/Modules/POS/Providers/POSServiceProvider.php | sed -n '1,100p'
nl -ba apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php | sed -n '1,260p'
nl -ba apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php | sed -n '260,560p'
nl -ba apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php | sed -n '1,220p'
rg -n "payment_lines|method_code|tendered|change|voucher|stock|split|variant|PosCoreReceiptProjection" apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php apps/api/tests/Unit/Fiscal apps/api/tests/Feature/Fiscal
rg -n "ReceiptSyncService|sync/receipts|receipts/sync|/pos/receipts/sync|SyncReceiptsRequest|ReceiptPaymentService|processReceiptPayments|storePayments|routes.*receipts|pos/sync/fiscal-events" apps/api/app/Modules/POS apps/api/app/Modules/Fiscal apps/api/routes apps/web apps/pos -g '*.{php,ts,tsx}'
nl -ba apps/api/app/Modules/Fiscal/Domain/DTOs/FiscalPayloadArrayGuards.php | sed -n '1,180p'
nl -ba apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php | sed -n '780,825p'
nl -ba apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php | sed -n '460,490p'
nl -ba apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php | sed -n '96,116p'
rg -n "FiscalPayloadValidationException|PayloadValidation|ValidationException" apps/api/app/Modules/Fiscal apps/api/app/Modules/POS
nl -ba apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php | sed -n '160,230p'
nl -ba apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php | sed -n '640,700p'
nl -ba apps/api/app/Modules/POS/routes.php | sed -n '72,108p'
nl -ba apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php | sed -n '45,75p'
nl -ba apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php | sed -n '500,520p'
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php | sed -n '48,64p'
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php | sed -n '34,54p'
nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '580,610p'
nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '609,676p'
nl -ba docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md | sed -n '760,766p'
nl -ba docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md | sed -n '14,28p'
nl -ba docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md | sed -n '256,263p'
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php | sed -n '780,820p'
nl -ba apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php | sed -n '486,505p'
rg -n "receipt_number|receiptNumber|FE-|MAIN-|POS01|generateReceipt" apps/pos/src apps/web/src | head -n 80
php artisan test --filter=PosCoreReceiptProjectionTest
php artisan test --filter=StrictCanonicalParserTest
rg -n -F '(string) $' apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php || true
rg -n -F 'catch (Throwable' apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php || true
rg -n -F 'ON CONFLICT' apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php || true
rg -n "chain_sequence|hash_sequence|app\s*\(" apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php apps/api/app/Modules/POS/Providers/POSServiceProvider.php apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php || true
git show --check 77f4674f0
```

Test results:

- `php artisan test --filter=PosCoreReceiptProjectionTest`: 8 tests, 29 assertions, passed with PHPUnit deprecation/file manifest warnings.
- `php artisan test --filter=StrictCanonicalParserTest`: 82 tests, 455 assertions, passed with PHPUnit deprecation/file manifest warnings.
