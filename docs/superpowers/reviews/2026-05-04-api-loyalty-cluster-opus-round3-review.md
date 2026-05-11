# Opus adversarial cluster review — api.loyalty (round 3)

Review date: 2026-05-04
Branch tip reviewed: eaf9c3bd
Reviewer: opus (round-3 post-Codex round-2 BLOCK + round-3 remediation)
Owner: codex
Verdict: APPROVE
Commit reviewed: 74ffc022

Note: 74ffc022 is the round-1 fix commit pinned by inventoried callsites api.loyalty.001-010. Round-2 (e8713644) and round-3 (eaf9c3bd) added in-cluster remediations for non-inventoried surfaces (LoyaltyMemberController::enroll / optOut / reactivate). All three commits were reviewed cumulatively; this verdict authorises the inventoried callsites' transition to fixed.

## Verdict

APPROVE

## Round-3 fix verification

The round-3 commit `eaf9c3bd` is a single-controller surgical fix touching only `LoyaltyMemberController::optOut` (lines 169-188) and `LoyaltyMemberController::reactivate` (lines 193-207). Both methods now mirror the canonical pattern already used by the sibling `transactions` (lines 229-253) and `adjust` (lines 258-298) methods:

```php
$tenantId = $this->companyContext->requireCompany()->tenant_id;
$member = LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($memberId);
$enrollment = Enrollment::where('id', $enrollmentId)
    ->where('member_id', $member->id)
    ->firstOrFail();

$this->enrollmentService->optOut($enrollment->id); // or reactivate(...)
```

Honest checklist:
- `$tenantId` is resolved via `$this->companyContext->requireCompany()->tenant_id` — not via `$request->user()`, not via `app()`. `CompanyContext` is already constructor-injected at line 32, no DI plumbing changes.
- Both methods pre-load `LoyaltyMember` tenant-scoped via `where('tenant_id', $tenantId)->findOrFail($memberId)` — fails with `ModelNotFoundException` (→ 404) before any service call when memberId is foreign.
- Both methods chain enrollment resolution on `$member->id` (not on raw `$enrollmentId` alone), so a tenant-A request bearing tenant-A's memberA + tenant-B's enrollmentB id fails the second `firstOrFail` (member_id mismatch) and 404s before the service runs.
- The service call passes `$enrollment->id` (semantically identical to `$enrollmentId` since the lookup matched on `id`, but reads more naturally — Codex round-2 noted the equivalent observation about the round-2 enroll fix discarding `$member`). This is cleaner than the round-2 enroll pattern because round-3 actually consumes the resolved entity.
- The pattern matches `transactions` (lines 234-239) and `adjust` (lines 263-268) byte-for-byte, except for the trailing service delegation. Consistency across all four route-anchored member+enrollment controller methods is now uniform.

**Test-pin honesty check (REQUIRED, executed):**

I reverted ONLY the optOut + reactivate controller diff back to the pre-`eaf9c3bd` form (preserving the new tests, preserving every prior fix on the branch including round-1 + round-2 enroll), then re-ran `vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`:

```
...........FF...                                                  16 / 16 (100%)
There were 2 failures:

1) test_opt_out_rejects_cross_tenant_enrollment
Expected response status code [404] but received 200.
LoyaltyTenantIsolationTest.php:521

2) test_reactivate_rejects_cross_tenant_enrollment
Expected response status code [404] but received 200.
LoyaltyTenantIsolationTest.php:546

Tests: 16, Assertions: 46, Failures: 2.
```

Exactly the expected pin behavior:
- The 14 round-1 + round-2 tests still passed — confirming the round-3 controller diff is independent of any earlier fix.
- The 2 round-3 tests fail with HTTP 200 instead of 404 — exactly the response Codex round-2 reported for the exploit (`POST /api/v1/loyalty/members/{memberA}/enrollments/{enrollmentB}/opt-out` and `.../reactivate` returning 200 success on cross-tenant input).
- HTTP 200 is the smoking gun: the pre-fix path called `$this->enrollmentService->optOut($enrollmentId)` directly, which delegates through `MemberEnrollmentService::optOut` → `EloquentEnrollmentRepository::findById` → bare `Enrollment::find($id)`. A 200 means the enrollment WAS resolved (cross-tenant) and mutated to `OptedOut` (or `Active` for reactivate); had it not been resolved, the service would have thrown and the request would have 5xx'd. This matches Codex's reported exploit signature exactly.
- Restored the fix; re-ran: `OK (16 tests, 50 assertions)`.

The discarded post-condition `assertSame(EnrollmentStatus::Active, ...)` (after `assertStatus(404)`) is unreached on the pre-fix run because PHPUnit short-circuits at the first failed assertion. That's fine — the 200/404 mismatch is sufficient to prove the exploit signature; the post-condition is an extra belt-and-braces guard against passing-for-the-wrong-reason on a future regression.

## LoyaltyMemberController exhaustiveness audit

Walked every method in `LoyaltyMemberController` (apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php) against the route registry (apps/api/app/Modules/Loyalty/Presentation/routes.php lines 204-247). All 11 methods are accounted for in 11 route registrations:

| Method | Route | Route param(s) | Scoping status |
|---|---|---|---|
| `index` | `GET /loyalty/members` | none | **list-query** — `LoyaltyMember::where('tenant_id', $tenantId)` at line 45. SCOPED. |
| `lookupByPhone` | `POST /loyalty/members/lookup` | none | **lookup** — `LoyaltyMember::where('tenant_id', $tenantId)->where('phone', ...)` at lines 312-315. SCOPED. |
| `show` | `GET /loyalty/members/{id}` | `$id` | api.loyalty.006 — pre-load via tenant-scoped `findOrFail` at line 87-89. SCOPED. |
| `store` | `POST /loyalty/members` | none | tenant_id injected from `CompanyContext` at line 102-105. SCOPED. |
| `update` | `PATCH /loyalty/members/{id}` | `$id` | api.loyalty.007 — tenant-scoped `findOrFail` at line 124. SCOPED. |
| `enrollments` | `GET /loyalty/members/{id}/enrollments` | `$id` | api.loyalty.008 — tenant-scoped `findOrFail` at line 217-219. SCOPED. |
| `enroll` | `POST /loyalty/members/{id}/enroll` | `$id` | round-2 fix — tenant-scoped `findOrFail` at line 149-150. SCOPED. |
| `optOut` | `POST /loyalty/members/{memberId}/enrollments/{enrollmentId}/opt-out` | `$memberId, $enrollmentId` | **round-3 fix** — tenant-scoped member + enrollment chained at lines 177-181. SCOPED. |
| `reactivate` | `POST /loyalty/members/{memberId}/enrollments/{enrollmentId}/reactivate` | `$memberId, $enrollmentId` | **round-3 fix** — tenant-scoped member + enrollment chained at lines 196-200. SCOPED. |
| `transactions` | `GET /loyalty/members/{memberId}/enrollments/{enrollmentId}/transactions` | `$memberId, $enrollmentId` | api.loyalty.009 — tenant-scoped member + enrollment chained at lines 234-239. SCOPED. |
| `adjust` | `POST /loyalty/members/{memberId}/enrollments/{enrollmentId}/adjust` | `$memberId, $enrollmentId` | api.loyalty.010 — tenant-scoped member + enrollment chained at lines 263-268. SCOPED. |

**No remaining LoyaltyMemberController route-param method is unscoped.** The controller has no other public methods. The 11 methods × 11 routes mapping is 1:1 with no orphans on either side. Round-3 closes the round-2 BLOCK exhaustively for this controller.

## Sibling-controller audit (LoyaltyProgramController, RewardController, TierController, EarningRuleController, StampCardController, LoyaltyPOSController)

### LoyaltyProgramController — Finding A enumeration

Route registry: 9 routes (`index`, `active`, `show`, `store`, `update`, `destroy`, `activate`, `deactivate`, plus nested earning-rules/rewards/tiers/stamp-cards under `{programId}/...` routed to other controllers). The 5 mutating/single-id methods Finding A enumerates (`show, update, destroy, activate, deactivate`) all match the actual controller surface. `index`, `active`, `store` are correctly scoped via `CompanyContext` (lines 27-37, 54-69, 125-135). Finding A enumeration is **exhaustive** for this controller.

### RewardController, TierController, EarningRuleController, StampCardController — NOT in any defer finding

These four controllers each follow the same pattern:
- A private `validateProgramAccess(string $programId)` method (RewardController:194, TierController:140, EarningRuleController:194, StampCardController:140) that resolves the parent program via `LoyaltyProgramRepositoryInterface::findById` and `abort(403)` if the program's `tenant_id !== current tenant_id`.
- Every public method (show, update, destroy, activate, deactivate, index, store) calls `validateProgramAccess` either with the route-supplied `$programId` (for nested routes) or with `$entity->program_id` after a bare-find on the entity.

For `show`/`update`/`destroy`/`activate`/`deactivate` (which take a child id, not a programId), the flow is: bare-find child → read `$child->program_id` → `validateProgramAccess($child->program_id)` → 403 if cross-tenant. This is **technically scoped** because the parent program tenant check rejects any child whose program belongs to another tenant. The bare-find on the child itself is OK because the child's `program_id` is the linkage to tenant; foreign children resolve to foreign programs and 403 out.

**Caveat / observation (not a finding):** this pattern depends on `findByProgram` and `findById` for the child being loaded in full so that `$child->program_id` is reliably present. It also depends on `LoyaltyProgramRepositoryInterface::findById` returning a non-null program with a `tenant_id` attribute. Both hold in current code. It is a slightly more fragile pattern than the LoyaltyMemberController canonical `Model::where('tenant_id', $tenantId)->findOrFail($id)` because it relies on a separate validation step rather than refusing to load foreign rows. But it is functionally tenant-isolated.

These four controllers are not in any defer finding because they are correctly scoped via `validateProgramAccess`.

### LoyaltyPOSController — Finding B enumeration

Route registry: 5 routes (`memberLookup`, `previewEarning`, `rewards`, `redeem`, `earn`).
- `memberLookup` (lines 40-73) — list-query scoped via `where('tenant_id', $company->tenant_id)`. SCOPED.
- `rewards` (lines 117-142) — scoped via `whereHas('member', tenant_id = ?)` at line 125. SCOPED.
- `previewEarning`, `redeem`, `earn` — UNSCOPED, accept request enrollment_id/reward_id directly into service. **All three are in Finding B.**

Finding B enumeration is **exhaustive** for this controller.

## New round-3 findings

**None at CRITICAL or IMPORTANT severity.**

Minor observations (not actionable, captured for posterity):

- **Code-style consistency.** The round-2 enroll fix discards the pre-loaded `$member` and passes raw `$id` to `enrollmentService->enroll`. The round-3 optOut/reactivate fix explicitly captures `$member` and passes `$enrollment->id`. Both are functionally correct, but they read differently. Codex round-2 noted the same observation about the enroll fix. Not blocking — the canonical "where(tenant_id)->findOrFail" pre-load is the load-bearing safety check in both. A future tidy pass could harmonize them. (Not a defect; not requesting a change.)

- **Defer audit Finding B line range nit.** Confirmed fixed at line 34 of `docs/superpowers/audits/2026-05-04-loyalty-cross-cluster-blind-spots.md`: `~174-200 (earn)` matches the actual `LoyaltyPOSController::earn` body (lines 174-210 — public method body proper is 174-210, validator+service call core is 176-201). The `~` qualifier is appropriate. ACCURATE.

- **Resolution section bullet list.** Lines 92-96 now correctly enumerate round-1 inventory closure + round-2 enroll fix + round-3 optOut/reactivate fix + Findings A-C deferred. Self-consistent.

## Verification commands

- Test suite (`vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`): **OK, 16 tests, 50 assertions.**
- Architecture gates (`vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`):
  - Gate A — unscoped exists rules in Presentation tier: **77** (no delta from round-2; expected — round-3 only touched controller findOrFail bodies, not exists rules).
  - Gate B — unscoped find()/findOrFail() on guarded models: **79** (no delta from round-2; expected — Gate B scanner is blind to the canonical `Model::where('tenant_id', $tenantId)->findOrFail($id)` pattern per round-1 Finding 3).
- PHPStan (`./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`): **[OK] No errors.**
- Pint (`./vendor/bin/pint --test app/Modules/Loyalty tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php`): **{"result":"pass"}.**
- verify-history (`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`): **820 events / 268 callsites; 0 problems.**
- POS surface diff (`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`): **empty.**
- Cross-cluster regression (`vendor/bin/phpunit tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php`): **OK, 159 tests / 561 assertions** (15 PHPUnit deprecation notices — pre-existing, unrelated to round-3).

## Confidence

High. The round-3 fix exhaustively closes Codex round-2's BLOCK; the test-pin honesty check confirms the new tests fail pre-fix with the exact 200/404 exploit signature Codex reported, and pass post-fix with all earlier fixes intact. The LoyaltyMemberController exhaustiveness sweep proves no other route-param method on this controller is still unscoped (11 methods × 11 routes = 1:1 with all 11 methods now demonstrably tenant-scoped or list-/lookup-anchored). The defer audit's Finding A and Finding B enumerations match the actual controller surfaces, with the line-range nit fix verified accurate. The cluster is ready to advance to `fixed`.
