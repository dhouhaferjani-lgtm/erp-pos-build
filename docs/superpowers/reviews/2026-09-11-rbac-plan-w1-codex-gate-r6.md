# Codex plan gate r6 — RBAC wave 1 plan rev 6 (gpt-5.6-sol, high, read-only, 2026-09-11)

Declared worktree HEAD:

```text
git rev-parse --short HEAD
177b3d7d7
```

Other re-measured refs:

```text
shared checkout          33796cc08
dev                      33796cc08
lane/w-lot-a-1a          a7010fe4d
lane/t2-receipt-spine    208449350
wave 0b rev 5 plan       39ecb80f9
```

Reviewed plan length: **11,289 lines**.

The rev-6 `SyncScope` design and compatibility guard correction close gate r5’s blocker and major. One propagation omission remains in the supplied fleet command: `SyncScope` is used without being imported. This makes Phase 1.10 fail static analysis/runtime resolution and prevents dispatch as written.

## Rev-1 closure table

| Rev-1 finding | Rev-6 disposition |
|---|---|
| **B1-1 — stale 323-key arithmetic** | **CLOSED and superseded.** Rev 2 correctly established 325; the subsequently accepted T2 whole-wave entry condition raises the live total to 327. The distinction is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:53-56,95-102,11243-11244`. |
| **B1-2 — incomplete manifests and red incremental history** | **CLOSED.** The historical closure records the complete assignment and shrink-only unassigned fixture at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11244`; the active nine-commit migration starts at `:1122` and the complete assignment at `:1297`. |
| **B1-3 — unconditional `template_version` update** | **CLOSED.** The active applier gates the update on `$versionChanged` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5334-5427`; the historical closure and query-log requirements remain at `:11245`. |
| **B1-4 — stopgap deletion separated from sync landing** | **CLOSED.** Phase 1.10 supplies the sync, both commands, replacement rollback sentinel and all eight deletions as one commit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5683-5689,7406-7425,7566-7607`. |
| **B1-5 — sync precedes its migration** | **CLOSED.** The generated sequence is migrate → dry run → apply → cache reset → export at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11033-11043`; the historical closure is accurate at `:11247`. |
| **B1-6 — undefined audit actor** | **CLOSED.** `recordTenantWide()` takes the actor explicitly as its second parameter at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5544-5587`; callers pass it by name at `:5600-5621`. |
| **B1-6a — nullable company assigned to a non-nullable property** | **CLOSED.** The required `$companyId ?? ''` change is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5539-5543`; historical closure is at `:11249`. |
| **B1-7 — implementation left as prose/placeholders** | **CLOSED as the original completeness finding.** Full implementations remain supplied across Tasks 1.4–1.18 and are catalogued at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11250`. The missing fleet import below is a new correctness/propagation defect, not a reopening of the original placeholder finding. |
| **M1-1 — invalid commit subjects** | **CLOSED.** The plan uses `Phase 1.<task>:` subjects, including `Phase 1.4a`–`1.4i`; the closure is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11251`. |
| **M1-2 — no exact staging lists** | **CLOSED.** Every task has an explicit staging list; Phase 1.10’s is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7566-7595`. |
| **M1-3 — invalid inherited-lock proof** | **CLOSED subject to Phase 0’s producer check.** `TemplateDeltaApplier::applyTo` remains list-valued under `LOCK_INHERITED_FROM`, with both entry points named at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:103-112`; the 0b contract is pinned at `39ecb80f9:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8192-8203`. |
| **M1-4 — scaffold output underspecified** | **CLOSED.** The five deterministic-output rules and full implementation remain in Task 1.15 beginning at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8459`; historical disposition at `:11254`. |
| **M1-5 — deploy test checks presence, not order** | **CLOSED.** Exact ordered-list and rendered-table equality remain in Task 1.18 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10245-10687`; the deployed table is at `:11033-11043`. |
| **M1-6 — incomplete PG/PHPStan/Pint commands** | **CLOSED generally.** Explicit PG, PHPStan and Pint commands remain present, including Phase 1.10 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7523-7543`. Those commands now correctly expose M6-1 below. |
| **minor — stale 323 task prose** | **CLOSED and superseded.** The active catalogue count is 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:95-102,399-403`; the rev-2 325 figure is retained only as qualified history at `:11243-11244,11257`. |
| **minor — overbroad “PHPStan cannot see route files”** | **CLOSED.** The distinction between module routes under `app/` and top-level routes remains in Task 1.16b beginning at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9309`; historical disposition at `:11258`. |
| **minor — YAML trailing space** | **CLOSED.** The authority is structured YAML rendered into the human table, not the old scalar row; see `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10245-10293,11259`. |
| **cross-plan — broken admin baseline** | **CLOSED by wave 0b.** Phase 0 requires all nineteen admin additions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:394-398`; the producer preserves both predicates and the addition map at `39ecb80f9:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8198-8200`. |
| **cross-plan — missing selector provider** | **CLOSED.** Wave 1 appends the `permissions:sync-fleet` provider row at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7514-7518`; the producer contract is at `39ecb80f9:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8201-8203`. |
| **cross-plan — rollback sentinel deleted without replacement** | **CLOSED.** `SyncRollback` lands in the same Phase 1.10 staging set as the deletion at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7566-7579,7602-7611`. |
| **cross-plan — stopgap not removed atomically** | **CLOSED.** The eight-file removal and replacement command landing remain one Phase 1.10 commit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7406-7425,7566-7607`. |
| **cross-plan — scanner/marker shapes** | **CLOSED.** The five consumed 0b shapes are pinned at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:25-34`; Phase 0 verifies them and stops on drift at `:360-393`. |
| **cross-plan — `DateFactory` substitution** | **REJECTED-correctly.** The repository-aligned substitution remains documented at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6261`. |
| **cross-plan — seeder preservation branch** | **REJECTED-correctly.** The marked-tenant preservation ruling remains binding at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:315-320,8306-8346`. |
| **cross-plan — three tenant states / Convention 09** | **REJECTED-correctly.** The plan retains the required tenant-state, second-database and second-location coverage; representative active requirements are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:271-278,7487-7519`. |
| **citation — stale ref register** | **CLOSED.** The recorded rev-5 tips still match the shared checkout: `dev 33796cc08`, W-LOT `a7010fe4d`, T2 `208449350`; see `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:36-45`. |
| **citation — stale controller/provisioning/entrypoint/audit anchors** | **CLOSED.** The corrected source-specific anchors remain in the active tasks and historical closure at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11261`. |

The historical rev-1 → rev-2 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11237-11266` remains substantively accurate. Its 325-key statements are explicitly historical; the live 327-key figure is an accepted later supersession, not a rev-1 closure regression.

## Rev-5 closure table

| Rev-5 finding | Rev-6 disposition |
|---|---|
| **B5-1 — compatibility mode binds `compat` to a UUID team column** | **CLOSED.** `SyncScope::tenant()` validates through `Str::isUuid()` and `compat()` carries no tenant value at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6175-6208`. `token()` and `teamValue()` are separated at `:6210-6224`; the sole raw `orWhere($team, …)` and both `whereNull($team)` branches live inside `applyTeamFilter()` at `:6234-6244`. The service accepts `SyncScope`, not a string, at `:6358`; all four team-filtered role statements call `applyTeamFilter()` at `:6932-6937,6955-6968,7026-7037,7076-7097`. |
| **M5-1 — compatibility fixtures use guard `web` while production reads `sanctum`** | **CLOSED.** The service’s guard is `sanctum` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6325-6329`; the fixture, count invariants and duplicate case all specify `sanctum` at `:7494-7509`. Dev code confirms the same guard in `apps/api/database/seeders/RolesAndPermissionsSeeder.php:34-40,562-568` and `apps/api/app/Modules/Identity/Domain/User.php:284-290`. |
| **minor — producer still pinned to wave 0b rev 4** | **CLOSED.** Active contracts are pinned to rev 5 `39ecb80f9` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:25-34`; Phase 0 checks both ancestry and `scripts/census_0b.py` at `:376-390`. A later 0b revision is explicitly handled as a re-check-and-reconcile condition rather than silently consumed. |
| **r5 dispatch consequence — EC-21/21a/21e unreachable and Phase 1.10 red** | **CLOSED as to the r5 causes.** EC-21f verifies the SQL and bindings, while EC-21g verifies constructor refusal and accessor separation at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7510-7511,10813-10815`. Phase 1.10 nevertheless remains red for the distinct M6-1 import omission below. |

The rev-5 → rev-6 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11090-11112` is correct about the scope design, compatibility CHECK, guard correction and producer re-pin. It is incomplete where it claims the r5 corrections restore Phase 1.10 dispatchability: the fleet command did not receive the corresponding `SyncScope` import.

## BLOCKER

None.

The value object closes the PostgreSQL UUID blocker structurally. No raw `$tenantId` survives in a `roles` team predicate; the remaining `$tenantId` variables are used for audit scope, cache namespace, marker output or tenant-owned controller work, not as compatibility team operands (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6703-6712,6904-6927,7217-7229,7359-7372`).

## MAJOR

### M6-1 — the fleet command uses `SyncScope` without importing it

The supplied `SyncPermissionsFleet` namespace/import block includes `PermissionSyncService`, the fleet DTO/service/enums, `Tenant`, `Command` and `Log`, but it does **not** import `App\Modules\Identity\Application\DTOs\SyncScope`:

```php
namespace App\Console\Commands;

use App\Modules\Identity\Application\Services\PermissionSyncService;
use App\Modules\Tenant\Application\DTOs\FleetTenantOutcome;
// ...
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7312-7320`.

The callback then executes:

```php
$scope = SyncScope::tenant($tenantId);
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7359-7364`.

Under this namespace PHP resolves that reference as `App\Console\Commands\SyncScope`, which does not exist. The required DTO lives at the PSR-4 path named in the plan, `apps/api/app/Modules/Identity/Application/DTOs/SyncScope.php` (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:179-187,6137-6246`).

Consequences:

- The `permissions:sync-fleet` path cannot execute the new scope constructor.
- The staging entrypoint’s dry-run/apply sequence cannot be green because it invokes this command (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11049-11057`).
- Phase 1.10’s own PHPStan command explicitly analyses `SyncPermissionsFleet.php`, so the promised green commit fails its stated lane (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7525-7542`).
- The rev-6 change log’s claim that the scope is threaded through the fleet call site is therefore incomplete (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11096,11110`).

Required correction: add

```php
use App\Modules\Identity\Application\DTOs\SyncScope;
```

to the supplied `SyncPermissionsFleet` import block and call it out in the rev-5 → rev-6 change log.

## MINOR

1. **EC-21g’s static oracle claims a wider scan than it specifies.** It says to prove that no production file under `app/Modules/Identity` passes `COMPAT_SCOPE` or `COMPAT_TOKEN` to an invalid sink, but then says this is asserted “by reading the two command sources,” which live under `app/Console/Commands` and cannot establish the stated module-wide property (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7511`). The structural value object plus EC-21f still cover the live compatibility path, so this is non-blocking. Make the test actually scan the stated production roots or narrow the claim to the two commands and service.

2. **One active fleet comment retains rev-4 line citations.** The comment above the corrected callback still says `0b rev 4 :1486,1570,1583` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7355-7358`, while the active producer is rev 5 and the re-measured anchors are `39ecb80f9:…:1493,1577-1579,1600` (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:25-32`). This contradicts the “every active anchor re-measured” claim at `:11101`, but it is editorial and the cited contract is unchanged.

## Citation audit

### Correct and confirmed

- Worktree HEAD is `177b3d7d7`; the target plan has 11,289 lines.
- The shared checkout and `dev` are `33796cc08`; W-LOT is `a7010fe4d`; T2 is `208449350`. These match the recorded rev-5 reference table at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:36-43`.
- `roles.tenant_id` is a nullable UUID at `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:33-44`.
- Installed Laravel provides `Str::isUuid()` at `apps/api/vendor/laravel/framework/src/Illuminate/Support/Str.php:640-666`.
- `Tenant` uses `HasUuids` at `apps/api/app/Modules/Tenant/Domain/Tenant.php:62-70`; the central schema declares `tenants.id` as UUID at `apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:14-18`. Deriving a scope from `(string) $tenant->id` is therefore valid.
- Raw-shape counts are correct: the plan has one `orWhere($team, …)` and two `whereNull($team)` occurrences in executable PHP, all inside `SyncScope::applyTeamFilter()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6234-6244`. The service has exactly four `applyTeamFilter()` calls at `:6937,6966,7031,7090`.
- The adoption update intentionally has no team predicate because its IDs originate in the scope-filtered locked read in the same transaction (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6731-6741`). This is not a raw-team propagation miss.
- Compatibility returns after adoption and before role creation at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6515-6535`; `general_manager` therefore cannot be created under compatibility.
- The W-LOT lane CHECK is exactly `provisioning_source IS NULL OR (<team> IS NOT NULL AND source/name/guard match)` at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-53`. An unmarked NULL-team row is legal, while the marked creation path uses a UUID team value.
- The `SyncScope`, service and per-tenant command snippets are PHP 8.4 syntax-valid. The fleet snippet is also syntactically valid, but its missing class import is a symbol-resolution defect rather than a parser error.
- The catalogue, manifest rows/bodies, enums, defaults, deprecations, renames, SoD rows, migration DDL, static guards and deploy YAML were not modified by the rev-5 → rev-6 delta. The previous code-backed 327-key verification therefore remains undisturbed, as recorded at `docs/superpowers/reviews/2026-09-10-rbac-plan-w1-codex-gate-r5.md:133-142` and acknowledged by the rev-6 log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11104`.
- Replenishment’s provider exists at `apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:14-21` and is registered at `apps/api/bootstrap/providers.php:41,116`; rev 6 does not disturb the supplied `register()` addition.
- The six `legacy()` exceptions, `LEGACY_ACTION_CEILING = 59`, 22 null replacements, eleven renames and eighteen SoD rows remain unchanged from the prior verified revision; their historical derivation is preserved at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11272-11281`.
- The rev-6 compatibility fixture uses the actual repository guard: `RolesAndPermissionsSeeder` creates permissions and roles on `sanctum` at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:34-40,562-568`, and `User::getDefaultGuardName()` returns it at `apps/api/app/Modules/Identity/Domain/User.php:284-290`.

### Wrong or stale

- Missing `SyncScope` import in the supplied fleet command: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7312-7320`, used at `:7361`.
- The module-wide EC-21g static-scan claim does not match its stated two-command implementation: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7511`.
- The active fleet callback comment still cites rev-4 line numbers rather than the pinned rev-5 anchors: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7355-7358`.
- Consequently, the absolute claim that every active 0b anchor was re-measured is slightly overstated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11101`.

## Rejected false positives

- **Do not reduce the catalogue back to 325.** That was correct at rev 2. The live 327 total follows from the accepted T2 whole-wave entry condition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:53-56,95-102`. Seeing 325 at dispatch is an unmet T2 entry condition, not a manifest correction.
- **Do not report current failure of `git merge-base --is-ancestor 39ecb80f9 dev` as a wave-1 design defect.** Wave 0b is not yet merged in the shared checkout; Phase 0 explicitly treats ancestry failure as a dispatch-time STOP and re-checks a concurrently produced later 0b revision at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:384-393`.
- **Do not restore two NULL-team role copies.** Compatibility deliberately owns one shared NULL-team template set; duplicate coverage remains a separate expected-BLOCKED EC-21e case at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7494-7509`.
- **Do not add a compatibility fleet or deploy-YAML row.** Compatibility is one global command and is deliberately refused by `permissions:sync-fleet` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7281-7303,7341-7349`.
- **Do not remove `COMPAT_SCOPE`.** Preserving it as an alias of `SyncScope::COMPAT_TOKEN` is correct; it is a lock/marker token, not a database value (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6331-6344`).
- **Do not add team predicates to ID-only adoption writes.** The IDs were resolved through the scope-filtered locked query in the same transaction, and the plan documents that invariant at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6731-6741`.
- **Do not classify audit/cache `$tenantId` variables as raw roles-query operands.** Their domains are explicitly audit scope and cache namespace at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6707-6712,6904-6927`.
- The previously rejected findings remain correctly rejected: raw post-rename reads, tenant restoration snapshot, Neon project-root handling, after-commit callback, report-dead suffix threat model, `payments.reverse` SoD membership, class-before-test presentation order, and in-lane PHPStan/localization measurements.

## Preserve

The correction for M6-1 must be limited to importing `SyncScope`; it must preserve:

- `SyncScope::tenant()` validation and `SyncScope::compat()` carrying no tenant value.
- `token()` only for the lock subject, audit/marker scope and marker output.
- `teamValue()` for the Spatie team and `roles` inserts.
- All four role team predicates through `applyTeamFilter()`.
- One global NULL-team role row per template in compatibility mode.
- EC-21e as a separate expected-BLOCKED duplicate case.
- Compatibility steps 1–4 only, with no role creation, marker write, template delta, replacement or admin rewrite.
- `general_manager` never being created under compatibility.
- `sanctum` across production and EC-21 fixtures.
- Compatibility fleet refusal and absence from the production deploy YAML.
- Both migration partial uniques and the W-LOT CHECK.
- The nine settled sync steps, adoption-before-write, canonical set comparison, exact template deltas, `admin = activeKeys()`, and after-commit cache reset with the team set inside the callback.
- List-valued `LOCK_INHERITED_FROM` for `TemplateDeltaApplier::applyTo`.
- The accepted r9 Preserve bindings summarized at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11266`.

## Owner decisions required

No new owner decision is introduced. M6-1 and the two minors are mechanical corrections.

Existing decisions remain:

- O-5/OQ-3 may revise the eighteen-row SoD baseline; otherwise the defined fallback ships eighteen and ratchets later (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:293-297,11019`).
- O-6/OQ-4 is made after the soak; the wave ships `SYNC_PERMISSIONS_ON_BOOT=false` (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:299-301,11020`).
- OQ-1/OQ-2 remain later-wave questions (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:303-305`).

## Dispatch assessment

The r5 blocker and major are closed at their causes:

- Compatibility no longer compares `compat` with a UUID.
- Every current roles-team predicate is centralized in `applyTeamFilter()`.
- Tenant scopes are UUID-validated and the fleet derives them from a UUID-backed `Tenant::$id`.
- Compatibility returns before step 5, so it never creates `general_manager`.
- EC-21 fixtures use and assert the repository’s `sanctum` guard.
- Wave 0b rev 5 is pinned with a dispatch-time ancestry/signature re-check.

However, Phase 1.10 is not green in isolation because the supplied fleet command cannot resolve `SyncScope`. This affects the primary fleet path and the staging entrypoint’s dry-run/apply sequence, and its own stated PHPStan command catches it. The one-line import must be added before dispatch.

The declared residuals remain acceptable after that correction:

- Named-but-unpasted tests are an execution-discipline note, not a dispatch blocker, because their fixtures and assertions are specified (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11024`).
- Machine-derived template defaults remain acceptable and unchanged.
- PHPStan literal-baseline and localization-gap counts may be measured in-lane.
- Class bodies appearing before test bodies remain an execution-order note.
- `payments.reverse` remains deliberately included in the payment SoD group.
- Real dispatch still requires wave 0b merged and deployed, T2 merged, and the W-LOT staging protocol complete (`docs/superpowers/plans/2026-09-10-rbac-wave-1.md:49-68,11112`).

VERDICT: CHANGES-REQUIRED