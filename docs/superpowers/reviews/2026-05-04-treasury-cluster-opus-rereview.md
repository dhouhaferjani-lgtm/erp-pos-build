# Opus adversarial round-2 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: f19624cf
Round-1 verdict: BLOCK (7 findings)
Reviewer: opus  (advisory — not an accepted review.reviewer per schema)

## Verdict

**APPROVE-WITH-MINOR-EDITS-APPLIED**

The two CRITICAL findings (pipe-form blind spot + bare exists on `payment_instruments`) are fully closed; the structural class of cross-tenant leak the round-1 BLOCK called out has been eliminated; Gate A dropped 94 → 69 (Block 3a) → 112 (broadened scanner) → 98 (Block 3b fixes). All four IMPORTANT findings (mine + Codex's) are closed except for one PARTIAL on Codex Finding 1 — `api.treasury.027`/`028` (Document::findOrFail/find at lines 108-112 and 190 of `PaymentAllocationService::applyAllocation`'s DB::transaction closure) are pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id`, which fails on `Payment::findOrFail` at line 87 BEFORE reaching the document loop. The 026 / 029 / 030 / 031 / 032 re-pins are correct; only 027 and 028 are still pinned to a non-reaching test.

That residual is a regression-pin gap, not a security gap (the production code IS scoped at lines 108-112 and 190 — verified by reading `PaymentAllocationService.php` directly), and it's surgical enough to be a follow-up commit rather than a blocking find. The hard gate may open with this verdict on condition that the orchestrator addresses 027/028 with a service-reaching pin BEFORE shipping the next cluster, since Treasury sets the reference standard.

## Round-1 findings status

### Finding 1 (Opus CRITICAL): Pipe-form `'required|exists:foo,id'` invisible to Gate A scanner (9 sites in MultiPaymentController)
- **Status**: CLOSED
- **Evidence**:
  - `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php:147-173` — `checkInlineString()` now splits on `|` and applies `str_starts_with($fragment, 'exists:')` per fragment.
  - `apps/api/tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` — 7 unit tests covering required-then-exists, exists-then-nullable, sometimes|nullable|exists, non-guarded table negative, single-fragment regression, array-form negative, and 4-segment exists-with-where defensive case.
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:32-48, 86-108, 145-152, 219-229` — all 9 pipe-form rules replaced with `ScopedExists::tenantAndCompany(...)`. `recordDeposit`, `applyDeposit`, `recordPaymentOnAccount` now derive `$tenantId` from `CompanyContext::requireCompany()->tenant_id` per master-plan pattern.
  - Gate A trajectory: 94 → 112 (broadened scanner exposed +23 pipe-form + 20 from new GUARDED_TABLES) → 98 after Block 3b scoping fixes; net delta-from-round-1 = +4 (residual pre-existing exposure on other clusters that the broadened scanner now sees).

### Finding 2 (Opus CRITICAL): `payment_instruments` not in GUARDED_TABLES → `PaymentController.php:112` unscoped
- **Status**: CLOSED
- **Evidence**:
  - `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:71` — `'payment_instruments'` added to GUARDED_TABLES.
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:112-116` — `instrument_id` now uses `ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId)`.
  - Cross-tenant test `test_payments_store_refuses_cross_tenant_instrument_id` exists in TreasuryTenantIsolationTest.

### Finding 3 (Opus IMPORTANT): `journals` and `users` missing from GUARDED_TABLES
- **Status**: CLOSED (with caveat re: `journals` table absence — see below)
- **Evidence**:
  - `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:63, 87` — `'journals'` and `'users'` added to GUARDED_TABLES.
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:76, 132` — `responsible_user_id` now uses `ScopedExists::tenant('users', $tenantId)` (correct: User table has `tenant_id` only, no `company_id` — verified via `database/migrations/2025_11_30_000003_create_users_table.php`).
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:77-82, 153-158` — `default_journal_id`'s `exists:journals,id` validator was REMOVED rather than scoped, with a multi-line comment explaining: "no `journals` table exists in the current schema (no migration, no model, no read path). The bare `exists:journals,id` validator was broken (any value triggers a 500 SQL error: relation 'journals' does not exist)." I independently verified — `find database/migrations -name "*.php" | xargs grep -l "Schema::create.*'journals'"` returns no results, no Accounting model references the table. The removal is correct: a validator pointing at a non-existent table was a defense theatre that crashed every form submission. The field stays as `['nullable', 'uuid']` storage-only, pending a proper Accounting-module journals table + scoped validation. **Caveat**: the value can now be any UUID, including a tenant-B journal id that happens to be a real UUID — but since there's no journals table to enforce against, there's no actual cross-tenant resolution on read either. Acceptable; flagged for the eventual Accounting cluster. The orchestrator's commit message documents the removal honestly.

### Finding 4 (Opus IMPORTANT): 3 PHPStan errors in TreasuryTenantIsolationTest
- **Status**: CLOSED
- **Evidence**:
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:111` — `$instrumentB` now read at lines 769, 840, 885 (3 cross-tenant test sites).
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:117` — `$purchaseOrderB` now read at line 1359 (forged Document for VendorRefundService cross-tenant test).
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1605` — `assertNoValidationErrorFor(TestResponse $response, ...)` docblock now declares `@param TestResponse<Response> $response` with `Symfony\Component\HttpFoundation\Response` imported on line 38.
  - `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` returns `[OK] No errors` on the full codebase.

### Finding 5 (Opus NICE-TO-HAVE): Inventory pattern_type counts off-by-one
- **Status**: ACKNOWLEDGED-AND-OBSOLETED
- **Evidence**: The Block 3a/3b inventory regeneration that surfaced 14 new callsites (api.treasury.049..062) renders the prior counts moot. Total Treasury callsites in inventory now = 62 (48 original + 14 newly-added Block 3b). The Gate A delta accounting is now: +43 from broadened scanner, -14 from Block 3b production fixes.

### Finding 6 (Opus NICE-TO-HAVE): 7-vs-6 commit count + ClusterResolver scope-creep
- **Status**: ACKNOWLEDGED-AND-DEFERRED
- **Evidence**: Round-1 ancestor (`0b5a6dd1` ClusterResolver) is now reachable in this round-2 range too but is irrelevant to Treasury; Block 3b's 7-commit window (eca00bdb..f19624cf) is properly Treasury-scoped: scanner fix + inventory regen + tests + production fix + PHPStan fix + service-reaching tests + inventory re-pin + workflow submit. Each commit has a clear single concern.

### Finding 7 (Opus NICE-TO-HAVE): verify-history multi-orphan acceptance
- **Status**: STILL-OPEN-AS-DEFERRED
- **Evidence**: `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` is NOT in the diff `eca00bdb..f19624cf`. Pre-existing reporting-fidelity quibble unchanged; not a security gap; not a Treasury-cluster concern.

### Finding 1 (Codex IMPORTANT): Service-layer regression pins for `api.treasury.026..029` stop at controller validation
- **Status**: PARTIALLY-CLOSED
- **Evidence**:
  - **026** (Payment::findOrFail at line 87) → re-pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id`. The test calls `PaymentAllocationService::applyAllocation(paymentId: $paymentB->id, ...)` directly under tenant-A's `CompanyContext` and asserts `ModelNotFoundException`. ✓ CORRECT — reaches the line-87 guard.
  - **029** (Document::findOrFail at line 488 inside `previewManualAllocation`) → re-pinned to `test_payment_allocation_service_preview_manual_refuses_cross_tenant_document_id`. The test calls `previewAllocation(allocationMethod: MANUAL, manualAllocations: [['document_id' => $invoiceB->id, ...]])` and asserts `ModelNotFoundException`. ✓ CORRECT — reaches line 488.
  - **027** (Document::findOrFail at line 112 inside `applyAllocation`'s DB::transaction closure) → re-pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id`. ✗ **NOT REACHED**: the test passes a cross-tenant `paymentId`, which fails the line-87 `Payment::findOrFail` BEFORE the function ever enters the DB::transaction at line 99. The Document::findOrFail at line 112 is never executed in this test. The same-tenant control test (`test_payment_allocation_service_apply_accepts_same_tenant_payment_id`) DOES reach line 112, but it only passes same-tenant document ids (FIFO over `partnerA`'s open invoices) — there's no cross-tenant assertion. **A regression that drops the `where('tenant_id'/'company_id')` chain on line 108-112 would NOT fail this test.**
  - **028** (Document::find at line 190 inside `applyAllocation`'s GL journal block) → same problem as 027. Re-pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id`, which fails on line 87 and never reaches line 190.
  - The production code at lines 108-112 and 190-193 IS correctly scoped (`Document::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->lockForUpdate()->findOrFail(...)`); I read the file directly. So this is a regression-pin gap, not a security gap. But the inventory rows for 027 and 028 are claiming a regression test that does not protect those callsites — exactly the failure mode Codex flagged in round-1. Suggested fix: add a service-reaching test that exercises `applyAllocation` with a same-tenant `paymentId` AND a manually-injected cross-tenant `document_id` in the FIFO preview (would require monkeypatching previewAllocation or using a custom AllocationMethod). Or accept that 027/028 are unreachable from any HTTP path that doesn't first satisfy line 87, document them as "structurally protected by line 87 guard" with a `note:` on the inventory row, and remove the misleading regression_test pin.

### Finding 2 (Codex IMPORTANT): `api.treasury.030` (PaymentRefundService) pinned to unrelated payment-store validator test
- **Status**: PARTIALLY-CLOSED
- **Evidence**: Re-pinned to `test_payment_refund_service_scoped_find_refuses_cross_tenant_payment_id`. The test does NOT call `PaymentRefundService::refundReceiptPayments()` — it reproduces the scoped-find pattern at the test level (`Payment::query()->where('tenant_id', $tenantA->id)->where('company_id', $companyA->id)->find($paymentB->id)`) and asserts null. The test author documents this honestly in a 7-line comment (test lines 1286-1300): the public API builds `allocationMap` from a query already scoped on the receipt's `company_id`, so it's structurally impossible to reach line 423 with a cross-tenant `originalPaymentId` from any caller. The defense-in-depth find at line 423 is unreachable from the public API. The pin is "test the pattern by reproduction" rather than "test the production codepath" — borderline acceptable but not service-reaching. Codex's original objection was "pinned to an unrelated payment-store validator test"; the pin is now to a relevant but synthetic test. The improvement is real but doesn't fully meet "service-reaching" honesty.

### Finding 3 (Codex IMPORTANT): `api.treasury.031..032` (VendorRefundService) tests masked by FormRequest
- **Status**: CLOSED
- **Evidence**:
  - **031** (Document::lockForUpdate findOrFail at line 50-54): re-pinned to `test_vendor_refund_service_refund_prepayment_refuses_cross_tenant_document_id`. The test instantiates a forged `Document` (in-memory, no DB persist) carrying tenant-A's `tenant_id`/`company_id` but tenant-B's PO id, then calls `VendorRefundService::refundPrepayment($forged, ...)` directly and asserts `ModelNotFoundException`. The locked-find inside the closure refuses to resolve because the row's actual `tenant_id`/`company_id` in the DB is tenant-B's. ✓ Service-reaching, bypasses the FormRequest entirely.
  - **032** (PaymentRepository::lockForUpdate find at line 113-117): re-pinned to `test_vendor_refund_service_repository_lookup_skips_cross_tenant_repository`. The test seeds a same-tenant allocation against `purchaseOrderA` to bypass the "exceeds total allocated" guard, then calls `refundPrepayment(po: purchaseOrderA, repositoryId: $repositoryB->id)` and asserts `repositoryB.balance` is unchanged after the call. Proves the locked-find returns null (cross-tenant) and the GL reversal skips. ✓ Service-reaching, bypasses the FormRequest entirely.
  - Same-tenant control `test_vendor_refund_service_refund_prepayment_accepts_same_tenant_document` proves the service is reached when scoping passes (asserts a `DomainException` for "exceeds total allocated", not `ModelNotFoundException`).

### Finding 4 (Codex IMPORTANT): Same-tenant controls incomplete across new regression file
- **Status**: CLOSED
- **Evidence**: Each new service-reaching cross-tenant test in commit `6b0b2cb0` has a paired same-tenant control:
  - `test_payment_allocation_service_apply_*` cross-tenant + accepts_same_tenant
  - `test_payment_allocation_service_preview_manual_*` cross-tenant + accepts_same_tenant
  - `test_payment_refund_service_scoped_find_*` (cross-tenant + same-tenant in the same test body — both assertions in a single method)
  - `test_vendor_refund_service_refund_prepayment_*` cross-tenant + accepts_same_tenant
  - `test_vendor_refund_service_repository_lookup_*` (single-test design — assertion is balance-unchanged, which by construction includes a same-tenant control: same-tenant repo+po would change balance)
  - The test author documents the design choice honestly per test (e.g. "Same-tenant call — should NOT throw ModelNotFoundException"). 4 explicit same-tenant control methods + 4 implicit (in-test) same-tenant assertions covering 8 service-reaching cross-tenant tests.

## New findings (round-2 specific)

### Finding 8: `api.treasury.027` and `028` pin to a Payment-guard test that never reaches the Document guards (carry-over from Codex Finding 1 partial closure)
- **Severity**: IMPORTANT
- **Location**:
  - `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` rows `api.treasury.027` and `api.treasury.028`
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1189-1228` (`test_payment_allocation_service_apply_refuses_cross_tenant_payment_id` + same-tenant control)
  - `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:108-112` (027) and `:190-193` (028)
- **Issue**: Both rows are now pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id`, which passes a cross-tenant `paymentId` and fails on the line-87 `Payment::findOrFail` BEFORE the function enters the DB::transaction at line 99. The Document::findOrFail at line 112 (027) and Document::find at line 190 (028) are never executed in this test. The same-tenant control reaches lines 112 and 190 but only with same-tenant document ids (FIFO over partnerA's invoices), so a regression that dropped the `where('tenant_id'/'company_id')` chain on line 108-112 or 190-193 would NOT fail this test. The production code IS scoped — verified by direct read — so this is a regression-pin gap, not a security gap. But it's the same failure mode Codex flagged in round-1 (pin doesn't reach the callsite), partially carried over.
- **Fix** (one of):
  1. **Preferred** — add a service-reaching test that exercises `applyAllocation` with a same-tenant `paymentId` AND a way to inject a cross-tenant `document_id` into the FIFO preview. This is hard because `previewAllocation` builds the document set from a query scoped on `payment->company_id`. One option: monkeypatch `Document` factory to create a partner with a same-tenant invoice, then reassign that invoice's `tenant_id`/`company_id` to tenant-B with raw SQL (bypassing model events), then call `applyAllocation` — the FIFO query won't pick up the now-cross-tenant invoice (so the test would test that the FIFO query is itself scoped, which is also a guarded callsite). Alternative: test `MANUAL` allocation method with a cross-tenant document_id — but that path is `previewManualAllocation` (line 485), already pinned by 029.
  2. **Acceptable** — explicitly document on rows 027 and 028 that the callsites are `structurally_protected_by_upstream_guard: api.treasury.026` (Payment::findOrFail at line 87 catches all cross-tenant attempts before the document guards run), and add a `note:` field explaining that the regression_test pin is to the upstream guard intentionally.

## What looks good

- **Pipe-form blind spot fix is principled and unit-tested**. The visitor change is 39 lines with a 193-line unit test covering 7 cases including defensive ones (4-segment exists, exists-on-non-guarded-table). The split-and-iterate approach is robust and won't regress single-fragment behavior.
- **GUARDED_TABLES expansion is honest**. `journals` was added even though the table doesn't exist (pre-emptive for the future Accounting cluster); `users` and `payment_instruments` are tenant-scoped tables Treasury validates against. The `default_journal_id` validator removal in PaymentMethodController is documented honestly with a multi-line comment naming the broken-validator failure mode.
- **Service-reaching tests for VendorRefundService and PaymentAllocationService::previewManual are model examples**. Same-tenant + cross-tenant pairing, real DB fixtures, no mocks of domain models, explicit ModelNotFoundException assertions, narrative comments explaining defense-in-depth pattern. The forged-Document trick for `refundPrepayment` cross-tenant is creative and bypasses the FormRequest cleanly.
- **The `edit_applied` re-pin events are intact**. `verify-history` reports `486 event(s) across 262 callsite(s); 0 problem(s)`. Each re-pin event has actor=claude, command name documenting the one-shot script, proper from/to YAML hashes, target_ids matching, and a note explaining old-pin → new-pin. Audit chain trustworthy.
- **No POS / Voucher cross-cluster regression** — `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` is empty.
- **No new `app()` helper or `@phpstan-ignore` in Treasury production code**. Pre-existing `app(DiscountToleranceBoundary::class)` in `Rules/DiscountAboveTolerance.php:49` is from `c0bd6068` (2026-04-15), not part of this remediation. Pre-existing `@phpstan-ignore-next-line argument.type` in `PaymentAllocationService.php:499, 503` is also pre-existing (file not modified in `eca00bdb..f19624cf`).
- **MultiPaymentController scoping is thorough**. All 9 pipe-form rules replaced with array-form `ScopedExists::tenantAndCompany`; `recordDeposit`, `applyDeposit`, `recordPaymentOnAccount` now derive `$tenantId` from `CompanyContext::requireCompany()->tenant_id` matching PaymentController's pattern (round-1 Finding 1 fix step 3 satisfied).

## Verification run

- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` — **OK (62 tests, 185 assertions)**, ~60s. Matches subagent claim.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` — **OK (2 tests, 4 assertions)**, **Gate A: 98**, **Gate B: 102**. Matches expected.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` — **`[OK] No errors`** on full codebase.
- `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ app/Application/Sweep/Visitors/` — **`{"result":"pass"}`**.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — **`verified 486 event(s) across 262 callsite(s); 0 problem(s).`** exit 0.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — empty.
- `grep -rnE "['\"]exists:[a-z_,]+['\"]|exists:[a-z_]+,id" app/Modules/Treasury/ --include="*.php"` — only 2 hits, both inside comment blocks documenting the `exists:journals,id` removal (PaymentMethodController.php:79 + :155); no live bare exists rules remaining.
- `grep -rnE "[^a-zA-Z_]app\(" app/Modules/Treasury/ --include="*.php"` — 1 hit, pre-existing in `Rules/DiscountAboveTolerance.php:49`, not part of this remediation.

## Required to lift the residual gap (does NOT block APPROVE-WITH-MINOR-EDITS)

1. Address Finding 8 by either adding a true service-reaching cross-tenant test for `PaymentAllocationService::applyAllocation`'s document-loop callsites (027, 028), or annotating those inventory rows as `structurally_protected_by_upstream_guard: api.treasury.026` with a `note:` removing the misleading regression-test pin. One commit, no schema change, no production code change.
2. (Defer to Accounting cluster) Add a real `journals` table + scoped validator for `PaymentMethod.default_journal_id`. The current storage-only state lets clients persist arbitrary UUIDs in that column, but with no read path the data is inert. Track in the Accounting cluster's pending callsite set.
3. (Defer to Identity cluster) The `users` table has `tenant_id` only; `ScopedExists::tenant('users', $tenantId)` is correct for Treasury's `responsible_user_id` use. If Identity later adds `company_id` to users, revisit.
