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

## DEPLOY CHECKLIST LINES for the perms lane (fold into the batch deploy checklist at promotion)

1. Per tenant: `tenants:run db:seed --class=RolesAndPermissionsSeeder`, then tenant-wide
   `permission:cache-reset` (Spatie cache is tenant-blind).
2. ⚠️ BEFORE the reseed: verify no tenant has CUSTOMISED role grants — the seeder is
   `syncPermissions`-based (no merge mode); a reseed clobbers custom grants on the builtin
   roles. Check `model_has_permissions`/`role_has_permissions` drift vs the seeder matrix per
   tenant, coordinate with support on any hit (gate m-1).
3. Post-deploy spot-check: manager 403 on `GET /reports/trial-balance` AND on
   `POST /vat/periods/{id}/file`; accountant 200-class on both; admin unchanged.

Also carried here: gate I-1 orchestrator ruling record — `reports.manage` REMOVED from the
manager role in-lane (manager could POST /vat/periods/{id}/file while 403 on reading the
period list; "manager gets OPERATIONAL ONLY" governs). If the owner wants managers to run
period lifecycle ops, that's a deliberate re-grant with its own ruling, not a default.

4. **R2-H addition (2026-08-07):** the withholding lane adds `taxation.withholding_rules.manage`
   (admin+accountant). Deploy sequence is seeder-FIRST then cache-reset (cache-reset cannot
   create the row); staging's `SYNC_PERMISSIONS_ON_BOOT=true` covers it on deploy —
   VERIFY the flag on any NEW environment before this reaches a tenant, else the rules group
   403s everyone including admin (API-only surface today).
