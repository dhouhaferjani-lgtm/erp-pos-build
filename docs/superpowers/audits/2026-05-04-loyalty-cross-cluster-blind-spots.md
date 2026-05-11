# Loyalty cross-cluster blind spots (Codex round-1 Findings 2-4 — DEFERRED)

Audit date: 2026-05-04
Reporter: Codex (round-1 second-layer review of api.loyalty cluster)
Triage: Claude Opus 4.7 (orchestrator)
Status: DEFERRED — out of api.loyalty scope per kickoff brief; tracked here for follow-up sweeps

## Context

The api.loyalty cluster closed 10 inventoried callsites + 1 hostile-grep blind spot (CreateProgramRequest::company_ids). During Codex's round-1 second-layer review, three additional surfaces were flagged that were absent from the inventory and span surfaces beyond the api.loyalty cluster's natural boundary (LoyaltyMemberController findOrFail + FormRequest validators on Loyalty resources).

The kickoff brief explicitly states: "If you find a cross-cluster blind spot, document and defer." Each finding below is appropriately deferred per that directive, BUT each is also a real security gap that must be closed by a future cluster sweep.

Codex round-1 Finding 1 (LoyaltyMemberController::enroll route param) was NOT deferred — it is the same controller and same surface as the 5 inventoried findOrFails (show / update / enrollments / transactions / adjust); the inventory missed it. That finding was applied in-cluster as a round-2 remediation commit. The three findings tracked here genuinely span different surfaces.

## Finding A — LoyaltyProgramController service-tier reachable findById (Codex round-1 Finding 2)

**Severity**: CRITICAL (real cross-tenant read/write/delete reachable from public HTTP routes)

**Surface**: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyProgramController.php` lines 42-114; `apps/api/app/Modules/Loyalty/Application/Services/ProgramManagementService.php` lines 76, 128, 168, 203, 238; `apps/api/app/Modules/Loyalty/Infrastructure/Repositories/EloquentLoyaltyProgramRepository.php` line 22

**Issue**: `LoyaltyProgramController::{show, update, destroy, activate, deactivate}` pass the route id directly to `ProgramManagementService`, which resolves programs through `EloquentLoyaltyProgramRepository::findById()` — an unscoped `LoyaltyProgram::find($id)`. There is no post-load tenant comparison. A tenant-A user with `loyalty.view` can read tenant-B programs; a tenant-A user with `loyalty.manage` can patch, activate, deactivate, or delete tenant-B programs.

This is the public-route reachability behind Opus's generic Infrastructure-tier defer (round-1 Finding 2): not just an Infrastructure concern — controllers expose the unscoped reads to attackers.

**Recommended fix**: introduce a tenant-scoped `LoyaltyProgramService::findByIdForTenant(string $id, string $tenantId)` (or pre-load the program in the controller via `LoyaltyProgram::where('tenant_id', $tenantId)->findOrFail($id)` before delegating). Mirror the LoyaltyMemberController fix pattern in this cluster.

**Future cluster**: this should belong to a `api.loyalty-program-controller` (or `api.loyalty.controllers`) follow-up cluster. Track in the inventory with a manual stub if the scanner doesn't pick up the service-tier reachability.

## Finding B — POS loyalty service endpoints accept unscoped enrollment/reward ids (Codex round-1 Finding 3)

**Severity**: CRITICAL (POS-surface attack vector — same module, but POS-blocked cluster)

**Surface**: `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyPOSController.php` lines 80-99 (previewEarning), ~174-200 (earn), 149-158 (redeem); `apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php` line 168; `apps/api/app/Modules/Loyalty/Application/Services/RedemptionProcessingService.php` lines 47, 53; backed by unscoped repositories (`EloquentEnrollmentRepository::findById`, `EloquentRewardRepository::findById`)

**Issue**: `LoyaltyPOSController::previewEarning` passes request `enrollment_id` directly to `EarningProcessingService::previewEarning()`, which calls unscoped `Enrollment::find()` via `EloquentEnrollmentRepository::findById()`. `LoyaltyPOSController::earn` follows the same path and can write transactions/balance changes to a foreign enrollment. `LoyaltyPOSController::redeem` passes raw `enrollment_id` and `reward_id` into `RedemptionProcessingService::redeemReward()`, which resolves both through unscoped repositories.

The sibling `rewards/{enrollmentId}` endpoint is correctly tenant-anchored with `whereHas('member', tenant_id = ?)` — which makes the unscoped service endpoints stand out as a real inconsistency.

**Recommended fix**: scope by tenant in the FormRequest validator (e.g., `Rule::exists('loyalty_enrollments', 'id')->whereIn('member_id', subquery LoyaltyMember.id WHERE tenant_id)`) and/or pre-load the entity tenant-scoped in the controller before delegating to the service.

**Future cluster**: this surface belongs to `api.pos-stabilization` (POS-blocked cluster) per the master plan. Out of scope for this session entirely (POS-blocked). The fix should land alongside other POS sweep work after the POS orchestrator branch merges.

## Finding C — CreateMemberRequest polymorphic loyaltyable_id unscoped (Codex round-1 Finding 4)

**Severity**: IMPORTANT (cross-tenant FK write defect on the same FormRequest as api.loyalty.004)

**Surface**: `apps/api/app/Modules/Loyalty/Presentation/Requests/CreateMemberRequest.php` lines 56-57

**Issue**: The cluster fix hardened `customer_id` (api.loyalty.004) but `loyaltyable_type` and `loyaltyable_id` remain only `in:contact,partner` plus `uuid` — no exists rule, no tenant scope. Both `contacts` and `partners` carry tenant/company ownership. `LoyaltyMemberController::store` persists the validated polymorphic id while setting the member's `tenant_id` from `CompanyContext`. Tenant A can therefore create a tenant-A loyalty member that points at a tenant-B contact or partner through the polymorphic fields.

The bare-exists scanner did not flag this because there is no `exists` rule at all (just `'in:contact,partner'` + `'uuid'`). The polymorphic-typed FK is genuinely a different validator pattern than the inventoried bare-exists callsites.

**Recommended fix**: add a custom polymorphic validation rule (or a `withValidator()` after-callback) that resolves the morph table and checks tenant scoping by morph_type. Sketch:

```php
'loyaltyable_id' => [
    'nullable',
    'uuid',
    function ($attribute, $value, $fail) {
        $morphType = $this->input('loyaltyable_type');
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        if ($morphType === 'partner') {
            $exists = DB::table('partners')
                ->where('id', $value)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->exists();
        } elseif ($morphType === 'contact') {
            $exists = DB::table('contacts')
                ->where('id', $value)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->exists();
        } else {
            $exists = false;
        }

        if (! $exists) {
            $fail("The selected {$morphType} does not exist in your tenant.");
        }
    },
],
```

**Future cluster**: belongs to a "polymorphic FK validators" sweep cluster (likely `api.polymorphic-validators` or similar). The same pattern applies to other morph-typed FKs across the codebase (Document.morphable, Audit.aggregate, etc.). Worth a dedicated sweep with its own scanner pass.

## Resolution

Findings A, B, C are deferred per the kickoff brief. The api.loyalty cluster closes with:
- All 10 inventoried callsites + 1 hostile-grep blind spot (CreateProgramRequest) fixed.
- Codex round-1 Finding 1 (LoyaltyMemberController::enroll) applied in-cluster as a round-2 remediation (same controller, same surface as inventoried findOrFails).
- Codex round-2 Finding 1 (LoyaltyMemberController::optOut + reactivate) applied in-cluster as a round-3 remediation (same controller, same route-anchored surface; both methods previously ignored `$memberId` and let tenant-A mutate tenant-B's enrollment state).
- Findings A-C documented here for follow-up cluster sweeps.

The cluster is otherwise sound and ready to advance to `fixed` after the round-3 remediation lands and a third reviewer pass clears it.
