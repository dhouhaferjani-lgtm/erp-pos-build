# Opus adversarial review — api.workshop cluster (round 2)

Review date: 2026-05-04
Branch tip reviewed: 6a534aae (round-2 minor-edit commit) — base fix at 148d2703
Reviewer: opus → claude (cluster owner is codex; reviewer differs)

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 148d2703
Round-2 minor-edit commit: 6a534aae

## Round-1 Codex finding disposition

Codex round-1 second-layer review at `docs/superpowers/reviews/2026-05-04-api-workshop-cluster-codex-review.md` (verdict: REQUEST-CHANGES) raised one NEW finding: **`appointment.vehicle_id` is unscoped at the appointment-store boundary**. A tenant-A appointment can carry a tenant-B vehicle UUID because `StoreAppointmentRequest` validates `vehicle_id` only as `['nullable','uuid']` (line 33), then the conversion path threads that foreign UUID through `AppointmentConversionService::convertToWorkOrder` (line 63) → `WorkOrderCreationService::createFromAppointment(vehicleId: $vehicleId, …)` → `WorkOrderAuthoringService::create(['vehicle_id' => $command->vehicle_id])`. The DB FK only checks vehicle existence, not tenant ownership. Downstream invoice vehicle-context code can then unscoped-load and snapshot that foreign vehicle.

Codex's recommended disposition was option (a): defer the validator-tier fix to a sibling cluster (`api.scheduling-store-validators` / `api.workshop-vehicle`) and lock api.workshop's 4 inventoried Partner-read callsites as-is.

**My round-2 disposition: option (a) is correct in principle (root cause IS at the appointment-store validator boundary, not in WorkOrderCreationService), but the api.workshop fix's narrative is asymmetric without a defense-in-depth mirror.** The cluster has already established the precedent that defense-in-depth scope guards belong in `WorkOrderCreationService::createFromAppointment` (the existing `assertPartnerInScope` does exactly this for the partner_id parameter coming from the same trust source — `appointment.customer_partner_id`). By the same logic — and with the same model shape (`Vehicle` has both `tenant_id` and `company_id` columns, identical to `Partner`) — a parallel `assertVehicleInScope` is the honest mirror. Cost is small (~10 lines + 2 tests) and it would close the exploit at this layer regardless of whether/when api.scheduling-store-validators ships its fix. This also matches Codex's own alternative phrasing ("Alternatively, defense-in-depth: WorkOrderCreationService also asserts vehicle_id belongs to the active tenant + company (similar to assertPartnerInScope).").

I therefore applied the minor edit at 6a534aae:
- `WorkOrderCreationService::createFromAppointment` now invokes `$this->assertVehicleInScope($vehicleId, $tenantId, $companyId)` immediately after the existing `assertPartnerInScope` call.
- New private method `assertVehicleInScope($vehicleId, $tenantId, $companyId)` runs `Vehicle::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereKey($vehicleId)->exists()` and throws `RuntimeException` with message ending in "refusing cross-tenant work order creation." on miss — byte-identical shape to the partner guard.
- Two new tests in `WorkshopTenantIsolationTest`: `test_create_from_appointment_refuses_cross_tenant_vehicle` (cross-tenant vehicle UUID rejected) and `test_create_from_appointment_vehicle_guard_query_includes_tenant_and_company_predicates` (structural-SQL-log invariant pinning both `tenant_id` AND `company_id` literals on the vehicle EXISTS guard).
- Test-suite totals: 16 tests / 42 assertions on the targeted 3-file run (was 14/37 pre-edit); 328 tests / 1072 assertions on the broader Workshop+Scheduling regression (was 326/1067).

The validator-tier root-cause fix at `StoreAppointmentRequest:33` (and by extension the appointment-update path) is genuinely OUT-OF-SCOPE for api.workshop and SHOULD be tracked as a sibling cluster finding (api.scheduling-store-validators or api.workshop-vehicle). Recording it in the manual-callsites stub for the orchestrator to triage is the right next move; this round-2 review does not consume that follow-up.

## Summary

The api.workshop cluster's 4 inventoried `Partner::find` callsites (api.workshop.001/002/003/004) at `WorkOrderCreationService.php` lines 124/134/144/162 are correctly closed at fix-commit 148d2703. Round-1 Opus APPROVE was correct on the inventoried scope. Round-1 Codex REQUEST-CHANGES correctly surfaced the vehicle_id sibling gap; the deferral disposition (option a) is appropriate, with the addition of a defense-in-depth `assertVehicleInScope` mirror at 6a534aae that closes the propagation surface inside this cluster regardless of when the validator-tier fix lands. Verification gates at HEAD (6a534aae):

- `vendor/bin/phpunit tests/Feature/Workshop/WorkOrder/WorkshopTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/CreateFromAppointmentTest.php tests/Feature/Scheduling/AppointmentConversionServiceTest.php` → **OK (16 tests, 42 assertions)**
- `vendor/bin/phpunit tests/Feature/Workshop/ tests/Feature/Scheduling/` → **OK, 328 tests, 1072 assertions, 7 skipped**
- `vendor/bin/phpstan analyse app/Modules/Workshop/WorkOrder app/Modules/Scheduling/Application/Services/AppointmentConversionService.php tests/Feature/Workshop/WorkOrder tests/Feature/Scheduling/AppointmentConversionServiceTest.php` → **[OK] No errors**
- `vendor/bin/pint --test app/Modules/Workshop/WorkOrder …` → **{"result":"pass"}**
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` → **`verified 1089 event(s) across 268 callsite(s); 0 problem(s).`** (re-verified before the minor-edit commit; no inventory mutation in this round)

## Findings

1. **Severity: NONE-NEW (round-1 Codex finding addressed in-cluster as defense-in-depth)** — `appointment.vehicle_id` exploit chain is structurally closed at WorkOrderCreationService by the new `assertVehicleInScope` guard at commit 6a534aae. The validator-tier root-cause fix at `StoreAppointmentRequest:33` remains an open sibling-cluster item (api.scheduling-store-validators / api.workshop-vehicle) — the orchestrator should add a manual-callsites stub entry under the appropriate sibling cluster so it does not get lost. Suggested entry shape:
   ```yaml
   - cluster_id: api.scheduling-store-validators  # or api.workshop-vehicle if that cluster exists
     slug: store-appointment-vehicle-id-uuid-only
     file: apps/api/app/Modules/Scheduling/Presentation/Requests/StoreAppointmentRequest.php
     line: 33
     symbol: 'App\Modules\Scheduling\Presentation\Requests\StoreAppointmentRequest::rules'
     pattern_type: trusts_uuid_format_only_for_cross_resource_id
     resource: vehicles
     expected_scope: tenant_and_company
     severity: high
     fiscal_path: false
     cross_module: true
     expected_fix: "Replace ['nullable','uuid'] with ScopedExists::tenantAndCompany('vehicles', $tenantId, $companyId) so a foreign vehicle_id is rejected at the appointment-store boundary. The api.workshop cluster has a defense-in-depth assertVehicleInScope guard at 6a534aae but the validator-tier fix is the structural one."
   ```
   Same pattern likely applies to `customer_partner_id` (also `['nullable','uuid']` at line 32) — both should be ScopedExists at the appointment-store boundary. Tracked as sibling-cluster, not blocking api.workshop.

2. **Severity: NICE-TO-HAVE (residual round-1 Opus Finding 1, unchanged)** — `resolveOpenedByUserId($tenantId)` still returns the FIRST tenant-scoped user regardless of company membership. Pre-existing, informational only (the `opened_by_user_id` column is read for display, not for ACLs), out of api.workshop's inventoried scope. The real `convertedByUserId` is already available at `AppointmentConversionService:47` and could be threaded through; this is a one-line interface-extension for a future api.scheduling-side fix. Not blocking.

3. **Severity: OUT-OF-SCOPE referrals (unchanged from round-1)** — the ~17 sibling Workshop reads in `Bundle/`, `Technician/`, `EloquentWorkOrderRepository`, `EloquentWorkOrderSequence`, `BundleExpansionAdapter` are still scanner-blind. Several of them (notably `EloquentWorkOrderRepository::find($id)` reached via WO route param, the three Technician controllers' `where('id', $X)` chains) look exploitable on a hostile-mindset reading. Tracked for a follow-up sweep (api.workshop-bundle / api.workshop-technician / api.workshop-repository) — not this cluster's gate.

## Audit exhaustiveness

- **Re-verification of the 4 inventoried callsites at HEAD (6a534aae)**: all 4 collapse to one `assertPartnerInScope` defensive guard scoped on `(tenant_id, company_id, id)`; the new `assertVehicleInScope` guard sits parallel; `resolveCurrency` remains scoped on `(tenant_id, id)` against `companies`; `resolveOpenedByUserId` remains tenant-only against `users` (correct — `users` table has no `company_id`). All four match the round-1 cluster narrative byte-for-byte.
- **Honesty re-check**: I did NOT need to re-do the pre-fix bug-reproduction (round-1 Opus and round-1 Codex both confirmed it independently with the exact failure shape — `Unknown named parameter $tenantId` × 4 errors + 2 RuntimeException-mismatch failures). The round-2 minor-edit at 6a534aae adds two new tests; both fail at the new `assertVehicleInScope` guard if the guard is reverted (verified by transient code-revert + test-run during edit cycle, then restored).
- **Targeted test-suite at HEAD (6a534aae)**: `vendor/bin/phpunit tests/Feature/Workshop/WorkOrder/WorkshopTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/CreateFromAppointmentTest.php tests/Feature/Scheduling/AppointmentConversionServiceTest.php` → **OK (16 tests, 42 assertions)**. The new vehicle-cross-tenant test follows the partnerB pattern (a tenantB+companyB vehicle factory-created in setUp(), passed as `vehicleId: $this->vehicleB->id` in the cross-tenant test). The new structural-SQL-log invariant test pins both `"tenant_id"` AND `"company_id"` literal substrings on the captured vehicle-EXISTS query, identical to the partner-guard invariant.
- **Broader regression at HEAD (6a534aae)**: `vendor/bin/phpunit tests/Feature/Workshop/ tests/Feature/Scheduling/` → **OK, 328 tests, 1072 assertions, 7 skipped** (was 326/1067 pre-edit). Two added tests, no regressions.
- **PHPStan level 8 on Workshop/WorkOrder + AppointmentConversionService + 3 affected test files** → **[OK] No errors**. The new `use App\Modules\Vehicle\Domain\Vehicle;` import resolves cleanly (Vehicle model is in the existing autoload tree); `Vehicle::query()->…->exists()` typechecks.
- **Pint --test on the same scope** → **{"result":"pass"}**.
- **`assertVehicleInScope` SQL shape**: `Vehicle::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereKey($vehicleId)->exists()` translates (with Vehicle using `SoftDeletes`) to `select exists(select * from "vehicles" where "tenant_id" = ? and "company_id" = ? and "id" = ? and "vehicles"."deleted_at" is null) as "exists"`. Both required predicates present; soft-deleted vehicles correctly excluded. Test pins both literals.
- **Multi-session contention observed during this review**: parallel session reverted my staged Workshop edits via `git stash` indirectly — recovered cleanly via `git stash pop stash@{0}`. Parallel session was working on `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` (Inventory cluster, NOT in my scope). Per brief, I did NOT touch that file. Final commit at 6a534aae stages ONLY the 2 Workshop files (`WorkOrderCreationService.php` + `WorkshopTenantIsolationTest.php`) — confirmed by `git show --stat 6a534aae`.

## Confidence

High confidence that the api.workshop cluster's 4 inventoried Partner-read callsites are correctly closed at 148d2703 AND that the round-1 Codex vehicle_id finding is materially addressed at the api.workshop layer by the defense-in-depth `assertVehicleInScope` guard at 6a534aae. The structural-SQL-log invariant tests on BOTH the partner-existence guard AND the vehicle-existence guard pin `tenant_id` + `company_id` as literal substrings — a future regression that drops one of the predicates would fail loudly. Currency lookup test pins `tenant_id`. Pre-fix bug-reproduction failed all 6 isolation tests (round-1 evidence) plus the 2 new vehicle tests fail at the new guard if reverted.

What I could have missed:
- The validator-tier root-cause fix at `StoreAppointmentRequest:33` is NOT addressed by this round-2 edit — it's deferred to a sibling cluster as agreed (option a). If the orchestrator does NOT add the manual-callsites stub entry for `api.scheduling-store-validators` (or whatever the sibling cluster ends up named), the root-cause fix could get lost. Recording the suggested stub-entry shape in Finding 1 above to mitigate.
- `customer_partner_id` at `StoreAppointmentRequest:32` has the same `['nullable','uuid']` shape as `vehicle_id` and is the same kind of cross-resource validator gap. The api.workshop `assertPartnerInScope` defends against this at the WorkOrderCreationService layer, but the validator-tier root-cause fix should also cover both fields. Out of api.workshop scope; flagging here for sibling-cluster owner.
- I did NOT re-audit the ~17 sibling Workshop reads from round-1 Finding 3 — they remain out of api.workshop scope per brief and should be triaged in a follow-up sweep. Several of them look exploitable on a hostile-mindset reading.
- I did NOT extend the api.workshop callsite inventory to add a `api.workshop.005` entry for the vehicle_id defense-in-depth guard — the guard is a defense-in-depth mirror for a sibling-cluster gap, not a Partner-read callsite, so it should not pollute the api.workshop callsite inventory. The orchestrator may choose to record it as a sibling-cluster manual entry instead.
- The `regression_test` references on api.workshop.001/002/003/004 still point at the original test names (`test_create_from_appointment_refuses_cross_tenant_partner` and `test_create_from_appointment_succeeds_with_in_scope_partner` and `test_create_from_appointment_currency_lookup_query_includes_tenant_predicate`) — those tests still exist and still pass; the round-2 minor-edit added 2 NEW tests for the vehicle guard but did not retire any existing test. Inventory references remain valid.
