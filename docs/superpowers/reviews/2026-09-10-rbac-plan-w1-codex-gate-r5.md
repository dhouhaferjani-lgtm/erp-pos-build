# Codex plan gate r5 — RBAC wave 1 plan rev 5 (gpt-5.6-sol, high, read-only, 2026-09-10)

Declared worktree HEAD:

```text
git rev-parse --short HEAD
2d3faa28b
```

Reviewed plan length: **10,963 lines**.

Re-measured refs:

```text
dev                     33796cc08
lane/w-lot-a-1a         a7010fe4d
lane/t2-receipt-spine   208449350
wave 0b rev 5           39ecb80f9
```

Neither W-LOT nor T2 is currently an ancestor of `dev`, matching the entry-condition state recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:36-39,45-52`.

## Rev-1 closure table

| Rev-1 finding | Rev-5 disposition |
|---|---|
| **B1-1 — stale 323-key arithmetic** | **CLOSED and superseded.** Rev 2 established 325; the accepted T2 whole-wave condition raises the live total to 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:51,93`. |
| **B1-2 — incomplete manifests and red incremental history** | **CLOSED.** The shrink-only mechanism is supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:795-810`; all nine green increments and ceilings are at `:1266-1279`; the complete manifest migration occupies `:1286-3330`. |
| **B1-3 — unconditional `template_version` update** | **CLOSED.** `$versionChanged` gates the write at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5397-5411`; query-log zero-write evidence is required at `:5429` and `:278`. |
| **B1-4 — stopgap deleted after sync rather than atomically** | **CLOSED.** Phase 1.10 contains the service, commands, successor sentinel and stopgap removal in one commit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5668,7334-7378`. |
| **B1-5 — sync precedes migration** | **CLOSED.** The global rule is migration-first at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:132`; the generated deploy table is migrate → dry run → apply at `:10737-10744`. |
| **B1-6 — undefined audit actor** | **CLOSED.** `recordTenantWide()` takes the explicit actor second at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5529-5558`. |
| **B1-6a — nullable company assigned to non-nullable property** | **CLOSED.** The `$companyId ?? ''` assignment is retained in Task 1.9 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5506-5521`. |
| **B1-7 — production implementation left as prose/placeholders** | **CLOSED as an implementation-completeness finding.** Full bodies remain supplied across Tasks 1.4–1.18, summarized at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10924`. The compatibility runtime defects found below are correctness defects, not a reopening of the original placeholder finding. |
| **M1-1 — invalid commit subjects** | **CLOSED.** The manifest subjects are concrete `Phase 1.4a`…`1.4i` subjects at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3519-3531`; the rule is reiterated at `:10581`. |
| **M1-2 — no exact staging lists** | **CLOSED.** Exact Phase 1.4 staging starts at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3335`; Phase 1.10’s exact list is at `:7334-7362`. |
| **M1-3 — invalid inherited-lock proof** | **CLOSED subject to the wave-0b producer entry condition.** The list-valued two-caller contract is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:97-108,7885-7886`. Wave-0b rev 5 preserves `collect()`, `methodSource()` and `callersOf()` at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3112,3176,3202`. |
| **M1-4 — scaffold output underspecified** | **CLOSED.** Task 1.15 supplies the deterministic scaffold implementation and artifact contract at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8040-8615`. |
| **M1-5 — deploy test checks presence rather than order** | **CLOSED.** Exact-order and byte-equal validation are supplied at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9981-10028,10173-10399`. |
| **M1-6 — incomplete PG/PHPStan/Pint commands** | **CLOSED.** Phase 1.10 uses the PG environment and `-c phpunit-pgsql.xml` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7301-7318`; final explicit lanes are at `:10476-10541`. |
| **minor — stale 323 task prose** | **CLOSED and superseded to 327.** Current task title and progression are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1107,1266-1279`. |
| **minor — overbroad “PHPStan cannot see route files”** | **CLOSED.** The rule now distinguishes analyzed module routes from top-level routes at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9018-9030`. |
| **minor — YAML trailing space** | **CLOSED.** The deploy source is structured `{command, note}` YAML and exact-rendered at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10003-10028`. |
| **cross-plan — broken admin baseline** | **CLOSED by wave 0b.** Wave 1 consumes the distinct baseline predicates at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:23-25,379-382`; wave-0b rev 5 preserves their signatures at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8198-8200`. |
| **cross-plan — missing selector provider** | **CLOSED.** Wave 1 appends its fleet row at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7290-7294`; wave-0b rev 5’s provider contract is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:8201-8203`. |
| **cross-plan — rollback sentinel deleted without replacement** | **CLOSED.** `SyncRollback` and atomic deletion are bound at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:127-129,5668,7334-7378`. |
| **cross-plan — stopgap not removed with sync** | **CLOSED.** Same Phase 1.10 anchors: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5668,7334-7378`. |
| **cross-plan — scanner/marker shapes** | **CLOSED, but the live producer pin is stale; see MINOR.** The consumed shapes are enumerated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:23-30,353-378`. |
| **cross-plan — `DateFactory` substitution** | **REJECTED-correctly.** It remains an explicit repository-aligned substitution at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6114-6116`. |
| **cross-plan — seeder preservation branch** | **REJECTED-correctly.** The binding preservation rationale remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:308-314`. |
| **cross-plan — three tenant types / convention 09** | **REJECTED-correctly.** The three tenant states, second database and second location remain explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:271-278,7235-7259,7295`. |
| **citation — stale ref register** | **CLOSED.** Current recorded refs match the remeasurement at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:32-41,340-347`. |
| **citation — stale controller/provisioning/entrypoint/audit anchors** | **CLOSED.** No r4 edit disturbed the corrected implementation anchors; representative bindings remain at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5456-5664,7450-7635`. |

The historical rev-1 → rev-2 change log is now correctly marked “as of rev 2” where it says 325, with the live 327 supersession beside it at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10918,10931`. Its underlying closures remain accurate.

## Rev-4 closure table

| Rev-4 finding | Rev-5 disposition |
|---|---|
| **M4-1 — compatibility fixture cannot exist under its stated topology** | **NOT CLOSED.** The duplicate topology was removed, but the replacement still cannot exercise the supplied resolver: the fixture uses guard `web` while the resolver requires `sanctum`, and the command sends the non-UUID token `compat` into a UUID comparison. See **B5-1** and **M5-1**. The rev-5 `CLOSED` claim at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10798` is therefore false. |
| **M4-1 second half — retain duplicate NULL-team collision coverage** | **NOT CLOSED operationally.** EC-21e is now separately specified at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7287,10799`, but its duplicate `web` roles are invisible to the `sanctum` resolver and the command fails on the UUID predicate first. |
| **minor 1 — stale reviewer-prompt outcomes** | **CLOSED.** The missing-admin split and previous-tenant restoration are accurately required at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10635-10665`. |
| **minor 2 — Replenishment lacks `register()`** | **CLOSED.** The new method is supplied explicitly at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1204-1217`. Dev confirms the provider currently has only `boot()` at `apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:14-21` and is registered at `apps/api/bootstrap/providers.php:41,116`. |
| **minor 3 — Phase 1.10 omits POS Pint** | **CLOSED.** The POS test is included in PHPUnit, Pint and staging at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7316-7318,7359`. |
| **minor 4 — deletion grep has impossible Markdown scope** | **CLOSED.** The grep is scoped to `apps/api scripts`, with no-output/exit-1 semantics at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7329-7332`. |
| **minor 5 — stale PHPStan constructor counts** | **CLOSED.** Step 1 now says three and two arguments at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9533-9545`. |
| **minor 6 — stale “only roles read” docblock** | **CLOSED.** It now says “only locked, ordered read of the template roles” and names the other reads at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6863-6871`. |
| **minor 7 — present-tense historical 325 wording** | **CLOSED.** The statements are qualified as rev-2 history and identify 327 as live at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10918,10931`. |
| **minor 8 — stale ref register** | **CLOSED.** The recorded `33796cc08` / `a7010fe4d` / `208449350` values match remeasurement at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:32-41,340-347`. |

The rev-4 → rev-5 change log is accurate for all eight minors and for self-review corrections N5-1/N5-2: the live replacements are visible at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:127,253,1204-1217,10635-10665`. It overstates M4-1 and EC-21e closure at `:10798-10799`, and its “Preserve intact” conclusion at `:10815` is not true while the promised executable compatibility path cannot run.

## BLOCKER

### B5-1 — `permissions:sync --compat` compares the literal `compat` to a PostgreSQL UUID column

The supplied command maps compatibility mode to `PermissionSyncService::COMPAT_SCOPE` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7002-7008`. That constant is the literal string `compat` at `:6185-6192`, and it is passed unchanged to `PermissionSyncService::sync(string $tenantId, …)` at `:6206,7033`.

The service then supplies it to:

```php
$query->whereNull($team)->orWhere($team, $tenantId)
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6875-6888`.

The real `roles.tenant_id` column is UUID at `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:33-44`. PostgreSQL must coerce the comparison operand to UUID; `tenant_id = 'compat'` fails with invalid UUID syntax. The `OR tenant_id IS NULL` arm does not make the bound operand type-valid.

Consequences:

- The supposedly executable compatibility command cannot reach adoption.
- EC-21 cannot report its six pristine/one customised adoption.
- EC-21e cannot reach `ambiguous_legacy_role_collision`.
- The fleet refusal correctly points operators to a command that fails before producing its promised typed outcome: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7122-7129`.
- The required PG test is on Phase 1.10’s run list at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7301-7316`, so that commit cannot be green.

Compatibility resolution must use a topology-aware predicate: under compatibility mode, query `whereNull($team)` only; under database-per-tenant mode, retain the settled NULL-or-valid-tenant-UUID predicate. The marker/lock token may remain `compat`, but it cannot be used as a database team value.

## MAJOR

### M5-1 — every rewritten EC-21 role uses the wrong guard

The corrected fixture expressly creates its seven shared roles with `guard_name = 'web'`, repeats that guard in its invariant, and adds the duplicate EC-21e manager with the same guard at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7276-7287`.

The supplied service declares:

```php
private const GUARD = 'sanctum';
```

at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6179-6183`, and `resolveTemplateRoles()` filters by that value at `:6881-6884`. Current production data also uses `sanctum`: the seeder creates permissions and roles with that guard at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:34-40,562-568`, and `User::getDefaultGuardName()` returns `sanctum` at `apps/api/app/Modules/Identity/Domain/User.php:284-290`.

Even after B5-1 is fixed:

- EC-21 finds zero template roles, so `adopted_pristine=6` and `adopted_customised=1` cannot pass.
- EC-21a cannot observe any `template_key` or `is_system` update.
- EC-21e’s second `web` manager remains invisible and cannot produce `ambiguous_legacy_role_collision`.
- The invariant at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7281` validates the wrong population and could pass while the actual `sanctum` population is absent.

All fixture roles, role-count invariants and duplicate-collision setup must use `guard_name = 'sanctum'`.

## MINOR

1. **Wave 1 is still pinned to wave-0b rev 4 instead of the required rev 5 producer.** The sibling and producer declarations still name rev 4 `2f172dd5e` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:21-30`, and Phase 0 still says signatures must match rev 4 at `:369-378`.

   Wave-0b rev 5 is `39ecb80f9`. Its consumed production signatures are unchanged: `TenantFleetRunner` still takes `Closure(Tenant): FleetTenantOutcome` at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1493-1495,1577-1602`; the writer census constants and two-argument visitor remain at `:3080-3106,3258-3261`; and the signature register still says no production change at `:8192-8203`.

   Rev 5 does, however, add `scripts/census_0b.py` as a committed producer artifact at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:87`, with the required merged-tree rerun at `:7771`. Re-pin the producer to `39ecb80f9`, update shifted citation lines such as `PermissionRenameMap::canonicalise()` now at `:429`, and make Phase 0 prove rev 5 rather than accepting a rev-4 tree—for example, `git merge-base --is-ancestor 39ecb80f9 dev` plus existence of `scripts/census_0b.py`. No new re-pin of the writer-census constant shapes is required.

## Citation audit

### Correct and confirmed

- Worktree HEAD is `2d3faa28b`; plan length is 10,963 lines.
- `dev`, W-LOT and T2 tips exactly match `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:34-39`.
- Replenishment’s provider and bootstrap anchors are exact: `apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:14-21`; `apps/api/bootstrap/providers.php:41,116`.
- The migration partial uniques are exactly as stated at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5165-5168`. One shared NULL-team role per template satisfies both: the tenant composite unique treats NULL tenant values as distinct, while `roles_global_template_key_unique` enforces the missing NULL-team half.
- Per-tenant `model_has_roles` pivots may point to the same global NULL-team role IDs. The existing pivot schema carries its tenant UUID independently at `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:74-90`. This does not violate either roles unique.
- The compatibility command/fleet policy is internally consistent: one global command and a typed fleet refusal at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7064-7080,7122-7129`. The defect is the command’s resolver input, not the refusal policy.
- All eight r4 minor anchors and N5-1/N5-2 were correctly propagated.
- Rev 5 does not change catalogue rows, manifests, enums, template defaults, deprecations, renames, SoD membership, migration DDL, production fleet logic or static guards. The r4-verified 327 catalogue and associated counts therefore remain undisturbed.

### Wrong or stale

- The active wave-0b producer declaration is stale at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:21-30,369-378`: it must name rev 5 `39ecb80f9`.
- Active `2f172dd5e` code-shape citations at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:112,5692,6793,7062,7066,7086,7137,7269` remain historically resolvable but are no longer citations to the mandated producer revision. They should be re-pointed where used as current contracts.
- The change-log claim that M4-1 and EC-21e are closed is wrong at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10798-10799`.
- The live EC-21 guard citations at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7277,7281,7287` are internally inconsistent with the supplied service at `:6181,6882`.

## Rejected false positives

- **Do not restore two NULL-team role copies.** One shared role set is the correct topology; fix the guard and UUID predicate without duplicating or re-homing roles. The binding preservation requirement remains at `docs/superpowers/reviews/2026-09-10-rbac-plan-w1-codex-gate-r4.md:202-223`.
- **Do not add a compatibility fleet.** Refusal is correct because compatibility mode has one shared table and no physical tenant databases to iterate: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7064-7080`.
- **Do not put compatibility mode in the production deploy YAML.** Its deliberate absence is correctly explained at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7078`.
- **Do not reduce 327 to 325.** The T2 keys remain a whole-wave entry condition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:51,93`.
- **Do not change `COMPAT_SCOPE` merely because it is not a UUID.** It remains valid as an advisory-lock subject and marker token. Only the roles-table predicate must stop treating it as a team ID: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6185-6192`.
- The prior rejected items remain rejected: the raw post-rename reads, tenant restoration snapshot, Neon `projectRoot`, after-commit callback, report-dead suffix threat model, `payments.reverse` SoD membership, class-before-test presentation order, and in-lane PHPStan/label measurements remain supported at `docs/superpowers/reviews/2026-09-10-rbac-plan-w1-codex-gate-r4.md:182-200`.

## Preserve

The next revision must preserve:

- One global NULL-team role row per template in compatibility mode; no re-homing and no duplicate APPLY fixture.
- EC-21e as a separate expected-BLOCKED duplicate case.
- Steps 1–4 only for `permissions:sync --compat`; no step 5, marker write, template delta, replacement or admin rewrite.
- `tenant=compat` as the marker and lock scope token, while branching role resolution to `whereNull(tenant_id)` only.
- Compatibility fleet refusal and absence from the production deploy runbook.
- `sanctum` as the repository’s permission guard.
- Both migration partial uniques.
- All r9 bindings summarized at `docs/superpowers/reviews/2026-09-10-rbac-plan-w1-codex-gate-r4.md:202-223`, including list-valued `LOCK_INHERITED_FROM`, exact template deltas, `admin = activeKeys()`, explicit tenant-wide actors and generated frontend/localization coverage.

## Owner decisions required

No new owner decision is introduced. B5-1, M5-1 and the rev-5 producer re-pin are mechanical corrections.

Existing decisions remain:

- O-5/OQ-3 may alter the eighteen-row SoD baseline, but the defined fallback is to ship eighteen and ratchet later: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:286-290`.
- O-6/OQ-4 is made after the soak; the implementation ships `SYNC_PERMISSIONS_ON_BOOT=false`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:292-294`.
- OQ-1/OQ-2 remain later-wave questions: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:296-298`.

## Dispatch assessment

The eight r4 minors are closed, the rewritten topology is directionally correct, and wave-0b rev 5 introduces no consumed production-signature change. The plan is nevertheless not dispatchable:

1. The actual PG compatibility command cannot execute because the marker/lock token `compat` is compared to a UUID team column.
2. The mandatory EC-21 fixture uses a guard the supplied resolver never reads.
3. Consequently EC-21, EC-21a and EC-21e cannot establish the compatibility contract, and Phase 1.10 cannot be green in isolation.

The declared named-but-unpasted-test residual remains acceptable as execution discipline in general at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10726,10828`; it does not excuse a named test whose specified fixture and production path are unsatisfiable. The machine-derived defaults, in-lane baseline counts, class-before-test presentation and `payments.reverse` SoD membership remain acceptable.

After correcting the two compatibility faults and re-pinning wave 0b to rev 5, dispatch still requires the existing operational entry conditions: wave 0b merged and deployed successfully, T2 merged, and the W-LOT staging protocol complete at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:45-64`.

VERDICT: CHANGES-REQUIRED