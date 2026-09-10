# RoleController team-scope annotations overstate query isolation

Status: deferred by the W-LOT-A-1a legacy-role ruling; tenancy-authz reviewer follow-up.
Evidence pin: `3046ca872`, `lane/w-lot-a-1a` (controller unchanged by this follow-up).

`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`:

- Line 158, `index`: claims `Role::with()->get()` has a Spatie team global scope.
- Line 189, `show`: claims `findOrFail()` filters to the active team.
- Line 236, `update`: claims a different team's role would return 404.
- Line 280, `destroy`: claims the role lookup is team-scoped.
- Line 206, `store`: distinguish the actual static `Role::create()` team stamping from the nonexistent query-wide global scope in the surrounding annotations.

Spatie `Role::findByParam()` explicitly applies `(tenant_id IS NULL OR tenant_id = current team)`; ordinary Eloquent `Role::query()`, `with()` and `findOrFail()` do not acquire that predicate. Database-per-tenant isolation remains a separate boundary and does not substantiate these annotations.

Follow-up: agree the team/global-role API policy; correct these claims and, where required by that policy, add explicit predicates with index/show/mutation regression tests against NULL-team and second-team role fixtures. Keep the shipped NULL-team catalogue compatible with console/queue contexts. Do not infer authorization from these annotations.

The binding ruling explicitly excludes this controller policy fix from W-LOT-A-1a. This ticket records the issue without changing controller lookup behavior.
