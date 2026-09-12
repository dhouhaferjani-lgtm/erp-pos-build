# Codex spec gate r2 — roles & permissions catalogue design rev 2 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`83da1248c`**.

The round-1 register was read at `4f5d2dd46` (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r1.md:3-9`). `git diff 971528977..83da1248c -- apps/api apps/web apps/pos packages` is empty, so the application code remains byte-identical to the spec’s declared code base. Only documentation advanced.

`apps/api/vendor/autoload.php` is absent, so Artisan could not boot. Route figures were recomputed from the permitted CSV fallback.

## Rev-1 closure table

| Rev-1 finding | Closure |
|---|---|
| B-1 token narrowing misses authorization idioms | **CLOSED at rev-2 §4.1.3a** (`spec:263-335`). The role-name sites are inventoried, conversion precedes token issuance, subject/target resolution is separated, and the PIN-holder payload is correctly left unnarrowed. |
| B-2 sync is not a W-LOT-A-1a superset | **NOT CLOSED.** The retained delta requires arguments and mutates all supplied templates, but the new sync neither injects it nor defines the frozen migration input (`spec:730-745,772-777`; lane `LotActionPermissionDelta.php:27-29,56-90`). General-manager adoption metadata remains undefined. |
| B-3 general-manager behavior contradicts lane | **CLOSED at §2 and EC-15/15a** (`spec:102,1382-1383`). The definition, `central manager` synonym, Settings → Users surface, and unrestricted-membership invariant match the lane. |
| B-4 last-admin floor misses `UserController::update` | **CLOSED at §4.6.3** (`spec:1002-1022`). All removal paths are enumerated and centralized. |
| B-5 unsafe deploy entrypoint | **NOT CLOSED.** The fleet runner contract is improved, but the status file has no success-side clearing and its parent directory is never created (`spec:787-821`; `apps/api/docker/entrypoint.sh:20-23,74-78`). The health endpoint integration is also unnamed. |
| B-6 catalogue cannot compile/pass initial guards | **NOT CLOSED.** `Close` and the `settings` exception are repaired, but `expenses.pay` is financially decisive without `PermissionVerb::Pay`, and the five-entry SoD baseline omits a current credit-note violation (`spec:494-529,1135-1160`; seeder `:599,605`). |
| B-7 `authz.self` ratchet bypass | **CLOSED at §4.4.2** (`spec:895-918`). Exact method/URI equality and structural checks prevent the alias from certifying arbitrary routes. |
| B-8 D8 reopened through `security_events` | **CLOSED at §4.6.5** (`spec:1048-1103`). Denials stay on `audit_events`. |
| B-9 wave 0a overlaps W-LOT-A-1a | **CLOSED substantively at §8** (`spec:1412-1450`). BatchExpiry and glossary work moved to 0b. The revised file list still contains a Coupon-path typo, reported below. |
| M-1 principal reuse impact inventory | **NOT CLOSED.** The database invariants improved, but multiple user writers, notification selectors, human-seat counters, POS PIN writers, mass-assignment fields, and the service-membership creation path remain unspecified. |
| M-2 Sanctum/revocation semantics | **NOT CLOSED.** Central storage, TTL, and cross-database retry semantics are correct, but `EnforceTokenScope` is never attached, so the stated retry-window protection does not exist. |
| M-3 role/effective-permission read authorization | **CLOSED at §4.6.4** (`spec:1025-1044`). Self or `roles.view` is required for the full effective payload; `users.assign-roles` receives names only. |
| M-4 mutation events/dedup | **NOT CLOSED.** Role events are corrected, but attribution is directed into the wrong constructor argument and the two-key dedup read/delete is non-atomic (`spec:1084-1103`; `AuditService.php:65-82`; `AuditEvent.php:90-100,125-135`). |
| M-5 static guard misses unknowns/routes | **NOT CLOSED.** Unknown literals and route parsing were added, but the route guard rejects the concatenated enum form the spec itself prescribes (`spec:635-640,939-952`). |
| M-6 conventions 09/10/11 | **CLOSED except for factual defects independently reported below.** The per-wave axes and glossary surfaces are now explicit (`spec:83-104,1333-1356`). |
| M-7 edge-case register | **NOT CLOSED.** EC-9a describes a database-impossible NULL-team marked role; EC-16a relies on unattached middleware; EC-21 misses the shared-schema unique collision; EC-28 has a race; EC-30 is red on day one; EC-32 misses existing PIN writers (`spec:1376,1385,1391,1399-1403`). |
| M-8 cache flush before commit | **CLOSED at sync step 7** (`spec:779`). `DB::afterCommit` runs while the tenant team id remains selected. |
| M-9 contradictory deprecation set | **NOT CLOSED.** The count is now one list, but it lists pre-rename `catalog_cart.*` keys while claiming deprecation occurs under `catalog-cart.*`, and replacement grants conflict with the “custom roles never written” invariant (`spec:768,778,1204,1239-1247`). |
| Minor 1 Gate::before wording | **CLOSED** (`spec:60,257`). |
| Minor 2 seeder dependency census | **CLOSED** (`spec:831-833`). |
| Minor 3 tombstones called public | **CLOSED** (`spec:900-917`). |
| Minor 4 wave-4 ditto marks | **CLOSED** (`spec:1347-1354`). |
| Minor 5 generic labels accidental | **CLOSED** as an explicit choice (`spec:423-433`). |
| Rev-1 email-uniqueness citation rejection | **REJECTED-correctly.** The historical `:34` line really is the original unique constraint, while the effective partial index is correctly cited separately (`create_users_table.php:33-35`; `make_user_email_nullable.php:18-23`). |
| Rev-1 citation audit overall | **NOT CLOSED.** New stale and wrong citations appear in rev 2; see the citation audit. |

## BLOCKER

### B-1 — `EnforceTokenScope` is ordered but never attached

Adding middleware to Laravel’s priority list controls ordering only for middleware already present on a request; it does not attach that middleware.

The spec adds only:

- a priority-list insertion (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:337-357`);
- service-account routes using `['api', 'auth:sanctum', SetPermissionsTeam, EnforceTokenTenantClaim]`, expressly omitting `EnforceTokenScope` (`:233`);
- a wave deliverable naming the class without changing the API groups (`:1477`).

The current global API group contains neither `SetPermissionsTeam` nor either token-enforcement middleware (`apps/api/bootstrap/app.php:133-153`). Protected routes attach them explicitly (`apps/api/app/Modules/Identity/routes.php:39-57`; `apps/api/routes/api.php:50-54`). Priority ordering is separately configured at `apps/api/bootstrap/app.php:174-188`.

Therefore these promised checks do not run:

- ambiguous `*` plus `permission:` rejection;
- inactive service-principal rejection;
- active-company-membership enforcement;
- EC-16a’s claim that a still-live token is refused after central revocation fails (`spec:1385`).

`User::hasPermissionTo()` still narrows permission checks, but that does not enforce status or membership and does not protect ungated/self-service endpoints such as `/auth/me`.

### B-2 — Template migration #1 is not executable without violating D2

`PermissionSyncService` does not inject `LotActionPermissionDelta` (`spec:726-745`) but later says it calls it (`:772-774`). Constructor-only injection is itself a stated rule (`:749`), so the declared service cannot implement its own step 5.

More importantly, the incoming delta is not a parameterless “create general manager” operation. It requires both the complete permission list and complete role-grant map (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:23-38`). It then:

- resolves every role named in that map (`:54-57`);
- creates every missing permission (`:65-72`);
- creates missing non-general-manager roles (`:74-85`);
- grants every supplied role its missing permissions and revokes manager recall (`:86-90,163-181`);
- verifies the entire supplied canonical state (`:188-205`).

Passing the current registry lists would let the retained delta grant current template additions to customised system roles, bypassing the `customised_at` skip in sync step 5 (`spec:775-778`). Passing a frozen W-LOT snapshot is plausible, but the spec never declares those exact arguments.

The claimed precondition is also not callable as described: `markedTenantCarriesWlota1aDelta()` is private to the incoming seeder (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:64-69`), while the spec says `PermissionSyncService` calls it (`spec:661`).

Finally, adoption runs before role creation (`spec:764-773`). A delta-created `general_manager` gets `name`, guard, tenant and `provisioning_source`, but none of `is_system`, `template_key`, `template_version` or `customised_at` (`lane/.../LotActionPermissionDelta.php:139-159`). Step 5 only says to proceed to grants and set `template_version`; it never performs the missing adoption metadata write (`spec:773-777`).

This does not yet implement the settled “W-LOT delta is template migration #1 and is invoked” ruling.

### B-3 — The legacy baseline cannot classify W-LOT’s `general_manager`

The adoption baseline is explicitly frozen from `RolesAndPermissionsSeeder` at `971528977` (`spec:678-686`). At that SHA the seeder has seven roles; `general_manager` is introduced only by the incoming lane (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:80-96`).

Nevertheless, adoption compares every `SystemRoleTemplate` case, including `general_manager`, against that snapshot (`spec:678-683,1316`). No version-0 grant set exists for that role. A marked tenant therefore cannot be deterministically classified as pristine or customised.

EC-9a is additionally impossible: it asks for a NULL-team role that already carries the W-LOT marker (`spec:1376`), but the marker CHECK requires `tenant_id IS NOT NULL` (`lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-48`), and the delta rejects a global marked role (`lane/.../LotActionPermissionDelta.php:61-63`).

The settled equality-based adoption rule can remain, but it needs a defined W-LOT version-0 snapshot for `general_manager`, and EC-9a must split “customised NULL-team unmarked role” from “customised marked tenant role.”

### B-4 — Wave 0b cannot provision its new keys on marked tenants

Wave 0b adds permission names to the seeder and deploys with `tenants:seed RolesAndPermissionsSeeder` (`spec:1452-1460`).

After W-LOT-A-1a:

- enforcement defaults to false (`lane/w-lot-a-1a:apps/api/config/lot_action_permissions.php:5`);
- when false, a marked tenant is left completely untouched (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:31-51`).

Consequently, marked tenants do not receive `credit-notes.cancel`, `services.*`, `channels.*`, or the other wave-0b additions, while their routes are gated on those names. Spatie will fail closed with 403. This directly conflicts with the wave’s stated deploy result and the lane’s required non-destructive marker behavior.

Deleting `PermissionSeeder` after declaring `credit-notes.cancel` is correctly ordered, and its sole caller is genuinely `ProductionSeeder.php:75` (`apps/api/database/seeders/ProductionSeeder.php:68-76`). The blocker is reliance on a seeder branch that deliberately performs no writes.

### B-5 — The SoD guard is red by construction

Two independent contradictions remain.

First, `expenses.pay` is explicitly a financially decisive permission (`spec:1144-1150,1160`), but `PermissionVerb` contains no `Pay` case and `isFinanciallyDecisive()` lists no `pay` (`spec:494-529,1135`). `PermissionDefinition::legacy()` still constructs an object whose non-null `$verb` property drives classification (`spec:441-455,468-484`), so the spec provides no truthful way to classify this key.

Second, the five-entry baseline is not today’s manager grant set. The spec declares `credit-note` as an initial SoD group (`spec:1160`), and manager already holds both `credit-notes.create` and `credit-notes.post` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:599`). That violation is absent from the five rows at `spec:1144-1150`, while EC-30 explicitly says adding a sixth baseline entry must fail (`:1401`).

There are further ungrouped create-plus-decisive combinations—orders, deliveries, income, and work orders—at seeder `:593,604,608,626-628`; those require a declared grouping decision, but `credit-note` alone proves the current test cannot pass without changing the settled policy.

### B-6 — The route literal guard rejects the required enum syntax

The migration contract says route middleware becomes concatenated enum expressions such as:

```php
'can:'.InventoryPermission::TransfersReconcile->value
```

and uses the same construction in the accepted stacked-AND example (`spec:635-640`).

The proposed `RoutePermissionLiteralTest`, however, says concatenated or variable-built keys are “unresolvable” and reported rather than accepted (`spec:950`). Thus every route migrated to the prescribed enum form fails the companion architecture test.

The PHPStan rule itself is implementable at level 8 and has a valid repository precedent (`apps/api/phpstan.neon:5-8,33-47`; `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40`). The blocker is the contradictory route-test contract, not PHPStan capability.

### B-7 — `roles_template_key_unique` breaks compatibility mode

The new index is globally unique on `template_key` alone (`spec:1309-1318`). In database-per-tenant mode that is appropriate.

The design also explicitly supports single-schema compatibility mode, where roles for different tenants share one physical table and adoption runs globally (`spec:692,829,1391`). Existing Spatie schema permits one role name per `(tenant_id, name, guard_name)` (`apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:33-47`). Two tenant-scoped manager roles are therefore legal today, but adopting both with `template_key='manager'` violates the new global unique index.

EC-21’s one-database SQLite test does not exercise a second tenant and would miss this collision (`spec:1391`). D1 remains settled; the compatibility index/test must express tenant-scoped templates without allowing duplicate NULL-team legacy matches.

### B-8 — Boot-failure visibility is not durable as written

The failure branch writes `/var/run/autoerp/permissions-sync.status`, but the entrypoint creates only `/var/run`, not `/var/run/autoerp` (`spec:787-795`; `apps/api/docker/entrypoint.sh:20-23,74-78`). The write can therefore fail.

The success branch prints a message but does not clear or overwrite the previous failure marker, despite the claim that a successful run clears it (`spec:789-821`). A recovered deployment can remain permanently unhealthy.

The referenced `/health` surface is also ambiguous:

- public `/v1/health` returns only `status` and `timestamp` (`apps/api/routes/api.php:25-27`; `apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php:23-35`);
- detailed health is the authenticated `/v1/admin/monitoring/health` (`apps/api/routes/api.php:81-87`) and currently checks database, Redis, queue, and storage (`apps/api/app/Modules/Admin/Application/Services/HealthCheckService.php:17-32`).

The spec names neither endpoint nor the reader for the status file. The settled “boot continues with structured marker plus `/health permissions_sync`” ruling is not yet implementable.

## MAJOR

### M-1 — Human-seat exclusion is only partially applied

The settled ruling changes only `PlanLimitsService::getUsage()` in the spec (`spec:400-406`), but human-seat enforcement and billing use four other all-row counts:

- admission: `PlanEnforcementService::canAddUser()` (`apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:157-176`);
- usage stats: `:279-305`;
- overage billing: `:421-443`;
- support/admin display: `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:87-104`;
- fleet totals: `apps/api/app/Services/TenantFleetStatsService.php:36-50`.

If unchanged, service principals still consume the capacity checked before creating a human and are billed as extra human users. The spec’s citation to `PlanEnforcementService.php:325-330` identifies only percentage shaping, not enforcement.

The new `max_service_accounts` key also needs to join the canonical limit enum/constants and every plan default, whose current contract begins at `apps/api/app/Modules/Billing/Domain/PlanLimits.php:15-35,155-171`; saying only “lands in the plan seed” is incomplete.

### M-2 — Service-account creation has no complete membership/write contract

A service token is unusable without an active company membership (`spec:348-359`), but `POST /service-accounts` has no declared membership input or membership writer (`spec:224-233`). The current human creation path creates the membership and applies `allowed_location_ids` inside its transaction (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-257`).

The service PATCH surface is limited to name, description, and active status (`spec:228`), so it cannot establish or change membership/location access. Reusing generic `UserController::update` would create a second service-account writer, contrary to the declared primary `ServiceAccountController` surface (`spec:85,104`), and exposes fields not valid for services: email, phone, locale, discount controls, role, and location access (`apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:44-62`; `UserController.php:368-399`).

The existing location path also requires `users.manage_location_access` (`CreateUserRequest.php:28,69`; `UpdateUserRequest.php:28,77`). The spec does not decide how that gate relates to `service-accounts.create/update`.

### M-3 — The principal writer and notification census is still incomplete

Writers omitted from the purported exhaustive census at `spec:214` include:

- `EmailVerificationService::verifyEmail()` (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:68-71`);
- central `SuperAdminController::verifyUserEmail()` (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:504-527`);
- `UserController::setPosPin()`’s clear and set arms (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:703-739`);
- `PosAuthController::setupPin()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:120-146`).

EC-32 mentions the raw sync writer at `PosAuthController.php:295-307`, but not `setupPin`; the database CHECK would turn a missed application guard into a database exception rather than the promised 422 (`spec:1403`).

The blanket “every notification writer short-circuits” rule is not inventoried either. Permission-selected recipients can now include service accounts, for example:

- verification mail: `EmailVerificationService.php:27-40`;
- enrichment notifications: `SendEnrichmentNotificationListener.php:18-36`;
- batch notifications: `BatchExpiryDailyCheckCommand.php:215-225`.

Finally, `User::$fillable` contains none of `principal_kind`, `principal_description`, or `created_by_user_id` (`apps/api/app/Modules/Identity/Domain/User.php:81-97`). The spec adds only a cast and predicates (`spec:202-208`), leaving the service creator’s persistence mechanism undefined.

### M-4 — Audit attribution is directed into the wrong data channel

The contract says `principal_id` and `token_id` belong in `audit_events.metadata` (`spec:361-363`). Later it instructs the implementation to add them to “the same `array_filter`” that holds impersonation values (`spec:1088`).

That existing array is passed as the `attributes` constructor argument, not `metadata` (`apps/api/app/Modules/Compliance/Services/AuditService.php:65-82`). `AuditEvent` JSON-encodes only the separate `$metadata` argument into the `metadata` column (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:90-100,125-135`). Adding arbitrary `principal_id` and `token_id` attributes would not satisfy the declared schema contract.

`AuthorizationDenied` also lacks the explicit immutable `getEventName()` and V2 evolution rule given to role mutations (`spec:1081-1092`), even though rule 8 applies equally to it.

### M-5 — Denial dedup can lose counts under concurrency

The two-key design fixes expiry retention, but “read and DEL” is not atomic (`spec:1096-1103`). After the next emitter reads the counter but before it deletes it, another denied request can fail the emission lock and increment the same counter. The subsequent `DEL` loses that suppression permanently.

The Redis integration test promises the count is “cleared exactly once” but does not specify a Lua/transactional get-and-delete or concurrency probe (`spec:1103,1399`). This is material because D8’s dedup is explicitly load-bearing.

### M-6 — Deprecation naming and custom-role writes remain contradictory

The rename map converts `catalog_cart.*` to `catalog-cart.*` (`spec:1211-1218`), but:

- the manifest ownership table still names `catalog_cart.*` (`:1204`);
- the authoritative deprecation list contains old `catalog_cart.convert_so` and `.manage_all` (`:1239-1241`);
- the following paragraph says they are deprecated under their new `catalog-cart.*` names (`:1243`).

A manifest cannot simultaneously define both names without defeating D5 and the orphan/rename model.

Separately, deprecation step 3 grants `replacedBy` to every role holding the old key (`spec:768`), which includes custom roles, while step 6 says custom roles are never written (`:778`). The design must choose a single mechanically testable behavior without changing D2.

The `reports.view` paragraph also says it is “removed rather than deprecated-and-kept” and immediately specifies `deprecated: true` until wave-3 pruning (`spec:1249`).

### M-7 — Wave-2 rollback cannot restore the prior users schema

Wave 2 changes three new columns plus the nullability of `password` (`spec:1279-1303`). Its rollback text says only that the “three users columns” are dropped after service tokens are revoked (`spec:1481`).

That leaves service-account rows with null passwords—and usually null emails—in a schema that no longer identifies them as services. Restoring `password NOT NULL` would fail while those rows remain; not restoring it leaves a materially different schema from the pre-wave state. Token revocation alone does not solve either condition.

### M-8 — The scaffold command’s write contract is internally inconsistent and nondeterministic

The command “writes exactly three artifact classes and nothing else” (`spec:145-161`), but its table describes:

- one PHP enum file;
- English JSON;
- French JSON;
- Arabic JSON;
- one French todo JSON;
- one Arabic todo JSON.

That is six files, not three artifacts and nothing else. The PHP path also omits the repository’s `apps/api/` prefix while the locale paths are root-relative (`spec:157-161`).

No insertion contract defines key ordering, indentation/newline preservation, duplicate detection, or atomic writes in the large `common.json` files. A second-run no-op test (`spec:163`) does not prove deterministic first-run output across clean worktrees. That omission threatens the promised byte-identity generation workflow at `spec:964-974`.

### M-9 — Edge-case register disposition

| Rows | Assessment |
|---|---|
| EC-1–EC-4b | Decided and PG-realistic. The nine removal paths are centralized at `spec:1006-1022`. |
| EC-5 | Depends on resolving the custom-role replacement contradiction at `spec:768,778`. |
| EC-6–EC-8 | Decided and realistic; in-place rename and after-commit cache semantics are sound (`spec:1372-1374`). |
| EC-9 | Realistic only after B-2 is fixed. |
| EC-9a | Impossible under the W-LOT CHECK; see B-3 (`spec:1376`; lane migration `:34-48`). |
| EC-10–EC-15a | Decided and realistic. The single-role method now exists in the declared interface (`spec:740-745`), and general-manager behavior matches the lane. |
| EC-16/16a | Feasible cross-database behavior, but the “next request refused” assertion fails until `EnforceTokenScope` is actually attached (`spec:1384-1385`). |
| EC-17–EC-20 | Realistic. Role idiom conversion is correctly an issuance precondition; concurrency tests are correctly PG-only (`spec:1386-1390`). |
| EC-21 | Testable on SQLite, but does not cover the second-tenant unique collision in B-7 (`spec:1391`). |
| EC-21a | Fleet verdict/exit-code test is realistic; health-marker assertions are not implementable until B-8 is resolved (`spec:1392`). |
| EC-22 | Correctly withdrawn (`spec:1393`). |
| EC-23–EC-27 | Realistic, except the route-literal test must accept the enum expression prescribed by the spec (`spec:1394-1398`). |
| EC-28 | Requires an atomic get-and-clear concurrency case, not merely TTL tests (`spec:1399`). |
| EC-29 | Decided and realistic (`spec:1400`; current filter `RoleController.php:326-335`). |
| EC-30 | Red on day one due to omitted `credit-notes.create + credit-notes.post` and missing `Pay` semantics (`spec:1401`; seeder `:599,605`). |
| EC-31 | Correctly records the accepted offline residual (`spec:1402`). |
| EC-32 | Incomplete: `setupPin`, generic user mutation, verification writers, and database-error shaping remain uncovered (`spec:1403`). |

Missing scenarios are:

1. wave-0b seeding against a marker-carrying tenant with `LOT_ACTION_PERMISSIONS_ENFORCE=false`;
2. compatibility-mode adoption of the same template for two tenant-scoped roles;
3. a successful boot after a failed boot clearing `/health` state;
4. concurrent denial increments during the emitter’s counter retrieval;
5. creating a service account with its first membership and location restriction.

These are engineering acceptance cases, not new owner decisions.

## MINOR

1. The raw CSV contains 642 `MIDDLEWARE` and 68 `SUPERADMIN_ONLY` rows, not 638 and 68 (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv:1`; `spec:916`). The spec’s 638 figure is defensible only after separately reclassifying four middleware-bearing tombstones; that intermediate transformation should be stated.

2. Wave 0a closes sixteen listed writes—Promotion 4, Uom 5, Menu 3, Coupon 3, counting 1—not fourteen (`spec:926,1439,1448`). The stated projected ceiling of roughly 151 also implies 167 − 16, contradicting `spec:917`.

3. The verified 0a file list repeats `Promotion/Presentation/routes.php` for Coupons (`spec:1427`). The real file is `apps/api/app/Modules/Coupon/Presentation/routes.php:10-23`.

4. `lane/t1-transfers-edge` is no longer empty as claimed at `spec:1431,1446`; it contains `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`. That fixture does not overlap wave 0a, so the no-overlap conclusion survives. `lane/t2-receipt-spine` remains empty.

5. Wave 0b cites its incoming marked-tenant branch as `:568` (`spec:1454`); the real branch is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`.

6. Section 5 says migrations run under `tenant:migrate-rolling` (`spec:1273`). The actual command is plural: `tenants:migrate-rolling` at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48`.

7. Section 5.2 says sync never writes `name` for any role (`spec:1321`), while step 5 explicitly creates every missing non-general-manager role (`spec:773`). Creation necessarily writes `name`, guard, tenant and id.

8. The roles CHECKs allow `template_version` to be non-null while `template_key` is null and `is_system=false` (`spec:1313,1318`). That state contradicts the column’s stated meaning at `:1319`, even though normal sync intends not to create it.

## Citation audit

All cited application paths were checked against `83da1248c`; application code is unchanged from `971528977`. Wrong or stale claims found are listed below.

| Claim | Result and real line |
|---|---|
| Rev-2 application code remains identical from `971528977` | **Verified.** `git diff 971528977..83da1248c -- apps/api apps/web apps/pos packages` is empty; current HEAD is `83da1248c`. |
| Manager expense combination at seeder `:606` | **Wrong.** `expenses.create/post/pay` are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:605`. |
| Manager stock-adjustment combination at seeder `:602` | **Wrong.** `inventory.adjustments.create/post` are at `RolesAndPermissionsSeeder.php:603`; `:602` is transfers. |
| Five manager SoD combinations cover today’s declared groups | **Wrong.** `credit-notes.create/post` is at `RolesAndPermissionsSeeder.php:599` and `credit-note` is declared as an initial group at `spec:1160`. |
| Human seats are “enforced at `PlanEnforcementService.php:325-330`” | **Wrong.** Those lines shape usage output. Admission is `PlanEnforcementService.php:162-176`; counting also occurs at `:279-305,426-443`. |
| W-LOT marker branch at incoming seeder `:568` | **Wrong.** Real branch: `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`. |
| `markedTenantCarriesWlota1aDelta()` exists and protects marked tenants | **Verified**, but it is private at lane seeder `:64-69`, so another service cannot call it as claimed. |
| W-LOT delta creation at `:139-155` | **Verified.** Full mutation breadth is additionally at lane delta `:56-90,163-181`. |
| NULL-team lookup predicate at lane delta `:104-128` | **Verified.** It preserves NULL-team roles and rejects duplicate matches. |
| EC-9a can seed a NULL-team marked role | **Wrong.** Marker CHECK requires non-null tenant at lane migration `:34-48`; delta rejects a global marker at lane delta `:61-63`. |
| Coupon routes are `Promotion/Presentation/routes.php` | **Wrong.** Real file: `apps/api/app/Modules/Coupon/Presentation/routes.php:10-23`. |
| Both T1 and T2 diffs are empty | **Stale.** T1 now contains `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`; T2 is empty. |
| Wave 0a closes fourteen writes | **Wrong.** Its own item counts total sixteen (`spec:1439,1448`). |
| `tenant:migrate-rolling` command | **Wrong.** `tenants:migrate-rolling` at `RollingTenantMigrationCommand.php:48`. |
| Existing role/permission `Permission::all()` behavior | **Verified.** `RolesAndPermissionsSeeder.php:566-568`; `RoleController::permissions()` also reads all rows at `RoleController.php:315-324`. |
| `RoleController::userRoles()` exposes target permissions | **Verified.** `RoleController.php:429-437`. |
| User permission overrides | **Verified.** `User.php:185-212`; token extraction is separately at `:216-241`. |
| Spatie Gate callback enabled | **Verified.** `apps/api/config/permission.php:103-107`; locked version is 6.25.0 at `apps/api/composer.lock:7747-7757`. |
| Eight backend `hasRole` sites | **Verified.** Request `:35`, Inventory controller `:782,866,905,975,1297,1494`, discount resolver `:39`. |
| Spatie `role:` middleware exists | **Verified absent.** Only separate central `central_admin_role` route middleware is present, e.g. `SupportAccess/routes.php:21-45`. |
| PAT abilities are TEXT with `expires_at` | **Verified.** `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`. |
| PAT table is central | **Verified.** `CentralPersonalAccessToken.php:11-41`; registration at `AppServiceProvider.php:196-219`. |
| Global TTL is 43,200 minutes and row expiry overrides | **Verified.** `apps/api/config/sanctum.php:43-53`; `AppServiceProvider.php:201-219`. |
| ResolveTenancy reads central token tenant claim | **Verified.** `ResolveTenancy.php:116-134`. |
| Email effective uniqueness is partial for non-null values | **Verified.** `make_user_email_nullable.php:14-23`; historical original unique remains correctly cited at `create_users_table.php:33-35`. |
| `PermissionSeeder` has one production caller | **Verified.** `apps/api/database/seeders/ProductionSeeder.php:68-76`. |
| PHPStan precedent and level 8 exist | **Verified.** `apps/api/phpstan.neon:5-8,33-47`; rule class `ForbidFloatCastOnDecimalProperty.php:40`. |
| Locale roots and generated union exist | **Verified.** en `common.json:1289`, fr `:1306`, ar `:1272`; generated union `apps/web/src/hooks/permissionsMap.generated.ts:312`. |
| Public health exposes permission-sync state | **Wrong as current-code evidence.** Public response has only status/timestamp at `MonitoringController.php:28-35`; detailed checks are `HealthCheckService.php:17-32`. |

## Rejected false positives

### Token-narrowing call paths

| Idiom | Gate result |
|---|---|
| `can:` route middleware | **Narrowed.** Laravel authorization reaches the enabled Spatie Gate callback (`config/permission.php:103-107`), which dynamically reaches `User::hasPermissionTo()` (`User.php:185-198`). |
| `$user->can()` / `cant()` | **Narrowed** through the same Gate path. |
| `Gate::authorize()` / `allows()` / `denies()` | **Narrowed for dotted Spatie permissions.** Bare policy abilities continue to policies registered at `AppServiceProvider.php:270-276`. |
| `hasPermissionTo()` | **Narrowed directly** by `User.php:185-198`. |
| `hasAnyPermission()` | **Narrowed** because Spatie checks each candidate through `checkPermissionTo()`. |
| `getAllPermissions()` | **Narrowed only for the authenticated model carrying `currentAccessToken()`.** `User.php:201-241`. `AuthUserData` therefore narrows `/auth/me` (`AuthUserData.php:34-58`), while a separately loaded target remains unnarrowed. |
| POS PIN `getAllPermissions()` | **Intentionally unnarrowed.** The PIN holder is loaded independently at `PosAuthController.php:87-100,206-225`; the terminal token must not alter the human approver’s authority. |
| `hasRole()` family | **Bypasses narrowing.** Rev 2 correctly requires conversion before scoped-token issuance (`spec:278-303`). |
| Spatie `role:` middleware | **Absent.** No bypass exists through that middleware today. |

The settled intersection claim is sound after backend role-name conversions. The new blocker is middleware attachment for service status/membership invariants, not the Gate intersection.

### Ratchet recomputation

From the CSV’s 1,054 rows:

- 642 `MIDDLEWARE`
- 68 `SUPERADMIN_ONLY`
- 18 `PUBLIC`
- 130 `CONTROLLER`
- 46 `FORMREQUEST`
- 8 `POLICY`
- 142 `AUTH_ONLY`

Thus uncovered = `130 + 46 + 8 + 142 = 326`, split mechanically by method into **177 writes / 149 reads**. Reclassifying four write tombstones, six self-service writes and `/auth/me` produces **167 / 148** exactly (`spec:900-917`).

The test is mechanically implementable by booting the live router and inspecting resolved middleware. A developer cannot whitelist a route merely by attaching `authz.self`; the exact method/URI allow-list and no-foreign-parameter check catch that (`spec:895-918`). Editing the allow-list remains an explicit reviewed code change, not an accidental bypass.

### Other preserved facts

- The NULL-team lookup predicate is sound; no re-homing is required (`lane/.../LotActionPermissionDelta.php:104-128`).
- The users CHECK is valid PostgreSQL SQL, both migrations are tenant-scoped and additive, and no enum-typed column is altered (`spec:1271-1329`).
- `admin = PermissionRegistry::activeKeys()` is the correct catalogue boundary after the frozen W-LOT migration has run (`spec:774`).
- Enum/manifest parity, locale coverage, and generated TypeScript union tests are feasible and compatible with generated-type rule 7 (`spec:958-974`).
- The last-admin inventory now covers role permission removal, role deletion, explicit removal, user update/destroy/deactivate, membership removal, sync, and impersonation (`spec:1006-1022`).
- Effective-permission authorization is correctly self-or-`roles.view`, with `users.assign-roles` limited to names (`spec:1025-1044`).
- Convention 09 now names second-company, second-location, and rerun evidence per wave (`spec:1347-1356`). The roles/permissions/users catalogue exclusions already exist at `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:131-132,160,175`.
- The convention-10 matrix has decisions on every row and direct code citations. G8’s incomplete manager census is a factual defect, not a missing decision column (`spec:53-70`).
- The vocabulary table uses one physical `users` table for both principal kinds, preserves the incoming General-manager wording and surface, and avoids `UserService` as a phantom component (`spec:83-104`; lane `docs/glossary.md:21,91`).
- The corrected 0a source set is disjoint from the 66-file W-LOT diff once the Coupon path typo is corrected. T1’s new fixture also does not overlap; T2 remains empty.
- ProductionSeeder ordering is safe only with respect to deleting `PermissionSeeder`; the marked-tenant provisioning issue is separate.

## Preserve

The fix round must not reopen or weaken:

- Direction B: catalogue-as-code with deploy-time sync.
- D1 tenant-scoped roles and NULL-team legacy preservation without re-homing.
- D2 template deltas: untouched system roles advance; customised roles are skipped; custom roles receive no automatic template grant.
- D3 shrink-only route ceilings, writes first, with **326 / 177 / 149 → 167 / 148** preserved.
- D4 `roles.view` for permission-bearing reads.
- D5 kebab-resource naming, closed verb semantics, and an explicit rename map.
- D6 no general per-user overrides.
- D7 wave 0a’s actual no-overlap subset; 0b onward waits for W-LOT-A-1a.
- D8 immutable, versioned role-mutation and denial events on `audit_events`, not a parallel security table.
- Service/API/MCP effective permissions as owner grants intersected with the selected token scope, never unioned.
- The `forSubject` versus `forTarget` distinction and explicit target-token selector.
- The intentional unnarrowed POS PIN-holder payload and exclusion of service principals from PIN populations.
- The W-LOT marker, exact general-manager grant set, sole role-definition writer, manager recall revocation, and unrestricted-membership invariant.
- The equality-based legacy-adoption ruling and same-wave `customised_at` writer.
- The single `LastAdminFloor` service with deterministic user-id lock ordering.
- The fleet runner’s per-tenant outcomes, continued boot, visible unhealthy state, and no `--force`.
- `admin = activeKeys()`, central Sanctum token storage, per-row expiry override, and no central schema migration.
- The generated TS union and en/fr/ar structural label coverage.

## Owner decisions required

Only the four permitted questions remain:

1. **OQ-1 — May a service admin satisfy the last-admin floor?** Genuinely open. Rev 2 currently implements “no” throughout F-2 and EC-4 (`spec:1002-1022,1368`). A contrary ruling requires the bounded edits the spec already identifies.

2. **OQ-2 — Default service-token TTL and any human-token policy change?** Genuinely open. Current code is 30 days globally with explicit per-row override and one-year POS tokens (`config/sanctum.php:43-53`; `AppServiceProvider.php:201-219`). No existing token should change retroactively.

3. **OQ-3 — Which existing manager SoD combinations should be retired?** Genuinely an owner policy decision, but the decision table must first be factually corrected to include at least the already-declared `credit-notes.create + credit-notes.post` combination (`RolesAndPermissionsSeeder.php:599`; `spec:1160`). The gate is not reopening whether the baseline is shrink-only.

4. **OQ-4 — Should `SYNC_PERMISSIONS_ON_BOOT` default true in production after soak?** Genuinely open, but not safely answerable until B-2, B-4 and B-8 make the sync input, marked-tenant behavior, and health marker reliable.

No additional owner question is required. The remaining findings are engineering-contract defects within settled rulings.

VERDICT: CHANGES-REQUIRED