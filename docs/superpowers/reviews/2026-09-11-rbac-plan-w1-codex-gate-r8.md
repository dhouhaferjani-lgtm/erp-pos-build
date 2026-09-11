# Codex plan gate r8 — RBAC wave 1 plan rev 6.2 (gpt-5.6-sol, high, read-only, 2026-09-11)

Declared worktree HEAD:

```text
git rev-parse --short HEAD
5c59eed45
```

The reviewed plan has exactly **11,460 lines**. The only working-tree modification is an unrelated change to `docs/superpowers/plans/2026-09-10-rbac-wave-2.md`; wave 1 is clean.

Measured references:

```text
shared checkout / dev    33796cc08
lane/w-lot-a-1a          a7010fe4d
lane/t2-receipt-spine    208449350
pinned wave-0b rev 5     39ecb80f9
```

The Git delta `ff2a949e9..5c59eed45` is confined to the reviewed plan: **134 additions / 18 deletions**. Its hunks match the declared rev-6.1→6.2 scope: import instructions for fragments, the final injected seeder form, EC-21g’s code-token wording, R-w1-19, the closure table, and historical wording corrections. It changes no catalogue row, manifest body, enum, template default, deprecation, rename, SoD row, migration DDL, runtime sync behavior, fleet closure, deploy YAML, staging command, or commit subject. The declaration at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11153` is accurate.

## Rev-7 closure table

| Rev-7 finding | Rev-6.2 disposition |
|---|---|
| **M7-1(a) — `TenantInitializationService` lacks imports for `PermissionSyncService` and `TenantPermissionSyncFailed`** | **CLOSED at rev-6.2 anchors.** All three cross-namespace imports are explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7786-7797`, and their uses are at `:7800-7831`. At dev tip, the target imports neither Identity class nor the exception; its existing imports run through `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:7-31`, with `Tenant` at `:17` and `Log` at `:31`. |
| **M7-1(b) — the seeder’s required injected `RoleMarkerRepository` has no import and contradicts the supplied `app()` form** | **CLOSED at rev-6.2 anchors.** The complete block imports the repository at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8333-8341`, injects it at `:8352-8358`, and calls it at `:8433-8442`. The obsolete third `app()` call is gone; only the two documented static shims remain at `:8402-8424,8446`. The full seeder block passes PHP 8.4 syntax checking. |
| **M7-1 class-wide — namespace-only review omitted fragments** | **CLOSED.** Rev 6.1 mechanically contains **129 PHP fences: 108 namespace-bearing blocks and 21 fragments**. Rev 6.2 records all 21 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11166-11194` and adds three import-instruction fences, yielding the correctly stated current total of **132 / 108 / 24**. |
| **N7-1 — EC-21g’s “textual” scan has an impossible allow-list** | **CLOSED.** EC-21g is now a code-token scan that removes comments, docblocks, string literals and inline HTML, then classifies the four permitted executable contexts at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7529-7538`. This covers the real constant declaration/read/alias shapes at `:6198-6229,6353-6360` without silently exempting the historical comments. |
| **N7-2 — rev-6.1 change log says no oracle changed while changing EC-21g** | **CLOSED.** The historical unchanged set now says **runtime behavioural oracle**, while expressly identifying EC-21g as the changed static oracle at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11208-11218`. |
| **N7-3 — `TenantInitializationService` assigned to Task 1.14** | **CLOSED.** The active fragment is correctly under Task 1.11 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7737-7836`; the historical self-review is corrected and marked superseded at `:11243-11245`. Task 1.14 remains the seeder at `:8310`. |
| **Citation audit items 1–4** | **CLOSED except one new endpoint-only citation minor, N8-1.** The whole-document resolution claim is narrowed to namespace-bearing blocks at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11236-11245`; task numbering, oracle terminology and EC-21g wording are corrected. N8-1 does not invalidate symbol resolution. |
| **Round-7 blockers** | **NONE.** No blocker existed to close. |
| **Round-7 rejected false positives and Preserve list** | **REJECTED-correctly / preserved.** The rev-6.2 delta leaves every item enumerated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11164` intact. |

## Rev-1 closure table

The historical rev-1→rev-2 register remains accurate against the code-backed closure record at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11408-11437`. Its **325** count is historical; the accepted T2 whole-wave condition subsequently and correctly raises the live total to **327** at `:325-342`.

| Rev-1 finding | Current disposition |
|---|---|
| **B1-1 — stale 323-key arithmetic** | **CLOSED at rev 2; correctly superseded to 327.** Historical evidence and the live T2 derivation are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11414,325-342`. |
| **B1-2 — incomplete manifests/red incremental commits** | **CLOSED.** Complete manifests plus the shrink-only unassigned fixture are recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11415`; rev 6.2 does not touch them. |
| **B1-3 — unconditional template-version write** | **CLOSED.** Conditional version accounting remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5342,5420-5434,11416`. |
| **B1-4 — stopgap deletion separated from sync** | **CLOSED.** The atomic Phase 1.10 disposition remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11417`. |
| **B1-5 — sync before migration** | **CLOSED.** Migration→dry-run→apply remains the generated sequence at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11092-11104,11418`. |
| **B1-6 — undefined tenant-wide actor** | **CLOSED.** The explicit second parameter is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5564-5572,11419`. |
| **B1-6a — nullable company assigned to a string property** | **CLOSED.** The required `$companyId ?? ''` correction remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5547-5551,11420`. |
| **B1-7 — implementation placeholders** | **CLOSED as the original completeness finding.** The supplied implementation inventory is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11421`. |
| **M1-1 — invalid commit subjects** | **CLOSED.** The `Phase 1.<task>:` requirement remains recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11422`. |
| **M1-2 — missing exact staging lists** | **CLOSED.** Exact `git add`/`git rm`/`git mv` treatment remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11423`. |
| **M1-3 — invalid inherited-lock proof** | **CLOSED subject to the final 0b re-pin.** The AST census and list-valued `LOCK_INHERITED_FROM` contract remain at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8186-8196,11424`. |
| **M1-4 — nondeterministic scaffold** | **CLOSED.** The five-artifact deterministic contract remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11425`. |
| **M1-5 — deploy test checks presence, not order** | **CLOSED.** Exact equality remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10342-10350,11426`. |
| **M1-6 — incomplete PG/PHPStan/Pint commands** | **CLOSED.** Explicit harness and path requirements remain at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11427`. |
| **Minor — stale catalogue count prose** | **CLOSED and superseded to 327.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11428`. |
| **Minor — overbroad PHPStan/routes explanation** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11429`. |
| **Minor — YAML trailing whitespace** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11430`. |
| **Citation — stale ref register** | **CLOSED historically; active remeasurement remains mandatory.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:346-395,11431`. |
| **Citation — stale source anchors** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11432`. |
| **Cross-plan — selector provider absent** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11433`. |
| **Cross-plan — rollback sentinel deleted without replacement** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11434`. |
| **Cross-plan — stopgap not removed atomically** | **CLOSED.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11435`. |
| **Cross-plan — scanner/marker signature shapes** | **CLOSED subject to dispatch re-pin.** See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:362-395,11436`. |
| **Cross-plan — DateFactory substitution** | **REJECTED-correctly.** It remains deliberate and bounded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8166-8173`. |
| **Cross-plan — marked-tenant seeder branch** | **REJECTED-correctly.** The branch remains binding at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:317-323,8360-8400`. |
| **Cross-plan — three tenant states / Convention 09** | **REJECTED-correctly.** The independent cases remain at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:276-287`. |
| **Spec-r9 Preserve register** | **HONOURED.** Its carried binding is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11437`. |

## BLOCKER

None.

## MAJOR

None.

Both halves of M7-1 are symbol-resolved, and the mandatory full seeder block is PHP 8.4 syntax-valid. No rev-6.2 edit makes a task red by construction.

## MINOR

### N8-1 — `AppServiceProvider`’s import-block endpoint is cited as line 40, but it ends at line 77

The new fragment instruction says the target’s import block is `dev:apps/api/app/Providers/AppServiceProvider.php:7-40` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1000`; the fragment table repeats that shortened endpoint at `:11175`.

At the measured dev tip `33796cc08`, line 40 is merely `CompanyConfigService`. Imports continue through `Laravel\Sanctum\Sanctum` at `apps/api/app/Providers/AppServiceProvider.php:41-77`, and the class begins at `:79`.

This is editorial only. The substantive claim remains correct: neither `PermissionManifest` nor `PermissionRegistry` exists anywhere in the complete current import block, and both required imports are explicitly supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1001-1004`.

Dispatch-brief edit: replace both `:7-40` citations with `:7-77`.

## Citation audit

### Six corrected fragment units

| Corrected unit | Code verification |
|---|---|
| Module-provider tagging | The example now states both import and tag at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:814-820`. The real provider exists and has `register()` at `dev:apps/api/app/Modules/Procurement/Providers/ProcurementServiceProvider.php:5-24`; it is registered at `dev:apps/api/bootstrap/providers.php:36,115`. |
| `AppServiceProvider::register()` / `boot()` | Both new imports are stated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1000-1019`. The target currently imports neither across its real import block, `dev:apps/api/app/Providers/AppServiceProvider.php:7-77`. N8-1 is only the cited endpoint. |
| `DomainEventSubscriber` method and registration fragments | `RoleSyncedV1` and `Throwable` are explicitly supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5598-5606`; their uses are at `:5638,5647,5661-5664`. Current code already imports `DomainEvent` and `Log` at `dev:apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:63,66`, while its only current throwable spelling is `\Throwable` at `:1216`. The either/or note is correct. |
| `TenantInitializationService` | The three required imports and all three uses agree at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7786-7831`; the current target import block confirms none existed at `dev:apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:7-31`. |
| `RoleController::update()` | The fragment uses existing `DB`, `Role` and `PermissionRegistrar` plus new `DateFactory` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8005-8042,8165-8172`. The existing lane imports are at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:7-31`. |
| `RoleController::reapplyTemplate()` | All six wave-1 imports, including load-bearing `TemplateDelta`, are explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8046-8147,8163-8173`. `PermissionWriteLock` is correctly omitted because 0b prescribes its import and `$permissionWriteLock` property at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3003-3017`. The property-token difference is correctly deferred as R-w1-19 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11088,11196`. |

### Five additional fragment spot checks

| Fragment | Result |
|---|---|
| Per-module provider template | **Resolved.** The matching-import instruction is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1226-1239`. The Replenishment exception is real: its current provider has only `boot()` at `dev:apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:14-21`. |
| `AuditService::recordTenantWide()` | **Resolved.** `AuditEvent` is already imported at `dev:apps/api/app/Modules/Compliance/Services/AuditService.php:8`; the fragment returns and constructs it at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5564-5595`. |
| `HealthCheckService` reader | **Resolved.** The fragment introduces only native functions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7914-7946`; the real target’s check array and aggregate are at `dev:apps/api/app/Modules/Admin/Application/Services/HealthCheckService.php:19-31`. |
| Identity route fragment | **Resolved.** `IdentityPermission` is supplied by the fragment; `RoleController` and `Route` already exist at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/routes.php:6,10`. See `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8149-8157`. |
| Frontend-map exporter fragments | **Resolved.** The registry import replacement is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9921-9956`; current `Filesystem` and `LogicException` imports are at `dev:apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:9-10`, supporting both that fragment and `render()` at plan `:9960-10031`. |

### Correct and confirmed

- Rev 6.1 contains exactly 129 PHP fences, 108 namespace-bearing blocks and 21 fragments; rev 6.2 adds the three import-instruction fences described at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11166-11194`.
- The seeder, migration and baseline-generator whole-file blocks at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8328-8444,5153-5268,9294-9352` pass PHP 8.4 syntax checking.
- The ref register’s measured tips match the shared checkout at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:38-45`.
- R-w1-19 correctly records a real cross-plan token mismatch: 0b prescribes `$permissionWriteLock` at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3003-3017`, while wave 1 uses `$writeLock` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8012-8015,8091-8093,8161`. Its dispatch-time reconciliation is appropriately mandatory.

### Wrong or stale

- Only N8-1: `AppServiceProvider.php:7-40` should be `AppServiceProvider.php:7-77` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1000,11175`.

No other reviewed rev-6.2 fragment citation is wrong or stale.

## Rejected false positives

- **Do not demand a second wave-1 `PermissionWriteLock` import.** Wave 0b owns that import and dependency at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3003-3017`; duplicating the imported short name would be invalid. Wave 1 correctly adds only its six new imports at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8163-8172`.
- **Do not elevate R-w1-19 to a blocker.** It is a one-token property-name reconciliation against a still-moving 0b producer, explicitly guarded by the final re-pin and PHPStan run at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11088,11196`.
- **Do not reduce the live catalogue to 325.** That is rev-2 history. The accepted T2 condition makes the live catalogue 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:325-342`.
- **Do not reopen catalogue/default/deprecation/rename/SoD/migration/sync/fleet/deploy findings.** The Git delta does not touch those regions; the narrow rev-6.2 scope is accurately declared at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11153`.
- **Do not reject the named-test residual.** It remains execution discipline, not missing architecture: R-w1-13 records the bounded residual at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11082`.
- **Do not reject the current 0b rev-5 pin merely because 0b remains in a fix round.** Dispatch must re-pin to the final accepted 0b revision and stop on signature drift, as required at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:378-395`.

## Preserve

The dispatch must preserve:

- The accepted r9 bindings carried at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11437`.
- `SyncScope::tenant()` UUID validation, compatibility carrying no tenant UUID, and the distinct token/team accessors at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6176-6229`.
- The nine settled sync steps, adoption-before-write, canonical comparison, exact pristine-template diff, custom-role replacement-only exception and `admin = activeKeys()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:97-128`.
- The after-commit cache reset with team context set and restored inside the callback.
- List-valued `LOCK_INHERITED_FROM` for both callers of `TemplateDeltaApplier::applyTo` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8186-8196`.
- The marked-tenant preservation branch and `WLOTA1A-RESEED` marker at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8360-8400`.
- EC-21e as a distinct expected-BLOCKED case and `sanctum` throughout the compatibility fixtures at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7518-7528`.
- The final 0b re-pin, signature verification and STOP-on-drift rule at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:378-395`.

## Owner decisions required

No new owner decision is introduced by rev 6.2 or by this gate.

Existing decisions remain:

- **O-5/OQ-3:** absent an owner ruling, ship the eighteen-row SoD baseline and ratchet later; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:295-299`.
- **O-6/OQ-4:** decide the production boot default after the soak; wave 1 ships `SYNC_PERMISSIONS_ON_BOOT=false`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:301-303`.
- **OQ-1/OQ-2:** remain later-wave questions; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:305-307`.

## Dispatch assessment

Rev 6.2 closes round-7’s only major in both affected classes and closes all three round-7 minors. The complete fragment pass is arithmetically correct, the six corrected fragment units resolve against their target namespaces/imports, five additional fragments were spot-checked successfully, and the changed whole-file PHP blocks are syntax-valid.

N8-1 is an endpoint-only citation correction. It does not obscure whether the required symbols are present, change an implementation instruction, or make an isolated commit red. Per the round-8 rule, a revision with only MINOR findings is dispatch-ready.

Dispatch remains conditional on:

1. Wave 0b being merged **and deployed successfully**.
2. T2 being merged into `dev`.
3. The W-LOT staging protocol being complete.
4. Re-pinning to the final accepted wave-0b revision, rerunning Phase 0, and reconciling `$writeLock` versus `$permissionWriteLock`.
5. Treating the named-test residual as execution discipline.

These conditions are explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:51-68,346-405,11082,11088,11202`.

Dispatch-brief editorial list:

- Correct `AppServiceProvider.php:7-40` to `AppServiceProvider.php:7-77` at plan lines `1000` and `11175`.
- At the final 0b re-pin, adopt the property name actually shipped by 0b and prove the assembled controller with Task 1.12’s PHPStan command, per R-w1-19 at plan lines `11088` and `11196`.

VERDICT: DISPATCH-READY