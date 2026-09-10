# Codex plan gate r4 — RBAC wave 1 plan rev 4 (gpt-5.6-sol, high, read-only, 2026-09-10)

Declared worktree HEAD:

```text
git rev-parse --short HEAD
041f77bbb
```

Reviewed plan length: **10,877 lines**.

Re-measured refs at the end of the review:

```text
dev                     33796cc08
lane/w-lot-a-1a         a7010fe4d
lane/t2-receipt-spine   208449350
```

The plan still records `dev 630afa86f`, W-LOT `52f5ad796`, and T2 `951a7637e` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:30-39`. Changes since those tips are signature-neutral for this wave: the W-LOT delta is documentation-only; T2’s seeder is byte-identical; and the new `dev` delta does not touch the seeder, provider bootstrap, PHPStan configuration, or Replenishment provider. Phase 0 already requires fresh measurement and signature reconciliation at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:337-344`.

## Rev-1 closure table

| Rev-1 finding | Rev-4 disposition |
|---|---|
| **B1-1 — stale 323-key arithmetic** | **CLOSED at rev 2, then correctly superseded.** Rev 2 established 325; rev 4 adds the accepted T2 whole-wave pair and derives 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:313-330`. |
| **B1-2 — incomplete manifests and red incremental history** | **CLOSED.** Complete assignment starts at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1268`; all 38 bodies follow at `:1809-3315`; shrink-only commit progression and concrete subjects are at `:1250-1252,3505-3517`. |
| **B1-3 — unconditional `template_version` update** | **CLOSED.** The write is conditional on `$versionChanged` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5383-5397`, with the zero-write service oracle at `:7233`. |
| **B1-4 — stopgap deleted after sync rather than atomically** | **CLOSED.** All eight stopgap artifacts and four affected tests are removed/renamed in Phase 1.10 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7169-7185`; replacement files are staged at `:7310-7335`. |
| **B1-5 — sync precedes migration** | **CLOSED.** YAML order is migrate → dry run → apply → cache → export at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10018-10030`; generated Markdown order matches at `:10693-10699`. |
| **B1-6 — undefined audit actor** | **CLOSED.** `recordTenantWide()` takes the actor second and passes it explicitly at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5527-5558`. |
| **B1-6a — nullable company assigned to a non-nullable property** | **CLOSED.** The required two-line `AuditEvent` change, including `$companyId ?? ''`, is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5510-5514`. |
| **B1-7 — prose/placeholder implementation** | **CLOSED for production classes.** Manifest/enum, migration, sync, command, audit, fleet, provisioning, reapply, static-guard, exporter and deploy-test bodies are now supplied. Remaining named test bodies are assessed under Residuals. Representative full bodies: `:5116-5231,6104-7167,7589-7620,7721-7822,8641-9504,9692-9919,10070-10362`. |
| **M1-1 — invalid commit subjects** | **CLOSED.** Concrete Phase 1.4 subjects are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3505-3517`; Phase 1.10’s full subject is at `:7337-7347`. |
| **M1-2 — no exact staging lists** | **CLOSED, with one minor verification omission below.** Examples include all 38 enum paths at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3575-3616` and Phase 1.10 at `:7310-7335`. |
| **M1-3 — invalid inherited-lock proof** | **CLOSED subject to the wave-0b entry condition.** `TemplateDeltaApplier::applyTo` is assigned to list-valued `LOCK_INHERITED_FROM` with both callers at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7849-7855`; producer contract is `2f172dd5e:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6981-7024`. |
| **M1-4 — scaffold determinism unspecified** | **CLOSED.** The command’s deterministic artifact contract and implementation occupy `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8188-8571`. |
| **M1-5 — deploy test checks presence rather than order** | **CLOSED.** Exact ordered-list and rendered-table comparison are specified at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10160-10180,10370-10380`. |
| **M1-6 — incomplete PG/test/PHPStan/Pint commands** | **CLOSED in general, with the Phase 1.10 POS Pint omission recorded below.** PG configuration and explicit paths are present at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7276-7293`; final two-lane commands are at `:10451-10508`. |
| **minor — 323 remains in Task 4 prose** | **CLOSED and superseded to 327.** Current progression totals 327 at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3507-3517`. |
| **minor — “PHPStan cannot see route files”** | **CLOSED.** It now distinguishes module route files under `app/` from top-level `routes/` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9018-9020`. |
| **minor — YAML trailing space** | **CLOSED.** The structured 0b wrapper row is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10005-10017`. |
| **cross-plan — broken admin baseline** | **CLOSED by the producer.** The nineteen-key admin addition begins at `2f172dd5e:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:580-603`; wave-1 adoption consumes `matchesVersion0()` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6321-6340`. |
| **cross-plan — missing selector provider** | **CLOSED.** Producer records the provider contract at `2f172dd5e:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6989-6992`; wave 1 appends the sync-fleet row at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7265-7269`. |
| **cross-plan — rollback sentinel deleted without replacement** | **CLOSED.** `SyncRollback` lands with Phase 1.10 and the old sentinel is deleted at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7169-7179,7349-7362`. |
| **cross-plan — stopgap not removed with sync** | **CLOSED.** Same Phase 1.10 anchors: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7169-7185,7310-7347`. |
| **cross-plan — scanner/marker shapes** | **CLOSED.** The inherited census, marker and callback contracts are pinned at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:19-28`; the producer’s unchanged wave-1 signatures are at `2f172dd5e:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:6981-7024`. |
| **cross-plan — `DateFactory` substitution** | **REJECTED-correctly.** It remains a deliberate recorded substitution, not accidental drift; the handback must record it at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10428`. |
| **cross-plan — seeder preservation branch** | **REJECTED-correctly.** The binding branch remains at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:305-311,8020-8055`. |
| **cross-plan — three tenant types / convention 09** | **REJECTED-correctly.** The three-type and two-physical-database cases remain explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7233-7240,7250-7252,7270`. |
| **citation — stale ref register** | **CLOSED at rev 2 as an entry-time remeasurement rule, but stale again as historical data.** Phase 0 remains safe at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:337-344`; current drift is listed in Citation audit. |
| **citation — stale controller/provisioning/entrypoint/audit lines** | **CLOSED.** Current code anchors are carried into the relevant sections, including entrypoint `:150-170` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7517-7539` and audit at `:5510-5514`. |

The rev-1 → rev-2 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10825-10854` accurately describes the rev-2 corrections as they existed. Its present-tense claims of “all 325” are now historically superseded by the accepted T2 expansion to 327; see MINOR.

## Rev-3 closure table

| Rev-3 finding | Rev-4 disposition |
|---|---|
| **B3-1 — fleet closure accepts `string`, runner supplies `Tenant`** | **CLOSED.** The closure is `function (Tenant $tenant): FleetTenantOutcome` and derives its id at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7119-7137`, matching producer `2f172dd5e:…wave-0b.md:1482-1488,1570-1595`. |
| **B3-2 — raw virtual grants disagree after in-place renames** | **CLOSED.** Projection grants are canonicalised at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6769-6803,6813-6843`; template/admin targets at `:6408-6417,6486-6505`; executor comparison at `:6592-6601`. |
| **B3-3 — `--tenant=B` destroys prior tenant A** | **CLOSED.** Snapshot/restoration is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7000-7039`. Stancl accepts a Tenant object and ends before switching at `apps/api/vendor/stancl/tenancy/src/Tenancy.php:18-19,29-58,61-73`. |
| **B3-4 — relative baselines cannot match absolute PHPStan sites** | **CLOSED.** Both constructors take `projectRoot` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8742-8746,9381-9384`; both normalise paths at `:8827-8834,9437-9445`; named service injection is at `:9510-9527`. |
| **B3-5 — nested outer transaction flushes the wrong team** | **CLOSED.** The callback captures the tenant, sets it at callback time, and restores the then-current team at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6683-6730`. |
| **B3-6 — no executable compatibility path** | **NOT CLOSED as a dispatchable, verified unit.** The production path now exists at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6922-6968,7047-7063`, but the mandatory EC-21 fixture is impossible under the same plan’s resolver and global unique. See **M4-1**. |
| **M3-1 — one missing-admin oracle demands an impossible dry-run trigger** | **CLOSED.** It is split into feasible dry-run/apply cases at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7243-7246`. |
| **M3-2 — broad `Permission.php` suffix exemption** | **CLOSED.** Anchored manifest/enum regexes and root-relative prefixes replace it at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8689-8719,8837-8853`. |
| **minor 1 — `implode()` claimed accepted without a `FuncCall` evaluator** | **CLOSED.** It is explicitly rejected at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9024-9029`, with a named test at `:9534`. |
| **minor 2 — deploy-table test claimed coverage of multiple documents** | **CLOSED.** Scope is narrowed to one plan at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9993-10002,10173-10180`. |
| **minor 3 — `resolveTemplateRoles()` called the only roles read** | **NOT CLOSED completely.** Main prose was corrected at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5693`, but the implementation docblock still says it is “the ONLY read of `roles`” at `:6849-6854`, despite reads at `:6758-6762,6822-6828`. |
| **minor 4 — stale worktree HEAD** | **CLOSED for the rev-4 artifact, but naturally stale again.** Historical closure is described at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10763`; Phase 0’s remeasurement remains the correct control at `:337-344`. |

The rev-3 → rev-4 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10746-10780` is correct for B3-1 through B3-5, M3-1, M3-2, minor 1, minor 2, and N4-2/N4-3. It overstates closure of B3-6 because EC-21 is unsatisfiable, and overstates closure of minor 3 because the stale implementation docblock remains.

## BLOCKER

None.

I found no remaining production-path blocker in the nine-step sync, fleet callback contract, tenant restoration, after-commit team handling, migration, or static guards.

## MAJOR

### M4-1 — the mandatory compatibility fixture cannot exist under the plan’s own role topology

All five compatibility cases are required to run on a shared roles table seeded with “**two tenants’ NULL-team roles**” at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7257-7262`.

That fixture has no valid interpretation:

1. If it means two NULL-team copies of each template role, `resolveTemplateRoles()` retrieves both because its predicate admits every NULL-team row, groups by `name`, and rejects any count other than one as `ambiguous_legacy_role_collision`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6864-6881`. EC-21 cannot reach `outcome=APPLIED`, and EC-21a cannot complete adoption.

2. If the duplicate rows somehow passed resolution, adoption assigns the same `template_key` to each. The migration’s `roles_global_template_key_unique` forbids duplicate non-null template keys where `tenant_id IS NULL`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5048-5050,5151-5154`.

3. If “two tenants” instead means one shared global NULL-team role set plus two tenant directory records, the wording and EC-21b’s “template-key uniqueness holds with two tenants” assertion do not specify that fixture. NULL-team roles contain no tenant identity, so merely inserting two tenant records does not create “two tenants’ roles”: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5721-5728`.

The settled compatibility design itself is coherent: one global NULL-team role per template, steps 1–4 once, globally. The required correction is bounded:

- Rewrite EC-21..EC-21d to seed **one shared NULL-team role set**, plus two tenant directory rows and any tenant-specific users/pivots needed to prove both tenants observe that same role set.
- Assert exactly one role row per template name and exactly one adopted NULL-team row per `template_key`.
- Keep the duplicate-NULL-team arrangement as a separate **BLOCKED** test expecting `ambiguous_legacy_role_collision`, not as the APPLY fixture.

Until that oracle is corrected, Phase 1.10 cannot satisfy its own required PG test run at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7276-7293`, and B3-6 is not dispatch-closed.

## MINOR

1. **The reviewer-gate prompt still carries two pre-rev-4 outcomes.** It asks for missing admin to exit 1 “on both paths” at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10607-10614`, contradicting the settled green dry-run/apply-only failure split at `:7243-7246,10522`. It also asks only for a guarded `end()` at `:10615-10619`, not restoration of prior tenant A as implemented at `:7008-7039,10518`. Update the reviewer prompt so it cannot reintroduce M3-1 or B3-3.

2. **The Replenishment tag edit needs an explicit `register()` addition.** The plan says each provider’s existing `register()` “gains exactly one line” at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1199-1207`, but `dev:apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:14-21` has only `boot()`. State that this provider gains a new `public function register(): void` containing the tag. It is registered in bootstrap at `dev:apps/api/bootstrap/providers.php:41,116`.

3. **Phase 1.10 does not Pint its staged POS test.** PHPUnit includes `tests/Feature/POS/PosApprovalPermissionSecondLocationTest.php` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7287-7290`, and staging includes it at `:7332`, but Pint covers only `tests/Feature/Identity` and `tests/Feature/Tenant` at `:7292`. Add the POS file explicitly.

4. **The Phase 1.10 deletion grep has an impossible expected result.** It searches PHP, shell and Markdown while excluding only `docs/superpowers/plans` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7303-7306`. Historical handoffs, reviews and the accepted spec intentionally contain `permissions:ensure`, `DryRunRollback`, and related names—for example `docs/handoff/CODEX-PROMPT-plan-gate-RBAC-w1-round-3-2026-09-10.md:5` and `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:931-947`. Restrict this guard to `apps/api scripts`, matching the final checklist at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10535,10541`.

5. **N4-1 was only partially propagated.** The actual constructors and Neon services are correct, but Step 1 still says the literal rule takes two arguments and the role-name rule one at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9508-9509`; the supplied constructors take three and two at `:8742-8746,9381-9384`. Correct those counts.

6. **The rev-3 minor-3 docblock survives.** `resolveTemplateRoles()` still claims to be the only roles read at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6849-6854`, while `refuseTemplateKeyNameMismatch()` and `holdersOf()` read roles at `:6758-6762,6822-6828`. Use the already-correct formulation from `:5693`: “only locked, ordered read of template roles.”

7. **Historical rev-2 “now/all 325” wording is stale in the current document.** The rev-1 change log says the plan “now supplies” 325 keys and “all are 325” at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10832,10845`, while rev 4 correctly carries 327 at `:313-330,3507-3517`. Mark those statements explicitly “as of rev 2” to avoid contradicting the live plan.

8. **The ref register is stale again.** The current recorded values at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:30-39` differ from the end-of-review measurements above. This does not block because Phase 0 requires remeasurement at `:337-344`, and the relevant code signatures did not move.

## Citation audit

### Correct and confirmed

- **Catalogue derivation:** `dev`’s method contains 304 unique keys at `dev:apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559`. W-LOT’s wrapper adds exactly `batches.recall.request` and `treasury.manage_all_locations` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91-102`. Wave 0b supplies the nineteen additions at `2f172dd5e:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:580-603`. T2 adds `inventory.transfers.reconcile` and `.close` at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-202` and grants both to manager at `:604`. Result: base 325, entry-condition total **327**.

- **Assignment table:** static extraction found **327 rows, 327 unique keys, no missing key, no extra key and no duplicate** against the complete entry-condition set. Relative to the old 325 set, the only additions are T2’s `.reconcile` and `.close`, whose rows are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1484-1487`.

- **Template defaults:** all **758 non-admin role/key pairs** match the combined W-LOT + 0b + T2 grant map. Totals are `admin 327`, `general_manager 259`, `manager 257`, `accountant 87`, `operator 58`, `cashier 49`, `viewer 34`, `technician 14`, matching `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1129-1140`.

- **Required spot checks:** verified `products.view` (`:1296`), `credit-notes.cancel` (`:1399`), `invoices.print` (`:1415`), both new transfer keys (`:1484-1487`), `batches.view` (`:1509`), `work-orders.view` (`:1526`), `workshop.technicians.view` (`:1538`), `services.view` (`:1551`), `payments.create/reverse/view` (`:1586-1590`), `treasury.manage_all_locations` (`:1596`), `journal.post` (`:1639`), both taxation legacy keys (`:1651-1652`), `pos_orders.delete` (`:1710`), `settings.view` (`:1765`) and `audit.view` (`:1785`) in `docs/superpowers/plans/2026-09-10-rbac-wave-1.md`.

- **Renames:** the eleven entries in producer `2f172dd5e:…wave-0b.md:368-382` exactly match the accepted list at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1893-1911`.

- **Deprecations:** all 22 plan entries at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4717-4723` exactly match the spec’s authoritative list at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1942-1948`. Static manifest extraction found 22 deprecated definitions and **zero non-null `replacedBy` values**.

- **Legacy classification:** exactly six resources fail `RESOURCE_PATTERN` at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:560`: four `pos_orders.*` rows at `:1709-1712` and two taxation rows at `:1651-1652`. No other definition requires `legacy()` for resource-shape reasons. Total legacy actions are exactly 59, matching `:3507-3517,3520-3530`.

- **Manifests and enums:** static parsing found **38 manifests, 38 enums and 327 unique values in each**, with exact per-module equality. The manifest bodies are `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1809-3315`; enum bodies are `:3625-4711`; equality is required at `:3550-3554`.

- **Provider registration:** all 38 named provider files at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1207-1248` exist. The bootstrap imports and registers the relevant provider set at `dev:apps/api/bootstrap/providers.php:3-54,60-117`. Registry aggregation uses tagged services at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:978-992`. Replenishment’s missing `register()` is the sole editorial exception noted above.

- **PHP 8.4 syntax:** I independently piped **23 complete PHP blocks** through `php -l`; all returned “No syntax errors detected.” This included migration `:5116-5231`, applier `:5337-5408`, audit blocks `:5450-5614`, sync service `:6104-6887`, both commands `:6907-7167`, static rules/tests `:8641-9504`, exporters/label test `:9692-9919`, and deploy renderer/test `:10070-10362`.

- **Nine-step sync:** settled order is explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5682-5702`. The lock is the transaction’s first statement at `:6205-6208`, matching producer key `'wlota1a:'.$tenantId` at `2f172dd5e:…wave-0b.md:1171-1179` and W-LOT’s actual key at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:131-135`.

- **Canonical comparisons:** the five revised sites are present at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6408-6417,6495-6497,6592-6601,6769-6803,6813-6843`. The two remaining raw relationship reads are immediately canonicalised. The applier and post-apply admin verification are safe raw comparisons because execution has already renamed rows in place and manifests may not declare sources: `:5375-5389,6258-6302,6668-6680`.

- **Dry run/idempotency:** the planner is read-only at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6247-6259`; execution is write-gated at `:6213-6235`; zero-write rerun is required by query-log count at `:7233`. Mutations versus gauges are correctly distinguished at `:5704-5717`.

- **Fleet:** callback contract is correct at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7119-7137`; producer readiness failures and initialization are at `2f172dd5e:…wave-0b.md:1570-1599`; command exit aggregation is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7139-7164`.

- **Migration:** four columns, two partial uniques, three PG checks, SQLite trigger equivalents, refusing `down()` and no enum-column alteration are all present at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5037-5065,5116-5231`. The general-manager marker write satisfies the lane CHECK at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-48`.

- **Tenant restoration:** `$previous` is a tenant contract/model object, valid for `Tenancy::initialize(Tenant|int|string)` at `apps/api/vendor/stancl/tenancy/src/Tenancy.php:18-19,27-58`. Restoration logic at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7008-7039` is plausible and preserves both prior-tenant and prior-central states.

- **Nested after-commit cache handling:** callback-time team snapshot, set, flush and restoration are correctly enclosed at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6710-6730`.

- **PHPStan injection:** `services:` named arguments match the constructors at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:8742-8746,9381-9384,9510-9527`. Installed PHPStan declares `currentWorkingDirectory: string()` in `apps/api/vendor/phpstan/phpstan/phpstan.phar/conf/parametersSchema.neon:82`.

- **AST route guard:** concat and array constant expressions are accepted, function calls rejected, at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9022-9029,9035-9320`.

- **Frontend map and labels:** generated map/type implementation is at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9570-9686`; three-locale active-key coverage is supplied in full at `:9806-9919`; task-local run and staging commands are at `:9923-9945`.

- **Deploy exact order:** YAML at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10003-10030` and generated table at `:10689-10699` agree in exact order. The test calls the same renderer at `:10160-10189,10370-10393`.

- **Entrypoint safety:** actual entrypoint has `set -e` at `dev:apps/api/docker/entrypoint.sh:1-2`; migration failure is contained in an `if` at `:150-154`. The replacement nests apply inside successful dry-run and catches all arms at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7541-7581`. The health reader exposes `permissions_sync` at `:7585-7623`. This is safe for a staging push: blocked preview skips apply, boot continues, and authenticated health becomes unhealthy.

- **SoD:** eight groups and twenty member keys, including admin-only `payments.reverse`, are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7889-7906`. The eighteen baseline combinations at `:7910-7935` exactly match the derived seeder/template combinations.

- **Audit and reapply:** `RoleSyncedV1`, explicit actor, company-null guard and subscriber are at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:5442-5619`. Reapply holds the shared lock and emits an audited exact diff at `:7760-7822`; its route uses the accepted enum expression for `roles.manage` at `:7824-7834`.

### Wrong or stale citations/instructions

- Historical ref tips and worktree HEAD at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:30-39` are stale relative to this review; Phase 0’s remeasurement at `:337-344` prevents execution drift.
- Rev-1 changelog’s present-tense 325 statements at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10832,10845` are stale after the accepted T2 expansion.
- Rev-3 changelog claims minor 3 is closed at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10762`, but the stale implementation docblock remains at `:6849-6854`.
- N4-1’s constructor-count claim at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10772` is correct in the File Structure and Neon block, but Step 1 still carries old counts at `:9508-9509`.
- The reviewer-gate instructions at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10607-10619` were not updated for the missing-admin split or prior-tenant restoration.
- Phase 1.10’s Markdown-wide stopgap grep at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7303-7306` cannot produce its stated output while historical specs, reviews and handoffs remain.

## Rejected false positives

- **Do not reduce 327 back to 325.** The two T2 keys are a whole-wave entry condition at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:21-25,313-330`; their seeder declarations remain at `lane/t2-receipt-spine:apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-202`.

- **The raw reads in `TemplateDeltaApplier` and `verifyAdmin()` are not missed B3-2 sites.** Both execute after step 1 renamed permission rows in place, and targets are canonical manifest spellings: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6258-6302,5375-5389,6668-6680`.

- **`tenant()` is a valid snapshot for restoration.** It returns the currently bound Stancl Tenant contract at `apps/api/vendor/stancl/tenancy/src/helpers.php:16-33`, and `initialize()` accepts that object at `apps/api/vendor/stancl/tenancy/src/Tenancy.php:27-58`.

- **`projectRoot: %currentWorkingDirectory%` is valid Neon syntax and a real PHPStan parameter**, not an invented interpolation: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9510-9528`; installed schema `apps/api/vendor/phpstan/phpstan/phpstan.phar/conf/parametersSchema.neon:82`.

- **The after-commit callback restores the correct team under nesting.** It snapshots the team at callback time, not registration time, at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:6712-6728`.

- **The `ReportDeadPermissions` broad `Permission.php` suffix is not M3-2 recurring.** Rev 4 deliberately records its different false-positive-only threat model at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10772-10774`.

- **`payments.reverse` belongs in the payment SoD group.** It is admin-only now but deliberately makes a future template grant fail immediately: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7901-7906`.

- **Class bodies preceding test bodies is presentation order, not red/green commit order.** Tasks still direct tests first and require red evidence, for example `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:7625-7637,7838-7848`.

- **Measured PHPStan and label-gap counts should not be hardcoded in the plan.** The lane is explicitly required to measure and record them at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9010-9016,9688-9690,10423-10426`.

## Preserve

The rev-9 Preserve list at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-210` remains intact in production design:

- Direction B, D1–D8, module-owned manifests and registry tagging.
- Tenant-scoped roles and membership-carried company/location scope.
- Legacy NULL-team roles remain in place; compatibility mode operates globally rather than re-homing them.
- W-LOT marker value, CHECK-compatible creation, advisory key and deterministic row order.
- Distinct `matchesPreWave0b()` and `matchesVersion0()` predicates.
- Exact deltas only for uncustomised template-linked roles.
- No ordinary grant/removal writes to custom or customised roles.
- Replacement grants remain the single audited exception and are a provable no-op in this catalogue.
- `admin = activeKeys()`.
- Exactly two production triggers: direct provisioning and flagged fleet deploy.
- Write-free but failure-preserving dry run.
- Settled selector/readiness/failure aggregation through `TenantFleetRunner`.
- Explicit tenant-wide actor handling and immutable `RoleSyncedV1`.
- Generated frontend map and complete en/fr/ar key coverage.
- Reapply remains gated by the enum expression for `roles.manage`.
- `TemplateDeltaApplier::applyTo` remains in list-valued `LOCK_INHERITED_FROM` with sync and reapply as its complete callers.

The compatibility fixture correction in M4-1 must preserve the NULL-team topology by using one global role set; it must not “fix” the test by inventing duplicate or re-homed tenant roles.

## Owner decisions required

No new owner decision is introduced by this gate. M4-1 is a mechanical consistency correction.

Existing decisions remain:

- **O-5 / OQ-3:** decide whether to retire any of the eighteen SoD combinations before Task 1.13 pins its baseline. If unanswered, the defined fallback is to ship ceiling 18 and lower it later: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:283-287,7939-7940`.

- **O-6 / OQ-4:** after the one-week soak, decide whether `SYNC_PERMISSIONS_ON_BOOT` becomes production-default true. The wave ships false and gathers evidence first: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:289-291,10414,10675-10676`.

- OQ-1 and OQ-2 remain later-wave questions and do not block wave 1: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:293-295`.

## Dispatch assessment

The production implementation plan is substantially corrected. Fleet closure typing, all five canonicalisation sites, prior-tenant restoration, constructor-injected PHPStan path normalisation, nested after-commit team handling, and the explicit `--compat` command path are technically plausible and consistent with the producer and current code.

The declared residuals are assessed as follows:

- **Named-but-unpasted tests:** acceptable as an execution-discipline residual after M4-1 is corrected. Their fixtures and assertions are generally specific enough to implement; wholesale pasting is not required for dispatch. Residual is recorded at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10780,10680`.
- **Machine-derived template defaults:** acceptable; independently verified exact for all 327 rows and all eight templates.
- **PHPStan baseline and label-gap counts measured in-lane:** acceptable and preferable to hardcoding; explicit handback requirements exist at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:10423-10426`.
- **Class-before-test document ordering:** acceptable; red-first execution is still required.
- **`payments.reverse` in SoD:** correct.
- **Current ref movement:** acceptable only because Phase 0 remeasures and must confirm the signature-neutral result before work starts.
- **Compatibility test fixture:** not acceptable. It is a required B3-6 oracle that cannot pass under the plan’s own resolver and migration.

Required before dispatch:

1. Correct EC-21..EC-21d to use one global NULL-team role set shared by two tenant records.
2. Add the duplicate-NULL-team state as a separate expected-BLOCKED test.
3. Apply the minor propagation fixes, especially the stale reviewer-gate outcomes and Phase 1.10 POS Pint path.
4. Re-run Phase 0 against the then-current `dev`, W-LOT and T2 refs and confirm no producer signature has moved.

Because a mandatory Phase 1.10 test is presently unsatisfiable, this is not the “only MINOR findings remain” case.

VERDICT: CHANGES-REQUIRED