# opus adversarial cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: eca00bdb
Reviewer: opus  (advisory — Opus is not an accepted `review.reviewer` under the schema; do not run `sweep:inventory:review --actor=opus`)
Parallel: an independent Codex review is being run at `docs/superpowers/reviews/2026-05-04-treasury-cluster-codex-review.md` (not consulted before writing this verdict)

## Verdict

**BLOCK**

## Commit reviewed

eca00bdb (and ancestors back to c91cf583)

Range actually contains **7 commits**, not 6 as the prompt claims: the merge between `c91cf583` and `a08aca55` includes `0b5a6dd1` ("triage 5 of 6 api.unmapped modules"), which adds `ClusterResolver.php` (+32 lines) and its unit test (+62 lines). That commit is unrelated to Treasury work but is reachable in the diff range. Acknowledged here, evaluated separately under Finding 6.

## Summary

The Treasury cluster's 48 inventoried callsites are correctly fixed (24 bare-exists → ScopedExists, 24 service-layer find/findOrFail → tenant+company scoped queries) with honest two-tenant regression coverage and clean architecture-gate drops (A: 94→69, B: 125→102). However, **the inventory missed a structural class of cross-tenant leak**: pipe-form `'required|exists:foo,id'` validator strings. Ten such rules survive untouched in `MultiPaymentController.php` (9 lines) and `PaymentController.php:112`, of which **seven are on already-guarded tables** (`payment_methods`, `payment_repositories`, `partners`, `documents`). The Architecture Gate A scanner has the same blind spot (`ExistsRuleVisitor::checkInlineString` only matches strings that *start* with `exists:`), so the gate cannot detect them, the inventory generator cannot detect them, and the parallel cluster sweep that follows Treasury will inherit this blind spot as the canonical baseline.

The hard gate **must not** open on this verdict. Treasury is the reference cluster; approving it with a real cross-tenant validation gap (and a scanner that can't see the gap) propagates the defect to every cluster that follows.

## Findings

### Finding 1: Pipe-form bare `exists:` rules in `MultiPaymentController` are unscoped, uninventoried, and undetected by Gate A
- **Severity**: CRITICAL
- **Location**:
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:33` — `splits.*.payment_method_id` (`payment_methods`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:35` — `splits.*.repository_id` (`payment_repositories`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:36` — `splits.*.instrument_id` (`payment_instruments`, NOT in GUARDED_TABLES)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:75` — `partner_id` (`partners`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:76` — `payment_method_id` (`payment_methods`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:79` — `repository_id` (`payment_repositories`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:80` — `instrument_id` (`payment_instruments`, not in GUARDED_TABLES)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:122` — `document_id` (`documents`, guarded)
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:190` — `partner_id` (`partners`, guarded)
- **Issue**: All nine validation rules are pipe-form (`'required|exists:payment_methods,id'` rather than the array form `[..., 'exists:payment_methods,id']`). The architecture visitor at `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php:147-166` matches inline strings only when the entire string starts with `exists:` (`str_starts_with($value, 'exists:')`). Pipe-form rule strings begin with `required|...`, so the visitor never inspects them. The same scanner powers the inventory generator, so the inventory has zero entries for `MultiPaymentController.php` `bare_exists_validator` callsites — only the three `unscoped_eloquent_findOrFail` entries (lines 38, 118, 120) appear there. All nine routes are live (`apps/api/app/Modules/Treasury/Presentation/routes.php:137-163`): `POST /api/v1/documents/{document}/split-payment`, `POST /api/v1/payments/deposit`, `POST /api/v1/payments/{payment}/apply-deposit`, `POST /api/v1/payments/on-account`. Concrete attack: a tenant-A user with treasury permissions calls `POST /api/v1/payments/deposit` with `partner_id` / `repository_id` / `payment_method_id` / `instrument_id` belonging to tenant B. The bare exists validator passes (no tenant filter), and the request is admitted to `MultiPaymentService::recordDeposit` which trusts the validated payload. Even if the service later applies its own tenant scoping, an attacker can still enumerate cross-tenant ids via 200 vs 422 responses, and a future refactor that "trusts validated input" turns the gap into immediate cross-tenant data binding. The cluster cannot be the canonical reference example with this surface unprotected.
- **Fix**:
  1. Extend `ExistsRuleVisitor::checkInlineString()` to handle pipe-form: split `$node->value` on `|`, iterate each fragment, and apply the `str_starts_with($fragment, 'exists:')` check per fragment. Add a unit test covering both forms in `tests/Unit/Shared/Architecture/ExistsRuleVisitorTest.php`.
  2. Re-run `sweep:inventory:generate` to add the 9 newly-detected bare exists in `MultiPaymentController.php` (plus 1 in `PaymentController.php:112` — see Finding 2) to the inventory under `cluster_id: api.treasury` with state `pending`.
  3. Replace each pipe-form rule with `ScopedExists::tenantAndCompany($table, $tenantId, $companyId)`, sourcing `$tenantId`/`$companyId` from `CompanyContext` (the controllers already resolve those — `MultiPaymentController::createSplitPayment` lines 28-29, `applyDeposit` lines 118-119; `recordDeposit` and `recordPaymentOnAccount` need to resolve them at the top of each method since they currently pull `tenant_id` only from `$user`).
  4. Add four cross-tenant regression tests covering the `recordDeposit`, `applyDeposit` (document_id field), `recordPaymentOnAccount`, and `createSplitPayment` (splits.*.payment_method_id, splits.*.repository_id) routes — the existing test file already covers `applyDeposit.payment_id` and `createSplitPayment.document_id` via the service-layer findOrFail fixes, so only the bare-exists field surfaces are missing.
  5. Re-run `php artisan sweep:inventory:verify-history`; expect 0 problems.
  6. Re-run Gate A; expect a further drop of 7 (the seven on guarded tables).

### Finding 2: `PaymentController::store` leaves `instrument_id` unscoped
- **Severity**: CRITICAL
- **Location**: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:112` — `'instrument_id' => ['nullable', 'uuid', 'exists:payment_instruments,id']`
- **Issue**: Same root cause as Finding 1 — array-form bare exists on a non-guarded table (`payment_instruments` is not in `TenantScopedExistsRulesTest::GUARDED_TABLES`). Allows a tenant-A `POST /api/v1/payments` to attach a tenant-B `PaymentInstrument` to the created payment record (the controller's `Payment::create([...'instrument_id' => $validated['instrument_id']...])` at line 199 trusts the validated value). The downstream service does NOT re-scope the instrument lookup. This is a same-cluster intra-Treasury leak — exactly the kind the reference cluster cannot leave open.
- **Fix**:
  1. Add `payment_instruments` to `TenantScopedExistsRulesTest::GUARDED_TABLES` (the array-form scan WILL catch this once the table is guarded).
  2. Replace the rule with `ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId)`.
  3. Add a regression test `test_payments_store_refuses_cross_tenant_instrument_id` in `TreasuryTenantIsolationTest`.

### Finding 3: `payment_instruments`, `users`, `journals` missing from GUARDED_TABLES even though Treasury validates against them
- **Severity**: IMPORTANT
- **Location**:
  - `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:51-89` — GUARDED_TABLES list
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:77, 148` — `'default_journal_id' => ['nullable', 'uuid', 'exists:journals,id']`
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:75, 131` — `'responsible_user_id' => ['nullable', 'uuid', 'exists:users,id']`
- **Issue**: `journals` (Accounting) and `users` (Identity) are tenant-scoped tables; bare `exists` against either lets a tenant-A user pin a tenant-B journal as the default journal of a tenant-A payment method, or designate a tenant-B user as the responsible user for a tenant-A repository. Neither table is in GUARDED_TABLES, so Gate A doesn't flag them and they were not in the Treasury inventory. Independently of Finding 1, the master-plan-mandated GUARDED_TABLES catalogue is incomplete on tables Treasury actually validates against.
- **Fix**: Add `journals`, `users`, `payment_instruments` (per Finding 2), and any other tenant-scoped FK targets validated in Treasury's Presentation tier to GUARDED_TABLES; re-run inventory generator; scope the four lines above with `ScopedExists::tenantAndCompany`.

### Finding 4: `TreasuryTenantIsolationTest` introduces 3 PHPStan errors on new code
- **Severity**: IMPORTANT
- **Location**:
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:104` — `private PaymentInstrument $instrumentB;` (`property.onlyWritten` — populated in `setUp()` via `seedTenantResources`, never read)
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:110` — `private Document $purchaseOrderB;` (same — written, never read)
  - `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:809` — `assertNoValidationErrorFor(TestResponse $response, ...)` missing `TResponse` generic (`missingType.generics`)
- **Issue**: CLAUDE.md mandates "PHPStan level 8 zero errors on new code"; pre-flight gate fails on the regression test file. This is the only test file the master plan Section 7 mandates, so it MUST be clean.
- **Fix**: Either add cross-tenant tests that consume `$instrumentB` / `$purchaseOrderB` (most honest — they exist precisely so cross-tenant ids can be passed; right now their slots aren't being exercised), or drop the unused properties from the fixture and from `seedTenantResources`'s tuple return. Add the `TestResponse<...>` generic on the helper.

### Finding 5: Inventory pattern_type counts diverge from prompt claims
- **Severity**: NICE-TO-HAVE
- **Location**: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` callsites api.treasury.001..048
- **Issue**: Prompt claims "25 Presentation `bare_exists_validator` sites + 23 Application/Domain/Presentation `unscoped_eloquent_find/findOrFail` sites". Actual inventory: **24** bare_exists + **24** unscoped_eloquent (9 find + 15 findOrFail) = 48. The Gate A drop of exactly 25 is suspicious given only 24 bare_exists in the inventory; one fix in commit `b09c7ac6` may have reduced the gate count for a callsite outside the inventory (e.g. an array-form rule on a guarded table that the gate sees but the YAML doesn't track), or vice-versa.
- **Fix**: Audit b09c7ac6's diff against the 24 inventoried bare_exists callsites; if any of the gate's 25-delta corresponds to a non-inventoried fix, add it to the YAML. Update the prompt template's headline counts so future cluster reviewers don't replicate the off-by-one.

### Finding 6: 7-vs-6 commit count + ClusterResolver scope-creep in the review window
- **Severity**: NICE-TO-HAVE
- **Location**: commit `0b5a6dd1` ("chore(tenant-isolation): triage 5 of 6 api.unmapped modules into existing clusters") — adds `apps/api/app/Application/Sweep/Scanners/ClusterResolver.php` (+32) and its test (+62)
- **Issue**: The prompt says "git diff --stat c91cf583..7b8a0a3a should be confined to apps/api/app/Modules/Treasury/ + the test file + the inventory YAML". `0b5a6dd1` sits inside that range and touches the Sweep scanner, not Treasury. It's a legitimate scanner improvement (5/6 unmapped modules folded into existing clusters via a deterministic resolver) and does not affect Treasury production code, but the prompt's own commit accounting is wrong.
- **Fix**: Update the prompt template to either include 0b5a6dd1 in the Treasury window's expected diffstat or rebase Treasury work past that triage commit so the windows are clean.

### Finding 7: verify-history relaxation is sound, but the multi-orphan acceptance accepts forged orphans into the chain-anchor set even when flagging them
- **Severity**: NICE-TO-HAVE
- **Location**: `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php:189-203`
- **Issue**: When `count($orphanPreviousHashes) > 1`, the command emits the `[bootstrap-orphan]` error AND merges every orphan into `$observedNewHashes` so per-event chain checks pass for them. The justification in the comment ("we still let the per-event check run so the report surfaces every event affected by the forgery") inverts the actual behavior — by accepting all orphans into the trusted set, downstream events that anchor on those orphans no longer surface as broken. The non-zero exit is preserved (problems > 0 from the multi-orphan flag itself), so this is a reporting-fidelity quibble rather than a security gap.
- **Fix**: Either accept exactly one orphan (the lowest by `at` timestamp on the events that reference it, or the unique seed-event new hash if available) and let the rest fall through as broken-chain errors, or document that the multi-orphan branch suppresses downstream chain errors so the operator knows the failure surface is "≥1 orphan, not necessarily all events broken".

## Test honesty assessment

- **A.1 (cross-tenant tests exercise the production path that was fixed)**: Honest. Each Surface 1/2/3 test posts a same-tenant fixture path with one cross-tenant id and asserts on the validator's structured `error.errors[<field>]` envelope or a `[403, 404, 422]` cross-tenant denial set. Spot-checked: `test_refund_prepayment_refuses_cross_tenant_repository_via_form_request` (TreasuryTenantIsolationTest.php:195-212), `test_payment_method_store_refuses_cross_tenant_default_account_id` (257-266), `test_smart_payment_apply_refuses_cross_tenant_payment_id` (476-484), `test_multi_payment_apply_deposit_refuses_cross_tenant_payment_id` (607-619). None pass for the wrong reason (e.g., none rely on a 401 from missing auth — the helper `actingAsForTenant` always authenticates the right user).
- **A.2 (same-tenant controls)**: Present where it matters most — the FormRequest/inline validate tests have explicit positive assertions (`assertNoValidationErrorFor`, `assertNotSame(422, $sameResponse->status(), ...)`). The Surface 3 service-layer tests rely on `assertContains([403, 404, ...])` without paired same-tenant 200 controls; minor gap (the test would still pass if the route was simply broken for everyone). Acceptable for the cluster's TDD anchor but worth tightening over time.
- **A.3 (real two-tenant fixtures)**: Honest. `setUp()` makes two `Tenant` rows, two `Company` rows, two distinct `User` rows with their own Spatie team-scoped admin assignments, and full per-tenant `Partner`/`PaymentMethod`/`PaymentRepository`/`PaymentInstrument`/`Account`/`Document`/`Payment` graphs via factories + explicit `create([...])`. No "cross-tenant id is just a fresh UUID" shortcut.
- **A.4 (no mocking)**: Verified. Test file imports `RefreshDatabase`, hits real factories, no `Mockery::mock(Payment::class)` or `vi.mock` in sight. The only `app(...)` calls are `app(PermissionRegistrar::class)` for Spatie team scoping, which is the established Spatie test pattern.
- **A.5 (coverage parity)**: Every guarded resource has at least one cross-tenant test — payment_methods (3 tests), payment_repositories (4 tests), accounts (3 tests including same-tenant control), partners (3 tests), documents (4 tests), payments (1 test), Payment model (1 service-layer test), Document model (1 service-layer test), PaymentRepository model (1 service-layer test). Gap: instrument_id never gets a cross-tenant test (consistent with Finding 2 — the rule isn't scoped, so writing the test would expose the gap).

## Architecture gate drops

- **Gate A**: 94 → 69 (delta 25 — matches claim, but see Finding 5: only 24 inventoried bare_exists; pipe-form bare_exists in MultiPaymentController are not counted by the gate at all, so the 69 baseline understates real exposure)
- **Gate B**: 125 → 102 (delta 23 — matches claim)

The gate drops match the inventory's expected reductions, but the Gate A scanner has a documented blind spot for pipe-form rules (Finding 1 root cause). A clean Gate A is therefore necessary but not sufficient evidence of cluster cleanliness.

## What looks good

- **Service-layer scoping is principled**: `VendorRefundService::refundPrepayment` (lines 47-52, 110-115) scopes lookups against the *locked PO's* `tenant_id`/`company_id` (a domain anchor that's already authenticated by the controller's scoped `findDocumentOrFail`), not against caller-supplied IDs. Defense in depth even if a controller validator regresses.
- **Module boundaries are preserved**: All `Rule::exists()` calls hit table names directly (`partners`, `documents`, `accounts`) — no cross-module model imports for enforcement. `ScopedExists` is a pure factory with no `auth()` / `app()` reach (constructor is `private` on a `final` class).
- **POS / Voucher untouched**: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` is empty.
- **No new `app()` helper or `@phpstan-ignore`** in Treasury production code (verified via `git diff c91cf583..eca00bdb`).
- **verify-history relaxation is conceptually sound**: the global-anchor + bootstrap-orphan check correctly tolerates the legitimate interleave of cluster-mode `start` followed by per-callsite `submit` while still rejecting forged events that reference fabricated states. The four existing defenses (format validation, file-level recompute, document-level anchor, non-null new hash on non-seed events) are preserved. The chosen trade-off (option b — relax verify-history rather than option a — batch-mode submit) is defensible because per-callsite commits + test pinning is a real audit win that batch-mode would erode.
- **Test fixture seeding is realistic**: real `Document::factory()->posted()`, real `Account::factory()`, real Spatie role assignments per tenant. The `bankRepositoryB` is created explicitly so the deposit cross-tenant test reaches the bare exists validator instead of being short-circuited by the controller's `INVALID_REPOSITORY` guard — that's the kind of fixture care that prevents passes-for-the-wrong-reason.

## Verification commands

- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` — **30 tests / 106 assertions / OK** (30/30 pass, ~30s)
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` — **OK (2/2)**, Gate A=69, Gate B=102 (matches claim)
- `vendor/bin/phpunit tests/Feature/Console/Sweep/SweepInventoryVerifyHistoryCommandTest.php` — **17 tests / 38 assertions / 1 skipped / OK** (1 skipped is the documentation-only placeholder noted in the prompt)
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — **`verified 363 event(s) across 219 callsite(s); 0 problem(s).`** exit 0
- `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` — **`{"result":"pass"}`**
- `./vendor/bin/phpstan analyse app/Modules/Treasury/ tests/Feature/Treasury/TreasuryTenantIsolationTest.php app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` — **3 errors** (all in `TreasuryTenantIsolationTest.php`: `instrumentB`/`purchaseOrderB` only-written; `assertNoValidationErrorFor` missing `TResponse` generic — see Finding 4)
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — **empty** (no sibling-cluster regression on POS/Voucher)
- `grep -rnE "['\"]exists:[a-z_,]+['\"]|exists:[a-z_]+,id" apps/api/app/Modules/Treasury/ --include="*.php"` — **14 unscoped bare exists remain**: 9 in MultiPaymentController.php (lines 33, 35, 36, 75, 76, 79, 80, 122, 190), 1 in PaymentController.php:112, 2 in PaymentMethodController.php (77, 148 — `journals`), 2 in PaymentRepositoryController.php (75, 131 — `users`)

## Required to lift the BLOCK

1. Patch `ExistsRuleVisitor::checkInlineString()` to handle pipe-form rule strings.
2. Re-run `sweep:inventory:generate` to capture the 9 newly-detected MultiPaymentController bare exists; add `payment_instruments`, `journals`, `users` to GUARDED_TABLES so the array-form rules in PaymentController/PaymentMethodController/PaymentRepositoryController also surface; expect ≥13 new pending callsites under `cluster_id: api.treasury`.
3. Apply `ScopedExists::tenantAndCompany` to all newly-found callsites; add the matching cross-tenant regression tests in `TreasuryTenantIsolationTest`.
4. Fix the 3 PHPStan errors in `TreasuryTenantIsolationTest.php` (Finding 4).
5. Re-run all verification commands and submit the next round of callsites for re-review. The hard gate cannot open until Gate A drops further AND the inventory's Treasury bare_exists count tracks reality.
