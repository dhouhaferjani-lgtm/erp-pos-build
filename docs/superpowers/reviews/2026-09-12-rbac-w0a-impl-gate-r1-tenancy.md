# RBAC wave 0a — implementation gate r1, `tenancy-authz-reviewer`

| | |
|---|---|
| Lane | `lane/rbac-w0a` |
| Worktree read | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a` |
| Base (`dev`) | `477c877a341a8f228c01489c04243befc229d3e5` (merge-base confirmed identical) |
| Tip | `b3e35990d2e103a3d23c0c263c4c8224b52f363f` |
| Diff | 33 files, +2929 / -107 |
| Authorities | plan rev 5.1, spec rev 9.2, `02-route-enforcement-sweep.csv`, `HANDBACK-rbac-w0a-2026-09-10.md` |
| Date | 2026-09-12 |
| Reviewer | tenancy-authz-reviewer (adversarial, code-grounded) |

## VERDICT: **MERGE-WITH-FIXES** — **0 BLOCKER / 2 MAJOR / 5 minor**

Both MAJORs are **record/precondition fixes, not code changes**. Every one of the ten
reviewer questions is answered AFFIRMATIVELY against the tree; four independent tampers
were planted and all four fired; the baseline's 298 keys were re-derived from the CSV
with **zero** difference in either direction. No cross-tenant, auth-bypass or privesc
defect was found. No `apps/web/` path is touched. No wave-0b route is gated.

---

## Q1 — is `authz.self` really NOT self-certifying? **YES, proven.**

`RouteCoverageClassifier::classify()` (`apps/api/tests/Architecture/Support/RouteCoverageClassifier.php:82-105`)
orders: tombstone-fixture → `PUBLIC_ALLOW_LIST` → `SelfServiceRouteRegistry::ALLOW_LIST`
(`:96`) → `carriesGatingMiddleware()` (`:100`) → `Uncovered` (`:104`). The self-service arm
tests **allow-list membership of the route key**, never the middleware stack; `authz.self`
is absent from `GATING_ALIASES` (`:31-37`), so a marked route with no entry falls through
to `Uncovered`. Not a comment — the ordering.

Two mechanical proofs beyond reading:
- Liveness fixture record `POST api/v1/__liveness__/marker-without-allow-list-entry`
  carries `["api","auth:sanctum","authz.self"]` and declares `expected_classification:
  "uncovered"` (`apps/api/tests/Architecture/fixtures/route-permission-coverage-liveness.json:29-35`);
  it is registered pre-boot and asserted exactly at
  `RoutePermissionCoverageRatchetLivenessTest.php:77-93`.
- **Tamper T1 (planted by me).** Added `->middleware('authz.self')` to
  `GET api/v1/notifications` (not on the allow-list). Result:
  `SelfServiceRouteAllowListTest::no_route_wears_the_marker_without_an_allow_list_entry`
  **FAILED** naming `GET api/v1/notifications`, **and the ratchet stayed clean** — i.e. the
  marker bought the route nothing (the key remains in the baseline at
  `baselines/route-permission-coverage-baseline.json:56`). Reverted; tree clean.

`AllowSelfService::handle()` (`apps/api/app/Http/Middleware/AllowSelfService.php:43-58`)
performs **no** ownership check and only 401s on a null user — consistent with its
declaration-not-gate contract. Alias registered once at `apps/api/bootstrap/app.php:124`.

## Q2 — is the gating alias set exactly five? **YES.**

`GATING_ALIASES = ['can','require.any.permission','super_admin','central_admin','central_admin_role']`
(`RouteCoverageClassifier.php:31-37`), matched by alias prefix before `:`
(`:120-137`), and pinned with `assertSame` at
`RoutePermissionCoverageRatchetTest.php:221-227`.

Nothing else reaches `Gated`: `module` (`bootstrap/app.php:120`),
`validate.location.access`, `throttle:*`, `scheduling.captcha`, `cross_tenant`,
`authz.self` are all outside the list. The live shape is proven, not asserted: the fixture
record `POST api/v1/__liveness__/module-gated-only` with `["api","auth:sanctum","module:Menu"]`
must classify `uncovered` (`fixtures/route-permission-coverage-liveness.json:36-43`).
Each of the five enforces unconditionally — `RequireAnyPermission::handle()` 403s with no
flag (`apps/api/app/Http/Middleware/RequireAnyPermission.php:14-35`); `can` is Laravel's
`Authorize`.

**What breaks if a sixth were added** (fail-closed in two directions):
1. `a_flag_conditional_middleware_is_not_gating` (`RoutePermissionCoverageRatchetTest.php:218-233`)
   fails immediately on the `assertSame` of the literal array.
2. Every route carrying the new alias leaves the uncovered set → its baseline entry becomes
   stale → `uncovered_routes_match_the_baseline_exactly` (`:174-183`) fails with the
   "DELETE them from the baseline" message. Widening cannot be silent.

## Q2b — is TOMBSTONE earned by a source-shape pin? **YES, and I broke it two ways.**

- **Same fixture, two consumers.** `RouteCoverageClassifier::tombstoneKeys()` delegates to
  `TombstoneRouteRegistry::keys()` (`RouteCoverageClassifier.php:77-80`), which reads
  `tests/Architecture/fixtures/route-tombstones.json` (`TombstoneRouteRegistry.php:20,27`);
  `TombstoneRouteBehaviourTest::the_classifier_exempts_exactly_the_fixture_entries`
  asserts set identity (`TombstoneRouteBehaviourTest.php:79-87`).
- **The pin.** `closureTokens()` uses `ReflectionFunction` → `getFileName()` /
  `getStartLine()` / `getEndLine()`, reads exactly that range, tokenises, drops
  `T_WHITESPACE`/`T_COMMENT`/`T_DOC_COMMENT`, stops at the brace that closes the body
  (`:282-335`); `normalisedSource()` joins with one space (`:267-270`); equality against the
  fixture `source` at `:110-118`.
- **Independent token assertions** (so a regenerated `source` cannot launder a branch):
  forbidden token IDs incl. `T_IF/T_MATCH/T_THROW/T_NEW/T_COALESCE/…` (`:69-76`, checked
  `:125-136`); exactly one `T_RETURN` (`:138-142`); body `T_STRING` identifiers exactly
  `['response','json']` (`:151-161`); no `?` token (ternary/nullsafe) (`:165`); normalised
  source ends `', 410 ) ; }'` (`:167-171`).
- **Query assertion covers EVERY connection**: `array_keys((array) config('database.connections'))`
  with a non-empty guard, `flushQueryLog`/`enableQueryLog` per connection before the
  invocation and `getQueryLog` per connection after (`:192-215`, asserted `:233-239`).
- **Tamper T4 (planted by me).** Added `if ($id === 'legacy-terminal-7') { return
  response()->json(['data'=>['ok'=>true]], 200); }` to the `POST /pos/receipts/{id}/payments`
  closure — a branch on an identifier **different** from the fixture's parameter
  (`00000000-0000-4000-8000-000000000000`). The single-invocation evidence test still
  passed (it returned 410 for that id, exactly the rev-2 hole); the **source pin FAILED**
  with the full observed token stream. 
- **Tamper T5 (laundering attempt).** Re-pinned the fixture `source` to the tampered
  observed string. Result: `contains forbidden token(s) T_IF` — laundering blocked.
- **Tamper T3 (fixture deletion).** Removed the `POST api/v1/pos/receipts` entry. Result:
  `the_fixture_holds_exactly_four_entries_with_no_duplicates` failed **and**
  `uncovered_routes_match_the_baseline_exactly` fired with
  `These routes have no action gate and are not in the baseline: POST api/v1/pos/receipts`
  plus the write ceiling at 153 > 152. "Re-enters the uncovered count" is mechanical.

All tampers reverted; `git status --short` empty, HEAD still `b3e35990d`.

## Q2c — convention-08 compliance. **All four confirmed.**

1. **Pre-boot registration**: `createApplication()` requires `bootstrap/app.php`, calls
   `$app->register(LivenessRouteServiceProvider::class)` and **then**
   `$app->make(Kernel::class)->bootstrap()` (`RoutePermissionCoverageRatchetLivenessTest.php:53-66`);
   the provider registers the fixture routes in `boot()`
   (`Support/LivenessRouteServiceProvider.php:30-44`) and is referenced from nowhere else
   (not `bootstrap/providers.php`, not config — grep clean).
2. **Injected routes**: new-violation (`:177-190`), stale-entry (`:192-205`) and the
   universe case (`:138-175`) build `new Route([...])` via `plantedRoute()` (`:243-249`)
   and drive `RoutePermissionCoverageScanner::scan()` directly — no application route can
   satisfy them.
3. **Matched growth** names `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB` twice, including an
   executable assertion that the ratchet file still references it (`:207-238`).
4. **CI step** appended at `.github/workflows/ci.yml:237-257`, inside `backend-architecture`
   (job `:143-264` at the tip), names **all six** classes the wave adds:
   `EntrypointPermissionCacheResetShapeTest`, `SelfServiceRouteAllowListTest`,
   `SelfServiceRouteShapeTest`, `TombstoneRouteBehaviourTest`,
   `RoutePermissionCoverageRatchetTest`, `RoutePermissionCoverageRatchetLivenessTest` —
   the exact set of new test classes in the diff (the other ten new PHP files are
   `Support/` helpers and two fixtures). `set -o pipefail` + an explicit
   zero-tests-selected guard are present (`:253-257`).

## Q3 — do the seven allow-listed routes act only on the caller? **YES, all seven.**

| Route | Evidence |
|---|---|
| `GET api/v1/auth/me` | `AuthController.php:616-628` — `$request->user()` only |
| `POST api/v1/auth/logout` | `:568-588` — `$user->tokens()->delete()` on `$request->user()` |
| `POST api/v1/auth/logout-all` | `:593-611` — `$user->tokens()`, `$user->devices()` |
| `POST api/v1/auth/resend-verification` | `:665-692` — `resendVerificationEmail($request->user())` |
| `POST api/v1/notifications/read-all` | `NotificationController.php:63-68` — `$this->user($request)->unreadNotifications()->update(...)` |
| `POST api/v1/notifications/{id}/read` | `NotificationController.php:55-61` — **`$this->user($request)->notifications()->findOrFail($id)`**; the `{id}` selects among the caller's own rows only, so `SHAPE_EXEMPTIONS` (`SelfServiceRouteRegistry.php:57-60`) is a TRUE statement. Route also carries `->whereUuid('id')` (`Notification/Presentation/routes.php:34`), so a non-UUID cannot reach the uuid column |
| `POST api/v1/support-access/sessions/{session}/exit` | `TenantSessionController.php:17-27` → `SessionLifecycleService::exit()` (`:137-194`), which loads the session `lockForUpdate()` on the **central** connection (`:143-145`, `tenancy.database.central_connection` — correct under db-per-tenant) and throws `AuthorizationException` unless `subject_user_id === $subject->id` **and** `tenant_id === $subject->tenant_id` **and** `personal_access_token_id === (int) currentAccessToken()->getKey()` (`:147-152`). Triple-scoped to the caller |

`SelfServiceRouteShapeTest` additionally refuses any URI parameter outside
`['session','tokenId']` (`SelfServiceRouteShapeTest.php:22-47`) and requires every
exemption to be allow-listed and to state a reason (`:49-60`).

## Q4 — is every gate key seeded, and who loses what?

All nine distinct keys live inside `RolesAndPermissionsSeeder::permissionNames()`
(function body `apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-563`).
Role grants read from `rolePermissionGrants()` (`:578-869`); role blocks begin at
manager `:585`, cashier `:690`, viewer `:726`, technician `:766`, operator `:791`,
accountant `:825`; `admin` is `Permission::all()` (`:568`).

| # | Route (17) | Gate | Seeded at | Roles holding it | Who loses what |
|---|---|---|---|---|---|
| 1-4 | `DELETE promotions/{id}`, `POST promotions/{id}/{activate,pause,archive}` | `can:promotions.manage` | `:105` | admin `:568`, manager `:665` | **viewer** (holds `promotions.view` `:757`) loses delete + the three transitions — previously checked at **no** layer |
| 5 | `POST uom/units` | `can:uom.create` | `:191` | admin, manager `:601` | nobody — controller already called `authorizeAbility('uom.create')` (`UomController.php:170`) |
| 6 | `PUT uom/units/{id}` | `can:uom.edit` | `:192` | admin, manager `:601` | nobody (`UomController.php:207`) |
| 7 | `DELETE uom/units/{id}` | `can:uom.delete` | `:193` | admin, manager `:601` | nobody (`UomController.php:261`) |
| 8 | `POST uom/unit-text-mappings` | `can:units.manage` | `:194` | admin, manager `:601` | nobody (`UomController.php:52`) |
| 9 | `POST uom/convert` | `can:uom.view` | `:190` | admin, manager `:601`, cashier `:703`, viewer `:742`, technician `:772`, operator `:807` | **accountant** — the only role without `uom.view` (block `:825-868` has no `uom.*`). No live caller today (see Q5) |
| 10-12 | `DELETE menus/{id}`, `DELETE menu-categories/{id}`, `DELETE menu-categories/{categoryId}/items/{itemId}` | `can:menus.manage` | `:101` | admin, manager `:664` | **cashier** and **viewer** (hold `menus.view` `:716`/`:756` and `composite-items.view` `:714`/`:754`) lose all three deletes — previously checked at no layer |
| 13-15 | `DELETE coupons/{id}`, `POST coupons/{id}/{revoke,reactivate}` | `can:coupons.manage` | `:109` | admin, manager `:666` | **viewer** (holds `coupons.view` `:758`) loses all three |
| R1-R2 | `GET coupons`, `GET coupons/{id}` | `can:coupons.view` | `:108` | admin, manager `:666`, viewer `:758` | **cashier, technician, operator, accountant** lose the reads — none of them can reach the `/pos/coupons` page, which is already FE-gated on `coupons.view` (`apps/web/src/routes/index.tsx:3054`) |

`authorizeAbility()` is `Gate::allows()` (`apps/api/app/Shared/Authorization/AuthorizesAbility.php:19-24`),
i.e. the route `can:` gate is decision-identical to the four controller checks it moves —
no widening, no narrowing.

**manager loses nothing.** Confirmed for all nine keys.

## Q5 — is any live surface broken?

Frontend callers of the 17 (apps/web only — `apps/pos/src` has **zero** callers of any of
the 17; grep for `uom/|promotions|coupons|menu-categories|/menus` returns nothing):

| Surface | FE guard | Roles reaching it | Verdict |
|---|---|---|---|
| `/pos/coupons` list + form (`features/coupons/hooks/useCoupons.ts`, `pages/CouponListPage.tsx`) | `coupons.view` (`routes/index.tsx:3054`), `coupons.manage` on new/edit (`:3064,:3074`) | admin, manager, **viewer** | Page still loads for viewer; the **row actions are not permission-gated in the UI** (`CouponListPage.tsx:203,212` and the delete arm `:82-88`) → viewer now gets a 403 toast. Handback probed exactly this |
| `/pos/promotions` list (`pages/PromotionListPage.tsx`) | `promotions.view` (`routes/index.tsx:3023`) | admin, manager, **viewer** | Same: `PromotionListPage.tsx:187,196,205,214` render activate/pause/archive/delete with no permission check → viewer 403s. **Not probed in the handback** |
| `/menus`, `/menus/:id/edit` (`features/menu/pages/MenuListPage.tsx`) | `ModuleGuard module="Menu"` + `composite-items.view` (`routes/index.tsx:2612-2621`, `:2634-2643`) | admin, manager, **cashier**, **viewer** | Delete button at `MenuListPage.tsx:184-196` is not permission-gated → **cashier and viewer** 403 on `menus.manage`. **Not probed in the handback** |
| Units settings (`features/uom/api/uomApi.ts:72,87,101,126`) | `uom.view` (`routes/index.tsx:2416`) | admin, manager, cashier, viewer, technician, operator | No NEW denial — the four writes were already controller-checked. Viewer's 403 on `POST /uom/units` is pre-existing behaviour, now returned earlier |
| `POST /uom/convert` (`uomApi.ts:112` → `hooks/useConversion.ts:24` → `components/ConversionCalculator.tsx:86`) | — | — | `ConversionCalculator` is **never mounted**: grep for the component name outside its own file returns nothing. No live caller, so accountant's lack of `uom.view` costs nothing today |

**Wave-0b routes are NOT gated** (the blocker condition): `GET api/v1/roles`,
`GET api/v1/roles/{id}`, `GET api/v1/permissions` and `GET api/v1/users/{userId}/roles`
are all still in the uncovered baseline
(`baselines/route-permission-coverage-baseline.json`, verified programmatically) and no
Identity read carries a `can:`. Residual R-0 stands unchanged — correct.

## Q6 — baseline arithmetic, re-derived independently. **EXACT.**

From `02-route-enforcement-sweep.csv` (1054 rows) I took every row classified
`AUTH_ONLY|CONTROLLER|FORMREQUEST|POLICY` (= not covered by route middleware under E-2):
**326**. Subtracting the 4 tombstone keys and the 7 self-service keys — all 11 verified
present in that set — gives **315**. Subtracting the 17 keys this wave gates gives **298**.

Diffed against the shipped baseline, both directions:

```text
expected 298 baseline 298
in baseline not in CSV-derived: []
in CSV-derived not in baseline: []
```

- `generated_write_count = 152`, `generated_read_count = 146`, 298 unique keys, no duplicates.
- `UNCOVERED_WRITE_CEILING = 152` (`RoutePermissionCoverageRatchetTest.php:72`),
  `UNCOVERED_READ_CEILING = 146` (`:84`). 152+15 = **167**, 146+2 = **148** ✔.
- My naive split of the CSV gave 166/149; the one-route delta is
  `GET|POST api/v1/platform/catalog/articles/search-by-criteria`, which the classifier
  counts as a **write** (`isWrite()` uses `str_contains` over the joined verbs,
  `RouteCoverageClassifier.php:107-118`). The live number is therefore 167/148 — the
  plan's figure is right and my first pass was wrong.
- `POST api/v1/coupons/validate` is still in the baseline and pinned as deliberately
  uncovered (`RoutePermissionCoverageRatchetTest.php:160-172`); the route is ungated in
  `Coupon/Presentation/routes.php:26`.
- **294 / read-ceiling 142 does NOT occur** — 0b's four Identity reads are untouched.

Tamper T2 independently confirmed the live count: removing `can:coupons.manage` from
`DELETE coupons/{id}` produced `Uncovered WRITE routes (153) exceed
UNCOVERED_WRITE_CEILING (152)` — i.e. the tree really is at 152.

## Q7 — ratchet directions. **Both fail closed; the skip names 0b-11 and its replacement.**

- Growth: `RouteCoverageRatchetChecker::check()` `array_diff(report, baseline)` → message
  ending `NEW ROUTES CANNOT BE ADDED TO THE COVERAGE BASELINE.`
  (`Support/RouteCoverageRatchetChecker.php:14,19-24`); asserted live at
  `RoutePermissionCoverageRatchetTest.php:174-183`; **fired in tampers T2 and T3**.
- Stale: `array_diff(baseline, report)` → "DELETE them from the baseline in the same commit
  that gated the route" (`:15,26-30`); tamper-proven with an injected route at
  `RoutePermissionCoverageRatchetLivenessTest.php:192-205`.
- Ceilings are shrink-only and separate (`RoutePermissionCoverageRatchetTest.php:186-205`).
- Anti-growth skip (`:249-262`) names `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB`, **wave
  0b-11**, the owner-held repository VARIABLE, and instructs 0b-11 to "replace this skip
  with the fail-closed-on-unset form used by `DocumentPerActionBaselineRatchetTest`".
- The regenerator deliberately `self::fail()`s after writing (`:288-315`) — it can never be
  a way to go green. Same design in the tombstone regenerator (`TombstoneRouteBehaviourTest.php:174-180`).

## Q8 — middleware ordering. **Nothing displaced; throttle survives.**

Runtime `route:list --json` at the tip:

```text
POST api/v1/auth/resend-verification
    web | Authenticate:sanctum | SetPermissionsTeam | EnforceTokenTenantClaim | AllowSelfService | ThrottleRequests:email-verification
GET|HEAD api/v1/auth/me           web | Authenticate:sanctum | SetPermissionsTeam | EnforceTokenTenantClaim | AllowSelfService
POST api/v1/notifications/{id}/read   api | Authenticate:sanctum | SetPermissionsTeam | EnforceTokenTenantClaim | AllowSelfService
POST api/v1/support-access/sessions/{session}/exit  api | Authenticate:sanctum | SetPermissionsTeam | EnforceTokenTenantClaim | AllowSelfService
GET|HEAD api/v1/coupons           api | ... | EnforceTokenTenantClaim | Authorize:coupons.view
DELETE api/v1/coupons/{id}        api | ... | EnforceTokenTenantClaim | Authorize:coupons.manage
POST api/v1/uom/convert           api | ... | EnforceTokenTenantClaim | Authorize:uom.view
POST api/v1/promotions/{id}/archive  api | ... | EnforceTokenTenantClaim | Authorize:promotions.manage
```

`SetPermissionsTeam` precedes `EnforceTokenTenantClaim` everywhere (rule 12 / Invariant D);
`authz.self` and every `can:` are **appended** at route level, displacing nothing;
`throttle:email-verification` survives on `resend-verification`
(`Identity/routes.php:53`). Coupon / Promotion / Menu / Uom / Notification / SupportAccess
groups all still carry `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`
(e.g. `Coupon/Presentation/routes.php:10`, `Promotion/Presentation/routes.php:10`,
`SupportAccess/Presentation/routes.php:49-55`). The **auth** group uses `web` rather than
`api` (`Identity/routes.php:20`) — **pre-existing and unchanged by this lane** (the diff
does not touch `:20`), and necessary: `logout` calls `Auth::guard('web')->logout()` and
`$request->session()->invalidate()` (`AuthController.php:577-579`).

## Q9 — overlap. **Only `.github/workflows/ci.yml`; hunks disjoint.**

(My first pass used an unquoted multi-line pathspec under zsh and produced a false
"no overlap" — re-run with `grep -Fx -f <file-list>`.)

```text
lane/w-lot-a-1a        -> .github/workflows/ci.yml   @@ -1130,7 +1130,7 @@
lane/t2-receipt-spine  -> .github/workflows/ci.yml   @@ -1138,8 +1138,12 @@
lane/imp1-history-export -> .github/workflows/ci.yml @@ -1139,7 +1139,7 @@
```

All three sit inside `backend-test-pgsql` (`dev` `:585-1182`). This lane's hunk is
`@@ -234,6 +234,27 @@`, inside `backend-architecture` (`dev` `:143-243`). ~880 lines
apart; no intersection. No other path collides — including
`apps/api/app/Modules/Inventory/Presentation/routes.php` (t2) and
`apps/api/app/Modules/BatchExpiry/Presentation/routes.php` (w-lot-a-1a), which this wave
deliberately does not touch.

## Q10 — entrypoint. **Never blocks boot; central reset survives.**

`apps/api/docker/entrypoint.sh` is `#!/bin/sh` with `set -e` at `:2`. The per-tenant reset
is the **condition** of an `if` (`:192`) — POSIX suspends `-e` for the condition of an
`if`, so a non-zero exit takes the `else` arm (`:194-196`) and the script continues. Both
arms print (`[completed]` / `[ABORTED - …]` with the FULL-fleet remediation, not the
`--tenants=` diagnostic). `2>/dev/null` is gone from that line. The bare central reset
survives verbatim at `:197`: `php artisan permission:cache-reset 2>/dev/null || true`.
Pinned by `EntrypointPermissionCacheResetShapeTest.php:36-80`.

---

## Test tail (lane worktree, `apps/api`, no PostgreSQL leg)

Prescribed eight-class command:

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
....................SS......F..................................  63 / 111 ( 56%)
................................................                111 / 111 (100%)

1) Tests\Architecture\AuthLifecycleTest::test_every_auth_sanctum_route_group_includes_set_permissions_team
Found Route::middleware(...) call(s) with a non-literal-array argument:
  - app/Modules/POS/routes.php:114

Tests: 111, Assertions: 674, Failures: 1, Skipped: 2.
```

The single failure is **PRE-EXISTING ON `dev`**, reproduced by me in the `dev` checkout
(`/Users/houssamr/Projects/syneriva/apps/erp/apps/api`) with the identical message and
file:line — `Tests: 2, Assertions: 32, Failures: 1`. `app/Modules/POS/routes.php:114`
(`Route::middleware(EnsureWebPosDemoTenant::class)`, a `ClassConstFetch`) is byte-identical
at base `477c877a3` and at the lane tip, and `AuthLifecycleTest.php` is untouched by the
lane. `.github/workflows/ci.yml:277-282` already records `AuthLifecycleTest` as one of four
known pre-existing Architecture reds — which is exactly why CI runs classes by path.

The six classes the CI step actually registers:

```text
....................SS......                                      28 / 28 (100%)
OK, but some tests were skipped!
Tests: 28, Assertions: 179, Skipped: 2.
```

Static gates re-run by me over the diff's **25** PHP files:

```text
phpstan analyse --level=8  ->  [OK] No errors
pint --test                ->  {"result":"pass"}
```

## Tamper results (all planted and reverted by me; nothing committed)

| # | Tamper | Expected | Observed |
|---|---|---|---|
| T1 | `->middleware('authz.self')` on `GET api/v1/notifications` (not allow-listed) | allow-list test red, ratchet unchanged | **RED** `no_route_wears_the_marker_without_an_allow_list_entry`; ratchet still clean — marker bought nothing |
| T2 | removed `can:coupons.manage` from `DELETE coupons/{id}` | 3 directions red | **RED ×3**: declared-gate, growth (`not in the baseline`), write ceiling `153 > 152` |
| T3 | deleted the `POST api/v1/pos/receipts` tombstone fixture entry | growth direction fires | **RED**: `the_fixture_holds_exactly_four_entries…` + growth `POST api/v1/pos/receipts` + ceiling 153 |
| T4 | planted `if ($id === 'legacy-terminal-7') { … 200 }` in the payments tombstone closure | source pin red | **RED** source pin (the 410-invocation test still passed — the rev-2 hole, now closed) |
| T5 | re-pinned the fixture `source` to the tampered token stream (laundering) | token assertions red | **RED** `contains forbidden token(s) T_IF` |

After reverts: `git status --short` empty, `HEAD = b3e35990d…`, no commits created.

## Handback cross-check

| Claim | Status |
|---|---|
| 28 tests / 179 assertions / 2 skips on the six CI classes | **VERIFIED** — byte-identical tail reproduced |
| 25-file PHPStan level 8 `[OK] No errors` + Pint `{"result":"pass"}` | **VERIFIED** — re-run; diff contains exactly 25 `.php` paths |
| `167/148/315 → −15/−2/17 → 152/146/298` | **VERIFIED** independently from the CSV (298, zero-diff both directions) and from the live router (T2 showed 153 after a single un-gate) |
| The 17 stale-baseline keys listed | **VERIFIED** — identical to `WAVE_0A_WRITE_CLOSURES` + `COUPON_READ_CLOSURES` (`RoutePermissionCoverageRatchetTest.php:106-133`) |
| `AuthLifecycleTest` red on lane AND on clean `dev` `477c877a3` | **VERIFIED** — reproduced in the `dev` checkout |
| Overlap `claimed=0`, hunks `1130 / 1138-1145 / 1139` vs `143..243` | **VERIFIED** — only `ci.yml`, disjoint |
| `feature-lane-manifest.json` unmodified; manifest check green | **VERIFIED** — not in the diff; `FeatureLaneManifestCheckerTest` green in my run |
| No `apps/web/` path, no seeder/migration change | **VERIFIED** — 33-file diff inspected |
| `markRead` scoped to `$request->user()` | **VERIFIED** (`NotificationController.php:57`) |
| Tombstone source pins derived mechanically | **VERIFIED** by re-derivation: the pins match the live closures token-for-token |
| Probe A / Probe B / viewer-denial browser runs, "zero 5xx", console capture, PharmaBio re-seed, temporary `Menu` extras toggle | **UNVERIFIED** — browser/tenant state at the time of the probes is not reproducible from this tree. Recorded as reported, not accepted as proven. The parts that *are* checkable (viewer lacks `coupons.manage`/`uom.create`; viewer holds `uom.view`) are consistent with the reported 403/403/200 |
| "The plan expects 200 for the deletes; controllers return 204" (Deviation 6) | **PLAUSIBLE, unverified** — no feature test was run in this gate |
| Task-2 combined `Tests: 16, Assertions: 115, Skipped: 2` | **CONSISTENT** with my 28/179/2 over the six classes |

---

## Findings

| id | sev | file:line | claim | required fix |
|---|---|---|---|---|
| **M-1** | MAJOR | `apps/api/docker/entrypoint.sh:160-171`; `apps/api/.env.example:178` | The 17 new gates depend on nine **pre-existing** keys being present on every ALREADY-PROVISIONED tenant database. The fleet catalogue sync is **opt-in** — `SYNC_PERMISSIONS_ON_BOOT` defaults to `false` — and the entrypoint's own comment names **`uom.view 2026-06`** as a past instance of exactly this "new permission missing on existing tenants → 403" gap. This wave gates `POST /uom/convert` on `uom.view` and three deletes on `menus.manage`. A staging/prod tenant seeded before those keys existed turns a working action into a 403 the moment this lane deploys. The handback's "Merge readiness" and "Residuals" carry no such precondition; its own local run hit it ("the local PharmaBio tenant's roles were stale, so its current `RolesAndPermissionsSeeder` was rerun"). | Add one promotion-precondition line to the handback and to the promotion checklist: before/with the deploy carrying this lane, run `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` (or set `SYNC_PERMISSIONS_ON_BOOT=true` for that deploy), then the per-tenant `permission:cache-reset`, and spot-check that `manager` holds `menus.manage`, `coupons.manage`, `promotions.manage`, `uom.create/.edit/.delete`, `units.manage` on each staging tenant. No code change. |
| **M-2** | MAJOR | `apps/web/src/features/menu/pages/MenuListPage.tsx:184-196`; `apps/web/src/routes/index.tsx:2612-2621`; `apps/web/src/features/promotions/pages/PromotionListPage.tsx:187,196,205,214`; `apps/web/src/routes/index.tsx:3023` | Two of the three newly-denied surfaces were **not probed**. `cashier` and `viewer` reach `/menus` (guard is `composite-items.view`, which both hold at seeder `:714`/`:754`) and the Delete button carries no permission check → both now get a 403 on an action the UI still offers. `viewer` reaches `/pos/promotions` (guard `promotions.view`, held at `:757`) and the activate/pause/archive/delete buttons are likewise unguarded. The handback probed only the viewer/coupons and viewer/uom cases. The denials are the intended hardening (none of these roles should mutate menus or promotions) — the gap is that the record understates the blast radius and no follow-up is filed. | Record both cases in the handback's deviations/residuals, and file the UI follow-up for wave 0b/2a: gate the Menu delete on `hasPermission('menus.manage')` and the Promotion row actions on `hasPermission('promotions.manage')` (the Coupon page needs the same for `coupons.manage`). No change to this lane's backend. |
| m-1 | minor | `.claude/commands/add-permissions.md:51-57` | Step 5 now prescribes `permissions:ensure-fleet` (wave 0b) and `permissions:sync-fleet` (wave 1); **neither exists at this tip** (`php artisan list` shows only `permissions:export-frontend-map` and Spatie's `permission:*`). The document is explicit about the wave labels, but it leaves a reader today with no working command — the same class of defect Task 5 exists to fix (`permission:` alias that never existed). | Add a "today" arm: `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` (the form `docker/entrypoint.sh:165` already uses), marked "until 0b lands `permissions:ensure-fleet`". |
| m-2 | minor | `apps/api/tests/Architecture/AuthLifecycleTest.php:194`; `apps/api/app/Modules/POS/routes.php:114` | `AuthLifecycleTest` is red on the lane **and** on clean `dev` (reproduced). The plan's verification checklist line "`AuthLifecycleTest` — green, unchanged" is unachievable at this base. The handback records it correctly (Deviation 1) and the lane's CI step does not include the class, so nothing is gated on it. | Informational. Correct the plan's checklist line in the next rev; do not touch POS from this lane. |
| m-3 | minor | `docs/glossary.md` (absent from the diff; deferred by plan rev 5.1 `:95`) | Convention 11: the wave introduces three reviewable nouns — the `authz.self` self-service marker, the five-state `RouteCoverage` classification, and "tombstone route" — none of which has a glossary row. The plan deliberately excludes `docs/glossary.md` because another lane owns it. | Record a 0b follow-up to add the three rows (with `authz.self` declared as a synonym of "self-service declaration", explicitly NOT a gate). |
| m-4 | minor | `apps/api/app/Modules/SupportAccess/Presentation/routes.php:60`; `apps/api/app/Modules/SupportAccess/Application/Services/SessionLifecycleService.php:146`; `apps/api/database/migrations/2026_08_06_230000_create_impersonation_access_tables.php:49` | `{session}` is bound straight into `ImpersonationSession::query()->lockForUpdate()->findOrFail($sessionId)` against a PostgreSQL `uuid` primary key with **no** `->whereUuid('session')` on the route — a non-UUID path segment 500s on PG (the house pitfall). `POST notifications/{id}/read` does carry `->whereUuid('id')`. Pre-existing; this lane only added the `authz.self` marker to the route. | Ticket only (not this lane): add `->whereUuid('session')`, or `Str::isUuid()` + 404 in the controller. |
| m-5 | minor | `apps/api/app/Modules/Uom/Presentation/routes.php:17-24`; `apps/api/app/Shared/Authorization/AuthorizesAbility.php:19-24` | For the four UoM routes the denial now comes from `Authorize` **before** the FormRequest instead of from `PermissionDeniedException` inside the controller: the 403 body's `ability` field becomes `null` (the handback's viewer probe shows exactly that), and a bad payload from an unauthorised caller now returns 403 rather than 422. The decision itself is identical (`Gate::allows`). No consumer is affected — grep for `.ability` across `apps/web/src` and `apps/pos/src` returns nothing. | Informational; mention in the handback so a future client-side consumer of `error.ability` is not surprised. |

---

## What to fix before merge

Add two record lines — the tenant-catalogue sync precondition (M-1) and the
cashier/viewer menu + viewer promotion denial follow-up (M-2) — to
`docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md`; no code change is required, and the lane
is otherwise mergeable as-is.

*Reviewer did not merge, push, rebase or commit anything. All tampers reverted; lane tree
verified clean at `b3e35990d`.*
