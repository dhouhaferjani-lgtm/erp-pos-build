# Codex second-layer review — api.workshop cluster (round 1)

Review date: 2026-05-05
Branch tip reviewed: HEAD at session-end
Reviewer: codex (round-1 second-layer review post-Opus APPROVE)
Commit reviewed: 148d2703

Verdict: REQUEST-CHANGES

## Summary

Opus's narrow claims hold: commit 148d2703 correctly closes all 4 inventoried `Partner::find` callsites in WorkOrderCreationService, and the fresh gates pass:

- `vendor/bin/phpunit tests/Feature/Workshop/ tests/Feature/Scheduling/` → OK, 326 tests, 1067 assertions, 7 skipped
- PHPStan targeted command → `[OK] No errors`
- Pint targeted command → `{"result":"pass"}`
- `sweep:inventory:verify-history` → `verified 1089 events / 268 callsites / 0 problems.`
- POS/Voucher diff → empty

## Findings

1. **Severity: REQUEST-CHANGES (NEW finding Opus missed)** — the same appointment-to-WO conversion path still trusts `appointment.vehicle_id`. A tenant-A appointment can carry a tenant-B vehicle UUID because the store request validates `vehicle_id` only as UUID, then conversion passes it through and `WorkOrderAuthoringService` persists it into a tenant-A work order. The DB FK only checks that the vehicle exists. Downstream invoice vehicle-context code can then unscoped-load and snapshot that foreign vehicle.
   - Files:
     - `apps/api/app/Modules/Scheduling/Presentation/Requests/StoreAppointmentRequest.php:31`
     - `apps/api/app/Modules/Scheduling/Presentation/Controllers/AppointmentController.php:123`
     - `apps/api/app/Modules/Scheduling/Application/Services/AppointmentAuthoringService.php:85`
     - `apps/api/app/Modules/Scheduling/Application/Services/AppointmentConversionService.php:63`
     - `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderCreationService.php:73`
     - `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderAuthoringService.php:41`
   - Suggested fix: vehicle_id validator at the appointment-store boundary uses `ScopedExists::tenantAndCompany('vehicles', $tenantId, $companyId)`. That closes the entry path. Alternatively, defense-in-depth: WorkOrderCreationService also asserts `vehicle_id` belongs to the active tenant + company (similar to `assertPartnerInScope`).
   - **Disposition for this cluster**: defer to a follow-up cluster (api.workshop-vehicle or api.scheduling). The api.workshop fix at 148d2703 closes its 4 inventoried Partner-read callsites and is a strict improvement; the vehicle_id path is a sibling gap not covered by the api.workshop callsite inventory.

2. **Honesty check confirmed** — pre-fix rollback (`git checkout 148d2703~1 -- apps/api/app/Modules/Workshop apps/api/app/Modules/Scheduling/Application/Services/AppointmentConversionService.php apps/api/tests/Feature/Workshop apps/api/tests/Feature/Scheduling`) caused the 2 structural-SQL tests to fail with `Unknown named parameter $tenantId`, matching Opus's broader pre-fix failure shape.

## Audit exhaustiveness

- Diff verification: commit 148d2703 modifies the expected 6 files (interface + impl + caller + 3 tests) with byte-identical content described in the brief.
- Pre-fix rollback honesty: 6 expected test errors / failures.
- HEAD-state gates: all green per the listing above.
- Cleanup note: rollback restore hit the sandbox on `.git/index.lock`; `git diff HEAD --` for the 3 touched files showed empty, but the Git index showed staged rollback/counter-diff state. Workspace was correctly cleaned in subsequent steps.

## Confidence

High that the api.workshop cluster's 4 inventoried callsites are correctly closed at 148d2703. The vehicle_id finding is a sibling gap that warrants its own follow-up cluster; recording it here so it doesn't get lost. Recommend cluster owner / orchestrator either:
(a) accept the api.workshop fix as-is, file the vehicle_id finding as api.workshop.005 (or api.workshop-vehicle.001) in the manual-callsites stub, and lock api.workshop;
(b) extend the api.workshop fix to add the vehicle_id ScopedExists / defense-in-depth scope before locking.

This orchestrator's choice (recorded by Claude after reading this verdict): option (a) — defer the vehicle_id finding to a sibling cluster. The api.workshop cluster's 4 inventoried callsites are scoped correctly; the vehicle_id finding belongs to api.scheduling-store-validators (or similar) where the appointment validators live.

Note: Codex sandbox could not write this verdict file directly (apps/api-only write permission). Verdict text written by orchestrator (Claude) verbatim from Codex's `--output-last-message` summary, then enriched with the orchestrator's disposition decision.
