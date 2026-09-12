# Codex plan gate r3 — RBAC wave 1 plan rev 3 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD` → **`ccc9dcf2a`**

The reviewed Wave-1 plan is clean at the worktree and is the blob committed by `1e99a4415` (SHA-256 `9caad5d094e105ba9d7a3148cb62538e811fd6577d13a47501cda764383f5381`). The only working-tree modification is the concurrent Wave-0b repair.

## Rev-1 closure table

| Rev-1 finding | Rev-3 disposition |
|---|---|
| B1-1 — catalogue arithmetic | **CLOSED at rev-3 anchor.** The derivation is now 304 dev + 2 W-LOT + 19 Wave-0b + 2 T2 = 327: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:41-43,83-85`. |
| B1-2 — incomplete manifests/red incremental migration | **CLOSED at rev-3 anchor.** There are 327 assignment rows, 38 complete manifests, and the nine shrink-only ceilings reconcile 327→286→265→236→213→188→153→86→29→0: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1236-1256,3305-3530`. |
| B1-3 — unconditional `template_version` write | **CLOSED at rev-3 anchor.** The write is guarded by `$versionChanged`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5351-5375`. |
| B1-4 — stopgap/replacement not atomic | **CLOSED at rev-3 anchor.** Phase 1.10 lands sync, commands, and `SyncRollback` while removing all eight stopgap artifacts: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6968-7108`. |
| B1-5 — dry run before migration | **CLOSED at rev-3 anchor.** YAML, deploy table, and entrypoint all order migrate → dry run → apply: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7285-7323,9582-9589,10262-10280`. |
| B1-6 — undefined audit actor | **CLOSED at rev-3 anchor.** Tenant-wide audit methods carry the actor explicitly: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5507-5604`. |
| B1-6a — nullable company `TypeError` | **CLOSED at rev-3 anchor.** The guard and `companyId ?? ''` assignment are both specified: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5534-5547`. |
| B1-7 — implementation completeness | **NOT CLOSED.** The ten previously absent implementation bodies now exist, but critical named-only tests contain contradictions and cannot be treated as assertion-fill; see M3-1: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7012-7042,10251,10403-10407`. |
| M1-1 — commit subjects | **CLOSED at rev-3 anchor.** The nine 1.4 subjects and later `Phase 1.<task>:` subjects are explicit: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3492-3503`. |
| M1-2 — exact staging | **CLOSED at rev-3 anchor.** The nine manifest/provider staging blocks and 38 enum paths are written out: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3328-3490,3561-3608`. |
| M1-3 — inherited-lock proof | **CLOSED in the Wave-1 design, conditional on the repaired producer.** The list-shaped row has both callers: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:89-100,7594-7603`. Wave 0b’s pending scanner repair remains a Wave-1 entry condition. |
| M1-4 — scaffold determinism | **CLOSED at rev-3 anchor.** The command implementation and deterministic rewrite rules are supplied: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7972-8310`. |
| M1-5 — presence-only deploy assertion | **CLOSED narrowly.** Exact command order and Wave-1 table equality are implemented: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9816-9834,9854-9877`. The broader spec/all-plans claim remains false; see MINOR. |
| M1-6 — incomplete commands/harnesses | **NOT CLOSED operationally.** PG/PHPStan/Pint commands and paths are now explicit, but Phase 1.16 remains red because generated baseline paths cannot match runtime rule paths; see B3-4: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8628-8677,9131-9136`. |
| Minor — lingering old catalogue counts | **CLOSED at rev-3 anchor.** Catalogue and commit arithmetic consistently say 327: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:83-85,1236-1252`. |
| Minor — PHPStan route-file wording | **CLOSED at rev-3 anchor.** The plan correctly distinguishes `app/Modules` from top-level `routes/`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8683-8687`. |
| Minor — YAML whitespace | **CLOSED at rev-3 anchor.** Normalisation is bounded to whitespace: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9954`. |
| Citation — ref register | **NOT CLOSED.** The plan records worktree HEAD `9282d7b2d`; current HEAD is `ccc9dcf2a`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:24-33`. Dev and lane tips remain correct. |
| Cross-plan — fleet selector provider | **CLOSED for fixture shape, but the runtime consumer is incompatible.** See B3-1: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6922-6934`. |
| Cross-plan — dry-run sentinel | **CLOSED at rev-3 anchor.** Every dry run and non-green result exits the transaction through `SyncRollback`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6144-6179`. |
| Cross-plan — stopgap same-commit deletion | **CLOSED at rev-3 anchor.** `git rm` and replacement staging are in Phase 1.10: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6968-7108`. |
| Cross-plan — `PermissionWriterCensus`/`markerLine()` | **CLOSED conditionally.** Wave 1 consumes the list-shaped API and renders one marker shape: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:89-100,6023-6054`. Re-run after the Wave-0b scanner repair. |
| r9 Preserve list | **NOT CLOSED.** Catalogue, migrations, lock shape, audit, templates, and null-team behavior are preserved, but fleet execution, canonical plan equivalence, tenant restoration, and after-commit tenant cache context are not: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:194-209`. |

The rev-1→rev-2 change log is therefore verified for the catalogue, migration, manifests/enums, audit signatures, staging lists, subjects, stopgap cutover, scaffold, and deploy ordering. Its implementation-completeness and Preserve claims remain overstated.

## Rev-2 closure table

| Rev-2 finding | Rev-3 disposition |
|---|---|
| B2-1 — dry run under-reports and non-green apply commits | **NOT CLOSED.** `SyncRollback` fixes the commit-on-failure half, but the virtual role grants are not canonicalised after planned renames, so dry-run and apply diverge on ordinary legacy tenants: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6222-6227,6250-6257,6344-6348,6461-6467,6511-6525,6661-6679`. |
| B2-2 — `--tenant=` does not initialize tenancy | **NOT CLOSED.** It now initializes B, but destroys an existing A context instead of restoring it: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6843-6873`; Stancl behavior is `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Tenancy.php:32-58`. |
| B2-3 — provisioning continues after failed sync | **CLOSED at rev-3 anchor.** The marker is written before `TenantPermissionSyncFailed`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7180-7218,7238-7249`. |
| B2-4 — Phase 1.16 fixture arrives later | **CLOSED for fixture ordering.** Step 0 now generates the fixture in Phase 1.16: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9106-9126`. Phase 1.16 is nevertheless red for a new baseline-path defect, B3-4. |
| B2-5 — full implementations absent | **NOT CLOSED as a dispatch claim.** The ten production/tool bodies are supplied, but the test residual includes at least one impossible critical oracle: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7038,10251,10403-10407`. |
| B2-6 — unresolved Wave-0b/T2/lock shape | **CLOSED at rev-3 anchor, with entry conditions.** The catalogue is 327 and `LOCK_INHERITED_FROM` is list-shaped: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:19-22,41-43,89-100,10326`. |
| M2-1 — dead scanner counts declarations | **CLOSED at rev-3 anchor.** Declaration roots are excluded and `MODULE_PERMISSIONS` is separately parsed: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4524-4681`. |
| M2-2 — reapply unconditional write/audit gap | **CLOSED at rev-3 anchor.** Flag clearing is conditional and every effective mutation is audited: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7475-7545`. |
| M2-3 — new route violates literal rule | **CLOSED at rev-3 anchor.** The route uses `IdentityPermission::RolesManage->value`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7567-7583`. |
| M2-4 — YAML cannot render Notes | **CLOSED narrowly.** Steps are mappings and the renderer consumes the same shape: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9564-9590,9677-9727`. |
| M2-5 — entrypoint applies without dry-run gate | **CLOSED structurally.** Apply is nested in the successful dry-run arm: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7301-7323`. B3-1 independently makes every fleet invocation fail. |
| M2-6 — placeholder staging/analysis commands | **CLOSED as explicitness.** The paths and generator are written out: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3328-3490,3561-3608,8628-8677`. |
| M2-7 — four scanner roots mismatch | **CLOSED at rev-3 anchor.** The contract is three consumer roots plus one generated existence fixture: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4524-4560`. |
| Minor — stale worktree HEAD | **NOT CLOSED.** Recorded `9282d7b2d`; current `ccc9dcf2a`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:24`. |
| Minor — `purchase-orders.approve` example | **CLOSED at rev-3 anchor.** The canonical example is `purchase-orders.confirm`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:502-515`. |
| Minor — “four” controller dependencies | **CLOSED at rev-3 anchor.** It now says five: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7460-7468`. |
| Minor — PHPStan registration section | **CLOSED at rev-3 anchor.** Both argument-taking rules are under `services:`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9108-9125`. |
| Minor — class bodies precede tests | **REJECTED-correctly.** This is acceptable document ordering if execution still produces and records the red first: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10408`. |
| Citation audit — everything else | **NOT CLOSED.** Several rev-3 prose claims do not match the supplied bodies; see Citation audit. |
| r9 Preserve list | **NOT CLOSED.** The rev-3 changelog’s “HONOURED” claim is contradicted by B3-1 through B3-5: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10340`. |

## BLOCKER

### B3-1 — `permissions:sync-fleet` passes the wrong callback type to Wave 0b’s runner

Wave 0b’s committed producer declares `Closure(Tenant): FleetTenantOutcome` and calls it with the hydrated `Tenant` object:

- `692cede7a:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1480-1484`
- `692cede7a:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1566-1589`

Wave 1 supplies `function (string $tenantId)`:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6922-6924`

With `strict_types=1`, the `Tenant` object cannot satisfy `string`. Wave 0b catches the resulting `TypeError` and converts it into `FleetTenantOutcome::failed(...)`:

- `692cede7a:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1589-1591`

Consequently every fleet tenant fails, the entrypoint dry run reaches its blocked arm, and the apply never runs. Phase 1.10 is not green in isolation, and the staging-push path is nonfunctional.

The closure must accept `Tenant $tenant`, derive `(string) $tenant->id`, and pass that ID to the service and marker.

### B3-2 — the virtual planner does not canonicalise role grants after step 1

Step 1 updates only the virtual permission-name set:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6194-6227`

The virtual role projection is then built from live, pre-rename relationship names and only sorted:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6250-6257,6661-6679`

Step 6 therefore compares template targets against pre-rename grants:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6344-6348`

The executor first renames the permission row in place, preserving pivots:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6461-6467`

`TemplateDeltaApplier` then re-reads post-rename relationship names:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5349-5355`

The executor comparison necessarily reports `plan_divergence` where a template holds a rename source:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6511-6525`

This is not hypothetical. W-LOT’s manager holds `uom.edit` and `deliveries.edit` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:681,684`; the Wave-1 template carries `uom.update` and `deliveries.update` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1316,1392`.

Dry-run plans “remove old/add new”; apply sees the pivot already renamed and computes neither. Ordinary legacy tenants therefore roll back with `plan_divergence` even though their dry run reports green. This directly falsifies B2-1’s closure and the canonical(S) Preserve requirement.

Use Wave 0b’s `PermissionRenameMap::canonicalise()` on every virtual grant set before adoption/delta/replacement comparisons. Its exact contract is `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:409-429`.

### B3-3 — `permissions:sync --tenant=B` does not restore a pre-existing tenant A

The command always marks itself as having initialized tenancy after calling `initialize(B)`, then calls `end()`:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6843-6853,6868-6873`

Stancl’s actual behavior is:

1. if another tenant A is active, `initialize(B)` first calls `end()`;
2. it then initializes B;
3. the command’s `finally` ends B and leaves no tenant active.

Evidence: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Tenancy.php:32-58,61-72`.

That contradicts the promised A-restoration oracle:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7042`
- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10091`

Snapshot the prior tenant object/key. In `finally`, restore it with `initialize($previousTenant)` when one existed; call `end()` only when the command began from central context.

### B3-4 — generated PHPStan baselines cannot match the rules’ site keys

Both rules use `$scope->getFile()` directly:

- literal rule: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8466-8468,8507-8509`
- role-name rule: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9013-9016,9033-9035`

PHPStan absolutizes command paths before analysis in its installed `CommandHelper.php:194`; those absolute paths flow into `Scope::getFile()`.

The generator deliberately strips `getcwd()."/"` and writes relative paths:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8644-8657`

The baseline therefore contains `app/...:line[:key]`, while the rules test `/absolute/worktree/apps/api/app/...:line[:key]`. No pre-existing site is absorbed. The required whole-app run is red:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9126,9131-9135`

Normalize paths identically in both the generator and rules, and add a rule test that loads a generated baseline entry rather than a hand-written path.

### B3-5 — nested transactions restore the permissions team before `afterCommit` runs

The service sets the tenant team, opens a transaction, registers an after-commit callback, and restores the previous team in its outer `finally`:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6141-6182`
- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6606-6617`

Laravel executes callbacks only when the root transaction reaches level zero:

- `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Database/DatabaseTransactionsManager.php:67-95,243-246`

The compatibility registration path already wraps tenant initialization in an outer transaction:

- `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:355-362,483-492`

On that path, the inner service transaction returns, the service restores the previous permissions team, and only later does the outer commit run `forgetCachedPermissions()`. The callback therefore clears the wrong S-1 namespace, contrary to both the plan and accepted spec:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5673,6606-6611`
- `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1198`

Capture the tenant ID in the callback and temporarily set/restore the registrar’s team inside the callback itself. Add a nested-outer-transaction test; the current second-connection test at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7032` does not establish this case.

### B3-6 — compatibility mode has no usable operator path

The plan promises `permissions:sync` will run steps 1–4 globally in compatibility mode:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5692,6281-6293`

But:

- `--tenant=` is refused: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6811-6816`.
- without `--tenant`, a central Artisan process has no permissions team and exits `no_tenant_context`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6833-6840`.
- the fleet runner requires each physical tenant database to exist: `692cede7a:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1571-1579`.
- the repository explicitly treats absent per-tenant databases as normal in compatibility mode: `dev:apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:90-102`.

Thus neither the per-tenant command nor the fleet command can perform the documented compatibility migration from a normal central console. The proposed EC-21 test cannot demonstrate an actual deploy/remediation route:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7047`.

Supply an explicit global compatibility execution path that does not depend on a pre-set Spatie team or Wave-0b’s physical-database readiness predicate.

## MAJOR

### M3-1 — a critical named residual test is impossible as specified

`a_missing_admin_role_fails_closed` installs the same `AFTER INSERT` trigger as the applying test and expects failure on both apply and dry-run:

- trigger: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7016-7029`
- two-mode expectation: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7038`

A dry run executes no insert, so the trigger cannot fire. Step 5 plans a virtual admin and the dry run remains green. This directly contradicts the claim that the remaining tests are routine assertion-fill:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10251,10403-10407`

Split the oracle: dry-run should preview creation successfully, while apply with the execution-time trigger should fail and roll back. If the accepted contract requires a failing dry run, use a planning-time failure fixture such as a rename collision.

The selector, fleet callback, canonicalisation, nested-cache, and compatibility cases also need pasted executable bodies because they are the only reliable oracles for the blockers above.

### M3-2 — the PHPStan literal guard exempts unrelated production files ending in `Permission.php`

`ALLOWED_SUFFIXES` includes the broad fragment `Permission.php`, checked with `str_contains()`:

- `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8427-8442,8495-8499`

That exempts all such production files, not just generated module permission enums. Existing examples include:

- `dev:apps/api/app/Http/Middleware/RequireAnyPermission.php:11-19`
- `dev:apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationSessionPermission.php:10-20`

Neither currently contains a literal violation, but future literals in them bypass the “door closes behind existing sites” guarantee. Restrict the exemption to the intended module-enum path, for example `/app/Modules/*/Domain/Enums/*Permission.php`.

## MINOR

1. The plan claims the AST test accepts `implode(',', [Enum...])`, but its third “accepted shape” is another concat and the evaluator has no `FuncCall` branch: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8693,8786-8808,8821-8834`. No current route uses the missing shape, so either implement it or delete the claim.

2. Rule (d) says the spec and every wave plan are checked, but the test has one `PLAN` constant and reads only Wave 1: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9556,9587-9589,9760,9854-9877`. Narrow the prose or add the other documents.

3. The changelog says `resolveTemplateRoles()` is the plan’s only roles read, but `refuseTemplateKeyNameMismatch()` and `holdersOf()` also query roles: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6642-6650,6691-6699,6721-6726,10340`.

4. The worktree HEAD citation is stale: recorded `9282d7b2d`, current `ccc9dcf2a`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:24`.

## Citation audit

### Catalogue and templates

Independent static derivation produced:

- 304 unique dev keys;
- two W-LOT wrapper additions;
- nineteen Wave-0b additions;
- two T2 additions;
- eleven spelling-only renames;
- **327 expected keys**.

The assignment table contains 327 unique rows with **no missing keys, no extras, and no duplicates**. The derivation anchors are `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:41-43,83-85`; T2 defines/grants its two keys at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:201-202,587-604`.

All 327 `templateDefaults` rows matched the post-lane grant map, not merely the requested sample. Role totals are exactly:

| Template | Keys |
|---|---:|
| admin | 327 |
| general_manager | 259 |
| manager | 257 |
| accountant | 87 |
| operator | 58 |
| cashier | 49 |
| viewer | 34 |
| technician | 14 |

Plan anchor: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1119-1126`.

Representative cross-checks include `products.view` at `:1282`, `credit-notes.cancel` at `:1385`, `invoices.print` at `:1401`, both T2 transfer keys at `:1470-1476`, `batches.view` at `:1495`, `work-orders.view` at `:1512`, `workshop.technicians.view` at `:1524`, `services.view` at `:1537`, `payments.create/reverse/view` at `:1572-1576`, `treasury.manage_all_locations` at `:1582`, `journal.post` at `:1625`, `taxation.tax_configurations.manage` at `:1637`, `pos_orders.delete` at `:1696`, `settings.view` at `:1751`, and `audit.view` at `:1771`.

The 22 deprecations exactly match the accepted authoritative list:

- plan: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4705-4707`
- spec: `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1942-1948`

All 22 have `replacedBy: null`.

The eleven renames exactly match the spec’s list at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1893-1911`.

Exactly six resources fail `RESOURCE_PATTERN`: four `pos_orders.*` keys plus `taxation.tax_configurations.manage` and `taxation.withholding_rules.manage`. No other resource fails it. The distinction from the total 59 `legacy()` definitions is correctly stated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:293,546,1254-1256,3503-3516`.

### Manifests, enums, providers, and PHP syntax

Static extraction found:

- 38 manifest bodies, 327 unique definitions;
- 38 enum bodies, 327 unique values;
- exact per-module enum/manifest equality;
- 59 `legacy()` calls;
- 22 deprecated definitions;
- no manifest rename source.

The registry aggregates tagged manifests at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:964-979`; every provider receives a tag line at `:1185-1234`. All 38 named providers exist at dev and are represented in `dev:apps/api/bootstrap/providers.php:3-54,60-117`.

I linted all 109 author-counted PHP blocks plus 19 nested/example PHP snippets after CommonMark indentation removal: **128/128 `php -l` clean under PHP 8.4.15**. Namespaces and PSR-4 paths are plausible.

### Sync, fleet, migration, and deployment

The nine logical steps are in the settled order, with adoption before role-grant writes:

- plan phase: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6199-6438`
- execution phase: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6456-6575`

`SyncRollback`, post-execute admin verification, conditional marker write, mutation/gauge separation, exact pristine-template diff, admin `activeKeys()`, and conditional version writes are structurally correct. The blockers concern inputs/context around those mechanisms.

Migration coverage is sound:

- four columns: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5116-5123`
- tenant and NULL-team partial uniques: `:5125-5128`
- PG CHECKs and SQLite trigger equivalents: `:5130-5142,5177-5203`
- guarded down migration: `:5145-5174`
- no enum-typed column is altered.

The W-LOT marker CHECK requires tenant, source, `general_manager`, and `sanctum`, and the proposed insert satisfies it: `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-48`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6491-6507`.

The entrypoint nesting itself is safe under the existing `set -e`:

- current `set -e`: `dev:apps/api/docker/entrypoint.sh:1-2`
- existing migration is already inside `if`: `dev:apps/api/docker/entrypoint.sh:148-154`
- proposed dry-run/apply commands are `if` conditions, so failures select status arms rather than terminating boot: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7301-7323`.

It is not deployable until B3-1 is fixed.

### SoD

The eight groups and twenty member keys, including `payments.reverse`, are correct at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7630-7649`.

Re-deriving template intersections produced exactly the eighteen baseline rows shown at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7653-7678`. `payments.reverse` contributes no row because it is admin-only.

### Commit isolation

The nine Phase-1.4 group counts total 327 and their explicit staging blocks are complete: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1236-1252,3328-3503`.

Phase 1.10’s `git rm`/`git mv`/`git add` coverage is explicit and includes the eight stopgap items: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6968-7108`.

However, green-per-commit is not established:

- Phase 1.10 fails at runtime/PHPStan on the fleet callback and its canonical legacy-tenant oracles fail: B3-1/B3-2.
- Phase 1.16’s baselines do not absorb existing sites: B3-4.
- Phase 1.11’s nested cache contract lacks a valid oracle: B3-5.

## Rejected false positives

- **The 327 catalogue is correct.** T2’s two keys belong in Inventory and in both manager/general-manager defaults: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1460-1476`.
- **The role totals are correct.** The previous 325/257/255 figures are obsolete after T2: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1119-1126`.
- **The six invalid-resource `legacy()` calls are not the entire legacy set.** The total 59 also includes actions that cannot be represented by the closed verb/qualifier model: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1254-1256,3503-3516`.
- **`payments.reverse` belongs in the SoD group despite having no baseline row.** Its absence from templates is the preventative control: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7644,7649`.
- **The migration does not alter an enum column and the SQLite split is supplied.** `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5088-5207`.
- **`SyncRollback` correctly rolls back dry runs and non-green applies.** The defect is the incorrect plan/context around it, not the sentinel: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6144-6179`.
- **Provisioning now fails closed.** `TenantPermissionSyncFailed` is a valid correction: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7238-7249`.
- **The shell nesting is valid under `set -e`.** Commands used as `if` conditions are intentionally tested for failure: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7301-7323`.
- **Class bodies preceding test bodies is acceptable plan presentation.** The executor must still write/run the red first: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10408`.
- **No Wave-0b gate-r3 defect is re-reported here.** The only Wave-1 consequence is that the repaired `PermissionWriterCensus` API and resulting writer set must be revalidated before Wave 1 opens: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:19-22,354-361`.

## Preserve

The following accepted contracts remain correctly preserved:

- Direction B and the 38-module catalogue architecture: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7-13,1185-1234`.
- Legacy NULL-team roles without re-homing: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6721-6756`.
- W-LOT marker value, CHECK-compatible creation, advisory key, and role order: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:89-102,6491-6507,6735-6743`.
- Distinct `matchesPreWave0b()` and `matchesVersion0()` consumption: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:337-352`.
- Exact deltas only for uncustomised templates and no ordinary custom-role writes: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5275-5279,6332-6413`.
- `admin = activeKeys()`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6416-6435`.
- Exactly two declared sync triggers: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7172-7178,7379`.
- Immutable tenant-wide `RoleSyncedV1` auditing with an explicit actor: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5420-5604`.
- Generated frontend map and en/fr/ar completeness gate: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9161-9527`.

The following Preserve obligations are violated:

- behavior-equivalent dry-run/apply planning: B3-2;
- usable fleet deployment: B3-1;
- tenant-context restoration: B3-3;
- after-commit tenant-scoped cache reset: B3-5;
- executable compatibility-mode sync: B3-6.

## Owner decisions required

No new owner decision is needed for B3-1 through B3-6 or M3-1/M3-2. They are engineering corrections under settled contracts.

Existing decisions remain:

1. O-5/OQ-3 may remain unanswered at dispatch, but must be resolved before Task 1.13 pins the SoD baseline; the default is eighteen: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7680-7683,10246`.
2. O-6/OQ-4 remains a post-soak decision; production default stays false: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10247,10272`.
3. OQ-1/OQ-2 remain Wave-2 matters.
4. A named French/Arabic translation owner is required before completion, not before initial engineering work: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9526-9527,10240`.

Mandatory entry conditions also remain:

- Wave 0a, repaired Wave 0b, and T2 must be ancestors of `dev`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:37-44,58-70`.
- Wave 0b’s gate-r3 scanner repair must be persisted, merged, and re-run against Wave 1’s three new locked entry points and two inherited callers. The pending producer repair can change the writer census and therefore invalidate Wave 1’s assumed fixture, but not the verified 327-key catalogue: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:89-100,354-361`.
- W-LOT’s staging protocol and Wave-0b deployment evidence must be complete: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:44-56`.

## Dispatch assessment

The catalogue, manifests, enums, defaults, deprecations, renames, migration, SoD table, audit design, reapply action, provisioning failure, YAML shape, entrypoint control flow, and explicit staging instructions are substantially sound.

The plan is not dispatch-ready because its principal deploy command cannot call the inherited fleet runner, its planner diverges from its executor on ordinary rename-bearing tenants, its remediation command destroys a prior tenant context, its PHPStan baseline commit is red, its after-commit cache reset uses the wrong tenant under a real nested transaction, and compatibility mode has no usable operator execution path.

The declared residuals resolve as follows:

- **Acceptable for dispatch once blockers close:** machine-derived template defaults; lane-measured PHPStan/label-gap counts; class-before-test document ordering; French/Arabic placeholders with a named owner; most named-only assertion-fill cases.
- **Must be corrected or supplied before dispatch:** fleet callback test, rename canonicalisation dry-run/apply test, prior-tenant restoration test, generated-baseline absorption test, nested-transaction cache test, compatibility operator-path test, and the impossible two-mode missing-admin trigger oracle.

VERDICT: CHANGES-REQUIRED