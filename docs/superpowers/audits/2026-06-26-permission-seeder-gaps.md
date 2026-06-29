# Permission seeder gaps — follow-up audit

> Found while fixing the `uom.*` onboarding-403 (see `project_per_unit_quantity_step`). The canonical `RolesAndPermissionsSeeder` (run on tenant provisioning via `DatabaseSeeder` / `TenantInitializationService`) is missing permissions that exist in the legacy `PermissionSeeder`. `uom.*` was fixed in this PR; the rest are listed here as a **security-reviewed follow-up** — granting abilities to roles is sensitive and must be decided per role/vertical, not bulk-applied. Coordinate with `project_go_live_security_audit_2026_06_14`.

## Already fixed in this PR

- `uom.view`, `uom.create`, `uom.edit`, `uom.delete` — added to `RolesAndPermissionsSeeder`; `uom.view` granted to manager/cashier/viewer/technician/operator, full set to manager (admin via `Permission::all()`).

## Still missing from the canonical seeder (present only in legacy `PermissionSeeder`)

Each needs a deliberate per-role/per-vertical grant decision before adding. The controller that `Gate::authorize`s it is the place to confirm who legitimately needs it.

| Permission(s) | Likely consumer | Notes / who plausibly needs it |
|---|---|---|
| `pos.view`, `pos.operate`, `pos.open_shift`, `pos.close_shift`, `pos.cash_drawer`, `pos.reports`, `pos.manage_terminals`, `pos.admin` | POS module | cashier/manager need operate/shift/drawer; admin/manager need terminals/admin/reports. **High priority** if POS roles are provisioned via the canonical seeder. |
| `pricing.view`, `pricing.manage` | Pricing | manager/admin |
| `payments.refund`, `payments.reverse` | Payments/treasury | manager/admin only (sensitive) |
| `quotes.confirm` | Sales documents | sales manager |
| `credit-notes.cancel` | Credit notes | manager/admin |
| `withholding.issue`, `withholding.submit`, `withholding.void` | Tax withholding (Tunisia) | accountant/manager |

> Method to resolve: for each, `grep -rn "Gate::authorize('<perm>'\|can:<perm>" apps/api/app` to find the gating controller/route, then add the permission to `RolesAndPermissionsSeeder`'s `$permissions` list and to the `syncPermissions([...])` array of each role that needs it. Add a feature test (a seeded role hitting the endpoint → 200) like `UomPermissionSeedingTest`. Keep edits additive (shared file, rule 21).

## Why not bulk-fix now

The owner-approved scope for this PR was "fix `uom.*` + descriptive 403 + audit the rest" (not a blanket reconcile): bulk-granting ~20 abilities across roles on a shared seeder is a security-sensitive, regression-prone change best done as its own reviewed PR. The descriptive-403 handler shipped here means that until each gap is closed, any tenant who hits one gets a self-explanatory message naming the missing ability rather than a bare 403.
