Commit reviewed: f68ed8c4

# Non-TanStack Tenant-Isolation Sweep Final Cumulative Review

Reviewer: codex
Date: 2026-05-13
Base: origin/dev @ 70ea2bccd6242bbc4f354e395da730aaca53f563
Head: origin/feat/tenant-isolation-sweep-execution @ f68ed8c4f50fe199330d2551929be7d2808b4263

Verdict: APPROVE

## Scope

This was a cumulative seam-level review of PR #93 after the per-task reviews and closure gates had already passed. I focused on the explicit gaps in the audit prompt: Task 3 scanner behavior against Task 4 Workshop repository shapes, Task 5 recheck-clear history mutation shape, WorkOrderCompleted V1-to-V2 listener wiring, cross-cluster tenant isolation in newly-added PHP application lookups, Workshop validation false-positive surfaces, HTTP matrix completeness, and Loyalty repository coverage.

## Cross-Checks

- `FindCallVisitor` accepts the new scoped repository implementations that use direct `Model::where('tenant_id', $tenantId)->where('company_id', $companyId)->find(...)` chains. The Workshop `findForUpdateForScope(...)` methods use `whereKey(...)->lockForUpdate()->first()`, which is outside that visitor's terminal-method scope, but callers route through scoped repositories and the WorkOrder/Bundle repository tests plus HTTP matrix cover the behavior.
- `sweep:inventory:recheck-clear` writes the same `review` history transition shape through the shared `InventoryService::mutate(...)` path. The command appends a callsite history event with `from_status: needs_recheck`, `to_status: fixed`, review file/commit linkage, and receives actor/command/commit/hash-chain stamping from the same mutation infrastructure used by the other workflow commands.
- Workshop production dispatch of V1 `WorkOrderCompleted` is gone. `WorkOrderTransitionService` dispatches `WorkOrderCompletedV2`; `EventServiceProvider` maps only V2 to the completed listeners; the retained V1 class is only referenced by tests and historical compatibility surfaces. I did not find another Workshop event with the same V1/V2 deploy-order caveat.
- The three listeners now mapped to `WorkOrderCompletedV2` can run in a queue worker without ambient tenant context. `WriteMileageReadingFromWorkOrderCompleted` uses V2 tenant/company payload and `findByIdForScope(...)`; the time-entry and scheduling mirrors operate from the event work-order anchor and do not perform a tenant-context-dependent `Model::find($id)` reload.
- Newly-added tenant-bearing PHP application lookups in the delta are either explicitly scoped by tenant/company predicates or routed through scoped repository methods. The remaining unscoped repository methods are retained deprecated compatibility methods rather than new call paths in this delta.
- New Workshop validation on technician time-entry `work_order_id` uses `ScopedExists::tenantAndCompany('workshop_work_orders', ...)`. The accepted `UpdateCompanyRequest` `exists:tax_configurations,id` residual remains the documented country-scoped exception.
- The Workshop HTTP matrix covers all ID-bearing WorkOrder routes in `WorkOrder/Presentation/routes.php` (14 cases) and all ID-bearing Bundle routes in `Bundle/Presentation/routes.php` (8 cases). Collection/create/applicable endpoints have no foreign route ID to exercise in that matrix; Technician body `work_order_id` 422 coverage lives in `TechnicianTimeEntryControllerTest`.
- Loyalty's new `RewardRepositoryInterface::existsForProgramInTenant(...)` and `existsInTenant(...)` paths both have negative cross-tenant HTTP coverage through `test_create_stamp_card_rejects_cross_tenant_reward_id` and `test_update_stamp_card_rejects_cross_tenant_reward_id`, respectively.

No cumulative blocker, P1, or P2 defect surfaced in this pass.
