# Codex second-layer review - api.loyalty cluster (round 2)

Review date: 2026-05-04
Branch tip reviewed: e8713644
Reviewer: codex (round-2 second-layer review post-Opus round-2 APPROVE; round-1 Codex BLOCK remediated)

Verdict: BLOCK
Commit reviewed: e8713644

## Round-1 BLOCK rationale verification

The round-2 `LoyaltyMemberController::enroll` fix honestly closes Codex round-1 Finding 1. `e8713644` adds a tenant-scoped pre-load at `LoyaltyMemberController.php:149-150` before delegating to `MemberEnrollmentService::enroll(memberId: $id, ...)`, and the test uses a fresh tenant-A program instead of the pre-enrolled `programA`, so the 404 is pinned to the route-member pre-load rather than the unique-membership constraint.

Test-pin honesty check:
- Temporarily removed only the new `enroll` pre-load and kept the test plus all round-1 fixes: `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` failed exactly one test, `test_enroll_rejects_cross_tenant_member_id`, with 201 instead of 404; the other 13 tests stayed green.
- Supplemental temporary assertion inversion confirmed the bad path creates `loyalty_enrollments(program_id=A, member_id=B)`.
- Restored the fix: `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` passed, 14 tests / 44 assertions.

The discarded pre-load result is acceptable. Assigning `$member` and passing `$member->id`, or using `whereKey()->exists()`, would be a little clearer, but the current pattern is functionally equivalent after the scoped `findOrFail`.

## Defer audit assessment

Finding A is accurate and sound: the audit captures `LoyaltyProgramController::{show, update, destroy, activate, deactivate}` and pins the service `findById` calls at `ProgramManagementService.php:76, 128, 168, 203, 238`. This is a real CRITICAL gap but it is outside the submitted `api.loyalty` member/FormRequest surface per the kickoff directive.

Finding B is directionally accurate and the recommended validator/controller fixes are sound. The audit calls out `previewEarning`, `earn`, and `redeem`, and the `api.pos-stabilization` blocked-cluster designation matches the inventory. Minor documentation nit: the line range for `earn` is stale in the audit; current `LoyaltyPOSController::earn` is around lines 174-200, not 100-148.

Finding C is technically correct: the fix needs a custom closure/after-validator that resolves `loyaltyable_type` to the target table and checks tenant/company scope for the supplied `loyaltyable_id`. I do not agree with treating it as merely NICE-TO-HAVE in severity; it is an IMPORTANT cross-tenant FK write. I do agree it can be documented and deferred only because the kickoff explicitly says cross-cluster blind spots should be deferred.

## New round-2 findings

### Finding 1 - CRITICAL - `LoyaltyMemberController::optOut` and `reactivate` still mutate cross-tenant enrollments

This is in-cluster and blocks approval. It is the same controller, same public `loyalty/members/{memberId}/enrollments/{enrollmentId}` route-param surface, and same service-tier unscoped enrollment repository pattern as the round-2 `enroll` remediation.

Reachable routes:
- `POST /api/v1/loyalty/members/{memberId}/enrollments/{enrollmentId}/opt-out`, `can:loyalty.manage`
- `POST /api/v1/loyalty/members/{memberId}/enrollments/{enrollmentId}/reactivate`, `can:loyalty.manage`

Bad path:
- `LoyaltyMemberController::optOut` ignores `$memberId` and calls `$this->enrollmentService->optOut($enrollmentId)`.
- `LoyaltyMemberController::reactivate` ignores `$memberId` and calls `$this->enrollmentService->reactivate($enrollmentId)`.
- `MemberEnrollmentService::{optOut, reactivate}` call `EnrollmentRepositoryInterface::findById`.
- `EloquentEnrollmentRepository::findById` is bare `Enrollment::find($id)`.

Temporary exploit pins passed against the restored round-2 tree:
- Tenant A posted with `memberId=memberA` and `enrollmentId=enrollmentB` to `opt-out`: response 200; tenant-B enrollment status became `OptedOut`.
- Tenant A posted with `memberId=memberA` and `enrollmentId=enrollmentB` to `reactivate`: response 200; tenant-B enrollment status became `Active`.

Expected remediation: before delegating, pre-load tenant-scoped member and enrollment chained to that member, e.g. resolve `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId)` and then `Enrollment::where('member_id', $member->id)->findOrFail($enrollmentId)`. Add regression tests for both cross-tenant routes.

## Audit exhaustiveness

- Hostile-grep delta: `e8713644` introduced only the canonical `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id)` in `enroll`; no new POS files changed. Hostile grep also exposed the new blocking miss above (`optOut` / `reactivate`).
- Tests: restored `tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`: OK, 14 tests / 44 assertions. Cross-cluster `vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php`: OK, 157 tests / 555 assertions, 15 PHPUnit deprecations.
- PHPStan: initial parallel run hit sandbox EPERM on `tcp://127.0.0.1:0`; serial rerun with `--debug --no-progress` passed with no errors for `app/Modules/Loyalty` plus `tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`.
- Pint: `vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`: pass.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: verified 810 events across 268 callsites; 0 problems. `e8713644` itself did not mutate the inventory YAML; later branch commits explain the event-count growth from the commit message's 792.
- POS surface diff: `git diff --name-only dev..e8713644 -- apps/pos apps/api/app/Modules/POS apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` was empty; `git diff --name-only e8713644^ e8713644` for the same POS surface was also empty.
- Workflow integrity: all 10 `api.loyalty` callsites remain `under_review` with `fix_commit: 74ffc022`. However, `git log --oneline 74ffc022..e8713644` does not show exactly one commit in this local history; it includes unrelated catalog/document/service/workshop commits between `74ffc022` and `e8713644`. The single-commit diff `e8713644^..e8713644` is cleanly limited to the loyalty controller, loyalty test, and defer audit.

## Confidence

High. The submitted `enroll` fix is honest, but round-2 review missed two adjacent public mutating endpoints in the same controller that still allow tenant A to mutate tenant B's enrollment state. This is not a defer-audit disagreement; it is a live in-cluster blocker under the same rationale that made round-1 Finding 1 non-deferrable.
