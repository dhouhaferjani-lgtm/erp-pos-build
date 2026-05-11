# api.super-admin-context + web.super-admin-frontend (paired) — Codex review

Reviewed commit: e51d3951
Submission commit: 29e2935b
Reviewer: codex
Date: 2026-05-08
Verdict: BLOCK

## Verdict rationale
The applied `#[CrossTenantRoute]` method attributes, the API controller architecture test, the web queryKey namespace test, and the FraudAlertActionModals fix all behave as intended under baseline and mutation checks. Approval is blocked by the API deferrals fixture: multiple entries claim concrete tenant-binding mechanisms that are not present in production code, so the fixture is masking real unclassified controller methods instead of documenting legitimate heuristic misses.

## API arch test review
`apps/api/tests/Architecture/ControllerTenantContextTest.php` implements the intended invariant: branch (a) instantiates `CrossTenantRoute` and re-checks a non-blank reason, branch (b1) scans method bodies for the listed tenant-context substrings, and branch (b2) deliberately limits class-body fallback to `CompanyContext`. Discovery covers `app/Http/Controllers/Api/**` plus module `Presentation/Controllers/*Controller*.php`, and the fixture supports per-method and class wildcard deferrals. Baseline run: `cd apps/api && vendor/bin/phpunit tests/Architecture` => `OK (9 tests, 41 assertions)`.

## API mutation tests
Mutation 3a, removing `#[CrossTenantRoute]` from `SuperAdminController::dashboard`, failed red naming `App\Http\Controllers\Api\Admin\SuperAdminController::dashboard`. Mutation 3b, removing the attribute from `AdminBillingController::getInvoice`, failed red naming `App\Modules\Billing\Presentation\Controllers\AdminBillingController::getInvoice`. Mutation 3c, adding `AuthController::unfiltered`, failed red naming that method. Mutation 3d, temporarily allowing blank reasons and setting one attribute reason blank, still failed red through the scan-time reason check. All temporary edits were restored, and the API suite was rerun green afterward.

## Hostile-grep coverage
The fleet-wide controller grep for `Tenant::all|Company::all|withoutTenancy|UnsetCompanyId|UnscopedTenant` returned no production hits. The frontend admin-feature grep returned no inverse leaks inside `features/admin`. The broad outside-admin grep returned `FraudAlertActionModals.tsx:25` for `queryKey: ['users', 'admin-role']`; this is a false positive because `admin-role` is the second segment, while the invariant and test correctly inspect only the first segment.

## Reason text quality
The new production `#[CrossTenantRoute]` reason strings are specific and generally cite the relevant guard, audit, signature-verification, or public/pre-auth shape. I did not find boilerplate in the method-level attributes. The problem is in the deferral reasons, not the applied attribute reasons.

## Deferrals fixture audit
BLOCKER: `DocumentAdditionalCostController` is deferred as route-model-binding via a tenant-scoped `Document` global scope, but `apps/api/app/Modules/Document/Domain/Document.php` has `scopeForTenant()` only; I found no `booted()`/`addGlobalScope()` tenant scope. The routes use permission strings such as `can:documents.view`, not model-scoped authorization against the bound document. BLOCKER: `RoleController` is deferred as Spatie TeamScope-only, but `assignRole`, `removeRole`, and `userRoles` call `User::findOrFail($userId)` on the global `User` model, which has no tenant global scope, before assigning/reading roles. BLOCKER: product deferrals are factually wrong. `ProductImageController` is deferred as service-trust with a `ProductImageService` `CompanyContext` verification, but that service does not inject `CompanyContext` and route-bound `Product`/`ProductImage` are not tenant-scoped by a model global scope. `KeyComponentController`, `IngredientController`, `HealthClaimController`, and `CertificationController` deferral reasons cite `Product*Service` classes / `Product $product` route binding, but the inspected controllers directly query global reference tables and those named service files do not exist.

## Web arch test review
`apps/web/src/__tests__/architecture/queryKeyNamespace.test.ts` scans `.ts/.tsx` files, extracts the first literal segment from `queryKey: [...]`, requires `features/admin/**` keys to start with `admin`, and rejects `admin`/`admin-*` first segments elsewhere. Baseline run: `cd apps/web && pnpm test src/__tests__/architecture/` => `Test Files 1 passed (1) | Tests 4 passed (4)`.

## Inventory state
`cd apps/api && php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 1720 event(s) across 347 callsite(s); 0 problem(s).` The reviewed rows `api.super-admin-context.001`, `api.unmapped.020-023`, and `web.super-admin-frontend.001` are under review with `fix_commit: e51d3951`.

## Scope check
`git diff --stat fcb4c7ab..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher apps/web/src/features/pos` returned empty.

## BLOCKERs (if any)
Deferrals fixture masks real gaps: `DocumentAdditionalCostController` has no verified tenant-scoped route model binding, `RoleController` has unscoped `User::findOrFail` role operations, and multiple product deferrals cite nonexistent or non-CompanyContext service-trust mechanisms.

## NICE-TO-HAVEs (if any)
Consider making `ControllerTenantContextTest` validate that every deferral entry has a non-blank `reason` and that each deferred class/method resolves to a discovered controller method, so fixture drift fails in the test instead of depending on review.

## Sign-off
Blocked until the incorrect deferrals are either replaced with real tenant binding / explicit `#[CrossTenantRoute]` where appropriate, or narrowed with accurate, verified reasons and additional tests proving the alternate scoping mechanism.
