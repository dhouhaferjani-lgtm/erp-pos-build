# Session D — Task 9 onboarding-hour batch — adversarial gate r1 (tenancy/authz lens)

- Branch `fix/session-d-onboarding-hour-batch`, base `c94d23043` → head `6a7adfa81`
- Worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t9-onboarding-hour-batch`
- Scope of THIS reviewer: (e) C-13(i) pos_pin offboarding, (d) `/documents/auto-save` gating claim, (c) N-9 tenant provisioning + backfill migration. Items (a) W4R-1 and (b) W2-5 read only for cross-tenant/authz defects.
- READ-ONLY. Every line cited was opened in the worktree.

## VERDICT: spec ✅ + quality APPROVED (4 non-blocking findings to ledger)

No cross-tenant leak, no auth bypass, no silent-403 introduced by this diff. The three claims under review hold on the current tree.

---

## (e) C-13(i) — `pos_pin` cleared on offboarding

### Verified

- `deactivate()` calls `clearPosPinFor()` inside the same `DB::transaction` as the status flip and the membership sweep — `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:642` (transaction opens :629, status flip :634-635, `revokeMembershipsFor` :641).
- `destroy()` (soft-delete) likewise — `UserController.php:484` (transaction :470, status flip :475-476, `revokeMembershipsFor` :483).
- `clearPosPinFor()` — `UserController.php:1019-1036`: no-ops when `pos_pin === null` (:1021-1023), writes `null` through the model (:1025), emits the SAME `user.pos_pin_cleared` audit event the explicit clear endpoint emits (:1029-1034 vs :709).
- The `hashed` cast is null-safe: `apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:1471-1475` returns `null` unchanged, so `update(['pos_pin' => null])` really nulls the column (same call shape the pre-existing clear endpoint uses at `UserController.php:706`).
- Tenant scoping intact: both endpoints resolve the target with `User::where('tenant_id', $currentUser->tenant_id)` (`UserController.php:445-447` for destroy; the same shape in deactivate), and under db-per-tenant the query runs on the tenant DB anyway.
- No ghost on the PIN surfaces: `verifyPin`, `pinData` and `hasPins` all read the single `pinHolders()` definition — `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`, used at `:87`, `:206`, `:344` — which scopes to tenant + ACTIVE `user_company_memberships` of the CompanyContext company + ACTIVE account + non-null `pos_pin`. An offboarded user fails all three belts even before the PIN is nulled; the PIN clear additionally frees the value in the tenant-wide uniqueness scans (`PosAuthController.php:133-144`, `UserController.php:724-728`).
- Tests are real deny/behaviour assertions, not `assertTrue(true)`: `apps/api/tests/Feature/Identity/UserManagement/UserActionsTest.php:241` (deactivate clears + audit row), `:263` (delete clears), `:280` (reactivate does NOT restore), `:303` (no event for a user with no PIN).

### Offboarding-path enumeration (the "which don't" answer)

| Path | Route | Clears `pos_pin`? |
|---|---|---|
| Deactivate user | `POST /api/v1/users/{id}/deactivate` (`Identity/routes.php:73`) | YES — `UserController.php:642` |
| Delete (soft) user | `DELETE /api/v1/users/{id}` (`Identity/routes.php:71`) | YES — `UserController.php:484` |
| Explicit PIN clear | `PATCH /api/v1/users/{id}/pos-pin` with `pin: null` | YES — `UserController.php:706` (pre-existing) |
| Remove membership from a company | **no such endpoint exists** — the only write of `MembershipStatus::Revoked` anywhere in `apps/api/app` is `revokeMembershipsFor()` (`UserController.php:990-998`), reached only from the two cascades above, and it sweeps EVERY company | N/A (covered by the two cascades) |
| Role strip | `DELETE /api/v1/users/{userId}/roles` (`Identity/routes.php:80` → `RoleController.php:390-424`) and `PATCH /api/v1/users/{id}` with `role` (`UserController.php:381-389`) | **NO** — see finding 1 |

---

## (d) `/documents/auto-save` gating — implementer's "already gated" claim

**CONFIRMED on the current tree.**

- Route group carries the rule-12 middleware: `apps/api/app/Modules/Document/Presentation/routes.php:34` — `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`.
- The route itself carries the coarse gate: `routes.php:75-77` — `Route::post('/documents/auto-save', …)->middleware('can:documents.update')`.
- Per-TYPE gate: `AutoSaveDraftRequest::authorize()` maps each of the 7 auto-savable types to the sibling store route's `*.create` ability (`AutoSaveDraftRequest.php`, const `AUTO_SAVABLE_CREATE_ABILITY`, `authorize()`), and `type` is narrowed by `Rule::enum(DocumentType::class)->only(self::autoSavableTypes())` so `correcting_entry` is unreachable.
- Update-branch type spoofing is refused server-side: `DraftPersistenceService::assertTypeMatches()` — `apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:196-208` — throws `DraftNotEditableException::typeMismatch()`, surfaced as 422 by `DraftController.php:199-204`. It runs BEFORE `assertDraftEditable()`/`updateDraftLines()` (`DraftPersistenceService.php:129-141`), so no line write happens on a mismatch.

**Deny-path tests are real and NOT made vacuous by the new lineless short-circuit** (`apps/api/tests/Feature/Document/AutoSaveRouteHardeningTest.php`):
- `:115` viewer lacking `documents.update` → 403, `Document::count() === 0`; payload carries a LINE (`:123`).
- `:145` viewer → 403 AND `document_sequences.last_number` stays 7; payload carries a LINE (`:161`).
- `:184` cashier without `purchase-orders.create` → 403 AND PO sequence stays 3; payload carries a LINE (`:204`).
- `:241` update branch, type the caller cannot create → 403.
- `:278` / `:319` spoofed `type` (incl. aimed at a correcting-entry draft) → 422.
- `userWithAbilities()` grants real Spatie permissions with the tenant team id set (`:1271-1292`) — no mocking of the thing under test.

The three number-burn deny tests all send a non-empty `lines` array, so they would still fail if the route gate were removed — the `carriesALine()` guard cannot green them for the wrong reason.

---

## (c) N-9 — tenant provisioning + backfill migration

**Runs inside tenant DB context.** `seedUnitsOfMeasure()` (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:257-264`) is called from `seedReferenceData()` (:238), itself called from `initializeForNewRegistration()` (:81). Under db-per-tenant that entry point is `TenantProvisioningService.php:197`, which runs AFTER `tenancy()->initialize($tenant)` at `:123` and before `tenancy()->end()` at `:211` — so `DB::table('units')` / `DB::table('unit_categories')` resolve on the swapped tenant connection, never central. The shared-DB compat branch (`AuthController.php:355-364`, gated on `config('tenancy_resolver.db_per_tenant')`) runs on the one connection where `units` exists. No central-DB read/write either way.

**The backfill cannot run against central.** The migration is `apps/api/database/migrations/tenant/2026_08_26_100000_seed_base_units_for_unit_less_tenants.php`. `database/migrations/tenant` is fed ONLY to `tenants:migrate` (`apps/api/config/tenancy.php:195-199`, `'--path' => [database_path('migrations/tenant')]`) and is loaded into the default migrator exclusively in the testing environment (`apps/api/app/Providers/AppServiceProvider.php:233-240`, early-return unless `environment('testing')`). Belt-and-braces: even if it ever ran centrally, `Schema::hasTable('units')`/`'unit_categories'` (migration up() :1st guard) and `Schema::hasTable('companies') && DB::table('companies')->exists()` (2nd guard) return early — central holds none of those tables.

**Self-guarding / idempotent.** Both the provisioning helper and the migration demand BOTH tables empty before running `UomSeeder` (which writes with bare `create()` and owns the `unit_categories.base_unit_id → units.id` cycle). Ordering is correct on a fresh tenant: `MigrateDatabase` at `TenantProvisioningService.php:120` runs before `Company::create` at `:137`, so the migration's companies guard makes it a no-op and provisioning does the seeding — the two halves cannot double-seed. `ResetTenantCommand.php:109` loops `initializeForNewRegistration` once per company; the guard makes companies 2..n no-ops, which is correct because `units`/`unit_categories` are tenant-scoped, not company-scoped.

**Seeded rows are visible.** `UomSeeder` writes system rows with `tenant_id` NULL (by design — `database/migrations/tenant/2026_01_09_095018_create_unit_categories_table.php:28-30`), and the read path admits them: `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:44-45` and `:77-78` use `whereNull('tenant_id')->orWhere('tenant_id', $tenantId)`.

**Rule 12 / seeder sync:** N-9 adds no route and no permission; `RolesAndPermissionsSeeder.php` and every `routes.php` are untouched in `c94d23043..6a7adfa81` (verified by diffstat). No `can:` guard added anywhere in the batch, so there is no new silent-403 surface and no seeder re-sync obligation from this batch.

**Cross-check on (b), outside my items but in my lens:** `ProductService::resolveDefaultTaxConfigurationId()` (`apps/api/app/Modules/Product/Application/Services/ProductService.php:163-213`) scopes the `Company` lookup by `tenant_id` + `id` and the `Category` lookup by `company_id`; `categories.id` is a bigint (`database/migrations/tenant/2025_12_26_194624_create_categories_table.php:14`) and `$attributes['category_id']` is always an int from `CategoryResolutionDTO::$categoryId` (`ProductService.php:88-90`), so there is no PG uuid/type-cast 500 vector. No new `latestOfMany`/`ofMany`, no raw cross-tenant join, no row-level tenant-scoping assumption anywhere in the diff.

---

## Findings

1. **[Important] Role strip is not an offboarding path today — `pos_pin` survives it and the PIN roster still carries the user.** `apps/api/app/Modules/Identity/routes.php:80` → `RoleController::removeRole()` (`RoleController.php:390-424`) and the role-sync branch of `UserController::update()` (`UserController.php:381-389`) leave `pos_pin` set. `pinHolders()` (`PosAuthController.php:46-57`) keys on account status + membership, never on role, so a demoted operator keeps appearing in `pin-data` / `has-pins` and can still be selected as the acting operator via `verify-pin`. Not a privilege leak — roles/permissions are recomputed live at `PosAuthController.php:98-101` and the demoted user's approval scopes are empty — but the operator identity and terminal access persist. NOT introduced by this diff; C-13(i) as briefed covers deactivate/delete only. Fix: either extend the cascade to a role change that drops `pos.operate_terminal`, or rule that role strip is deliberately not offboarding and record it. Ledger it.

2. **[Important] The server-side fix is not end-to-end — C-13(ii) leaves an offline hole on the exact single-operator case.** `apps/pos/src/lib/sync/syncService.ts:1320-1322` prunes cached operators only when the `pin-data` response is NON-EMPTY. A shop whose only PIN-holder is offboarded now gets an EMPTY `pin-data`, so the device never prunes and keeps that operator's `pin_hash` + `approval_scopes` cached indefinitely — offline PIN verification and offline override approval keep succeeding for a fired employee. Explicitly declared out of scope by the implementer (report §(e)), which is legitimate, but C-13 must NOT be closed on the strength of (i) alone. Ledger C-13(ii) as a device-sync lane with this severity attached.

3. **[Important] The N-9 backfill's all-or-nothing guard skips the most likely affected tenant.** `2026_08_26_100000_seed_base_units_for_unit_less_tenants.php` runs only when BOTH `units` and `unit_categories` are empty. A tenant that hit N-9 and worked around it by hand-creating one unit through `POST /uom/units` (`UomController.php:125-131`, which stamps `tenant_id`) is silently excluded forever and stays without the 5 system categories / 19 base units. The trade-off is documented in the docblock, but the report's deploy note ("fleet-safe as-is") stops short of a post-migrate verification step. Before promotion, run the docblock census per tenant and hand-seed any tenant reporting `companies > 0` with a nonzero-but-tiny `units`/`categories` count.

4. **[Minor] The N-14 null contract is half-applied: the silent-failure arm still fabricates a draft id.** `apps/api/app/Modules/Document/Presentation/Controllers/DraftController.php:214-219` answers 200 with `'draft_id' => $draftId ?? Str::uuid()->toString()` on any non-`DraftNotEditableException` throwable. The FE change (`apps/web/src/hooks/useDraftAutoSave.ts:176-181`) only suppresses `onSuccess` for a literal `null`, so the fabricated uuid is still reported to the editor as a saved draft that does not exist. Pre-existing, and harmless in data terms (the next save with that id misses the lookup and authors a fresh document), but it contradicts the lane's own "no ghost draft id" premise. Fold into residual R-2/R-5.

5. **[Minor] Offboarding blast radius is tenant-wide, not company-scoped, and the new audit row is stamped with the actor's company.** `destroy()`/`deactivate()` resolve the target by `tenant_id` only (`UserController.php:445-447`) and `revokeMembershipsFor()` sweeps memberships in EVERY company (`UserController.php:990-998`); `clearPosPinFor()` now extends the same reach to the PIN, so a company-A admin can clear a company-B-only operator's PIN. No new privilege is granted (the memberships were already globally revoked in the same transaction), but the `user.pos_pin_cleared` audit row carries `companyId: $this->companyContext->requireCompanyId()` (`UserController.php:1033`) — the ACTOR's company, which mis-attributes the event for a cross-company target. Pre-existing pattern, same as the sibling `user.deactivated` row.

## What to fix before merge

Nothing blocking in the changed code — ledger findings 1, 2 and 3 (role-strip enumeration gap, C-13(ii) device residual, N-9 backfill census before promotion) and merge.
