# Ticket: tax-configuration management is DEAD on every real tenant — `taxation.tax_configurations.manage` is never seeded

From the money-campaign W-X leg (2026-08-05), live-proven against
`demo-pharmacy-tn` on the local stack. Cases MTP-TAX-03, MTP-TAX-06, MTP-CFG-09.

## Finding — P1 (fiscal-config lockout; no data corruption)

The tax-configuration write endpoints are all gated by
`can:taxation.tax_configurations.manage`
(`apps/api/app/Modules/Taxation/routes.php:23-31` — store / update / delete /
reorder). That permission is **never created in tenant databases**:

- It IS defined in `database/seeders/PermissionSeeder.php` (`:121`, `:225`, granted
  there to an `Accountant` role) — but `PermissionSeeder` is **not inert**: it is
  invoked by `ProductionSeeder.php:68`. `ProductionSeeder`/`PermissionSeeder` is the
  **central-DB / registration bootstrap** path, NOT the per-tenant provisioning path.
- **Per-tenant provisioning uses ONLY `RolesAndPermissionsSeeder`** — invoked by
  `TenantInitializationService.php:177-178`, which never calls `PermissionSeeder`. And
  `RolesAndPermissionsSeeder` contains **zero** references to `taxation.*`. Its `admin`
  role syncs `Permission::all()` (`:473`), but "all" is only the permissions that seeder
  itself created — so `taxation.tax_configurations.manage` is absent in every tenant DB.

Live proof on `demo-pharmacy-tn`:

- Tenant DB `tenant019fbe86-…` has **288 permission rows, ZERO matching `taxation.*`**
  (`select count(*) from permissions where name like 'taxation%'` → 0).
- `owner@pharmabio.tn` (role `admin`, the all-permissions role) `/auth/me`
  permission list does **not** contain `taxation.tax_configurations.manage`.
- `PATCH /taxation/configurations/{id}` → **403** as `owner` (admin) AND as `cashier`
  (same permission absence, not a role difference). Reads (`GET`) stay **200** for all.

**Consequence:** on any tenant seeded by RolesAndPermissionsSeeder (i.e. all real
tenants), **no user — not even the admin/owner — can create, edit, deactivate, or
reorder a tax configuration** (VAT rates, stamp duties). The Taxes tab in
`/settings/tax` is effectively read-only in production; the launch tenant cannot
turn a stamp on/off or add a rate through the app.

## Campaign impact

- **MTP-TAX-03** (deactivate stamp → total omits stamp): mutation half BLOCKED. The
  403 refusal is GREEN-tripwired in `wx-tax-config.spec.ts`.
- **MTP-TAX-06** (change rate after posting → posted doc unchanged): retroactive-recompute
  path unreachable; posted-doc immutability separately covered by W1b MTP-DOC-08.
- **MTP-CFG-09** (deactivate 19% → no longer offered): mutation half BLOCKED.

## Fix

Fix the **per-tenant** seeder — `RolesAndPermissionsSeeder` (the one
`TenantInitializationService.php:177-178` runs), NOT `PermissionSeeder`/`ProductionSeeder`
(central bootstrap): add `taxation.tax_configurations.manage` to
`RolesAndPermissionsSeeder::permissionNames()` and grant it to the roles that should
manage fiscal config (at minimum `admin`; per `PermissionSeeder`'s intent, also the
`accountant`/`Accountant` role). Then reseed + `permission:cache-reset` tenant-wide. A
deploy adding this permission MUST run a tenant-wide `permission:cache-reset` (tenant-blind
Spatie cache).

## Companion, distinct finding (same case family, already ticketed elsewhere)

`PUT /companies/{id}` authorization is separately covered by
`docs/superpowers/tickets/2026-08-02-company-update-route-unauthorized.md`. Note the
company `tax_status` field IS additionally protected by a **fiscal lock**: once posted
documents exist it returns `422 BUSINESS_ERROR` ("Tax status is permanently locked
after the first invoice or credit note is posted"). That lock is correct behaviour and
is GREEN-tripwired by MTP-TAX-02 — it is not part of this ticket's defect.
