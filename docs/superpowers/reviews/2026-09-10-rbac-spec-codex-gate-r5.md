# Codex spec gate r5 — roles & permissions catalogue design rev 5 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`3107eeba7`** on `docs/rbac-audit-2026-09-09`.

The declared application-code base remains reproducible: `git diff 971528977..3107eeba7 -- apps/api apps/web apps/pos packages/shared` is empty. `apps/api/vendor/autoload.php` is absent, so Artisan could not boot; route arithmetic was recomputed with a real CSV parser from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`.

Current refs at final verification:

| Ref | SHA | `git diff --shortstat dev...ref` |
|---|---:|---|
| `dev` | `febeb89a0` | — |
| `lane/w-lot-a-1a` | `2fa724c1d` | 81 files, 4,390 insertions, 646 deletions |
| `lane/t1-transfers-edge` | `86273346a` | empty |
| `lane/t2-receipt-spine` | `93b106461` | 86 files, 5,979 insertions, 423 deletions |

## Rev-4.1 closure table

| Rev-4.1 finding | Rev-5 disposition |
|---|---|
| B-1 frozen-v0 adoption invalidated by rename and 0b additions | **NOT CLOSED.** Rename canonicalisation is added at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:871-878`, but the revised v0 oracle makes the preceding `permissions:ensure` grant impossible; see B-1 below. |
| B-2 template delta failed to remove active absent keys | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1035-1045,1708-1718`. Concurrent operator-edit safety remains unspecified as a new blocker, B-2. |
| B-3 unconditional `DatabaseMigrated` listener bypassed the flag | **CLOSED** operationally at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1127-1140`. The convention-10 G3 row remains contradictory; see M-3. |
| B-4 `coupons.view` called dead while used by FE | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1675-1706,1977`. The live uses remain `apps/web/src/routes/index.tsx:3051-3059` and `apps/web/src/hooks/usePermissions.ts:112`; rev 5 now gates API reads at `apps/api/app/Modules/Coupon/Presentation/routes.php:12,14`. |
| M-1 generic role assignment could mutate service principals | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:343-357,1904`. |
| M-2 Batch second-location test could not exercise location denial | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1842`, using the real rule at `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:83-91` behind `apps/api/app/Modules/Inventory/Presentation/routes.php:126-128`. |
| M-3 wave 0a overlapped current lane tips | **CLOSED in the operative task list** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1959-1965,1971-1988`. The accepted manifest overlap is the only measured overlap. Stale contradictory lead-in prose remains; see minor m-2. |
| M-4 mutation counters conflicted with persistent gauges | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:966-984,1057`. |
| minor 1 marker overstated as making the old delta a no-op | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:931-937,1902`. |
| minor 2 three Parapharmacy `users` writers missing | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:238-246`. |
| minor 3 `RoleSyncedV1` used an unversioned event name | **NOT CLOSED end-to-end.** `getEventName()` is corrected at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1484`, but the class comment and EC-35 still require the old name at `:1470,1905`; see minor m-1. |
| minor 4 retired-symbol/change-log and stale step references | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1866,2239`. |
| Rev-4 citation audit | **NOT CLOSED.** New and surviving stale claims are enumerated under Citation audit. |
| Rev-4 rejected findings | **REJECTED-correctly: none.** Rev 5 itself records that round 4 rejected nothing at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2242`. |

## BLOCKER

### B-1 — The rev-5 v0 oracle makes the first wave-0b grant unreachable

Rev 5 settles:

- `version0(role) = postA1a(role) ∪ wave0bAdditions(role)` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:871-878`;
- `matchesVersion0()` compares the current set to that union at `:878`;
- `permissions:ensure` invokes that same oracle before granting a wave-0b key at `:1199-1206`.

For a pristine post-A-1a `manager`, let `P` be its canonical post-A-1a set and `A` the seven approved additions. Before ensure runs, `current = P`, while `version0 = P ∪ A`. Therefore `matchesVersion0(P)` is false. The role is classified as already customised and receives none of `A`.

This directly falsifies:

- “a pristine manager receives the key” at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1206`;
- `adopted_customised=0` after ensure at `:1216`;
- EC-32c’s pristine-manager result at `:1900`;
- the change-log assertion that r4 B-1 is closed at `:2228`.

The input state is confirmed by the lane: `rolePermissionGrants()` is at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:93-104`, with `manager`/`general_manager` at `:97-98`. Those rows do not contain the seven wave-0b additions listed by rev 5 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:884-888,1996`.

Required correction: preserve the settled v0 union and canonical adoption equality, but give ensure a distinct, explicitly named **pre-0b equality predicate** against canonical `postA1aGrants()`. Eligibility must be snapshotted once per role before any of that role’s seven additions are written, then the approved additions granted as one batch. Reusing `matchesVersion0()` cannot work.

### B-2 — Exact template replacement can race an operator edit and destroy it

Rev 5’s safety proof depends entirely on `customised_at` being set on the first operator permission edit (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:939,1041-1045`). Sync serializes only against another sync by taking its tenant advisory lock at `:1013-1025`. Nothing requires `RoleController::update`, template reapply, or wave-0b `permissions:ensure` to take that lock.

The current writer reads the role and later calls `syncPermissions()` with no transaction or lock at `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247`. Rev 5 adds a transaction for audit-diff computation at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1463`, but does not coordinate it with sync.

A valid interleaving remains:

1. Sync reads `customised_at IS NULL` and the old grant set.
2. The operator writes a new grant set and `customised_at`.
3. Sync applies its stale exact diff, overwriting the operator’s choice.
4. The role ends with `customised_at IS NOT NULL`, disguising the lost choice as protected customisation.

Wave 0b has the analogous equality-check-then-grant race before `customised_at` exists (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1191-1206`).

This violates settled D2. Specify shared serialization between all role-grant writers and ensure/sync—such as the same tenant advisory lock plus role-row locking and a post-lock predicate recheck—and add a PostgreSQL two-connection edge case. EC-20 covers sync-versus-sync only (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1883`).

## MAJOR

### M-1 — The claimed canonical normalisation invariant is not actually pinned

Rev 5 calls `canonical(S)` total, order-free and idempotent because no rename target is also a source, then says `no_manifest_declares_a_rename_source_key` plus `rename_map_is_acyclic` pins that property (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:873-878`).

Those tests do not prove it. Acyclic maps may contain `a→b, b→c`; the manifest/source test says nothing about rename targets. With the stated one-pass definition:

- `canonical({a}) = {b}`;
- `canonical(canonical({a})) = {c}`.

The current eleven-entry map has no chain (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1653-1667`), so today’s data is safe. The append-only guarantee is not. Add either an explicit `rename_targets_are_not_sources` set-disjointness test or define canonicalisation as transitive closure with cycle rejection.

### M-2 — Wave 0a closes six reads, not two

The raw census is correct: 326 uncovered, 177 writes and 149 reads; after four tombstones, six self-service writes and `/auth/me`, the generated ceilings are 167/148 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1272-1280`).

But wave 0a also gates four currently uncovered identity reads:

- `GET /roles` — `apps/api/app/Modules/Identity/routes.php:59`
- `GET /roles/{id}` — `apps/api/app/Modules/Identity/routes.php:61`
- `GET /permissions` — `apps/api/app/Modules/Identity/routes.php:64`
- `GET /users/{userId}/roles` — `apps/api/app/Modules/Identity/routes.php:78`

Rev 5 expressly assigns those to 0a-4 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1976`. Together with the two coupon reads at `:1977`, wave 0a closes **six** reads. The post-0a ceiling is therefore:

- writes: `167 − 15 = 152`;
- reads: `148 − 6 = 142`.

The repeated `148 − 2 = 146` claim at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1280,1974,1988,2234` leaves four units of read headroom and defeats the intended shrink schedule.

### M-3 — Convention-10 G3 still reintroduces the forbidden migration trigger

The operative trigger ruling says rolling migrations never trigger sync and identifies exactly provisioning plus the flagged entrypoint fleet run (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1127-1138`).

The convention-10 matrix still decides **“permissions:sync in tenants:migrate”** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:74`. That is not MATCH/DEFER wording trivia: it directs the plan writer toward the exact listener/coupling rev 5 removed. Change G3 to “provisioning plus flagged deploy fleet sync”; rolling migration may precede the fleet command but must not be its trigger.

## MINOR

### m-1 — `RoleSyncedV1` is not versioned “end to end”

The implementation sketch returns `identity.role.synced.v1` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1484`, but its class comment still says `identity.role.synced` at `:1470`, and EC-35 requires an audit row under the unversioned name at `:1905`. Correct both to `.v1`.

### m-2 — Wave-0a prose contradicts the accepted manifest overlap

The final operative list correctly preserves the single accepted `feature-lane-manifest.json` overlap (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1973,1986-1988`). Earlier prose still says every 0a item touches no lane file at `:1917`, and the superseded ruling moves 0a-1 to 0b at `:1944-1955`. Mark that block superseded or align it with the final ruling.

### m-3 — `reports.view` is entry 22, not entry 23

The authoritative list has 22 entries and places `reports.view` last at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1698-1700`. The follow-up calls it entry 23 at `:1722`.

### m-4 — The Redis narrative retains the impossible interleaving

The corrected EC-28 properly races a second connection outside the atomic script (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1893`). The main mechanism still demands an `INCR` “between the script’s read and its clear” at `:1531`, which Redis Lua atomicity makes impossible. Copy EC-28’s outcome-based wording.

### m-5 — Convention-09 still prescribes the seeder path wave 0b rejected

The wave-0b idempotency cell says running `tenants:seed` twice adds every new key (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1842`). The design already proves that command writes nothing on marker-carrying tenants and replaces it with `permissions:ensure` at `:1177-1208,2002-2005`. Remove the `tenants:seed` assertion.

### m-6 — “All 917 call sites” overstates the token proof

The precise rule says permission-based idioms inherit narrowing while role-name checks do not (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:381-400`). The summary later says all 917 authorization call sites inherit without edits at `:2078`, while wave 2a explicitly converts eight role-name sites before scoped token issuance at `:2022-2025`. Say “all 917 counted permission-based Gate/can sites”; retain the eight-site prerequisite.

### m-7 — The edge register omits provisioning during an in-progress fleet sync

The outcome is derivable: provisioning performs its own sync before assigning `admin` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1131-1134`), and two concurrent syncs serialize through the tenant lock (`:1013-1025,1883`). Add the explicit case: a tenant created before, during, or after the fleet iterator’s snapshot ends with the full catalogue; if both triggers reach it, the second is `ALREADY_CURRENT`. This belongs in the PG two-connection lane.

## Citation audit

| Claim | Result |
|---|---|
| Spec application base is `971528977` | **VERIFIED.** Application paths are byte-identical through read SHA `3107eeba7`; basis is recorded at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7-15`. |
| Rev-5 lane table | **STALE only for `dev`.** Spec says `d418a2656` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:21`; current `dev` is `febeb89a0`. The intervening changes are review documents only. Lane tips and both diff stats at `:22-24` remain exact. |
| W-LOT `rolePermissionGrants()` rows | **VERIFIED.** Real lane lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:93-104`, with admin/manager/general-manager at `:96-98`. |
| W-LOT marker schema | **VERIFIED.** CHECK expression is `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34`, PG constraint at `:38-48`, SQLite guards at `:50-63`, partial unique at `:64-75`. |
| Rename-source citation in ensure supersession | **WRONGLY QUALIFIED.** `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1212` presents lane line numbers under an unqualified HEAD path. At HEAD the real lines are `apps/api/database/seeders/RolesAndPermissionsSeeder.php:192,212,419-421,480-485`; on the intended lane they are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:268,288,495-497,556-561`. |
| All eleven rename sources exist and targets do not | **VERIFIED** against the seeder; the current map is at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1655-1667`. |
| Route census 326/177/149 | **VERIFIED** from the CSV under the exact classifier at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1274-1280`. Post-0a arithmetic is wrong as M-2 describes. |
| Four identity reads are ungated | **VERIFIED** at `apps/api/app/Modules/Identity/routes.php:59,61,64,78`. |
| Coupon reads are ungated and `coupons.view` is live | **VERIFIED** at `apps/api/app/Modules/Coupon/Presentation/routes.php:12,14`, `apps/web/src/routes/index.tsx:3054`, and `apps/web/src/hooks/usePermissions.ts:112`. |
| Deprecation set is 22 and FE/POS-aware | **VERIFIED.** Fixed-string scan found no consumer for 21 entries; `reports.view` hits are comments/tests/i18n, including `apps/pos/src/components/pos/TodaySalesPanel.tsx:308`. `coupons.view` is correctly excluded. Only its later “entry 23” label is wrong. |
| Stock-adjustment second-location test has a real enforcement path | **VERIFIED.** Gate at `apps/api/app/Modules/Inventory/Presentation/routes.php:126-128`; location rule at `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockAdjustmentRequest.php:83-91`. `validate.location.access` is registered at `apps/api/bootstrap/app.php:118` and attached to zero routes. |
| `StockTransferController:131,221,254` shorthand in convention 09 | **SEMANTICALLY VERIFIED but path is incomplete.** Real citation: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:131,221,254`. |
| `PermissionSeeder` deletion ordering/callers | **VERIFIED.** Key at `apps/api/database/seeders/PermissionSeeder.php:72`; production call at `apps/api/database/seeders/ProductionSeeder.php:68-75`; deletion correctly belongs to 0b-6 after `credit-notes.cancel`, with the seven test consumers handled at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1730-1744`. |
| PHPStan custom-rule precedent | **VERIFIED.** Level 8 and eight direct rules are `apps/api/phpstan.neon:5-8,33-41`; tagged ninth rule at `:43-47`; implementation precedent at `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-90`. |
| `RoleSyncedV1` persisted name | **PARTLY WRONG.** Correct implementation anchor is `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1484`; stale names remain at `:1470,1905`. |
| Spatie/Stancl vendor paths | **SOURCE VERIFIED, local citation non-reproducible.** `apps/api/vendor` is absent in this worktree. Package pins are reproducible at `apps/api/composer.lock:7747-7758` for Spatie 6.25.0 and `:8242-8247` for Stancl 3.10.0. |
| Historical transfer fixture | **VERIFIED only on T2.** It is absent at HEAD but exists at `lane/t2-receipt-spine:apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`; the historical attribution at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2164,2171` assigns it to T2 correctly. |
| Wave-0a no-overlap | **VERIFIED for the final six-item list with one accepted exception.** The only hit in per-path diffs is `apps/api/tests/feature-lane-manifest.json`, modified by W-LOT and T2. The absolute prose at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1917,1944-1948` is stale. |

## Rejected false positives

### Token narrowing and bypass matrix

The route middleware leg is confirmed: Laravel `Authorize::handle()` delegates to Gate authorization ([Laravel 12 `Authorize.php`](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Auth/Middleware/Authorize.php)). AutoERP enables Spatie’s callback at `apps/api/config/permission.php:103-107`. The pinned Spatie callback invokes `checkPermissionTo()` ([Spatie 6.25 `PermissionRegistrar.php`](https://raw.githubusercontent.com/spatie/laravel-permission/d7d4cb0d58616722f1afc90e0484e4825155b9b3/src/PermissionRegistrar.php)), and `checkPermissionTo()` dynamically calls `hasPermissionTo()` while `hasAnyPermission()` iterates `checkPermissionTo()` ([Spatie 6.25 `HasPermissions.php`](https://raw.githubusercontent.com/spatie/laravel-permission/d7d4cb0d58616722f1afc90e0484e4825155b9b3/src/Traits/HasPermissions.php)). AutoERP aliases the trait implementation and overrides both `hasPermissionTo()` and `getAllPermissions()` at `apps/api/app/Modules/Identity/Domain/User.php:56-59,185-212`.

| Idiom | Narrowed? | Result |
|---|---:|---|
| `can:` route middleware | Yes | `Authorize → Gate → PermissionRegistrar::before → checkPermissionTo() → User::hasPermissionTo()`. |
| `$user->can()` | Yes | Uses the same Gate and Spatie callback. |
| `Gate::authorize()` / `Gate::allows()` | Yes | Same callback and dynamic override. |
| Direct `->hasPermissionTo()` | Yes | Calls AutoERP’s override directly. |
| `->hasAnyPermission()` | Yes | Spatie iterates `checkPermissionTo()`, which dynamically reaches the override. |
| `->getAllPermissions()` | Subject: yes; target: intentionally no | Override at `User.php:201-212`. `/auth/me` uses it through `AuthUserData` at `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:34-55` and `AuthController.php:616-627`. A freshly loaded target has no current token and remains unprojected. |
| POS PIN permission payload | Intentionally unnarrowed target view | `PosAuthController` invokes `getAllPermissions()` on the PIN-selected target at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`; that model owns no current request token. |
| `->hasRole()` / `->hasAnyRole()` | **Bypass** | Eight authorization sites remain: `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, and `DiscountPermissionResolver.php:39`. Rev 5 correctly makes their conversion an issuance prerequisite at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2022-2025`. |
| Spatie `role:` middleware | Not present | No registration or use was found. `central_admin_role` at `apps/api/bootstrap/app.php:114-123` is a separate central guard. |

Therefore the substantive token-intersection design is sound once the eight role-name sites are converted. The “all 917” summary is only an editorial overclaim.

### Ratchet and `authz.self`

The classifier is mechanically implementable in PHPUnit from the live router: exact middleware aliases, exact public/self/tombstone allow-lists and method-aware read/write grouping are all deterministic (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1272-1283`).

A developer cannot whitelist a route merely by attaching `authz.self`: the ratchet consults the exact allow-list, not middleware presence, and the shape test rejects other-resource parameters (`:1255-1261`). A developer can still deliberately edit the reviewed allow-list, particularly for a no-parameter route; that is the intended human policy boundary, not an accidental self-certification hole.

### W-LOT-A-1a, NULL-team roles, and schema coexistence

No collision exists between `provisioning_source` and `is_system/template_key/template_version/customised_at`. The lane’s CHECK and partial unique govern only the marker (`lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-75`); the new columns are separate, additive and timestamped later (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1758-1813`).

The NULL-team ruling is preserved: existing role lookup accepts NULL or the tenant id, preserves existing `tenant_id`, and creates only missing template roles as tenant-scoped (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md:3-9`; spec `:1033-1034,1061`). No re-homing is specified.

Replacing `admin = Permission::all()` with `admin = registry->activeKeys()` is an intentional tightening and does not violate A-1a’s manager/general-manager delta contract (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1054`; lane grants at `RolesAndPermissionsSeeder.php:96-104`).

### Principals, writers, Sanctum and data model

No additional production `users` writer was found beyond the exhaustive census at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:238-262`. In particular, rev 5 now includes the three Parapharmacy post-create PIN writes at `apps/api/database/seeders/ParapharmacySeeder.php:1448,1483,1519`.

The table reuse constraints are addressed:

- email is nullable with a tenant-scoped partial unique at `apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`;
- password is currently non-null at `2025_11_30_000003_create_users_table.php:22` and is explicitly altered nullable;
- status remains a string column with `UserStatus` cast at `apps/api/app/Modules/Identity/Domain/User.php:115-125`, making the proposed PG CHECK valid without enum alteration;
- login/PIN ordering, `pinHolders()` human filtering, notifications, all six seat counters, provisioning, FormRequests, location grants and memberships are assigned explicit changes at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:234-280,299-369,538-567`;
- last-admin membership is human-only and covers all removal paths at `:1377-1399`.

Sanctum storage is correctly central: abilities are nullable TEXT and `expires_at` is indexed at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`; the central model is registered at `apps/api/app/Providers/AppServiceProvider.php:196-219`. The global TTL is 30 days at `apps/api/config/sanctum.php:43-53`, with per-row expiry overriding it in the provider.

Tenant resolution reads the central token before authentication at `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:116-134`; middleware priority puts tenant resolution before authentication and the tenant-claim check after team selection at `apps/api/bootstrap/app.php:166-188`. Membership removal can commit separately from central token deletion: the next request is nevertheless denied from the missing active membership, while the queued central revocation retries (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1877-1878`). No tenant migration for `personal_access_tokens` is required.

All new columns have type, nullability, default, indexes, FK and enum ownership stated at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1764-1813`. Both migrations are tenant-scoped and additive; the PG CHECKs operate on varchar/boolean/integer fields and require no `ALTER` of a PostgreSQL enum-typed column.

### Static guards, hardening, conventions and waves

The PHPStan rule is implementable at level 8 using the existing custom-rule pattern, but not without a baseline. Rev 5 correctly acknowledges the large shrink-only literal baseline at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1297-1333`. Enum↔manifest parity is bidirectional at `:1892`; en/fr/ar files exist and label coverage is specified at `:1335-1339`. The generated `Permission` union at `:1341-1351` is backend-generated catalogue output, not a manually maintained domain-DTO interface, so it does not violate CLAUDE rule 7 (`CLAUDE.md:33-34`).

Last-admin and role hardening cover:

- admin permission removal through `RoleController::update`;
- system-role deletion;
- `RoleController::removeRole`;
- `UserController::update`, destroy and deactivate;
- membership removal;
- sync;
- impersonated invocations of the same controllers.

The complete routing is at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1371-1399`. `assignRole` only increases the admin population, while the dedicated service-account role routes are separately constrained at `:343-357`. Effective-permission reads are self-only or require `roles.view`, never merely `users.assign-roles`, at `:1403-1419`.

Convention 11 is otherwise satisfied: one users table and create path for service accounts; one manifest write path and sync projection for permissions; one operator surface per glossary row; and the General-manager wording/surface is adopted from `lane/w-lot-a-1a:docs/glossary.md:21,91` at spec `:119`.

Wave 0a currently has six tasks and one settled manifest overlap. 0a-6 is correctly struck and moved to 0b-6 after `credit-notes.cancel`; `ProductionSeeder` and all seven tests are handled together (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1978,1996,1730-1744`).

Exactly two **automatic** sync triggers remain: provisioning and the flagged fleet command. The manual `SyncPermissions` command is an operator surface, not an automatic trigger (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1131-1138`). A tenant provisioned while fleet sync is running receives its own sync; duplicate execution serializes and is idempotent. Subject to B-1/B-2 being corrected, replacing `SYNC_PERMISSIONS_ON_BOOT`’s seeder call is safe for staging auto-deploy because migrations precede sync and fleet failures remain visible without stopping boot (`:1063-1140`).

## Preserve

The fix round must not reopen or weaken:

- Direction B and settled D1–D8.
- Canonical post-rename adoption equality and the settled v0 definition `post-A-1a ∪ wave-0b additions`.
- Exact add-and-remove template diffs for uncustomised roles; customised and custom-role protections.
- W-LOT’s marker semantics, NULL-team no-re-homing ruling, and exact manager/general-manager grant sets.
- Exactly two automatic sync triggers; no `DatabaseMigrated` listener.
- `admin = PermissionRegistry::activeKeys()`.
- The 326/177/149 starting census and 167/148 generated ceilings; only the post-0a read ceiling changes to 142.
- Active `coupons.view`, the 22-key deprecation set, and FE/POS-aware permission-shaped scanning.
- First-class service principals and `owner grants ∩ token scope`, including the intentional subject/target split and unnarrowed POS PIN target payload.
- Dedicated service-account role routes and typed refusal on generic user-role routes.
- Mutation-versus-gauge result semantics.
- Human-only last-admin floor, `roles.view` read shaping and audit-chain events.
- The two additive tenant migrations, central Sanctum token storage, generated TypeScript union and en/fr/ar label coverage.
- The six-item wave 0a and its single accepted manifest overlap.
- The real second-location test on `POST /stock-adjustments`; do not restore the unattached `ValidateLocationAccess` middleware premise.

## Owner decisions required

Only the four existing questions are genuinely open; no additional owner ruling is required to fix the findings above.

- **OQ-1:** Whether a service account counts toward the last-admin floor. Code does not decide the future policy; the design currently applies the recommended human-only answer at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1379,1399,2084-2088`.
- **OQ-2:** Service-token TTL and the proposed human-token change. Current behaviour is 30-day global TTL with per-row override (`apps/api/config/sanctum.php:43-53`; `apps/api/app/Providers/AppServiceProvider.php:201-219`), so this is a real policy decision at spec `:2090-2094`.
- **OQ-3:** Which existing SoD combinations, if any, to retire. The present ceiling of 11 rising to 18 after A-1a is code-derived at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1567-1590,2096-2099`.
- **OQ-4:** Whether the boot fleet-sync flag defaults true in production after staging soak. With the listener removed this is genuinely open and wholly governs automatic deploy sync (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2101-2104`).

VERDICT: CHANGES-REQUIRED