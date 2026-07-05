# Codex second-layer review — api.loyalty cluster (round 3)

Review date: 2026-05-04
Branch tip reviewed: eaf9c3bd
Reviewer: codex (round-3 second-layer review post-Opus round-3 APPROVE; round-2 Codex BLOCK remediated)

Verdict: APPROVE
Commit reviewed: eaf9c3bd

## Round-2 BLOCK rationale verification

The round-3 fix honestly closes Codex round-2 Finding 1. `LoyaltyMemberController::optOut` now resolves the route member through `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId)`, then resolves the enrollment through `Enrollment::where('id', $enrollmentId)->where('member_id', $member->id)->firstOrFail()` before delegating to `MemberEnrollmentService::optOut($enrollment->id)`. `reactivate` uses the same pattern before calling `reactivate($enrollment->id)`.

Test-pin honesty check:
- Temporarily reverted only the `optOut` and `reactivate` controller guards, keeping the tests and earlier fixes. `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` failed exactly 2 tests: `test_opt_out_rejects_cross_tenant_enrollment` and `test_reactivate_rejects_cross_tenant_enrollment`, both with expected 404 but actual HTTP 200.
- Supplemental temporary assertion inversion on those two tests confirmed the database side of the exploit: the cross-tenant `opt-out` path flipped tenant-B's enrollment to `OptedOut`, and the cross-tenant `reactivate` path flipped tenant-B's enrollment back to `Active`.
- Restored the round-3 fix and original tests. The restored file passed: 16 tests / 50 assertions.

## LoyaltyMemberController exhaustiveness (round-3 unique test)

Exhaustive route/method walk found no third blind spot in `LoyaltyMemberController`.

- `index` and `lookupByPhone`: no route id; both are list/lookup queries anchored by `tenant_id`.
- `store`: no route id; writes `tenant_id` from `CompanyContext`.
- `show({id})`, `update({id})`, `enrollments({id})`: route member id is tenant-scoped through `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail(...)`.
- `enroll({id})`: round-2 guard remains in place before service delegation.
- `optOut({memberId}, {enrollmentId})` and `reactivate({memberId}, {enrollmentId})`: round-3 guard now tenant-scopes the member, then chains the enrollment to that member before service delegation.
- `transactions({memberId}, {enrollmentId})` and `adjust({memberId}, {enrollmentId})`: existing tenant-scoped member lookup anchors the enrollment lookup through `member_id`.

This covers every `loyalty/members` route mapped in `routes.php`.

## Defer audit + sibling-controller exhaustiveness

Finding A remains complete for `LoyaltyProgramController`: all five public route-param actions that pass an id into unscoped program service `findById` paths are enumerated: `show`, `update`, `destroy`, `activate`, and `deactivate`. `index`, `active`, and `store` are tenant-anchored and do not add a missing route-param service path. No audit addendum needed.

Finding B remains complete for `LoyaltyPOSController`: `previewEarning`, `earn`, and `redeem` are the unscoped request-body enrollment/reward id service paths; `rewards/{enrollmentId}` is separately tenant-anchored with `whereHas('member', tenant_id = ?)`. No audit addendum needed.

Sibling controllers are scoped through parent-program validation: `RewardController`, `TierController`, `EarningRuleController`, and `StampCardController` all validate the parent program's tenant before nested list/create, and validate the loaded resource's `program_id` before individual show/update/delete/activate/deactivate operations. I found no bypass of the `validateProgramAccess` pattern.

## New round-3 findings

None.

## Audit exhaustiveness

- Hostile-grep delta: `eaf9c3bd^..eaf9c3bd` changes only `LoyaltyMemberController`, `LoyaltyTenantIsolationTest`, and the defer-audit doc. The same-controller hostile walk found no remaining unscoped `LoyaltyMemberController` route-param method.
- Tests: `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`: OK, 16 tests / 50 assertions. Cross-cluster `vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php`: OK, 159 tests / 561 assertions, 15 PHPUnit deprecations.
- PHPStan: parallel run hit sandbox EPERM on `tcp://127.0.0.1:0`; serial `vendor/bin/phpstan analyse app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php --debug --no-progress --memory-limit=1G`: OK, no errors.
- Pint: `vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`: pass.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: verified 820 events across 268 callsites; 0 problems.
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`: empty.
- Workflow integrity: `api.loyalty` remains `in_progress`; all 10 inventoried `api.loyalty.001` through `.010` callsites remain `under_review` with `fix_commit: 74ffc022`.

## Confidence

High. The recurring blind spot was the `LoyaltyMemberController` route-param surface, and this pass walked every mapped method rather than relying on scanner output. Round-3 closes the two round-2 mutators honestly, the exploit pins fail in the expected way when the guard is removed, and no additional same-controller route-param method remains unscoped.
