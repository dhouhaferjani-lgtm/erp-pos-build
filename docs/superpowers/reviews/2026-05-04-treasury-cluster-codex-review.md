# codex adversarial cluster review - Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: eca00bdb
Reviewer: codex
Parallel: a separate independent review is being run by the OTHER agent at the sibling path

## Verdict

REQUEST-CHANGES

## Commit reviewed

eca00bdb (and ancestors back to c91cf583)

## Summary

The Treasury hard gate should not open yet. The production edits for the inventoried callsites have the right scoped shape and the architecture drops match the expected 25/23 deltas, but several application/domain regression pins do not actually exercise the fixed service-layer `find()`/`findOrFail()` callsites. That makes the test anchor weaker than the Section 7 review threshold requires.

## Findings

### Finding 1: Service-layer regression pins stop at controller validation
- **Severity**: IMPORTANT
- **Location**: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:10734`, `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:10828`, `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:10922`, `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11016`, `apps/api/app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php:115`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:83`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:108`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:190`, `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:485`
- **Issue**: `api.treasury.026` through `api.treasury.029` are service-layer `PaymentAllocationService` callsites, but their pinned regression tests are HTTP tests that now fail at `SmartPaymentController`'s `ScopedExists` validators before calling the service. For example, `test_smart_payment_apply_refuses_cross_tenant_payment_id` asserts a `payment_id` validation error, so it never reaches `PaymentAllocationService::applyAllocation()` and never proves the scoped `Payment::query()->...->findOrFail($paymentId)` guard. The manual document cases have the same problem: the controller rejects `manual_allocations.*.document_id` before `previewManualAllocation()` or the transaction document lookups run.
- **Fix**: Add service-reaching regression coverage for these callsites. Either call `PaymentAllocationService` directly under a tenant-A company context with tenant-B ids, or craft endpoint tests where the controller validators pass and the service-layer guard is the first tenant boundary under test. Pin each inventory item to the test that reaches its actual fixed callsite.

### Finding 2: PaymentRefundService callsite is pinned to an unrelated payment-store validator test
- **Severity**: IMPORTANT
- **Location**: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11110`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:361`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:423`
- **Issue**: `api.treasury.030` is the scoped `Payment::query()->where(...)->find($originalPaymentId)` lookup inside `PaymentRefundService`, but the inventory pins it to `test_payments_store_refuses_cross_tenant_payment_method_id`. That test posts `/api/v1/payments` with a tenant-B `payment_method_id` and asserts a validation error; it does not enter `PaymentRefundService` or exercise `$originalPaymentId`.
- **Fix**: Replace the regression pin with a test that reaches the refund proration path and proves tenant-B original payment ids are not usable in the refund row creation loop. If that path is hard to hit through HTTP, add focused service coverage with real database fixtures and tenant context.

### Finding 3: VendorRefundService service-tier test is masked by the FormRequest
- **Severity**: IMPORTANT
- **Location**: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11204`, `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11298`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:636`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:653`, `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:50`, `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:113`
- **Issue**: The test comment says `test_refund_prepayment_refuses_cross_tenant_repository_at_service_tier` hits `VendorRefundService`, but the same comment also acknowledges the `repository_id` FormRequest validator trips first. After the presentation fix, tenant-B `repository_id` is rejected by `RefundPrepaymentRequest`, so the test does not reach `VendorRefundService`'s scoped `Document` or `PaymentRepository` lookups.
- **Fix**: Add a service-reaching test for `VendorRefundService::refundPrepayment()`, using a tenant-A `Document` object and tenant-B repository/payment inputs under real DB fixtures. Keep the FormRequest tests for `api.treasury.001` and `api.treasury.002`, but do not use them as the sole regression pins for service callsites `031` and `032`.

### Finding 4: Same-tenant controls are incomplete across the new regression file
- **Severity**: IMPORTANT
- **Location**: `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:352`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:450`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:503`, `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:580`
- **Issue**: The prompt requires corresponding same-tenant acceptance controls for each cross-tenant denial. The suite has some useful controls, such as refund-prepayment and payment-method account ids, but many cross-tenant tests only assert rejection. The service-layer tests are especially weak because several accept `[403, 404, 422]` without a same-tenant success/control path, so a route-level denial or unrelated validation failure can satisfy the test without proving the fixed callsite.
- **Fix**: Add same-tenant controls for each endpoint family and each service-layer path. Controls should prove the request reaches the production operation far enough that the target field/id would be accepted when tenant/company-scoped correctly.

## Test honesty assessment

The fixture setup is real and two-tenant: `setUp()` creates two tenants, two companies, two users, tenant-scoped roles, and tenant-owned payment methods, repositories, accounts, partners, documents, instruments, and payments. The tests use real database rows and HTTP paths; I found no model mocking.

The presentation-layer tests for `RefundPrepaymentRequest`, `PaymentMethodController`, `PaymentController`, `PaymentInstrumentController`, `SmartPaymentController`, and `BankReconciliationController` generally assert structured validation errors for the target fields, which is a useful guard against accidental 401/403-only passes. However, the suite is not honest enough for the 23 service-layer inventory pins: `PaymentAllocationService`, `PaymentRefundService`, and `VendorRefundService` are largely protected by tests that fail before those services execute or by unrelated endpoint tests.

Same-tenant controls are present for a subset only. They prove refund-prepayment and payment-method account ids can pass validation, but they do not cover each cross-tenant denial or the service-tier paths called out above.

## Architecture gate drops

- Gate A: 94 -> 69 (expected 69; delta 25)
- Gate B: 125 -> 102 (expected 102; delta 23)
- No discrepancies in the architecture counts.

## What looks good

- The inventoried presentation fixes use `ScopedExists::tenantAndCompany(...)` with `CompanyContext`-derived tenant/company ids.
- The inventoried Eloquent fixes use `query()->where('tenant_id', ...)->where('company_id', ...)->find/findOrFail` patterns without introducing production `app()` calls or `@phpstan-ignore` in the Treasury diff.
- Inventory workflow state is coherent: the 48 Treasury callsites are `under_review` with non-null `fix_commit` and `regression_test`, and `verify-history` passes on the live inventory.
- The verify-history relaxation is documented honestly and preserves the important format, metadata recompute, document-anchor, non-null-new-hash, and multi-orphan defenses. The self-consistent-splice residual gap is called out as a separate CI-diff responsibility.

## Verification commands

- `./vendor/bin/phpunit tests/Feature/Treasury` - pass: 229 tests, 717 assertions; 18 PHPUnit deprecations.
- `./vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` - pass: Gate A 69, Gate B 102.
- `./vendor/bin/phpstan analyse` - pass: no errors.
- `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/` - pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` - pass: verified 363 event(s) across 219 callsite(s); 0 problem(s).
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` - pass: empty output.
