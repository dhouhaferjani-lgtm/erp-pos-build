# Codex plan gate r7 — RBAC wave 1 plan rev 6.1 (gpt-5.6-sol, high, read-only, 2026-09-11)

Declared worktree HEAD:

```text
git rev-parse --short HEAD
ff2a949e9
```

Other measured references:

```text
shared checkout / dev    33796cc08
lane/w-lot-a-1a          a7010fe4d
lane/t2-receipt-spine    208449350
wave 0b rev-5 plan       39ecb80f9
```

The worktree is clean. The reviewed plan has exactly **11,344 lines**.

The rev-6→6.1 Git delta was verified directly between `177b3d7d7` and `ff2a949e9`. It contains the two advertised `SyncScope` imports, removal of the Dashboard manifest’s unused `PermissionVerb` import, the EC-21g expansion, two corrected wave-0b anchors, and the new change-log text. It does not alter catalogue rows, manifests beyond that unused import, enums, migration DDL, sync predicates, deploy YAML, staging lists, or commit subjects.

The named round-6 major is closed. The mechanical import review, however, considered only namespace-bearing whole-file fences. The final code prescribed by two follow-on fragment/injection instructions still lacks three required cross-namespace imports. That is the same compile-resolution class of defect as M6-1 and leaves Phase 1.11 and Phase 1.14 red as written.

## Rev-6 closure table

| Rev-6 finding | Rev-6.1 disposition |
|---|---|
| **M6-1 — `SyncPermissionsFleet` cannot resolve `SyncScope`** | **CLOSED.** `use App\Modules\Identity\Application\DTOs\SyncScope;` is present at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7313-7316`, and the callback uses it at `:7362-7366`. |
| **M6-1 class-wide follow-up — seeder also used `SyncScope` without importing it** | **CLOSED at that symbol.** The seeder imports `SyncScope` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8285-8289` and calls it at `:8341-8347`. A different final-injection import omission remains under M7-1. |
| **minor 1 — EC-21g’s claimed scope exceeded its scan** | **NOT CLOSED completely.** The roots are now correctly widened to `app/Modules/Identity`, `app/Console/Commands`, and `database/seeders`, but the stated raw “textual occurrence” allow-list is not executable against the supplied code; see N7-1 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7514`. |
| **minor 2 — fleet comment cited rev-4 anchors** | **CLOSED.** The active callback comment now cites rev 5 `39ecb80f9` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7357-7359`. The producer really declares the callback at `39ecb80f9:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1493` and calls it at `:1600`. |
| **citation item 4 — “every active anchor re-measured” was overstated** | **CLOSED.** The Global Constraint now uses `:1600` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:120`; the historical change-log attribution is annotated at `:11107`. |
| **Dashboard manifest’s unused `PermissionVerb` import, found by the editorial pass** | **CLOSED.** The import block contains only the manifest contract and `PermissionDefinition` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:2894-2901`; its sole definition is a `legacy()` call at `:2908-2913`. |
| **Round-6 rejected false positives and Preserve list** | **REJECTED-correctly / preserved.** The delta does not alter the scope value object, compatibility topology, team predicates, W-LOT CHECK, sync order, catalogue, or deploy sequence; the preservation statement is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11108`. |

## Rev-1 closure table

| Rev-1 finding | Rev-6.1 disposition |
|---|---|
| **B1-1 — stale 323-key arithmetic** | **CLOSED at rev 2 and later superseded correctly.** Rev 2 established 325; the accepted T2 entry condition subsequently raises the live catalogue to 327. The historical qualification is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11298-11299`; the live derivation is at `:95-102`. |
| **B1-2 — incomplete manifests and necessarily-red incremental commits** | **CLOSED.** The complete assignment and shrink-only migration mechanism are recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11299`; rev 6.1 changes only Dashboard’s unused import. |
| **B1-3 — unconditional `template_version` update** | **CLOSED.** The rev-2 disposition remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11300`; the supplied applier gates the update on `$versionChanged` at `:5413-5427`. |
| **B1-4 — stopgap deletion separated from sync landing** | **CLOSED.** The historical closure is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11301`; Phase 1.10 still lands sync, commands and `SyncRollback` with all eight removals in one commit. |
| **B1-5 — sync precedes its schema** | **CLOSED.** The YAML-generated migration→dry-run→apply order remains the rev-2 disposition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11302`. |
| **B1-6 — undefined tenant-wide audit actor** | **CLOSED.** The explicit second actor parameter remains recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11303`. |
| **B1-6a — nullable company assigned to non-nullable property** | **CLOSED.** The `$companyId ?? ''` correction remains recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11304`. |
| **B1-7 — implementation left as prose/placeholders** | **CLOSED as the original completeness finding.** The supplied implementations are catalogued at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11305`. M7-1 is a narrower namespace-resolution defect in final patch assembly, not a reopening of the original absence finding. |
| **M1-1 — invalid commit subjects** | **CLOSED.** The required `Phase 1.<task>:` subjects are recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11306`. |
| **M1-2 — no exact staging lists** | **CLOSED.** Exact staging/removal lists remain required at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11307`. |
| **M1-3 — invalid inherited-lock proof** | **CLOSED subject to Phase 0’s producer check.** The list-valued `LOCK_INHERITED_FROM` treatment is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11308` and instantiated at `:108-114,8140-8147`. |
| **M1-4 — nondeterministic scaffold contract** | **CLOSED.** The five-artifact deterministic rewrite contract remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11309`. |
| **M1-5 — deploy test checked presence rather than exact order** | **CLOSED.** Exact ordered equality remains the disposition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11310`. |
| **M1-6 — incomplete PG/PHPStan/Pint commands** | **CLOSED as the original command-specification finding.** Explicit harness and path requirements remain at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11311`. Those commands would expose M7-1 rather than concealing it. |
| **minor — stale 323 task prose** | **CLOSED and superseded.** Historical 325 and live 327 are distinguished at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11312`. |
| **minor — overbroad PHPStan/routes explanation** | **CLOSED.** The corrected distinction is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11313`. |
| **minor — YAML trailing whitespace** | **CLOSED.** The obsolete scalar row was replaced, as recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11314`. |
| **cross-plan — broken admin baseline** | **CLOSED by wave 0b.** Phase 0 still requires all nineteen additions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:396-400`. |
| **cross-plan — missing selector provider** | **CLOSED.** The append-only fleet provider row remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7517-7521`; historical disposition at `:11317`. |
| **cross-plan — rollback sentinel deleted without replacement** | **CLOSED.** `SyncRollback` remains the same-commit replacement; historical disposition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11318`. |
| **cross-plan — stopgap not removed atomically** | **CLOSED.** The binding is retained at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11319`. |
| **cross-plan — scanner/marker shapes** | **CLOSED subject to dispatch re-pin.** Phase 0 checks the inherited shapes at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:362-395`; historical disposition at `:11320`. |
| **cross-plan — `DateFactory` substitution** | **REJECTED-correctly.** The repository-aligned substitution remains explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6260-6263`. |
| **cross-plan — seeder preservation branch** | **REJECTED-correctly.** The marked-tenant preservation ruling remains binding at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:317-323,8310-8339`. |
| **cross-plan — three tenant states / Convention 09** | **REJECTED-correctly.** Fresh, second-tenant, second-location and rerun coverage remain separately specified at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:278-287`. |
| **citation — stale ref register and source anchors** | **CLOSED as historical findings.** The rev-2 closure is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11315-11316`; active dispatch remeasurement remains mandatory at `:346-395`. |
| **r9 Preserve register** | **HONOURED.** Its binding disposition remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11321`. |

The rev-1→rev-2 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11292-11344` remains substantively accurate against code. Its 325-key statements are expressly historical; the active 327-key total is the accepted later T2 supersession, not a regression.

## BLOCKER

None.

The unresolved symbols below make isolated commits red, but they do not corrupt data or invalidate the settled sync/migration contracts before execution. They are MAJOR dispatch defects.

## MAJOR

### M7-1 — the “all PHP blocks” import pass excludes executable final-patch instructions, leaving three unresolved symbols

The self-review says the 108 namespace-bearing blocks were checked and then names `TenantInitializationService` as the sole fragment introducing a new symbol, claiming it is correct as written at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11110-11114,11122-11129`. That conclusion does not follow from checking only blocks that declare a namespace.

#### Phase 1.11: two imports remain unspecified

The `TenantInitializationService` fragment adds three cross-namespace symbols:

```php
private readonly PermissionSyncService $permissionSync;
$scope = SyncScope::tenant($tenantId);
throw new TenantPermissionSyncFailed(...);
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7762-7795`.

Only the `SyncScope` import is explicitly instructed at `:7781-7785`. The current development file’s imports contain none of these three classes at `dev:apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:5-31`. Therefore the final prescribed class still lacks:

```php
use App\Modules\Identity\Application\Services\PermissionSyncService;
use App\Modules\Tenant\Domain\Exceptions\TenantPermissionSyncFailed;
```

Without them PHP resolves the names as:

```text
App\Modules\Tenant\Application\Services\PermissionSyncService
App\Modules\Tenant\Application\Services\TenantPermissionSyncFailed
```

Neither class exists. The intended service and exception paths are established at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:188-190,7723-7759`.

Phase 1.11’s own PHPStan command analyses the modified class at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7937-7944`, so that commit is not green in isolation as written.

#### Phase 1.14: the required repository injection lacks its import

The supplied seeder block currently uses a fully qualified service-locator reference:

```php
app(\App\Modules\Identity\Infrastructure\Repositories\RoleMarkerRepository::class)
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8383-8387`, so the extracted block itself parses.

The immediately following mandatory instruction says to replace that seam with:

```php
private readonly RoleMarkerRepository $roleMarkers
```

and `$this->roleMarkers->tenantCarriesMarker(...)` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8392`. The import block at `:8285-8292` does not import `RoleMarkerRepository`. The class is defined under `App\Modules\Identity\Infrastructure\Repositories` at `:185,5293-5328`.

Following the instruction literally therefore resolves the property type as nonexistent `Database\Seeders\RoleMarkerRepository`. The Phase 1.14 seed commands and preservation test exercise this class at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8399-8415`.

Required correction:

```php
use App\Modules\Identity\Application\Services\PermissionSyncService;
use App\Modules\Tenant\Domain\Exceptions\TenantPermissionSyncFailed;
```

must explicitly join `TenantInitializationService`’s imports, and:

```php
use App\Modules\Identity\Infrastructure\Repositories\RoleMarkerRepository;
```

must join the final seeder import block. The seeder block should preferably show the required injected final form rather than a service-locator form followed by a contradictory transformation instruction.

This is the same namespace-resolution failure class gate r6 rated MAJOR. Rev 6.1 cannot be dispatched while the two affected commits remain red by construction.

## MINOR

### N7-1 — EC-21g’s expanded allow-list still does not describe an executable scan exactly

The root expansion is correct, but EC-21g says it examines every **textual occurrence** of `COMPAT_SCOPE` or `COMPAT_TOKEN` and permits only the declaration, service alias, lock call, or `markerLine()` call at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7514`.

The supplied code contains additional legitimate textual occurrences:

- `self::COMPAT_TOKEN` inside `SyncScope::token()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6211-6215`;
- a service docblock reference at `:6332-6344`;
- a historical command comment containing `COMPAT_SCOPE` at `:7213-7217`.

A raw textual scan using the stated allow-list either fails immediately or must silently implement exemptions not specified by the plan.

Required editorial correction: define this as a PHP code-token/AST scan that ignores comments and strings, permits the `SyncScope::token()` implementation, the declaration and alias, and then rejects every other executable forwarding sink. This is non-blocking by itself because EC-21f independently checks actual SQL bindings at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7513`.

### N7-2 — the rev-6.1 change log contradicts itself about changing an oracle

The change log says no “oracle assertion” was touched and then, in the same paragraph, says EC-21g’s scan was widened at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11098`. The corresponding row explicitly changes its roots and accepted sinks at `:11104`.

Replace “oracle assertion” with “runtime behavioural oracle” or remove it from the unchanged list.

### N7-3 — the import self-review assigns `TenantInitializationService` to the wrong task

The self-review calls it “Task 1.14’s `TenantInitializationService` block” at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11129`. It belongs to Task 1.11, beginning at `:7713`; Task 1.14 is the seeder shim at `:8260-8461`.

## Citation audit

### Import/namespace sample

I parsed all **129** fenced PHP blocks and found exactly **108** namespace-bearing whole-file blocks, matching the plan’s counts at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11110-11114`. All 108 pass `php -l` under PHP 8.4.15.

A 31-block namespace/import-resolution sample was checked:

| Blocks checked | Plan anchors | Result |
|---|---|---|
| `PermissionVerb`, `PermissionDefinition`, `PermissionManifest`, `PermissionRegistry`, migration-progress test | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:435-522,558-791,853-1094` | Resolved; same-namespace exception/value types are legitimate. |
| Product and Dashboard manifests; Product enum | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1848-1890,2894-2917,3661-3692` | Resolved; Dashboard’s unused import is gone. |
| `ReportDeadPermissions`, `SystemRoleTemplate`, `RoleMarkerRepository`, `TemplateDelta`, `TemplateDeltaApplier`, `RoleSyncedV1` | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4816-5141,5293-5437,5481-5537` | Resolved. `PermissionWriteLock` in the applier block is explanatory prose, not an executable class reference. |
| `SyncRollback`, `SyncPlan`, `SyncScope`, `PermissionSyncService` | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5794-5836,5981-6026,6145-6246,6265-7113` | Resolved. `SyncRollback` is same-namespace in the service; `PermissionWriteLock` and `SyncScope` are explicitly imported at `:6271-6284`. |
| `SyncPermissions`, `SyncPermissionsFleet` | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7135-7277,7309-7406` | Resolved. Both import `SyncScope`; both import `Tenant`; fleet imports `FleetTenantOutcome`, `TenantFleetRunner`, and `FleetOutcome`. |
| `TenantPermissionSyncFailed`, `RolesAndPermissionsSeeder` extracted block | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7726-7759,8281-8389` | Extracted blocks resolve. Final fragment/injection assembly does not; see M7-1. |
| Scaffold and both PHPStan rules | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8499-8865,8937-9233,9628-9798` | Resolved. |
| AST route test | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9331-9614` | Resolved. |
| Label exporter and coverage test | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9988-10213` | Resolved. |
| Deploy-table renderer and exact-order test | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10366-10656` | Resolved. |

Every requested named-symbol block was included:

- `SyncScope`: value object, service, both commands and seeder at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6145-6246,6265-7113,7135-7277,7309-7406,8281-8389`.
- `SyncRollback`: declaration and same-namespace service use at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5794-5836,6265-7113`.
- `FleetTenantOutcome`: explicit fleet import/use at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7317-7320,7362-7376`.
- `Tenant`: explicit imports in both commands at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7141-7146,7315-7322`.
- `PermissionWriteLock`: explicit service import/use at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6282,6354-6361`; other whole-block appearances are explanatory docblocks.

### Correct and confirmed

- Current HEAD is `ff2a949e9`; the plan has 11,344 lines.
- Shared `dev`, W-LOT and T2 remain `33796cc08`, `a7010fe4d`, and `208449350`, matching the active reference table at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:38-45`.
- The fleet callback’s rev-5 anchors are correct at `39ecb80f9:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1493,1577-1579,1600`.
- The W-LOT CHECK still permits an unmarked NULL-team row and requires a tenant UUID only for marked rows at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-53`.
- Rev 6.1 did not touch catalogue rows, enums, defaults, deprecations, renames, SoD rows, migration DDL, sync ordering, static-rule implementations, generated frontend files, or deploy YAML. Those code-backed r6 results remain intact.
- The orchestrator’s rev-5/rev-6 wave-0b pin ruling is correctly represented by the dispatch-time ancestry and signature recheck at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:378-395`.

### Wrong or stale

- The claim that every supplied cross-namespace use resolves is too broad once mandatory final-patch instructions are included: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11122-11129`; see M7-1.
- `TenantInitializationService` is Task 1.11, not Task 1.14: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7713,11129`.
- “No oracle assertion … is touched” conflicts with the changed EC-21g oracle: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11098,11104`.
- EC-21g’s “textual occurrence” language and exact allow-list do not cover the supplied valid occurrences at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6214,6338,7214,7514`.

No other active r6 citation was found stale.

## Rejected false positives

- **Do not reject the current wave-0b rev-5 pin merely because waves 2–4 pin rev 6.** The orchestrator requires wave 1 to retain `39ecb80f9` until the dispatch brief, then re-pin to the final accepted wave-0b revision and rerun Phase 0. The plan already makes signature drift a STOP at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:386-395`.
- **Do not reduce the live catalogue to 325.** That is the historical rev-2 count. The accepted T2 whole-wave condition makes the active total 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:95-102`.
- **Do not reopen manifest/default/deprecation/rename/SoD/migration/sync/deploy findings.** The rev-6→6.1 code delta does not touch them; the scope of this round is import resolution and propagation.
- **Do not classify same-namespace references as missing imports.** `SyncRollback` in `PermissionSyncService` and `PermissionDefinition`/`InvalidPermissionCatalogue` inside the shared authorization namespace resolve correctly at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6269,6326,853-995`.
- **Do not demand an import for explanatory docblock text.** `PermissionWriteLock` in the `TemplateDeltaApplier` and `SyncScope` docblocks is not an executable type reference at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5380-5392,6169-6174`.
- **Do not remove `COMPAT_SCOPE`, add compatibility fleet/YAML rows, or add a team predicate to ID-only adoption.** Those round-6 false positives remain rejected at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11108`.
- The named-test residual, class-before-test presentation, in-lane baseline measurements and `payments.reverse` membership remain acceptable in principle. N7-1 concerns one newly edited oracle’s exact scan mechanics, not the general named-test policy.

## Preserve

The corrective round must preserve:

- `SyncScope::tenant()` UUID validation and `SyncScope::compat()` carrying no tenant value at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6176-6208`.
- `token()` for lock/marker scope and `teamValue()` for database/team values at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6211-6224`.
- All four role-team predicates through `applyTeamFilter()`.
- Compatibility steps 1–4 only, one global NULL-team row per template, no `general_manager` creation, EC-21e as a separate expected-BLOCKED case, and `sanctum` fixtures at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7282-7300,7499-7514`.
- The nine settled sync steps, adoption-before-write, canonical grant comparison, exact pristine-template deltas, replacement-only custom-role exception, and `admin = activeKeys()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:97-128`.
- The after-commit cache reset with its team set inside the callback.
- List-valued `LOCK_INHERITED_FROM` for both `TemplateDeltaApplier::applyTo` callers at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:108-114,8140-8147`.
- The W-LOT marked-tenant preservation branch. The missing `RoleMarkerRepository` import must be added without replacing that branch or restoring the service locator.
- The rev-5 wave-0b pin until the dispatch brief, followed by the orchestrator-required final re-pin and Phase 0 recheck.
- All accepted spec-r9 Preserve bindings recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11321`.

## Owner decisions required

No new owner decision is required. M7-1 and the minors are mechanical/editorial corrections.

Existing decisions remain:

- **O-5/OQ-3:** the eighteen-row SoD baseline may be revised; absent a ruling, ship eighteen and ratchet later at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:295-299`.
- **O-6/OQ-4:** decide the production boot default after the soak; this wave ships `SYNC_PERMISSIONS_ON_BOOT=false` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:301-303`.
- **OQ-1/OQ-2:** remain later-wave questions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:305-307`.

## Dispatch assessment

The round-6 fleet import is closed, as are the seeder’s `SyncScope` import, Dashboard’s unused import, and both stale wave-0b anchors. The 108 supplied namespace-bearing blocks are PHP 8.4 syntax-valid, and the 31-block symbol-resolution sample found no unresolved executable reference inside those extracted blocks.

The plan is nevertheless not dispatch-ready because its mechanical pass stopped at those extracted blocks while the dispatched implementation also includes mandatory fragment/follow-up transformations:

- Phase 1.11 omits imports for `PermissionSyncService` and `TenantPermissionSyncFailed`.
- Phase 1.14 omits the import for its required injected `RoleMarkerRepository`.

Those defects make the plan’s own isolated verification lanes red and are materially the same class as gate-r6 M6-1.

After those three imports are made explicit, the remaining EC-21g/changelog/task-number findings are editorial. Dispatch would then remain gated by the already-settled operational entry conditions: wave 0b merged and deployed, T2 merged, W-LOT staging protocol complete, final wave-0b re-pin plus Phase 0 rechecks, and named-test execution discipline at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:51-68,346-400,11137`.

VERDICT: CHANGES-REQUIRED