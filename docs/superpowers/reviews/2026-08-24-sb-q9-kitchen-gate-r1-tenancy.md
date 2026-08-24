# Session B — Lane Q-9 (F1 kitchen/order module gating + terminal-state guard) — GATE r1, TENANCY/AUTHZ LENS (primary)

- **Date:** 2026-08-24
- **Reviewer:** tenancy-authz-reviewer (round 1, PRIMARY half of the dual gate; fiscal-pos-reviewer runs the second half)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q9-kitchen-gating`
- **Branch / commit:** `fix/sb-q9-kitchen-order-gating` @ `020d0ab55` on base `fc3330ad4` (single commit; 12 files, +469/-26)
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q9-kitchen-gating.md`
- **Migrations:** none. **New `can:` permissions:** none (no seeder re-sync required — see §Permission catalog).

---

## Verdict

**VERDICT: spec ✅ + quality CHANGES-REQUESTED**

All three brief parts (a backend gate, b FE mirror, c terminal-state guard) are delivered and independently
verified. The production code is sound on the tenancy/authz lens: no cross-tenant path, no central/tenant
connection error, no silent-403 permission gap, no new migration. **One Important test-integrity defect**
(C-1) blocks a clean ACCEPT: the `PosStabilizationTenantIsolationTest` fixture now models a tenant
configuration the product itself forbids and that `tenant:reconcile-modules` would destroy, which makes the
suite's Loyalty cross-tenant pins latently red. The fix is one enum case.

### Conditions to clear before merge

- **C-1 (blocking, Important).** `tests/Feature/POS/PosStabilizationTenantIsolationTest.php:218` — change
  `Vertical::Restaurant` to `Vertical::CoffeeShop` and correct the comment at :204-211. Rationale + proof in
  Finding 1. `coffee_shop` + `['Loyalty','Inventory']` is BOTH product-valid AND a strict module superset of
  the previous `retail` + `['Loyalty']`; `restaurant` + `Loyalty` is neither.
- **C-2 (non-blocking, must be TICKETED not fixed here).** The `module:Tables` backend gate the brief assumed
  already exists does **not** exist (Finding 2). File the follow-up lane before this cluster is called done.
- **C-3 (non-blocking, ticket).** 5 PRE-EXISTING PostgreSQL errors in
  `PosStabilizationTenantIsolationTest` (`shift_number` string into an integer column) sit on exactly the 5
  order/kitchen cross-tenant probes this lane's gate now fronts — see Finding 3.

---

## Findings

### 1. [IMPORTANT] `apps/api/tests/Feature/POS/PosStabilizationTenantIsolationTest.php:218` — the new fixture is a product-INVALID tenant; its Loyalty isolation pins survive only on an unfiltered merge

**What's wrong.** The fixture became:

```php
'vertical' => Vertical::Restaurant,
'enabled_extras' => ['Loyalty', 'Inventory'],
```

`config/verticals.php` `restaurant.compatible_extras = ['Tables', 'Reservation', 'Inventory']` — **`Loyalty` is
not a compatible extra for `restaurant`.** `app/Modules/Tenant/Application/Commands/ReconcileModulesCommand.php:40-48`
(`tenant:reconcile-modules`) computes `array_intersect($enabledExtras, compatible \ defaults)` and force-saves
the pruned list, i.e. it would **strip `Loyalty` from this tenant**. The super-admin write path
(`SuperAdminController.php:302-332`) validates compatibility for the same reason.

**Why it matters.** The suite's Group-4 probes at :1054, :1066, :1084, :1102 POST to `/api/v1/loyalty/pos/*`,
which lives inside the `module:Loyalty` group at `app/Modules/Loyalty/Presentation/routes.php:27`. Those
cross-tenant isolation assertions stay green **only** because `app/Services/CompanyConfigService.php:70`
merges `default_modules ∪ enabled_extras` with **no filter against `compatible_extras`**. The lane has
therefore pinned four tenant-isolation regressions on a configuration that (a) cannot be produced through the
product, and (b) one `tenant:reconcile-modules` run — or any future decision to make `hasModule` respect
`compatible_extras` — turns into 403s. The in-file comment at :209-211 ("`restaurant` is a strict module
superset of `retail` here") is true for the module SET but silently omits that the Loyalty half of it is an
incompatible-extra accident.

**Suggested fix.** `Vertical::CoffeeShop`. `coffee_shop.default_modules` contains `Menu` (so the gate is
satisfied) and `coffee_shop.compatible_extras = ['Tables','Loyalty','Inventory']` — so `['Loyalty','Inventory']`
is a legal, reconcile-stable extras set, and the resulting module set is still a strict superset of the old
retail fixture (see §Module-set computation). Reword the comment to state coffee_shop's compatibility rather
than asserting a superset relation that does not survive reconciliation.

### 2. [IMPORTANT — pre-existing, adjacent, DO NOT FIX IN THIS LANE] `apps/api/app/Modules/POS/routes_tables.php:15` — no `module:Tables` gate exists anywhere in the backend

The brief states "Tables-specific routes keep their existing `module:Tables`". **There is no such gate.**
A grep of every route file and service provider yields exactly these module gates:
`BatchExpiry`, `CompositeItems`, `Ecommerce`, `Inventory` ×2, `Loyalty`, `Marketplace` ×2, `Menu` ×4 (this
lane), `POS`, `Parapharmacy`, `Procurement`, `Sales` ×3, `Vehicle`, `Workshop` ×5 — **no `Tables`**.
`routes_tables.php:15` carries only `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`,
while the web layer gates the same surface on both layers-of-one: `apps/web/src/routes/index.tsx:2988`
(`<ModuleGuard module="Tables">`) and `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:237`
(`module: 'Tables'`). So `/api/v1/pos/floors*` and `/api/v1/pos/tables*` are still a rule-12 one-layer surface
reachable by any `pos.manage_tables` holder on parapharmacy/retail. This is the exact sibling of the hole
Q-9 just closed and is Dhouha P1 #4 territory — out of this lane's scope, but the record should not let the
brief's false premise stand unchallenged. Ticket it.

### 3. [MINOR] 5 PostgreSQL errors in `PosStabilizationTenantIsolationTest` — confirmed PRE-EXISTING, but they blind the PG evidence for exactly the probes this gate fronts

Ran the suite against a throwaway PG 16 database (`q9_review_tmp` on 127.0.0.1:5433, dropped after the run):
`Tests: 49, Assertions: 154, Errors: 5, Skipped: 8`. All 5 errors are the same fixture bug — `shift_number`
bound as `'SHIFT-B-xxxx'` into an integer column (`tests/…/PosStabilizationTenantIsolationTest.php:1575`,
`:1722`). **Pre-existing confirmed by construction:** the lane's diff for that file contains zero
`shift_number` lines (`git diff 020d0ab55^ 020d0ab55 -- <file> | grep -c shift_number` → `0`), and the failure
is a column-type mismatch that no vertical change can cause. The implementer's claim is accepted.

The sting: the 5 affected tests are `test_cancel_order_refuses_cross_tenant_order_id`,
`test_send_to_kitchen_refuses_cross_tenant_order_id`, `test_remove_line_refuses_cross_tenant_order_id`,
`test_kitchen_bump_refuses_cross_tenant_order_id`, `test_cancel_order_lookup_includes_tenant_and_company_predicates`
— i.e. precisely the order/kitchen cross-tenant probes that now sit BEHIND the new `module:Menu` gate. On
PostgreSQL they prove nothing today; the "the gate did not break cross-tenant discrimination" evidence for
those five is **sqlite-only**. The other cross-tenant order probes (`test_create_order_refuses_cross_tenant_terminal_id`
:645, `test_create_order_refuses_cross_tenant_partner_id` :655, `test_add_order_line_refuses_cross_tenant_product_id`
:608) do run and do discriminate — they use `assertApiValidationErrors` (`tests/Traits/AssertsApiValidation.php:33-51`),
which asserts 422 **and** the specific field key, so a 403 from the gate would fail them. Ticket the
`shift_number` fix on the isolation suite.

### 4. [MINOR] `apps/web/src/routes/routes.test.tsx:72-86` — the FE deny-path is a source-string grep, while the behavioral idiom exists 2 lines away

The new case reads `routes/index.tsx` as text and asserts `<ModuleGuard module="Menu">` appears in the 400
characters preceding `<OrdersPage />`, plus a string check on the Sidebar line. It mirrors the existing kitchen
case at :60-70, so it is idiomatic — but a genuine render-level deny test already exists at
`apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx:603-616`
("hides Tables and Kitchen for a retail vertical" / "shows Tables and Kitchen for a restaurant vertical") and
was **not** extended to `posOrders`. One added `queryByRole('link', { name: /navigation\.posOrders/i })`
assertion in each of those two cases would convert the FE half from "the string is in the file" to "the user
does not see the link". Cheap; recommend.

### 5. [MINOR] `apps/api/tests/Feature/Security/PosOrderKitchenModuleAccessControlTest.php` — module-deny is pinned, permission-deny is not

Every test grants `admin`. The class proves the MODULE deny path (parapharmacy → 403 on all 12 live routes)
but never a Menu-vertical user LACKING `pos.operate_terminal`. The permission IS enforced —
`OrderController.php:44,95,118,162,226,278,327,367,404` and `KitchenDisplayController.php:31,56,95,125` all call
`Gate::authorize('pos.operate_terminal')` — so this is a coverage note, not a hole. Worth one added case since
the class is already the security-lane home for this cluster.

### 6. [MINOR — informational, for Session A collision hygiene] Owner-sheet §E announcement vs. actual FE footprint

`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:94` announced "nothing else in `apps/web`" beyond
`routes/index.tsx` and `Sidebar.tsx`, and named the Sidebar path as `apps/web/src/components/layout/Sidebar.tsx`.
Actuals: the Sidebar is `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` (1 line changed, :236), and
`apps/web/src/routes/routes.test.tsx` (+17 lines) was also touched. Both edits are minimal and additive; the
FE footprint is 3 files + the regenerated manifest. Session A's `fix/campaign-fe-contract` should expect a
trivial merge, as announced.

---

## Gate verified

### Rule 12 — backend layer (probe evidence)

`php artisan route:list --json` from the worktree, filtered to `pos/(orders|kitchen)`. **12 of 13 routes carry
`App\Http\Middleware\RequireModule:Menu`; the only ungated one is the §14.2 tombstone**, exactly as claimed:

| Method | URI | `RequireModule:Menu` |
|---|---|---|
| GET | `api/v1/pos/kitchen/orders` | ✅ |
| PATCH | `api/v1/pos/kitchen/orders/{orderId}/lines/{lineId}/status` | ✅ |
| POST | `api/v1/pos/kitchen/orders/{orderId}/bump` | ✅ |
| POST | `api/v1/pos/orders/{orderId}/served` | ✅ |
| POST | `api/v1/pos/orders` | ✅ |
| GET | `api/v1/pos/orders` | ✅ |
| GET | `api/v1/pos/orders/{id}` | ✅ |
| POST | `api/v1/pos/orders/{id}/lines` | ✅ |
| PATCH | `api/v1/pos/orders/{id}/lines/{lineId}` | ✅ |
| DELETE | `api/v1/pos/orders/{id}/lines/{lineId}` | ✅ |
| POST | `api/v1/pos/orders/{id}/send-to-kitchen` | ✅ |
| POST | `api/v1/pos/orders/{id}/cancel` | ✅ |
| POST | `api/v1/pos/orders/{id}/close` | ❌ **by design (tombstone)** |

Every one of the 13 also carries the full rule-12 chain in the correct order:
`api, auth:sanctum, SetPermissionsTeam, EnforceTokenTenantClaim` — no `'api'` omission (no 401 trap), no
missing `SetPermissionsTeam` (no unset Spatie team). `routes_kitchen.php:23` gates the whole group inline;
`routes_orders.php:24` uses a nested `Route::middleware('module:Menu')->group(...)`. The nested string-argument
idiom is not novel — precedent at `app/Modules/Product/routes.php:89` (`Route::middleware('module:Parapharmacy')->group(...)`).

**Tombstone contract preserved:** `routes_orders.php:60-67` returns 410 `NEW_SALE_AUTHORING_RETIRED` with no
module gate, and this is asserted on a **non-Menu vertical** at
`PosOrderKitchenModuleAccessControlTest.php:194-204`. Identical answer on every vertical: verified.

### Rule 12 — frontend layer

- `apps/web/src/routes/index.tsx:2974-2986` — Orders route wrapped `<ModuleGuard module="Menu"><RequirePermission permission="pos.operate_terminal">…`,
  byte-for-byte the KDS idiom at :3227-3241.
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:236` — `module: 'Menu'` added to `posOrders`, matching
  `kitchen` at :238. The nav filter honours it at Sidebar.tsx:438/455/459 (`isNavItemVisible(child.module, child.permission)`).
- `apps/web/src/components/guards/ModuleGuard.tsx:49-76` — fail-closed on resolved-config-without-module,
  holds render while the config query is in flight (no false bounce).
- `scripts/factory/manifests/routes-web.yaml:558-561` — diff is the **single line** `module_gate: null → Menu`
  on `/pos/orders`. Nothing else in the manifest moved.

### Menu-vs-Tables decision — computed from `config/verticals.php` (the SoT)

| vertical | `Menu` in defaults? | `Tables` in defaults? | gated cluster reachable? |
|---|---|---|---|
| `coffee_shop` | ✅ | ❌ (compatible extra only) | ✅ reachable |
| `restaurant` | ✅ | ✅ | ✅ reachable |
| `retail` | ❌ | ❌ | ❌ 403 |
| `parapharmacy` | ❌ | ❌ | ❌ 403 |

Confirmed against `config/verticals.php`: `coffee_shop.default_modules` = Identity, Tenant, Catalog, **Menu**,
Partner, Sales, Treasury, Accounting, CompositeItems (Tables is only in `compatible_extras`);
`restaurant.default_modules` includes both Menu and Tables; `retail`/`parapharmacy` have neither.
**`Menu` is the correct key** — gating on `Tables` would have broken coffee_shop, which is the whole point of
the parent pre-check. Both directions are probed live in the new class: parapharmacy → 403 on all 12 routes
(:111-171), coffee_shop → `assertSuccessful()` on `/pos/kitchen/orders` and `/pos/orders` (:177-188).

### `RequireModule` resolution + tenancy correctness

- `app/Http/Middleware/RequireModule.php:38-66` — resolves `$request->user()->tenant`, then
  `CompanyConfigService::getConfigForTenant()`, then `CompanyConfig::hasModule()` (strict `in_array` against
  `all_enabled_modules`). Aborts 403 **before any resource lookup**, so it is **not an existence oracle** —
  a non-Menu tenant gets the same 403 for a real and a fabricated order id (the new test uses `Str::uuid()`
  throwaways for exactly this reason and gets 403, not 404).
- **Module set = `default_modules ∪ enabled_extras`**, unfiltered, at `CompanyConfigService.php:70`
  (`array_values(array_unique(array_merge(...)))`). This is what makes Finding 1 possible.
- **db-per-tenant correctness:** `App\Modules\Tenant\Domain\Tenant` extends `Stancl\Tenancy\Database\Models\Tenant`,
  which `use`s `Concerns\CentralConnection` (`vendor/stancl/tenancy/src/Database/Concerns/CentralConnection.php`
  → `getConnectionName() = config('tenancy.database.central_connection')`). So the gate's tenant-directory read
  hits the **central** DB regardless of the per-request swapped default connection. Correct.
- **Cache:** `CompanyConfigService` deliberately uses `GlobalCache` (never swapped by
  `CacheTenancyBootstrapper`), keyed `tenant_config:<tenant_id>`; isolation is by key, invalidation by
  `TenantObserver.php:94` on save and `ReconcileModulesCommand.php:52`. No cross-tenant bleed; a
  vertical/extras change invalidates immediately rather than waiting out the 24h TTL.
- **Can a Menu tenant reach another tenant's order?** No. The gate is orthogonal to scoping; scoping is
  unchanged and still enforced in `OrderManagementService` — every entry point does
  `[$tenantId, $companyId] = $this->tenantAndCompany()` then `where('tenant_id')->where('company_id')->where('id')->lockForUpdate()`
  (e.g. `updateLineStatus` at :555-560), and `KitchenDisplayController` re-anchors the response read on both
  columns (:64-70, :101-107, :129-135). The 8 cross-tenant order/kitchen probes in
  `PosStabilizationTenantIsolationTest` still run and still discriminate on sqlite (Finding 3 for the PG caveat).

### Terminal-state guard (SM-1)

`OrderManagementService.php:564-570` refuses with `\RuntimeException` when `! $order->status->isActive()`.
Placement verified correct: **inside** the `DB::transaction`, **after** the tenant+company-scoped
`lockForUpdate()->firstOrFail()` (:552-560) and **before** the line lookup — so the check reads a locked,
tenant-scoped row and cannot race a concurrent cancel. `KitchenDisplayController.php:85-86` maps
`\RuntimeException` → **422** (and lets `ModelNotFoundException` bubble to 404, preserving the cross-tenant
404 contract). Defence-in-depth at :748 (`$allReady && $order->status->isActive() && $order->status !== OrderStatus::Ready`);
`checkAndTransitionOrderToReady` has exactly one caller (:592), so the belt-and-braces is genuinely redundant
today, which is the right posture. `OrderStatus::isActive()` (`Domain/Enums/OrderStatus.php:32-38`) = Open,
SentToKitchen, Ready → true; Closed, Cancelled → false: correct terminal set.

**Red-first:** verified **by construction, not by execution** (the lane is a single commit and I was instructed
not to checkout/stash). Reading the pre-lane code in the diff: `Sent → Ready` is a valid line transition, and
the old condition `if ($allReady && $order->status !== OrderStatus::Ready)` had no active-status term — a
Cancelled order with one `sent` line therefore did flip to Ready. The exploit chain in triage §2 reproduces
mechanically. The fiscal lens should confirm the OrderReady-broadcast half.

### Permission catalog / silent-403 check

**No new `can:` guard and no new permission are introduced by this lane.** The gated routes rely on
`Gate::authorize('pos.operate_terminal')` inside the controllers, an already-seeded permission
(`RolesAndPermissionsSeeder`). **No seeder re-sync is required for existing tenants.** The only new 403 source
is `RequireModule`, which reads config, not the permission table — so there is no
"staging tenant provisioned before the permission existed" trap here.

**Operational note (not a defect):** this lane makes the order/kitchen cluster newly 403 for every non-F&B
tenant. That is the intent, but it is a **behaviour change on a live surface**: any retail/parapharmacy tenant
whose staff had been reaching `/pos/orders` (the FE route was permission-only until now) loses it at deploy.
Per the module SoT they should never have had it; flagging so the deploy note says so out loud.

### Test execution — BY PATH ONLY (never the full suite)

| Driver | File | Result |
|---|---|---|
| sqlite (`phpunit.xml`: `DB_CONNECTION=sqlite`, `:memory:`) | `tests/Feature/Security/PosOrderKitchenModuleAccessControlTest.php` | **OK 7/7, 32 assertions** |
| **PostgreSQL 16** (throwaway `q9_review_tmp` @ 127.0.0.1:5433) | same file | **OK 7/7, 32 assertions** |
| sqlite | `tests/Feature/POS/OrderManagementTest.php` | **OK 18/18, 94 assertions** |
| sqlite | `tests/Feature/POS/KitchenDisplayTest.php` | **13 tests, 31 assertions, 1 skipped, 0 failures** |
| sqlite | `tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php` | **OK 11/11, 31 assertions** |
| sqlite | `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` | **49 tests, 171 assertions, 8 skipped, 0 failures** |
| **PostgreSQL** | `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` | **49 tests, 5 errors (PRE-EXISTING, Finding 3), 8 skipped** |
| sqlite | `tests/Feature/Security/MenuModuleAccessControlTest.php` (idiom source) | **OK 2/2** (2 PHPUnit deprecations, pre-existing) |
| sqlite | `tests/Architecture/AuthLifecycleTest.php` | **1 failure — PRE-EXISTING, zero delta** |

**AuthLifecycleTest** fails only on `dynamic_middleware` with a single entry: `app/Modules/POS/routes.php:94`.
That file is **not in the lane diff**. The lane's own additions do not appear in the violation list — the
literal-array group at `routes_kitchen.php:23` classifies cleanly, and the nested
`Route::middleware('module:Menu')` at `routes_orders.php:24` is not flagged (the nested group inherits the
parent's `auth:sanctum`/`SetPermissionsTeam` chain and is not classified as a standalone protected group).
Implementer's zero-delta claim: **confirmed**.

The 8 skips in the isolation suite are all explicit `markTestSkipped` with §14.2 retirement justifications
(:668, :687, :705, :728, :751, :770, :895, :1654) — none is vertical-driven, so the fixture change did not
silently disable anything.

### Static analysis / style / manifests

- **PHPStan L8** on the 3 changed backend files + the new test class: `[OK] No errors`.
- **Pint `--test`** on all 8 changed backend files: `{"result":"pass"}`.
- **`apps/api/tools/feature-lane-manifest-check.php`** (run from the worktree): **exit 0** —
  "1382 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml".
  The new class lands in the `Security` group, whose lane `security-regression` uses a **whole-directory
  selector** (`./vendor/bin/phpunit tests/Feature/Security`) on a job with **no `if:` guard`** — so it runs on
  every PR→dev from the moment it lands. **No gated ceiling moved and `tests/feature-lane-manifest.json` is
  not in the diff** (the group's `17 → 18` note is explicitly informational for live lanes). `ci.yml` untouched.
- **`scripts/factory/check-manifest-drift.sh`**: **exit 0**, no drift — `routes-web.yaml` is a faithful regen.
- **Frontend:** `pnpm vitest run src/routes/routes.test.tsx` → **22/22 pass**;
  `pnpm vitest run src/components/organisms/Sidebar/__tests__` → **50/50 pass** (the Sidebar test file lives at
  `src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`, not the path in the task line);
  `pnpm typecheck` (`tsc --noEmit`) → **clean**. `pgrep -fl vitest` after the runs → **no vitest processes**.

### Module-set computation (the "strict superset" claim, from `config/verticals.php`)

```
retail.default_modules      = {Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting}
OLD fixture (retail + ['Loyalty'])
                            = {Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting, Loyalty}      (9)

restaurant.default_modules  = {Identity, Tenant, Catalog, Menu, Partner, Sales, Treasury, Accounting, Tables, CompositeItems}
NEW fixture (restaurant + ['Loyalty','Inventory'])
                            = OLD ∪ {Menu, Tables, CompositeItems}                                                        (12)
```

**Superset claim: TRUE.** `retail` loses only `Inventory` relative to `restaurant`, and the fixture restores it
via the (compatible) `Inventory` extra. **But the claim is true only under an unfiltered merge** — `Loyalty` is
carried by `CompanyConfigService.php:70` despite not being in `restaurant.compatible_extras`, which is Finding 1.

```
coffee_shop.default_modules = {Identity, Tenant, Catalog, Menu, Partner, Sales, Treasury, Accounting, CompositeItems}
PROPOSED fixture (coffee_shop + ['Loyalty','Inventory'])
                            = OLD ∪ {Menu, CompositeItems}                                                                (11)
coffee_shop.compatible_extras = {Tables, Loyalty, Inventory}   ⊇ {Loyalty, Inventory}   → reconcile-STABLE
```

**`coffee_shop` is both a strict superset of the old retail fixture AND product-valid.** That is the C-1 fix.

For the three simple re-fixtures (`OrderManagementTest`, `KitchenDisplayTest`,
`NewSaleServerAuthoringDispositionTest`) the tenants get **no extras at all**, so they trade retail's default
`Inventory` for restaurant's `Menu`/`Tables`/`CompositeItems`. Audited for hidden weakening: none of those
three suites touches an `Inventory`-gated route, stock movement, batch/expiry, or Loyalty (grep for
inventory/stock/batch/loyalty/composite returns only unrelated hits — `OrderManagementTest:226/258` are
"batches product-unit **queries**", not batch/expiry). `KitchenDisplayTest`'s table tests
(`test_order_table_assignment_on_create`, `test_table_released_on_order_close`, `test_cannot_assign_occupied_table`)
are unaffected either way because no `module:Tables` gate exists (Finding 2).
`NewSaleServerAuthoringDispositionTest`: only `test_order_crud_non_close_routes_still_function` (:364) needed
Menu; the ten receipt/void/return/tombstone cases are vertical-insensitive.
**No assertion was weakened, deleted, or made vacuous by any of the four fixture changes.**

### Scope

`git diff --stat dev...HEAD` = exactly 12 files: 3 backend prod, 5 backend test, 3 web, 1 manifest. Working
tree clean apart from the stray untracked `node_modules` (ignored per instructions). **Nothing on Session A's
collision matrix** — no PIN/`has_pins`, no VAT resolution, no `StockLevel`, no web document pages, no
opening-balance wizard, no `VatReportPage`. The two shared web files are the two that were pre-announced in
OWNER-SHEET §E (:94), with the minor deltas in Finding 6. No migration, no seeder change, no `ci.yml` change,
no `horizon.php` queue addition. Nothing was modified by this review except this record.

---

**What to fix before merge:** apply C-1 — swap the `PosStabilizationTenantIsolationTest` fixture from
`Vertical::Restaurant` to `Vertical::CoffeeShop` (and fix its comment), then ticket C-2 (missing backend
`module:Tables`) and C-3 (`shift_number` PG reds).
