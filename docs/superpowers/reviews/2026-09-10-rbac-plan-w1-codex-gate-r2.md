# Codex plan gate r2 — RBAC wave 1 plan rev 2 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD` → **`f7c2805d3`**

Reviewed only `docs/superpowers/plans/2026-09-10-rbac-wave-1.md` against the accepted rev-9.1 specification, the r9 Preserve register, current `dev`, W-LOT, T2, Wave-0b rev 2, and the Wave-0b gate-r2 scratchpad. No Artisan boot was attempted.

## Rev-1 closure table

| Rev-1 finding | Rev-2 ruling |
|---|---|
| **B1-1 — 323/325 catalogue arithmetic** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:74,277-287,1206-1742`. Static derivation is exactly 304 unique `dev` keys + two W-LOT additions + nineteen Wave-0b additions = 325. |
| **B1-2 — incomplete manifests and necessarily-red incremental migration** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1190-1205,1206-3250,3285-3308`. The plan contains 325 assignment rows, 38 complete manifest bodies and a coherent shrink-only unassigned fixture across Phase 1.4a–1.4i. |
| **B1-3 — unconditional `template_version` write** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4883-4885,4963-4985,4993-4996`. `template_version` changes only when its value differs and is included in `TemplateDelta::wrote()`. |
| **B1-4 — stopgap and replacement not atomic** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5234-5236,6070-6080`. Phase 1.10 lands sync, both commands and `SyncDryRunRollback` while deleting all eight stopgap items. |
| **B1-5 — dry-run before schema migration** | **CLOSED as an ordering edit** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7107-7114,7426-7432`. Migration is first in both representations. The generated-table implementation and staging-push path are not closed; see B2-4 and M2-5. |
| **B1-6 — undefined audit actor** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:177-179,5201-5207`. The new tenant-wide method takes the actor explicitly in the same position as `dev:apps/api/app/Modules/Compliance/Services/AuditService.php:43-51`. |
| **B1-6a — nullable company guard causes `TypeError`** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:177,7479`. Both the widened guard and `$this->companyId = $companyId ?? ''` are required; the underlying property remains non-nullable at `dev:apps/api/app/Modules/Compliance/Domain/AuditEvent.php:71,103-104,127,236-240`. |
| **B1-7 — prose/placeholder implementation** | **NOT CLOSED.** Manifests, enums, migration, sync, audit and command bodies were added, but scaffold, both PHPStan rules, the route AST test, label exporter/test, deploy renderer/test and many critical tests remain instructions rather than bodies. The changelog claim at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7480` is false; see B2-5. |
| **M1-1 — commit subjects** | **CLOSED.** The global rule and task sites consistently use `Phase 1.<task>:` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:105-111`; Phase 1.4 correctly uses 1.4a–1.4i. |
| **M1-2 — exact staging** | **NOT CLOSED.** Phase 1.4 still uses `<Mod>`, `<the module's provider path>` and `...one pair...` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3274-3282`; Phase 1.5 dynamically stages shell output at `:3342-3348`; Phase 1.16 stages a directory at `:6975-6987`. The changelog assertion at `:7482` is false. |
| **M1-3 — inherited lock proof** | **NOT CLOSED.** Wave 1 asks one public helper to inherit from two external callers at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6511-6514`, while the inherited Wave-0b fixture remains `array<string,string>` and checks only same-file callers at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3000-3013,3136-3166`. Phase 0 merely greps constant names at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:307-323`. |
| **M1-4 — nondeterministic scaffold contract** | **CLOSED as design, not implementation.** The five deterministic rules are now concrete at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6826-6838`; the command body remains absent, covered by B2-5. |
| **M1-5 — presence-only deploy test** | **NOT CLOSED as executable code.** Exact-order intent is correct at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7068-7072,7147-7159`, but only an `assertSame` fragment exists and the proposed YAML cannot generate the displayed Notes column; see M2-4. |
| **M1-6 — incomplete PG/static commands** | **NOT CLOSED.** PG commands now consistently name both databases and `-c phpunit-pgsql.xml`, but Phase 1.4 retains path placeholders at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3268-3282`, and Phase 1.16 retains an ellipsis-only baseline generator at `:6913-6918`. The changelog claim at `:7486` is false. |
| **Minor — lingering 323 references** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:74,277-287,1190-1205`. |
| **Minor — PHPStan route-file wording** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6921-6925`; current PHPStan analyses `app/` at `dev:apps/api/phpstan.neon:5-8`, so only top-level `routes/` needs the companion scanner. |
| **Minor — YAML trailing whitespace** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7096-7106`. |
| **Citation — stale ref register** | **CLOSED for `dev` and lane tips; stale again for worktree HEAD.** `dev=630afa86f`, W-LOT=`52f5ad796`, T2=`951a7637e` still match `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:17-26`. The recorded worktree HEAD `fc0f124b6` no longer matches current `f7c2805d3`. |
| **Citation — RoleController/TenantInitialization/entrypoint/AuditEvent/health/PHPStan lines** | **CLOSED.** Reopened coordinates match current code; details are in Citation audit. |
| **Cross-plan — selector provider** | **CLOSED subject to the pending Wave-0b fix.** Wave 1 appends the correct `permissions:sync-fleet` row and deletes the old row at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6133-6137,7290-7292`. |
| **Cross-plan — dry-run sentinel** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5319-5341,5520-5523,5542-5551`. |
| **Cross-plan — stopgap same-commit deletion** | **CLOSED** at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5234-5236,6070-6080`. |
| **Cross-plan — `PermissionWriterCensus`/`markerLine()`** | **PARTLY NOT CLOSED.** `markerLine(tenantId, write)` is supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5423-5441`; the external-caller lock inheritance proof remains invalid. |
| **r9 Preserve list** | **NOT CLOSED.** Catalogue, migration, exact pristine-template diff and audit design are preserved, but dry-run is not behaviorally equivalent to apply, the operator command lacks tenant initialization, and provisioning may continue after sync failure. These violate the preserved contracts at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:194-209`. |

The rev-2 changelog is therefore accurate through `B1-6a`, but its claims for B1-7, M1-2, M1-3, M1-5, M1-6 and the Preserve list are not supported by the supplied code. N-9 also introduces a new red-commit dependency, and N-11 introduces a new literal that violates the static rule it adds: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7480-7486,7496,7510-7512`.

## BLOCKER

### B2-1 — the first Wave-1 fleet dry-run fails or under-reports every legacy tenant

Dry-run does not simulate the state created by earlier steps:

- Adoption writes `template_key` only under `$write` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5629-5651`.
- Missing roles are added to the role collection only under `$write` at `:5662-5698`.
- Template deltas then re-query only rows already carrying `template_key` at `:5703-5718`.
- Admin likewise re-queries by `template_key='admin'` and declares failure if absent at `:5822-5838`.

On the first Wave-1 run, legacy roles have `template_key = NULL`. Apply mode adopts them and proceeds; dry-run leaves them unchanged in the database, skips their deltas, then reports `admin_role_missing`. A tenant missing only `general_manager` avoids the admin failure but still omits the would-be general-manager grant delta. This contradicts the promise that dry-run changes only whether writes commit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:91,5323-5327`, the named missing-admin test at `:6125`, and the mandatory pre-apply fleet dry-run at `:7428-7430`.

The service needs a simulated post-step state for adoption and role creation, or a shared planner that computes all nine steps before either applying or rolling back.

There is a second failure-path defect: `stepAdmin()` merely sets `FAILED` and returns at `:5832-5838`; the transaction subsequently registers cache work, settles and returns normally at `:5538-5548`. If that failure becomes reachable on apply through a concurrent or inconsistent state, prior permission, adoption and template writes commit despite the promised zero-write failure. A non-green apply result must leave the transaction by rollback, not ordinary return.

### B2-2 — `permissions:sync --tenant=` does not initialize the selected tenant database

The command resolves a central `Tenant` identifier and immediately calls the sync service at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5958-5968`. Neither the command nor `PermissionSyncService` initializes Stancl tenancy; the service only sets Spatie’s team ID at `:5507-5511`.

Current database-per-tenant command code explicitly initializes before tenant work at `dev:apps/api/app/Console/TenantScopedCommand.php:323-332`, and the repository’s fail-closed resolver performs `tenancy()->initialize($tenant)` at `dev:apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:81-107`.

As written, the documented remediation command can operate on the current/default connection while labelling the result with another tenant’s ID. That is a cross-tenant data-safety blocker. Resolve and initialize the tenant, restore/end tenancy in `finally`, and test ID and slug selectors against two physical tenant databases.

### B2-3 — provisioning logs a failed sync and continues creating the tenant

`PermissionSyncService` catches failures and returns a `FAILED` result at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5550-5555`. The proposed provisioning replacement only renders that result and returns normally at `:6252-6258`; it never checks `outcome->isGreen()` or throws. Initialization then proceeds to `assignDefaultRoles()` in the unchanged order shown by `dev:apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:70-78`.

If an incomplete sync leaves an existing `admin` role, assignment can succeed and provisioning can finish with a partial catalogue. That contradicts the accepted assertion that provisioning-time sync failure surfaces as failed tenant creation and the proposed completed-provisioning test at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6342-6345`.

After logging, provisioning must throw on `BLOCKED` or `FAILED`; its transaction/cleanup contract and test must prove the tenant is not reported provisioned.

### B2-4 — Phase 1.16 is knowingly red because its required key fixture arrives in Phase 1.17

The new PHPStan rule reads `permission-registry-keys.json` and deliberately raises an error if that file is absent at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6898-6909`. Phase 1.16 nevertheless runs whole-app PHPStan at `:6968-6973`. The fixture is first generated and staged in Phase 1.17 at `:7015-7024,7051-7055`.

The rollback table itself exposes the dependency at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7446`. Forward history is therefore red between commits 1.16 and 1.17, contrary to the global green-per-commit rule at `:75`.

Generate and commit the fixture in Phase 1.16, or land the exporter/fixture commit before the rule registration.

### B2-5 — B1-7’s “full implementations supplied” claim remains false

No class body exists in the document for any of these promised deliverables:

- `ScaffoldPermission`: only signature, sequencing and named tests at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6822-6879`.
- `ForbidPermissionStringLiteral`, `ForbidRoleNameAuthorization` and `RoutePermissionLiteralTest`: only registration, a twelve-line evaluator fragment and prose at `:6883-6989`.
- Exporter `render()` changes, `ExportPermissionLabelSkeleton`, and `PermissionRegistryLabelCoverageTest`: only a constructor/call-site fragment and requirements at `:6993-7060`.
- `RenderPermissionDeployTable` and `PermissionDeploySequenceTest`: only YAML and a four-line assertion fragment at `:7064-7172`.

A direct search for `class ScaffoldPermission`, `class ForbidPermissionStringLiteral`, `class ForbidRoleNameAuthorization`, `class RoutePermissionLiteralTest`, `class ExportPermissionLabelSkeleton`, `class PermissionRegistryLabelCoverageTest`, `class RenderPermissionDeployTable`, and `class PermissionDeploySequenceTest` returns no definition in the plan.

Approximately 45 other test bodies are named but not supplied, including the only oracles for cross-database isolation, concurrency, query-log zero-write behavior and migration constraints at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6115-6140,6342-6347,6507-6514,6815-6818,7143-7159`.

The author’s declared residual is not dispatch-acceptable. These are not routine assertion-fill tasks; implementers would have to design the missing AST traversal, source rewriting, YAML rendering, database fixtures and concurrency coordination.

### B2-6 — Wave 1 cannot be dispatched until the Wave-0b fix chooses and publishes its dependency shape

Wave-0b gate r2 requires either whole-wave T2 ancestry or a complete split of overlapping paths at `/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/cb4416d5-b735-4a13-969b-b6e60af933c5/scratchpad/rbac-plan-0b-gate-r2.out:34-50`.

If the fix chooses whole-wave T2 ancestry, T2 contributes `inventory.transfers.reconcile` and `.close` plus manager grants at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-202,602-605`. Wave 1 currently excludes those definitions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1085-1088` and pins 325 at `:74,277-287`. Its generated preflight would correctly stop at `:287,329-330`, but the complete table, role totals, manifests and enums would then need regeneration to 327.

The Wave-0b lock fix must also widen `LOCK_INHERITED_FROM`: current Wave-0b proves one same-file caller only at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3000-3013,3136-3166`, while Wave 1 needs one public helper with two external callers at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6511-6514`. Wave-0b gate r2 records that exact incompatibility at the scratchpad `:131-137`.

This is not a re-report of Wave-0b defects. It is a Wave-1 entry dependency whose resolution can invalidate Wave-1’s frozen catalogue and fixture syntax.

## MAJOR

### M2-1 — `permissions:report-dead` counts catalogue grants as consumers

The generic pattern `'/[\'"]%s[\'"]\s*,?\s*\]/'` is described as a `MODULE_PERMISSIONS` detector but is applied to every PHP/TS file under `apps/api`, `apps/web/src` and `apps/pos/src` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4531-4564`.

Task 1.6 runs before the seeder is replaced in Task 1.14. The current seeder has deprecated permissions as final elements of ordinary role arrays—for example `pos.refund_voucher_to_cash` at `dev:apps/api/database/seeders/RolesAndPermissionsSeeder.php:678-687` and `pos.redeem_voucher` at `:715-723`. Both satisfy the generic regex and are falsely counted as live consumers.

Consequently Task 1.6 cannot reliably reproduce the authoritative 22-key result promised at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4477,4600-4604`. Use AST-aware authorization-call detection, explicitly target `MODULE_PERMISSIONS`, or exclude catalogue/seeder declaration files.

### M2-2 — template re-apply violates its own zero-write and audit contracts

`reapplyTemplate()` unconditionally executes `UPDATE roles SET customised_at = NULL` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6452-6454`. An already-pristine role therefore writes even when the exact delta and version are current, contradicting the named zero-write test at `:6510`.

The event is emitted only when permissions are added or removed at `:6458-6472`. Clearing a non-null `customised_at`, or advancing only `template_version`, mutates the role without emitting the promised re-apply audit event from `:6425-6429`.

Make the flag clear conditional, retain whether it changed, and emit for any effective re-apply mutation. The already-pristine/current path should perform no update and emit no event.

### M2-3 — the new route violates the new static-literal rule

Task 1.12 adds:

`->middleware('can:roles.manage')`

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6495-6500`.

Task 1.16 says every such dotted literal at a middleware call is an error and that a baseline may absorb only pre-existing sites, never a site introduced by this wave: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6885-6896,6968`.

Use the module enum expression, such as `'can:'.IdentityPermission::RolesManage->value`, and include it among the AST accepted-shape tests.

### M2-4 — the YAML cannot generate the displayed deploy table byte-for-byte

The YAML represents each step as a plain scalar and contains no `note:` field at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7096-7136`. The proposed renderer says its Notes column comes from optional per-step `note:` values at `:7145-7146`, while the displayed Wave-1 table contains substantive notes at `:7426-7432`.

Those outputs cannot be byte-equal. Either encode each step as `{command, note}` and compare the complete rendered table, or remove the Notes column from generated equality and explicitly validate only commands.

### M2-5 — a staging push with the soak flag enabled bypasses the required dry-run gate

The entrypoint runs applying `permissions:sync-fleet` directly whenever `SYNC_PERMISSIONS_ON_BOOT=true` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6289-6301`. The one-week staging soak explicitly enables that flag at `:7436`, while the authoritative deployment sequence requires migrate → dry-run → apply at `:7107-7114,7428-7430`.

A normal image push starts the entrypoint before an operator can run a command inside that new container, so the plan does not establish how its mandatory dry-run precedes the automatic apply. This is not safe on a staging push as documented—especially while B2-1 makes the dry-run itself incorrect.

Supply a release-image/pre-start procedure, or make the flagged entrypoint execute dry-run first and skip apply on non-zero.

### M2-6 — task-exact commit instructions remain placeholders

Phase 1.4 uses placeholder analysis paths and staging paths at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3268-3282`. Phase 1.5 discovers/stages files through command substitution rather than naming 38 exact paths at `:3342-3349`. Phase 1.16’s baseline generator is literally `php -r '…extract…'` at `:6913-6918`.

The 1.4 shrink-only architecture is sound, but the plan does not satisfy its own exact-path/copy-paste command requirement at `:106-111`. The changelog claims at `:7482,7486` must be withdrawn until all paths and commands are enumerated.

### M2-7 — “four roots” is not what `ReportDeadPermissions` implements

The command declares only three roots at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4517-4523`, explicitly excludes the generated map at `:4558-4561`, and reports `count($roots)` at `:4589`. It therefore prints “3 roots,” despite the task, file table, docblock and tests repeatedly pinning four at `:150,4479-4489,4504-4509,4612`.

Treat the generated map as a separately validated fourth input or call the contract “three consumer roots plus one generated existence fixture.”

## MINOR

- The worktree reference at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:17` says `fc0f124b6`; current HEAD is `f7c2805d3`. The branch tips on `:21-24` remain correct.
- `PermissionDefinition` still uses nonexistent `purchase-orders.approve` as its canonical-key example at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:511`, despite the correction at `:1087`. Use `.confirm`.
- `RoleController` “gains four dependencies” but lists five at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6503`.
- The file-structure table says both PHPStan rules are registered under `rules:` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:183`. The task correctly puts the argument-taking rule under `services:` at `:6898-6908,6961-6964`.
- The author’s class-before-test ordering residual is acceptable as document ordering; the executor discipline at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7514-7518` is sufficient. It does not excuse absent test bodies.

## Citation audit

### Catalogue re-derivation

The 325-key assignment table is exact:

- `dev` defines 304 unique permission keys in `dev:apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559`.
- W-LOT’s wrapper adds exactly `batches.recall.request` and `treasury.manage_all_locations` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91-102`.
- Wave 0b names nineteen additions at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:547-589`.
- The eleven rename pairs are exact at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:328-349` and match the authoritative specification at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1893-1911`.
- After one-pass canonicalization, the plan’s 325 table rows at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1206-1742` have no missing, extra or duplicate key.

All 325 table rows reconstruct from the 38 manifest bodies at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1743-3250`, including exact `templateDefaults`, `sodGroup`, `behavesAs`, legacy and deprecation metadata. All 325 are represented once by the 38 enums at `:3352-4442`; per-module enum values equal per-module manifest values in both directions.

The derived non-admin totals are exactly those stated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1075-1081`: general manager 257, manager 255, accountant 87, operator 58, cashier 49, viewer 34 and technician 14. `admin` is 325 before excluding the 22 deprecated keys from `activeKeys()`.

### Template-default spot checks

I compared all 325 rows, not only the requested fifteen. Representative checks:

| Key | Derived defaults | Plan anchor |
|---|---|---|
| `products.view` | manager, general_manager, cashier, viewer, technician, operator | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1234` |
| `uom.view` | manager, general_manager, cashier, viewer, technician, operator | `:1269` |
| `credit-notes.cancel` | manager, general_manager | `:1337` |
| `invoices.print` | manager, general_manager, cashier, operator | `:1353` |
| `purchase-hub.orders.create` | none | `:1405` |
| `inventory.view` | manager, general_manager, cashier, viewer, technician, operator | `:1416` |
| `batches.view` | manager, general_manager, cashier, viewer, operator | `:1443` |
| `batches.recall.request` | manager, general_manager | `:1445` |
| `work-orders.view` | manager, general_manager, cashier, viewer, technician, operator, accountant | `:1460` |
| `workshop.technicians.view` | manager, general_manager, technician, operator | `:1472` |
| `services.view` | manager, general_manager | `:1485` |
| `payments.create` | manager, general_manager, cashier, operator, accountant | `:1520` |
| `payments.reverse` | none | `:1523` |
| `payments.view` | manager, general_manager, cashier, viewer, operator, accountant | `:1524` |
| `treasury.manage_all_locations` | general_manager only | `:1530` |
| `journal.post` | accountant only | `:1573` |
| `pos_orders.delete` | manager, general_manager | `:1644` |
| `channels.view` | none | `:1675` |
| `settings.view` | manager, general_manager, viewer | `:1699` |
| `audit.view` | accountant only | `:1719` |

### Metadata, legacy and SoD

The 22 deprecations exactly match the authoritative list at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1917-1948`. All 22 manifest definitions have `replacedBy: null`, so replacement step 7 is a provable Wave-1 no-op.

Exactly six resources fail `RESOURCE_PATTERN`, and all six use `legacy()`:

- `taxation.tax_configurations.manage` and `taxation.withholding_rules.manage` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1585-1586`.
- `pos_orders.create/delete/update/view` at `:1643-1646`.

No other table resource fails the pattern. The complete manifest metadata also contains exactly 59 `legacy()` definitions, matching `LEGACY_ACTION_CEILING = 59`.

The eight SoD groups and twenty members at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6543-6560` reproduce eighteen template combinations exactly at `:6564-6589`. `payments.reverse` correctly belongs to the payment group but creates no baseline row because no non-admin template holds it.

### Manifest/provider/compile plausibility

All supplied complete PHP file fences are syntactically plausible under PHP 8.4; no duplicate enum cases or illegal readonly/mutable-property combinations were found. Namespace/path pairs for the 38 manifests and enums are PSR-4-consistent.

All 38 provider paths listed at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1145-1188` exist on `dev`. Their provider classes are registered at `dev:apps/api/bootstrap/providers.php:3-54,60-117`. The plan supplies the per-provider tag line at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1139-1144` and registry aggregation through `$app->tagged('permission.manifests')` at `:923-937`.

### Sync and fleet citations

The nine settled steps appear in order at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5242-5255` and in the service at `:5520-5538`. Lock acquisition is the first transaction statement at `:5513-5516`; adoption precedes role grant writes; permission names are normalized through the inherited canonical baseline at `:5638-5641`; general-manager marker creation satisfies W-LOT’s CHECK, whose actual PG/SQLite expressions are at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-53` and partial unique at `:64-75`.

Conditional template version writes, exact pristine-role equality, active-key admin replacement, mutation/gauge separation and after-commit cache reset are correctly represented at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4883-4985,5251-5268,5358-5421,5822-5862`. The dry-run state transition remains broken as B2-1 describes.

`permissions:sync-fleet` correctly delegates selection/readiness/tenancy iteration to Wave-0b’s `TenantFleetRunner`, maps tenant outcomes and preserves non-zero failure/blocked exits at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5985-6067`. The entrypoint status writer and health reader are structurally consistent at `:6272-6338`; current health aggregation really is at `dev:apps/api/app/Modules/Admin/Application/Services/HealthCheckService.php:17-31`, and the authenticated route is at `dev:apps/api/routes/api.php:81-84`.

### Migration citations

The migration supplies the four requested columns, unique `(tenant_id, template_key)`, partial NULL-team unique, three CHECKs, six SQLite triggers, guarded down migration and no enum-column ALTER at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4617-4645,4694-4813`. The PG and SQLite branches are compile-plausible and preserve W-LOT’s prior constraint/index objects.

### Correct code citations

The following rev-2 coordinates were reopened and remain correct:

- `TenantInitializationService`: call order and old helper at `dev:apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:70-78,194-217`.
- W-LOT `RoleController::update`: resolution and write at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:239-265`.
- Identity role route group: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/routes.php:57-64`.
- Entrypoint runtime directories and old sync block: `dev:apps/api/docker/entrypoint.sh:19-23,145-170`.
- Audit event property/guard/column/hash: `dev:apps/api/app/Modules/Compliance/Domain/AuditEvent.php:69-73,98-108,123-131,236-243`.
- Frontend/POS examples: `dev:apps/web/src/routes/index.tsx:3051-3058`, `dev:apps/web/src/hooks/usePermissions.ts:106-116`, `dev:apps/pos/src/components/pos/TodaySalesPanel.tsx:302-309`.
- PHPStan registration sections: `dev:apps/api/phpstan.neon:5-8,33-47`.

Wrong/stale coordinates or semantic citations are limited to the worktree HEAD, “four roots,” “four dependencies,” the `.approve` example and the PHPStan `rules:` file-structure row identified under MINOR. No other numeric code coordinate inspected was stale.

## Rejected false positives

- **The catalogue is not 323, 326 or incomplete.** It is exactly 325 under the currently stated `dev + W-LOT + Wave-0b` ancestry, with exact manifest/enum parity at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:74,1206-4442`.
- **Template defaults are not hand-copy drift.** Full comparison against the lane grant map plus Wave-0b additions matched all 325 rows and the totals at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1075-1083`.
- **The six `legacy()` resource exceptions are correct.** No seventh resource violates the frozen pattern at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:495-508`.
- **The 22 deprecated definitions and eleven renames are correct.** They match `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1893-1913,1917-1948`.
- **The lack of real replacement grants is not missing Wave-1 behavior.** All 22 `replacedBy` values are intentionally null; the branch is a tested future contract at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5253,7417`.
- **`payments.reverse` does not make the SoD count nineteen.** It is group membership without a non-admin grant at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6555-6560`.
- **The roles migration does not alter an enum-typed column or re-home NULL-team roles.** It is additive and leaves `tenant_id` untouched at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4617-4645,4694-4813`.
- **The `AuditEvent` tenant-wide guard is no longer a `TypeError`.** The required `?? ''` assignment is explicitly included at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:177,7479`.
- **`TemplateDeltaApplier`’s conditional version write is correct.** The new re-apply zero-write defect is in the controller’s unconditional flag clear, not the applier at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4963-4985,6452-6454`.
- **All 38 module providers exist and are registered.** The generic one-line tag edit is adequate for those existing providers at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1139-1188`; `dev:apps/api/bootstrap/providers.php:3-54,60-117`.
- **Class bodies appearing before test bodies is not itself a dispatch blocker.** Missing class/test bodies and red dependency order are the blockers.

## Preserve

Corrections must preserve:

- The exact current 325-key derivation unless the Wave-0b T2 decision changes the entry tree; then regenerate rather than hand-edit. Anchors: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:277-287,1206-4442`.
- The complete 38-module ownership split and provider tagging at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1090-1205`.
- The eleven in-place renames and one-pass canonicalization at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:328-400`.
- Adoption before any grant write, exact deltas only for uncustomised template-linked roles, no template mutation of custom/customised roles, and `admin = activeKeys()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:77,86-90,5242-5255`.
- W-LOT’s advisory-lock key/order, marker CHECK and unique index at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-75`.
- Conditional template-version writes and zero-write second-run semantics at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4883-4985`.
- Mutation counters versus persistent-state gauges at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5268,5358-5392`.
- The two explicit triggers only—provisioning and flagged fleet deployment—while making provisioning failure-propagating at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6232-6264`.
- Failure-preserving, genuinely behavior-equivalent and write-free dry-run at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:91,5323-5327`.
- The PG/SQLite migration split, both partial uniques, three CHECKs and guarded down migration at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4617-4813`.
- The six `legacy()` exceptions, 59 legacy ceiling, 22 deprecations, 18 SoD baseline rows and inclusion of `payments.reverse`.
- Tenant-wide audit rows with nullable `company_id`, explicit actor and stable empty-string hash input at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:177-179,5201-5207`.
- Generated frontend permissions, TypeScript union, module mapping and complete en/fr/ar key coverage at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6993-7060`.
- The r9 binding register in full at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-210`.

## Owner decisions required

1. **Wave-0b/T2 ancestry must be settled before Wave 1 is released for execution.** If Wave 0b takes whole-wave T2 ancestry, Wave 1 must regenerate to 327 keys; if Wave 0b fully splits the overlap, the current 325 input remains valid. Evidence: Wave-0b gate scratchpad `:34-50`; T2 seeder additions at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-202,602-605`; Wave-1 exclusion at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1085-1088`.

2. **O-5/OQ-3 does not block overall dispatch once engineering blockers close.** The bounded default is to ship eighteen SoD rows; an owner ruling before Task 1.13 may lower it to 15 or 12 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:241-245,6591-6594`.

3. **O-6/OQ-4 is correctly deferred until the soak.** The production default remains false; the final choice follows evidence at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:247-249,7415,7436`.

4. **A named French/Arabic translation owner is required before Wave-1 completion, not before initial engineering dispatch.** English placeholders are permitted only as an explicit residual at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7028-7043,7408`.

The tenancy initialization, failed-provisioning behavior, dry-run simulation, scanner precision, fixture order and missing implementations are engineering corrections under settled rulings—not new owner questions.

## Dispatch assessment

The catalogue itself is in substantially better shape than rev 1: the 325-key table, 38 manifests, 38 enums, template defaults, renames, deprecations, legacy exceptions, SoD baseline, migration, audit event and core exact-diff implementation all pass static scrutiny.

The plan is nevertheless not dispatch-ready. The minimum required corrections are:

1. Make dry-run plan over the virtual post-adoption/post-role-creation state and roll back every non-green apply.
2. Initialize and restore Stancl tenancy in `permissions:sync --tenant=`.
3. Fail provisioning when sync returns `BLOCKED` or `FAILED`.
4. Move `permission-registry-keys.json` into or before Phase 1.16.
5. Supply complete implementations and critical test bodies for scaffold, PHPStan rules, AST route evaluation, exporter/label command and deploy renderer/test.
6. Repair the dead-key scanner so catalogue grant declarations cannot count as consumers.
7. Make template re-apply conditionally clear `customised_at` and audit every effective mutation.
8. Replace the new route’s permission literal with its enum expression.
9. Make YAML/table generation structurally capable of byte-equal Notes output.
10. Replace staging/analysis placeholders with exact paths and commands.
11. Publish the Wave-0b T2 and lock-inheritance resolutions, then revalidate the Wave-1 catalogue and fixtures.
12. Define how a staging push with the soak flag enabled observes migrate → dry-run → apply before automatic entrypoint mutation.

VERDICT: CHANGES-REQUIRED