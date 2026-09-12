# Codex spec gate r3 — roles & permissions catalogue design rev 3 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at repository HEAD:

```text
13947608c
```

The spec’s production-code baseline remains `971528977`; `git diff 971528977..13947608c -- apps/api apps/web apps/pos packages/shared` is empty. The round-2 register was read first at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r2.md:1-414`.

`apps/api/vendor/autoload.php` is absent, so Artisan could not boot. Route arithmetic was recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv:1-1055`.

## Rev-2 closure table

| Rev-2 finding | Rev-3 disposition |
|---|---|
| B-1 — `EnforceTokenScope` ordered but unattached | **CLOSED at rev-3 anchor** `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:397-406`; central-super-admin fallout is a new MAJOR below |
| B-2 — template migration #1 not executable without violating D2 | **NOT CLOSED** — frozen-input invocation is now specified at `:759-823`, but it reintroduces rename sources and mutates customized roles before adoption |
| B-3 — no `general_manager` v0 baseline | **CLOSED at rev-3 anchor** `:821-829`; it matches `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:90-91` |
| B-4 — wave 0b cannot provision marked tenants | **NOT CLOSED** — `permissions:ensure` exists at `:1037-1059`, but its non-admin grants violate D2 |
| B-5 — SoD guard red by construction | **NOT CLOSED** — arithmetic is corrected at `:1363-1388`, but `behavesAs` is not stored and the worked manifest creates a new forbidden combination |
| B-6 — route literal guard rejects enum syntax | **CLOSED at rev-3 anchor** `:1152-1163` |
| B-7 — global template-key unique breaks compatibility | **CLOSED at rev-3 anchor** `:1565-1570` |
| B-8 — boot failure state not durable | **CLOSED at rev-3 anchor** `:936-1000` |
| M-1 — incomplete human-seat exclusion | **CLOSED at rev-3 anchor** `:469-490` |
| M-2 — service creation/membership contract absent | **NOT CLOSED** — creation is specified at `:267-295`, but generic user endpoints still mutate services |
| M-3 — user-writer/notification census incomplete | **NOT CLOSED** — the new census at `:223-242` still omits live writers/selectors |
| M-4 — attribution written to the wrong audit argument | **CLOSED at rev-3 anchor** `:1301-1305` |
| M-5 — denial counter loses concurrent increments | **CLOSED at rev-3 anchor** `:1313-1329`; the proposed concurrency test wording remains a MINOR |
| M-6 — contradictory deprecation/custom-role semantics | **NOT CLOSED** — system-role removal is impossible under additive-only step 7, and `reports.view` is outside the authoritative 22 |
| M-7 — rollback cannot restore the old users schema | **CLOSED at rev-3 anchor** `:1754-1761` |
| M-8 — scaffold contract inconsistent | **CLOSED at rev-3 anchor** `:136-172` |
| M-9 — edge-case register incomplete/inconsistent | **NOT CLOSED** — EC-9/21b/28/32a remain false or incomplete |
| minor 1 — CSV count 638/642 | **CLOSED at rev-3 anchor** `:1118` |
| minor 2 — wave 0a closes fourteen/sixteen | **CLOSED at rev-3 anchor** `:1119,1711` |
| minor 3 — Coupon path | **CLOSED at rev-3 anchor** `:1683-1685` |
| minor 4 — T1/T2 branch assignment | **REJECTED-correctly** at `:1689-1694`; T1 remains empty and T2 owns the fixture, although T2 has advanced again |
| minor 5 — marker cited at current-tree `:568` | **CLOSED at rev-3 anchor** `:742` |
| minor 6 — singular migration command | **CLOSED at rev-3 anchor** `:1521` |
| minor 7 — sync never writes role name | **CLOSED at rev-3 anchor** `:1573` |
| minor 8 — version allowed without template key | **CLOSED at rev-3 anchor** `:1570` |
| Rev-2 citation audit | **CLOSED at its stated rev-3 anchors**; new stale/false citations are registered below |

## BLOCKER

### B-1 — Fresh-tenant sync is not idempotent and blocks on its second run

The settled sequence runs rename → registry creation → frozen W-LOT migration at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:908-925`.

The frozen permission list is copied from the lane’s entire legacy catalogue (`:785-790`). That list contains every rename source:

- `uom.edit`: `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:260`
- `deliveries.edit`: `lane/…/RolesAndPermissionsSeeder.php:280`
- `pos_held_orders.*`: `lane/…/RolesAndPermissionsSeeder.php:487-489`
- `catalog_cart.*`: `lane/…/RolesAndPermissionsSeeder.php:548-553`

The lane delta creates every missing frozen permission at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:65-73`.

Therefore, on a fresh post-wave-1 tenant:

1. Rename finds no source row.
2. Registry creation creates the new targets from `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1457-1469`.
3. Template migration recreates all old source rows.
4. The first run finishes with both namespaces.
5. The second run reaches `from` and `to` together and blocks with `rename_target_exists` under `:910`.

That directly falsifies “second run reports `ALREADY_CURRENT` with all counters zero” at `:908` and makes repeated staging auto-deploy unsafe.

### B-2 — Template migration mutates customized roles before adoption can protect them

Step 5 invokes the frozen lane delta before step 6 performs adoption (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:921-927`).

The lane implementation:

- resolves every catalogue role: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:56,74-90`;
- grants every missing frozen permission: `:163-178`;
- revokes `manager`’s `batches.recall`: `:167,179-180`.

For an unmarked legacy tenant whose `manager` was customized before wave 1, the delta therefore writes that role before adoption compares it with `LegacyRoleBaseline`. A tenant customization consisting of removing one frozen grant is re-added, making the role compare equal and become “pristine”; later template deltas then continue widening it.

This violates settled D2 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:35` and contradicts EC-9/EC-9a’s promises that the role receives nothing and “no delta is applied” at `:1627-1628`.

### B-3 — The SoD model cannot implement its own rev-3 contract

`PermissionDefinition` accepts `$behavesAs` in `legacy()` but does not store it:

- declared stored properties: `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:521-535`;
- accepted argument: `:554-561`;
- later classification reads `$behavesAs`: `:565-566`.

Thus `payments.pay-supplier` and `supplier-invoices.approve-invoice-first` cannot retain the classification required by SoD-1 at `:1365`.

Separately, the worked Procurement manifest gives `general_manager` both:

- `purchase-orders.create`: `:659-660`;
- new `purchase-orders.approve`: `:661-662`.

They share `sodGroup: purchase-order`, so this is a new create-plus-decisive combination. The hard half explicitly forbids adding a new baseline entry at `:1369-1372`. The example therefore makes `PermissionSodTemplateTest` red even after `$behavesAs` is fixed.

### B-4 — `permissions:ensure` widens customized system roles

The command grants every requested key to every named `--grant-to` role without reading customization state (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1043-1056`). Wave 0b explicitly sends `services.*`, `service-categories.*`, and `credit-notes.cancel` to `manager` and `general_manager` at `:1719`.

The template columns do not exist until wave 1 (`:1553-1572`), so the stopgap has no stated way to distinguish a customized system role. “Additive” does not make the write safe: granting a new capability is precisely the widening D2 says customized roles must not receive (`:35`).

The claimed `PermissionsSyncSupersedesEnsureTest` is also impossible as written: the first wave-1 sync must at least adopt existing roles and report non-zero adoption/template-migration counters, contrary to `ALREADY_CURRENT` with every counter zero at `:1059`.

## MAJOR

### M-1 — Global token middleware lacks a central-`SuperAdmin` no-op contract

The global `api` group also wraps central admin routes in `apps/api/routes/api.php:25-48,56-97`. Those authenticate a `SuperAdmin` carrying `['super-admin']` (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminAuthController.php:53-57`), whose model has neither `principal_kind` nor `tenant_id` (`apps/api/app/Models/SuperAdmin.php:14-32`).

Rev 3 specifies no-op cases only for unauthenticated, session, and unscoped human requests (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:401-406`). Its coverage test covers `auth:sanctum`, not `auth:sanctum-admin`.

The middleware contract must explicitly no-op for authenticated subjects that are not tenant `User` principals, with coverage for the central admin pipeline. Otherwise the globally attached middleware may read service-principal fields from `SuperAdmin`.

### M-2 — Generic human-management endpoints remain a second service-account surface

Rev 3 makes `ServiceAccountController` the service-account management surface and only requires `UserController::update` to refuse service targets (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:87-89,293-295`).

Current generic user endpoints can still target any row in the tenant:

- destroy/inactivate and revoke memberships: `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:430-503`;
- activate: `:509-570`;
- deactivate: `:580-663`.

Consequently a caller with `users.update` or `users.delete`, but without the corresponding `service-accounts.*` permission, can alter a service principal’s status, memberships and tokens. All three endpoints need an explicit wrong-surface disposition or must route through the service-account surface.

### M-3 — The claimed complete user-writer and notification census is still incomplete

Writers absent from the “every existing users writer” census at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:223-232` include:

- password-reset mutation: `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:791-817`;
- DemoPharmacy re-run PIN/discount update: `apps/api/database/seeders/DemoPharmacySeeder.php:997-1004`.

The “three exist today” notification inventory at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:234-242` omits:

- permission-selected Treasury recipients: `apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php:23-38`;
- its recurring-expense sender: `apps/api/app/Modules/Expense/Presentation/Console/GenerateRecurringExpensesCommand.php:166-181`;
- three reconciliation senders: `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php:476-490,742-755,1141-1155`;
- two maturity senders: `apps/api/app/Modules/Treasury/Presentation/Console/InstrumentMaturityAlertsCommand.php:157-173,202-216`;
- support-access recipient selection: `apps/api/app/Modules/SupportAccess/Infrastructure/Notifications/TenantDatabaseSupportAccessNotifier.php:55-66`.

All currently admit service principals possessing the selected permission. EC-32a’s “all three” claim at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1658` is therefore false.

### M-4 — Deprecation cannot remove keys from non-admin system roles

Step 3 says deprecated keys are removed from every system-role template (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:912`), but step 7 is expressly additive-only with “no revocation ever” (`:923-927`). Admin is corrected through exact `syncPermissions(activeKeys())`; manager, accountant, and other system roles retain their old deprecated grants indefinitely.

That contradicts the lifecycle promise at `:1493-1495`.

The authoritative set is also not authoritative: the stated 22 entries at `:1485-1487` omit `reports.view`, while `:1497` separately declares it deprecated. The implementable total is therefore 23 unless one listed key is removed. The current source confirms `reports.view` is still seeded and slated for retirement at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:325-332`.

### M-5 — Sync cannot emit the declared `RoleUpdated` event

A replacement grant to a custom role must emit `RoleUpdated` during sync (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:914-919`).

The event signature requires non-null `companyId` and `actorUserId` (`:1283-1291`). Sync is tenant-wide, executes from a console/deploy context, and has neither a company nor an authenticated actor. The existing subscriber likewise requires a string company id (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1186-1204`).

The audit contract needs a defined system/console actor and a tenant-wide company-null representation, while retaining the settled immutable, versioned `identity.role.updated` event family.

### M-6 — Deleting `PermissionSeeder` leaves test code uncompilable

Moving deletion after `credit-notes.cancel` is correct, and `ProductionSeeder` has the expected caller at `apps/api/database/seeders/ProductionSeeder.php:68-75`.

But that is not the only caller. Tests import or seed the class, including:

- `apps/api/tests/Feature/Pricing/PricingPermissionSeederTest.php:68-78,122-130`;
- `apps/api/tests/Feature/Pricing/CheckMarginTest.php:66`;
- `apps/api/tests/Feature/Identity/GuardConsistencyTest.php:113-120`;
- `apps/api/tests/Feature/Document/DocumentAdditionalCostTest.php:69-70`;
- `apps/api/tests/Feature/Taxation/TaxConfigurationCapabilityDelegationTest.php:68-72`;
- `apps/api/tests/Feature/Taxation/TaxBreakdownEndpointTest.php:49`;
- `apps/api/tests/Feature/Taxation/TaxConfigurationManagementTest.php:37`.

Wave 0b-6 only names deletion and the production caller at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1719`. The test migrations/replacements must be part of that task.

### M-7 — Convention 09’s wave-0b second-location test is impossible

Rev 3 calls Channel a “genuinely location-bound” resource and requires `ChannelPermissionsSecondLocationTest` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1602`.

The actual `Channel` model has `company_id` but no `location_id` (`apps/api/app/Modules/Channel/Domain/Models/Channel.php:15-44`), and creation accepts only company, name, adapter and metadata (`apps/api/app/Modules/Channel/Presentation/Controllers/ChannelController.php:36-49`). There is no second-location channel write to exercise.

The same convention row still says re-run `tenants:seed` twice (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1602`), contradicting the settled `permissions:ensure` deployment at `:1725-1731`.

### M-8 — The current T2 lane now overlaps wave 0a

Current refs are:

```text
dev                         65cd9aff9
lane/w-lot-a-1a             04e60530c
lane/t1-transfers-edge      86273346a
lane/t2-receipt-spine       82f362d72
```

T1 remains empty. W-LOT-A-1a’s 66-file diff remains disjoint from the verified 0a production list.

T2 is no longer the one-file branch described at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1689-1694`; it now has 18 files, 1,679 insertions and modifies `.github/workflows/ci.yml:1139-1145`.

Wave 0a-2 requires registering the ratchet’s protected blob in CI (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1699`). That is a real file overlap with T2, so the no-overlap claim at `:1709` is stale.

### M-9 — `syncRole()` cannot run template migration #1 “for one role”

The single-role contract says it runs steps 5 and 7 for one template (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:884-889`), and the UI says re-apply runs the delta for that role only (`:1361`).

But `TemplateMigration::apply()` accepts only a tenant, and migration #1 delegates to a delta that resolves and mutates every role in `ROLE_GRANTS` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:27-30,56-90`). It has no role-scoped entry point. The re-apply contract is therefore not implementable as declared.

## MINOR

1. EC-21b predicts that the first duplicate NULL-team `manager` adopts and the second hits the partial unique (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1645`). The required resolver already says two matches produce `RoleCollision` before adoption at `:753`; the lane implementation does that at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:117-125`.

2. EC-28 proposes interleaving another client’s `INCR` “between the script’s read and clear” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1333,1653`). Redis Lua execution is atomic, so another client cannot interleave inside the script. A two-client race before/after the script can prove the outcome, but not the stated schedule.

3. The spec still says “table of five” after expanding the SoD baseline to eleven at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1394`; the real table is `:1374-1386`.

4. The sync pseudocode comment still says “steps 1..7” at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:895-905`; the settled service has ten steps at `:908-930`.

5. The PHPStan prose says there are eight existing registered rules at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1137-1139`. There are eight under `rules:` plus `ForbidFloatCastOnDecimalProperty` registered as a tagged service: `apps/api/phpstan.neon:33-47`.

6. OQ-2 says human tokens “shorten” from 30 to 90 days and immediately calls this a lengthening (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1813-1817`). It is a lengthening.

7. `template_key` is documented as belonging to `SystemRoleTemplate`, but the database CHECKs do not constrain it to the enum values (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1557-1570`). Either specify the finite-value CHECK/SQLite trigger or state explicitly that enum validity is application-only.

8. Missing edge cases following directly from the blockers/majors: fresh post-wave-1 tenant followed by a second sync; unmarked customized role whose only customization is a removed frozen W-LOT grant; global `api` execution with an authenticated central `SuperAdmin`; generic `UserController` operations against a service target; and console-originated deprecation replacement audit. The current register ends at EC-32d (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1657-1661`) without them.

## Citation audit

A mechanical pass found no numeric citation beyond EOF among existing HEAD files. Proposed files and branch-only files were checked separately. The semantic, missing-file, and stale citations are:

| Claim | Verified / wrong, with real line |
|---|---|
| G13 outlier locations | **Verified:** `catalog_cart.*` at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:479-485`; `pos_held_orders.*` at `:418-421`; `uom.edit` at `:189-193`; `deliveries.edit` at `:210-214` |
| Current admin receives `Permission::all()` | **Verified:** `apps/api/database/seeders/RolesAndPermissionsSeeder.php:564-568` |
| W-LOT migration cited as an ordinary HEAD file at spec `:741` | **Wrong at HEAD:** file is absent; real source is `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-74` |
| W-LOT delta cited as an ordinary HEAD file at spec `:744` | **Wrong at HEAD:** file is absent; real source is `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:21-100,104-205` |
| “No verification token is ever issued” to a null-email user | **Wrong:** `EmailVerificationService::sendVerificationEmail()` unconditionally deletes, creates and sends at `apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:27-40`; rev 3’s planned guard is necessary |
| “Every existing users writer” | **Wrong:** missing `AuthController.php:791-817` and `DemoPharmacySeeder.php:997-1004` |
| “Three notification selectors exist today” | **Wrong:** missing Treasury at `TreasuryAlertRecipients.php:23-38` and SupportAccess at `TenantDatabaseSupportAccessNotifier.php:55-66`, with senders cited in M-3 |
| Ratchet 326 / 177 / 149 | **Verified:** CSV `:1-1055` gives 642 MIDDLEWARE, 68 SUPERADMIN_ONLY, 18 PUBLIC, and 326 uncovered = 177 writes + 149 reads |
| Ratchet ceilings 167 / 148 | **Verified:** four write tombstones plus six write self routes and one read self route produce `177−4−6=167`, `149−1=148`; rules at spec `:1075-1120` are mechanically checkable |
| `authz.self` cannot self-certify | **Verified:** exact bidirectional allow-list and structural test are specified at spec `:1095-1101`; merely adding middleware does not whitelist a route |
| Current token storage/TTL | **Verified:** abilities TEXT and `expires_at` at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`; 30-day global at `apps/api/config/sanctum.php:43-53`; row expiry override at `apps/api/app/Providers/AppServiceProvider.php:196-219` |
| PAT storage is central | **Verified:** `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`; tenant claim is read centrally at `ResolveTenancy.php:116-134` |
| Channel is location-bound | **Wrong:** Channel has only company ownership at `apps/api/app/Modules/Channel/Domain/Models/Channel.php:15-44`; create uses company context at `ChannelController.php:36-49` |
| NULL-team lookup/re-homing | **Verified:** lane resolver preserves NULL and uses `(NULL OR tenant)` at `lane/w-lot-a-1a:…/LotActionPermissionDelta.php:104-128`; rev 3’s `RoleCollision` rule is sound at spec `:743,753` |
| EC-21b first-row adoption | **Wrong:** two matches are rejected before adoption by the resolver at spec `:753` and lane delta `:117-125` |
| “One authoritative deprecation set: 22” | **Wrong:** list at spec `:1485-1487` has 22 but `reports.view` is separately added at `:1497`; current source is `RolesAndPermissionsSeeder.php:325-332` |
| T2 branch SHA and one-file diff | **Stale:** spec `:1691-1694` cites `b41183f50`; current T2 is `82f362d72`, 18 files, including `.github/workflows/ci.yml:1139-1145` |
| PermissionSeeder has only the production caller relevant to deletion | **Wrong:** production caller is `ProductionSeeder.php:68-75`, but live test callers remain at the paths in M-6 |
| `RoleUpdated` usable by sync | **Wrong:** signature requires company and actor at spec `:1283-1291`; existing subscriber requires company at `DomainEventSubscriber.php:1186-1204` |
| General-manager snapshot | **Verified:** lane grants are revised manager plus `batches.recall` and `treasury.manage_all_locations` at `lane/w-lot-a-1a:…/RolesAndPermissionsSeeder.php:86-96` |
| SoD counts | **Verified:** manager’s seven at `RolesAndPermissionsSeeder.php:594,595,598,599,603,605,609`; accountant’s four at `:829,832,836,841`; lane GM mirrors the seven at lane seeder `:90-91` |
| PHPStan custom-rule precedent | **Verified:** level 8 at `apps/api/phpstan.neon:5-8`; custom tagged rule at `:43-47`; implementation precedent at `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-64` |
| New schema types/topology | **Verified:** both migrations are tenant-scoped/additive at spec `:1519-1573`; PG CHECK syntax is valid, existing status is string-backed rather than a PostgreSQL enum (`UserStatus.php:10-15`), and no enum-typed column is altered |
| Effective-permissions authorization | **Verified:** self or `roles.view`; `users.assign-roles` alone receives no matrix at spec `:1237-1255` |
| Last-admin floor coverage | **Verified:** all role-update/delete/remove, user update/destroy/deactivate, membership removal, sync, and impersonation paths are enumerated at spec `:1213-1235` |

## Rejected false positives

### Token narrowing idioms

The spec’s idiom analysis at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:323-336` is correct:

| Idiom | Result |
|---|---|
| `can:` middleware | Narrowed: Laravel authorization → Gate → Spatie’s registered Gate callback (`apps/api/config/permission.php:103-107`) → dynamically dispatched `User::hasPermissionTo()` (`User.php:185-198`) |
| `$user->can()` / `cant()` | Narrowed through the same Gate path |
| `Gate::authorize()` / `allows()` / `denies()` | Narrowed for dotted permission abilities; ordinary policy abilities remain policy decisions |
| `hasPermissionTo()` | Narrowed directly |
| `hasAnyPermission()` | Narrowed because Spatie checks each candidate through `checkPermissionTo()` |
| `getAllPermissions()` | Narrowed only on the authenticated subject carrying `currentAccessToken()` (`User.php:201-241`). `/auth/me` uses that subject at `AuthController.php:616-627` and `AuthUserData.php:34-55` |
| POS PIN payload `getAllPermissions()` | Correctly unnarrowed: it is called on a fresh PIN-holder model at `PosAuthController.php:87-100,218-225` |
| `hasRole()` family | Bypasses narrowing; the live backend sites are correctly enumerated at spec `:345-353` |
| Spatie `role:` middleware | None exists. `central_admin_role:` is a separate central guard, e.g. `SupportAccess/Presentation/routes.php:21-45` |

Other rejected concerns:

- `template_key` metadata and `provisioning_source` do not structurally collide. The lane CHECK constrains only a marked tenant-scoped `general_manager` at `lane/w-lot-a-1a:…/2026_09_06_205000_add_provisioning_source_to_roles.php:34-74`.
- The NULL-team ruling does not itself break lookup: the lane predicate at `LotActionPermissionDelta.php:111-128` is sound and performs no re-homing.
- `admin = PermissionRegistry::activeKeys()` is the correct post-registry boundary; the failure is the preceding lifecycle, not replacing `Permission::all()`.
- Central Sanctum storage, application-side TEXT ability parsing, per-row expiry override, and best-effort cross-connection token revocation are feasible as described.
- The two data migrations are additive, tenant-scoped, and use valid PostgreSQL CHECK/partial-index shapes. No forbidden PostgreSQL enum alteration is proposed.
- The PHPStan rule is implementable at level 8 with the acknowledged shrink-only baseline. Enum⊆manifest, locale coverage for `apps/web/src/locales/{en,fr,ar}/common.json`, and generated TypeScript output are compatible with rule 7.
- The last-admin floor and effective-permission read authorization are complete and should not be reopened.
- Moving `PermissionSeeder` deletion to 0b after `credit-notes.cancel` is the correct order; only the omitted test callers need adding.
- T1 remains empty, and W-LOT-A-1a remains disjoint from the verified 0a production paths. The current overlap is T2’s CI file only.

## Preserve

The fix round must preserve:

- Direction B and settled D1–D8, including tenant-scoped roles, D2 customized-role protection, the write-first ratchet, `roles.view`, kebab-resource keys, no general per-user overrides, sequencing after W-LOT-A-1a, and audit-chain events.
- First-class human/service principals and `owner grants ∩ token scope`, never union.
- The `forSubject()` versus `forTarget()` distinction and the intentionally unnarrowed POS PIN-holder payload.
- Global `api` attachment and priority ordering for `EnforceTokenScope`; add the central-subject no-op without returning to a route allow-list.
- Central `personal_access_tokens`, nullable TEXT abilities, `expires_at`, and existing per-row expiry precedence.
- W-LOT’s sole creation/marker ownership for `general_manager`, its exact frozen v0 grant set, public marker repository, and the NULL-team no-re-homing ruling.
- The settled full-sync order create → template migrations → adopt → template deltas → admin → after-commit cache. Repairs must make those phases safe rather than silently dropping the ruling.
- `admin = PermissionRegistry::activeKeys()`.
- Ratchet figures `326 / 177 / 149 → 167 / 148`, exact `authz.self` allow-list, separate tombstone classification, and sixteen wave-0a write closures.
- Human-only last-admin floor, deterministic locking, and all nine mutation paths.
- `roles.view` payload shaping and self-only effective-permission access.
- Immutable, versioned `identity.role.*` and `authz.denied` events on `audit_events`.
- Tenant-only additive migrations, the two topology-aware partial role indexes, nullable service password/email, and the service-row CHECK.
- The corrected eleven-entry SoD baseline, eighteen after `general_manager`, and the settled eight SoD groups.
- The generated TypeScript union under rule 7 and the acknowledged static-rule baselines.
- The lane’s General-manager glossary wording and sole assignment surface.

## Owner decisions required

No additional owner question is required; every finding above is an engineering/specification defect inside settled rulings.

| Open question | Gate assessment |
|---|---|
| **OQ-1** — service accounts count toward last-admin floor? | Genuinely policy-open; the recommended Human-only answer is consistently applied at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1215,1235,1807-1811` |
| **OQ-2** — service-token TTL | Genuinely open; current code establishes the 30-day global and per-row override. Correct “shorten” to “lengthen” at `:1817` |
| **OQ-3** — retire SoD baseline entries | Genuinely open business policy; current 11/18 arithmetic is code-verifiable at `:1369-1388,1819-1822` |
| **OQ-4** — enable boot sync by default | Genuinely open operational policy, but not safe to rule until B-1/B-2/B-4 are closed; its stated precondition at `:1824-1827` is not yet true |

VERDICT: CHANGES-REQUIRED