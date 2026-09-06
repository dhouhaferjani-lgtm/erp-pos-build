# Gate r2 — PR #210 "fix(authz): gate supplier-invoice posting behind a dedicated permission (F-W2-14)" — after fix round 1

- **Gate verdict: MERGE-WITH-FIXES**
- **Reviewed**: worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-210`, branch `gate/pr-210`, `git diff dev...HEAD` = 33 files, +1638/−48. Merge-base `fa000edc39c5e2c060748db534ff0a22ed1e31a8`. Fix-round commits `e55ddbb0a`, `ad0292ef8`, `b5421aef5`.
- **Inputs**: gate r1 `docs/superpowers/reviews/2026-09-05-dhouha-pr-210-gate-r1.md` (findings 1–10), handback `docs/superpowers/reviews/2026-09-07-dhouha-pr-210-fix-round-1-handback.md`.
- **Owner ruling under test (2026-09-07, binding)**: option (a) — `operator` denied by default; every such permission must remain **grantable to a specific role or user through the existing permissions surface**; **no role-name checks anywhere**.
- **Reviewer**: tenancy-authz-reviewer. Gate only — nothing merged, nothing committed. Two throwaway probe files were created, run, and deleted; `git status --porcelain` in the worktree is **empty**.

---

## Summary

The security substance of F-W2-14 is now **genuinely closed on all three halves**, and I re-derived it myself rather than trusting the handback: I wrote four adversarial probes against the payment gate (including two deliberate bypass attempts) and they all failed to get through. Route middleware, policy placement, seeder grants, generator byte-identity and the deptrac story all check out.

One **MAJOR** defect remains, and it is the one that touches the owner ruling directly: the frontend **module gate** on the supplier-invoice routes is a **role-name check** that sits in front of the new permission gate, so a user who is granted `supplier-invoices.manage` exactly as the ruling requires — and the `accountant` this PR's own seeder grants — **cannot reach any supplier-invoice page in the product**. It fails closed (no security exposure), but it makes the ruling's grantability API-only, and the PR's FE grantability test does not catch it because it renders the page component **outside** the route guard.

---

## Findings

### 1. [MAJOR] The FE module gate is a role-name check that makes the owner ruling's grantability unreachable, and locks the seeded `accountant` out of the whole surface

All three supplier-invoice routes gate on `moduleKey="purchases"`:
- `apps/web/src/routes/index.tsx:1034` (list), `:1050` (create, alongside the new `permission="supplier-invoices.manage"`), `:1060` (detail).
- `apps/web/src/features/auth/components/RequirePermission.tsx:51-52` evaluates the **module gate first**; `:56` only then looks at `permission`. A module denial short-circuits the permission entirely.
- `apps/web/src/hooks/usePermissions.ts:58` — `MODULE_PERMISSIONS.purchases = ['purchases.view']`; `:191-199` `canAccessModule` = `hasAnyPermission(['purchases.view'])`.
- `apps/web/src/hooks/uiAliasPermissions.ts:6` — `'purchases.view': ['admin', 'purchases', 'manager']`, and the file's own header says *"UI grouping gates — NOT backend authorization"*. `purchases.view` is **not** in the generated backend map (`grep -n "'purchases\." apps/web/src/hooks/permissionsMap.generated.ts` → no output), so it can never arrive in the server permission list and the alias resolves **purely by role name**.

**Measured** (throwaway vitest probe against the real hook + `useAuthStore`, since deleted):
```
PROBE operator   (roles ['operator'],   permissions [... 'supplier-invoices.manage','payments.pay-supplier'])
   manage= true   module purchases= false
PROBE accountant (roles ['accountant'], permissions [... 'supplier-invoices.manage','payments.pay-supplier'])
   manage= true   module purchases= false
```
Consequences, both against the ruling:
- An `operator` granted `supplier-invoices.manage` through the permissions surface (the exact scenario the ruling protects, and the scenario `SupplierInvoiceApiTest::test_operator_granted_supplier_invoices_manage_directly_can_post` proves at the API) is redirected to `/dashboard` from **list, detail and create** alike. The grant buys nothing usable in the web app.
- `accountant` — which this PR explicitly grants `supplier-invoices.manage` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:829`) and `payments.pay-supplier` (`:836`) — is likewise shut out of the entire supplier-invoice UI. This is precisely the harm gate r1 finding 4 named ("denied accountants a page whose API call they are explicitly authorised to make"); the fix round moved the CREATE route off the `purchases.create` alias but kept `moduleKey="purchases"`, which reproduces the same role-name denial one layer up.
- The module gate has **no backend twin** to justify it: the Procurement route group is `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` with **no `module:` middleware** (`apps/api/app/Modules/Procurement/Presentation/routes.php:76-81`), and `Procurement`/`purchases` is not a `ModuleName` in `apps/api/config/verticals.php`. So this is not rule-12 both-layer gating; it is a UI role alias with no server counterpart.

**Test-quality corollary (same finding):** `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx:337` — *"honours a direct server grant to an operator (owner ruling: grantable)"* renders `<SupplierInvoiceListPage />` **directly**, bypassing the route's `RequirePermission` wrapper. It passes while the real route denies that same operator. This is a green test asserting the opposite of production behaviour.

**Exact fix** (small, and it is the one that matches the backend):
- `apps/web/src/routes/index.tsx:1034` and `:1060` → `<RequirePermission permission="documents.view">` (the real backend gate on `GET /supplier-invoices` and `GET /supplier-invoices/{id}`, `apps/api/app/Modules/Procurement/Presentation/routes.php:84 and :94`).
- `:1050` → `<RequirePermission permission="supplier-invoices.manage">` (drop `moduleKey`).
- Add a route-level FE test that mounts the routes through `RequirePermission` and asserts a granted `operator` and an `accountant` reach the page, and a `cashier` does not.
- If the owner instead wants the `purchases` module gate kept, then `purchases.view` must become a **real seeded permission** granted to admin/manager/accountant (and grantable), not a role alias — otherwise "grantable through the permissions surface" cannot be honoured on this surface.

### 2. [MINOR] The deliberate `PartnerType::Both` exclusion is safe today but is not pinned by any test

`apps/api/app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php:99-109` returns supplier-side **only** for `PartnerType::Supplier`; `Both` is excluded by design (`:33-37`). I attacked exactly this seam.

**Measured** (throwaway PHPUnit probe, sqlite, since deleted): cashier → `POST /api/v1/payments` with a `Both`-typed partner, no allocations, 300.00:
```
PROBE A status=201  balance 0.000 -> 300.000  movements=[{"direction":"in","amount":300}]
```
So no bypass exists: money moved **IN**. That is structural — `store()` sets `$isSupplierPayment` only from a `DocumentType::SupplierInvoice` allocation (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:507,571`) and the movement direction keys on that flag (`:1396`); `PaymentType::SupplierPayment` is written at exactly one place in the whole app (`:979`, confirmed by `grep -rn "PaymentType::SupplierPayment" app/`). But the exclusion is only safe *because* of that coupling. If direction ever becomes partner-derived, the `Both` arm becomes a live hole with nothing failing.

**Fix**: add to `tests/Feature/Treasury/SupplierPaymentPermissionTest.php` a case pinning cashier + `Both` partner + no document → 201 **and** the resulting `repository_movements.direction === 'in'`.

### 3. [MINOR] Arm 2 over-denies a cashier's money-IN receipt from a pure `supplier` partner — behaviour change, undocumented

`SupplierPaymentAuthorizer.php:99-109` refuses a cashier **any** document-less payment naming a `supplier`-typed partner. Such a payment would have been recorded as an inbound receipt (`PaymentController.php:1396`, `$isSupplierPayment` false without a supplier-invoice allocation), i.e. money coming IN. Secure and defensible, but it is a live behaviour change for tills that take cash back from a supplier, and it is neither tested nor listed in the promotion smoke steps. **Fix**: one line in the promotion row + a note in the release notes, or an explicit test recording the intent.

### 4. [MINOR] The idempotency replay short-circuit runs BEFORE the new gate

`PaymentController.php:388-397` (`store`) and `:1472-1478` (`storeMultiple`) return a previously created payment on a matching `Idempotency-Key` / `idempotency_key` **before** validation and before `assertMayPay`.

**Measured** (probe D): a manager creates a supplier payment with key `PROBE-KEY-1` (201); a cashier then posts `{"idempotency_key":"PROBE-KEY-1"}` and receives **200 with the full supplier-payment payload**. No write, no movement, and `cashier` holds `payments.view` anyway, so the blast radius today is nil — but the replay is a read path that skips the gate the PR just added. **Fix (optional, record either way)**: re-run `assertMayPay` against the replayed payment's partner/allocations before returning it.

### 5. [MINOR, pre-existing but now on the PR's own surface] Attachment endpoints bind a raw route param into a `uuid` column

`apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:192-207` does `->where('id', $documentId)` with no `Str::isUuid()` guard, and `apps/api/app/Modules/Media/routes.php:19-41` puts no `whereUuid` on `{document}`. `documents.id` is `uuid` (`apps/api/database/migrations/tenant/2025_11_30_080000_create_documents_table.php:14`). A non-UUID id 500s on PostgreSQL (passes under sqlite). Not introduced here — but the PR adds the new `authorizeAttach()` call on this very path (`:65`, `:137`, `:163`), so it belongs in the residual list. **Fix**: `->whereUuid('document')` on the Media route prefix, or `Str::isUuid()` + 404 in `resolveDocument()`. Not re-measured on PG in this gate.

### 6. [MINOR / OWNER CONFIRM] PO revert is manager-tier only — `accountant` is pinned as denied

The ruling's parenthetical names "admin/manager/accountant/purchases-type roles" as the default holders. In the shipped shape `purchase-orders.confirm` is manager+admin only, and the new test **pins** the accountant denial: `apps/api/tests/Feature/Permissions/P2pEntryPointPermissionsTest.php`, `test_purchase_order_revert_permission_is_manager_tier_not_cashier_tier` (asserts `viewer`, `cashier`, `operator`, **`accountant`** all lack it). This is a defensible narrowing (an accountant should not un-commit a supplier commitment) and it stays grantable, but it is a deliberate deviation from the ruling's letter and needs one line of owner confirmation rather than passing silently.

### 7. [INFO] Verified correct — the list I checked and could not break

- **Finding 1(a), PO revert — CLOSED.** `apps/api/app/Policies/DocumentPolicy.php:155-166`: company-isolation check first, then `PurchaseOrder => can('purchase-orders.confirm')`, `default => can('documents.update')`. `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:241` takes `Gate::forUser(...)->authorize('revert', $documentModel)` **after** the tenant+company-scoped 404 lookup (`:219-232`) and **before** `documentPostingService->revert()` (`:244`) — cross-company stays 404, and a denial cannot mutate state. Policy is registered (`apps/api/app/Providers/AppServiceProvider.php:275`). `revertPurchaseOrder` has exactly one caller (`DocumentPostingService.php:533`, reached only from `DocumentController::revert`), so there is no second door. Route keeps `can:documents.update` + `whereUuid('document')` (`apps/api/app/Modules/Document/Presentation/routes.php:84-87`).
- **Finding 1(b), supplier payment — CLOSED, and I could not construct a bypass.** Gate runs after `$request->validate(...)` and before the first write in both arms (`PaymentController.php:449-462` store, `:1520-1535` storeMultiple). Probes: (B) cashier via the multi-tender arm naming a posted supplier invoice → **403**, no rows; (C) cashier allocating to a **confirmed purchase order** → **403** (arm 1 catches it via `DocumentType::isSupplierSettlement()`, `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:134`); (A) the `Both`-partner evasion → allowed but **inbound** (finding 2). Allocation ids are only read as strings from already-`uuid`+`ScopedExists`-validated input (`PaymentController.php:98-110`), so no unvalidated value reaches a uuid column.
- **Grantability at the API — proven.** Three real-route tests, all green here: `SupplierInvoiceApiTest::test_operator_granted_supplier_invoices_manage_directly_can_post`, `SupplierPaymentPermissionTest::test_operator_granted_the_permission_directly_can_pay_a_supplier_invoice`, `DocumentRevertEndpointTest::test_directly_granted_purchase_order_confirm_permission_allows_revert`. No role name is read anywhere in the backend gates (policy + authorizer both `can()`/`Gate::authorize` only).
- **Permissions surface.** `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:317` returns `Permission::all()` grouped by prefix; `:331-336` filters only groups present in `PERMISSION_GROUP_MODULE` (`:35-46` — `vehicles`, `workshop*`, `work-orders`, `menus`, `modifier-groups`, `composite-items`, `scheduling`, `batches`, `loyalty`). Neither `supplier-invoices` nor `payments` is listed → both new permissions are always returned, with no module filter, on every tenant.
- **Seeder grants are exactly what the handback claims.** Catalogue: `RolesAndPermissionsSeeder.php:158` (`supplier-invoices.manage`), `:260` (`payments.pay-supplier`). Manager `:595`, `:609`; accountant `:829`, `:836`; admin via `Permission::all()`. Not granted to cashier/operator/viewer/technician. `purchase-orders.confirm` is manager-only (existing). Every new permission is granted to at least one role — no "seeded but ungranted → 403 for everyone" trap.
- **FE fails closed on a stale tenant.** `apps/web/src/hooks/usePermissions.ts:39-40` adds both permissions to `SERVER_AUTHORITATIVE_PERMISSIONS`; `:155-160` returns the server answer first (so a direct grant is honoured) and returns `false` for a listed permission the server did not send (so a stale-tenant manager sees no control). Pinned by `usePermissions.supplierInvoiceGates.test.tsx` (4 tests, real hook + real store, deny path included).
- **Both-layer surfaces.** Detail page: Post/Re-match behind `canManageSupplierInvoice` (`SupplierInvoiceDetailPage.tsx:97, 380, 396`); "Pay in Treasury" link **and** "Record payment" behind `canCreatePayments = payments.create && payments.pay-supplier` (`:94, 407`). List "New" link behind `hasPermission('supplier-invoices.manage')` (`SupplierInvoiceListPage.tsx:200`). No other FE caller of `POST /payments` offers a supplier path un-gated: `apps/web/src/features/treasury/PaymentForm.tsx:772` has a `?supplier_invoice=` mode (`:280, 400-407, 665-669`) whose only in-app entry point is the gated detail-page link, and its route `/treasury/payments/new` is `permission="treasury.create"` (`apps/web/src/routes/index.tsx:1859`), an alias excluding cashier/operator (`uiAliasPermissions.ts:11`). A deep-linked stale-tenant manager could still open that form and collect a 403 — noted, not a security hole; folding it into finding 1's fix is the cheap option.
- **Generators byte-identical.** Re-ran both myself: `CACHE_STORE=array php artisan permissions:export-frontend-map` and `node scripts/factory/gen-route-manifest.mjs` → `git status --porcelain` **empty**, `git diff --stat` **empty**.
- **Rule 12 / tenancy.** Treasury group `apps/api/app/Modules/Treasury/Presentation/routes.php:35` and Media group `apps/api/app/Modules/Media/routes.php:19` both carry `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`. Every new query is tenant+company scoped (`SupplierPaymentAuthorizer.php:78-81, 103-107`); the two new policy abilities check `company_id` against `CompanyContext` first (`DocumentPolicy.php:158, 184`). Spatie teams are keyed on `tenant_id` (`apps/api/config/permission.php` `team_foreign_key`), so second-company behaviour inside a tenant is unchanged — rule 22 second-of-everything is **N/A** (no table, no unique key, no catalogue entity), as the handback states.
- **No `app()` in new app code** (`git diff dev...HEAD -- 'apps/api/app/**' | grep '^+.*app('` → empty); `declare(strict_types=1)` present in the new service; `SupplierPaymentAuthorizer` is constructor-injected (`PaymentController.php:79`).
- **No money/quantity float, no i18n string, no new query key, no `latestOfMany`, no queue/job code** in the diff. Rules 19/11/14 not engaged.
- **Findings 5–8 of r1**: 5 CLOSED (`DocumentPolicy::attach()` `:181-193` + `DocumentAttachmentController.php:65,137,163`, with allow **and** deny tests); 6 CLOSED (`docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md:30,33,36,39` + payment note); 7 CLOSED (`P2pEntryPointPermissionsTest` `$permissions` now carries both new names + the new PO-revert test); 8 CLOSED (correction recorded in the handback, both engines).

---

## Commands run and result lines

Swap checked first (`sysctl vm.swapusage` → used 8945M/10240M); runs kept to single files. **The full suite was never run.**

**Backend — sqlite (`apps/api`, `CACHE_STORE=array ./vendor/bin/phpunit <path>`)**
```
tests/Feature/Document/DocumentRevertEndpointTest.php + tests/Feature/Treasury/SupplierPaymentPermissionTest.php
  OK (13 tests, 35 assertions)

tests/Feature/Permissions/P2pEntryPointPermissionsTest.php + tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php
  OK, but there were issues!  Tests: 21, Assertions: 127, PHPUnit Deprecations: 14   (0 failures)

tests/Feature/Procurement/SupplierInvoiceApiTest.php --filter '(operator_granted|operator_cannot_post|cashier_cannot|seeded_roles_grant)'
  OK (6 tests, 20 assertions)
```

**Backend — my own adversarial probes** (`tests/Feature/Treasury/ZGateProbeR2Test.php`, written, run, **deleted**)
```
PROBE A (cashier, Both partner, no document)      status=201  balance 0.000 -> 300.000  movements=[{"direction":"in","amount":300}]
PROBE B (cashier, storeMultiple, supplier invoice) status=403  {"error":{"code":"FORBIDDEN",...}}   no rows
PROBE C (cashier, allocation to a confirmed PO)    status=403  {"error":{"code":"FORBIDDEN",...}}   no rows
PROBE D (manager creates key; cashier replays it)  first=201, replay-by-cashier=200 (full payload, no write)
```

**Frontend (`apps/web`, `./node_modules/.bin/vitest run <paths>`)**
```
usePermissions.supplierInvoiceGates.test.tsx + SupplierInvoiceListPage.test.tsx + SupplierInvoiceDetailPage.test.tsx
  Test Files 3 passed (3)   Tests 45 passed (45)

PROBE (module gate vs direct grant; written, run, deleted)
  PROBE operator:   manage= true   module purchases= false
  PROBE accountant: manage= true   module purchases= false
```

**Generators / static analysis**
```
$ (apps/api) CACHE_STORE=array php artisan permissions:export-frontend-map   -> Exported …
$ (repo)     node scripts/factory/gen-route-manifest.mjs                     -> wrote routes-web.yaml (271 routes)
$ git status --porcelain    -> (empty)
$ git diff --stat           -> (empty)          # both committed artefacts ARE the generator output

$ ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php \
    app/Policies/DocumentPolicy.php --memory-limit=1G --no-progress
  [OK] No errors      (level 8, live env)
```

**Deptrac — item 7, exactly what I verified**
```
$ (apps/api) php tools/deptrac-ratchet.php
  Deptrac report: 183 violations, 14732 allowed, 13814 uncovered.
  ModuleApplication on ModuleInfrastructure   68 -> 68  held
  ModuleDomain on ModuleApplication           54 -> 53  improved (-1)
  ModuleInfrastructure on ModulePresentation   1 ->  1  held
  SharedContracts on ModuleApplication        18 -> 18  held
  SharedContracts on ModuleDomain             36 -> 37  RATCHET (+1)
  SharedDomain on ModuleDomain                 4 ->  4  held
  SharedInfrastructure on ModuleDomain         2 ->  2  held
  TOTAL                                      183    183
  RESULT: FAIL
```
I did **not** build a merged worktree (vendor cost on this laptop). What I verified instead, and it is decisive:
1. `git diff --stat dev...HEAD -- apps/api/deptrac.baseline.json` → **empty**: the branch does not touch the baseline, so a merge takes `dev`'s file with no conflict.
2. `git diff HEAD dev -- apps/api/deptrac.baseline.json` shows `dev` carries the 2026-09-05 reconciliation: `ModuleDomain on ModuleApplication` 54→**53**, `SharedContracts on ModuleDomain` 36→**37**, plus the `ceiling_note`.
3. The **computed** numbers on this branch are `68 / 53 / 1 / 18 / 37 / 4 / 2` (TOTAL 183) — **identical, category by category, to `dev`'s reconciled baseline**.
Therefore the PR's code adds **zero** new boundary violations, and the ratchet passes the moment the branch sits on current `dev`. The implementer's claim is confirmed.

**Promotion checklist (item 6) — correct.** `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3 row carries `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` then `php artisan permission:cache-reset`, the "gate on printed output, `tenants:*` exit 0 regardless" warning, the `SYNC_PERMISSIONS_ON_BOOT` confirmation step, a five-point post-deploy smoke, and the `syncPermissions` release-note caveat. Every citation in it checks out: `apps/api/docker/entrypoint.sh:156-171` (opt-in seed) and `:176` (`permission:cache-reset`, unconditional), `apps/api/.env.example:178` (`SYNC_PERMISSIONS_ON_BOOT=false`).

---

## What I could NOT verify

- **No browser/Playwright run.** Finding 1 is proven at the hook level by a probe, not by a click-through. Nobody has driven this tree as a cashier / manager / granted operator / accountant.
- **No staging or production deploy**; the `SYNC_PERMISSIONS_ON_BOOT` claim rests on `entrypoint.sh`, not on a run.
- **Full PHPUnit suite / whole `pnpm lint` / PostgreSQL leg not run** (laptop constraint + standing rule). The five PG failures the handback describes in `SupplierInvoiceApiTest` (3 × invoice-first 422 + 2 × `locations.code` truncation) were **not** re-measured here; r1 verified them identical on unmodified `dev`. A regression outside the files listed above is unmeasured.
- **Finding 5's PG 500** is a code-grounded inference (uuid column + unguarded bind), not an executed PG request.

---

## Merge recommendation

**MERGE-WITH-FIXES.** The three F-W2-14 holes are closed, gates sit before every write, tenant/company scoping is intact, generators are byte-identical and deptrac clears on merge. Land it on `dev` — but **finding 1 must land before F-W2-14 is reported closed to the owner**, because as shipped the ruling's "grantable to a specific role or user" is true only at the API: in the product, a granted operator and the seeded accountant still cannot open a single supplier-invoice page, and the FE test that claims otherwise renders outside the route guard. Findings 2, 3 and 6 are one-liners (a test, a checklist line, an owner confirmation); findings 4 and 5 can be ticketed.

## Owner-facing summary — what F-W2-14 now closes

A cashier can no longer: create, re-match or post a supplier invoice (also via the ingestion commit back door), attach or delete a file on a supplier invoice, un-confirm a purchase order, or send money to a supplier — the last one verified with no payment row, no allocation, no journal entry and no cash movement. A cashier's customer payments are untouched. These capabilities default to admin/manager/accountant (PO un-confirm: admin/manager only), and any of them can be granted to any other role or to an individual user from Settings → Roles, with no role names hard-coded anywhere in the backend. **Caveat before this is called done:** the supplier-invoice *screens* are still hidden behind an old role-name-based "purchases" UI gate, so an accountant — or an operator you deliberately grant the permission to — is authorised by the API but still cannot open those screens. Deploy step owed on every existing tenant: re-run the roles/permissions seeder plus a permission cache reset, or the new permissions do not exist there.
