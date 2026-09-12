# RBAC wave 0a handback

Status: review

## Lane and base

- Branch: `lane/rbac-w0a`
- Task 0 already-done tip: `ed88aa2ed23db17b006850e0a0f2466a163f5e2f`
- Observed `dev`: `477c877a341a8f228c01489c04243befc229d3e5` (`477c877a3`)
- Merge of `dev` into the lane: `007b35ed85a83269af97c55cf2f519a6472b18cf`
- Bootstrap: existing vendor tree, `composer dump-autoload` only. Versions: PHPUnit 11.5.55, PHPStan 2.1.54, Pint 1.29.0.
- Task commits:
  - `ed88aa2ed` — `Phase 0.1.0: Report the per-tenant permission cache reset's outcome in the deploy entrypoint`
  - `24ec28235` — `Phase 0.1.1: Add the authz.self self-service marker, its alias and its exact seven routes`
  - `a4bc7ed6d` — `Phase 0.1.2: Install the route-coverage ratchet with its scanner, tombstone behaviour test and tamper cases`
  - `fd796c0df` — `Phase 0.1.4: Gate fifteen writes and two coupon reads with existing permission keys`
  - `e286845a8` — `Phase 0.1.5: Correct the two documents that teach authorization`
  - `8a82301b9` — `Phase 0.1.6: Register wave 0a's Architecture classes in the always-on backend-architecture job`
  - Task 0a-1 / S-1 was already merged into `dev` at `6415062b9`; this lane does not duplicate it.
  - There is no Task 3. The four Identity read gates moved to wave 0b task 0b-15.

## Phase 0 overlap evidence

Overlap check A used the literal 32-path array and all three active lanes:

```text
paths: 32
claimed=0
```

No `CLAIMED` line was emitted.

Overlap check B against the current `backend-architecture` old-file range `143..243`:

```text
lane/w-lot-a-1a:        @@ -1130,7 +1130,7 @@
lane/t2-receipt-spine:  @@ -1138,8 +1138,12 @@
lane/imp1-history-export: @@ -1139,7 +1139,7 @@
```

None intersects `143..243`.

## TDD and verification evidence

### Task 0 — deploy entrypoint visibility

The pre-fix failure captured by the already-completed Task 0 was:

```text
apps/api/docker/entrypoint.sh:190 sends the per-tenant permission cache reset's stderr to /dev/null, which hides an aborted tenants:run loop from the deploy log. Drop the redirect and report the outcome.
```

Current path-only green re-check:

```text
OK (3 tests, 13 assertions)
```

The entrypoint continues booting on failure, reports both completion and abort, and preserves the bare central reset. Per-tenant failure isolation remains residual R-2.

### Task 1 — exact self-service allow-list

First intended red:

```text
Allow-listed routes that do NOT carry the authz.self middleware: GET api/v1/auth/me, POST api/v1/auth/logout, POST api/v1/auth/logout-all, POST api/v1/auth/resend-verification, POST api/v1/notifications/read-all, POST api/v1/notifications/{id}/read, POST api/v1/support-access/sessions/{session}/exit. Either apply the marker...
```

The same run also reported the expected allow-list/observed-array difference. Before marking `POST notifications/{id}/read`, its implementation was checked to use the authenticated user's relation:

```php
$this->user($request)->notifications()->findOrFail($id);
```

Green:

```text
OK (7 tests, 14 assertions)
```

Task-scoped PHPStan and Pint passed.

`AuthLifecycleTest` is independently red on both this lane and clean `dev` `477c877a3` because `apps/api/app/Modules/POS/routes.php:114` passes a computed value to `Route::middleware(...)`. POS is explicitly out of this lane's scope; see Deviations.

### Task 2 — route-coverage ratchet and liveness

Missing-baseline intended red:

```text
Could not read tests/Architecture/baselines/route-permission-coverage-baseline.json
Failed asserting that false is of type string.
```

The first implementation used an unsuppressed missing-file read and produced an `ErrorException` before this load-bearing assertion. The read was narrowed to `@file_get_contents`, then the prescribed red above was rerun and captured.

Tombstone behavior before baseline generation:

```text
OK (4 tests, 84 assertions)
```

Deliberate baseline regeneration red:

```text
Baseline regenerated with 167 writes and 148 reads. Review the diff, set the two ceilings to those numbers, then re-run WITHOUT ROUTE_COVERAGE_BASELINE_REGENERATE.
```

Generated baseline: 315 keys. Live-router universe: 1054 routes, 38 skipped outside the API/action universe.

Manual growth-direction proof, after temporarily removing `DELETE api/v1/batches/{uuid}` from the baseline:

```text
These routes have no action gate and are not in the baseline: DELETE api/v1/batches/{uuid}. Add can:<permission> ... NEW ROUTES CANNOT BE ADDED TO THE COVERAGE BASELINE.
Baseline: tests/Architecture/baselines/route-permission-coverage-baseline.json
Failed asserting that false is true.
```

The key was restored and the ratchet returned green.

Tombstone source pins generated and installed mechanically:

```text
function ( ) { return response ( ) -> json ( [ 'error' => [ 'code' => 'NEW_SALE_AUTHORING_RETIRED' , 'message' => 'POST /api/v1/pos/receipts is retired for new-sale SALE_RECEIPT authoring per fiscal Phase 1 §14.2. Receipts are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.' , ] , ] , 410 ) ; }
function ( ) { return response ( ) -> json ( [ 'error' => [ 'code' => 'LEGACY_VOID_RETIRED' , 'message' => 'POST /api/v1/pos/receipts/{id}/void is retired (document-per-action remediation V9, owner ruling D3). A sealed receipt is corrected by a device-authored refund (REFUND_RECEIPT / PARTIAL_REFUND) ingested via POST /api/v1/pos/sync/fiscal-events, never by mutating the original receipt.' , ] , ] , 410 ) ; }
function ( string $id ) { return response ( ) -> json ( [ 'error' => [ 'code' => 'NEW_SALE_AUTHORING_RETIRED' , 'message' => 'POST /api/v1/pos/receipts/{id}/payments is retired for new-sale Treasury payment authoring per fiscal Phase 1 §14.2. Receipts and their payment lines are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.' , ] , ] , 410 ) ; }
function ( string $id ) { return response ( ) -> json ( [ 'error' => [ 'code' => 'NEW_SALE_AUTHORING_RETIRED' , 'message' => 'POST /api/v1/pos/orders/{id}/close is retired for new-sale SALE_RECEIPT authoring per fiscal Phase 1 §14.2. Receipts are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.' , ] , ] , 410 ) ; }
```

Task 2 combined green:

```text
Tests: 16, Assertions: 115, Skipped: 2
```

The skips are the explicit regenerator and R-3's protected-blob anti-growth direction. Task-scoped PHPStan and Pint passed.

### Task 4 — existing-key gates

The gate assertions were added before the route changes. After restoring the required test import, the intended first red was:

```text
DELETE api/v1/promotions/{id} is not gated. Spec 4.4.4 requires can:promotions.manage.
Failed asserting that two variables reference the same object.
Expected Gated, Actual Uncovered
```

The first attempt had failed earlier with `Class "Tests\\Architecture\\RouteCoverage" not found` because Pint removed an import while it was still unused; the assertion was corrected and the intended behavioral red was rerun.

After the fifteen writes and two coupon reads were gated, the stale-baseline red named exactly these 17 keys:

```text
DELETE api/v1/coupons/{id}
DELETE api/v1/menu-categories/{categoryId}/items/{itemId}
DELETE api/v1/menu-categories/{id}
DELETE api/v1/menus/{id}
DELETE api/v1/promotions/{id}
DELETE api/v1/uom/units/{id}
GET api/v1/coupons
GET api/v1/coupons/{id}
POST api/v1/coupons/{id}/reactivate
POST api/v1/coupons/{id}/revoke
POST api/v1/promotions/{id}/activate
POST api/v1/promotions/{id}/archive
POST api/v1/promotions/{id}/pause
POST api/v1/uom/convert
POST api/v1/uom/unit-text-mappings
POST api/v1/uom/units
PUT api/v1/uom/units/{id}
```

Baseline arithmetic:

```text
167 writes / 148 reads / 315 keys
  -15 writes /  -2 reads /  17 keys
=152 writes / 146 reads / 298 keys
```

Task 4 step 8b regeneration check:

```text
Baseline regenerated with 152 writes and 146 reads. Review the diff, set the two ceilings to those numbers, then re-run WITHOUT ROUTE_COVERAGE_BASELINE_REGENERATE.
before=8d086fdb8eb93ee3e6630bcc605747d84b5aedba
after=8d086fdb8eb93ee3e6630bcc605747d84b5aedba
IDENTICAL
298 152 146
```

The four affected feature suites passed:

```text
Tests: 153, Assertions: 514, PHPUnit Deprecations: 6, Skipped: 11
```

Task-scoped PHPStan and Pint passed.

### Task 5 — authorization documentation

The pre-fix audit showed the slash command teaching the nonexistent `permission:` middleware alias and the convention document teaching invented or deprecated keys. The documents now point to the generated map and server catalogue, explain route-first action gates, and use the production Promotion routes file as the canonical example.

The Promotion code block was extracted and diffed byte-for-byte against `apps/api/app/Modules/Promotion/Presentation/routes.php`; `diff` produced no output.

Verified citations:

- Policies are registered in `apps/api/app/Providers/AppServiceProvider.php:270-276`.
- Promotion read defense-in-depth gates are in `PromotionController.php:29,69`.
- Promotion write FormRequest checks are in `StorePromotionRequest.php:17` and `UpdatePromotionRequest.php:17`.
- `reports.view` is deprecated in `RolesAndPermissionsSeeder.php:325-332`.
- Generated map keys: `accounts.manage:6`, `journal.post:131`, `journal.view:132`, `reports.financial:236`, `reports.operational:238`, `reports.view:239`, `settings.update:253`.
- `usePermissions.ts`: `Permission` at 8, `SERVER_AUTHORITATIVE_PERMISSIONS` at 34, `MODULE_PERMISSIONS` at 55, `ModuleKey` at 172.

### Task 6 — CI, manifest, static analysis, and owned tests

`actionlint .github/workflows/ci.yml` passed. The appended always-on step names exactly the same six wave classes as the local CI-shaped run:

```text
OK, but some tests were skipped!
Tests: 28, Assertions: 179, Skipped: 2.
```

No Feature test path was added. `php tools/feature-lane-manifest-check.php` passed and `tests/feature-lane-manifest.json` is unmodified:

```text
tests/Feature lane manifest OK — 1519 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched against 1936 test classes across all suites.
```

The exact eight-class lane-wide command produced:

```text
Tests: 111, Assertions: 674, Failures: 1, Skipped: 2
```

Its only failure was the inherited `AuthLifecycleTest` POS computed-middleware finding, reproduced unchanged on clean `dev` `477c877a3`. The six classes actually registered in CI are green as shown above.

Exact 25-file analysis and style gates:

```text
PHPStan level 8: [OK] No errors
Pint --test: {"result":"pass"}
```

The cross-check found exactly 25 PHP paths, all represented in those commands, and no `apps/web/` path.

## UI and authenticated network probes

The lane API ran at `127.0.0.1:8010`; the unchanged `dev` frontend ran at `127.0.0.1:5173` and proxied to that API. The local PharmaBio tenant's roles were stale, so its current `RolesAndPermissionsSeeder` was rerun for that tenant before the probes. It reported 304 permissions and refreshed the existing admin, manager, cashier, viewer, technician, operator, and accountant roles. This changed local demo data only.

The Codex in-app browser does not expose a network panel/API. Visible page checks were therefore paired with authenticated `curl` calls against the same lane server to capture exact response codes and denial bodies. No 5xx occurred.

### Probe A — admin

Visible browser checks:

- Settings → Roles rendered 5 roles and the permission matrix.
- Settings → Users rendered 18 users.
- Promotions and Coupons both opened, but the unchanged frontend rendered `No promotions yet` / `No coupons yet` even while the corresponding GET calls returned populated arrays and the database contained the created records. The action status evidence below came from direct authenticated calls because the broken lists expose no row actions.

Network summary:

```text
ADMIN GET /roles 200
ADMIN GET /permissions 200
ADMIN GET /users 200
ADMIN GET /promotions 200
ADMIN POST /promotions 201
ADMIN POST /promotions/{id}/archive 200
ADMIN POST /uom/units 201
ADMIN PUT /uom/units/{id} 200
ADMIN POST /uom/convert 200
ADMIN DELETE /uom/units/{id} 204
ADMIN GET /coupons 200
ADMIN POST /coupons 201
ADMIN POST /coupons/{id}/revoke 200
ADMIN DELETE /menu-categories/{categoryId}/items/{itemId} 204
```

The plan says both deletes should be 200; the controllers' established contract and existing feature tests use 204. Both deletions completed successfully with 204.

### Probe B — manager no-regression

Visible browser checks:

- Settings → Users rendered 18 users.
- Settings → Roles rendered 5 roles and 260 permission records. The manager therefore still reaches the two pages that depend on ungated `GET /roles` and `GET /permissions`.

Network summary:

```text
MANAGER GET /roles 200
MANAGER GET /permissions 200
MANAGER GET /users 200
MANAGER GET /promotions 200
MANAGER POST /promotions 201
MANAGER POST /promotions/{id}/archive 200
MANAGER POST /uom/units 201
MANAGER PUT /uom/units/{id} 200
MANAGER POST /uom/convert 200
MANAGER DELETE /uom/units/{id} 204
MANAGER GET /coupons 200
MANAGER POST /coupons 201
MANAGER POST /coupons/{id}/revoke 200
MANAGER DELETE /menu-categories/{categoryId}/items/{itemId} 204
```

The PharmaBio vertical does not enable Menu. To avoid measuring `module:Menu` instead of RBAC, `Menu` was temporarily appended to this one local tenant's extras, throwaway menu/category/product-link fixtures were created for admin and manager, each item deletion returned 204, the menu fixtures were removed, and the exact original extras `BatchExpiry`, `Loyalty`, `Ecommerce` were restored.

### Viewer denial

The browser rendered the Units page and system units. Submitting the Add Unit dialog visibly produced `Request failed with status code 403`; the dialog remained open and no unit was created. The Coupons list has the same inherited response-shape defect as Promotions, so revoke was exercised through an authenticated request against a throwaway active coupon and then cleaned up as admin.

```text
VIEWER POST /uom/units 403
{"error":{"code":"FORBIDDEN","message":"You do not have permission to perform this action. An administrator can grant access under Settings → Roles.","ability":null}}

VIEWER POST /coupons/{id}/revoke 403
{"error":{"code":"FORBIDDEN","message":"You do not have permission to perform this action. An administrator can grant access under Settings → Roles.","ability":null}}

VIEWER POST /uom/convert 200
{"originalQuantity":"2","convertedQuantity":"2000","conversionFactor":"1000.0000000000"}
```

Console capture was non-empty, but every entry comes from the unchanged `dev` frontend rather than this lane (which has zero `apps/web/` changes):

```text
WARN  CSRF token mismatch, refreshing token...          src/lib/api.ts
ERROR Each child in a list should have a unique "key" prop. Check the render method of RolesPage.
ERROR Access denied: You do not have permission to perform this action. An administrator can grant access under Settings → Roles.  src/lib/api.ts
```

There was also one SQL connection error during initial Docker startup, before any probe began. After PostgreSQL and Redis became ready, all three probes ran with zero 5xx. This lane introduces zero console errors; the strict plan phrase "zero new console errors" is satisfied, while the broader "console output is empty" interpretation is not and is recorded under Deviations.

## Scope audit

- No `apps/web/` file changed.
- No Feature test changed; the feature-lane manifest is unmodified.
- No migration, seeder, new permission key, wave-0b Identity read gate, or out-of-scope Treasury/Accounting/Document/Inventory/Purchasing/POS/Fiscal file changed.
- The final diff contains only the 32 implementation paths shown by `git diff dev...HEAD --name-only`, plus this handback. `.github/workflows/ci.yml` is the one explicitly accepted file-level overlap.

## Residuals

- **R-0 — live, owner wave 0b task 0b-15.** `GET /users/{userId}/roles` remains ungated; any authenticated caller can still read another tenant user's full permission list. This is pre-existing and first in line for 0b-15 with the related frontend guards.
- **R-1 — relocated.** D4 payload shaping moved with Task 3/0b-15; it remains a wave 2a deliverable owned through the wave 0b plan.
- **R-2 — live, owner wave 3 or standalone micro-lane.** `tenants:run permission:cache-reset` has no per-tenant failure isolation. Task 0 made aborts visible but did not implement a rolling runner.
- **R-3 — live, owner wave 0b task 0b-11.** Anti-growth remains skipped until the owner registers `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB`; 0b-11 must add the env line and switch the test to fail closed when unset.
- **R-4 — closed in this wave.** The six wave classes are registered in the always-on PR→`dev` backend-architecture job.

## Deviations and reviewer attention

1. `AuthLifecycleTest` is red because of computed middleware in out-of-scope `apps/api/app/Modules/POS/routes.php:114`. The identical failure occurs on clean `dev` `477c877a3`; no POS file was changed.
2. Current PHPStan required iterating `RouteFacade::getRoutes()->getRoutes()` and returning the direct regular-expression match list instead of the plan's older interface assumptions.
3. The missing-baseline read needed `@file_get_contents` to reach the plan's prescribed type assertion under this error handler.
4. The local browser fixture needed a role-seeder refresh. The Promotions and Coupons pages then exposed an inherited response-unwrapping/rendering problem, so exact action statuses were captured through authenticated API requests to the same lane server.
5. The unchanged frontend emits the Roles-page key error and logs an expected 403 as `console.error`; there are zero lane-introduced frontend errors, but the console is not literally empty.
6. The plan expects 200 for UoM and menu-item deletes; the production controllers and existing feature tests correctly return 204. The probes observed 204.

## Merge readiness

```text
git rev-parse --short dev
477c877a3

git merge-tree --write-tree dev HEAD  # pre-handback commit
a149a1116b72f1c44c71070ab6349aa68d756931

git merge-base dev HEAD
477c877a341a8f228c01489c04243befc229d3e5

32-path git diff <merge-base>..dev
empty

git diff dev...HEAD --name-only | grep '^apps/web/'
empty
```

Final diff stat (including this handback):

```text
33 files changed, 2929 insertions(+), 107 deletions(-)
```

The lane is ready for the orchestrator's whole-diff `tenancy-authz-reviewer`. It has not been pushed, rebased, or merged into `dev`.

## Gate r1 addendum (orchestrator, 2026-09-12)

`tenancy-authz-reviewer` gate r1 on tip `b3e35990d`: **MERGE-WITH-FIXES — 0 BLOCKER / 2 MAJOR / 5 minor**; register `docs/superpowers/reviews/2026-09-12-rbac-w0a-impl-gate-r1-tenancy.md` (audit branch `docs/rbac-audit-2026-09-09`). Both majors are records, not code; they are recorded here as the gate requires.

- **M-1 — promotion precondition (tenant permission catalogue).** The 17 gates rely on nine pre-existing keys (`menus.manage`, `coupons.view`, `coupons.manage`, `promotions.manage`, `uom.view`, `uom.create`, `uom.edit`, `uom.delete`, `units.manage`) being present on every already-provisioned tenant. Fleet sync is opt-in (`SYNC_PERMISSIONS_ON_BOOT=false`, `apps/api/docker/entrypoint.sh:160-171`); the local PharmaBio tenant was stale and had to be reseeded during the probes. **Before or with the deploy that carries this lane:** run `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` (or set `SYNC_PERMISSIONS_ON_BOOT=true` for that deploy), then the per-tenant `permission:cache-reset`, and spot-check on each staging tenant that `manager` holds all nine keys. Owner decision owed: the seeder's `syncPermissions` overwrites tenant-edited roles (audit finding), so the owner chooses seeder rerun vs the flag for that deploy. Recorded in the RBAC resume brief as an owner-owed item.
- **M-2 — newly denied surfaces not probed.** `cashier` and `viewer` reach `/menus` (guard `composite-items.view`) and its Delete button has no permission check (`apps/web/src/features/menu/pages/MenuListPage.tsx:184-196`); `viewer` reaches `/pos/promotions` (guard `promotions.view`) with unguarded activate/pause/archive/delete row actions (`apps/web/src/features/promotions/pages/PromotionListPage.tsx:187,196,205,214`). Both now answer 403 on an action the UI still offers. The denial is the intended hardening; the record understated the blast radius. UI follow-up filed for wave 0b/2a: `docs/superpowers/tickets/2026-09-12-rbac-w0a-unguarded-row-actions-menus-promotions-coupons.md` (gate Menu delete on `menus.manage`, Promotion row actions on `promotions.manage`, Coupon row actions on `coupons.manage`). No `apps/web` change in this lane.
- Minors m-1..m-5 are carried in the register: m-1 `add-permissions.md` needs a "today" arm (`tenants:seed --force --class=…RolesAndPermissionsSeeder`) until 0b lands `permissions:ensure-fleet`; m-2 `AuthLifecycleTest` is red on clean `dev` too (plan checklist line to correct in the next rev); m-3 glossary rows for `authz.self` / `RouteCoverage` / tombstone owed to 0b; m-4 pre-existing PG UUID 500 on `POST support-access/sessions/{session}/exit` (ticket, not this lane); m-5 UoM denial body `ability` is now null (no consumer).
