# Opus adversarial cluster review — api.loyalty (round 2)

Review date: 2026-05-04
Branch tip reviewed: e8713644
Reviewer: opus (round-2 post-Codex round-1 BLOCK + round-2 remediation)
Owner: codex

## Verdict

APPROVE

## Round-2 fix verification

The round-2 commit `e8713644` is a single-call surgical fix that mirrors the canonical `Model::where('tenant_id', $tenantId)->findOrFail($id)` pattern Codex used for the inventoried `.006`–`.010` callsites in the same controller:

```php
// LoyaltyMemberController::enroll, lines 149-150
$tenantId = $this->companyContext->requireCompany()->tenant_id;
LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id);
```

Honest checklist:
- `$tenantId` is resolved via `$this->companyContext->requireCompany()->tenant_id` — NOT via `$request->user()`, NOT via `app()`. The `CompanyContext` dependency was already constructor-injected at line 32, so no constructor changes were needed.
- The pre-load result is intentionally discarded (rather than passing `$member->id` into the service). Since the service path then fetches by id again via `LoyaltyMember::find($id)` in `EloquentLoyaltyMemberRepository::findById`, the discarded pre-load is acceptable: the route id has been proven to exist within the tenant, so the unscoped repository fetch will resolve the same row. `ModelNotFoundException` from the pre-load is caught by Laravel's exception handler and returned as 404 before the service is ever called.
- The new test `test_enroll_rejects_cross_tenant_member_id` constructs a fresh tenant-A program (`Program A New`), NOT the pre-enrolled `programA` from `setUp`. This is critical — without it, the existing membership-uniqueness collision (`programA + memberA` is enrolled in `setUp`) would conflate the failure path. The fresh program ensures the `EnrollMemberRequest::program_id` validator passes, so the failure must come from the controller pre-load, not from validator bounce.

**Test-pin honesty check (REQUIRED, executed):**

I reverted ONLY the `LoyaltyMemberController.php` controller diff to its `HEAD~1` state (preserving the new test, preserving all round-1 fixes), then re-ran `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`:

```
...........F..                                                    14 / 14 (100%)
There was 1 failure:
1) Tests\Feature\Loyalty\LoyaltyTenantIsolationTest::test_enroll_rejects_cross_tenant_member_id
Expected response status code [404] but received 201.
Tests: 14, Assertions: 43, Failures: 1.
```

Exactly the expected pin behavior:
- 13/13 round-1 tests still PASS — confirming the round-1 fixes are independent of the round-2 controller change.
- The new test fails ONLY at `assertStatus(404)` with `received 201` — proving that without the pre-fix, `MemberEnrollmentService::enroll` succeeds end-to-end and returns the 201-`enrollment created` response. The 201 implies the service committed the cross-tenant `loyalty_enrollments(program_id=newProgramA, member_id=memberB)` row before returning. (The follow-up `assertDatabaseMissing` would also have failed if reached, but PHPUnit short-circuits on the first failure.)
- After restoring `e8713644`'s controller content: 14/14 PASS, 44 assertions.

This is a real cross-tenant write defect that round-2 honestly closes. The pin behavior matches Codex's BLOCK-level diagnostic: cross-tenant POST `/loyalty/members/{tenantB-id}/enroll` with a tenant-A program creates an `Enrollment(program_id=A, member_id=B)` row in a table without a `tenant_id` column, and only a `(program_id, member_id)` unique key — so the DB cannot reject the mismatch.

## Defer audit assessment

Reviewed `docs/superpowers/audits/2026-05-04-loyalty-cross-cluster-blind-spots.md` end-to-end and cross-checked file/line accuracy against the live source.

### Finding A — LoyaltyProgramController (CRITICAL → DEFER)

Surface accuracy: **confirmed**. `LoyaltyProgramController.php` lines 42-114 (`show`, `update`, `destroy`, `activate`, `deactivate`) all pass raw route `$id` directly to `ProgramManagementService::*` methods. `ProgramManagementService::updateProgram` at line 76 calls `$this->programRepository->findById($programId)`, which is `EloquentLoyaltyProgramRepository::findById($id)` → unscoped `LoyaltyProgram::find($id)`. There is no post-load tenant comparison. The audit's claim is exactly right: a tenant-A user with `loyalty.view` reads tenant-B programs; with `loyalty.manage` they patch / activate / deactivate / delete tenant-B programs.

Fix recommendation: **sound**. The proposed mirror of the LoyaltyMemberController pattern (pre-load `LoyaltyProgram::where('tenant_id', $tenantId)->findOrFail($id)` before delegating to the service, OR introduce a tenant-scoped service method) would close the gap.

Defer triage: **defensible**. This is a different controller surface (LoyaltyProgramController, not LoyaltyMemberController). The api.loyalty inventory enumerated 5 LoyaltyMember findOrFail callsites + 5 FormRequest validators, none of which include the LoyaltyProgramController service-tier reachability. Codex's round-1 BLOCK explicitly only insisted Finding 1 (enroll) be in-cluster — he did not escalate Finding 2 to a BLOCK condition, framing it as a "cross-cluster" callsite reachable via service tier. The kickoff brief's directive ("If you find a cross-cluster blind spot, document and defer") covers this. NOT escalating to REQUEST-CHANGES.

### Finding B — LoyaltyPOSController (CRITICAL → DEFER)

Surface accuracy: **confirmed**. `LoyaltyPOSController` `previewEarning`, `earn`, `redeem` pass request-body `enrollment_id` and `reward_id` directly to `EarningProcessingService` / `RedemptionProcessingService`, which call unscoped `EloquentEnrollmentRepository::findById` and `EloquentRewardRepository::findById`. The audit's note that `rewards/{enrollmentId}` correctly uses `whereHas('member', tenant_id = ?)` is also accurate — making the unscoped POS endpoints stand out as a real inconsistency.

Fix recommendation: **sound**. Tenant-scoped FormRequest validator (e.g., `Rule::exists('loyalty_enrollments', 'id')->whereIn('member_id', subquery LoyaltyMember.id WHERE tenant_id)`) and/or controller pre-load would close the gap.

Defer triage: **defensible and required**. The POS surface is gated by `api.pos-stabilization` (POS-blocked cluster). Per the master plan and kickoff brief, all POS work is out of scope this session entirely. The POS surface diff (`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`) is empty, confirming no POS-side modifications. NOT escalating.

### Finding C — CreateMemberRequest polymorphic `loyaltyable_id` (IMPORTANT → DEFER)

Surface accuracy: **confirmed**. `CreateMemberRequest.php` lines 56-57 validate `loyaltyable_type` only with `in:contact,partner` and `loyaltyable_id` only with `uuid`. There is no `exists` rule. `LoyaltyMemberController::store` persists the validated polymorphic id while setting `tenant_id` from `CompanyContext`, so a tenant-A loyalty member can be created pointing at a tenant-B contact/partner.

Fix recommendation: **sound**. The proposed `withValidator()` after-callback (or custom rule that resolves the morph table by `loyaltyable_type` and checks `tenant_id`/`company_id`) is the standard polymorphic-FK pattern.

Defer triage: **defensible, but the closest call**. This finding is in the same FormRequest as `api.loyalty.004` (CreateMemberRequest::customer_id), which is uncomfortably close to the cluster boundary. Counter-arguments for deferral:
1. The validator pattern is genuinely different — `api.loyalty.004` was a bare `exists:partners,id` (Gate A territory); the polymorphic case has no `exists` rule at all. The sweep scanner inventory is bare-exists-only; this surface was invisible to scanner generation, not just under-resolved.
2. The same polymorphic pattern likely exists across the codebase (Document.morphable, Audit.aggregate per the audit doc) and warrants its own dedicated sweep with a polymorphic-FK scanner pass, not a one-off fix.
3. Codex round-1 categorized this as IMPORTANT (not CRITICAL) and did not include it in the BLOCK reasoning; the orchestrator's triage matches Codex's severity assessment.

I do flag this as a **NICE-TO-HAVE** in the new findings section below — if the orchestrator wants to bundle it into the api.loyalty cluster anyway because it's the same FormRequest file, that would be a defensible choice and a minor remediation. But the defer-to-polymorphic-FK-sweep classification is also defensible per the kickoff brief, and I am NOT making my verdict contingent on it.

## New round-2 findings

### NICE-TO-HAVE — Polymorphic FK validator (Finding C bundling discussion)

The CreateMemberRequest polymorphic `loyaltyable_id` defer is the orchestrator's closest call. Bundling argument: it's literally the same FormRequest file as api.loyalty.004 (`CreateMemberRequest.php`), one validator entry away. Defer argument: it's a categorically different validator pattern (no `exists` rule at all → polymorphic morph-type-conditional resolution). I lean defer because the broader polymorphic-FK pattern deserves its own scanner-driven sweep, but flagging this here so the orchestrator has the option to bundle if they prefer cluster-cohesion over pattern-cohesion.

### NICE-TO-HAVE — Gate B count actually went UP by 1 in round-2

Pre-round-2 (HEAD~1): Gate A=80, Gate B=80.
Post-round-2 (HEAD): Gate A=80, Gate B=81.

The kickoff brief noted "no expected delta" because the scanner is blind to `Model::where('tenant_id', ...)->findOrFail()` chains (Opus round-1 Finding 3). In practice, adding ONE MORE such chain in `enroll` makes Gate B count one higher, since the scanner sees `findOrFail($id)` but cannot credit the upstream `where('tenant_id', ...)` as scoping. This is structurally identical to all 5 round-1 controller findOrFail fixes and is a known scanner gap. The brief's "no expected delta" was slightly off-by-one in direction; the actual delta is +1, fully explained by Finding 3. NOT a fix-quality concern, just an accuracy note for the orchestrator's gate snapshot logs.

### NOTE — Discarded pre-load is structurally fine

The fix calls `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id)` and discards the result. Could equivalently call the service with `$member->id` instead of `$id`, but since both reference the same UUID (the tenant predicate proves the row exists in tenant A; the service's unscoped `find($id)` will resolve the same row by primary key), the two are semantically identical. The current form matches the canonical pattern from the round-1 fixes (`show`, `update` discard the pre-load when it's not needed downstream; `enrollments`, `transactions`, `adjust` retain `$member` because they use it for follow-up queries). NOT a finding.

## Verification commands

- Test suite: `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` → **OK (14 tests, 44 assertions)**.
- Architecture gates (sweep-progress): pre-round-2 Gate A=80, Gate B=80 → post-round-2 Gate A=80, **Gate B=81** (+1). Delta explained by Finding 3 from round-1 (scanner-blind to `Model::where('tenant_id', ...)->findOrFail()` chain pattern).
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` → **[OK] No errors**.
- Pint: `./vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` → **{"result":"pass"}**.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` → **verified 792 event(s) across 268 callsite(s); 0 problem(s)**.
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` → **EMPTY**.
- Cross-cluster regression: `vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php` → **OK (157 tests, 555 assertions, 15 PHPUnit deprecations)**.
- Test-pin honesty (revert + rerun): pre-fix only `test_enroll_rejects_cross_tenant_member_id` FAILS expecting 404 / receiving 201; round-1 13/13 still PASS. Post-restore: 14/14 PASS.

## Confidence

High. The round-2 fix is a textbook one-line tenant pre-load that mirrors the canonical pattern from the round-1 fixes; the new test pins exactly the production surface the fix patches; the test-pin revert proves cross-tenant 201 became 404 with the cross-tenant `loyalty_enrollments` row creation suppressed. Defer audit findings A/B/C are accurately described and the deferral classifications are defensible per the kickoff brief — the closest call (Finding C polymorphic) is flagged as NICE-TO-HAVE rather than escalated, which gives the orchestrator the option to bundle if they prefer cluster-cohesion. The cluster is ready to flip to `fixed`.
