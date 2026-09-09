> Adversarial verification of 06-synthesis headline claims by the Opus tenancy-authz-reviewer agent, 2026-09-09, at dev HEAD 73f2040c6. Read-only.

# Adversarial verification pass — roles & permissions audit claims

- Repo: `/Users/houssamr/Projects/syneriva/apps/erp`
- Branch: `dev`
- HEAD: **`73f2040c6`**
- Method: every citation below was opened at HEAD. Route middleware was re-derived from a fresh `php artisan route:list --json` (byte-identical size to the auditors' `route-list.json`, 521960 B). No edits, no git writes, no test runs.
- Package versions read from `composer.lock`: `spatie/laravel-permission 6.25.0`, `stancl/tenancy v3.10.0`.

---

## C1 — Spatie permission cache is tenant-blind — **CONFIRMED (mechanism corrected and strengthened)**

Verified facts:
- `apps/api/config/permission.php:192` — `'key' => 'spatie.permission.cache'`. No tenant suffix. Store is `'default'` (`:200`), and `apps/api/config/cache.php:18` resolves `default` to `redis` (`.env.example:102` `CACHE_STORE=redis`) — i.e. a **shared, cross-tenant** Redis store, not `array`.
- `apps/api/app/Modules/Identity/Application/Listeners/` contains exactly one file, `SendEnrichmentNotificationListener.php`. No `ScopePermissionCacheToTenant`, no `RestoreCentralPermissionCache`.
- `apps/api/app/Providers/TenancyServiceProvider.php:47-60` registers **only** `TenancyInitialized -> BootstrapTenancy` and `TenancyEnded -> RevertToCentralContext`. No permission-cache listener.
- `apps/api/docker/entrypoint.sh:176` — `php artisan permission:cache-reset 2>/dev/null || true` at container boot. This is a boot-time flush, not tenant scoping.
- No unmerged fix on any ref: `git log --all --grep` for permission-cache/tenant-blind returns nothing matching; there is no `lane/rh-t1-permission-cache` branch and no `.worktrees/rh-t1` worktree (`git worktree list` shows 10 worktrees, none named rh-t1). The nearest-named branch `lane/rh-t9-cache-store` is **fully merged** (`git log dev..lane/rh-t9-cache-store` is empty) and its commits (`9381de4d5`, `d31769e87`) are about the **websocket entrypoint's cache store**, not Spatie. `git log --all --diff-filter=A -- '*PermissionCache*' '*ScopePermission*'` returns nothing — such a file has never existed on any ref.

**Correction the auditors missed — the reason this is NOT already fixed by the tenancy bootstrapper.** `apps/api/config/tenancy.php:40` *does* enable `CacheTenancyBootstrapper::class`. A reader could reasonably conclude the cache is already tenant-scoped. It is not, for two compounding reasons:
1. `vendor/stancl/tenancy/src/Bootstrappers/CacheTenancyBootstrapper.php:32-34` swaps the container's `cache` **manager** for `Stancl\Tenancy\CacheManager`, whose tagging lives in `__call` (`vendor/stancl/tenancy/src/CacheManager.php:18-36`). `__call` fires only for **undefined** methods.
2. `vendor/spatie/laravel-permission/src/PermissionRegistrar.php:79` stores `$this->cache = $this->getCacheStoreFromConfig()`, and `:89-90` returns `$this->cacheManager->store()`. `store()` **is** defined on `Illuminate\Cache\CacheManager`, so `__call` never runs and Spatie receives the raw, **untagged** repository. Every subsequent `$this->cache->remember($this->cacheKey, …)` (`:221-222`) reads and writes the single global key.

**Why this is Critical, not cosmetic.** `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:25` and `:35` declare `bigIncrements('id')` for both `permissions` and `roles`. Under database-per-tenant each tenant DB has its **own auto-increment sequence**, so permission id `N` means a different permission name in tenant A than in tenant B, while the cached snapshot is keyed only by `spatie.permission.cache`. The cached payload is therefore semantically wrong for every tenant except the one that populated it. Three in-repo call sites already work around this by hand at `forgetCachedPermissions()` (`app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:192`, `app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:213`, `app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:32`), and `BatchExpiryDailyCheckCommand.php:46` names the memory file `project_spatie_permission_cache_tenant_blind` in a comment — the defect is known and unfixed at HEAD.

---

## C2 — `can:credit-notes.cancel` unseeded — **CONFIRMED**, and the 403-vs-500 question resolved: **403 (silent)**

- `apps/api/app/Modules/Document/Presentation/routes.php:269-271` — `Route::post('/credit-notes/{creditNote}/cancel', [RefundController::class, 'cancelCreditNote'])->middleware('can:credit-notes.cancel')`.
- Absent from `RolesAndPermissionsSeeder`: `grep -n "credit-notes"` returns only `:179-181` (`view`/`create`/`post`) and role grants at `:599`, `:736`, `:831`. **`credit-notes.cancel` is not in the catalog.** Contrast `invoices.cancel`, which IS seeded at `RolesAndPermissionsSeeder.php:176`.
- Present only in the legacy `apps/api/database/seeders/PermissionSeeder.php:72` (catalog) and `:214` (grant).

**403, not 500.** `vendor/spatie/laravel-permission/src/PermissionRegistrar.php:125-132` registers `Gate::before(... $user->checkPermissionTo($ability, …) ?: null)`. `vendor/spatie/laravel-permission/src/Traits/HasPermissions.php:260-267` — `checkPermissionTo` wraps `hasPermissionTo` in `try { } catch (PermissionDoesNotExist) { return false; }`. `false ?: null` returns `null`, the gate falls through, no ability/policy is defined for `credit-notes.cancel`, and Laravel's `Authorize` middleware raises `AuthorizationException` → **403**. So this is exactly the silent-403 trap: no exception, no log, no way to tell "you lack the permission" from "the permission does not exist."

**`ProductionSeeder` does call `PermissionSeeder`** — `apps/api/database/seeders/ProductionSeeder.php:75`. But this does **not** rescue production tenants:
- `ProductionSeeder` is invoked only manually (`db:seed --class=ProductionSeeder`, documented at `:17`); it appears in no compose file, no CI workflow and not in `apps/api/docker/entrypoint.sh` (the entrypoint's `AUTO_SEED` branch runs the default `DatabaseSeeder`, `entrypoint.sh:187`).
- The Spatie tables are **tenant** migrations (`database/migrations/tenant/2025_11_29_231806_create_permission_tables.php`), so running `ProductionSeeder` against the central connection would not create rows in any tenant DB.
- The per-tenant provisioning path (`TenantInitializationService.php:213-216`) calls `RolesAndPermissionsSeeder` **only**, never `PermissionSeeder`.
So: on any tenant created through the real signup path, `credit-notes.cancel` does not exist and **POST /api/v1/credit-notes/{id}/cancel 403s for every user including admin**.

---

## C3 — `syncPermissions` clobber + "no automatic sync" — **PARTIALLY CONFIRMED (the second half is false on staging)**

- **Clobber: CONFIRMED.** `apps/api/database/seeders/RolesAndPermissionsSeeder.php:566-572` loops every entry of `rolePermissionGrants()` and calls `$role->syncPermissions(...)` at `:568`. `syncPermissions` is destructive (detach-then-attach), so every re-run resets the 7 seeded roles (`admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant` — enumerated at `:580-…`) to the canonical set, discarding tenant customisation.
- **Guard: CONFIRMED.** `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199-217`; `:201-208` returns early when a `('admin','sanctum')` role row exists. So the **signup/provisioning** path never re-syncs an existing tenant.
- **"Existing tenants never receive newly added permissions automatically": REFUTED for staging.** `SYNC_PERMISSIONS_ON_BOOT` **does exist**: `apps/api/docker/entrypoint.sh:162` — `if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then` … `:165` `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'`, i.e. the full seeder across **every** tenant DB, followed by `permission:cache-reset` at `:176`. `.env.example:178` ships `SYNC_PERMISSIONS_ON_BOOT=false`, but `docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:32` records it as **`true` on the staging API application**. Consequence, and it cuts both ways: on staging every deploy fixes the "new permission missing" gap *and* wipes any per-tenant customisation of the 7 built-in roles — silently, with no diff and no audit row.

**Correction worth surfacing:** `:568` grants `admin` **`Permission::all()`**, not `self::permissionNames()`. Any permission row that lands in a tenant DB by any path is auto-granted to `admin` on the next seeder run.

---

## C4 — "53 live write routes with no permission check at any layer" — **PARTIALLY CONFIRMED (10 of 12 spot-checks hold; 2 are false positives)**

Mechanical recount at HEAD from the fresh route list: **178** write routes (POST/PUT/PATCH/DELETE) on `auth:sanctum` carry no route-level `Illuminate\Auth\Middleware\Authorize` (`can:`) and no `RequireAnyPermission`. The auditors' "53" is a *subset* of that 178 after removing routes gated in a FormRequest or controller — the headline number is therefore plausible as an order of magnitude but I could not verify it exactly without opening all 178 controllers; my 12-route sample shows the classification is **~83% accurate**, so treat 53 as approximate, not exact.

Reachability, common to every genuinely ungated row below: the only gates left are `api` + `auth:sanctum` + `SetPermissionsTeam` + `EnforceTokenTenantClaim` + `CompanyContextMiddleware` (appended to the `api` group at `bootstrap/app.php:144-153`). `CompanyContextMiddleware` rejects only "member of no company" / "not your company" (`app/Http/Middleware/CompanyContextMiddleware.php:31-32`, 403s at `:131`/`:141`) — it never inspects a role. **So every seeded role reaches these: `admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant`, plus any custom role**, with the module gate as the only extra filter where noted.

| # | Route | Verdict | Evidence |
|---|---|---|---|
| 1 | `DELETE /api/v1/categories/{id}` | **UNGATED — confirmed** | `CategoryController@destroy` at `app/Modules/Product/Presentation/Controllers/CategoryController.php:247`; `grep -n "Gate\|authorize\|can("` over the whole 307-line file returns **zero** hits; no FormRequest (`Request $request`). All 7 roles. |
| 2 | `POST /api/v1/categories` | **UNGATED — confirmed** | same file `:115`, same zero-hit grep. All 7 roles. |
| 3 | `DELETE /api/v1/uom/units/{id}` | **FALSE POSITIVE — gated** | `app/Modules/Uom/Presentation/Controllers/UomController.php:259-261` → `$this->authorizeAbility('uom.delete')`; `app/Shared/Authorization/AuthorizesAbility.php:19-24` throws `PermissionDeniedException` when `Gate::allows` fails. `uom.delete` is seeded (`RolesAndPermissionsSeeder.php:193`) and granted to manager (`:601`) + admin. |
| 4 | `POST /api/v1/uom/units` | **FALSE POSITIVE — gated** | `UomController.php:168-170` → `authorizeAbility('uom.create')`; seeded `:192`, granted `:601`. |
| 5 | `DELETE /api/v1/coupons/{id}` | **UNGATED — confirmed** | `app/Modules/Coupon/Presentation/Controllers/CouponController.php:111` `destroy(string $id)` — no FormRequest, no `can(`. (Contrast `store` at `:83`, gated by `StoreCouponRequest::authorize` → `coupons.manage`, `app/Modules/Coupon/Presentation/Requests/StoreCouponRequest.php:23`.) All 7 roles. |
| 6 | `POST /api/v1/promotions/{id}/archive` | **UNGATED — confirmed** | `app/Modules/Promotion/Presentation/Controllers/PromotionController.php:194` `archive(string $id)` — no FormRequest. `index`/`show` **do** gate (`Gate::authorize('promotions.view')` at `:29`, `:69`); `store` gates via `StorePromotionRequest.php:17` → `promotions.manage`. So the auditors' framing is right: the read paths and create path are gated and `archive`/`destroy` (`:122`) are not. All 7 roles. |
| 7 | `POST /api/v1/channels` | **UNGATED (module-gated only) — confirmed** | `app/Modules/Channel/Presentation/Controllers/ChannelController.php:36` `store(Request $request)`. Route middleware includes `RequireModule:Ecommerce` and nothing else. All 7 roles **in an Ecommerce-enabled tenant**. |
| 8 | `POST /api/v1/companies` | **UNGATED — confirmed, and worse than stated** | `app/Modules/Company/Presentation/Requests/CreateCompanyRequest.php:11-14` — `authorize(): bool { return true; }`. `CompanyController@store` (`:74`) creates the company **and at `:148-155` inserts a `UserCompanyMembership` for the caller with `'role' => MembershipRole::Owner`**. Any authenticated tenant user — a `viewer`, a `cashier` — can mint an unlimited number of companies inside the tenant and make themselves **Owner** of each, complete with a default location (`:128`), genesis hash chains for every `HashChainType` (`:157-165`) and full document numbering. Owner is not decorative: `app/Modules/Identity/Presentation/Controllers/UserController.php:929` uses `$membership?->isOwner()` to **bypass** the `LOCATION_ESCALATION` check at `:932-942`. |
| 9 | `DELETE /api/v1/services/{service}` | **UNGATED (module-gated only) — confirmed** | `app/Modules/Service/Presentation/Controllers/ServiceController.php:168` `destroy(Request $request, string $service)`; `app/Modules/Service/Presentation/routes.php:20` group is `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Workshop']` and **no route in the file carries a `can:`** (`:22-54`). All 7 roles in a Workshop tenant. |
| 10 | `POST /api/v1/services` | **UNGATED (module-gated only) — confirmed** | `ServiceController.php:107` takes `CreateServiceRequest`, whose `authorize()` returns **`true`** (`app/Modules/Service/Presentation/Requests/CreateServiceRequest.php:15-18`). All 7 roles in a Workshop tenant. |
| 11 | `DELETE /api/v1/batches/{uuid}` | **UNGATED (module-gated only) — confirmed** | `app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176` `destroy(string $uuid)` — no FormRequest. Route `app/Modules/BatchExpiry/Presentation/routes.php:26`, group gated `module:BatchExpiry` only. Note `store` **is** gated (`CreateBatchRequest.php:20-23` → `batches.create`) and `write-off` is (`routes.php:19`, `:35` → `can:batches.write-off`), so `destroy` (`:26`), `update` (`:25`) and `recall` (`:29`) are the holes. UUID handling is correct here — `findBatchOrFail` guards with `Str::isUuid()` at `:48-55`, no PG uuid-cast 500. All 7 roles in a BatchExpiry tenant. |
| 12 | `POST /api/v1/purchase-hub/orders` | **UNGATED — confirmed** | `app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOrderController.php:20` `store(Request $request)` — validation only. `app/Modules/PurchaseHub/Presentation/routes.php:13` group is `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`, no module gate, no `can:`, route at `:18`. This one **commits the tenant to a real outbound purchase order** on the upstream platform (`#[CrossTenantRoute]` at `:19`). All 7 roles, including `viewer`. |

Module names used by the gates (`Ecommerce`, `Workshop`, `BatchExpiry`) all exist in `apps/api/config/verticals.php` — no SoT drift there.

---

## C5 — no route→permission coverage ratchet, no PHPStan permission rule — **CONFIRMED**

- `apps/api/tests` contains no `RouteCoverage` / `PermissionCoverage` / `RoutesRequirePermission` test. `find tests -iname "*Coverage*"` returns only `InventoryCostLockCoverageTest`, `FiscalEventCoveragePolicyTest`, `HorizonQueueCoverageTest`, `CheckCogsCoverageCommandTest`, `PermissionCatalogVerticalFilterTest` — none of which map routes to permissions.
- The closest thing, `tests/Architecture/AuthLifecycleTest.php`, ratchets only **`SetPermissionsTeam` + `EnforceTokenTenantClaim` presence** on `auth:sanctum` groups (`:143`, `:160-178`), with globs at `:100-119` and a vacuous-pass floor of 30 at `:129`. It says nothing about `can:`.
- `tests/Feature/CountryDefaults/CentralAdminRouteInventoryTest.php:24-34` is a real middleware inventory but covers exactly the **16 central-admin routes**.
- `tests/Feature/Permissions/P2pEntryPointPermissionsTest.php:32-39` pins a hand-listed 6-permission matrix — useful, but a whitelist, not a ratchet.
- PHPStan: `apps/api/phpstan.neon:33-45` registers 9 custom rules (`app/PHPStan/Rules/*`), all about document/status writes, bcmath scale, quantity scale, inventory GL. **None references a permission string.**

---

## C6 — RoleController — **CONFIRMED on every sub-claim, with two corrections and one escalation upgrade**

- **`index`/`show`/`permissions`/`userRoles` unauthorized: CONFIRMED.** `app/Modules/Identity/Presentation/Controllers/RoleController.php:123` (`index`), `:162` (`show`), `:315` (`permissions`), `:429` (`userRoles`) take a bare `Request`, no `Gate`, no `can(`. Route list confirms **no `Authorize` middleware** on `GET api/v1/roles`, `GET api/v1/roles/{id}`, `GET api/v1/permissions`, `GET api/v1/users/{userId}/roles`. Any authenticated tenant user can enumerate the full role catalogue with permission sets, the full permission catalogue, and **any other user's complete role+permission list** (`:437` `getAllPermissions()`).
  - **Correction:** `tests/Feature/Identity/RBACTest.php:137-149` and `:151-165` do **not** assert that *any* authenticated user can list — both act as `$this->adminUser`. They are allow-path-only tests whose *names* ("authenticated_user_can_list_roles") assert more than their bodies. The openness is real, but it is proven by the absent middleware, not by these tests. As written they are exactly the anti-pattern the test-quality bar rejects: no DENY path.
- **`store`/`update`/`destroy` gated on `roles.manage` via FormRequests: CONFIRMED.** `StoreRoleRequest.php:19-22`, `UpdateRoleRequest.php:18-21`, `DeleteRoleRequest.php:20-23`.
- **`AssignableRole` subset check: CONFIRMED** on `AssignRoleRequest.php:34` (used by both `assignRole` and `removeRole`, `RoleController.php:350`/`:390`), implemented at `app/Modules/Identity/Presentation/Rules/AssignableRole.php:46-51` (`$rolePermissions->diff($actorPermissions)->isNotEmpty()` → fail).
- **The `UpdateRoleRequest` escalation hole: CONFIRMED — `AssignableRole` does NOT close it.** `AssignableRole` is bound only to the `role` field of `AssignRoleRequest`. `RoleController::update` (`:223`) runs `$role->syncPermissions($validated['permissions'])` at `:246` with **no subset check whatsoever** — `UpdateRoleRequest::rules()` only requires `exists:permissions,name` (`:31`). A `roles.manage` holder edits a role **they already hold**, adds any permission in the catalogue, and holds it on the next request. From there their own permission set is the full catalogue, so `AssignableRole` no longer constrains anything they assign to anyone.
  - **Severity correction the auditors should carry:** this is **latent, not live**, because `roles.manage` appears in the seeder only in the catalogue (`RolesAndPermissionsSeeder.php:383`) and is granted to **no seeded role except `admin`** (which receives `Permission::all()` at `:568`). It becomes exploitable the moment an admin grants `roles.manage` to a custom role — which the product explicitly supports (`usePermissions.ts:23-32` documents per-role granting as an owner ruling).
- **`$systemRoles = ['super-admin','admin','owner']` at `:228` and `:277`: CONFIRMED, and only `admin` is a real seeded role** (seeded set: `admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant`). So `manager`/`accountant`/`cashier` are **not** protected from rename or deletion.
- **No last-admin protection: CONFIRMED.** `UserController::destroy` (`:430`) checks only `users.delete` (`:435`) and self-delete (`:460`); `UserController::update` (`:303`) and `RoleController::removeRole` (`:390`) have no "at least one admin" guard — `grep -i "last admin|at least one admin|LAST_ADMIN"` over `app/Modules/Identity` and `app/Modules/Company` returns nothing. An actor with `users.assign-roles` can strip `admin` from every admin including themselves and permanently lock the tenant out of role management.
- **No audit event on role CRUD: CONFIRMED.** `app/Modules/Identity/Domain/Events/` contains exactly `RoleAssigned.php` and `RoleRemoved.php`; `RoleController::store/update/destroy` emit no `event(...)` (compare `:365-371` and `:404-410`, which do).

---

## C7 — Spatie team = tenant, membership role is a parallel unenforced axis — **CONFIRMED**

- `app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-31` — `setPermissionsTeamId($user->tenant_id)`. Team is the **tenant**, never the company.
- `app/Modules/Company/Domain/Enums/MembershipRole.php:9-15` — `Owner, Admin, Manager, Accountant, Cashier, Technician, Viewer`. Six of seven names collide with seeded Spatie role names (`admin`, `manager`, `accountant`, `cashier`, `technician`, `viewer`) and nothing keeps them consistent.
- **Complete inventory of `MembershipRole` reads used for an authorization decision — exactly one:**
  - `app/Modules/Identity/Presentation/Controllers/UserController.php:929` — `$isOwner = $membership?->isOwner() ?? false`, feeding the `LOCATION_ESCALATION` bypass at `:932-942`. `isOwner()` is `app/Modules/Company/Domain/UserCompanyMembership.php:140-143` → `$this->role === MembershipRole::Owner`.
- Everything else is a **write**, never a read-for-authz: `TenantProvisioningService.php:216` (`Owner`), `AuthController.php:455` (`Owner`), `CompanyController.php:151` (`Owner`), `UserController.php:246` (`Viewer`), `BackfillMembershipsCommand.php:166` (`Viewer`), plus the cast at `UserCompanyMembership.php:83`.
- `UserCompanyMembership::isAdmin()` (`:148-151`) and `MembershipRole::isAdminRole()` (`:20-23`) have **zero production callers** — `grep -rn "isAdmin()" app/` returns only the definition. Dead authorization code that reads like a live gate.

---

## C8 — frontend fail-open fallback — **CONFIRMED verbatim**

`apps/web/src/hooks/usePermissions.ts:193-211`:
```ts
const hasPermission = (permission: Permission): boolean => {
  if (serverPermissions?.includes(permission) === true) { return true }   // :194-196
  if (SERVER_AUTHORITATIVE_PERMISSIONS.has(permission)) { return false }  // :197-199
  let allowedRoles: readonly string[]
  if (isGeneratedPermission(permission)) { allowedRoles = PERMISSIONS[permission] }        // :202-203
  else if (isUiAliasPermission(permission)) { allowedRoles = UI_ALIAS_PERMISSIONS[permission] } // :204-205
  else { return false }
  return roles.some((role) => allowedRoles.includes(role))               // :210
}
```
The fallback at `:201-210` is reached whenever the server list does **not** contain the permission — including the case where `serverPermissions` is a fully-populated array from which the permission was deliberately removed server-side. `:210` then grants it purely on role name from the static map. **Fail-open, exactly as claimed.** The only exceptions are the 9 entries of `SERVER_AUTHORITATIVE_PERMISSIONS` (`:34-44`), and the file's own comment at `:17-22` states the fail-closed rationale, confirming the fail-open reading of the default path is the authors' understanding too.

- `services.view` / `services.create` / `services.edit` exist **only** in `apps/web/src/hooks/uiAliasPermissions.ts:8-10`: **CONFIRMED** — `grep -n "'services\."` over `RolesAndPermissionsSeeder.php` returns **nothing**.
- `app/Modules/Service/Presentation/routes.php` has **no `can:` middleware on any of its 10 routes** (`:22-54`), group gate `module:Workshop` only (`:20`): **CONFIRMED**.
- `permissionsMap.generated.ts:1-3` declares it is generated from `RolesAndPermissionsSeeder.php` with a source hash, so a permission appearing there is **not** an independent reference.

---

## C9 — 26 seeded-but-unreferenced permissions; 6 spot-checked — **CONFIRMED, and stronger than claimed**

Repo-wide grep (`apps/api/{app,routes,config,database}`, `apps/web/src`, `apps/pos/src`, `packages`):

| Permission | Only occurrences |
|---|---|
| `pos_orders.view` | seeder `:413`, `:650`, `:711` + generated map `:203` |
| `pos.void_receipts` | seeder `:393`, `:642` + generated map `:195` |
| `invoices.print` | seeder `:177`, `:598`, `:697`, `:801` + generated map `:127` |
| `audit.view` | seeder `:554`, `:861` + generated map `:8` |
| `batches.recall` | seeder `:428`, `:653` + generated map `:15` |
| `products.import` | seeder `:62`, `:587` + generated map `:212` |

All six: **zero** backend enforcement and **zero** frontend gate call sites. Since `permissionsMap.generated.ts` is derived from the seeder (`:1-3`), the generated-map row is not an independent reference. So none of the six is even "referenced by the frontend but unenforced" — they are **fully dead catalogue entries**. `batches.recall` is the sharpest example: `POST /api/v1/batches/{uuid}/recall` exists (`app/Modules/BatchExpiry/Presentation/routes.php:29`, controller `:193`), a permission named for it is seeded and granted to manager+admin — and the route is gated by **nothing**.

---

## C10 — policies — **CONFIRMED**

- `ls app/Policies/` → exactly `DocumentPolicy.php`, `ExpenseCategoryPolicy.php`.
- Registered manually at `app/Providers/AppServiceProvider.php:275-276` (`Gate::policy(Document::class, …)`, `Gate::policy(ExpenseCategory::class, …)`).
- `grep -rn "Gate::before" app/` → **no hits** (the only `Gate::before` in the process is Spatie's own, `vendor/spatie/laravel-permission/src/PermissionRegistrar.php:125`).
- `ls app/Providers/` → `AppServiceProvider`, `BroadcastServiceProvider`, `EventServiceProvider`, `HorizonServiceProvider`, `TenancyServiceProvider`. **No `AuthServiceProvider`.**

---

## C11 — super admin separation + impersonation two-person rule — **CONFIRMED**

- `apps/api/config/auth.php:50-53` — guard `sanctum-admin` → provider `super_admins`.
- `app/Models/SuperAdmin.php:14-18` — `class SuperAdmin extends Authenticatable` `use CentralConnection;` with the comment "central table — super-admins span tenants and live only in the central database". Correct central pinning under db-per-tenant.
- No super-admin flag on the tenant user: `grep -n "is_super_admin\|isSuperAdmin\|super_admin" app/Modules/Identity/Domain/User.php` → **no hits**.
- Middleware gating: `EnsureSuperAdmin.php:43` (`$user instanceof SuperAdmin && role === SuperAdminRole::SuperAdmin`), `RequireCentralAdminRole.php:26` (`in_array($actor->role, $roles, true)`), aliases registered at `bootstrap/app.php:115-117`.
- Request/approve separation: `app/Modules/SupportAccess/Presentation/routes.php` — request `:23-24` `central_admin_role:super_admin`; approve `:26-27` `central_admin_role:support_approver`; elevation create `:35-36` `super_admin`; elevation approve/reject `:38-42` `support_approver`. **Disjoint, as claimed.**

---

## C12 — docs prescribe an unregistered `permission:` middleware — **PARTIALLY CONFIRMED (one of the two docs; the other says something different and arguably worse)**

- **No `permission:` alias exists.** `bootstrap/app.php:114-123` registers exactly `super_admin`, `central_admin`, `central_admin_role`, `validate.location.access`, `module`, `require.any.permission`, `scheduling.captcha`, `cross_tenant`. `grep -rn "PermissionMiddleware\|RoleMiddleware\|RoleOrPermissionMiddleware" app/ bootstrap/ config/` → **no hits**. A route using `middleware('permission:x')` would throw `Target class [permission] does not exist`. (The `permission:` prefix *is* used in the codebase, but as a **Sanctum token ability** prefix — `app/Modules/Identity/Domain/User.php:237-238`, `SessionLifecycleService.php:80`, `ImpersonationContext.php:256` — unrelated.)
- **`.claude/commands/add-permissions.md:16-17`: CONFIRMED** — literally instructs `Route::middleware('permission:{resource}.view')` and `('permission:{resource}.create')`. Following this slash command produces a route that 500s on every request.
- **`docs/conventions/03-AUTHORIZATION.md`: REFUTED.** It never mentions `permission:` middleware. Its route example (`:30-35`) is `['api', 'auth:sanctum', SetPermissionsTeam::class]` and it then teaches an **in-controller `$user->can(...)` check** (`:45-63`). What it actually does wrong is different and, for this audit, more relevant: its canonical example shows `Route::post('users', …)` at `:34` **with no authorization on the route at all**, and `:38-41` lists only the three group middlewares as required — the document never says a write route needs a permission gate. That is a plausible proximate cause of the 178 ungated writes in C4. It also omits `EnforceTokenTenantClaim`, which `tests/Architecture/AuthLifecycleTest.php:171-178` now enforces.

---

## Verdict table

| Claim | Verdict | Correction |
|---|---|---|
| C1 permission cache tenant-blind | **CONFIRMED** | `CacheTenancyBootstrapper` IS enabled (`tenancy.php:40`) but cannot help: Spatie holds an untagged `Repository` from `CacheManager::store()`, which bypasses Stancl's `__call` tagging. Permission/role PKs are per-tenant `bigIncrements`, so the shared snapshot is semantically wrong per tenant. No fix on any branch. |
| C2 `credit-notes.cancel` unseeded | **CONFIRMED** | Spatie 6.25 yields **403**, not 500 (`checkPermissionTo` swallows `PermissionDoesNotExist`). `ProductionSeeder:75` does call `PermissionSeeder`, but it is manual-only, never in the entrypoint, and never in the per-tenant provisioning path — so real tenants still lack the row. |
| C3 `syncPermissions` clobber + no auto-sync | **PARTIALLY** | Clobber and the `seedRolesAndPermissionsIfMissing` early-return confirmed. "Never receive new permissions automatically" is **false on staging**: `SYNC_PERMISSIONS_ON_BOOT=true` there runs the whole seeder across every tenant on **every** deploy (`entrypoint.sh:162-165`) — which also silently clobbers built-in-role customisation on every push. `admin` gets `Permission::all()`, not `permissionNames()`. |
| C4 53 ungated write routes; 12 spot-checks | **PARTIALLY** | 10/12 confirmed ungated; **`POST /uom/units` and `DELETE /uom/units/{id}` are gated** in-controller (`uom.create`/`uom.delete`). Route-level ungated writes number **178**; "53 at any layer" is a plausible but unverified subset. `POST /companies` is worse than described — it self-grants `MembershipRole::Owner`. Reachable by all 7 seeded roles. |
| C5 no route→permission ratchet, no PHPStan perm rule | **CONFIRMED** | Nearest gates are `AuthLifecycleTest` (team/tenant-claim middleware only) and `CentralAdminRouteInventoryTest` (16 central routes). |
| C6 RoleController | **CONFIRMED** | Two corrections: `RBACTest:137-165` acts as **admin**, so it proves an allow path, not openness (openness is proven by absent middleware). The `roles.manage` escalation is real and **not** closed by `AssignableRole`, but is **latent** — only `admin` holds `roles.manage` in the seeded catalogue today. |
| C7 team=tenant vs `MembershipRole` | **CONFIRMED** | Exactly one authz read: `UserController.php:929` `isOwner()` (bypasses `LOCATION_ESCALATION`). `isAdmin()`/`isAdminRole()` have zero production callers. |
| C8 FE fail-open fallback | **CONFIRMED** | `usePermissions.ts:194-210`, fallback fires even when `serverPermissions` is present but lacks the entry. `services.*` alias-only and `Service/routes.php` `can:`-free both confirmed. |
| C9 26 dead permissions; 6 spot-checked | **CONFIRMED** | All 6 are fully dead — no FE call site either (the generated map is derived from the seeder, not an independent reference). `batches.recall` names a real, entirely ungated route. |
| C10 only 2 policies, manual registration | **CONFIRMED** | `AppServiceProvider.php:275-276`; no `Gate::before` in app; no `AuthServiceProvider`. |
| C11 super admin separate central model | **CONFIRMED** | `auth.php:50-53`, `SuperAdmin.php:14-18` (`CentralConnection`), request/approve disjoint at SupportAccess `routes.php:23-27`, `:35-42`. |
| C12 docs prescribe unregistered `permission:` | **PARTIALLY** | Only `.claude/commands/add-permissions.md:16-17` does. `docs/conventions/03-AUTHORIZATION.md` teaches in-controller `$user->can()` instead — its real defect is a canonical route example with **no** authorization at all (`:30-41`) and no `EnforceTokenTenantClaim`. |

---

## Missed findings

1. **[Critical] `apps/api/app/Modules/Identity/routes.php:58` — the comment documents a gate that does not exist.** `// Role management (requires roles.view or roles.manage permission)` sits directly above six routes (`:59-64`) that carry **no** `can:`. `roles.view` is seeded (`RolesAndPermissionsSeeder.php:382`) and published to the frontend (`permissionsMap.generated.ts:243` → `['admin']`) yet is enforced **nowhere** in `apps/api/app`. The same pattern repeats at `:77` (`// User role assignment (requires users.assign-roles permission)`) where `GET users/{userId}/roles` (`:78` → `RoleController@userRoles`, `:429-444`) has no gate at all and returns **any tenant user's full role + `getAllPermissions()` list** (`:436-437`) to any authenticated caller. A reviewer reading the route file sees a gate; the router does not.

2. **[Important] `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568` grants `admin` `Permission::all()`, not `self::permissionNames()`.** Combined with staging's `SYNC_PERMISSIONS_ON_BOOT=true` (`docker/entrypoint.sh:162-165`, seeder runs on **every** deploy), any permission row that reaches a tenant DB by **any** path — an out-of-band insert, a future custom-permission feature, a `Permission::firstOrCreate` left behind by tooling — is silently attached to `admin` on the next boot, with no diff, no review and no audit row. The catalogue in `permissionNames()` stops being the boundary of what `admin` holds.

3. **[Important] `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:227-247` — the "system role protection" protects only the *name*.** The guard at `:229` fires only when `$request->has('name') && input('name') !== $role->name`. `$role->syncPermissions($validated['permissions'])` at `:246` then runs **unconditionally, including on `admin`**. A `roles.manage` holder can `PATCH /api/v1/roles/{adminRoleId}` with `{"permissions": []}` and empty the `admin` role for the entire tenant. There is no last-admin guard anywhere (C6) and no audit event on role update (`:223-266` emits none), so the tenant is locked out with no record of who did it. `DeleteRoleRequest` is at least protected by the users-count check (`:288-296`); the permission set is not.

4. **[Important] `apps/web/src/hooks/uiAliasPermissions.ts:3-12` maps to role names that no seeder creates.** The arms `'sales'`, `'purchases'`, `'inventory'`, `'treasury'`, `'user'` are not among the 7 seeded Spatie roles (`admin`, `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant`). Every such arm is unreachable, so e.g. `'services.view': ['admin','sales','manager']` (`:8`) effectively means `['admin','manager']`. Because these keys are the *only* gate on the Service surfaces (whose API is `can:`-free — `app/Modules/Service/Presentation/routes.php:22-54`), the effective authorization model for services is "whatever the FE alias map happens to resolve to", with no server-side counterpart at all. The file's own header (`:1`) says these are "NOT backend authorization" — but for services they are the only authorization.

5. **[Important — test quality] `apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142` manufactures the very permission whose absence is the C2 bug.** The only test touching `POST /credit-notes/{id}/cancel` does `Permission::firstOrCreate(['name' => 'credit-notes.cancel', 'guard_name' => 'sanctum'])` and grants it directly, with the comment "permissions whose routes we test that admin doesn't include **by default**" — mischaracterising a permission that does not exist in the catalogue at all as one admin merely lacks. A test that creates its own authz precondition can never detect a catalogue gap, and it converts a seeder omission into a permanently green suite. Same shape as C6's `RBACTest` allow-path-only tests: the deny path is never exercised, so nothing in CI can observe the 403 that every production tenant gets.
