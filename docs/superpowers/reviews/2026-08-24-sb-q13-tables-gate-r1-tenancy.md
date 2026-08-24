# Session B — lane Q-13 (`module:Tables` on the floors/tables routes + device `pullTables` guard) — gate ROUND 1, tenancy/authz lens

- **Date:** 2026-08-24
- **Lens:** tenancy-authz-reviewer (single lens, per the brief)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q13-tables-gating`
- **Branch/commit:** `fix/sb-q13-tables-module-gate` @ `d611e054b` (base `ba602748f`, ancestor of dev; Q-9 `020d0ab55` present)
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q13-tables-gating.md` (incl. AMENDMENT §e–§g, device guard in scope)
- **Origin:** this reviewer's own Q-9 record, `docs/superpowers/reviews/2026-08-24-sb-q9-kitchen-gate-r1-tenancy.md:70` (Finding 2 — "no `module:Tables` gate exists anywhere in the backend"); owner "go ahead" 2026-08-24.
- **Diff:** exactly 8 files (`git diff --name-only ba602748f d611e054b`), 1 backend prod, 3 backend test, 1 device prod, 3 device test. No migration, no new permission, no `ci.yml`/manifest/`horizon.php` change.

---

## Verdict

**VERDICT: spec ✅ + quality APPROVED**

The claimed hole was real and is now closed on both layers. All 10 live routes in `routes_tables.php` carry
`App\Http\Middleware\RequireModule:Tables` (verified live via `route:list -v`, not from the source alone), the
FE has gated the same surface on the same key since before this lane, `Tables` is the SoT-correct key for every
vertical probed, the two re-fixtured PHP suites were audited hunk by hunk and **no assertion was weakened,
deleted or made vacuous**, the coffee_shop fixture change is reconcile-stable by construction, and the device
guard is a faithful mirror of the existing Menu-gate idiom with a defensible fail-open. Zero delta on every
inherited red I could measure (PG isolation suite 5 errors, `AuthLifecycleTest` 1 failure).

**No blocking conditions.** Three MINOR findings + three residuals below are ticket-material, not merge-blockers.

---

## Findings

### 1. [INFO — the defect, CONFIRMED CLOSED] `apps/api/app/Modules/POS/routes_tables.php:25`

Pre-lane the group was `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` with no
module middleware, while `apps/web/src/routes/index.tsx:2991` (`<ModuleGuard module="Tables">`) and
`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:237` (`module: 'Tables', permission: 'pos.manage_tables'`)
both hid the surface. That is a textbook rule-12 one-layer gate: any `pos.manage_tables` holder on retail,
parapharmacy, fashion, pharmacy, mechanic or body_shop could create/rename/delete floors and tables and drive
table status. Post-lane the group carries `'module:Tables'` as the 5th entry, applied to the whole file — and
the file genuinely contains **no tombstone/410 closure** to leave outside the gate (whole file read; 10
`Route::` declarations, all live controller actions).

**Live enumeration** (`php artisan route:list --path=pos/floors -v` and `--path=pos/tables -v`, run in the worktree):

| # | Method | URI | `RequireModule:Tables` |
|---|---|---|---|
| 1 | GET\|HEAD | `api/v1/pos/floors` | ✅ |
| 2 | POST | `api/v1/pos/floors` | ✅ |
| 3 | PATCH | `api/v1/pos/floors/{id}` | ✅ |
| 4 | DELETE | `api/v1/pos/floors/{id}` | ✅ |
| 5 | GET\|HEAD | `api/v1/pos/tables` | ✅ |
| 6 | POST | `api/v1/pos/tables` | ✅ |
| 7 | PATCH | `api/v1/pos/tables/{id}` | ✅ |
| 8 | DELETE | `api/v1/pos/tables/{id}` | ✅ |
| 9 | POST | `api/v1/pos/tables/{id}/release` | ✅ |
| 10 | POST | `api/v1/pos/tables/{id}/status` | ✅ |

10/10, plus `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim` on each (rule 12 chain intact —
nothing was dropped to make room). `php artisan route:list | grep -Ei "floor|table"` returns **exactly these 10
routes and nothing else**, so there is no second, ungated door onto floor/table data.

### 2. [INFO — key correctness against the SoT] `apps/api/config/verticals.php`

`Tables` appears at exactly three places: `restaurant.compatible_extras:75`, `restaurant.default_modules:91`,
`coffee_shop.compatible_extras:107`. It is **not** in `retail` (`:128-146`), `parapharmacy` (`:335-362`),
`fashion`, `pharmacy`, `mechanic` or `body_shop`. So:
- `restaurant` → default module ⇒ always open, cannot be pruned;
- `coffee_shop` → compatible extra only ⇒ 403 without the extra, open with it;
- `retail`/`parapharmacy` → neither ⇒ always 403.

That is precisely the matrix the new test class probes. **`Tables` is the correct key** — gating on `Menu`
(coffee_shop's default) would have let a Tables-less coffee shop through, and gating a coffee shop out
unconditionally would have contradicted its declared `compatible_extras`.

### 3. [INFO — `RequireModule` resolution under db-per-tenant] — verified in Q-9, cited not redone

`apps/api/app/Http/Middleware/RequireModule.php:38-66` resolves `$request->user()->tenant` →
`CompanyConfigService::getConfigForTenant()` → `CompanyConfig::hasModule()` and `abort(403)` **before any
resource lookup**. The Q-9 record established, from `vendor/stancl/tenancy/src/Database/Concerns/CentralConnection.php`,
that `App\Modules\Tenant\Domain\Tenant` reads on the **central** connection regardless of the per-request
swapped default, and that the config cache is `GlobalCache` keyed `tenant_config:<tenant_id>` with invalidation
on `TenantObserver` save and on reconcile — see `2026-08-24-sb-q9-kitchen-gate-r1-tenancy.md:194-210`. Nothing in
this lane changes that resolution path; re-verification would be redundant. **Not an existence oracle:** the new
test's deny cases address `Str::uuid()` throwaway ids and still get 403, never 404
(`PosTablesModuleAccessControlTest.php:105-127`).

### 4. [INFO — the fixture is reconcile-stable] `apps/api/tests/Feature/POS/PosStabilizationTenantIsolationTest.php:228`

`enabled_extras` goes `['Loyalty','Inventory']` → `['Loyalty','Inventory','Tables']` on a `Vertical::CoffeeShop`
tenant. `app/Modules/Tenant/Application/Commands/ReconcileModulesCommand.php:41-49` computes
`allowedExtras = compatible_extras \ default_modules` then `array_intersect(enabled_extras, allowedExtras)` and
force-saves the result. For coffee_shop: `compatible_extras = {Tables, Loyalty, Inventory}` (`verticals.php:107`),
`default_modules` contains none of the three (`:109-118`) ⇒ `allowedExtras = {Tables, Loyalty, Inventory}` ⊇ the
fixture. **`tenant:reconcile-modules` prunes nothing** — the fixture is product-valid, unlike the Q-9 r1 C-1
mistake (`Loyalty` on `restaurant`) that this reviewer blocked last round. Superset property preserved: the
fixture still carries every module the old retail fixture had (retail defaults minus nothing, plus `Menu`,
`CompositeItems`, `Tables`), so no cross-tenant probe lost its 403-vs-404 discrimination.

### 5. [INFO — fixture scrutiny, no weakening] `apps/api/tests/Feature/POS/TableManagementTest.php:317-323`

`Tenant::factory()->create()` → `Tenant::factory()->create(['vertical' => Vertical::Restaurant])`. Additive; not
one assertion, expectation or route in the 12 cases (`:40-311`) was touched. Audited for **hidden** behaviour
change: `restaurant.default_modules` trades retail's `Inventory` for `Menu`/`Tables`/`CompositeItems`, so the
question is whether this suite depends on `Inventory`. `grep -nEi "inventory|stock|batch|loyalty|menu|composite|product"`
over the whole file returns **zero hits** (exit 1) — the suite is floors/tables/status only. No sensitivity, no
weakening. `restaurant` was also the *stronger* choice over CoffeeShop+extra: Tables is a **default** module
there, so reconcile can never prune it out from under the suite.

Same audit for the isolation suite: the change is one array element; the 49 test bodies are untouched
(`git show d611e054b -- <file>` = 8 lines, 6 of them comment).

### 6. [MINOR] `apps/api/tests/Feature/Security/PosTablesModuleAccessControlTest.php:145-155, 166-176` — the ALLOW cases probe only the two GET routes

The deny helper asserts all 10 routes (`:100-127`); the two allow cases assert `assertSuccessful()` on
`GET /pos/floors` and `GET /pos/tables` only. So a hypothetical future regression that 403s only the write
routes for a legitimate restaurant would not be caught here. Same shape as the Q-9 class, so this is a
consistency point rather than a new gap — but the allow side is 2/10 while the deny side is 10/10.
*Fix (optional, follow-up):* add one `POST /pos/floors` 201 to each allow case.

**Countervailing strength worth recording:** the deny cases prove the *module* gate, not a permission accident.
The same `admin` role in the same helper (`:88` `$user->assignRole('admin')`) reaches `GET /pos/floors`
successfully on restaurant and on coffee_shop+extra, so `pos.manage_tables`
(`database/seeders/RolesAndPermissionsSeeder.php:346`, granted at `:597`) is demonstrably held in all five cases.
The only variable across allow/deny is the vertical. That differential is what makes the 403s attributable to
`RequireModule` rather than to `Gate::authorize('pos.manage_tables')` in
`TableController.php:33,46,59,72,104,120,135` (which fires *after* the middleware). No new permission is
introduced by this lane, so **no seeder re-sync is owed on any existing tenant** — a rare clean case.

### 7. [MINOR] `apps/pos/src/lib/sync/__tests__/syncService.test.ts:1441` + `syncService.customers.test.ts:325,407,491,559` — the runFullSync fixtures now ALL pin a Tables tenant; the skip path has no integration-level coverage

Every `runFullSync` describe's `companyConfig` went `['POS']` → `['POS','Tables']`, and the `pullTables` describe
gained a `beforeEach` that pins `['POS','Menu','Tables']` (`:1605-1622`). Hunk-by-hunk diff: **five one-line
config edits and one added `beforeEach`; no `expect` was changed, removed, or relaxed** — I diffed all six hunks.
The stated reason is honest and I verified the mechanism: these suites drive `apiGet` with a positional
`mockResolvedValueOnce` queue (`setupApiGetSequence`, "pull-phase alignment / 12 slots"), so a skipped
`/pos/floors` would leave one fixture unconsumed and shift every later slot — a test-harness artifact with **no
production analogue** (real `apiGet` is not positional).

The residual is coverage, not correctness: after this change **no test exercises `runFullSync` end-to-end for a
non-Tables tenant**, which is exactly the retail/parapharmacy production shape the guard exists for. The unit
test covers `pullTables` in isolation; the composition is unpinned.
*Fix (optional, follow-up):* one `runFullSync` case with `['POS']` asserting the remaining pulls still land on
their own responses.

### 8. [MINOR] `apps/pos/src/lib/sync/syncService.ts:1841` — the skip is logged as `success`; `tablesPulled` cannot express "skipped"

Ruled below in its own section. Recorded here so it appears in the severity-ordered list.

---

## The device-skip ruling (brief §e, parent asked for an explicit call)

### Is the guard a faithful mirror of the Menu idiom? — YES

Read side by side:

- Menu gate, `syncService.ts:1942-1944`: `const { useProductStore: psModule, hasModule } = await import('@/stores/productStore'); const config = psModule.getState().companyConfig; isMenuTenant = config !== null && hasModule(config, 'Menu');` inside a `try { } catch { }` whose comment says "Defensive: dynamic-import failure (test harness, edge cases)".
- New Tables gate, `syncService.ts:1823-1834`: `const { useProductStore, hasModule } = await import('@/stores/productStore'); const config = useProductStore.getState().companyConfig; tablesModuleKnownAbsent = config !== null && !hasModule(config, 'Tables');` with the same defensive `catch`.

Identical shape, same dynamic-import rationale (`productStore` imports from this module — a static import would
cycle; verified: `resolveCatalogTenantGate` at `:855` uses the same trick). The polarity is inverted correctly:
the Menu gate needs `config !== null && hasModule(...)` to *enable* a destructive reconcile; the Tables gate
needs `config !== null && !hasModule(...)` to *disable* a network call. Both default to the pre-gate behaviour
on unknown. Note the third idiom in the file, `resolveCatalogTenantGate:855-880`, **defers the tick** on unknown
config rather than falling through — deliberately, because guessing there risks "bare-row pollution". The
`pullTables` choice of fail-open is the right one for its blast radius: the worst case of a wrong fail-open is
one 403 logged as an error (i.e. exactly the pre-lane behaviour), whereas fail-closed on unknown would starve a
genuine restaurant of its layout on the boot tick.

### Is the skip fail-safe for a STALE config (tenant had Tables, extra later removed)? — YES, and the degradation is the right one

`hasModule` reads `useProductStore.getState().companyConfig`, which is refreshed by the normal config fetch, so
the window is one tick at most. In that window: the device keeps its **already-cached** SQLite layout (the guard
returns before `upsertFloors`/`upsertTables` — pinned by
`syncService.pullTablesModuleGate.test.ts:71-73`) and simply stops pulling updates. It does **not** wipe the
cache, and it does **not** silently unlock anything: the cached layout is read-only convenience for
`tableApi.getFloors()` when the API is unreachable, and every *mutating* table operation still goes through the
now-gated server routes, which will 403. **A stale device can therefore display stale tables it may no longer be
entitled to see, but cannot act on them.** For a module downgrade (a paid extra being removed), that is an
acceptable and appropriately-scoped degradation — the authority is the server gate, and the device cache is not
a security boundary. **ACCEPTABLE. No change requested.** (If a future lane wants stricter behaviour, the right
move is an explicit local purge on config-change, not a fail-closed pull.)

### Is logging the skip as `success` honest, given the consumer? — YES, and the "distinguish skipped" alternative buys nothing here

Verified the whole consumer chain rather than the one line the brief cited:
- `syncLogRepository.ts:4-17` — `logSyncOperation(db, operation, entityType, entityId, status: 'success'|'error', details?)` and a raw INSERT. The status column is **binary by type**; there is no third value to use, so `'error'` (a permanent red per tick for a tenant with no table surface) was the only alternative, and it would be the dishonest one.
- `sync_log` is **write-only in production**: `grep -rn "sync_log" apps/pos/src` returns the `CREATE TABLE` (`migrations.ts:151`), the index (`:160`), the INSERT (`syncLogRepository.ts:14`), the retention DELETE (`:40`) and two comments. **Nothing reads a `sync_log` status programmatically** — no staleness check, no UI, no gate. The row is diagnostics for a human, and the human gets the disambiguation in `details`: `"skipped — module Tables not enabled"` (`syncService.ts:1841`).
- `tablesPulled` (`SyncResult`, `:240`) is set at `:2325`, returned at `:2507`, and **has no production consumer at all**: `grep -rn "tablesPulled" apps/pos/src` outside `syncService.ts` returns only `syncStore.test.ts:39` and `syncScheduler.test.ts:141`. It does **not** feed the amber-dot heuristic — `computeDegraded` (`:302-314`) takes only `receiptsFailed`, `zReportsFailed`, `paymentConfigPulled`, `errors`. So `return true` cannot mislead a cashier; it merely avoids a false failure signal.

**RULING: the `success` + explanatory-detail encoding is honest and correct as written. A `skipped` tristate in
`SyncResult`/`sync_log` would be a schema change (device migration) with, today, zero readers to benefit.** If a
sync-log viewer is ever built, the detail string is already machine-greppable. No edit requested — recorded so
the next person does not "fix" it into an error row.

### Fail-open on `null` config — correct, and pinned

`pullTablesModuleGate.test.ts:102-111` asserts `apiGet` IS called with `/pos/floors` when `companyConfig === null`.
A Tables tenant on its very first tick is therefore never starved. The comment at `syncService.ts:1826-1830`
states the rationale, as the brief required.

---

## Gate verified — every command run by this reviewer, per driver

All runs from the worktree, one test process at a time, by path. Never the full suite.

| Driver | File | Result |
|---|---|---|
| sqlite (`phpunit.xml:44-45`, `:memory:`) | `tests/Feature/Security/PosTablesModuleAccessControlTest.php` | **OK 5/5, 34 assertions** |
| **PostgreSQL 16.10** (throwaway `autoerp_gate_q13` @ 127.0.0.1:5433) | same file | **OK 5/5, 34 assertions** |
| sqlite | `tests/Feature/Security/PosOrderKitchenModuleAccessControlTest.php` + `tests/Feature/POS/TableManagementTest.php` | **OK 19/19, 68 assertions** (7 + 12) |
| **PostgreSQL** | same two files | **OK 19/19, 68 assertions** |
| sqlite | `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` | **49 tests, 171 assertions, 8 skipped, 0 failures** |
| **PostgreSQL** | `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` | **49 tests, 154 assertions, 5 errors, 8 skipped** |
| sqlite | `tests/Architecture/AuthLifecycleTest.php` | **2 tests, 32 assertions, 1 failure** |
| node/vitest (worktree `apps/pos`, one run) | `syncService.pullTablesModuleGate.test.ts` + `syncService.test.ts` + `syncService.customers.test.ts` | **3 files passed, 88 tests passed, 0 failed** (4.53 s) |

**Zero-delta confirmations against the Q-9 record's measured baselines:**
- PG isolation suite: Q-9 measured `49 tests, 154 assertions, 5 errors, 8 skipped`
  (`2026-08-24-sb-q9-kitchen-gate-r1-tenancy.md:87,259`). I measured **byte-identical numbers**. The 5 errors are
  the pre-existing `shift_number` string-into-integer fixture bug (`PosStabilizationTenantIsolationTest.php:1729`,
  `PDOException 22P02: invalid input syntax for type integer: "SHIFT-A-gKTJ"`), tracked as Q-9 C-3. **Delta = 0**;
  this lane neither fixed nor worsened them, and its diff for that file contains no `shift_number` line.
- sqlite isolation suite: Q-9 measured `49 / 171 assertions / 8 skipped / 0 failures` (`:258`). Identical here —
  so the 4 cross-tenant `/pos/tables` probes really did survive the gate via the extra, rather than being
  skipped or silently downgraded.
- `AuthLifecycleTest`: the single failure is `dynamic_middleware` with **one** entry, `app/Modules/POS/routes.php:99`
  (Q-9 saw the same file at `:94` — the line moved on dev, the file is not in this lane's diff). The lane's own
  literal-array edit at `routes_tables.php:25` is **not** flagged: the arch parser classifies it cleanly.
  **Delta = 0.**

**Static analysis / style / manifests:**
- `php vendor/bin/phpstan analyse app/Modules/POS/routes_tables.php --level=8` → **[OK] No errors**.
  `tests/Feature/Security/PosTablesModuleAccessControlTest.php` → **[OK] No errors**.
  `tests/Feature/POS/TableManagementTest.php` → **[OK] No errors**.
  (`PosStabilizationTenantIsolationTest.php` reports 9 `deadCode.unreachable`/`method.unused` errors when
  analysed by explicit path — but `phpstan.neon:6-7` scopes `paths:` to `app/` only, so tests are **outside the
  CI gate**, and every one of those 9 is a `markTestSkipped` artifact pre-dating this lane, which changed one
  array element in that file. Not a lane red.)
- `php vendor/bin/pint --test` on all four touched PHP files → `{"result":"pass"}`.
- `pnpm exec eslint` on all four touched TS files (worktree `apps/pos`) → **clean, no output**.
- `php apps/api/tools/feature-lane-manifest-check.php` (from the worktree root) → **EXIT=0**,
  `tests/Feature lane manifest OK — 1400 Feature classes in 74 groups; every group has a disposition; every
  declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched`. The main checkout
  reports 1399 on the same tool — **+1 = exactly the new class**, and **no ceiling was moved** (the diff touches
  no manifest and no `ci.yml`). Legitimate, because `security-regression` (`ci.yml:503-509` + the "Whole
  directory, not a --filter list, so new classes are gated the moment they land" note) runs the whole
  `tests/Feature/Security` directory and carries **no `if:` guard** — the new class is gated on PR→dev from the
  moment it merges, with no owner ops step. The standing PARKED/COVERAGE-DEBT warnings are the inherited F-2
  execution-gate state, unchanged by this lane.
- `node scripts/factory/gen-route-manifest.mjs --check` → **EXIT=0** (no web route changed; no regeneration needed).

---

## Scope

`git diff --name-only ba602748f d611e054b` = exactly the 8 declared files. `git status --porcelain` in the
worktree shows only the untracked `apps/pos/node_modules` symlink (per instructions; not staged).
**Nothing on Session A's collision matrix**: no PIN / `has_pins`, no VAT resolution, no `StockLevel`, no web
document pages, no X/Z report path. `apps/web` was **not** edited — correctly, since the FE already gated on
`Tables`. No migration, no new permission, no seeder change, no `horizon.php` queue, no `ci.yml`.
Nothing was modified by this review except this record; the throwaway PG database was dropped and no vitest
worker survived the run (`pgrep -fl vitest` → none).

---

## Residuals (ticket, not blocking)

1. **Order-lane table writes remain gated on `Menu`, not `Tables`.** `OrderManagementService.php:158` persists
   `table_id` on order create and `:446-452`/`:510-516` flip table occupancy on close/cancel — all reachable
   from the `module:Menu`-gated orders routes Q-9 closed. A coffee_shop WITHOUT the Tables extra therefore still
   has an indirect write path onto `pos_tables` rows. **Practically inert today** — that tenant cannot create a
   table in the first place (all 8 CRUD routes now 403), so there is nothing for an order to reference — but the
   module boundary around table *state* is not fully closed. Pre-existing, out of this lane's scope.
2. **Q-9 C-3 is still open:** the 5 PG `shift_number` errors in `PosStabilizationTenantIsolationTest` blind PG
   evidence for 5 of the probes this gate fronts. `security-regression` runs **sqlite only** in CI, so the PG
   evidence for this lane exists solely in this record.
3. **Dhouha's unmerged table-management PR chain (#201–206)** touches these routes; a rebase there will hit the
   one-line group edit at `routes_tables.php:25` and must keep `'module:Tables'`. Noted, not actionable here.
4. Optional follow-ups from Findings 6 and 7 (widen the allow-side probes to a write route; pin `runFullSync`
   for a non-Tables tenant).

---

**What to fix before merge:** nothing — merge as is; open tickets for residual 1 (order-lane table writes still
`Menu`-gated) and Q-9 C-3 (`shift_number` PG reds).
