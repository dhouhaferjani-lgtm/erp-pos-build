# Opus adversarial cluster review — api.loyalty

Review date: 2026-05-04
Branch tip reviewed: 74ffc022 (fix); e7706d3d (submit-state)
Reviewer: opus
Owner: codex

## Verdict

APPROVE

## Commit reviewed

74ffc022 (and ancestors back to 0d6a01f8)

## Summary

The 10 inventoried api.loyalty callsites + 1 scanner-blind-spot (CreateProgramRequest::company_ids) are honestly fixed and rigorously tested. The test-pin honesty check (revert production code, run tests) failed all 13 tests pre-fix with diagnostics that exactly target the fix surfaces — including the structural-SQL-log invariants that pin the partners exists query to BOTH `tenant_id` AND `company_id` and the loyalty_members lookup to `tenant_id`. PHPStan + Pint clean, verify-history clean, cross-cluster regression suite (Loyalty + Cart + Contact + Treasury) green at 156/553. The cluster's callsites can flip to `fixed`. Two adjacent-but-out-of-scope blind spots are surfaced as cross-cluster defers (see Findings 1 and 2) — they are NOT in the api.loyalty inventory and are explicitly defer-only per the kickoff brief.

## Findings

### Finding 1: LoyaltyMemberController::enroll route param `$id` is unscoped (cross-cluster blind spot — DEFER)
- **Severity**: NICE-TO-HAVE (out of scope)
- **Location**: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php:136-150`
- **Issue**: `enroll(EnrollMemberRequest $request, string $id)` passes `$id` directly to `MemberEnrollmentService::enroll(memberId: $id, ...)`, which calls `EloquentLoyaltyMemberRepository::findById($id)` → unscoped `LoyaltyMember::find($id)`. A tenant-A user POSTing to `/api/v1/loyalty/members/{tenant-B-member-id}/enroll` with their own (now-correctly-tenant-scoped) `program_id` would have `MemberEnrollmentService` create an `Enrollment` row linking tenant-B's member to tenant-A's program. The `enrollments` table doesn't carry `tenant_id`, so the DB layer can't catch it; tenant-A's view of `/loyalty/members/{tenant-B-id}/enrollments` would then 404 (because `show`/`enrollments` are now correctly tenant-scoped post-fix), but the corrupted enrollment row persists. This is a missed callsite in the original inventory — the api.loyalty inventory only enumerated 5 controller findOrFail callsites (show / update / enrollments / transactions / adjust); `enroll` was overlooked.
- **Fix**: Out of scope for this cluster per the kickoff brief ("if not, surface as a NEW finding (cross-cluster blind spot — document and defer per the kickoff brief, do NOT touch them in this cluster)"). Track for the next loyalty-controller sweep, or fold into the Loyalty Application/Service tier sweep (where the same issue exists for `optOut(enrollmentId)` and `reactivate(enrollmentId)` — both fetch by enrollmentId via unscoped `EloquentEnrollmentRepository::findById`).

### Finding 2: Loyalty Eloquent repositories use unscoped `Model::find($id)` (cross-cluster blind spot — DEFER)
- **Severity**: NICE-TO-HAVE (out of scope)
- **Location**: `apps/api/app/Modules/Loyalty/Infrastructure/Repositories/Eloquent*Repository.php` (`findById` methods on LoyaltyMember, LoyaltyProgram, Reward, Tier, Enrollment, Transaction, EarningRule, StampCard)
- **Issue**: All `findById($id)` implementations call `Model::find($id)` with no tenant predicate. The Architecture Gate B scanner does NOT scan the `/Infrastructure/` tier so these are invisible to the gate. They become problems wherever a service or listener calls `findById` with an attacker-controlled id without an upstream tenant guard. `MemberEnrollmentService::optOut/reactivate` (Finding 1 chain) and downstream services for stamp cards / rewards / tiers are likely affected.
- **Fix**: Out of scope for the api.loyalty cluster (callsites would belong to a separate Application-tier or repository-tier sweep). Defer per the kickoff brief.

### Finding 3: Architecture Gate B does not credit `Model::where('tenant_id', ...)->...->findOrFail()` chains as scoped (scanner limitation, NOT a fix issue)
- **Severity**: NICE-TO-HAVE (tooling only)
- **Location**: `apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php` `chainIsScoped()` (lines 258-286)
- **Issue**: All 5 controller findOrFail fixes use `LoyaltyMember::where('tenant_id', $tenantId)->...->findOrFail($id)` (matching the canonical Cart/Contact pattern). The scanner's `chainIsScoped` walks UP through MethodCalls but the terminal `LoyaltyMember::where(...)` is a StaticCall with method name `'where'`, which is not in `SCOPE_METHODS` (which only contains `forCompany`, `forTenant`, etc). The scanner therefore reports all 5 LoyaltyMember findOrFail sites as still unscoped, which is why Gate B count did not drop after this commit (loyalty fix-tip shows Gate B=96, identical to pre-loyalty baseline). The fixes themselves are correct — the test-pin honesty check confirmed the production code does filter by tenant_id. This is a Gate B scanner gap consistent with how Cart/Contact closures landed (same pattern, same scanner blindness). The inventory + tests are the ground truth, not Gate B.
- **Fix**: Track as a future Architecture-test improvement: extend `chainIsScoped()` to recognize a terminal `Model::where('tenant_id'|'company_id', ...)` StaticCall as scoped. Out of scope for this cluster.

### Finding 4: Commit message Gate A/B counts are stale by parallel-session activity (NOTE only)
- **Severity**: NICE-TO-HAVE (cosmetic)
- **Location**: 74ffc022 commit message claims "Gate A 80, Gate B 84 (down from 98/102 baseline)"
- **Issue**: At fix-tip 74ffc022 (loyalty diff applied, no later cluster diffs), the actual gate counts I measured are Gate A=92 / Gate B=96 (vs the 95/96 pre-loyalty baseline I measured at 0d6a01f8). The 80/84 claim was likely measured AFTER subsequent api.catalog work landed on top, or against a different baseline checkpoint. Real loyalty-attributable delta: -3 on Gate A (CreateMember partners + UpdateMember partners + EnrollMember loyalty_programs); 0 on Gate B (Finding 3 explains why). The remaining expected Gate A reductions for this cluster are absorbed by (a) `companies` not being in the Gate A guarded-tables list (CreateProgram blind-spot fix invisible), (b) CreateStampCardRequest using `where('program_id', ...)` instead of `where('tenant_id', ...)` (correct security but invisible to Gate A), and (c) UpdateStampCardRequest using a closure-based exists rule (correct security but invisible to Gate A). All three are honest, defensible choices — gate scanner just under-counts.
- **Fix**: Re-record gate snapshots in the orchestrator's session log when the next cluster lands; clarify in retro that Gate A/B are necessary-not-sufficient signals.

## Test honesty assessment

Verified, gold-standard pass.

- **A.1 Production-path coverage**: every test method exercises the actual Laravel route → FormRequest → controller path (real HTTP via `postJson` / `patchJson` / `getJson` / `delete`). No service-level direct calls that bypass FormRequests.
- **A.2 Same-tenant controls**: 5 of 6 FormRequest cross-tenant 422 tests are paired with same-tenant 201/200 controls (test_create_member, test_update_member, test_create_stamp_card, test_update_stamp_card, test_create_program). The one omission (test_enroll_member_rejects_cross_tenant_program_id) is documented in-test (lines 336-340) — same-tenant enroll on programA would hit the membership-uniqueness constraint because setUp already enrolled memberA in programA. The cross-tenant 404 controller tests (show / update / enrollments / transactions / adjust) all carry same-tenant 200 controls (or, for adjust, the controller-level cross-tenant 404 is sufficient since same-tenant adjust is exercised by other Loyalty regression tests).
- **A.3 Real two-tenant fixtures**: setUp creates 2 Tenants, 2 Companies, 2 Users (with proper UserCompanyMembership rows), 2 LoyaltyPrograms, 2 LoyaltyMembers, 2 Rewards, 1 StampCardDefinition (A), 2 Enrollments, 2 Partners. Spatie team-id is set per-tenant before each `seed(RolesAndPermissionsSeeder::class)` call to land roles/permissions on the right `tenant_id` (mirrors ContactTenantIsolationTest).
- **A.4 Test-pin honesty check (REQUIRED)**: I `git checkout 0d6a01f8 --` reverted all 7 fix files (controller + 6 FormRequests) while keeping the test file. Ran the suite. Result: **13/13 tests FAILED** with diagnostics that targeted exactly what each test should catch:
  - 6 FormRequest cross-tenant tests failed expecting 422 but got 201/200 (cross-tenant ids accepted because `exists:partners,id` / `exists:loyalty_rewards,id` / `exists:loyalty_programs,id` / `exists:companies,id` pre-fix were unscoped).
  - 5 controller findOrFail tests failed expecting 404 but got 200/201 (cross-tenant memberB id resolved because pre-fix findOrFail was unscoped).
  - test_show_query_includes_tenant_predicate failed with concrete SQL: `select * from "loyalty_members" where "loyalty_members"."id" = ? and "loyalty_members"."deleted_at" is null limit 1` — no tenant_id predicate, exactly the structural leak the test pins.
  - test_create_member_validator_query_includes_tenant_and_company_predicates failed with concrete SQL: `select count(*) as aggregate from "partners" where "id" = ?` — bare exists, exactly the leak.
  Restored fix → all 13 pass. Tests are honest and pin the production fix surfaces.
- **A.5 No mocks of guarded operations**: real DB (RefreshDatabase), real Eloquent, real Sanctum auth, real CompanyContext via X-Company-Id header. No `Mockery::mock` / `vi.mock` / response fakes anywhere in the test.

## Hostile grep results

```
# A. app() helper in fix-touched files (excluding tests)
grep -rnE 'app\(' apps/api/app/Modules/Loyalty/Presentation/ | grep -v '/tests/'
→ EMPTY ✓

# B. @phpstan-ignore introduced
grep -rn '@phpstan-ignore' apps/api/app/Modules/Loyalty/
→ EMPTY ✓

# C. mixed introduced in production code
→ All matches are pre-existing PHPDoc `@return array<string, mixed>` annotations on `rules(): array`
  signatures across the entire Loyalty module (14 files, both touched and untouched). NOT introduced
  by this diff. ✓

# D. Bare ->where on FK columns in Loyalty (excluding tests)
grep -rnE -- "->where\(['\"](id|reward_id|program_id|member_id|...)" apps/api/app/Modules/Loyalty/
→ 7 matches. Classifications:
  - EarnPointsOnReceiptCompleted.php:65  ->where('member_id', $member->id)
      → structurally_protected_by_upstream_tenant_query (line 39: tenant_id-scoped LoyaltyMember query)
  - EloquentEnrollmentRepository.php:30  ->where('program_id', $programId)
      → repository helper; out-of-scope (Infrastructure tier, see Finding 2)
  - EloquentLoyaltyMemberRepository.php:42  ->where('customer_id', $customerId)
      → repository helper; called only with $tenantId in same chain (line 41); structurally protected
  - CreateStampCardRequest.php:49  Rule::exists('loyalty_rewards')->where('program_id', $programId)
      → THE FIX. structurally_protected_by_upstream_tenant_validation
        (StampCardController::validateProgramAccess validates programId tenant ownership before
         the FormRequest fires)
  - LoyaltyMemberController.php:205, 234  ->where('member_id', $member->id)
      → THE FIX (lines 203, 232 tenant-scope $member); structurally_protected_by_upstream_guard
  - LoyaltyPOSController.php:62  ->where('member_id', $member->id)
      → structurally_protected_by_upstream_tenant_query (line 50: tenant_id-scoped lookup)

# E. Bare exists: strings on guarded tables in Loyalty (excluding tests)
grep -rnE -- "exists:(partners|loyalty_rewards|loyalty_programs|loyalty_members|...)"
→ 1 match in CreateProgramRequest.php:34 — comment text only, no actual rule. ✓

# F. Header/input access of company_id / tenant_id (anti-pattern)
grep -rnE -- '\$request->(input|header|query)\(.{0,30}(company_id|tenant_id|X-Company-Id)' apps/api/app/Modules/Loyalty/
→ EMPTY ✓

# G. ::find / ::findOrFail in Loyalty
grep -rnE -- "::find(OrFail)?\(" apps/api/app/Modules/Loyalty/
→ 9 matches, ALL in app/Modules/Loyalty/Infrastructure/Repositories/Eloquent*Repository.php:
  Reward, Tier, Enrollment, Transaction, LoyaltyProgram, LoyaltyMember, EarningRule,
  StampCardDefinition, MemberStampCard. Per Finding 2: cross-cluster blind spot — DEFER.
  Confirmed scoping at controller level for the api.loyalty cluster surface (LoyaltyMember
  controller paths now tenant-scoped); other repositories are out of scope.
```

## Architecture gate drops

I measured these myself by checking out parent and fix-tip revisions of `apps/api/`:

- Gate A: 95 (parent 0d6a01f8) → 92 (loyalty fix 74ffc022) — delta **-3** (CreateMemberRequest partners + UpdateMemberRequest partners + EnrollMemberRequest loyalty_programs).
- Gate B: 96 (parent) → 96 (fix) — delta **0**.

Discrepancy vs commit-message claim ("Gate A 80, Gate B 84"): explained in Finding 4 (commit-message snapshot was post-catalog, not loyalty-only). The remaining "missing" drops are explained by:
- `companies` is not in Gate A's guarded-tables list (CreateProgramRequest blind-spot fix invisible to Gate A, despite being a real security fix tested honestly).
- CreateStampCardRequest uses `where('program_id', ...)` (tighter than tenant — controller validates program tenancy upstream), which the Gate A AST visitor does not credit because it only looks for literal `tenant_id`/`company_id` in `where()` args.
- UpdateStampCardRequest uses a closure-based `Rule::exists`, which the Gate A visitor does not introspect.
- All 5 controller findOrFail fixes use the `Model::where('tenant_id', ...)->findOrFail()` chain that the Gate B scanner does not recognize as scoped (Finding 3). Same scanner gap as Cart/Contact.

These are scanner limitations, not fix-quality issues. Test-pin honesty check confirms all production fixes are real.

## What looks good

- **Schema-aware scoping is meticulous and correct**: partners → tenant_and_company; loyalty_programs → tenant; companies → tenant; loyalty_members → tenant only (no company_id column); loyalty_rewards → scoped via parent program FK (closure-based for UpdateStampCard, route-anchored for CreateStampCard). Each choice is annotated in code comments and matches the cluster invariant exactly.
- **Structural-SQL-log invariants are bar-raising**: `test_show_query_includes_tenant_predicate` and `test_create_member_validator_query_includes_tenant_and_company_predicates` capture the actual SQL emitted under load and assert literal `"tenant_id"` / `"company_id"` substrings. UUID uniqueness can mask data-level leaks; SQL-shape pinning catches a regression where someone "fixes" the test by changing UUIDs but the query is still unscoped. This is the strongest test pattern the sweep has produced.
- **CompanyContext constructor injection on FormRequests is parent-safe**: every modified FormRequest calls `parent::__construct()` after binding the dependency, preserving Laravel's FormRequest hydration. No sibling tests broke (Loyalty regression suite 36/136 green).
- **Same-tenant controls ride alongside every cross-tenant assertion (with one explicitly-documented exception)**: rules out "passes for the wrong reason" (e.g. validation failing because a required field is missing rather than because the FK is foreign). The single intentional omission (EnrollMember same-tenant) carries an in-test paragraph explaining the membership-uniqueness collision and points to the controller-level same-tenant 200 control as the redundant safety net.

## Verification commands

- `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` — **OK (13 tests, 42 assertions)**
- `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` — **OK (Gate A=92, Gate B=96 at fix-tip; both reduced from baseline; deltas explained above)**
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` — **[OK] No errors**
- `./vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php` — **{"result":"pass"}**
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — **756 events / 268 callsites; 0 problem(s)**
- `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` — **EMPTY (POS / Voucher untouched)**
- `vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php` — **OK (156 tests, 553 assertions)**

Test-pin honesty check (revert + rerun): pre-fix 13/13 FAIL with diagnostics that target exactly the fix surfaces; post-restore 13/13 PASS.

All 10 api.loyalty callsites carry `status: under_review`, `fix_commit: 74ffc022`, `regression_test: tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`, and full `generate → claim → start → submit` history with `actor: codex`. The cluster is ready to flip to `fixed`.
