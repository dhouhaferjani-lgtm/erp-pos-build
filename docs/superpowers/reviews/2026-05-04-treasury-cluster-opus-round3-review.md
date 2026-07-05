# Opus adversarial round-3 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: 50e8d8ca
Round-1 verdict: BLOCK (7 findings)
Round-2 verdict: APPROVE-WITH-MINOR-EDITS-APPLIED (Opus) / REQUEST-CHANGES (Codex, the merged outcome — Opus missed Finding 12)
Reviewer: opus  (advisory — not an accepted review.reviewer per schema)

## Verdict

**APPROVE-WITH-MINOR-EDITS-APPLIED**

Rationale: All 3 round-2 findings are CLOSED. The Finding-12 fix is rigorous and the test coverage is honest (real two-tenant fixtures, asserts both `ModelNotFoundException` AND zero foreign-id Payment row). The schema field for Findings 8/9 is well-formed and the annotations carry meaningful semantics. The sibling-service-pattern check shows that no other Treasury domain service has the same caller-supplied-FK-persisted-before-scope-check pattern that Finding 12 addressed (the other Payment::create writers are either presentation-tier with `ScopedExists` validation upstream, or service methods that copy FK ids from an already-tenant-scoped Payment object, not raw caller input). Two NICE-TO-HAVE annotation accuracy nits exist on the api.treasury.030 structural-protection label, but they don't affect the underlying security guarantee.

## Round-2 finding status

### Codex Finding 12 (IMPORTANT — VendorRefundService scope-before-create)

- Status: **CLOSED**
- Evidence:
  - `VendorRefundService::refundPrepayment()` at apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:46-160 now resolves `payment_method_id` (line 86-89) and `repository_id` (line 92-96) under `$lockedPo->tenant_id`/`company_id` BEFORE `Payment::create` (line 99). Both use `findOrFail` so cross-tenant ids throw `ModelNotFoundException`, aborting the transaction.
  - The downstream balance update + GL reversal (lines 138-157) reuses `$resolvedRepository`, no second query that could drift.
  - The locked PO at line 51-55 is the domain anchor — `tenant_id`/`company_id` come from the locked row, not from the caller-supplied `$po`. (The `$po` argument is used only for `findOrFail($po->id)` against tenant_id/company_id from the same `$po`, which is a defense-in-depth chain — in practice the controller already scopes the document, so this is belt-and-braces.)
  - Two new tests at apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:
    - `test_vendor_refund_service_refuses_cross_tenant_repository_before_payment_create` (line 1488-1543) — asserts `ModelNotFoundException` thrown, asserts `Payment::query()->where('tenant_id', tenantA)->where('repository_id', repositoryB)->exists()` is false, asserts tenant-A payment count unchanged.
    - `test_vendor_refund_service_refuses_cross_tenant_payment_method_before_payment_create` (line 1556-1601) — same pattern for `payment_method_id`.
  - Updated existing test `test_vendor_refund_service_repository_lookup_skips_cross_tenant_repository` (line 1430-1480) — was asserting silent skip with row created; now asserts `ModelNotFoundException` AND no foreign-id Payment row exists. The change is semantically correct: the silent-skip behaviour was the leak.
  - Real two-tenant fixtures (no mocking — verified `grep "Mock|mock(|fake(|::shouldReceive"` returns empty in the test file).
  - Verification: `phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` → 64 tests / 190 assertions / 0 failures.

### Codex Findings 8/9 (IMPORTANT — structural-protection annotation)

- Status: **CLOSED-with-deviation** (Codex's preferred remediation said "remove the misleading regression-test claim"; the subagent kept the pin AND added the annotation. I treat this as defensible because the annotation explicitly acknowledges the limitation in its `note` text — the misleading-pin-only situation Codex flagged no longer exists.)
- Evidence:
  - Schema change at apps/api/app/Application/Sweep/InventoryYamlSchema.json:268-277 adds `structurally_protected_by_upstream_guard` as `array<string>|null`, optional (forward-compatible — 262 existing callsites validate unchanged), with a clear description that distinguishes sibling-callsite-id from `file:line` free-text locators.
  - api.treasury.027 (docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11177-11178) annotated `[api.treasury.026]`. The `note` at line 11176 explicitly says "the regression_test pin (test_payment_allocation_service_apply_refuses_cross_tenant_payment_id) exercises the upstream guard, not this line; this annotation is the honest signal." Self-disclosing the pin's limitation.
  - api.treasury.028 (line 11305-11306) — same pattern, `[api.treasury.026]`.
  - api.treasury.030 (line 11543-11544) annotated `[apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:626-633]` (file:line locator).
  - Verification: `php artisan sweep:inventory:verify-history` reports 489 events / 262 callsites / 0 problems. `phpunit --testsuite=Architecture --group=sweep-progress` reports Gate A 98 / Gate B 102 (matches expectations).
  - Edit_applied history events (lines 11161-11176, 11288-11304, 11527-11542) carry meaningful per-callsite notes.

### Codex Finding 13 (NICE-TO-HAVE — ExistsRuleVisitor whitespace)

- Status: **CLOSED**
- Evidence:
  - apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php:161-162 applies `trim()` to each fragment after `explode('|', ...)`. PHP's default `trim()` strips space + `\t\n\r\v\0`, so the whitespace classes Codex Finding 13 cited (space, tab, newline) are all covered. Unicode whitespace is NOT covered by default `trim()`, but Laravel's own ValidationRuleParser uses default-character trim, so the visitor matches Laravel's parsing behaviour exactly. This is the right call — the scanner should exactly mirror what the framework treats as a rule name.
  - Two new tests at apps/api/tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php:194-241:
    - `test_pipe_form_with_whitespace_around_fragments_is_flagged` — asserts `'sometimes | nullable | exists:partners,id'` produces a violation with table=`partners`, form=`inline_string`. Positive case verified.
    - `test_in_rule_containing_exists_substring_is_not_flagged` — asserts `'in:exists:foo,bar'` produces zero violations. Negative case verified — single fragment `in:exists:foo,bar` does not start with `exists:`, even after trim.
  - Verification: `phpunit tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` → 9 tests / 18 assertions / 0 failures.

## New findings

### NEW Finding A (NICE-TO-HAVE — annotation note typo on api.treasury.030)

- Severity: NICE-TO-HAVE
- Location: docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11542
- Issue: The history note for api.treasury.030's structural-guard annotation says "PaymentRepository::find at PaymentRefundService.php:420-423 (defense-in-depth scoped find inside the cashier-choice refund flow)". The actual code at apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:422-426 is `Payment::query()->where(...)->find($originalPaymentId)` — i.e., `Payment::find`, not `PaymentRepository::find`. The inventory's `resource: Payment` field at line 11427 is correct, but the human-readable note has a model-name typo. Purely descriptive — no security or test impact.
- Suggested fix: rewrite the note to say `Payment::find` (one-shot mutate script update; the annotation field value `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:626-633` itself is correct).

### NEW Finding B (NICE-TO-HAVE — api.treasury.030 structural protection over-narrows the upstream guard)

- Severity: NICE-TO-HAVE
- Location: docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11543-11544 (`structurally_protected_by_upstream_guard: ['apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:626-633']`)
- Issue: The cited locator points at `buildCashierChoiceMap()` (lines 626-633), which validates cashier-supplied payment ids against `$payments->pluck('id')->all()`. But the `$originalPayments` collection that backs `$payments` is fetched at apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:335-345 as `Payment::where('company_id', $originalReceipt->company_id)->...->get()`. That company-scoped query is the actual structural guard: it short-circuits Proportional and LargestFirst strategies the same way `buildCashierChoiceMap` short-circuits CashierChoice. The current annotation correctly captures the guard for one of three control-flow paths and silently relies on the same upstream query for the other two. A more-honest annotation would point at line 335-345 (the actual company-scoped query), or include both locators.
- Note: `Payment::where('company_id', ...)` at line 335 is single-column (no explicit `tenant_id`). Within a single tenant this is fine because company_id ownership implies tenant ownership — but it's less defensive than the .026/027/028 pattern (`tenant_id` + `company_id`). This is out of scope for the current closure but worth tracking.
- Suggested fix: replace the locator with `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:335-345` or include both the upstream company-scoped query AND the cashier-choice validator.

## Sibling-service vulnerability check (the meta-question Opus was asked to scrutinise)

The same data-binding-before-scope-check pattern that Finding 12 addressed in VendorRefundService was checked against:

1. **PaymentAllocationService::applyAllocation** (apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:75-316) — CLEAN. The Payment is scoped at line 83-87 via `Payment::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->findOrFail($paymentId)`. The Documents inside the loop are scoped at line 108-112 (api.treasury.027) and line 190-193 (api.treasury.028) using the same tenant/company anchor. PaymentAllocation::create at line 115-120 only sets `payment_id` from the scoped payment + `document_id` from the scoped document — NO caller-supplied FK is persisted. The Codex Findings 8/9 annotations correctly identify these lines as structurally protected by the upstream Payment guard (api.treasury.026).

2. **PaymentRefundService::refundPayment / partialRefund** (apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:45-117 and 127-191) — CLEAN. The `$payment` argument is the Eloquent model passed by `PaymentRefundController::findPaymentOrFail()` (apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php:28-36), which scopes by `tenant_id`. The refund Payment::create at line 68-84 (and 153-169) copies `payment_method_id`, `instrument_id`, `repository_id` from `$payment` — i.e., from a tenant-scoped row — not from caller input. NOT the Finding-12 pattern.

3. **PaymentRefundService::refundReceiptPayments** (line 317-474) — CLEAN. `$originalPayments` at line 335-345 is company-scoped (`Payment::where('company_id', $originalReceipt->company_id)->...`). The refund Payment::create at line 434 copies FK ids from `$original` (the scoped lookup at line 423-426). All FK ids on the new Payment row come from already-scoped rows, not caller input.

4. **MultiPaymentService::createSplitPayment / recordDeposit / recordPaymentOnAccount** (apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php) — CLEAN at the **system level** but with a noteworthy structural property. These services accept caller-supplied raw FK ids (`$split['payment_method_id']`, `$paymentMethodId`, etc.) and persist them to Payment::create WITHOUT a service-layer scoped lookup. The protection comes from MultiPaymentController's `ScopedExists::tenantAndCompany` validators (apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:34-47, 92-105, 220-228). This is exactly the **structurally_protected_by_upstream_guard** pattern that the new schema field describes — but these MultiPaymentService callsites are NOT currently in the inventory as separate Gate-B entries (they're not bare `find()`/`findOrFail()` — they're direct `::create()` calls), so no annotation is required. The pattern is acceptable defense-in-depth-only-at-controller because the controllers are the SOLE callers (verified via grep — no internal code calls these service methods bypassing the controllers).

5. **MultiPaymentService::applyDepositToDocument** (line 155-200) — POTENTIAL FUTURE FINDING (NOT in scope for round-3). Takes both `$deposit` and `$document` as already-loaded Eloquent models, no service-level cross-tenant verification. Controller (`MultiPaymentController::applyDeposit` line 141-185) scopes both, so currently safe. If a future internal caller passed a deposit_tenantA + document_tenantB, the service would create a cross-tenant PaymentAllocation row. This is the same defense-in-depth-at-controller-only pattern as MultiPaymentService::createSplitPayment — out of scope for the round-2 closure but worth a future audit.

6. **POS::ReceiptPaymentService::processPayments** (apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:202-225) — CLEAN. Already hardened to the Codex Finding 12 pattern — scoped lookup of PaymentRepository AND PaymentMethod under `$receipt->tenant_id`/`company_id` BEFORE Payment::create at line 252. The lookup uses `findOrFail` so cross-tenant ids throw `ModelNotFoundException` and the enclosing transaction rolls back. Reference template for the Finding 12 fix.

7. **PaymentController::store / storeMultiple** (apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:88-) — CLEAN. All FK ids validated by `ScopedExists::tenantAndCompany` (lines 101-137, 446-480). Payment::create at line 198 and 571 takes the validated values. Finding-12-style leak NOT possible.

8. **Billing\Presentation\Controllers\AdminBillingController::recordPayment** — OUT OF SCOPE. Different `Payment` model (App\Modules\Billing\Domain\Payment), super-admin-only route, design intent is to record cross-tenant manual billing payments. Not part of Treasury cluster.

**Conclusion**: No sibling Treasury domain service repeats the Finding-12 pattern that VendorRefundService had. The sweep is internally consistent: the only services that persist caller-supplied FK ids without a service-layer scoped lookup are guarded by `ScopedExists` at the controller layer, and the controllers are the only callers in the codebase.

## Defect surface checks

- `app()` helper in production diff: `git diff f19624cf..50e8d8ca -- apps/api/app/ | grep -E '^\+.*app\('` → empty. Test file uses `app()` for fixture instantiation, but this is the established pattern across the file (19+ pre-existing instances) and the project rule applies to production code only.
- `@phpstan-ignore`: `git diff f19624cf..50e8d8ca -- apps/api/ | grep '@phpstan-ignore'` → empty.
- `mixed`: `git diff f19624cf..50e8d8ca -- apps/api/ | grep -E '^\+.*: mixed'` → empty.
- Module boundary violations in VendorRefundService changes: NONE. The service imports only from Treasury, Document, and Accounting modules — all already-imported before this commit.
- Test honesty: the new VendorRefundService tests use real two-tenant fixtures (`$this->tenantA`, `$this->tenantB`, `$this->repositoryA/B`, `$this->paymentMethodA/B`, `$this->paymentA`, `$this->purchaseOrderA`) seeded via the test class setUp. They invoke the production code path (`$service->refundPrepayment(...)`) and assert post-conditions on real DB rows (`Payment::query()->...->exists()`). Tests cannot pass for an unrelated reason — they exercise the exact lines the fix added.

## What looks good

- Finding 12 fix is clean and minimal: scoped lookup BEFORE the Payment::create write, `findOrFail` so cross-tenant attempts abort the transaction, downstream code reuses the resolved `$resolvedRepository` reference. No duplicate query or risk of TOCTOU drift.
- Finding 12 test coverage is honest: asserts both the exception AND the post-condition (no foreign-id row, no row count change). The updated existing test now correctly reflects the new behaviour (was asserting silent-skip, now asserts exception) — and the docblock acknowledges the change.
- Schema change for Findings 8/9 is forward-compatible: 262 existing callsites validate unchanged because the field is optional + nullable. The schema description distinguishes sibling-callsite-id from `file:line` locators clearly.
- ExistsRuleVisitor `trim()` fix is one-line, surgical, and matches Laravel's own ValidationRuleParser behaviour.
- Verification gates all green: 64+9+2 PHPUnit tests pass, PHPStan clean, Pint clean, verify-history clean (489 events / 262 callsites / 0 problems), Gate A 98 / Gate B 102 unchanged.
- Cluster scope discipline maintained: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` is empty — no scope creep into POS or Voucher.

## Verification run

| Command | Expected | Actual | Pass |
|---|---|---|---|
| `phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` | 64 / 190 / 0 fail | 64 / 190 / 0 fail | YES |
| `phpunit tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` | 9 / 18 / 0 fail | 9 / 18 / 0 fail | YES |
| `phpunit --testsuite=Architecture --group=sweep-progress` | Gate A 98 / Gate B 102 | Gate A 98 / Gate B 102 | YES |
| `phpstan analyse --no-progress` | [OK] No errors | [OK] No errors | YES |
| `pint --test app/Modules/Treasury/ tests/Feature/Treasury/ app/Application/Sweep/Visitors/` | pass | `{"result":"pass"}` | YES |
| `sweep:inventory:verify-history --inventory-path=...tenant-isolation-sweep-inventory.yml` | 489 / 262 / 0 problems | 489 / 262 / 0 problems | YES |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty | empty | YES |

All verification gates pass.

## Recommended next step

Move api.treasury.001-032 from `under_review` to `verified` (or whatever the SOT terminal state is) once the Codex round-3 review concurs. The two NICE-TO-HAVE annotation accuracy nits (Findings A and B above) are non-blocking and can be cleaned up in a future inventory housekeeping pass.
