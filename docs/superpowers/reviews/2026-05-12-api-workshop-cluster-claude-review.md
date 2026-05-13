# api.workshop Cluster — Claude Review

Cluster: `api.workshop`
Cluster aggregate status (inventory): `fixed`
Verdict: **CONDITIONAL APPROVE** (pending non-TanStack closure plan execution for 3 real code defects + 2 newly-discovered Bundle repository defects)

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

WorkOrder + ServiceBundle module — quote/work-order generation, bundle expansion, technician time entries. Pre-sweep, the cluster shipped the `WorkOrderCreationService` fix but Round-3+ inventory passes surfaced cross-module raw-FK patterns in the WorkOrder adapter layer.

Inventory callsite total: **7 callsites — 4 fixed, 3 pending**.

## Implementation summary

Round-1 fix commit: `148d2703` (4 callsites — `WorkOrderCreationService` initial pass).

Top files touched (current state): `WorkOrderCreationService`, `EloquentWorkOrderRepository`, `BundleExpansionAdapter`, `DocumentGenerationAdapter`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Workshop/WorkshopTenantIsolationTest.php`. The closure plan adds `tests/Feature/Workshop/WorkOrder/*TenantIsolationTest.php` for the pending rows.

## Per-round review trail

- `2026-05-04-api-workshop-cluster-codex-review.md`
- `2026-05-04-api-workshop-cluster-opus-round2-review.md`

## Open work (handled by closure plan)

| Row | File | Defect | Plan task |
|---|---|---|---|
| api.workshop.005 | `EloquentWorkOrderRepository.php` `findById/findForUpdate` | Unscoped (no tenant/company predicate) | Plan Task 4 Steps 1-3: add scoped methods + migrate callers |
| api.workshop.006 | `BundleExpansionAdapter.php:42, :49` | `ServiceBundle::query()->find($bundleId)` unscoped | Plan Task 4 Step 4 |
| api.workshop.007 | `DocumentGenerationAdapter.php:90` | `Document::find($wo->quote_document_id)` unscoped | Plan Task 4 Step 5 |

**Newly discovered during caller audit (added to plan scope as `api.workshop.008` and `.009`):**

| Defect | File | Status |
|---|---|---|
| `EloquentBundleRepository::findById` unscoped | `Bundle/Infrastructure/Persistence/EloquentBundleRepository.php:27` | To be added to manual stub via plan Task 4 Step 0 |
| `EloquentBundleRepository::findWithComponentsAndApplicabilities` unscoped | `Bundle/Infrastructure/Persistence/EloquentBundleRepository.php:32` | To be added to manual stub via plan Task 4 Step 0 |

## Gates evaluated

1. **Round-1 service fix** (`WorkOrderCreationService`): covered.
2. **Cross-module adapters**: PENDING (closure plan).
3. **Repository contract**: PENDING — additive `findByIdForScope` methods per closure plan Task 4.
4. **HTTP-level cross-tenant 404**: PENDING — closure plan Task 4 Step 1.

## Disposition

CONDITIONAL APPROVE. Upgrades to APPROVE once closure plan Task 4 lands AND the 2 newly-discovered `EloquentBundleRepository` defects are inventoried + fixed in the same PR.

## Cross-references

- Closure plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md` Task 4 (all steps)
- Drift detail audit: `docs/superpowers/audits/2026-05-11-tenant-isolation-non-tanstack-drift-detail.md`
- Cluster-level test: `apps/api/tests/Feature/Workshop/WorkshopTenantIsolationTest.php`
