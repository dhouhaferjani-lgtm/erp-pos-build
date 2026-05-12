# api.workshop Task 4 Closure — Claude Review

Commit reviewed: 01574841
Plan: docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md (Task 4)
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer: codex
Date: 2026-05-12

## Scope

Task 4 of the non-TanStack closure plan: close `api.workshop.005-.010` real code defects through additive repository scoping, event-V2 migration for cross-module listeners, Technician body-validation hardening, and the full HTTP tenant-isolation matrix test.

## Verifications performed

| Check | Result |
|---|---|
| Workshop tenant-isolation tests at HEAD (`vendor/bin/phpunit tests/Feature/Workshop/WorkOrder/WorkshopHttpTenantIsolationMatrixTest.php tests/Unit/Workshop/WorkOrder/EventSignaturesTest.php tests/Feature/Vehicle/WriteMileageReadingFromWorkOrderCompletedTest.php tests/Feature/Workshop/Technician/TechnicianTimeEntryControllerTest.php`) | 32 tests, 108 assertions, **OK** |
| `sweep:inventory:verify-history` | 6313 events / 1208 callsites / **0 problems** |
| `sweep:inventory:status --drift` | yaml_says_fixed_code_unsafe=**0**, code_safe_yaml_pending=**0**, unmapped_in_scanner_output=**0** (clean) |
| Endpoint matrix vs revised plan (Bundle 8 + WorkOrder 14) | All rows exercised at `assertSame(404, …)`; Technician body 422 paths covered separately |
| Manual stub additions for newly-discovered defects | `api.workshop.008` (`EloquentBundleRepository::findById`), `api.workshop.009` (`findWithComponentsAndApplicabilities`), `api.workshop.010` (Technician body work_order_id) all present |

## Plan compliance per section

### WorkOrder repository scoped finders (Task 4 Step 2)

`WorkOrderRepositoryInterface` keeps `findById(string)` and `findForUpdate(string)` with `@deprecated` PHPDoc; new `findByIdForScope(string $tenantId, string $companyId, string $id)` and `findForUpdateForScope(…)` methods added. `EloquentWorkOrderRepository` implementation uses `WorkOrder::query()->where('tenant_id', …)->where('company_id', …)->find($id)` for read and `…->whereKey($id)->lockForUpdate()->first()` for the update path. **ADDITIVE commitment honored — no in-place signature change.**

### Bundle repository scoped finders (Task 4 Step 2)

`BundleRepositoryInterface` mirrors the additive pattern: `findById(string)` and `findWithComponentsAndApplicabilities(string)` retain `@deprecated`; new `findByIdForScope` and `findWithComponentsAndApplicabilitiesForScope` added with full tenant+company predicates. This addresses the newly-discovered defects flagged during the original caller audit (`api.workshop.008` and `.009`).

### Vehicle listener event-anchored migration (Task 4 Step 3a, Option A)

`WorkOrderCompletedV2` created with `tenant_id` + `company_id` + the original V1 fields. `WriteMileageReadingFromWorkOrderCompleted::handle()` now accepts `WorkOrderCompletedV2` and calls `findByIdForScope($event->tenant_id, $event->company_id, $event->work_order_id)`. `WorkOrderTransitionService` dispatches V2 only. The `WorkOrderCompleted` (V1) class is preserved for serialization compatibility per AutoERP "Events are Immutable Forever" rule.

### Technician body work_order_id (Task 4 Step 3b)

`StoreTimeEntryRequest` and `UpdateTimeEntryRequest` both injected with `CompanyContext` and add `ScopedExists::tenantAndCompany('workshop_work_orders', $tenantId, $companyId)` to the `work_order_id` rule. Defense-in-depth: `TechnicianTimeEntryController::lookupStatus()` migrated to `findByIdForScope`, AND `index()` also uses the scoped finder per-row. Regression tests `test_store_rejects_foreign_work_order_id` and `test_update_rejects_foreign_work_order_id` assert `422` on cross-tenant body submissions.

### HTTP matrix test (Task 4 Step 1)

`apps/api/tests/Feature/Workshop/WorkOrder/WorkshopHttpTenantIsolationMatrixTest.php` (221 lines) covers:

- **WorkOrder (14 endpoints)**: GET /{id}, PATCH /{id}, POST /lines, POST /lines/bundle, PATCH/DELETE /lines/{lineId}, PUT /lines/reorder, POST /assignments, DELETE /assignments/{assignmentId}, PUT /primary-technician, POST /approval, POST /transition, POST /cancel, POST /complete.
- **Bundle (8 endpoints)**: GET, PATCH, DELETE /{id}, GET /expansion, POST /components, PATCH/DELETE /components/{componentId}, PUT /vehicle-applicabilities.

Each endpoint is invoked with a tenant-A authenticated user against a foreign-tenant resource ID; all assert `404`. Matches the revised plan's endpoint matrix exactly.

### Inventory hygiene

- `api.workshop.008` and `.009` added to `tenant-isolation-sweep-manual-callsites.yml` with `pattern_type: unscoped_eloquent_find`, `severity: low` (interface gap rather than active exfiltration path now that consumers use the scoped methods).
- `api.workshop.010` added with `pattern_type: unscoped_body_foreign_key`, `severity: high` (was the real exfiltration vector found by Codex's adversary review).

## Non-blocking findings

1. **Dead V1 listener mapping** in `EventServiceProvider.php`: the `WorkOrderCompleted::class => [CloseTimeEntryOnWorkOrderCompleted::class, MirrorAppointmentOnWorkOrderCompleted::class]` block is now functionally dead because `WorkOrderTransitionService` dispatches `WorkOrderCompletedV2` exclusively. Listeners themselves still type `handle(object $event)` so they work for either class. The V1 mapping is harmless overhead; suggest removing in a follow-up PR titled "cleanup(workshop): drop dead WorkOrderCompleted V1 listener mapping". Not a blocker because the V2 path is correctly wired and the V1 class must remain for queue-serialization compatibility per the AutoERP immutable-events convention.

2. The `CloseTimeEntryOnWorkOrderCompleted` docstring still says "Subscribes to Plan B's `WorkOrderCompleted` event." Outdated reference; should mention V2. Cosmetic.

## Disposition

APPROVE. Task 4 is closed cleanly. The two non-blocking findings can be addressed in a small cleanup PR after the master closure PR lands.

## Cross-references

- Plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md` (Task 4 + Step 0/3a/3b)
- Codex adversary review (Findings 1, 2): `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-adversary-review.md`
- Cluster-level review (now upgradable from CONDITIONAL to APPROVE): `docs/superpowers/reviews/2026-05-12-api-workshop-cluster-claude-review.md`
- Tests: `apps/api/tests/Feature/Workshop/WorkOrder/WorkshopHttpTenantIsolationMatrixTest.php`, `apps/api/tests/Feature/Workshop/Technician/TechnicianTimeEntryControllerTest.php`, `apps/api/tests/Feature/Vehicle/WriteMileageReadingFromWorkOrderCompletedTest.php`, `apps/api/tests/Unit/Workshop/WorkOrder/EventSignaturesTest.php`
