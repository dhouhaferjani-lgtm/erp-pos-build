# Task 21 — Opus Adversarial Review

**Subject:** `77f4674f0` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`).
**Scope:** `PosCoreReceiptProjection` (POS-core, always-active `FiscalEventProjector` for `SALE_RECEIPT`) + `POSServiceProvider::register()` tag wiring + docblock-only deprecations on `ReceiptSyncService` / `ReceiptPaymentService` + regression updates to `OutboxIngestorTest` + `FiscalEventProjectionRegistryTest` + new Feature test `PosCoreReceiptProjectionTest`.

## Verdict: REQUEST-CHANGES

The §7.4 split contract is structurally honored — zero outbound coupling to Treasury operational classes, only the permitted inbound `payment_methods` mirror lookup. Idempotency anchor uses the Task 11 `pos_receipts.fiscal_event_id` UNIQUE; the guard fires both pre-transaction and inside the transaction (concurrency-safe). The provider wiring shape matches Task 18's tagged-set contract. The standing patterns (no `(type) $array['key']` casts, `FiscalPayloadArrayGuards` everywhere, `QueryException` wraps around UUID lookups, constructor injection, `chain_sequence` wording, `private readonly` deps, no `app()` helper) are all observed.

However, two genuine BLOCKER-class issues land:

1. **F1 (BLOCKER) — `vat_breakdown_hash` / `payment_methods_hash` zero-sentinel breaks the still-wired legacy `pos:verify-chains` operator command** for every projection-written `pos_receipts` row. The legacy verifier (`ReceiptHashService::verifyHash` reads `serializeForHashing(receipt)` which folds in those two columns) will return `false` for every projection-written receipt because the stored `fiscal_hash` is `sha256(canonical_bytes)`, NOT the legacy `sha256(receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash)` shape. Operators running `pos:verify-chains` against the new dataset will see every chain reported as "Sequence #N (hash)" broken. The command is still registered in `POSServiceProvider::boot()` and has its own test suite. The spec defers retirement to Tasks 25/28 but the projection rows land **today** in PR #124.

2. **F2 (BLOCKER) — Stock decrement + voucher redemption are never test-exercised.** The fixture lines have no `product_id`, the fixture payment lines have no `store_voucher` instrument. `decrementStock()` and `redeemVouchers()` together amount to ~120 lines of newly-relocated logic — the most operationally-significant new responsibility of this projector — and zero tests touch them. The idempotency test's `assertSame($stockMovementsAfterFirst, …)` is vacuous because both sides are 0. This is a hard correctness gap: any of (a) `StockMovement::create` column name regression, (b) `bccomp` scale drift, (c) `VoucherRedemptionService::redeem` signature change, (d) currency / cashier propagation bug would land in production undetected.

There is also one P1 (test gap on the cross-tenant `payment_method_id` FK-rejection that the docblock promises), one P2 (CI PG-merge-gate filter not extended), two P2 docblock / behaviour drift findings, and three P3 cleanups. The C2/C5 concerns the implementer flagged are real and adjudicated below; C1/C3/C4 are acceptable deferrals.

## Findings summary

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | BLOCKER | app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:197-198,673-676 | Zero-sentinel `vat_breakdown_hash`/`payment_methods_hash` breaks still-wired `pos:verify-chains` for every projection-written row. |
| F2 | BLOCKER | tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php (entire fixture set) | Stock decrement + voucher redemption code paths are untested — the fixture line has no `product_id` and no payment line has a `store_voucher` instrument. |
| F3 | P1 | tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php | Cross-tenant `payment_method_id` rejection promised by projector docblock (`writePayments()` §"Cross-tenant … is rejected") has no test. |
| F4 | P2 | .github/workflows/ci.yml:~366 | `PosCoreReceiptProjectionTest` not added to CI PG-merge-gate filter despite inserting into PG-CHECK-constrained `pos_receipts` and FK-bound `pos_receipt_payments`. |
| F5 | P2 | app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:438-444 | Docblock claim "foreign payment_method_id resolves to null and the row insert crashes on the NOT NULL FK" is misleading — the raw payload string is the value written; the resolved `$method` only feeds `$payment_type`. |
| F6 | P2 | app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:81 | `SCALE = 3` matches the `Receipt::$casts` claim but the migration column is `decimal(12, 2)`. PG silently truncates the projector's normalized 3-decimal strings; on SQLite the test passes the strings through. Pre-existing model/migration drift inherited; the constant should be documented as "matches model cast, not migration scale". |
| F7 | P3 | app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:470 | `unset($event)` inside the `foreach` body has no effect — it removes the local parameter reference, not the array element. |
| F8 | P3 | app/Modules/POS/Application/Services/ReceiptPaymentService.php:44 | Inline citation `(:297)` is stale: the inserted docblock pushed the actual `ReceiptPayment::create` to `:306`. |
| F9 | P3 | tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php | No assertion that `$receipt->id === $receiptId` is preserved through the `new Receipt([…]); $receipt->id = …; $receipt->save();` HasUuids pattern. |

---

### F1 — BLOCKER — Zero-sentinel hash columns break the still-wired `pos:verify-chains` operator command

**Files.**
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:197-198` (the writes) and `:673-676` (the `placeholderHash()` helper).
- `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:218` (the verifier call site).
- `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:62-72,189-199` (the legacy-chain serializer and verifier).
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:53-60` (the command is still registered).

**Observation.** The projector writes `vat_breakdown_hash = '0'×64` and `payment_methods_hash = '0'×64` to every `pos_receipts` row (deliberate sentinel — the docblock at `:663-676` explains that the canonical hash is now `fiscal_events.current_hash` mirrored into `fiscal_hash`, and that the legacy per-section hashes are deprecated). It also writes `fiscal_hash = $event->current_hash`, i.e. `sha256(canonical_bytes)` shape.

But `pos:verify-chains` is still registered in `POSServiceProvider::boot()` and still runs `ReceiptHashService::calculateHash($receipt, $previousHash)` for every receipt (`VerifyPosChainCommand.php:218`). That method serializes the receipt via `serializeForHashing()` which produces the pipe-string `receipt_number|posted_at|total|currency|vat_breakdown_hash|payment_methods_hash` (`ReceiptHashService.php:62-72`) and computes the SHA-256 over the resulting string folded with `previous_hash` + `genesis_seed`. The result is **not** `sha256(canonical_bytes)`; it cannot match the stored `fiscal_hash`. Every projection-written row therefore returns `"Sequence #N (hash)"` from the verifier — every chain looks broken.

This is not a hypothetical. The command exists, has its own test suite (`tests/Feature/POS/VerifyPosChainCommandTest.php`), and is the operator-facing command an SRE would run after rebuild to confirm chain integrity. If anyone runs it on the new dataset before Task 25 retires it, every chain is red.

**Why it's a BLOCKER, not a P1.** The §7.4 split landing today persists projection rows that the legacy operator gate categorically rejects. Spec §15.1 lines name `fiscal:verify-event-chain` (Task 31) as the new truth surface, but Task 31 has not landed yet. There is no migration plan documented for the interim window in which (a) projection rows exist, (b) `fiscal:verify-event-chain` does not, and (c) `pos:verify-chains` is still wired. The fix has three reasonable shapes:

1. Compute the legacy hashes for compatibility — re-execute `ReceiptHashService::hashVATBreakdown()` and `hashPaymentMethods()` over the projection-written sub-rows and store the resulting 64-char SHA-256. Cost: ~10 lines plus two `use` imports; preserves verifier compatibility through the rollout window.
2. Detect projection-written rows in the verifier and short-circuit them (e.g., `if ($receipt->fiscal_event_id !== null) { /* fiscal_events is truth; skip this row */ }`). Cost: ~5 lines in `VerifyPosChainCommand::verifyReceiptChain()`; preserves the operator surface but admits a documented blind spot.
3. Stop registering `VerifyPosChainCommand` when projection rows exist on the tenant. Cost: provider-level gate; surfaces as `command not found`.

Option 1 is the lowest-friction interim choice; it costs nothing at the projector boundary because the inputs (lines + payment lines + VAT detail rows) are already being written in the same transaction. Option 2 keeps the docblock's "deprecated" framing intact but admits the blind spot. The current implementation chooses neither.

**Reference.** SoT v3 §13.6 / D16 (the projector boundary is correct — this is about the legacy verifier, not the projector logic). Spec v7 §15.1 (`fiscal:verify-event-chain` as canonical replacement) is the eventual fix; the interim window is the gap.

---

### F2 — BLOCKER — Stock decrement + voucher redemption are never test-exercised

**File.** `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` — the entire `storeSaleReceiptFiscalEvent()` fixture set.

**Observation.** The projector consolidates four newly-relocated responsibilities into `apply()`: `pos_receipts` write, line writes, VAT writes, payment writes, voucher redemption (`:491-527`), stock decrement (`:537-637`). The fixture at `:307-323` produces:

- `lines[0]` with `sku=X, unit_price=10.00, line_total=10.00, quantity=1, tax_rate=0` — and **no `product_id`** (it's optional and omitted).
- `payment_lines[0]` with `method_code='CASH'` — and **no `instrument_type`** (the cash path explicitly excludes the `store_voucher` branch).

Therefore:
- `decrementStockForLines()` iterates `lines` and skips because `$productId === null` (`:551`). `decrementStock()` itself is never invoked. `StockMovement::create` is never invoked. `StockLevel::lockForUpdate` is never tested. None of the 120+ lines of stock logic is covered.
- `redeemVouchers()` iterates `payment_lines` and skips because `$instrumentType !== 'store_voucher'` (`:502`). `VoucherRedemptionService::redeem` is never invoked.

The idempotency test (`:149`) asserts `$stockMovementsAfterFirst === DB::table('stock_movements')->count()` — but both are 0. The assertion is vacuous; it would pass even if the second-apply call duplicated stock movements. The plan §1623 wording ("Creates: … voucher redemption, stock movement") is the central new responsibility of this projector and it is not test-locked.

**Why it's a BLOCKER, not a P1.** The "relocated from `ReceiptSyncService:683-710` / `:713-735`" docblock is a load-bearing claim — these are the lines the §7.4 split mandates be moved into the projector. Standing pattern #1 (handoff §4.2) explicitly says "Per-task TDD + dual review is non-negotiable." Untested code in a TDD task with this footprint is exactly what Codex caught on Tasks 8/14/15/16/18/19. The legacy `ReceiptSyncService` has its own dedicated tests (`ReceiptSyncServiceVoucherRedemptionTest.php`, etc.); the projector inherits the responsibility without inheriting the coverage.

**Minimum remediation.** Three additional tests:

1. `test_decrement_stock_for_product_line` — fixture has a `product_id`, `StockLevel::create()` pre-seeds a row, assert one `stock_movements` row with `movement_type=Issue, reason=POSSale, quantity_before/after`, and assert `StockLevel.quantity` decremented.
2. `test_redeem_store_voucher_instrument_calls_redemption_service` — fixture's `payment_lines[0]` has `instrument_type='store_voucher', instrument_serial='V-123'`, mock or stub `VoucherRedemptionService` (or use the real one with a pre-seeded `vouchers` row), assert one `voucher_ledger` row with `Redeemed` event and the right `applied_amount` + `voucher_code`.
3. `test_idempotency_does_not_duplicate_stock_movements_or_voucher_ledger_rows` — same fixture as #1+#2, apply twice, assert counts unchanged.

Bonus: `test_voucher_redemption_failure_rolls_back_pos_receipts_row` — assert that throwing from the redemption service rolls back the entire projection transaction.

**Reference.** Plan §1623 ("Creates: … voucher redemption, stock movement"); handoff §4.2 standing pattern #1 (TDD discipline).

---

### F3 — P1 — Cross-tenant `payment_method_id` rejection has no test

**File.** `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`.

**Observation.** The projector's `writePayments()` docblock at `:389-391` promises: "Cross-tenant `payment_method_id` is rejected: the `PaymentMethod` lookup is scoped by the fiscal event's tenant + company so a foreign FK lands as `null` and the row insert fails on the NOT NULL FK — atomic rollback." This is a load-bearing security claim about the SoT §13.6/D16 bounded-modules seam. The test suite does not exercise it.

A regression that (a) widened the `PaymentMethod` lookup scope, or (b) silently coerced a missing method to a default would land undetected. The migration `2026_03_02_100000_make_payment_method_id_nullable_on_payments_table.php` shows the historical direction of drift is towards relaxing nullability — exactly the kind of change that could re-emerge.

**Remediation.** Add one test that creates a `PaymentMethod` scoped to a DIFFERENT tenant from the fiscal event, points the `payment_lines[0].payment_method_id` at it, asserts the apply call throws an FK violation (PG) or QueryException (SQLite), and asserts zero `pos_receipts` rows are persisted.

**Reference.** SoT v3 §13.6 / D16; spec v7 §5.0 (boundary discipline).

---

### F4 — P2 — CI PG-merge-gate filter not extended

**File.** `.github/workflows/ci.yml:~366`.

**Observation.** The implementer's status report claims no filter change is needed because "tests are pure Laravel-on-SQLite." This is partially correct — the test class does not directly assert PG-specific behaviour. But it does:

- Insert into `pos_receipts`, which has PG-only CHECK constraints on hash column lengths (`length(fiscal_hash) = 64 AND length(vat_breakdown_hash) = 64 AND length(payment_methods_hash) = 64`) and `pos_receipts_totals CHECK (total = subtotal + tax_amount)`.
- Insert into `pos_receipt_payments`, which has PG-only `pos_receipt_payments_amount CHECK (amount > 0)`.
- Insert into `pos_receipts` with the Task 11 PG-only FK `pos_receipts_fiscal_event_id_fk REFERENCES fiscal_events(id)`.
- Interact with the PG-only `prevent_receipt_modification` BEFORE UPDATE OR DELETE trigger if any future refactor changes the projection's INSERT to an UPDATE.

A future refactor that (a) writes a non-64-char hash, (b) writes a zero or negative payment amount, or (c) writes an orphan `fiscal_event_id` would pass on SQLite (silent) and break the PG-merge-gate. The merge-gate filter exists precisely for this class of regression. Tasks 10 / 11 / 19 / 20 reviewers all flagged the same omission once each — pattern is established.

**Remediation.** Extend `--filter="…|PosCoreReceiptProjectionTest"` in `.github/workflows/ci.yml:366` and add the matching comment block (lines 318-350 enumerate prior entries with the same shape).

**Reference.** Handoff §4.2 standing pattern #2 ("CI PG merge-gate filter must be extended at the same commit any new fiscal table-shape test ships").

---

### F5 — P2 — `writePayments()` docblock misrepresents the cross-tenant rejection mechanism

**File.** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:389-391, 437-468`.

**Observation.** The docblock at `:389-391` says: "Cross-tenant `payment_method_id` is rejected: the `PaymentMethod` lookup is scoped … so a foreign FK lands as `null` and the row insert fails on the NOT NULL FK — atomic rollback." The body at `:437-444` looks up `$method` via tenant+company-scoped query, but the value written to `pos_receipt_payments.payment_method_id` is the **raw payload value** `$paymentMethodId` (`:455`), NOT `$method->id`. The `$method` resolution feeds only `$payment_type = $method !== null ? $method->name : $methodCode` (`:450`).

So the rejection mechanism is not "foreign FK lands as null" — it's "the raw payload string fails the FK at the DB constraint layer." This still works (FK enforcement on both PG and SQLite, the projector transaction rolls back), but the docblock prose describes a different code path than the code executes. A future reader fixing a different bug might "fix" the projector to write `$method?->id ?? $paymentMethodId` to make the prose match, and silently break the security claim.

**Remediation.** Rewrite the docblock to reflect actual behaviour: "Cross-tenant `payment_method_id` is rejected at the DB FK layer: the raw payload string is written, and the FK constraint `pos_receipt_payments.payment_method_id → payment_methods.id` fails when the referenced row does not exist. The tenant+company-scoped `$method` lookup is used only to populate the `payment_type` snapshot."

**Reference.** Task 20 standing pattern #2 ("stale-comment hazard: comment + behaviour must move together"). Same lesson here.

---

### F6 — P2 — `SCALE = 3` constant matches the model cast but not the migration column scale

**File.** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:80-81`.

**Observation.** The projector defines `private const int SCALE = 3` and the docblock claims "Currency-scale used by all numeric columns on `pos_receipts*` (`decimal:3`)." The Receipt model casts `subtotal / tax_amount / discount_amount / total / change_due / tolerance_writeoff` as `decimal:3` (`Receipt.php:206-211`). But the actual DB migration declares `$table->decimal('subtotal', 12, 2); $table->decimal('tax_amount', 12, 2); $table->decimal('total', 12, 2);` (`2026_01_08_190637_create_pos_receipts_table.php:59-61`).

This is a pre-existing model-vs-migration drift; the projector inherits it. On PG, inserting `'10.000'` into a `decimal(12,2)` column truncates to `'10.00'` (silently). The CHECK constraint `total = subtotal + tax_amount` is evaluated post-truncation so it still holds. The test does not catch this because the fixture uses `'10.00'` strings (scale 2) which `bcadd($v, '0', 3)` normalizes to `'10.000'` and SQLite stores literally.

**Why it's not a BLOCKER.** No data corruption — both sides truncate consistently. But the constant's name + docblock are misleading. A future hand-off engineer reading "the projector normalizes to scale 3 because the columns are `decimal:3`" would not know the actual columns are scale-2.

**Remediation.** Rewrite the docblock at `:80`: "Currency-scale used for normalization; matches `Receipt::$casts` (`decimal:3`). Note: the underlying migration columns are `decimal(12, 2)`; PG truncates on INSERT. This is a pre-existing model/migration drift outside Task 21 scope." Alternatively, change `SCALE = 2` and let `bcadd` produce scale-2 output (no behavioural change after PG truncation, but matches the storage truth).

**Reference.** Receipt migration `2026_01_08_190637_create_pos_receipts_table.php:59-61` vs `Receipt::$casts` (`Receipt.php:206-211`).

---

### F7 — P3 — `unset($event)` inside foreach is dead code

**File.** `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:470`.

**Observation.** Inside `writePayments()`'s `foreach` body, after the `ReceiptPayment::query()->create([…])` call, the projector executes `unset($event);` — a local-scope unset on a parameter. This does nothing useful: it does not unbind the FiscalEvent's DB connection, does not free memory in any meaningful way (the row was already created), and is not referenced anywhere else in the method. Plausibly leftover from a refactor where `$event` was reassigned within the loop.

**Remediation.** Remove the line.

---

### F8 — P3 — `ReceiptPaymentService` docblock cites stale line number `:297`

**File.** `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:44`.

**Observation.** The newly-added docblock says "the `ReceiptPayment::create` portion (`:297`) has been relocated to `PosCoreReceiptProjection`." But the inserted docblock (`:40-52`) itself shifted the actual `ReceiptPayment::create` call from line 297 to line 306. The citation is stale at the moment it was committed.

**Remediation.** Either drop the line number (just say "the `ReceiptPayment::create` portion") or update to `:306`.

---

### F9 — P3 — No regression test for the HasUuids deterministic-ID round-trip

**File.** `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`.

**Observation.** The projector uses the pattern at `:185-217`:

```php
$receipt = new Receipt([…]);  // 'id' NOT in $fillable
$receipt->id = $receiptId;
$receipt->save();
```

This works because Laravel's `HasUniqueIds::setUniqueIds()` (`vendor/laravel/framework/.../HasUniqueIds.php:29-36`) only sets the column when `empty($this->{$column})`. The explicit `$receipt->id = $receiptId` assignment satisfies `! empty()` so the auto-generator is bypassed. But the test suite does not pin this — every test reads via `DB::table('pos_receipts')->first()` and never asserts that `Receipt::find($receiptId)` returns the row, nor that the stored `id` matches `$receiptId`. A future Laravel version that tightens the empty check (e.g. checks `isset` instead) would silently drop the explicit value and the projector would generate UUIDv7s instead of using the deterministic value.

**Remediation.** Add one assertion to `test_apply_links_pos_receipt_to_the_fiscal_event` or write a dedicated test: `$this->assertNotNull(Receipt::find($expectedReceiptId));` where `$expectedReceiptId` is captured (e.g., via a spy on `Str::uuid()` or by reading back the pre-projection state). Alternatively, since the projector's deterministic-receipt-number contract relies on the `id` round-trip, assert `Receipt::query()->where('id', $receipt->id)->where('fiscal_event_id', $event->id)->exists()`.

---

## C1–C5 adjudications

### C1 — Implementer retained legacy `ReceiptSyncService` + `ReceiptPaymentService` bodies with deprecation docblocks instead of physical deletion

**Verdict: ACCEPTABLE.** Plan §1635 says "Relocate the business-effect logic ... into this projector"; it does not mandate deletion of the source. Spec §622 explicitly lists those legacy service tests as "Migrate ... to fiscal-event ingestion tests" — work tracked for Task 25 (legacy endpoint retirement). Physical deletion now would cascade into `ReceiptCreationService`, `ReceiptReturnService`, `SyncController`, `ReceiptController`, plus seven test classes (`ReceiptPaymentServiceTest`, `OfflineV3CutoverSyncTest`, etc.). The deprecation docblocks at `ReceiptSyncService.php:50-59` and `ReceiptPaymentService.php:40-52` correctly forbid new writers from extending the legacy code path. The §5.0 / D8 "single canonical path" invariant is preserved at the wiring layer (the legacy endpoint is the surface, not the canonical sealing path).

### C2 — `vat_breakdown_hash` / `payment_methods_hash` populated with zero sentinel

**Verdict: PARTIALLY DISAGREED — escalated to F1 BLOCKER.** The mechanical reasoning is sound (the columns are NOT NULL + 64-char CHECK; the canonical hash is now `fiscal_events.current_hash`). But the consequence — every projection-written row reports as chain-broken by the still-wired `pos:verify-chains` command — is operationally severe and not addressed anywhere in the commit. See F1 for the three remediation paths.

### C3 — `StrictCanonicalParser` checks only `is_array()` on `payment_lines`; projector enforces per-line keys

**Verdict: ACCEPTABLE.** `validateListOfAssoc('payment_lines', …, ['amount', 'tendered', 'change'])` at `StrictCanonicalParser.php:646` already enforces that each item is a non-empty associative array with money-string `amount` / `tendered` / `change` fields when present. The projector's additional `requireString(…, 'payment_method_id')` + `requireString(…, 'method_code')` checks are the second-line gate, throwing `InvalidArgumentException` which rolls back the projection transaction atomically (covered by `test_malformed_payload_payment_method_id_rolls_projection_back`). Tightening the parser to require `payment_method_id` + `method_code` is genuinely Task 16 follow-on work and not blocking for Task 21.

### C4 — Receipt-number format change `FE-{terminalCode}-{year}-{seq}` vs legacy `{location}-{terminal}-{year}-{seq}`

**Verdict: ACCEPTABLE with operational caveat.** The UNIQUE scope on `pos_receipts.receipt_number` was rescoped in `2026_03_24_100000_scope_receipt_number_unique_to_tenant.php` to `(tenant_id, company_id, location_id, receipt_number)` — within that scope, `terminal_code` is unique (`pos_terminals_unique_code` is `(tenant_id, company_id, location_id, code)`) and `sequence_number` is unique-per-terminal-per-tenant (fiscal_events constraint). So no UNIQUE collision is possible in normal operation. **Operational caveat:** chain restarts (spec §9, deferred to Task 22/25) reset `sequence_number` to 1, which would produce a duplicate `receipt_number` in the post-restart chain. The current implementation should add a docblock note acknowledging this is a forward concern; full mitigation is in Task 22/25 scope.

### C5 — `HasUuids` deterministic-ID round-trip via `new Receipt([…]); $receipt->id = …; $receipt->save();`

**Verdict: WORKS — but untested.** The pattern is correct (verified in `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUniqueIds.php:29-36`: `setUniqueIds()` only sets when `empty($this->{$column})`). But the test suite never asserts the round-trip. Filed as F9 (P3) — add one assertion or a dedicated test to lock the pattern against future Laravel changes.

---

## Grep verification log

| Claim | Verified | Notes |
|---|---|---|
| `pos_receipts.fiscal_event_id UNIQUE FK fiscal_events(id)` | Yes | `2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:59-77` confirms UNIQUE + FK (PG only). |
| `ReceiptSyncService:683-710` voucher + `:713-735` stock | Partial | Actual lines now `:692-720` (voucher) and `:722-736` (stock). Symbols match; line numbers have drifted ~10 lines. Cite-by-symbol going forward. Plan citation unchanged in commit. |
| `ReceiptPaymentService.php:297` `ReceiptPayment::create` | Drifted | Actual line is `:306` after the inserted docblock. Filed as F8. |
| `pos_terminals.code` UNIQUE scope | Yes | `(tenant_id, company_id, location_id, code)` per `2026_01_08_190429_create_pos_terminals_table.php:67`. |
| `pos_receipts.receipt_number` UNIQUE scope | Yes (rescoped) | Originally global UNIQUE; rescoped to `(tenant_id, company_id, location_id, receipt_number)` in `2026_03_24_100000_scope_receipt_number_unique_to_tenant.php`. |
| `pos_receipts.vat_breakdown_hash` / `payment_methods_hash` NOT NULL + 64-char CHECK | Yes | `char(64)` (no `nullable()`) + CHECK `length(…) = 64` on PG only. |
| `VerifyPosChainCommand` still registered | Yes | `POSServiceProvider::boot():56-59` registers the command unconditionally. |
| `ReceiptHashService::verifyHash` reads `vat_breakdown_hash` + `payment_methods_hash` | Yes | `ReceiptHashService.php:62-72` `serializeForHashing()` folds both columns into the pipe-string input to `calculateHash()`. |
| CI PG-merge-gate filter at `.github/workflows/ci.yml:~366` | Yes | Current filter at line 366 does NOT include `PosCoreReceiptProjectionTest`. Filed as F4. |
| `FiscalEventProjectionRegistry` constructor takes `iterable<FiscalEventProjector>` | Yes | `FiscalEventProjectionRegistry.php:80-86` — `OutboxIngestorTest`'s rebind pattern is correct. |
| `PaymentMethod` lives in Treasury module | Yes | `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php` — the inbound mirror-data lookup permitted by SoT §13.6/D16. |
| `FiscalPayloadArrayGuards::{requireString, requireArray, optionalString}` exist | Yes | `FiscalPayloadArrayGuards.php:39, 78, 96` — projector uses them throughout. |

---

## Standing-pattern conformance

| # | Pattern | Status |
|---|---|---|
| 1 | `(type) $array['key']` casts on payload values | CLEAN — zero instances; `FiscalPayloadArrayGuards` used everywhere. |
| 2 | Resolver / downstream-service exceptions fail-closed | CLEAN — `resolveTerminal()` wraps `QueryException` and returns null + logs; `resolveCashierName()` wraps `QueryException` and returns `'Unknown'`; `PaymentMethod::find` wraps `QueryException`. |
| 3 | `Eloquent::find($uuid)` PG malformed-UUID wrap | CLEAN — both `Terminal` and `PaymentMethod` lookups wrapped. |
| 4 | `chain_sequence` not `hash_sequence` wording | CLEAN — projector writes `'chain_sequence' => $event->sequence_number` per Task 11 CLEAN-6. |
| 5 | Constructor injection with `private readonly`; no `app()` helper | CLEAN — only `VoucherRedemptionService` injected; no `app()` calls in the projector. |
| 6 | `$fillable` boundary discipline | CLEAN — `id` NOT in fillable (manual assignment after `new Receipt`); `canonical_bytes` + `fiscal_event_id` ARE in fillable (Task 11 CLEAN-2). |
| 7 | Constructor-asserted invariants | N/A — projector has no construction-time invariants to assert; `requiresModule()` returns null (always-active) per spec §7.3, which is a runtime contract not a construction-time one. |
| 8 | DB primitives the spec names by-the-statement | N/A — Task 21 does not use the load-bearing ON CONFLICT primitive; the idempotency guard is a pre-INSERT `exists()` query inside a transaction with a re-check. |

---

## Final notes on subagent execution

The subagent followed the plan's letter on the §7.4 split (no Treasury operational imports — verified by grep), the idempotency anchor (Task 11 `fiscal_event_id` UNIQUE — verified by reading the migration), the provider tag (POS-module wiring — verified at `POSServiceProvider.php:44-47`), and every standing code-smell pattern (verified above). The remaining gap is testing rigor: 3 of the 8 tests are interface introspection (`name() / handlesEventType() / requiresModule()` + tag-presence), 4 are happy-path / standing-pattern defense (idempotency, mirror columns, malformed payload, terminal-not-found), and only 1 (`test_runs_to_completion_with_treasury_inactive`) is what the plan §1571 asks for. The plan-named 4 cases are present but two of the most operationally-significant new responsibilities (stock + voucher) are entirely absent from fixtures. Codex will catch this — the 7-of-20-task BLOCKER streak on coverage gaps continues unabated.

Round-2 changeset shape: F1 + F2 are the only must-close. F3 (cross-tenant FK rejection) is the only must-close P1. F4 (CI filter) is must-close-before-merge but not behavioural. F5 / F6 are docblock-only edits. F7 / F8 / F9 are sweep-while-you're-in-here.
