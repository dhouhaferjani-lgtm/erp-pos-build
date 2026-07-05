# Codex second-layer review — api.loyalty cluster (round 1)

Review date: 2026-05-04
Branch tip reviewed: 74ffc022 (fix); e7706d3d (submit-state)
Reviewer: codex (round-1 second-layer review post-Opus APPROVE)

Verdict: BLOCK
Commit reviewed: 74ffc022

## Opus claim verification

I re-read `git show 74ffc022` end-to-end. Opus accurately described the submitted diff:

- `LoyaltyMemberController::{show,update,enrollments,transactions,adjust}` now resolve the route member through `LoyaltyMember::where('tenant_id', $this->companyContext->requireCompany()->tenant_id)->findOrFail(...)` before using downstream enrollment queries.
- `CreateMemberRequest` and `UpdateMemberRequest` inject `CompanyContext` and use `ScopedExists::tenantAndCompany('partners', $tenantIdFromCompanyContext, $companyId)` for `customer_id`.
- `EnrollMemberRequest` injects `CompanyContext` and uses `ScopedExists::tenant('loyalty_programs', $tenantId)`.
- `CreateProgramRequest` is limited to constructor injection plus the `company_ids.*` change from bare `exists:companies,id` to `ScopedExists::tenant('companies', $tenantId)`. No unrelated refactor.
- `CreateStampCardRequest` uses the route program param: `Rule::exists('loyalty_rewards', 'id')->where('program_id', $programId)`. Route-list and a one-off route bind probe confirm the route is `api/v1/loyalty/programs/{programId}/stamp-cards` and `parameter('programId')` returns the URL value (`programId=probe-program-uuid`).
- `UpdateStampCardRequest` scopes rewards through `whereIn('program_id', select id from loyalty_programs where tenant_id = ?)`. Laravel stores the closure via `DatabaseRule::using()` and invokes it from `DatabasePresenceVerifier::addConditions()` with an `Illuminate\Database\Query\Builder`; that class implements `Illuminate\Contracts\Database\Query\Builder`, so the closure type is runtime-compatible.

Test-pin honesty reproduced independently. I temporarily reverse-applied the production part of `0d6a01f8..74ffc022`, kept `LoyaltyTenantIsolationTest.php`, and ran `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`: 13/13 failed. The failures match Opus's diagnostics: six validator tests accepted foreign IDs with 201/200, five route lookup tests accepted foreign members with 200/201, `show` SQL was `select * from "loyalty_members" where "loyalty_members"."id" = ? ...`, and the partners validator SQL was `select count(*) as aggregate from "partners" where "id" = ?`. Re-applying the fix made the suite pass: 13 tests, 42 assertions.

Opus Finding 3 is correct as a tooling gap: the scanner does not credit `Model::where('tenant_id', ...)->findOrFail()` chains. Opus Finding 4 is cosmetic and consistent with current inventory churn: `verify-history` now reports 784 events, not the older count in the commit message.

I do not accept Opus's DEFER classification for Finding 1 as non-blocking. The unscoped `enroll` route is in the same controller and same loyalty-member route-param surface as the five submitted controller fixes, and the submitted regression explicitly covers the `enroll` endpoint only for `program_id`. Leaving the member route id unscoped means the endpoint is still tenant-unsafe after this cluster.

## New findings (round 1, second-layer)

1. CRITICAL — `LoyaltyMemberController::enroll` still creates cross-tenant enrollments.

`apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php:136` passes route `$id` directly into `MemberEnrollmentService::enroll(memberId: $id, programId: $data['program_id'])`. The service calls `EloquentLoyaltyMemberRepository::findById()` at `MemberEnrollmentService.php:40`, which is `LoyaltyMember::find($id)` at `EloquentLoyaltyMemberRepository.php:21`. The fixed `EnrollMemberRequest` only proves `program_id` belongs to tenant A; it does not prove the route member does. Tenant A can post `/api/v1/loyalty/members/{tenantBMember}/enroll` with a tenant-A program and create `loyalty_enrollments(program_id=A, member_id=B)`. The table has no tenant column and only a `(program_id, member_id)` unique key, so the database does not reject the tenant mismatch.

This callsite is absent from `api.loyalty.001` through `.010`; the inventory has line 126 matches elsewhere but not `LoyaltyMemberController::enroll`. Because this endpoint is part of the same controller cluster and the regression suite claims enrollment coverage, the cluster should be reopened.

2. CRITICAL — `LoyaltyProgramController` exposes unscoped program read/write/delete through service repositories.

`LoyaltyProgramController::{show,update,destroy,activate,deactivate}` call `ProgramManagementService` with a raw route id (`LoyaltyProgramController.php:42-114`). The service resolves programs through `EloquentLoyaltyProgramRepository::findById()` (`ProgramManagementService.php:76,128,168,203,238`), which is `LoyaltyProgram::find($id)` (`EloquentLoyaltyProgramRepository.php:22`). There is no post-load tenant comparison in this controller or service. A tenant-A user with `loyalty.view` can read tenant-B programs; a tenant-A user with `loyalty.manage` can patch, activate, deactivate, or delete tenant-B programs if the target state permits it. This is the concrete public-route reachability behind Opus's generic repository defer, so it is not just an Infrastructure-tier concern.

3. CRITICAL — POS loyalty service endpoints accept unscoped enrollment/reward ids.

`LoyaltyPOSController::previewEarning` passes request `enrollment_id` directly to `EarningProcessingService::previewEarning()` (`LoyaltyPOSController.php:80-99`), which calls unscoped `Enrollment::find()` via `EloquentEnrollmentRepository::findById()` (`EarningProcessingService.php:168`, repository line 21). `LoyaltyPOSController::earn` follows the same service path and can write transactions/balance changes to a foreign enrollment. `LoyaltyPOSController::redeem` passes raw `enrollment_id` and `reward_id` into `RedemptionProcessingService::redeemReward()` (`LoyaltyPOSController.php:149-158`), which resolves both through unscoped repositories (`RedemptionProcessingService.php:47,53`). The sibling `rewards/{enrollmentId}` endpoint is correctly tenant-anchored with `whereHas('member', tenant_id = ?)`, which makes the unscoped service endpoints stand out.

4. IMPORTANT — `CreateMemberRequest` still accepts unscoped polymorphic `loyaltyable_*`.

The fix hardens `customer_id`, but `loyaltyable_type` and `loyaltyable_id` remain only `in:contact,partner` plus `uuid` (`CreateMemberRequest.php:56-57`). `contacts` and `partners` both carry tenant/company ownership, and `LoyaltyMemberController::store` persists the validated polymorphic id while setting the member's `tenant_id` from `CompanyContext` (`LoyaltyMemberController.php:101-105`). Tenant A can therefore create a tenant-A loyalty member that points at a tenant-B contact or partner through the polymorphic fields. This was not found by the bare-exists inventory because there is no `exists` rule at all, but it is on the same create-member surface as `api.loyalty.004`.

5. NOTE — Scope-before-create binding itself is correct.

For `LoyaltyMemberController::store`, the persisted `tenant_id` comes from `CompanyContext::requireCompany()->tenant_id`, not `$request->user()?->tenant_id`. That part of the fix is structurally correct. The unique-phone validator still scopes by auth tenant, which is a possible super-admin/impersonation consistency issue, but it is not the cross-tenant FK write defect blocking this review.

## Audit exhaustiveness

- Hostile-grep result count: 16 broadened `->where(...)` matches on guarded-looking columns in `apps/api/app/Modules/Loyalty` production code.
- `LoyaltyMemberController.php:205,234`: protected by the newly tenant-scoped member lookup.
- `LoyaltyPOSController.php:50,62`: member lookup protected by tenant predicate; enrollment query anchored on that member.
- `CreateMemberRequest.php:44` and `UpdateMemberRequest.php:45`: unique-phone auth-tenant predicate, not part of the submitted FK fix.
- `CreateStampCardRequest.php:49`: fixed route-program reward scope.
- `UpdateStampCardRequest.php:62`: fixed tenant-program subquery reward scope.
- `EloquentLoyaltyMemberRepository.php:42`: tenant-scoped `findByCustomerId`.
- `EloquentEnrollmentRepository.php:30`, `EloquentStampCardRepository.php:64`: repository helper chains; safe only with scoped upstream ids.
- `EarnPointsOnReceiptCompleted.php:41,42,52,55,65`: line 39/48 member lookup includes `tenant_id = $event->tenantId`; enrollment query is anchored on that member.
- Hostile-grep `::find` / bare exists count: 10. Nine are unscoped Loyalty infrastructure repository `findById`/member-card methods; one is a comment in `CreateProgramRequest`. The repository matches are not harmless: at least `LoyaltyProgramController`, `LoyaltyMemberController::enroll`, and `LoyaltyPOSController` expose them through HTTP routes.
- Tests: `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` passed after restore, 13 tests / 42 assertions. Pre-fix pin check failed 13/13. Cross-cluster `vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php` passed, 156 tests / 553 assertions, with 15 PHPUnit deprecations.
- PHPStan: normal run hit sandbox EPERM while opening its parallel worker TCP server; rerun with `--debug` completed single-process with `[OK] No errors`.
- Pint: `./vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` returned `{"result":"pass"}`.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 784 event(s) across 268 callsite(s); 0 problem(s).`
- Inventory state: all ten `api.loyalty.001` through `.010` entries show `status: under_review`, `fix_commit: 74ffc022`, and `regression_test: tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`. Cluster `api.loyalty` remains `status: in_progress`.
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` was empty.

## Confidence

High. The submitted ten callsite fixes are real and the regression tests are honest, but the endpoint-level tenant boundary is still broken in the same Loyalty module through service/repository paths the scanner missed. The cluster should not advance to fixed until the unscoped member enroll route and the reachable unscoped program/POS service paths are either fixed in this sweep or explicitly split into a blocking follow-up cluster with tests.
