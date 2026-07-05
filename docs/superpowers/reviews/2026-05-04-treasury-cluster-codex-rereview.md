# Codex adversarial round-2 cluster review — Treasury (Section 7)

Review date: 2026-05-04
Branch tip reviewed: f19624cf
Round-1 verdict: REQUEST-CHANGES (4 IMPORTANT findings)
Reviewer: codex (second-layer review of Opus round-2 verdict)

## Verdict
REQUEST-CHANGES

Opus's production-code closure is mostly correct: the live Treasury bare-exists surfaces are scoped or removed, `payment_instruments` / `users` / `journals` are guarded, and the mandatory verification commands pass. I do not agree that the hard gate should open yet. I found one substantive new IMPORTANT service-layer gap in `VendorRefundService`: the service creates the refund `Payment` row with caller-supplied `payment_method_id` / `repository_id` before the scoped repository lookup runs, so the new service-level cross-tenant test can pass while still persisting a tenant-A payment that references tenant-B's repository id.

## Opus finding-status verification

### Finding 1: Pipe-form scanner blind spot
- Opus's status: CLOSED
- My verification: AGREED, with scanner robustness caveat
- Evidence: `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php:147-173` now splits pipe-form strings and flags `sometimes|nullable|exists:partners,id` and `exists:foo,id|nullable`; `apps/api/tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php:54-111` covers those forms; `MultiPaymentController.php:32-48,87-108,146-152,220-229` no longer has live pipe-form `exists:` rules.
- Caveat: the parser does not `trim()` fragments before `str_starts_with($fragment, 'exists:')`. Laravel accepts a leading-space rule name (`ValidationRuleParser::parse(" exists:partners,id")` returns `Exists`), so `nullable | exists:partners,id` remains scanner-invisible. I found no live Treasury hit for that whitespace pattern.

### Finding 2: `payment_instruments` unguarded and `PaymentController::store.instrument_id` unscoped
- Opus's status: CLOSED
- My verification: AGREED
- Evidence: `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:71` includes `payment_instruments`; `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:112-116` uses `ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId)`.

### Finding 3: `journals` and `users` missing from `GUARDED_TABLES`
- Opus's status: CLOSED
- My verification: AGREED
- Evidence: `TenantScopedExistsRulesTest.php:63,87` includes `journals` and `users`; `PaymentRepositoryController.php:76,132` scopes `responsible_user_id` with `ScopedExists::tenant('users', $tenantId)`. `users` has `tenant_id` and no `company_id` in `database/migrations/2025_11_30_000003_create_users_table.php:16-40`.
- `default_journal_id` removal is justified: `find apps/api/database/migrations/ -name '*journal*'` finds only `journal_entries` migrations, and both `grep 'Schema::create.*journals'` and `grep "table.*'journals'" apps/api/app/Modules/` return no hits. `PaymentMethodController.php:77-82,153-158` documents the missing table and stores only nullable UUIDs.

### Finding 4: PHPStan errors in `TreasuryTenantIsolationTest`
- Opus's status: CLOSED
- My verification: AGREED
- Evidence: full `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` returns `[OK] No errors`; `$instrumentB` is read at test lines `769,840,885`, `$purchaseOrderB` at `1359`, and the `TestResponse<Response>` helper annotation is present.

### Finding 5: Inventory pattern-type counts off by one
- Opus's status: ACKNOWLEDGED-AND-OBSOLETED
- My verification: AGREED
- Evidence: the regenerated inventory now includes `api.treasury.049..062`; e.g. `api.treasury.049` starts at `tenant-isolation-sweep-inventory.yml:14148` and `api.treasury.062` at `:16336`.

### Finding 6: 7-vs-6 commit count and ClusterResolver scope creep
- Opus's status: ACKNOWLEDGED-AND-DEFERRED
- My verification: AGREED
- Evidence: not a Treasury production blocker. The requested sibling diff check is empty: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`.

### Finding 7: verify-history multi-orphan reporting fidelity
- Opus's status: STILL-OPEN-AS-DEFERRED
- My verification: AGREED
- Evidence: unchanged from round 1 and not in the Treasury remediation range. Current inventory history verifies cleanly: `486 event(s) across 262 callsite(s); 0 problem(s)`.

### Finding 8: Codex Finding 1, `api.treasury.026..029` service-layer pin honesty
- Opus's status: PARTIALLY-CLOSED
- My verification: AGREED
- Evidence: `api.treasury.026` is pinned to `test_payment_allocation_service_apply_refuses_cross_tenant_payment_id` at inventory `:10968`, and the test calls `PaymentAllocationService::applyAllocation(paymentId: $paymentB->id)` at `TreasuryTenantIsolationTest.php:1189-1202`, reaching the scoped `Payment::findOrFail` at `PaymentAllocationService.php:83-87`. `api.treasury.029` is pinned to `test_payment_allocation_service_preview_manual_refuses_cross_tenant_document_id` at inventory `:11298`, and that test reaches `previewManualAllocation()`'s scoped `Document::findOrFail` at `PaymentAllocationService.php:485-488`.
- Opus's residual is real: `api.treasury.027` and `.028` are pinned at inventory `:11078` and `:11188` to the same payment-id test. That test fails before `DB::transaction()` because the payment guard at `PaymentAllocationService.php:83-87` runs before the document loop at `:108-112` and the GL document lookup at `:190-193`. The exact requested phpunit command produced no stack trace because the test expects the exception and passes; direct code flow confirms the line-87 guard.
- Preferred fix: add a schema-backed structural annotation rather than pretending there is a direct service-reaching cross-tenant test. For automatic allocation the preview documents come from company-scoped query output; for manual allocation, cross-tenant document ids are rejected by `api.treasury.029` before the transaction. Add a field such as `structurally_protected_by_upstream_guard` to `InventoryYamlSchema.json`, annotate `027/028` with `api.treasury.026` plus `api.treasury.029`/preview-source protection as appropriate, and remove the misleading regression-test claim.

### Finding 9: Codex Finding 2, `api.treasury.030` PaymentRefundService pin
- Opus's status: PARTIALLY-CLOSED
- My verification: AGREED
- Evidence: inventory `:11408` pins `api.treasury.030` to `test_payment_refund_service_scoped_find_refuses_cross_tenant_payment_id`, but that test only reproduces the query at `TreasuryTenantIsolationTest.php:1302-1329`; it does not execute `PaymentRefundService.php:423-426`. This is better than the old unrelated payment-store validator pin, but it is still not a true callsite-reaching regression pin. The service path is structurally protected by `buildCashierChoiceMap()` validating ids against the already company-scoped `$originalPayments` collection at `PaymentRefundService.php:626-633`.

### Finding 10: Codex Finding 3, `api.treasury.031..032` VendorRefundService tests masked by FormRequest
- Opus's status: CLOSED
- My verification: AGREED for the two scoped lookup callsites; new adjacent issue below
- Evidence: `api.treasury.031` is pinned at inventory `:11518`; the forged-document test at `TreasuryTenantIsolationTest.php:1353-1378` reaches the scoped `Document::lockForUpdate()->findOrFail()` at `VendorRefundService.php:50-54`. `api.treasury.032` is pinned at inventory `:11628`; `test_vendor_refund_service_repository_lookup_skips_cross_tenant_repository` at `TreasuryTenantIsolationTest.php:1428-1461` reaches the scoped `PaymentRepository::find()` at `VendorRefundService.php:113-117`.

### Finding 11: Codex Finding 4, same-tenant controls
- Opus's status: CLOSED
- My verification: AGREED
- Evidence: same-tenant controls exist for the newly-found validator paths at `TreasuryTenantIsolationTest.php:990-1083`, and for service paths at `:1211-1228`, `:1264-1283`, `:1319-1329`, and `:1387-1411`. I spot-checked new callsites `049`, `058`, `060/061`, and `062` against their production routes and fields.

## New findings (beyond Opus's round-2 verdict)

### Finding 12: Vendor refund service persists cross-tenant FK ids before its scoped repository guard
- Severity: IMPORTANT
- Location: `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:75-80`, `:113-117`; `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php:1428-1461`; `apps/api/app/Modules/Treasury/Domain/Payment.php:154-156`
- Issue: The new service-reaching test for `api.treasury.032` calls `refundPrepayment()` with tenant-A PO data and tenant-B `repositoryId`. The service creates a tenant-A refund `Payment` first, storing the caller-supplied `repository_id` at line 80, then later scopes `PaymentRepository::query()->where(...)->find($repositoryId)` at lines 113-117 and skips the balance update when the repository is cross-tenant. That proves tenant B's balance is unchanged, but it does not prevent tenant-A `payments.repository_id` from pointing at tenant-B's repository. The `Payment::repository()` relation is an unscoped `belongsTo`, so this is a real cross-tenant data binding/leak risk. The same create block also stores caller-supplied `payment_method_id` at line 79 without a service-layer scoped lookup.
- Fix: resolve and lock the payment method and repository under `$lockedPo->tenant_id` / `$lockedPo->company_id` before `Payment::create()`. Because the FormRequest requires `repository_id`, the service should throw `ModelNotFoundException`/domain exception on a cross-tenant repository rather than create the refund row with a foreign id. Pin a regression test that asserts no tenant-A `Payment` is created with tenant-B `repository_id` or `payment_method_id`.

### Finding 13: ExistsRuleVisitor misses Laravel-accepted whitespace before pipe fragments
- Severity: NICE-TO-HAVE
- Location: `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php:155-157`
- Issue: `explode('|', $node->value)` is correct, but each fragment is checked without `trim()`. Laravel trims rule names during parse, so `"nullable | exists:partners,id"` validates as `Exists` but the scanner misses it. I found no current Treasury production hit.
- Fix: trim each fragment before `str_starts_with()`, and add a unit test for `"sometimes | nullable | exists:partners,id"` plus a negative for `"in:exists:foo,bar"`.

## Verification run

- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php` - OK, 62 tests, 185 assertions.
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` - OK, Gate A = 98, Gate B = 102.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G` - OK, no errors.
- `./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/ app/Application/Sweep/Visitors/` - pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` - verified 486 event(s) across 262 callsite(s); 0 problem(s).
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` - empty.
- Requested pipe-form grep - no live Treasury production hits.
- `grep -rnE "Rule::exists\\(" apps/api/app/Modules/Treasury/ --include="*.php"` - two hits, both `PaymentRepositoryController` `gl_account_id` rules scoped by `where('company_id', $companyId)`.
- Forbidden production diff greps for added `app(`, `@phpstan-ignore`, and `: mixed` under `apps/api/app/` - no hits.
- `vendor/bin/phpunit tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter test_payment_allocation_service_apply_refuses_cross_tenant_payment_id 2>&1 | head -20` - OK, 1 test, 1 assertion; no stack trace because the exception is expected by the test.
- `vendor/bin/phpunit tests/Unit/Application/Sweep/Visitors/ExistsRuleVisitorTest.php` - OK, 7 tests, 14 assertions.

## What looks good

- The live Treasury Presentation-tier validators are now scoped through `ScopedExists` or intentionally removed for the nonexistent `journals` table.
- Inventory history is healthy: final `edit_applied` events for `api.treasury.026..032` have non-null chain hashes and verify-history reports 0 problems.
- The service-layer tests for `PaymentAllocationService::previewManualAllocation` and `VendorRefundService` now bypass FormRequests and use real two-tenant fixtures.
