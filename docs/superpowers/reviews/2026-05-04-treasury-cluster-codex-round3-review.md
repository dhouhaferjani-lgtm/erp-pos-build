# Codex adversarial round-3 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: 50e8d8ca
Round-1 verdict: REQUEST-CHANGES (4 IMPORTANT)
Round-2 verdict: REQUEST-CHANGES (1 NEW IMPORTANT + 1 NICE-TO-HAVE)
Reviewer: codex (second-layer review of Opus round-3 verdict)

## Verdict

BLOCK

All three round-2 closure items are closed, and Opus's Finding-12 sibling-service persistence-pattern check is correct. I am blocking on one new same-cluster tenant-isolation read leak outside that persistence-pattern check: two MultiPayment partner-balance endpoints accept a route partner id and read payments by `partner_id` without tenant/company scoping.

## Opus finding-status verification

### Finding 12: VendorRefundService scope-before-create

- Opus's status: CLOSED
- My verification: AGREED
- Evidence: `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:51-55` locks the PO under the PO's tenant/company, `:86-96` resolves both `payment_method_id` and `repository_id` with tenant/company-scoped `findOrFail()` before `Payment::create()` at `:99`, and `:139-145` reuses `$resolvedRepository` for the balance update/GL branch. The new tests at `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1498-1542` and `:1556-1600` use real two-tenant fixtures, assert `ModelNotFoundException`, and correctly query for forbidden tenant-A rows carrying tenant-B `repository_id` / `payment_method_id`.

### Findings 8/9: structural-protection annotation

- Opus's status: CLOSED-with-deviation
- My verification: AGREED
- Evidence: `apps/api/app/Application/Sweep/InventoryYamlSchema.json:197-213` does not require the new field, and `:268-276` accepts `array<string>` or `null`. `api.treasury.027` and `.028` carry `structurally_protected_by_upstream_guard: [api.treasury.026]` at `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11177-11178` and `:11305-11306`. PaymentAllocationService control flow supports the claim: `Payment::findOrFail()` runs first at `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:83-87`, then the document loop/GL lookup run later in the same method at `:108-112` and `:190-193`. No path reaches those later lookups without the payment guard.

### Finding 13: ExistsRuleVisitor whitespace trim

- Opus's status: CLOSED
- My verification: AGREED
- Evidence: `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php` trims pipe fragments before testing for `exists:`, and `apps/api/tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` now covers whitespace-positive and `in:exists:foo,bar` negative cases. The unit suite passes with 9 tests / 18 assertions.

### Opus NICE-TO-HAVE A: api.treasury.030 note typo

- Opus's status: NEW NICE-TO-HAVE
- My verification: AGREED
- Evidence: the inventory note says `PaymentRepository::find` at `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:11542`, but the guarded lookup is `Payment::query()->where(...)->find($originalPaymentId)` at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:423-426`.

### Opus NICE-TO-HAVE B: api.treasury.030 locator over-narrows

- Opus's status: NEW NICE-TO-HAVE
- My verification: AGREED
- Evidence: the annotation locator points to `buildCashierChoiceMap()` at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:626-633`, which validates CashierChoice ids only. The broader upstream source for all three strategies is the company-scoped original-payment collection at `:335-345`. This is annotation accuracy, not a live security miss.

## Sibling-service vulnerability verification

- Result: CLEAN for Opus's specific meta-claim: I did not find another Treasury writer with the Finding-12 pattern of persisting caller-supplied raw FK ids before any tenant-scoped guard.
- Methodology: inspected `PaymentAllocationService`, `PaymentRefundService`, `MultiPaymentService`, `PaymentController`, `MultiPaymentController`, `PaymentRefundController`, and POS `ReceiptPaymentService` because no Treasury `Application/Services/ReceiptPaymentService.php` exists. `PaymentAllocationService` copies ids from scoped `Payment`/`Document` rows or preview output protected by the payment guard. `PaymentRefundService` copies FK ids from scoped `Payment` rows. `PaymentController` and `MultiPaymentController` persist validated ids behind `ScopedExists` and scoped route lookups; app-code grep found no production caller bypassing `MultiPaymentController` for the raw-FK `MultiPaymentService` methods. POS `ReceiptPaymentService` resolves repository and payment method under the receipt tenant/company before creating Treasury/receipt payment rows.

## New findings

### Finding 14: MultiPayment partner-balance endpoints read cross-tenant payments by unscoped partner id

- Severity: CRITICAL
- Location: `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:190-204`, `:262-272`; `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:204-226`, `:288-304`; routes at `apps/api/app/Modules/Treasury/Presentation/routes.php:149-159`
- Issue: `GET /api/v1/partners/{partner}/unallocated-balance/{currency}` and `GET /api/v1/partners/{partner}/account-balance/{currency}` accept a route partner id and pass it directly to service methods. The service queries `Payment::where('partner_id', $partnerId)` with no `tenant_id` or `company_id` predicate. A tenant-A user with `payments.view` who knows a tenant-B partner UUID can read tenant-B unallocated payment balance, and the account-balance endpoint returns the matching `Payment` collection.
- Fix: In the controller, resolve the partner under current `CompanyContext` tenant/company before calling the service, or reject with 404. In the service, add tenant/company parameters or accept a scoped Partner model and include `where('tenant_id', ...)` and `where('company_id', ...)` on both payment queries. Add cross-tenant denial tests for both GET endpoints plus same-tenant controls.

## Verification run

- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter "cross_tenant_repository_before_payment_create|cross_tenant_payment_method_before_payment_create" 2>&1 | tail -10` — OK, 2 tests / 4 assertions.
- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` — OK, 64 tests / 190 assertions.
- `vendor/bin/phpunit tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` — OK, 9 tests / 18 assertions.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` — OK, Gate A 98, Gate B 102, 2 tests / 4 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` — OK, no errors.
- `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ app/Application/Sweep/Visitors/` — pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — verified 489 events across 262 callsites, 0 problems.
- `vendor/bin/phpunit tests/Feature/Console/Sweep --no-coverage 2>&1 | tail -5` — OK, 118 tests / 404 assertions / 1 skipped.
- Forbidden diff greps for added production `app(`, `@phpstan-ignore`, and `: mixed` in `f19624cf..50e8d8ca` — empty.
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — empty.

## What looks good

- The Finding-12 fix is correctly placed before the write and uses the locked PO, not caller-supplied scope, as the tenant/company anchor.
- The structural-guard schema is backward-compatible, and the `.027/.028/.030` notes honestly disclose where regression pins are upstream/synthetic rather than direct callsite execution.
- The required verification gates are green; the block is from the newly observed unscoped read path, not from the round-2 remediation itself.
