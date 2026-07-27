# Treasury Phase ⑤ Plans — tenancy-authz-reviewer (Opus) — 2026-07-18

Scope: plans ⑤a/⑤b permission/route/tenancy surface vs spec §7, verified against worktree code.

## Sound (no action)
- Permission naming (`instruments.clear-outbound`, `bank-statements.*`) consistent with existing hyphenated verbs/resources (`RolesAndPermissionsSeeder.php:475-706`; `expense-recurrences`, `credit-notes`, …).
- Module gating correctly absent — Treasury is in `default_modules` of every vertical (`config/verticals.php:30-351`), universal module.
- Company scoping of new aggregates present; `ScopedExists::tenant` correctly used for Wave-0 FK.

## Findings
1. **[HIGH]** Plans' literal middleware array `['api','auth:sanctum',SetPermissionsTeam::class]` omits `EnforceTokenTenantClaim` — the shipped Treasury group (`Presentation/routes.php:33`) includes it. Fix: new routes go INSIDE the existing `api/v1` group; per-route only `->middleware('can:…')`; never a parallel group.
2. **[HIGH]** No `permission:cache-reset`/reseed deploy note in either plan (tenant-blind Spatie cache bug + push=autodeploy ⇒ silent 403s). Fix: Deploy Notes block per plan + `treasury-phase5{a,b}-deploy-checklist.md` stacking on ③/④ owes.
3. **[HIGH]** Legacy `bank-reconciliations` FE surface not scoped: `apps/web/src/features/treasury/api/reconciliation.ts`, `BankReconciliationPage.tsx`, `FinanceHubPage.tsx:75` link, tests, types, i18n — backend cutover in Wave 4 while replacement UI lands in Wave 5 (ordering contradiction with spec §5.4). Fix: move route removal to Wave 5 after workspace ships; explicit FE deletion/repoint Files list; Vitest asserting FinanceHub card resolves.
4. **[MED-HIGH]** Role grants: "admin/owner" — no `owner` role exists (roles: admin/manager/cashier/viewer/technician/operator/accountant). Inbound analogs granted to `admin`+`accountant` (seeder 476-482, 700-706). ⑤b grants unspecified. Fix: `instruments.clear-outbound` + `bank-statements.{view,import,reconcile}` → `admin`+`accountant`; `bank-statements.reopen` → admin only; deny-path tests with non-privileged role.
5. **[MED]** One permission bundling clear+bounce+represent+cancel breaks least-privilege parity with inbound split (`instruments.clear/bounce/cancel`). Fix: split `instruments.cancel-outbound` or reuse existing inbound grant names; at minimum justify.
6. **[MED]** `{id}` params vs sibling `{instrument}` route-model binding — malformed UUID → PG 500 (memory pitfall). Fix: route-model binding or `Str::isUuid` guards, both plans' controllers.
7. **[MED]** `ScopedExists::tenant` not mandated on ⑤b `payment_repository_id`/`parser_profile_id` FormRequest inputs (cross-company reference risk within tenant). Fix: mandate + company-scoping test.
8. **[MED]** Stale path anchor: `routes.php` is at `app/Modules/Treasury/Presentation/routes.php` (4 places wrong; `:249-279` range itself correct).

## Verdicts
- **Plan ⑤a: APPROVE-WITH-FIXES** (must-fix: 1, 2, 4, 6; should-fix: 5, 8)
- **Plan ⑤b: APPROVE-WITH-FIXES** (must-fix: 1, 2, 3, 4, 7; should-fix: 6, 8)
