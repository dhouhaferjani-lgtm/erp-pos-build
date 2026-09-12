# Codex spec gate r1 — roles & permissions catalogue design rev 1 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`4f5d2dd46`**.

HEAD advanced from `af3062411` during the review, but the intervening commit added only two handoff documents. The reviewed spec blob and all reviewed application paths are identical across those SHAs. The spec’s declared base `971528977` is stale (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7`).

`apps/api/vendor/autoload.php` is absent, so `artisan` could not boot. Ratchet figures were recomputed from the permitted CSV fallback.

## BLOCKER

### B-1 — Token narrowing does not cover every authorization idiom

The claim that changing `User::hasPermissionTo()` makes all existing authorization sites inherit narrowing is false (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:227,1170`).

AutoERP enables Spatie’s permission Gate callback at `apps/api/config/permission.php:103-107`. Spatie 6.25.0 is pinned at `apps/api/composer.lock:7747-7757`; its `Gate::before` calls `checkPermissionTo()`, which dynamically calls the model’s overridden `hasPermissionTo()`. [Spatie `PermissionRegistrar.php:113-122`](https://github.com/spatie/laravel-permission/blob/6.25.0/src/PermissionRegistrar.php#L113-L122), [Spatie `HasPermissions.php:238-261`](https://github.com/spatie/laravel-permission/blob/6.25.0/src/Traits/HasPermissions.php#L238-L261).

| Idiom | Result | Evidence |
|---|---|---|
| `can:` route middleware | Narrowed | Laravel `Authorize` → Gate → Spatie `Gate::before` → `checkPermissionTo()` → AutoERP override at `apps/api/app/Modules/Identity/Domain/User.php:185-198` |
| `$user->can()` | Narrowed | Same Gate path; Spatie callback enabled at `apps/api/config/permission.php:103-107` |
| `Gate::authorize()` | Narrowed for dotted Spatie permissions | Same Gate path; bare policy abilities then proceed to the registered policy at `apps/api/app/Providers/AppServiceProvider.php:270-276` |
| `hasPermissionTo()` | Narrowed | Direct AutoERP override at `apps/api/app/Modules/Identity/Domain/User.php:185-198` |
| `hasAnyPermission()` | Narrowed | Spatie calls `checkPermissionTo()` for every candidate |
| `getAllPermissions()` | Narrowed only when called on the authenticated model carrying the current token | Override at `apps/api/app/Modules/Identity/Domain/User.php:201-212`; the token is obtained from that model at `:216-241` |
| `hasRole()` / `hasAnyRole()` | **Bypasses narrowing** | No AutoERP override; production uses exist at `apps/api/app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:29-35`, `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:779-785`, and `apps/api/app/Modules/POS/Domain/Services/DiscountPermissionResolver.php:31-63` |
| Spatie `role:` middleware | Would bypass through `hasAnyRole()` | No Spatie `role:` middleware is registered or used at HEAD; the central `central_admin_role` middleware at `apps/api/bootstrap/app.php:114-123` is a separate platform guard |

Consequences:

- A scoped manager/admin token can still pass `CreateDraftCountingRequest` solely because of its role (`apps/api/app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:29-35`).
- Admin-only counting-operation bypasses ignore token scope (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:779-785`; further sites at `:866,905,975,1297,1494`).
- The POS admin discount ceiling ignores token scope (`apps/api/app/Modules/POS/Domain/Services/DiscountPermissionResolver.php:37-63`).
- Target-user calls do not inherit the caller’s token. `RoleController::userRoles()` calls `getAllPermissions()` on a separately loaded user (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`).
- The PIN payload does the same for a PIN holder loaded from the database (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`). Therefore the “gains token-scope narrowing for free” claim is false (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:962`).

The design must inventory and replace or explicitly scope every role-based authorization path and distinguish “current token owner” from “target principal” before it can satisfy the settled intersection guarantee.

### B-2 — `permissions:sync` is not yet a superset of W-LOT-A-1a

Three incompatibilities remain.

1. **New `general_manager` creation collides with the marker contract.** REV-1 promises never to write `provisioning_source` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:524,1004`) but directs sync to create a missing `general_manager` (`:599-604`). W-LOT-A-1a treats an existing `general_manager` without `provisioning_source = w-lot-a-1a` as an unmarked collision (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:139-155`). Its database CHECK and unique marker contract are at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-42,64-75`. A tenant created after wave 1 can therefore receive an unmarked role which the retained delta refuses.

2. **Existing role adoption is unspecified.** The migration defaults every legacy role to `is_system=false`, `template_key=NULL`, `template_version=NULL`, `customised_at=NULL` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:992-1003`). Sync describes creation metadata but no safe classification/backfill of existing seeded roles (`:599-605`). W-LOT-A-1a deliberately preserves every role/grant for marker-carrying tenants (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`; plan `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:1496-1507`). REV-1 therefore cannot distinguish a pristine legacy role from an already tenant-customised one before applying D2 deltas.

3. **The D2 writer lands a wave too late.** `customised_at` is supposed to be set by `RoleController::update` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1003`), but wave 1 deploys and repeatedly runs sync while role hardening is deferred to wave 2 (`:1113-1126`). During the soak, the existing editor still calls `syncPermissions()` without marking customisation (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247`). The next sync can add template grants to a role the tenant has just customised.

Compatibility mode has the same problem: sync skips template steps entirely (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:626`), leaving all system roles at `is_system=false`, while wave 2 relies on `is_system`/`template_key` for protection and floors (`:764-784`).

The NULL-team lookup itself is sound: W-LOT-A-1a already resolves `(tenant_id IS NULL OR tenant_id = tenant)` without re-homing (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:104-128`). The blocker is metadata adoption and marker ownership, not the predicate.

### B-3 — General-manager behavior contradicts the incoming lane

EC-15 says a location-restricted user may receive `general_manager` and remain restricted (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1056`).

The incoming lane explicitly refuses that assignment: every active membership must have `allowed_location_ids = NULL`, otherwise it returns `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:19-23,36-49`). Its glossary defines the same invariant and the sole assignment surface as Settings → Users (`lane/w-lot-a-1a:docs/glossary.md:21,91`).

REV-1 also changes the synonym from `central manager` to `directeur général` and the canonical surface from Settings → Users to Settings → Roles (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:100`). That violates the “absorbed, never undone” promise at `:512-528`.

### B-4 — Last-admin floor misses a live removal path

F-2 enumerates four operations (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:776-784`) but omits:

- `PATCH /api/v1/users/{id}` with a different `role`: `UpdateUserRequest` accepts `role` (`apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:44-62`) and `UserController::update` calls `syncRoles()` (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380-389`). This can remove the last admin without calling `RoleController::removeRole`.

The remaining paths are:

- Admin permission removal through `RoleController::update`: addressed by F-1 (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247`).
- Role deletion: addressed by system-role protection (`:271-298`).
- Explicit role removal: listed, current writer at `:390-410`.
- User deletion/deactivation: listed, current writers at `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:470-484,630-642`.
- Membership removal: no independent production endpoint currently exists; membership revocation occurs inside deletion/deactivation at `:988-998`.
- Sync: `admin = activeKeys()` should preserve the floor if role adoption is fixed.
- Impersonation: it reaches the same controllers, so the centralized floor can cover it.

The definition of *A* also counts a human admin with no active membership. Such a principal cannot recover company-scoped access, so the floor must define recovery eligibility, not just status and role (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:776-782`; company access is membership-based at `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:147-155`).

### B-5 — The deployment entrypoint is not safe as specified

The proposed command is:

```sh
php artisan tenants:run permissions:sync --force
```

(`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:614-618`).

This is not the `tenants:run` option contract. Child options must be forwarded through `--option`; bare flags are explicitly documented as invalid by existing commands (`apps/api/app/Console/Commands/SeedChartsCommand.php:31-36`). W-LOT-A-1a proves the invocation shape as `--option=['apply=1']` and records string normalization (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:1573-1601,1941-1942`). `permissions:sync` does not even define `--force` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:534-550`).

More importantly, `tenants:run` discards child exit statuses (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2956`). The current entrypoint also catches rolling migration and seed failures and continues booting (`apps/api/docker/entrypoint.sh:150-169`). A partial sync can therefore be logged as completed while staging starts on mixed authorization state.

The sync must have a fleet runner that checks every exact per-tenant verdict and exits non-zero on any blocked/failed tenant before replacing the staging-on-push entrypoint.

### B-6 — The catalogue contract cannot compile or pass its own initial guards

Several contradictions make wave 1 red by construction:

- `PermissionVerb::Close` is used in the accepted T-2/T-3 example (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:501-504`) and `InventoryPermission::TransfersClose` is declared (`:483-496`), but `Close` is absent from the closed enum (`:365-398`). Line 401 incorrectly maps `.close` to `Complete`.
- The `manage` invariant says no existing resource mixes `manage` with CRUD (`:403`), but `settings.update` and `settings.manage` coexist at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:549-554`.
- The admin test asserts equality with `PermissionRegistry::keys()` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:146`), which includes deprecated keys (`:456`), while sync assigns exactly `activeKeys()` (`:601`).
- AI-1 says adding one manifest definition “and nothing else” creates enum reachability and en/fr/ar labels (`:109,132-150`). Enums are separate manually authored classes (`:481-499`), locale files are separate human-edited artifacts (`:732-736`), and a new module also needs provider tagging (`:470-477`). The tests can reject missing work; they cannot synthesize those artifacts from a single definition.

### B-7 — `authz.self` is a mechanical ratchet bypass

`authz.self` performs no ownership check and is counted as coverage merely by appearing in middleware (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:670-700`). A developer can put it on an ID-bearing or unrelated route and satisfy the ratchet. The statement that every addition is “a reviewed diff on the alias” is incorrect: the alias is registered once in `apps/api/bootstrap/app.php:114-123`; subsequent additions are ordinary route edits.

The ratchet needs a fixed allow-list or a second test asserting the exact eligible route set and structural self-only shape. Otherwise D3’s anti-growth guard protects baseline edits but not false self-service declarations.

### B-8 — OQ-3 reopens settled D8 and makes the schema incomplete

D8 is settled as denial events on the audit chain. REV-1 nevertheless recommends a separate `security_events` table and explicitly admits that the table is absent from §5 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1182-1184`). This contradicts both the settled ruling and the statement that there are only two tenant migrations and no other table (`:968-1012`).

OQ-3 must be removed as an open decision and denial events specified on `audit_events`.

### B-9 — Wave 0a is not a no-overlap subset

`git diff --stat dev...lane/w-lot-a-1a` contains 66 files, 4,065 insertions and 595 deletions. It directly modifies:

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`, while 0a-5 proposes modifying the same file (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1092`). The incoming edits are at `lane/w-lot-a-1a:apps/api/app/Modules/BatchExpiry/Presentation/routes.php:13-40`.
- `docs/glossary.md`, while 0a-10 also claims it (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1097`). The incoming General-manager row and sole-writer statement are at `lane/w-lot-a-1a:docs/glossary.md:21,91`.

Therefore the unconditional no-overlap claims at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1082-1084,1099` are false. The two tasks must be removed from the pre-merge 0a subset or explicitly sequenced after W-LOT-A-1a.

Additionally, 0a-6 is labelled wave 0a but cannot land until 0b (`:1093`), and 0a-7 intentionally leaves the suite red until 0b (`:1094`). Those cannot be independently mergeable wave-0a commits.

## MAJOR

### M-1 — Principal reuse has an incomplete impact inventory

The proposed database CHECK does not enforce all stated invariants. It constrains `principal_kind`, `pos_pin`, and `email_verified_at` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:982-984`) but does not constrain service-account status to `active|inactive`, even though `UserStatus` also contains `suspended` and `pending_verification` (`apps/api/app/Modules/Identity/Domain/Enums/UserStatus.php:10-15`).

The literal password sentinel is also incorrect as described. `User` casts `password` as `hashed` (`apps/api/app/Modules/Identity/Domain/User.php:115-125`), so assigning `'!'` through Eloquent stores a valid hash of `!`, not the literal invalid-hash sentinel promised at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:984`. The login kind check must remain before `Hash::check`; today the hash comparison occurs first (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:249-268`).

Synthetic `.invalid` email does not prevent application mail attempts:

- Invitations are sent to every non-null email at `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:281-292`.
- Password resets accept every non-null email at `:786-808`.
- `User` includes `Notifiable` at `apps/api/app/Modules/Identity/Domain/User.php:49-61`.

The impact census also omits existing writers:

- Registration: `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:390-400`.
- DB-per-tenant provisioning: `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:152-162`.
- User administration: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-250,357-391,470-484,548-550,630-642,1019-1025`.
- POS raw PIN update: `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:295-307`.
- Login metadata: `apps/api/app/Modules/Identity/Domain/User.php:274-281`.
- Data migration writer: `apps/api/database/migrations/tenant/2026_03_23_200000_fix_discount_permission_defaults.php:13`.
- Seed writers: `apps/api/database/seeders/DatabaseSeeder.php:293,331`; `CoffeeShopSeeder.php:1249,1278`; `ParapharmacySeeder.php:1415,1453,1488`; `DemoPharmacySeeder.php:962,1032`; `DemoTenantSeeder.php:208,559,1513,1610,1684,1758,1832,1906,1980,2054`.

`TenantInitializationService` does not create a user; it assigns the admin role and conditionally seeds roles (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:183-216`).

Three missing design questions, not new owner-decision IDs:

1. Do service principals consume subscription user seats? Current billing and usage counts include every `users` row (`apps/api/app/Services/PlanLimitsService.php:71-86`; `apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:325-330`; `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:88-104`).
2. Should service accounts use the already-supported nullable email instead of a routable-shaped synthetic address? The real uniqueness rule is partial for non-null emails (`apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`).
3. Which token, if any, is represented when an administrator reads another principal’s effective permissions and that principal owns multiple tokens? The proposed payload has one `token_id` without a selector (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:835-852`).

### M-2 — Sanctum details and revocation semantics need correction

- `personal_access_tokens` is correctly central, through `CentralPersonalAccessToken` (`apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`) and its registration at `apps/api/app/Providers/AppServiceProvider.php:196-219`.
- `abilities` is a nullable TEXT column, not JSON (`apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`). Sanctum casts it to an array. Correct `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1009`.
- The repository default TTL is 43,200 minutes/30 days, not “none” (`apps/api/config/sanctum.php:43-53`). Explicit `expires_at` overrides the global setting through the custom callback (`apps/api/app/Providers/AppServiceProvider.php:201-219`). Existing web tokens use the global 30 days, while POS tokens explicitly use one year (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:146-176,294-310`).
- `ResolveTenancy` runs before authentication and needs a central token `tenant:` ability to select the tenant database (`apps/api/bootstrap/app.php:166-185`; `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:116-134`). A bearer-only service token missing that claim normally cannot load its tenant `User`, so the proposed later `SERVICE_TOKEN_MISSING_TENANT_CLAIM` branch is not a universal error path.
- Membership-removal token revocation is feasible, and destroy/deactivate already revoke tokens (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:470-484,630-642`). But the token deletion is on the central connection while the membership mutation is tenant-side; describing both as one atomic transaction is incorrect.
- No standalone membership-removal writer exists today. The new hook needs a named service/observer boundary rather than an unspecified “when removed” rule.

EC-22 is not realistic: a central `SuperAdmin` has no `tenant_id` (`apps/api/app/Models/SuperAdmin.php:14-32`), while `SetPermissionsTeam` assumes any authenticated subject has one (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-29`). A central token calling an ordinary tenant route is not a supported tenant authorization path.

### M-3 — Role reads and effective-permission authorization violate D4

A caller holding only `users.assign-roles` is permitted to call both `userRoles` and the new effective-permissions endpoint (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:790-796`), but D4 allows that caller to read role names, not the permission matrix.

The current `userRoles` payload includes the complete permission set (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`). REV-1 specifies response shaping for the roles list, but not for `userRoles`. The new effective endpoint returns even more sensitive information: complete permissions, role provenance, discount limits, company scope, location IDs, and token scope (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:835-855`).

For subjects other than self, that payload must require `roles.view`; `users.assign-roles` may receive role names only.

### M-4 — Audit event and dedup design is incomplete

The sample `RoleCreated`, `RoleUpdated`, and `RoleDeleted` classes do not extend `DomainEvent`, define `getEventName()`, or define `getAuditPayload()` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:800-825`). The existing subscriber accepts `DomainEvent` and explicitly registers each class (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1181-1209,1236-1262`). Existing role events are immutable and have stable names `identity.role.assigned` and `identity.role.removed` (`apps/api/app/Modules/Identity/Domain/Events/RoleAssigned.php:14-43`; `RoleRemoved.php:14-43`). The three new event names and versioning contract must be explicit.

Global `(principal_id, token_id)` attribution also needs to be implemented at the audit chokepoint. Today the subscriber supplies only event class/time metadata (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1194-1208`), and `AuditService` adds impersonation metadata but no token id (`apps/api/app/Modules/Compliance/Services/AuditService.php:43-84`).

The denial-dedup algorithm is impossible with one five-minute Redis counter. Once the key expires, its suppressed count is gone, so the “next emitted event” cannot carry the previous window’s count (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:829-831,1069`). It needs distinct emission-lock and retained-count state, and a Redis integration test rather than “SQLite counter arithmetic.”

### M-5 — Static guard misses unknown literals and routes

The custom-rule precedent is valid: level 8 is configured at `apps/api/phpstan.neon:5-8`, existing rules live under `apps/api/app/PHPStan/Rules/`, and `ForbidFloatCastOnDecimalProperty` is registered at `apps/api/phpstan.neon:43-47`.

But the proposed rule only flags literals already present in the registry (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:721-730`). A typo such as `invoices.posst` is absent from the registry and therefore passes—the most important failure mode.

PHPStan also analyzes only `app/` (`apps/api/phpstan.neon:5-8`), so it cannot cover top-level route files without widening its paths or adding a separate AST guard. As written, wave 1 requires a large shrink-only ignore list for the existing sites; it is implementable at level 8, but not “without a mass baseline.”

The enum⊆manifest equality test and locale coverage tests are implementable. The locale roots are real at `apps/web/src/locales/en/common.json:1289`, `fr/common.json:1306`, and `ar/common.json:1272`. The generated TS union is also compatible with rule 7: it already exists at `apps/web/src/hooks/permissionsMap.generated.ts:312`, while rule 7 forbids manually editing generated domain types (`CLAUDE.md:33-34`).

### M-6 — Convention 09/10/11 obligations are incomplete

- Roles, permissions, and users are already explicitly excluded from the catalogue-unique ratchet (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:131-132,160,175`). §6 incorrectly speaks of a future possible exclusion (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1016-1021`).
- `CategoryPermissionsSecondLocationTest` is not a meaningful location-axis test. Categories are company-scoped only; their queries and writes use `company_id`, with no location binding (`apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:28-35,101-108,115-178`).
- The convention-10 matrix lacks real code `path:line` evidence in G8 and G15, and G13 cites an audit rather than code (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:60,65,67`). Convention 10 requires direct AutoERP code citations (`docs/conventions/10-BENCHMARK-FIRST-SPECS.md:45-53,59-62`).
- The glossary table does not consistently provide an operator surface: Permission, Permission verb, Permission registry, and Grant use “none”, “—”, or code review rather than a defined operator-visible read surface (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:91-95`).
- The Principal row names a new `UserService` as the common writer (`:83`), but no such service is specified in the implementation sections or wave deliverables.
- General-manager wording/surface drifts from the incoming canonical row, as covered by B-3.

### M-7 — Edge-case register disposition

| Rows | Gate assessment |
|---|---|
| EC-1–EC-4 | Expected behavior and PG lane are realistic, but incomplete because `UserController::update()->syncRoles()` and membership-based recoverability are missing (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380-389`). |
| EC-5 | “Removed next release” is not decided by the design: sync never deletes and pruning is an explicit later operator action (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:598,1046,1206`). |
| EC-6–EC-7 | Decided and PG-realistic. In-place permission-name update preserves pivots. |
| EC-8 | Expected behavior is sound, but step 7 is not actually after commit; see M-8. |
| EC-9 | Correct only after safe legacy-role adoption and same-wave `customised_at` writers exist; currently blocked by B-2. |
| EC-10 | Refers to a “single-role path” absent from `PermissionSyncService::sync(string, bool)` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:563-575,1051`). |
| EC-11–EC-12 | Decided and PG-realistic after metadata adoption. |
| EC-13 | The 422 floor refusal is not a 403 denial, yet it is said to be audited as one (`:1054` versus denial rule `:829`). Its audit event/mechanism is undecided. |
| EC-14 | Decided; vitest/playwright are appropriate (`apps/web/src/lib/tenantScopedKey.ts:29-34`). |
| EC-15 | Contradicts W-LOT-A-1a; see B-3. |
| EC-16 | Feasible but no independent membership writer or cross-DB atomicity contract is named; see M-2. |
| EC-17 | Decided and realistic. |
| EC-18 | Insufficient: it tests only permission-method narrowing, not `hasRole()` and target-user `getAllPermissions()` bypasses; see B-1. |
| EC-19–EC-20 | PG-only lane is correct. The floor must lock the role/pivot state in deterministic order, not merely unspecified “candidate rows.” |
| EC-21 | Test lane is realistic, but expected behavior leaves system roles unmarked and unprotected in compatibility mode; see B-2. |
| EC-22 | Not a supported middleware/model path; see M-2. |
| EC-23–EC-27 | Mechanically realistic, subject to fixing `authz.self` and the PHPStan unknown-literal hole. |
| EC-28 | Single expiring counter cannot provide the promised next-event count; Redis, not SQLite, must prove TTL behavior. |
| EC-29 | Decided and realistic; existing filter is at `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:326-335`. |
| EC-30 | The stated test cannot initially pass: current manager grants already combine create with post/confirm/allocate/refund across several proposed groups (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:593-610`). `PermissionVerb::isFinanciallyDecisive()` also excludes `confirm` while the group definition includes it (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:394-397,869`). |
| EC-31 | Decided residual and lanes are appropriate. |
| EC-32 | PG lane is appropriate, but the status CHECK and all impersonation/PIN writers must be included; see M-1. |

Missing scenarios that must be added include: token-scope bypass through `hasRole`; adoption of a customized NULL-team role carrying the W-LOT marker; and a fleet sync where one child returns BLOCKED/FAILED but `tenants:run` itself returns success.

### M-8 — Cache flush is not after commit

The service says `forgetCachedPermissions()` is executed “inside the transaction’s `finally`, i.e. after commit” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:606`). A `finally` inside the transaction callback executes before the callback returns and therefore before Laravel commits.

The cache flush must be outside the transaction/through an after-commit hook, while the team id is still set to the tenant cache namespace. Otherwise another request can rebuild the old snapshot between the early flush and commit.

### M-9 — Deprecation set is internally contradictory

The declared 25-key dead list includes `batches.delete`, `batches.recall`, and `products.import` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:940`), then the next paragraph says all three must be excluded (`:942`). There is no final authoritative deprecation set.

This must be a single mechanically derived list after wave 0a, especially because deprecation removes keys from all system-role templates (`:944`).

## MINOR

1. The spec repeatedly says “there is no `Gate::before`” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:58,125,664,1063`). There is no application-authored global bypass, but Spatie registers a permission `Gate::before` because `register_permission_check_method=true` (`apps/api/config/permission.php:103-107`). Use the precise wording.

2. The seeder dependency census says five call sites (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:630`), but PHP references exist in the exporter, `DatabaseSeeder`, `DemoTenantSeeder`, `CoffeeShopSeeder`, `ParapharmacySeeder`, `ProductionSeeder`, and `TenantInitializationService` (`apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29-30`; `apps/api/database/seeders/DatabaseSeeder.php:76`; `DemoTenantSeeder.php:111`; `CoffeeShopSeeder.php:116`; `ParapharmacySeeder.php:316`; `ProductionSeeder.php:70`; `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213-216`).

3. “Four inert tombstones are reclassified by the public allow-list” is misleading (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:704`). They are authenticated 410 routes (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:66-71`) and need a named tombstone exemption, not classification as public.

4. Wave 4 uses ditto quotation marks rather than naming its second-location and rerun obligations (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1031`). Convention 09 asks for explicit evidence-bearing tests.

5. A `PermissionDefinition` label model based only on module and action means different resources with the same verb necessarily share one generic action label (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:300-307,357-361`). That is acceptable only if intentional; the current Roles UI likewise translates the suffix rather than a permission-specific label (`apps/web/src/features/settings/RolesPage.tsx:37-42`).

## Citation audit

| Claim | Verified / wrong, with real line |
|---|---|
| Base SHA is `971528977` | **Wrong/stale.** Reviewed HEAD is `4f5d2dd46`; declaration is at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7`. |
| Membership glossary row is `docs/glossary.md:16` | **Wrong.** Line 16 is the table separator; Membership is `docs/glossary.md:20`. |
| Permission declarations occupy seeder `:48-559`; templates `:578-871` | **Verified.** `apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559,578-871`; file is exactly 871 lines. |
| Existing admin uses `Permission::all()` at seeder `:568` | **Verified.** `apps/api/database/seeders/RolesAndPermissionsSeeder.php:564-570`. |
| W-LOT marker check is immediately before current seeder `:568` | **Wrong as a lane citation.** Incoming implementation is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`; current `:568` is only the old destructive `syncPermissions()` call. |
| `User.php:216-241` proves both narrowing methods | **Incomplete.** That range contains only token-scope extraction. Actual overrides are `apps/api/app/Modules/Identity/Domain/User.php:185-212`; extraction is `:216-241`. |
| Identity route references `:41,57,59,61,64,68,75,78,79` | **Verified.** `apps/api/app/Modules/Identity/routes.php:39-80`. |
| `RoleController::userRoles` exposes permissions at `:429-444` | **Verified.** Payload is specifically `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`. |
| Role hard-coded protection at `RoleController:228,277`, permission replacement at `:246` | **Verified.** `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247,271-298`. |
| Role-assignment audit at `RoleController:360-372` | **Verified.** `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:360-372`. |
| Roles UI matrix at `RolesPage.tsx:151,186-201` | **Verified.** `apps/web/src/features/settings/RolesPage.tsx:148-151,174-201`. |
| POS `pinHolders()` contract at `PosAuthController:46-57` and payload at `:99` | **Verified.** `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57,87-100`. The additional scope-inheritance conclusion is wrong, as covered in B-1. |
| Email remains unique using original users migration `:34` | **Stale citation.** Original constraint is `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:33-35`; the effective schema is a partial unique index for non-null email at `apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`. |
| Sanctum abilities are JSON | **Wrong.** DB type is nullable TEXT at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:17-21`. |
| Personal-access tokens are central | **Verified.** `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`; registration at `apps/api/app/Providers/AppServiceProvider.php:196-219`. |
| Token ordering insertion site is `bootstrap/app.php:178-185` | **Verified.** Team setup follows authentication, and tenant-claim enforcement follows team setup at `apps/api/bootstrap/app.php:174-185`. |
| Permission schema unique key at migration `:30` | **Verified.** `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:23-31`. |
| Role/team schema semantics at permission config `:99,134` | **Verified.** `apps/api/config/permission.php:93-99,127-139`. |
| Shared Spatie cache key/store at `permission.php:192,200` | **Verified.** `apps/api/config/permission.php:186-200`. |
| Entry boot sync and cache-reset citations | **Verified.** `apps/api/docker/entrypoint.sh:150-176`; failures are currently caught and boot continues. |
| `RequireAnyPermission` implementation/message at `:14-25` | **Verified.** `apps/api/app/Http/Middleware/RequireAnyPermission.php:11-25`. |
| Permission exporter/preflight citations | **Verified.** `apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:12-43`; `scripts/preflight.sh:136-152`. |
| Generated permission union at `permissionsMap.generated.ts:312` | **Verified.** Full path: `apps/web/src/hooks/permissionsMap.generated.ts:312`. |
| `RBACTest:137-165` | **Path incomplete.** Real path is `apps/api/tests/Feature/Identity/RBACTest.php:137-165`. |
| `PermissionSeeder.php` is 274 lines, contains `credit-notes.cancel` at `:72`, one ProductionSeeder caller at `:75` | **Verified.** `apps/api/database/seeders/PermissionSeeder.php:68-72`; `apps/api/database/seeders/ProductionSeeder.php:68-76`. |
| Marketplace kill-switch/admin gates at `:49,92-97` | **Verified.** `apps/api/app/Modules/Marketplace/Presentation/routes.php:47-50,87-101`. |
| Current admin/company creation self-grant at CompanyController `:151` | **Verified.** Membership creation is `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:147-155`. |
| MembershipRole production authorization read at `UserController:929` | **Verified.** `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-936`. |
| Existing partial unique exclusions for roles/permissions/users are future work | **Wrong.** They already exist at `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:131-132,160,175`. |
| W-LOT general-manager grant/location contract is preserved exactly | **Wrong.** Incoming role requires unrestricted memberships at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:36-49` and canonical glossary surface is `lane/w-lot-a-1a:docs/glossary.md:21,91`. |
| Wave 0a touches none of W-LOT’s files | **Wrong.** Both modify BatchExpiry routes and `docs/glossary.md`; spec tasks are at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1092,1097`. |
| `lane/t1-transfers-edge` and `lane/t2-receipt-spine` presently overlap | **No overlap at the requested refs.** Both `git diff --stat dev...<lane>` results are empty; T1 is already an ancestor of dev and T2 equals dev. |

All other explicit current-tree path ranges in REV-1 were opened and agree materially with the cited code, including the route alias map, auth guards, policy registrations, Roles UI fallback, permission exporter, refund masking test, marketplace kill-switch, audit metadata columns, POS ladder, and `tenantScopedKey`.

## Rejected false positives

- **Ratchet arithmetic is correct.** The CSV has 1,054 API routes: 642 middleware, 130 controller, 46 FormRequest, 8 policy, 68 super-admin-only, 142 auth-only, and 18 public (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:32-45`). Under REV-1’s exact middleware-only classification, uncovered is `130 + 46 + 8 + 142 = 326`: 177 writes and 149 reads. Exempting four method-qualified 410 tombstones, six self-service writes, and `GET /auth/me` gives ceilings **167 writes / 148 reads**. Those numbers should remain.
- **The ratchet is mechanically testable in PHPUnit.** The existing audit obtained the same data from the live Router and `gatherMiddleware()` (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:9-20`). The flaw is the unrestricted `authz.self` declaration, not live-router feasibility.
- **The proposed PG CHECK expressions are valid.** `principal_kind IN (...)`, `principal_kind='human' OR (...)`, `template_key IS NULL OR is_system`, and `customised_at IS NULL OR is_system` are valid PostgreSQL checks. The migrations are additive, tenant-scoped, and do not alter an enum-typed column (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:968-1012`).
- **NULL-team roles do not inherently break lookup.** W-LOT-A-1a’s real resolver already handles NULL/team roles without re-homing and rejects ambiguity (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:104-128`).
- **`admin = registry activeKeys()` is not inherently incompatible with A-1a.** It can coexist if the retained delta receives the same registry list and marker/adoption issues are fixed. Replacing `Permission::all()` is consistent with making the registry the catalogue boundary.
- **Generated TS permission types do not violate rule 7.** The union is generated, not manually authored (`apps/web/src/hooks/permissionsMap.generated.ts:312`; `CLAUDE.md:33-34`).
- **No Spatie `role:` middleware currently needs migration.** Production role bypasses are direct `hasRole()` calls; central `central_admin_role` belongs to the separate platform guard.
- **`PermissionSeeder` deletion evidence is sound.** It is exactly 274 lines, its only production caller is `ProductionSeeder`, and it contains the stranded `credit-notes.cancel` declaration (`apps/api/database/seeders/PermissionSeeder.php:68-72`; `ProductionSeeder.php:73-76`).
- **Central token storage is correct.** `personal_access_tokens` belongs in `synerivia_central`, not tenant databases (`apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`).

## Preserve

The fix round must preserve:

- Direction B: per-module catalogue-as-code with deploy-time sync.
- D1 tenant-scoped roles and membership-based company/location scope.
- D2 template-delta behavior, including no deltas after the first tenant customisation.
- D3 separate shrink-only read/write ceilings and the verified **326 / 177 / 149 → 167 / 148** arithmetic.
- D4 `roles.view` for permission details and role-name-only visibility for `users.assign-roles`.
- D5 kebab-resource naming, a genuinely closed verb enum, and in-place rename-map migration.
- D6 no general per-user permission overrides.
- D7 sequencing after W-LOT-A-1a, retaining only a truly non-overlapping pre-merge 0a subset.
- D8 immutable audit-chain events for role mutations and write-route 403 denials.
- First-class human/service principals on `users`, with effective token permissions equal to live owner grants intersected with token scope.
- Central `personal_access_tokens`, explicit `expires_at`, and tenant claims.
- W-LOT’s `provisioning_source` CHECK/index, NULL-team no-re-homing rule, marker preservation, exact `general_manager` grants, and unrestricted-membership assignment guard.
- In-place permission renames so role and direct-permission pivots retain their numeric permission ids.
- Orphan reporting without automatic deletion.
- `admin` bounded by the registry’s active catalogue rather than `Permission::all()`.
- Existing generated frontend-map signature, path, hash header, and byte-drift guard.
- The PG-only lanes for advisory locks and concurrency tests, with SQLite used only for portable logic.

## Owner decisions required

- **OQ-1 is genuinely open.** The recommended human-only last-admin floor is coherent, but the spec must stop claiming §§1–8 are independent of it while already hard-coding that answer at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:205,776-782,1045,1174-1176`.
- **OQ-2 is genuinely open.** If 365-day service and 90-day human TTLs are selected, they must be explicit per-token `expires_at` values and the pruning schedule must be added. Current default behavior is 30 days (`apps/api/config/sanctum.php:43-53`; override semantics at `apps/api/app/Providers/AppServiceProvider.php:201-219`).
- **OQ-3 is not open.** Settled D8 requires write-denial events on the audit chain. Remove the separate-security-table recommendation (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1182-1184`).
- **OQ-4 remains an operational owner decision**, but only after B-5 is fixed. Defaulting boot sync on is not safe while the fleet runner discards child failures and the entrypoint continues after partial errors (`apps/api/docker/entrypoint.sh:150-169`; `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2956`).

VERDICT: CHANGES-REQUIRED