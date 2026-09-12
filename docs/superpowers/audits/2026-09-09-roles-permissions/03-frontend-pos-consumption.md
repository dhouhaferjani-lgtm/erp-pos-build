> Audit lane: roles & permissions, 2026-09-09. Produced by a Sonnet research agent from HEAD e85641a66 (dev); read-only. Verification verdicts: see 06-synthesis.md §0.

# Frontend Roles & Permissions Audit — apps/erp

Read-only audit. No repo files edited, no git writes, vitest not run.

**Git HEAD at task start:** `e85641a66` (`e85641a667f97c96b2574a967defef8a3c156f91`), branch `dev`.
**Git HEAD when writing this report:** `73f2040c6` (`73f2040c6d997b26a2a55dfb7d3c8742d361792e`), branch `dev`.
HEAD advanced mid-audit — `dev` is a shared branch other sessions push to (per CLAUDE.md rule 21 / MEMORY.md). All file reads below were taken at whatever HEAD was current at read time; no file was re-read after a HEAD move, so line numbers could theoretically be off by a commit's worth of drift on files not re-verified. Every citation below is `path:line` as read.

No `apps/mobile` directory exists in this repo (`find apps/erp -maxdepth 2 -iname "mobile*"` → empty; top-level `apps/` contains only `api`, `pos`, `test-results-local`, `web`). Item 8 is reported as **N/A — app absent**.

---

## 1. Web permission hook(s)

Files:
- `apps/web/src/hooks/usePermissions.ts` (263 lines)
- `apps/web/src/hooks/permissionsMap.generated.ts` (312 lines) — generated
- `apps/web/src/hooks/uiAliasPermissions.ts` (15 lines) — hand-maintained
- `apps/web/src/components/auth/RequirePermission.tsx` (2 lines, re-export)
- `apps/web/src/features/auth/components/RequirePermission.tsx` (85 lines, real implementation)

**Current state: hybrid, NOT purely hardcoded.** `usePermissions.ts:193-211` (`hasPermission`):
1. If the server-supplied `user.permissions` array (from `/auth/me`, see §6) contains the permission literal, return `true` immediately (`usePermissions.ts:194-196`).
2. Else, if the permission is in `SERVER_AUTHORITATIVE_PERMISSIONS` (`usePermissions.ts:34-44`, a `Set` of 9 entries: `pricing.view_cost_prices`, 4× `bank-statements.*`, 2× `support-access.*`, `supplier-invoices.manage`, `payments.pay-supplier`), return `false` — no fallback for these (comment `usePermissions.ts:10-33` explains why: new/grantable-per-role permissions that a stale static map would fail-open on).
3. Else fall back to a static role→permission map: `PERMISSIONS` from `permissionsMap.generated.ts` (304 entries) or `UI_ALIAS_PERMISSIONS` from `uiAliasPermissions.ts` (10 entries), and check `roles.some(role => allowedRoles.includes(role))` (`usePermissions.ts:201-210`).

So a hardcoded role→permission map still exists and is actively consulted (step 3) whenever the server payload doesn't explicitly grant a permission — but it is generated from the backend seeder for the 304 real permissions, and only the 10-entry `UI_ALIAS_PERMISSIONS` file remains hand-maintained (see §4 for why that file is legacy).

**Generated permission map + regeneration pipeline (confirms it exists and is kept in sync):**
- Backend command: `apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php` (signature `permissions:export-frontend-map`, `:14`) — reads `RolesAndPermissionsSeeder::permissionNames()` and `::rolePermissionGrants()` (`:29-30`), writes `apps/web/src/hooks/permissionsMap.generated.ts` (default path `:27`), and embeds a `sha256` hash of the seeder-derived content in a header comment (`:84-90`). The generated file's header (`permissionsMap.generated.ts:1-3`) reads:
  ```
  // This file is generated. Do not edit it by hand.
  // Source: apps/api/database/seeders/RolesAndPermissionsSeeder.php
  // Source hash: sha256:a00c4e9ddf1ba329a62305e233c386bda4120d120c4b0fc9b018f9502ed53f6b
  ```
- **CI check:** `.github/workflows/ci.yml:2695-2696` runs `php artisan permissions:export-frontend-map`, then `:2698-2711` fails the build (`git diff --quiet -- apps/web/src/hooks/permissionsMap.generated.ts`) if the regenerated file differs from the committed one.
- **Local preflight check:** `scripts/preflight.sh:136-161` does the same (regenerate, then `git -C "$ROOT_DIR" diff --quiet -- "$GENERATED_PERMISSIONS"`, printing the two-line fix command on failure).
- **Meta-test guarding the guard:** `apps/web/tools/__tests__/permission-map-drift-guard.test.mjs:11-23` asserts both `preflight.sh` and `ci.yml` actually contain the regenerate+diff steps (i.e. a test that the sync mechanism itself hasn't been silently removed).

**`RequirePermission` component** (`apps/web/src/features/auth/components/RequirePermission.tsx:37-83`): accepts `permission`, `permissions` (+`requireAll`), and `moduleKey` props; calls `canAccessModule` for `moduleKey` first, then `hasPermission`/`hasAnyPermission`/`hasAllPermissions`; on denial, redirects to `/dashboard` (`:73-76`) unless already there, in which case it renders a `PermissionDenied` fallback (`:78-79`), or renders a caller-supplied `fallback` (`:66-69`). `apps/web/src/components/auth/RequirePermission.tsx:1-2` is a pure re-export for backward-compat import paths.

---

## 2. Route gating — `apps/web/src/routes/index.tsx` (3263 lines, 298 `<Route` elements)

All routes below the top `<Route path="/" element={<RequireAuth>...<Layout/>...</RequireAuth>}>` (`routes/index.tsx:561-568`) are behind `RequireAuth` (login only — no RBAC). `RequireAuth` is defined in `apps/web/src/features/auth/AuthProvider.tsx` (not shown in table; login-gate only, not a permission gate).

Full route table (parsed programmatically from the file; `Gate` column lists every `moduleKey=`, `permission=`, `permissions=[...]`, `ModuleGuard=<Module>`, `RequireAdminAuth`, or `RequireAdminRole:<roles-const>` found in that Route's own JSX block; `NONE` = no such prop found in that block):

| Line | Path | Gate |
|---|---|---|
| 354 | `/login` | NONE |
| 362 | `/register` | NONE |
| 370 | `/verify-email` | NONE |
| 378 | `/forgot-password` | NONE |
| 386 | `/reset-password` | NONE |
| 396 | `/privacy` | NONE |
| 404 | `/terms` | NONE |
| 414 | `/admin/login` | NONE |
| 422 | `/admin` | RequireAdminAuth |
| 432 | `(index)` | NONE |
| 433 | `{adminRoutePolicies.dashboard.path}` | RequireAdminRole:adminRoutePolicies.dashboard.roles |
| 443 | `{adminRoutePolicies.supportAccess.path}` | RequireAdminRole:adminRoutePolicies.supportAccess.roles |
| 453 | `{adminRoutePolicies.tenants.path}` | RequireAdminRole:adminRoutePolicies.tenants.roles |
| 463 | `{adminRoutePolicies.companyOwners.path}` | RequireAdminRole:adminRoutePolicies.companyOwners.roles |
| 473 | `{adminRoutePolicies.verticals.path}` | RequireAdminRole:adminRoutePolicies.verticals.roles |
| 483 | `{adminRoutePolicies.auditLogs.path}` | RequireAdminRole:adminRoutePolicies.auditLogs.roles |
| 493 | `{adminRoutePolicies.billing.path}` | RequireAdminRole:adminRoutePolicies.billing.roles |
| 503 | `{adminRoutePolicies.subscriptions.path}` | RequireAdminRole:adminRoutePolicies.subscriptions.roles |
| 513 | `{adminRoutePolicies.invoices.path}` | RequireAdminRole:adminRoutePolicies.invoices.roles |
| 523 | `{adminRoutePolicies.payments.path}` | RequireAdminRole:adminRoutePolicies.payments.roles |
| 533 | `{adminRoutePolicies.monitoring.path}` | RequireAdminRole:adminRoutePolicies.monitoring.roles |
| 543 | `{adminRoutePolicies.countryDefaults.path}` | RequireAdminRole:adminRoutePolicies.countryDefaults.roles |
| 544 | `{adminRoutePolicies.countryDefaultsTemplate.path}` | RequireAdminRole:adminRoutePolicies.countryDefaultsTemplate.roles |
| 545 | `{adminRoutePolicies.countryDefaultAssignments.path}` | RequireAdminRole:adminRoutePolicies.countryDefaultAssignments.roles |
| 549 | `/company-onboarding` | RequireAuth(login-only) |
| 561 | `/` | RequireAuth(login-only) |
| 570 | `(index)` → `DashboardLanding` | **NONE** |
| 573 | `dashboard` | **NONE** |
| 583 | `sales` (container) | NONE (no element on parent) |
| 584 | `(index)` → `Navigate to /sales/customers` | NONE (redirect, no page) |
| 586 | `to-bill` | permission=deliveries.view / ModuleGuard=Sales |
| 600 | `customers` | moduleKey=sales / permission=partners.view |
| 610 | `customers/new` | permission=partners.create |
| 620 | `customers/:id` | moduleKey=sales / permission=partners.view |
| 630 | `customers/:id/edit` | permission=partners.update |
| 642 | `quotes` | moduleKey=sales |
| 652 | `quotes/new` | permission=sales.create |
| 662 | `quotes/:id` | moduleKey=sales |
| 672 | `quotes/:id/edit` | permission=quotes.update |
| 684 | `orders` | moduleKey=sales |
| 694 | `orders/new` | permission=sales.create |
| 704 | `orders/:id` | moduleKey=sales |
| 716 | `orders/:id/edit` | permission=orders.update |
| 728 | `invoices` | moduleKey=sales |
| 738 | `invoices/new` | permission=sales.create |
| 748 | `invoices/:id` | moduleKey=sales |
| 760 | `invoices/:id/edit` | permission=invoices.update |
| 772 | `credit-notes` | moduleKey=sales |
| 782 | `credit-notes/new` | permission=sales.create |
| 792 | `credit-notes/create` | permission=sales.create |
| 802 | `credit-notes/:id` | moduleKey=sales |
| 814 | `return-notes` | moduleKey=sales |
| 824 | `return-notes/create` | moduleKey=sales |
| 834 | `return-notes/:id` | moduleKey=sales |
| 847 | `purchases` (container) | NONE |
| 848 | `(index)` | NONE (redirect) |
| 851 | `suppliers` | moduleKey=purchases / permission=partners.view |
| 861 | `suppliers/new` | permission=partners.create |
| 871 | `suppliers/:id` | moduleKey=purchases / permission=partners.view |
| 881 | `suppliers/:id/edit` | permission=partners.update |
| 893 | `quote-requests` | moduleKey=purchases |
| 903 | `quote-requests/new` | moduleKey=purchases |
| 913 | `quote-requests/groups/:groupId` | moduleKey=purchases |
| 923 | `quote-requests/:id` | moduleKey=purchases |
| 935 | `orders` (purchases) | moduleKey=purchases |
| 945 | `orders/new` (purchases) | permission=purchases.create |
| 955 | `orders/:id` (purchases) | moduleKey=purchases |
| 967 | `orders/:id/edit` (purchases) | permission=purchase-orders.update |
| 979 | `receipts` | moduleKey=purchases |
| 989 | `receipts/new` | permission=goods-receipt.create-standalone |
| 999 | `scans` | permission=document-ingestions.view |
| 1009 | `scans/new` | permission=document-ingestions.view |
| 1019 | `scans/:id` | moduleKey=purchases / permission=document-ingestions.view |
| 1046 | `supplier-invoices` | permission=documents.view |
| 1061 | `supplier-invoices/new` | permission=supplier-invoices.manage |
| 1071 | `supplier-invoices/:id` | permission=documents.view |
| 1084 | `inventory` (container) | NONE |
| 1085 | `(index)` | moduleKey=inventory |
| 1093 | `products` | moduleKey=inventory |
| 1103 | `products/new` | permission=inventory.create |
| 1113 | `products/:id` | moduleKey=inventory |
| 1123 | `products/:id/edit` | permission=products.update |
| 1134 | `stock` | moduleKey=inventory |
| 1145 | `stock-by-location` | permission=inventory.view |
| 1156 | `placement` | permission=inventory.view |
| 1167 | `movements` | moduleKey=inventory |
| 1178 | `entry-exit-notes` | moduleKey=inventory |
| 1189 | `categories` (inventory) | moduleKey=inventory / ModuleGuard |
| 1201 | `batches` | moduleKey=inventory / ModuleGuard=BatchExpiry |
| 1213 | `batches/new` | moduleKey=inventory / ModuleGuard=BatchExpiry |
| 1225 | `batches/:uuid/edit` | moduleKey=inventory / ModuleGuard=BatchExpiry |
| 1237 | `batches/:uuid` | moduleKey=inventory / ModuleGuard=BatchExpiry |
| 1251 | `expiry-write-off` | permission=batches.write-off / ModuleGuard=BatchExpiry |
| 1265 | `delivery-notes` | moduleKey=inventory |
| 1275 | `delivery-notes/new` | permission=inventory.create |
| 1285 | `delivery-notes/:id` | moduleKey=inventory |
| 1297 | `return-notes` (inventory) | moduleKey=inventory |
| 1307 | `return-notes/new` (inventory) | permission=inventory.create |
| 1317 | `return-notes/:id` (inventory) | moduleKey=inventory |
| 1329 | `counting` | moduleKey=inventory |
| 1339 | `counting/list` | moduleKey=inventory |
| 1349 | `counting/create` | permission=inventory.create |
| 1359 | `counting/:id` | moduleKey=inventory |
| 1369 | `counting/:id/review` | moduleKey=inventory |
| 1379 | `counting/:id/report` | moduleKey=inventory |
| 1390 | `enrichment-results` | moduleKey=inventory |
| 1402 | `stock-transfers` | permission=inventory.transfers.view |
| 1412 | `stock-transfers/new` | permission=inventory.transfers.create |
| 1422 | `stock-transfers/:id` | permission=inventory.transfers.view |
| 1437 | `stock-adjustments` | permission=inventory.adjustments.view |
| 1447 | `stock-adjustments/new` | permission=inventory.adjustments.create |
| 1457 | `stock-adjustments/:id` | permission=inventory.adjustments.view |
| 1467 | `replenishment` | permission=replenishment.view |
| 1477 | `replenishment/new` | moduleKey=parts_catalog / permission=replenishment.create / ModuleGuard=PlatformIntegration |
| 1495 | `parts-catalog` | ModuleGuard=PlatformIntegration |
| 1505 | `parts-catalog/:articleId` | ModuleGuard=PlatformIntegration |
| 1517 | `vehicles` | moduleKey=vehicles / ModuleGuard=Vehicle |
| 1529 | `vehicles/new` | permission=vehicles.create / ModuleGuard=Vehicle |
| 1541 | `vehicles/:id` | moduleKey=vehicles / ModuleGuard=Vehicle |
| 1553 | `vehicles/:id/edit` | permission=vehicles.update / ModuleGuard=Vehicle |
| 1567 | `services` (container) | NONE |
| 1568 | `(index)` | moduleKey=services / ModuleGuard=Workshop |
| 1580 | `new` (services) | permission=services.create / ModuleGuard=Workshop |
| 1592 | `categories` (services) | moduleKey=services / ModuleGuard=Workshop |
| 1604 | `:id` (services) | moduleKey=services / ModuleGuard=Workshop |
| 1616 | `:id/edit` (services) | permission=services.edit / ModuleGuard=Workshop |
| 1631 | `workshop/work-orders` (container) | NONE |
| 1632 | `(index)` | permission=work-orders.view / ModuleGuard=Workshop |
| 1644 | `new` (work-orders) | permission=work-orders.create / ModuleGuard=Workshop |
| 1656 | `:id` (work-orders) | permission=work-orders.view / ModuleGuard=Workshop |
| 1671 | `scheduling` (container) | NONE |
| 1672 | `(index)` | permission=scheduling.appointments.view |
| 1682 | `appointments/:id` | permission=scheduling.appointments.view |
| 1692 | `capacity` | permission=scheduling.appointments.view |
| 1705 | `workshop/bundles` (container) | NONE |
| 1706 | `(index)` | permission=workshop-bundles.view |
| 1716 | `new` (bundles) | permission=workshop-bundles.manage |
| 1726 | `:id` (bundles) | permission=workshop-bundles.view |
| 1739 | `expenses` (container) | NONE |
| 1740 | `(index)` | permission=expenses.view |
| 1750 | `new` (expenses) | permission=expenses.create |
| 1760 | `categories` (expenses) | permission=expense-categories.view |
| 1770 | `recurring` | permission=expense-recurrences.view |
| 1780 | `analytics` (expenses) | permission=expenses.view |
| 1790 | `:id` (expenses) | permission=expenses.view |
| 1800 | `:id/view` | permission=expenses.view |
| 1810 | `:id/edit` (expenses) | permission=expenses.update |
| 1823 | `income` (container) | NONE |
| 1824 | `(index)` | permission=income.view |
| 1834 | `new` (income) | permission=income.create |
| 1844 | `:id/edit` (income) | permission=income.update |
| 1857 | `treasury` (container) | NONE |
| 1858 | `(index)` | NONE (redirect, no page) |
| 1860 | `payments` (treasury) | moduleKey=treasury |
| 1870 | `payments/new` (treasury) | permission=treasury.create |
| 1880 | `payments/:id` (treasury) | moduleKey=treasury |
| 1891 | `instruments` | permission=instruments.view |
| 1901 | `instruments/:id` | permission=instruments.view |
| 1912 | `remittances` | permission=instruments.remit |
| 1922 | `remittances/new` | permission=instruments.remit |
| 1932 | `remittances/:id` | permission=instruments.remit |
| 1943 | `payment-methods` | moduleKey=treasury |
| 1954 | `repositories` | moduleKey=treasury |
| 1964 | `repositories/:id` | moduleKey=treasury |
| 1975 | `statements` | moduleKey=treasury / permission=bank-statements.view |
| 1986 | `statements/:id` | moduleKey=treasury / permission=bank-statements.view |
| 1998 | `withholding-certificates` | permission=withholding.view |
| 2008 | `withholding-certificates/:id` | permission=withholding.view |
| 2020 | `sales-withholding-tracking` | permission=withholding.view |
| 2033 | `reports` | permission=dashboard.owner |
| 2045 | `finance` (container) | NONE |
| 2046 | `overview` | permission=reports.operational |
| 2056 | `lane-separation` | permission=reports.financial |
| 2066 | `cash-movements` | permission=reports.operational |
| 2076 | `chart-of-accounts` | permission=accounts.view |
| 2086 | `ledger` | permission=ledger.view |
| 2096 | `trial-balance` | permission=reports.financial |
| 2106 | `profit-loss` | permission=reports.financial |
| 2116 | `balance-sheet` | permission=reports.financial |
| 2126 | `aged-receivables` | permission=reports.operational |
| 2136 | `aged-payables` | permission=reports.operational |
| 2146 | `journal-entries` | permission=journal.view |
| 2156 | `journal-entries/create` | permission=journal.create |
| 2166 | `journal-entries/:id` | permission=journal.view |
| 2176 | `vat-periods` | moduleKey=reports |
| 2186 | `vat-report/:id` | moduleKey=reports |
| 2199 | `pricing` (container) | NONE |
| 2200 | `(index)` | NONE |
| 2201 | `price-lists` | permission=pricing.view |
| 2211 | `price-lists/new` | permission=pricing.manage |
| 2221 | `price-lists/:id` | permission=pricing.view |
| 2231 | `price-lists/:id/edit` | permission=pricing.manage |
| 2244 | `channels` (container) | NONE |
| 2245 | `(index)` | moduleKey=inventory |
| 2255 | `new` (channels) | moduleKey=inventory |
| 2265 | `:id/products` | moduleKey=inventory |
| 2275 | `:id/sync` | moduleKey=inventory |
| 2285 | `:id/orders` (channels) | moduleKey=inventory |
| 2298 | `ecommerce` (container) | NONE |
| 2299 | `orders` (ecommerce) | moduleKey=inventory |
| 2312 | `settings` (container) | NONE |
| 2313 | `support-access` | permission=support-access.view |
| 2323 | `(index)` (settings) | moduleKey=settings |
| 2333 | `users` | moduleKey=settings |
| 2343 | `roles` | moduleKey=settings |
| 2353 | `company` | moduleKey=settings |
| 2363 | `tax` | moduleKey=settings |
| 2373 | `locations` | permission=inventory.view |
| 2383 | `inventory` (settings) | moduleKey=settings |
| 2393 | `pos-refund-policies` | moduleKey=settings |
| 2403 | `audit/customer-history` | permission=pos.search_customer_full_history |
| 2413 | `units` | permission=uom.view |
| 2425 | `import` | moduleKey=settings |
| 2435 | `import/history` | moduleKey=settings |
| 2445 | `import/:type` | moduleKey=settings |
| 2457 | `opening-balances` | moduleKey=settings |
| 2467 | `opening-balances/:type` | moduleKey=settings |
| 2480 | `setup` | moduleKey=settings |
| 2491 | `compliance/fraud-settings` | permission=fraud-settings.view |
| 2501 | `compliance/fraud-alerts` | permission=fraud-alerts.view |
| 2511 | `compliance/export` | permissions=[compliance.export_jet, compliance.verify_chains, compliance.view_reprint_log] |
| 2525 | `compliance/quarantine-resolution` | permission=fiscal.events.resolve_quarantine |
| 2538 | `catalog` (container) | NONE |
| 2539 | `composite-items` | permission=composite-items.view / ModuleGuard=CompositeItems |
| 2551 | `composite-items/new` | permission=composite-items.create / ModuleGuard=CompositeItems |
| 2563 | `composite-items/:id/edit` | permission=composite-items.view / ModuleGuard=CompositeItems |
| 2575 | `modifier-groups` | permission=modifier-groups.view / ModuleGuard=CompositeItems |
| 2587 | `modifier-groups/new` | permission=modifier-groups.manage / ModuleGuard=CompositeItems |
| 2599 | `modifier-groups/:id/edit` | permission=modifier-groups.manage / ModuleGuard=CompositeItems |
| 2611 | `menus` | permission=composite-items.view / ModuleGuard=Menu |
| 2623 | `menus/new` | permission=composite-items.create / ModuleGuard=Menu |
| 2635 | `menus/:id/edit` | permission=composite-items.view / ModuleGuard=Menu |
| 2647 | `attributes` | permission=catalog.attributes.view / ModuleGuard |
| 2662 | `parapharmacy` (container) | NONE |
| 2663 | `ingredients` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2675 | `ingredients/new` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2687 | `ingredients/:id` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2700 | `certifications` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2712 | `certifications/new` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2724 | `certifications/:id` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2737 | `health-claims` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2749 | `health-claims/new` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2761 | `health-claims/:id` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2774 | `key-components` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2786 | `key-components/new` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2798 | `key-components/:id` | permission=settings.manage / ModuleGuard=Parapharmacy |
| 2813 | `crm` (container) | NONE |
| 2814 | `(index)` | NONE (redirect) |
| 2817 | `companies` (container) | NONE |
| 2818 | `contacts` | moduleKey=contacts |
| 2828 | `contacts/new` | permission=contacts.create |
| 2838 | `contacts/:id` | moduleKey=contacts |
| 2848 | `contacts/:id/edit` | permission=contacts.update |
| 2861 | `workshop` (container) | NONE |
| 2862 | `(index)` (workshop) | NONE |
| 2863 | `technicians` | permission=workshop.technicians.view |
| 2873 | `technicians/:id` | permission=workshop.technicians.view |
| 2883 | `payroll-exports` | permission=workshop.payroll.view |
| 2896 | `pos` (container) | NONE |
| 2897 | `(index)` (pos) | moduleKey=pos |
| 2905 | `terminals` (pos) | permission=pos.manage_terminals |
| 2916 | `shift-history` | permission=pos.manage_shifts |
| 2927 | `z-reports` | permission=pos.view_reports |
| 2937 | `z-reports/:zNumber` | permission=pos.view_reports |
| 2948 | `analytics` (pos) | permission=pos.view_reports / ModuleGuard=POS |
| 2961 | `receipts` (pos) | permission=pos.view_receipts / ModuleGuard=POS |
| 2972 | `receipts/refunds` | permission=pos.view_receipts / ModuleGuard=POS |
| 2983 | `receipts/:id` (pos) | permission=pos.view_receipts |
| 2994 | `orders` (pos) | permission=pos.operate_terminal / ModuleGuard=Menu |
| 3007 | `tables` | permission=pos.manage_tables / ModuleGuard=Tables |
| 3020 | `promotions` | permission=promotions.view |
| 3030 | `promotions/new` | permission=promotions.manage |
| 3040 | `promotions/:id/edit` | permission=promotions.manage |
| 3051 | `coupons` | permission=coupons.view |
| 3061 | `coupons/new` | permission=coupons.manage |
| 3071 | `coupons/:id/edit` | permission=coupons.manage / ModuleGuard |
| 3082 | `loyalty/programs` | permission=loyalty.view / ModuleGuard=Loyalty |
| 3094 | `loyalty/programs/new` | permission=loyalty.manage / ModuleGuard=Loyalty |
| 3106 | `loyalty/programs/:id` | permission=loyalty.view / ModuleGuard=Loyalty |
| 3118 | `loyalty/programs/:id/edit` | permission=loyalty.manage / ModuleGuard=Loyalty |
| 3131 | `loyalty/members` | permission=loyalty.view / ModuleGuard=Loyalty |
| 3143 | `loyalty/members/new` | permission=loyalty.manage / ModuleGuard=Loyalty |
| 3155 | `loyalty/members/:id` | permission=loyalty.view / ModuleGuard=Loyalty |
| 3167 | `loyalty/members/:id/edit` | permission=loyalty.manage / ModuleGuard=Loyalty |
| 3180 | `vouchers` | moduleKey=pos |
| 3190 | `vouchers/:id` | moduleKey=pos |
| 3203 | `growth` (container) | NONE |
| 3204 | `(index)` → `GrowthPage` | **NONE** |
| 3212 | `modules` → `ProgressionModulesPage` | **NONE** |
| 3223 | `partners` | NONE (`<Navigate to="/sales/customers"/>` — legacy redirect) |
| 3224 | `partners/*` | NONE (redirect) |
| 3225 | `documents` | NONE (`<Navigate to="/sales/invoices"/>` — redirect) |
| 3226 | `documents/*` | NONE (redirect) |
| 3227 | `products` (top-level) | NONE (`<Navigate to="/inventory/products"/>` — redirect) |
| 3230 | `*` | NONE (`<Navigate to="/dashboard"/>` catch-all) |
| 3234 | `/pos/transactions` (outside Layout) | permission=pos.operate_terminal / RequireAuth(login-only) |
| 3247 | `/pos/kitchen` (outside Layout) | permission=pos.operate_terminal / ModuleGuard=Menu / RequireAuth(login-only) |

**Routes with genuinely no permission/module gate** (authenticated real pages, verified by direct read, not counting pre-auth public pages, parent containers with no element, or pure `<Navigate>` redirects):
- `dashboard` — `apps/web/src/routes/index.tsx:573-580` (any authenticated user of any role sees it)
- `(index)` under `/` (`DashboardLanding`) — `routes/index.tsx:570`
- `growth` index (`GrowthPage`) — `routes/index.tsx:3203-3210`
- `growth/modules` (`ProgressionModulesPage`) — `routes/index.tsx:3211-3219`

Verified directly (`routes/index.tsx:566-582`, `:3200-3220`): these sit inside the outer `<RequireAuth>` (`:561-568`) but carry no `RequirePermission`/`ModuleGuard` wrapper.

---

## Sidebar navigation gating — `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (728 lines)

Nav item shape carries two independent, differently-named gate fields, both read by `isNavItemVisible` (`Sidebar.tsx:449-467`):
- `module?: BackendModule | BackendModule[]` → checked via `hasModule(name)` from `apps/web/src/contexts/CompanyConfigContext.tsx:33,66-84` (tenant's `all_enabled_modules`, i.e. vertical/module entitlement — nothing to do with the user's role).
- `permission?: ModuleKey` → checked via `canAccessModule(permission)` from `usePermissions.ts:230-238`, which looks up `MODULE_PERMISSIONS[moduleKey]` and does `hasAnyPermission`. **This field is named `permission` in the nav-item type but is actually a `ModuleKey`, not a raw backend `Permission` string** — e.g. `Sidebar.tsx:161-162` sets `permission: 'sales'`, which resolves through `MODULE_PERMISSIONS.sales = ['sales.view']` (`usePermissions.ts:57`), a `UI_ALIAS_PERMISSIONS` entry, not a seeded permission (see §4 finding).

Gate is fail-closed on both axes (`Sidebar.tsx:445-448` comment): a declared `module` with no match on `hasModule` hides the item regardless of `permission`; a declared `permission` with no match on `canAccessModule` hides the item regardless of `module`.

Representative nav entries (module / permission columns, `Sidebar.tsx` line numbers):
- `allServices` — `module: 'Workshop', permission: 'services'` (`:147`)
- Sales group — `module: 'Sales', permission: 'sales'` (`:161-162`); child `toBill` — `permission: 'deliveries.view'` (`:170`, a real backend permission, not a module key)
- Purchases group — `permission: 'nav.purchasesGroup'` (`:187`, a nav-only synthetic key documented at `usePermissions.ts:151-163`)
- Catalog group — `module: 'Catalog', permission: 'inventory'` (`:213-214`)
- Inventory group — `module: 'Inventory', permission: 'inventory'` (`:232-233`)
- POS group — `permission: 'pos'` (`:251`), children individually keyed to real `pos.*` permissions (`:256-264`)
- Ecommerce/CRM group — `module: 'Ecommerce', permission: 'inventory'` (`:273-274`)
- Treasury group — `module: 'Treasury'` (`:294`, no group-level `permission`), children each carry their own `permission` (`:296-304`)
- Accounting group — `module: 'Accounting', permission: 'accounts'` (`:310-311`)
- Vehicles/Workshop/PlatformIntegration combined group — `module: ['Vehicle','Workshop','PlatformIntegration']` (`:337`, no group-level `permission`)
- Parapharmacy group — `module: 'Parapharmacy'` (`:355`, no `permission`)
- Owner reports — `permission: 'ownerReports'` (`:368`)
- Support access — `permission: 'support-access'` (`:377`)
- Compliance — `permission: 'compliance'` (`:384`)
- Settings — `permission: 'settings'` (`:391`)

---

## 3. Distinct FE permission strings vs. backend seeder

Extraction method: regex scan of `apps/web/src` (excluding `__tests__`/`*.test.*`) for `hasPermission('...')`, `hasAnyPermission([...])`/`hasAllPermissions([...])`, JSX `permission="..."`, `permissions={[...]}`, and object-literal `permission: '...'`/`permissions: [...]` (this last form covers `Sidebar.tsx`'s nav-config `permission:` field, which — per §2 — is semantically a `ModuleKey`, not a `Permission`). **183 distinct strings** found (`fe_perms_all.tsv`, 183 lines).

Seeder ground truth: `apps/web/src/hooks/permissionsMap.generated.ts` (304 permission keys, lines 5-308ish, deterministically generated from `apps/api/database/seeders/RolesAndPermissionsSeeder.php::permissionNames()` per §1 — used as the seeder's live permission set since the CI/preflight guard keeps it byte-identical).

**Result: 0 of the 183 FE strings are undefined** against the union of {304 seeded permissions} ∪ {10 `UI_ALIAS_PERMISSIONS` keys, `apps/web/src/hooks/uiAliasPermissions.ts:2-13`} ∪ {56 `MODULE_PERMISSIONS` keys, `usePermissions.ts:55-164`}.

Strictly against the seeder alone (ignoring aliases/module-keys), **42 of 183** FE strings are absent — all 42 resolve to either a `MODULE_PERMISSIONS` key (bare module names like `accounts`, `sales`, `treasury`, `pos`, `inventory`, `dashboard`, `settings`, `compliance`, etc., and nav-only synthetic keys `nav.purchaseOrders` / `nav.purchaseQuoteRequests` / `nav.purchasesGroup` / `nav.supplierInvoices`) or a `UI_ALIAS_PERMISSIONS` key (`sales.create`, `purchases.create`, `services.create`, `services.edit`, `inventory.create`, `treasury.create`). Full list in `fe_perms_not_in_seeder_or_alias_or_modulekey.txt` → 0 lines (i.e., nothing left over after that reconciliation).

**Naming inconsistency found — `services.*` has no backend permission at all.** `uiAliasPermissions.ts:1` states "UI grouping gates — NOT backend authorization. Never add keys here; migrate call sites to real backend permissions instead." `services.view` (`uiAliasPermissions.ts:8`), `services.create` (`:9`), `services.edit` (`:10`) exist only in this alias file — the seeder has **no** `services.*` permission at all (confirmed: `grep -E "^services\." seeder_permissions.txt` → empty). Cross-checked against the actual API routes: `apps/api/app/Modules/Service/Presentation/routes.php:20` gates `/services` and `/service-categories` (`:22-53`) with `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Workshop']` only — **no `can:` middleware on any of the six Service routes**. So `routes/index.tsx:1580` (`permission="services.create"`) and `:1616` (`permission="services.edit"`) are pure client-side UI conveniences with zero server-side permission enforcement backing them: any authenticated user in a Workshop-enabled tenant can POST/PATCH/DELETE a service regardless of role.

---

## 4. Role names referenced in the frontend vs. seeded roles

**Seeded tenant roles** (from `permissionsMap.generated.ts`'s role arrays, which mirror `RolesAndPermissionsSeeder.php:582,585,690,726,766,791,825`): `admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant` — **7 roles**.

**`hasRole`/`roles.includes` call sites in `apps/web/src`:**
- `usePermissions.ts:243-245` (`hasRole` definition) — `roles.includes(role)`, generic, no hardcoded names at the call site itself.
- `usePermissions.ts:250-252` (`isAdmin`) — hardcodes `roles.includes('admin')` (valid seeded role).
- `apps/web/src/features/admin/lib/adminRolePolicy.ts:51-53` (`canAccessAdminRoute`) — `policy.roles.includes(role)`, against `AdminRole` (central-admin namespace, see below).
- `apps/web/src/features/admin/components/AdminLayout.tsx:60` — `item.roles.includes(admin.role)`, same central-admin namespace.
- No other direct `hasRole(...)` caller was found in `apps/web/src` outside its own definition/tests.

**Central-admin role namespace is separate and does NOT overlap the tenant role namespace.** `adminRolePolicy.ts:10-12`:
```ts
export const fullAdminRoles = ['super_admin'] as const satisfies readonly AdminRole[]
export const countryDefaultsRoles = ['super_admin', 'defaults_editor'] as const satisfies readonly AdminRole[]
export const supportAccessRoles = ['super_admin', 'support_approver'] as const satisfies readonly AdminRole[]
```
`AdminRole` (imported from `apps/web/src/features/admin/stores/adminAuthStore.ts:1`) is the `SuperAdminRole` union — matches `packages/shared/types/generated.d.ts:8`: `export type SuperAdminRole = 'super_admin' | 'support_approver' | 'defaults_editor'`. This is the platform/central-admin auth system (separate login at `/admin/login`, separate store `adminAuthStore.ts`), gating only the `/admin/*` routes in §2's table — unrelated to tenant RBAC.

**Legacy hardcoded role map confirmed to persist in reduced form.** `git log --follow -- apps/web/src/hooks/uiAliasPermissions.ts` shows the file's origin commit `7ef795cbd` ("Phase 3.0.16: Generate frontend permission map", 2026-07-13) — this is the commit that migrated the OLD fully-hardcoded `ROLE_PERMISSIONS` map (previously inline in `usePermissions.ts`) to the generated `permissionsMap.generated.ts`, and split out 10 entries that could not be generated (because they reference role names the seeder never grants any real permission to) into `uiAliasPermissions.ts`. That file's role arrays reference **`sales`, `purchases`, `inventory`, `treasury`, `user`** as role names (`uiAliasPermissions.ts:3-12`, e.g. `'dashboard.view': ['admin', 'sales', 'purchases', 'inventory', 'treasury', 'accountant', 'manager', 'user']`) — **none of these five names are seeded tenant roles** (the 7 seeded roles are listed above). This directly confirms the "2026 memory" premise: a hardcoded role map existed and, in reduced/vestigial form (10 aliases, 5 of them referencing dead role names), still does — it is just no longer the primary/majority mechanism (304 of the 314 total map entries are now generated).

**`RolesPage.tsx` / `RoleController.php` `SYSTEM_ROLES` constant references role names that don't exist in the seeder either.** `apps/web/src/features/settings/RolesPage.tsx:35`: `const SYSTEM_ROLES = ['super-admin', 'admin', 'owner']` (note hyphen, not underscore). Backend mirror: `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:228` and `:277`: `$systemRoles = ['super-admin', 'admin', 'owner'];` (used to block rename `:229-233` and delete `:278-282` via `SYSTEM_ROLE_PROTECTED`). Of these three, only `admin` is a seeded tenant Spatie role; `super-admin` (hyphenated) matches neither the seeded roles nor the central `super_admin` (underscored) `AdminRole`; `owner` is not a Spatie role at all — it is the name of a value in a **different** enum, `MembershipRole` (`apps/api/app/Modules/Company/Domain/Enums/MembershipRole.php`, mirrored in generated types at `packages/shared/types/generated.d.ts:571`: `export type MembershipRole = 'owner' | 'admin' | 'manager' | 'accountant' | 'cashier' | 'technician' | 'viewer'` — a `company_users` membership-role column, structurally unrelated to Spatie `roles`). This `SYSTEM_ROLES` protection is therefore only effectively reachable if a tenant creates a **custom** Spatie role literally named `super-admin` or `owner` via the RolesPage UI (§5) — the guard is not inert, but it protects against name collisions with concepts from a different subsystem, not against renaming/deleting any role the seeder actually creates besides `admin`.

**POS reproduces the same `super_admin` naming slip against the tenant login user's roles.** `apps/pos/src/stores/operatorStore.ts:354`: `const isAdmin = user.roles.includes('super_admin') || user.roles.includes('admin');` — `user` here is the **tenant** login user (`useAuthStore` from `apps/pos/src/stores/authStore.ts`, not the central admin store), whose `roles` come from the same 7-role seeded set. `'super_admin'` can never appear there, so this offline-PIN-setup admin-bypass (`can_discount: isAdmin, max_discount_percent: isAdmin ? 100 : null` — `operatorStore.ts:362-363`) is reachable only via the `'admin'` half of the check.

---

## 5. Role & permission management UI

`apps/web/src/features/settings/RolesPage.tsx` (544 lines) and `apps/web/src/features/settings/UsersPage.tsx`.

**RolesPage** — full CRUD against `/roles` and `/permissions`, tenant+company scoped:
- List: `useQuery` keyed `tenantScopedKey(['roles'])` (`RolesPage.tsx:71-79`), `enabled: tenantId !== null && companyId !== null` — i.e. **per-company** (the query re-runs on company switch since `tenantScopedKey` appends `companyId`, see §6).
- Create: `POST /roles` with `{name, permissions}` (`:90-104`).
- Update: `PATCH /roles/{id}` with `{name?, permissions?}` (`:106-120`) — for a `SYSTEM_ROLES` role, `name` is omitted from the payload client-side (`:178-186`, `isSystem ? {} : {name: roleName}`) so renaming a system role is prevented client-side; permissions can still be toggled for system roles.
- Delete: `DELETE /roles/{id}` (`:122-136`), with two server-side deletion guards surfaced via error codes: `ROLE_HAS_USERS` → `t('roles.messages.cannotDeleteWithUsers')` (`:139-140`) and `SYSTEM_ROLE_PROTECTED` → `t('roles.messages.cannotDeleteSystem')` (`:141-142`).
- Permission toggling: `togglePermission` (`:186-192`) and `toggleModulePermissions` (bulk-toggle a whole module's permissions, `:194-201`).
- No location-scoping concept anywhere in `RolesPage.tsx` or `RoleController.php` (`grep -in location` on both files → no matches). Location restriction is instead a **per-user** concern: `UsersPage.tsx` has a `canManageLocationAccess` prop (`:641`) and a dedicated test file `UsersPage.locationAccess.test.tsx`, plus a seeded permission `users.manage_location_access` — i.e. roles are company-scoped only; location restriction is layered on individual users, not on roles.

**Permission-label i18n**: `translateModule` (`RolesPage.tsx:33-34`) does `t('permissions.modules.' + module, module)` and `translateAction` (`:36-39`) does `t('permissions.actions.' + action, action)` where `action = permission.replace(module + '.', '')`. Both use the raw key as the i18next fallback, so a missing label degrades silently to the raw permission-module/action string rendered in whatever locale is active — never a broken UI, just an unlocalized (English-looking) string. Module here = `explode('.', $permission->name)[0]` server-side (`RoleController.php:320-324`, the `/permissions` endpoint's own grouping logic) — confirmed to match the client's `module` derivation exactly.

Labels live in `apps/web/src/locales/{en,fr,ar}/common.json` under `permissions.modules` (40 keys) and `permissions.actions` (35 keys) — **identical counts across all three locales** (verified by direct JSON parse), so there is no per-locale gap; any gap exists in all three locales simultaneously or none.

**Computed against the 304 seeded permissions** (module = first dot-segment, action = remainder after `module.`):
- **119 of 304** seeded permissions have a module prefix with **no** `permissions.modules` label (falls back to the raw prefix, e.g. `batches`, `catalog`, `catalog_cart`, `document-ingestions`, `scheduling`, `work-orders`, `workshop`, `pos_held_orders`, `pos_orders`, `fiscal`, `fiscal-periods`, `marketplace`, `goods-receipt`, `expense-categories`, `expense-recurrences`, `purchase-quote-requests`, `supplier-invoices`, `support-access`, `fraud-alerts`, `fraud-settings`, `bank-statements`, `dashboard`, `compliance`, `enrichment`, `imports`, `income`, `units`, `audit`). Full 119-entry list computed in-session (see method above); representative sample above.
- **125 of 304** seeded permissions have an action suffix with **no** `permissions.actions` label (falls back to the raw, possibly multi-segment, suffix, e.g. every `pos.*` permission whose action is a long snake_case string like `approve_discount_limit_override`, `refund_above_threshold`, `issue_goodwill_voucher_high_value`; every multi-segment permission like `scheduling.appointments.view` → action string `appointments.view`, `workshop.technicians.manage_time_entries` → action `technicians.manage_time_entries`, `catalog.attributes.view` → action `attributes.view`).

No seeded permission is missing in one locale but present in another — the three JSON files were confirmed structurally identical in key-count for `permissions.modules`/`permissions.actions`.

---

## 6. Login/me payload, auth store, company-switch refresh, localStorage staleness

**`apps/web/src/stores/authStore.ts`** (118 lines), Zustand + `persist` middleware, localStorage key `'autoerp-auth'` (`:108`):
- `User` interface (`:18-27`): `id, name, email, tenant_id, roles: string[], permissions?: string[], email_verified_at, impersonation?`.
- `partialize` (`:111-115`) persists `user` (roles+permissions included) to localStorage always; `token` is persisted **except** when `user.impersonation` is set (impersonation bearer tokens are deliberately memory-only, `:113`).
- No per-company field in `User` at all — roles/permissions are a single flat array on the store, refreshed by re-fetching, not by an explicit per-company sub-object.

**`AuthProvider`** (`apps/web/src/features/auth/AuthProvider.tsx`, 118 lines): fetches `GET /auth/me` via `useQuery({ queryKey: tenantScopedKey(['auth','me']), staleTime: 1000*60*5, enabled: token !== null })` (`:63-71`) and on success calls `setUser(userData)` (`:97`) with `roles: data.roles, permissions: data.permissions` straight from the response (`:88-95`).

**Company switch DOES refresh permissions**, via query-key re-keying rather than an explicit refetch call: `apps/web/src/lib/tenantScopedKey.ts:29-34` appends `[tenantId, companyId]` to every tenant-scoped query key by reading `useCompanyStore.getState().currentCompanyId` at call/render time. Since `AuthProvider`'s `/auth/me` query key is `tenantScopedKey(['auth','me'])`, switching company changes the key, and TanStack Query fetches the new key automatically (no manual invalidate needed for this one). `CompanySelector.tsx:38-45` additionally calls `queryClient.invalidateQueries()` (no filter — invalidates everything) on a `currentCompanyId` change, via a `useEffect` keyed on `previousCompanyIdRef` (`:37-46`), which would also re-trigger the auth/me query if it were still mounted on the old key. So the mechanism for permission refresh on company switch is real and multiply-redundant (new query key + blanket invalidate), assuming the backend's `/auth/me` actually returns company-scoped roles/permissions (not independently verified against backend in this audit; `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php` uses `CompanyContext` injection which is consistent with that assumption but wasn't traced end-to-end).

**Staleness risk that does exist:** on cold page load, the Zustand `persist` middleware hydrates `user` (and thus `roles`/`permissions`) **synchronously from localStorage** before the `/auth/me` query resolves — `usePermissions()` reads `useAuthStore((state) => state.user)` (`usePermissions.ts:186`) with no loading gate, so any component calling `usePermissions()` during that window sees the last-persisted (possibly stale, e.g. after an out-of-band role change made in another session/tab) roles/permissions until the query resolves and calls `setUser`. Additionally, `staleTime: 1000 * 60 * 5` (`AuthProvider.tsx:69`) means an already-mounted tab will not refetch `/auth/me` for up to 5 minutes after its last fetch even if a role change happens server-side in the interim (no websocket/poll pushes a refresh) — a role change made by an admin in one tab/session will not reach an already-open second tab's `usePermissions()` until that tab's next `/auth/me` refetch (5-minute staleTime elapses, window refocus per TanStack Query defaults, or an explicit `invalidateQueries()` call such as the one in `CompanySelector.tsx:45`, which only fires on that same tab's own company switch).

---

## 7. POS app (`apps/pos/src`)

**Operator PIN auth**: `apps/pos/src/stores/operatorStore.ts`. `Operator` interface (`:23-46`) carries `roles: string[]`, `permissions: string[]`, `can_discount: boolean`, `can_apply_line_discounts`, `can_apply_transaction_discounts`, `max_discount_percent`, `discount_permissions_status`, `discount_permissions_refresh_error`, and `authority_stale?: boolean` (`:36-45`, true when the cached operator row is older than the offline TTL — set on the offline PIN-verify path from `operator_pins.synced_at`, per the roles.ts doc comment).

**Primary gating mechanism — server permissions, with a documented legacy fallback.** `apps/pos/src/lib/auth/roles.ts` (131 lines):
- `ROLE_LEVEL` (`:15-20`): `{cashier: 0, manager: 1, admin: 1, owner: 2}` — explicitly labeled "LEGACY role-name ladder, kept ONLY as the fallback for a cached operator row that carries no `permissions`" (`:1-14` doc comment, which itself states `owner` is not seeded — matches §4's finding — and that `viewer`/`technician`/`operator`/`accountant` ARE seeded but absent from this ladder, so any of those roles alone scores `0`).
- `hasManagerAccess(operator, surface)` (`:118-131`): fail-closed in order — (1) no operator ⇒ `false`; (2) `operator.authority_stale === true` ⇒ `false` (no fallback even to role name); (3) if `operator.permissions !== undefined`, check `permissions.includes(MANAGER_SURFACE_PERMISSION[surface])` (`:125-127`) — real seeded permissions `pos.view_reports` / `pos.approve_cash_drawer_control` / `pos.manage_terminals` (`MANAGER_SURFACE_PERMISSION`, `:57-61`); (4) only if `permissions` is `undefined` (old cache row, pre-permissions-field), fall back to `isManagerRole(operator.roles)` i.e. the legacy `ROLE_LEVEL` ladder (`:129-130`). An explicitly empty `permissions: []` is treated as a positive "holds nothing" answer, not missing data, and refuses (`:114-116` comment).

**Scoped manager-PIN override flow** (void/refund/discount/tender-tolerance overrides): `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts` (286 lines) and `scopedManagerPin.ts` (397 lines). `PosOverrideApprovalScope` (`posOverrideAuthoring.ts:7-21`) = `'discount_limit_override' | 'tender_tolerance_override' | 'void_or_return_override' | 'payout_dispute_evidence'`. `verifyScopedManagerPin` (`scopedManagerPin.ts:142+`) checks the **supervisor's** cached `approval_scope_permissions_fetched_at` for freshness (`:325`) and matches against the requested scope — i.e. void/discount/tender overrides require a second PIN entry from an operator who server-side holds the matching `pos.approve_*_override` permission, not a boolean flag or a hardcoded role check.

**Discount gating**: driven by `operator.can_discount` / `can_apply_line_discounts` / `can_apply_transaction_discounts` / `max_discount_percent` — boolean/numeric flags populated from `fetchDiscountPermissions()` (`operatorStore.ts:134,146-154`), i.e. server-computed booleans cached on the operator row, not a client-side role check.

**Other hardcoded role/flag references found:**
- `operatorStore.ts:354`: `user.roles.includes('super_admin') || user.roles.includes('admin')` — see §4 (dead for `'super_admin'` against tenant roles).
- `components/pos/ProductDetailDrawer.tsx:77`: `operator?.permissions?.includes('pos.view_cross_location_stock')` — direct permission-string check, a real seeded permission.
- Audit/event-log strings (`'pos.operator_signin'`, `'pos.sale_held'`, `'pos.manager_override_denied'`, etc., inventoried via `grep -rohE "'pos\.[a-zA-Z_.]+'"`) are telemetry/audit event *type* names, not permission gates — distinguished from real `pos.*` permission checks in the extraction above.

**Tests**: `apps/pos/src/lib/auth/roles.test.ts` — `describe('POS access hierarchy (legacy role-name fallback)', ...)`; `apps/pos/src/stores/__tests__/operatorStore.test.ts` and `operatorStore.audit.test.ts`.

---

## 8. Mobile app

**N/A — `apps/mobile` does not exist in this repository.** Confirmed via `find apps/erp -maxdepth 2 -iname "mobile*"` (no results) and `apps/erp/apps/` directory listing = `api`, `pos`, `test-results-local`, `web` only.

---

## 9. Generated types (`packages/shared/types`)

`packages/shared/types/generated.d.ts` (3211 lines) is the PHP-DTO-generated type file (per CLAUDE.md rule 7, `php artisan typescript:transform`). Relevant exports:
- `SuperAdminRole` (`:8`): `'super_admin' | 'support_approver' | 'defaults_editor'` — the central-admin role union (§4).
- `MembershipRole` (`:571`): `'owner' | 'admin' | 'manager' | 'accountant' | 'cashier' | 'technician' | 'viewer'` — the `company_users` per-company membership-role enum (`apps/api/app/Modules/Company/Domain/Enums/MembershipRole.php`), structurally distinct from Spatie `roles`/`permissions` despite sharing several literal string values.
- No generated `Permission` type and no generated union of the 304 seeded permission strings exists in `packages/shared/types` — that union is generated separately, directly into `apps/web/src/hooks/permissionsMap.generated.ts` (§1), not into the shared types package.
- No hand-rolled duplicate `Permission` union exists elsewhere in `apps/web/src` — `grep -rn "type Permission ="` across `apps/web/src` returns exactly the two expected declarations: `permissionsMap.generated.ts:312` (`export type Permission = keyof typeof PERMISSIONS`) and `usePermissions.ts:8` (`export type Permission = GeneratedPermission | UiAliasPermission`, the latter re-exporting/widening the former). No violation of "types flow from backend" (CLAUDE.md rule 7) found for the `Permission` type specifically — though `uiAliasPermissions.ts`'s existence is itself a small, deliberately-scoped, documented exception (its own header comment forbids extending it).

---

## 10. Tests covering permissions / RequirePermission / usePermissions / role UI

All under `apps/web/src` (file : `describe(...)` block found : one-line summary of what's asserted, from reading each test's `describe`/first `it`):

- `hooks/__tests__/usePermissions.authPayload.test.tsx` — `'usePermissions auth payload'` — asserts hook behavior against server-supplied auth payload shape.
- `hooks/__tests__/usePermissions.expenseRecurrences.test.ts` — `'expense recurrence frontend permission alignment'` — `it('grants full recurrence CRUD and export only to the normative financial roles', ...)`.
- `hooks/__tests__/usePermissions.expenses.test.ts` — `'expenses permission keys'` + `'expense-categories permission keys'` — permission-key alignment checks.
- `hooks/__tests__/usePermissions.moduleAccess.test.tsx` — `'canAccessModule fail-closed contract'` + `'MODULE_PERMISSIONS covers the keys the navigation actually uses'` — asserts the fail-closed behavior documented at `usePermissions.ts:230-238` and that every nav-referenced module key exists in `MODULE_PERMISSIONS`.
- `hooks/__tests__/usePermissions.ownerReports.test.ts` — `'ownerReports module permission'` — `it('maps to dashboard.owner', ...)`.
- `hooks/__tests__/usePermissions.replenishment.test.ts` — `'replenishment frontend permission alignment'` — `it('grants operator inventory view and maps direct child route permissions', ...)`.
- `hooks/__tests__/usePermissions.supplierInvoiceGates.test.tsx` — `'supplier-invoice / supplier-payment permission gates'`.
- `hooks/__tests__/usePermissions.treasuryReconciliation.test.ts` — `'treasury bank reconciliation permission alignment'` — includes `it('does not preserve the frontend-only treasury role fallback', ...)` (i.e. explicitly tests AGAINST reintroducing a hardcoded role fallback for this permission).
- `hooks/__tests__/usePermissions.uiAliases.test.tsx` — `'usePermissions UI aliases'` — covers the `UI_ALIAS_PERMISSIONS` mechanism.
- `features/auth/components/__tests__/RequirePermission.moduleKey.test.tsx` — `'RequirePermission moduleKey gating'`.
- `routes/ComplianceRoutePermissions.test.tsx` — `'compliance route permissions'`.
- `routes/__tests__/ReceiptPermissionParity.test.ts` — `'receipt reporting permission parity'` — `it('defines every exact POS child permission without fail-open aliases', ...)`.
- `features/admin/__tests__/adminRoleShell.test.tsx` — `'three-role admin shell'` — covers the central-admin `AdminRole` (`super_admin`/`support_approver`/`defaults_editor`) route policy.
- `features/settings/RolesPage.primitives.test.tsx` — `'RolesPage shared primitives'`.
- `features/settings/__tests__/RolesPage.tenantScope.test.tsx` — `'RolesPage tenant scope'` — covers the per-tenant/company query-key scoping described in §5.
- `apps/web/tools/__tests__/permission-map-drift-guard.test.mjs` — `'generated frontend permission map drift guard'` — asserts the CI/preflight regen+diff mechanism itself is present (§1).

POS (`apps/pos/src`):
- `lib/auth/roles.test.ts` — `'POS access hierarchy (legacy role-name fallback)'`.
- `stores/__tests__/operatorStore.test.ts` — `'operatorStore'`.
- `stores/__tests__/operatorStore.audit.test.ts` — operator-store audit-event coverage (not read in full).

---

## Top incongruences

1. **`services.*` permissions are entirely client-side fiction with zero server enforcement.** `apps/web/src/hooks/uiAliasPermissions.ts:8-10` defines `services.view`/`services.create`/`services.edit` as UI-only aliases; the seeder has no `services.*` permission whatsoever (confirmed absent from `permissionsMap.generated.ts`); `apps/api/app/Modules/Service/Presentation/routes.php:20-53` gates all six `/services` and `/service-categories` routes with only `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Workshop']` — **no `can:` middleware**. Frontend gates create/edit at `apps/web/src/routes/index.tsx:1580,1616` on these fictitious permissions, giving a false sense of RBAC while the API accepts the request from any authenticated Workshop-tenant user regardless of role.

2. **Two independent role/permission subsystems collide on shared string literals, producing dead or misleading code.** Seeded Spatie tenant roles are exactly `admin, manager, cashier, viewer, technician, operator, accountant` (`RolesAndPermissionsSeeder.php:582-871`, mirrored `permissionsMap.generated.ts`). Three other places hardcode role-name lists that don't match this set: (a) `apps/web/src/hooks/uiAliasPermissions.ts:3-12` references `sales, purchases, inventory, treasury, user` as roles (legacy pre-migration names, confirmed via `git log --follow`, origin commit `7ef795cbd`); (b) `apps/web/src/features/settings/RolesPage.tsx:35` and `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:228,277` both hardcode `SYSTEM_ROLES = ['super-admin', 'admin', 'owner']` — `super-admin` (hyphen) matches neither the seeded tenant roles nor the central `super_admin` (underscore) `AdminRole`, and `owner` is actually the name of a `MembershipRole` enum value (`packages/shared/types/generated.d.ts:571`), a different subsystem entirely; (c) `apps/pos/src/stores/operatorStore.ts:354` checks `user.roles.includes('super_admin')` against tenant-user roles, which can never be true.

3. **`Sidebar.tsx`'s nav-item field is named `permission` but is typed and resolved as a `ModuleKey`, not a `Permission`.** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:461` calls `canAccessModule(permission)` (a `ModuleKey`-keyed lookup into `MODULE_PERMISSIONS`), not `hasPermission`. Concretely e.g. `Sidebar.tsx:161-162` sets `permission: 'sales'`, which is not among the 304 seeded permissions (it resolves via `MODULE_PERMISSIONS.sales → ['sales.view']`, a `UI_ALIAS_PERMISSIONS` entry, `usePermissions.ts:57`). This is internally consistent (typed as `ModuleKey`, `usePermissions.ts:230-238`) but the field name is a naming inconsistency against `RequirePermission`'s same-named-but-differently-typed `permission` prop (`features/auth/components/RequirePermission.tsx:8`, which takes a real `Permission`).

4. **119/304 (39%) seeded permissions have no `permissions.modules.*` i18n label; 125/304 (41%) have no `permissions.actions.*` i18n label**, in all three locales (en/fr/ar) simultaneously (verified identical key-counts, 40 modules / 35 actions, across all three `common.json` files). Both fall back silently to the raw permission-name fragment (`RolesPage.tsx:33-39`) rather than erroring, so new permissions added to the seeder since roughly the mid-2026 `catalog_cart`/`pos.approve_*`/`scheduling.*`/`workshop.technicians.*` additions render unlocalized labels in the Roles UI permission matrix in every locale, including English (whose raw strings happen to be readable, masking the same gap in `fr`/`ar` where the fallback is equally raw-English).

5. **Four authenticated real pages have no RBAC gate at all** (any logged-in user of any role/company reaches them): `apps/web/src/routes/index.tsx:570` (`DashboardLanding` index), `:573-580` (`dashboard`), `:3203-3210` (`growth` index / `GrowthPage`), `:3211-3219` (`growth/modules` / `ProgressionModulesPage`). This may be intentional (dashboard/growth-hub landing pages meant to be universally visible), but it is not documented as such anywhere in the route file the way other deliberately-open surfaces are.

---

## Numbers table

| Metric | Count |
|---|---|
| Total `<Route` elements parsed in `apps/web/src/routes/index.tsx` | 298 (301 parsed blocks incl. 3 multi-line splits) |
| Routes with an explicit gate (`moduleKey=`/`permission=`/`permissions=[...]`/`ModuleGuard=`/`RequireAdminAuth`/`RequireAdminRole`) | 254 |
| Routes with `NONE` (no gate found in that Route's own block) | 47 |
| — of which: pre-auth public pages (login/register/verify-email/forgot-password/reset-password/privacy/terms/admin-login) | 8 |
| — of which: pure `<Navigate>` redirects or index-redirects with no independent page content | ~14 |
| — of which: parent container `<Route path="x">` with no `element` (children gated individually) | ~22 |
| — of which: **genuinely ungated real authenticated pages** | 4 (`dashboard` ×2 entries incl. index, `growth` index, `growth/modules`) |
| Distinct FE permission-shaped strings (`hasPermission`/`permission=`/`permissions=[...]`/`permission:` incl. Sidebar's ModuleKey-typed field) | 183 |
| FE strings absent from the 304 seeded permissions (strict) | 42 |
| FE strings absent from seeded permissions ∪ 10 UI aliases ∪ 56 module keys (i.e. truly undefined) | 0 |
| Seeded permissions (`permissionsMap.generated.ts`) | 304 |
| Seeded tenant roles | 7 (`admin, manager, cashier, viewer, technician, operator, accountant`) |
| `UI_ALIAS_PERMISSIONS` entries (legacy hand-maintained, non-backend) | 10 |
| `MODULE_PERMISSIONS` keys (nav/route module-key namespace) | 56 |
| Seeded permissions with NO `permissions.modules.*` i18n label (all 3 locales equally) | 119 / 304 |
| Seeded permissions with NO `permissions.actions.*` i18n label (all 3 locales equally) | 125 / 304 |
| `permissions.modules` / `permissions.actions` label counts per locale (en=fr=ar) | 40 / 35 |
