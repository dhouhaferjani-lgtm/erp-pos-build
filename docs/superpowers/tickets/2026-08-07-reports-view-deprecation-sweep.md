# `reports.view` deprecation-removal sweep (next release after the W-6 D5 split ships)

Source: perms-lane gate m-5 (docs/superpowers/reviews/2026-08-06-l5-perms-gate.md on
`fix/l5-finance-perms-and-taxconfig-seed`). The W-6 D5 split leaves `reports.view` seeded but
route-orphaned (gate verified: zero routes check it). When the row is DROPPED next release:

- `e2e/money-campaign/w8-isolation.spec.ts:642` pins the literal `'reports.view'` — breaks on
  drop; update alongside.
- Remove from `RolesAndPermissionsSeeder::permissionNames()` + all role grant lists, regen
  `permissionsMap.generated.ts`, per-tenant reseed + `permission:cache-reset` (the seeder is
  syncPermissions-based, so the reseed both drops the grant and — warning — clobbers any
  tenant-custom role grants; coordinate with support).
- `apps/pos/.../TodaySalesPanel.tsx:222` looks like a hit but is an i18n key, NOT a
  permission (gate-verified) — do not touch.
- Sweep erp-mobile for `reports.view` before dropping (not checked by the gate).

Also carried here: gate I-1 orchestrator ruling record — `reports.manage` REMOVED from the
manager role in-lane (manager could POST /vat/periods/{id}/file while 403 on reading the
period list; "manager gets OPERATIONAL ONLY" governs). If the owner wants managers to run
period lifecycle ops, that's a deliberate re-grant with its own ruling, not a default.
