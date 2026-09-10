<!-- W-LOT-A-1a rev 10, orchestrator addendum on a byte copy of rev 9 (implementation gate r1 rulings 2026-09-10: B-2 string totals adopted at Push 3 with riders, §6.1 non-inert exceptions declared, I-1 scoped mutation responses, technician ruling, manifest raise + promotion order) — earlier header: rev 9, orchestrator addendum on a byte copy of rev 8 (gate r8 DISPATCH-READY, c5dddaddb) 2026-09-09. Rev 8 = 3305e6f46 is the verbatim base; the ONLY change is §0 + the §8.4 addendum below (owner/orchestrator ruling on legacy NULL-team roles). Status: DISPATCH-READY with addendum; the addendum is reviewed by tenancy-authz-reviewer at the implementation gate (§14), not by a new plan gate. -->
<!-- W-LOT-A-1a rev 10; Tasks 1/2/6 only. Audited at c76435df4a98193dfacb80ad9c25839169179509 on 2026-09-09. -->
# Slice plan W-LOT-A-1a — lot action permissions, general-manager role delta, web gating (rev 10)

## 0. Round-9 addendum — implementation gate r1 rulings (orchestrator, 2026-09-10)

Implementation gate r1 on `lane/w-lot-a-1a` HEAD `04e60530c` (registers: [tenancy](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-10-w-lot-a-1a-impl-gate-r1-tenancy.md:1) CHANGES-REQUIRED B-1/B-2/M-1..M-5; [inventory](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-10-w-lot-a-1a-impl-gate-r1-inventory.md:1) CHANGES-REQUIRED I-1..I-9; frontend register follows). Rulings that amend this plan:

| Item | Ruling |
|---|---|
| **B-2 / I-2 — Push 3 not inert on batch totals and the expiring/expired eager load** | **Option B ADOPTED: the 4-dp string totals (`total_quantity`, `available_quantity`) and the location-filtered eager load on `/batches/expiring` and `/batches/expired` ship at Push 3 for every tenant, flag-independent.** Reasons (inventory register "Ruling input for B-2"): flag-gating would reinstall a `(float)` cast on a `SUM` over `decimal(15,4)` at the JSON boundary (rule 19) as the live path for the whole Push-3→activation window, restore an N+1 `SELECT SUM` per resource, and create a second, flag-dependent wire-type flip; the old shape already disagreed with the `batch_stock[]` array printed beside it. **§6.1 is AMENDED**: "while false, newly location-scoped endpoints retain pre-activation read behavior" now carries two declared exceptions — (a) batch-level totals are 4-dp strings computed from the loaded (scoped) `batchStock` relation on every response, (b) `/batches/expiring?location_id=` and `/batches/expired?location_ids[]=` narrow their `batch_stock[]` and totals to the requested locations. Both are recorded in the Push-3 release note and in REALIGNMENT-LOG. Riders, all mandatory: **R1** load the scoped relation before building `BatchResource` on every response path — `store` (`BatchController.php:211`) and the four mutation responses `update`/`recall`/`transfer`/`writeOff` (`:229,:268,:464,:503`, inventory I-1) use the same scoped closure as `BatchRepository::findVisibleByUuid` driven by `resolvedReadLocationIds()`; never recompute totals from a fresh query. **R2** an unloaded `batchStock` relation is loud, not `"0.0000"` (`whenLoaded` omission or `LogicException`; inventory I-3). **R3** `apps/web/src/features/batches/types.ts:38-39,281,322-324` become `string`, the "PHP float aggregate" comments go, and Vitest renders `BatchListPage` + `ExpiryWriteOffPage` with `"3.1234"`-shaped strings. **M-1/I-2 test**: `test_flag_off_preserves_pre_activation_access_and_payloads` becomes a real HTTP contract test with `enforce=false` over `/batches`, `/batches/{uuid}`, `/batches/expired?location_ids[]=`, `/products/{id}/batch-stock`, `POST /batches`, asserting the committed payload shape including the JSON string types and the declared exceptions. |
| **B-1** | `LotActionPermissionDeltaTest:32-33` → driver skip (`markTestSkipped` when not pgsql), the database-name assertion is deleted (no committed test may name a session-private DB); `GeneralManagerAssignmentTest` shares the harness. Both re-run on PG and on `phpunit.xml` to prove the skip. |
| **M-2 technician** | Owner RULED 2026-09-10: `technician` is NOT granted `batches.view` (leave as is; automotive may re-grant later through the normal delta path). Seeder comment + handback record the deliberate exclusion. |
| **M-3** | `RoleData::$permissions` gets `#[LiteralTypeScriptType('string[]')]` (or equivalent) and the generated file is regenerated; `RolesPage.tsx:157` is typed. |
| **M-4 / M-5 / I-5** | `backwardTrace` validates `partnerId` (`Str::isUuid` → 404) and `product_id`/`date_from`/`date_to` (`sometimes|uuid`, `sometimes|date`); trace DTOs restore nullability: `productId` and `documentNumber` become `?string` on both `BackwardDocumentBatchTraceData` and `ForwardDocumentBatchTraceData`, casts dropped; `BatchTraceReaderContractTest` gains a Draft-document / null-`product_id` line. |
| **I-4** | `BatchReadLocationScopeTest` gains: restricted actor + foreign `location_id` on `/pos/products/{id}/batches` → 403 with the seven-table snapshot unchanged; unrestricted actor's suggestion JSON identical with `enforce` true and false; a second-company backward-trace case. |
| **I-6 / I-7 / I-8 / I-9, N-1..N-5** | I-6 comment + test pinning `getByProduct`'s quantity>0-only semantics; I-7 widen the architecture test glob to `app/Modules/BatchExpiry/Presentation/Controllers/*.php`; I-8 ticket for `BatchDetailPage.tsx:279,301` `parseFloat`; I-9 hoist the duplicate `resolvedReadLocationIds` call; N-1 release-note line; N-2 add `LOT_ACTION_PERMISSIONS_ENFORCE=false` to `apps/api/.env.example`; N-3 emit the `WLOTA1A-RESEED` stderr marker only on the marked/activated paths, not on every flag-off boot; N-4 `currentTenantId()` must not throw for `DatabaseSeeder`/`ProductionSeeder` outside tenancy (fall back to the legacy behaviour with a logged reason) — add a test; N-5 confirm the `key` warning is pre-existing or fix it. |
| **Feature-lane manifest + CI allowlist (plan defect: §10 never listed the manifest)** | Raised **in the lane's fix round** (not at merge): `apps/api/tests/feature-lane-manifest.json` BatchExpiry 17→21, Console 16→18, Identity 32→39, Migrations 14→15, `gated_ceiling` 1250→1264, with the standard truthful raise notes; `.github/workflows/ci.yml` `backend-test-pgsql` `--filter` gains the PG-lane classes listed in the tenancy register (after B-1 is fixed); `php tools/feature-lane-manifest-check.php` exit 0 captured in the handback. These two files join the Push-3 ledger. |
| **Promotion order (§10/§11)** | The web bundle (Push 5) has no role-map fallback for `batches.*`; it MUST NOT reach staging before the Push-4 delta has granted `batches.view` to `viewer`/`operator`/`cashier`. The orchestrator promotes Push 1–4 and verifies the delta before Push 5; never one combined push. |

## 0A. Round-8 addendum — legacy NULL-team roles (owner/orchestrator ruling 2026-09-09)

Gate r8 was DISPATCH-READY ([gate r8](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r8.md:1)). Implementation surfaced a plan defect the gate did not catch: the legacy seeder creates every role with `Role::firstOrCreate(['name' => ..., 'guard_name' => 'sanctum'])` ([RolesAndPermissionsSeeder.php:567](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:567)), so every seeded role in every tenant database carries `tenant_id = NULL`; the §8.4 delta's tenant-scoped predicate therefore found no legacy `admin`/`manager` rows and the upgrade path was blocked. Spatie matches a NULL-team role from every lookup ([Role.php:174](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Models/Role.php:174) through line 180: `whereNull(team) OR team = getPermissionsTeamId()`), and this deployment is database-per-tenant, so a NULL team is the shipped, unambiguous shape — not an ownership question.

| Finding | Disposition |
|---|---|
| Implementation question: adopt legacy NULL-team roles (set `tenant_id`) after proving assignments belong to the tenant? | **REJECTED — no adoption.** Setting `tenant_id` would hide those roles from every context where `getPermissionsTeamId()` is null (console commands and queued jobs without `SetPermissionsTeam`, CLAUDE.md rule 20) and would change an application-wide lookup contract outside §2. The ownership migration §8.4 does not define stays undefined. Ruling text: [CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md:1); owner register [OWNER-QUESTIONS-consolidated-2026-09-06.md](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:1) (2026-09-09 night entry). |
| §8.4 predicate | **AMENDED** — see the "§8.4 addendum (rev 9)" block inside §8.4. |

## 0B. Round-7 change log

The governing review is [gate r7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r7.md:1). The resolved staging topology and observed Dokploy deployment-record shape remain pinned by the [U-1 resolution](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:1). Every round-7 finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-C1 — tenant database names are synthesized | CLOSED — §§11.2 and 11.3 run an executable producer inside the current API container using `Tenant::getTenantKey()` and canonical `Tenant::getDatabaseName()`, persist `/root/wlota1a/manifests/<PHASE>/tenant-databases.tsv`, and fail unless its count, UUID set, and non-empty database column match the immutable UUID manifest one-to-one. Every `psql` and `pg_dump` loop consumes that mapping; no database name is constructed from a UUID. The application resolver is [Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264), the inherited key accessor is [Tenant.php:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Database/Models/Tenant.php:42), the configured prefix/suffix contract is [tenancy.php:59](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/tenancy.php:59) through line 63, and the persisted override is honored at [DatabaseConfig.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:66). |
| B-B3a — raw per-tenant evidence is validated too early and test-only gates lack committed comparisons | CLOSED — §11.2 makes delta, reseed, census, migration, snapshot, and backup loops capture every command status and full per-tenant output without early exit, aggregate every raw prefixed marker, and copy container evidence to the host before status or bad-output validation. §§11.5, 11.6, 11.8, 11.9, and 11.11 add literal expected records and named `cmp -s ... || fail_gate` branches for timestamp, registration response/pause/resume, maintenance restoration, and normal/fallback served asset and fingerprint evidence. |
| M-E — whole-second strict ordering rejects a correct deployment | CLOSED — §11.8 saves the pre-push set of web `deploymentId` values to the host before the push, then selects only a `description="Commit: ${PUSH5_SHA}"` record whose ID was absent from that baseline. `createdAt` uses an inclusive whole-second lower bound only as a secondary check. The selected ID is pinned, polled to `status=done`, and compared with the committed record template; the incident fallback uses the identical baseline-exclusion and inclusive-bound protocol. |

No round-7 item is rejected and no owner decision is required. All prior closures and rejected false positives remain preserved.

## 0C. Round-6 change log

The governing review is [gate r6](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r6.md:1). The resolved staging topology and observed Dokploy deployment-record shape are pinned by the amended [U-1 resolution](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:1). Every round-6 finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-B2 — flag-off boot seeding undoes the canonical delta | CLOSED — §§8.1, 8.6, 8.9, 10, 11.2, 11.8, 11.12, 13, 14, 16, and 17 make `RolesAndPermissionsSeeder` branch on the exact marked `general_manager` tuple before the current destructive `syncPermissions()` seam. Flag-off fresh/unmarked tenants alone receive the legacy matrix; marked tenants preserve the applied canonical delta and all custom grants. Two named PostgreSQL red-first tests cover reseeding after Push 4 and after rollback, while Push 5 and rollback retain full before/after snapshot equality and also compare canonical post-delta snapshots with committed heredoc expectations. |
| B-B3a — fleet proof has prose producers and fail-open comparisons | CLOSED — §§11.2, 11.6, 11.9, and 11.11 install and source one durable `fail_gate`, replace every positive bare `cmp`, issue exact real registration requests against `POST /api/v1/auth/register`, validate host-captured JSON, extract one `.data.user.tenantId`, persist each one-line manifest under `/root/wlota1a/`, and explicitly `docker cp` it into the current API. Registration is paused with Laravel's existing cache-backed maintenance mode through a reversible Dokploy API-app environment update/redeploy, the operator request uses the secret bypass cookie, and resume executes `artisan up`, verifies public registration is reachable, restores the saved API environment, and redeploys. |
| M-E — Push 5 polls an impossible or unordered deployment record | CLOSED — §11.8 records the push timestamp, searches the unordered `deployments[]` array for `description == "Commit: ${PUSH5_SHA}"` with `createdAt` after that timestamp, pins the selected `deploymentId`, polls only that ID until `status == done`, fails explicitly on terminal error or timeout, and compares `(deploymentId, description, status)` with a committed template. The normal path still forbids manual redeploy; the incident fallback uses the same description/time/ID correlation. The observed record is documented at [U-1 resolution:34](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:34) through line 38. |
| M-F — role-index response test omitted from inventories | CLOSED — §§8.1 and 10 explicitly list `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php`. |

No round-6 item is rejected and no owner decision is required. All prior closures and rejected false positives remain preserved.

## 0D. Round-5 change log

The governing review is [gate r5](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r5.md:1). The resolved staging topology remains pinned by the [U-1 resolution](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:1). Every round-5 finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-B2 — initial OFF and rollback are not executable | CLOSED — §§11.4 and 11.12 each contain a self-contained workstation block defining `WORK`, the staging host, Dokploy URL/API-key retrieval, and `update_and_redeploy`; each performs full-env `application.update`, `application.one` readback, sequential worker → API → scheduler redeploys with the exact application IDs/appNames, replacement-container `printenv`, and exact pre/post API permission snapshot `cmp`. |
| B-B3a — fleet gates depend on prose or synthesized expectations | CLOSED — §§11.2–11.11 define and consume `PHASE`, give literal copy-in/copy-back commands and exact Push-3/Push-5 invocations, implement both rollout-window branches, aggregate raw census markers, run cache/Horizon commands with exit rules, and compare every backup, migration, delta, reseed, census, rollout, and activation record against a committed literal heredoc/template built from its immutable manifest. |
| B-B3b — registration marker is trapped by `Artisan::call()` | CLOSED — §§8.1, 8.6, 8.9, 11.11, and 13 require `RolesAndPermissionsSeeder::emitWlota1aReseedMarker()` to write the exact marker through `Log::channel('stderr')->info()`, add a PostgreSQL registration test that observes the channel despite Artisan buffering, and extract every raw marker from container stderr before literal-template `cmp`. The registration call is buffered at [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213) and [Application.php:165](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Console/Application.php:165); the existing `stderr` channel writes to `php://stderr` at [logging.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/logging.php:97) through line 104. |
| M-E — Push 5 collides with web auto-deploy | CLOSED, SUPERSEDED BY ROUND 6 RECORD CORRELATION — §11.8 keeps the no-manual-redeploy normal path and now correlates the unordered observed deployment records by `description="Commit: ${PUSH5_SHA}"`, post-push `createdAt`, and pinned `deploymentId` before polling that record to `status=done`. Web `autoDeploy=true` is resolved at [U-1 resolution:17](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:17). |
| N-B — A-1b ownership lists differ | CLOSED — §§2 and 15 reproduce the same complete A-1b ownership list byte-for-byte. |
| N-C — named-symbol census SHA is obsolete | CLOSED — §§1 and 6.5 identify planning SHA `b05506eef4f477d86d4745b219a8651b11068801`. |

No round-5 item is rejected and no owner decision is required. All prior closures and rejected false positives remain preserved.

## 0E. Round-4 change log

The governing review is [gate r4](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r4.md:1). The resolved staging topology is pinned by the [U-1 resolution](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:1). Every round-4 finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-B2 — neither topology branch executable end to end | CLOSED — [`R5-B-B2`](#r5-b-b2), §§10, 11.1, 11.9, 11.11, and 11.12 use only the resolved `separate_applications` topology; verify the four exact Dokploy application records; mutate the full environment through `application.update`; redeploy and read back worker, API, and scheduler; persist Push 1–5 SHAs; and provide executable aggregate-OFF rollback. The fact sheet proves there are zero compose services and no Push 6 at [U-1 resolution:7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7). |
| B-B3 — state crosses destroyed shells/containers and unexpected markers are filtered | CLOSED — [`R5-B-B3`](#r5-b-b3) and all of §11 persist manifests, helpers, SHAs, snapshots, and evidence under `/root/wlota1a/`; explicitly `docker cp` every required file into or out of each current API container; aggregate every raw prefixed marker before validation; and build literal expected records from the immutable manifest for `cmp`. §11.11 captures the initial activated `APPLIED` registration marker and the explicit `ALREADY_APPLIED` reseed separately. |
| M-C — generated Roles DTO cannot have the contracted wire field | CLOSED — [`R5-M-C`](#r5-m-c), §§8.3, 8.8, 8.9, 9.2, 9.3, 13, and 17 use snake-case `public readonly` RoleData properties, serialize `index()`/`show()` through RoleData, consume the generated type in the web, and add `RoleIndexResponseContractTest::test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived`. The transformer preserves PHP property spelling at [typescript-transformer.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:34), and the repository snake-case precedent is [TrialBalanceData.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/DTOs/Reports/TrialBalanceData.php:66) through line 68 → [generated.d.ts:259](/Users/houssamr/Projects/syneriva/apps/erp/packages/shared/types/generated.d.ts:259) through line 264. |
| N1 — two citations land on unrelated permissions | CLOSED — the convention-10 rerun citation now targets role synchronization at [RolesAndPermissionsSeeder.php:568](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:568), and the permission citation now targets the manager `batches.recall` grant at [RolesAndPermissionsSeeder.php:653](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:653). Every absolute citation was semantically re-opened at the planning pin. |
| Staging boot synchronization fact | CLOSED — [`R5-BOOT-SYNC`](#r5-boot-sync), §§5, 11.4–11.10, 16, and 17 record `SYNC_PERMISSIONS_ON_BOOT=true`, treat every source push as a seeder run, compare pre/post role-permission snapshots while enforcement is false, and prove Push 4 is the only existing-tenant role/permission change. The boot command is [entrypoint.sh:157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:157) through line 165. |

No round-4 item is rejected and no owner decision is required. All prior closures and rejected false positives remain preserved.

## 0F. Round-3 change log

The governing review is [gate r3](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r3.md:1). Every finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-B1 — migration success regexes reject valid output | CLOSED — §11.5 uses `grep -Fxc 'Rolling tenant migrations across 1 tenant(s).'` and `grep -Fxc 'Done. 1 tenant(s) migrated, 0 failed.'`, matching the literal output at [RollingTenantMigrationCommand.php:73](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73) and [RollingTenantMigrationCommand.php:163](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163). Each tenant pass extracts those lines and compares them byte-for-byte with a literal heredoc through `cmp`. Every other escaped static-output ERE in §11 is replaced by fixed-string exact-line matching. |
| B-B2 — unresolved staging topology | SUPERSEDED BY ROUND 4 — [`R5-B-B2`](#r5-b-b2) closes the finding against the resolved separate-application fact sheet with exact application IDs, full-environment updates, sequential redeploys, inside-container readback, persisted Push 1–5 SHAs, and executable rollback. |
| B-B3 — prose and cross-shell deployment checks | CLOSED — §11.3 defines the complete host-shell `assert_exact_marker_set` dependency chain and invokes it directly. §§11.6 and 11.11 write OFF and ON records to aggregate files and compare them with committed literal heredoc expectations using `cmp`. §11.8 contains the full Push-4 day-one command. §11.10 defines and invokes complete rollout-window functions in the same shell. Every gate runs under `set -Eeuo pipefail` and has an explicit non-zero exit rule. |
| M-C — protected role remains writable through API and Roles page | CLOSED — [`R4-M-C`](#r4-m-c), §§8.1, 8.3, 8.8, 8.9, 9.1–9.3, 10, 13, 14, and 17 make the marker-derived role read-only. `RoleController::update()` currently synchronizes arbitrary permissions at [RoleController.php:223](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223) and [RoleController.php:245](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:245); Task 2 now rejects name and permission requests for the marked role and rejects deletion with stable `PROVISIONED_ROLE_READ_ONLY`/422 responses, proving grants unchanged. Task 6 consumes generated `is_provisioned_read_only`, suppresses edit/delete controls, and blocks modal submission. |
| M-D — `tenants:run` VALUE_NONE normalization omitted | CLOSED — [`R4-M-D`](#r4-m-d) requires `--apply` and `--verify` normalization for `true`, `1`, `'1'`, and `'true'`. This matches string forwarding at [Run.php:40](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:40), [Run.php:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:50), and the existing truthy precedent at [DayOneCensusCommand.php:83](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/DayOneCensusCommand.php:83). PostgreSQL red-first apply and verify wrapper tests assert exact mode markers. |
| N-B — inconsistent A-1b ownership lists | CLOSED — §§2 and 15 now contain the identical list: POS sale, direct batch transfer, and StockTransfer allocation. Delivery, write-off, stock-count, and return eligibility are explicitly deferred to named later lanes. |
| Consolidation | Consolidation — every by-reference sentence of the round-3 draft replaced by the verbatim rev-3 content plus the round-3 additions; citations re-verified at ad1d6ceb1df9428003f7e78ef0a53d6f38fb64db. §4 (convention-10 table and sources) and §7.3 (trace DTO property lists and controller mapping) were restored verbatim from rev 3 by the orchestrator after the consolidation pass dropped them. |

The gate’s rejected false positives remain rejected:

- A-1a implements no hold schema, request route, hold behavior, eligibility change, release/reject transition, or hold UI.
- The four-state A-1b enum/CHECK remains forward-compatible schema rather than premature release/reject behavior.
- A-1b exposes only `requested → recalled`; A-1c remains the sole release/reject owner.
- Dormant `batches.recall.request` provisioning remains a prerequisite.
- The marked-role PostgreSQL/SQLite constraints, triggers, partial index, guarded rollback, and role-ID preservation remain intact.
- Tasks 1, 2, and 6 retain their existing files, signatures, DTOs, red-first tests, convention-09 evidence, reviewers, and rollback contracts except for the named corrections above.
- Convention 10 retains its required eight-column header and nine rows.
- The glossary still names Settings → Users as the sole assignment surface.
- The shared-manifest checklist remains verbatim.
- No owner decision is required.

## 0G. Round-2 change log

The governing review is [gate r2](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r2.md:1). Every finding is closed below.

| Finding | Disposition and closing plan line |
|---|---|
| B-A — Q10 assigned to the wrong slice | CLOSED — “W-LOT-A-1b implements only request/hold and `requested → recalled`; W-LOT-A-1c exclusively implements release/reject transitions, GM authority, mandatory reasons, append-only evidence, routes, and UI.” See [`R3-B-A`](#r3-b-a) and §15. |
| B-B — deployment gates fail open | CLOSED — superseded and strengthened by round-3 B-B1 through B-B3. No deployment phase advances unless its executable gate exits zero, its captured tenant manifest is non-empty, and each expected UUID has exactly one valid marker with no failed, skipped, missing, duplicate, or unexpected marker. |
| M-A — middleware outside API contract | CLOSED — `BatchActionAccess` is attached only to reads, traceability, delete, and recall; create, update, transfer, and write-off retain their existing authorization unchanged. See [`R3-M-A`](#r3-m-a), §6.4, §7.2, and §7.6. |
| M-B — marked global system role allowed | CLOSED — a non-null `provisioning_source` requires the configured team column to be non-null, and the team-scoped partial unique index permits one marker per tenant. See [`R3-M-B`](#r3-m-b) and §8.9. |
| N-A — glossary names two canonical surfaces | CLOSED — `LotActionPermissionDelta` is the sole role-definition writer and Settings → Users is the sole assignment surface; Settings → Roles is read-only inspection. Round-3 M-C now carries that ruling through the API and web implementation. |

No round-2 item is rejected. The gate’s rejected false positives remain rejected: A-1a still contains no hold, eligibility, release/reject, POS refusal, transfer refusal, company-recall replacement, history, or hold UI implementation.

## 0H. Round-1 change log

The governing review is [gate r1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r1.md:1). Every finding is closed by a named plan line below.

| Finding | Disposition and closing plan line |
|---|---|
| B1 — constructors | CLOSED — all changed constructors reproduce correct FQCNs and promoted `private readonly` properties; NEW command constructors call `parent::__construct()`. See [`R2-B1`](#r2-b1) and the named-symbol census. |
| B2 — Push 3 seeder not inert | CLOSED — the generic seeder uses the legacy catalogue and role matrix while enforcement is off; only the explicit Push-4 delta changes existing tenants. Activated fresh provisioning uses the new matrix. Rollout-window tenants are re-censused and re-delta’d. See [`R2-B2`](#r2-b2). |
| B3 — premature browser gate | CLOSED — browser evidence is split into pre-activation web-only gating/fingerprint checks and post-activation duplicate/location checks. See [`R2-B3`](#r2-b3). |
| B4 — impossible SHA equality | CLOSED — this plan declares the SHA read, but dispatch records and pins its actual HEAD; drift triggers a symbol/citation recensus rather than an impossible equality with a pre-plan commit. See [`R2-B4`](#r2-b4). |
| B5 — fail-open fleet/census gates | CLOSED — every fleet phase captures a non-empty expected tenant set, validates exact-once success markers, rejects missing/duplicate/unexpected/`FAILED`/`SKIPPED` markers, and gates every census summary. The manifest checklist is copied verbatim and activation is worker → API → scheduler. See [`R3-B-B`](#r3-b-b). |
| M1 — trace DTO contract loss | CLOSED — three discriminated DTOs preserve every current forward-document, forward-POS, and backward-document field. See [`R2-M1`](#r2-m1). |
| M2 — delta transaction/team scope | CLOSED — the role delta captures, sets, and restores the Spatie team ID and places lock/read/write/verification inside one `DB::transaction`. See [`R2-M2`](#r2-m2). |
| M3 — assignment transaction | CLOSED — create, update, and dedicated assignment use one transaction covering ordered locks, merged-state validation, role mutation, and audit/event emission; team ID is restored in `finally`. See [`R2-M3`](#r2-m3). |
| M4 — denied mutation snapshots | CLOSED — exact cashier/viewer/manager denial tests compare batch, stock, reservation, trace-history, journal-entry, and journal-line snapshots before and after 403. See [`R2-M4`](#r2-m4). |
| M5 — two create affordances | CLOSED — one `canCreate` value gates both the header and empty-state links, with populated and empty-state tests. See [`R2-M5`](#r2-m5). |
| M6 — incomplete Task-6 symbols | CLOSED — every Task-6 Vitest and Playwright `describe`/`it` title, first failing assertion, command, and lane is enumerated. See [`R2-M6`](#r2-m6). |
| M7 — incomplete CLI | CLOSED — exactly one of `--apply`/`--verify` is mandatory; neither and both exit 2. Apply, verify, collision, schema failure, invalid-option, and wrapper-normalization command tests are named. See [`R2-M7`](#r2-m7) and [`R4-M-D`](#r4-m-d). |
| M8 — inventory reviewer omitted | CLOSED — `inventory-costing-reviewer` is mandatory for Tasks 1, 2, 6 and the combined gate. See [`R2-M8`](#r2-m8). |
| M9 — convention-10 matrix | CLOSED — the exact convention-10 header is used, rerun is `MATCH — Task 2`, and unsupported competitor claims are `NV` rather than `DIVERGE`. See [`R2-M9`](#r2-m9). |
| M10 — wrong typecheck command | CLOSED — verification uses the real `typecheck` and `typecheck:e2e` scripts. See [`R2-M10`](#r2-m10). |
| N1 — inoperative citations | CLOSED — manager recall points to the grant at line 653, `RequirePermission` to line 57, company creation to line 76, and viewer omission to the complete 726–763 grant range. See [`R2-N1`](#r2-n1). |
| N2 — unnamed selected location | CLOSED — the second-location test persists `allowed_location_ids=[B2]` and proves B2 is the selected restricted location. See [`R2-N2`](#r2-n2). |

## 1. Plan identity and dispatch pin

- Repository: `/Users/houssamr/Projects/syneriva/apps/erp`.
- Planning HEAD read in full: `c76435df4a98193dfacb80ad9c25839169179509`.
- Branch state: local `dev`.
- Commit: `docs(parapharmacy): Codex gate r7 on slice plan W-LOT-A-1a rev 7 — CHANGES-REQUIRED (M-F closed, B-B2 seeder design accepted; NEW B-C1 tenant DB name must come from Tenant::getDatabaseName() not tenant_<uuid>; B-B3a aggregate-before-validate + remaining heredoc cmps; M-E subsecond ordering)`.
- Plan preparation at the planning pin changed no production file, ran no test, and ran no git command; rev 8 is an in-place documentation edit over the rev-7 byte copy.
- Every absolute `path:line` citation in this plan was semantically re-opened and re-verified at the planning HEAD, including the cited line content rather than only path existence and range.
- The §6.5 named-symbol census was re-run against the current files.
- Rev 7 is the verbatim base for this round-7 correction.
- Manifest U-1 is resolved as `separate_applications`: API `x5wfthp8-7cVbiUfI6Hq7`, worker `KKYDsAvk4UpYfJXVmsDj2`, scheduler `HSXqHvmo_vq7NAYIjrE3T`, and web `mY6P_PHb4pw-2LdG1Y7Ml`; staging has no compose service and therefore no Push 6 ([U-1 resolution:7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7)).
- Preserve these unrelated untracked files:

  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

<a id="r2-b4"></a>
**R2-B4:** implementation does not compare HEAD to a commit that necessarily predates this saved plan. At dispatch, the implementer must run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
DISPATCH_SHA="$(git rev-parse HEAD)"
test -n "$DISPATCH_SHA"
git status --short
git show -s --format='%H %cI %s' "$DISPATCH_SHA"
```

Record `DISPATCH_SHA` in the dispatch evidence. If it equals the planning HEAD, the citations are pinned. If it differs:

1. Run the named-symbol census in §6 against `DISPATCH_SHA`.
2. Re-open every cited production seam.
3. Record corrected `path:line` citations in a dispatch addendum pinned to `DISPATCH_SHA`.
4. Confirm the complete source-push ledger still matches the production and test inventories.
5. Begin implementation only after that repin is reviewed.

Documentation-only ancestry does not block dispatch. Any production-tree change affecting a named seam requires the repin procedure; there is no unconditional equality gate against the planning SHA.

## 2. Outcome and hard scope

This slice contains only Tasks 1, 2, and 6 extracted from W-LOT-A-1 rev 5:

1. API action permissions and location-safe batch reads.
2. Seeded role delta and guarded `general_manager` assignment.
3. Web route/action gating and deterministic permission-map generation.

The staging topology is resolved as separate Dokploy applications; this slice has exactly five pushes, no compose branch, and no Push 6 ([U-1 resolution:7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7)).

It guarantees after activation:

- Every uncovered BatchExpiry read, traceability, deactivate, and recall HTTP action has its intended permission boundary.
- Existing create, update, transfer, and write-off authorization remains unchanged.
- Batch list, detail, stock, expiry, product-stock, POS suggestion, and trace reads honor company and membership-location scope.
- `null` membership scope remains unrestricted; `[]` remains fail-closed.
- Unrestricted users retain company lot metadata, including zero-stock lots and null-location history.
- Restricted users see only current or historical lots attributable to allowed locations.
- `BatchExpiry` imports no Document or POS persistence models for trace queries.
- Batch totals are four-decimal strings computed only from the scoped eager-loaded relation.
- `manager` loses `batches.recall` and gains dormant `batches.recall.request`.
- A marked seeded `general_manager` receives the revised manager set plus `batches.recall` and `treasury.manage_all_locations`.
- `general_manager` may be assigned only when every active company membership is unrestricted.
- The marked role cannot be global, renamed, duplicated within a tenant team, deleted, or have its permissions synchronized through the ordinary Roles API.
- Settings → Roles consumes the API’s marker-derived read-only field and exposes the marked role only for inspection.
- The web uses the same exact permission names as the API.
- Push 3 is behaviorally inert while `LOT_ACTION_PERMISSIONS_ENFORCE=false`.

Explicitly deferred:

W-LOT-A-1b implements only:

- Recall-request and branch-hold tables.
- Initial status enum/CHECK accepting `requested`, `recalled`, `released`, and `rejected`.
- Request/hold creation and local branch holds.
- The `requested → recalled` transition.
- Recall escalation, operation UUIDs, replay, concurrency, and append-only request/recalled evidence.
- Eligibility changes only for POS sale, direct batch transfer, and StockTransfer allocation.
- Delivery, write-off, stock-count, and return eligibility changes are deferred to named later lanes.
- Company-wide recall-service replacement.
- Request/history/capability routes and request/history UI.
- The existing web recall payload defect.

Its initial schema must accept all four forward-compatible states—`requested`, `recalled`, `released`, and `rejected`—but A-1b must expose and implement no release/reject transition, authority, route, or UI.

W-LOT-A-1c exclusively implements:

- `requested → released` and `requested → rejected`.
- General-manager-only release/reject authority.
- Mandatory release/reject reasons.
- Append-only release/reject evidence.
- Release/reject routes and UI.
- Enforcement that the requesting branch never lifts its own hold.

The named later lanes for delivery, write-off, stock-count, and return eligibility must be assigned when those workflows are separately sourced; neither A-1a nor A-1b owns them.

A-1a and A-1b must not implement the A-1c behaviors.

This slice must not:

- Change `Batch::recall()` behavior.
- Fix the existing web recall payload key.
- Change quantity, reservation, valuation, stock movement, or GL posting behavior.
- Add a handwritten web trace DTO.
- Add a recall-request button.
- Add release or reject permissions.
- Add route middleware to create, update, transfer, or single-lot write-off.

The existing web payload remains `{ recall_reason: ... }` at [BatchDetailPage.tsx:49](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:49); W-LOT-A-1b owns that defect.

## 3. Owner rulings

### 3.1 RD2 — verbatim

> A manager is branch-linked, or is a **general manager** (explicit company-wide role). Recall is a safety action: a branch manager **initiates** a recall for a lot present in their branch; the recall **escalates** to the general manager, who executes it company-wide. Permissions stay tight; escalation, not denial.

Source: [OWNER-RULINGS:9](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:9).

A-1a implements only the permission and role split. A-1b implements request/hold and `requested → recalled`.

### 3.2 Q4 — verbatim

> CONFIRMED: new seeded `general_manager` role (manager set, no location restriction, `batches.recall`, `treasury.manage_all_locations`).

Source: [OWNER-RULINGS:113](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:113).

### 3.3 Q10 — verbatim

> Hold lifecycle `requested → recalled` or `requested → released`; only the general manager may release or reject; mandatory reason and append-only evidence; the requesting branch never lifts its own hold.

Source: [OWNER-RULINGS:151](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:151).

The ruled process consequence is:

> Later slices (hold release/reject, drawer session, reason codes, alignment) may now implement the ruled branches.

Source: [OWNER-RULINGS:158](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:158).

<a id="r3-b-a"></a>
**R3-B-A:** slice ownership is exact:

| Slice | Q10 portion implemented |
|---|---|
| W-LOT-A-1a | No hold lifecycle or Q10 state schema; permission split and unrestricted GM prerequisite only |
| W-LOT-A-1b | Request/hold plus `requested → recalled` only |
| W-LOT-A-1c | Release/reject transitions, GM authority, mandatory reasons, append-only evidence, routes, and UI |

W-LOT-A-1b must declare NEW `App\Modules\BatchExpiry\Domain\Enums\BatchRecallRequestStatus`:

```php
enum BatchRecallRequestStatus: string
{
    case Requested = 'requested';
    case Recalled = 'recalled';
    case Released = 'released';
    case Rejected = 'rejected';
}
```

Binding: none; this is a backed domain enum.

A-1b’s initial `batch_recall_requests.status` enum/CHECK must accept exactly:

```text
requested
recalled
released
rejected
```

Accepting `released` and `rejected` in the initial schema is forward compatibility, not authorization to implement those transitions in A-1b. W-LOT-A-1c is their sole behavior owner.

## 4. Industry baseline (benchmark-first — convention 10)

<a id="r2-m9"></a>
**R2-M9:** the following table uses the exact convention-10 header and decision vocabulary from [10-BENCHMARK-FIRST-SPECS.md:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37).

Flow: permissioned lot reads, restricted-location visibility, central recall authority, and deterministic role provisioning. Reference systems: Odoo 18 and current ERPNext manuals. A competitor cell is `NV` when the cited material does not directly establish the guarantee.

Sources:

- [Odoo 18 lots documentation](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/lots.html)
- [Odoo 18 access-rights documentation](https://www.odoo.com/documentation/18.0/applications/general/users/access_rights.html)
- [ERPNext Batch documentation](https://docs.frappe.io/erpnext/user/manual/en/batch)
- [ERPNext User Permissions documentation](https://docs.frappe.io/erpnext/user/manual/en/user-permissions)

| # | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| create | Creating a lot requires explicit action authority | Model operations are controlled through access rights | Document operations are role/permission controlled | NV — exact batch-create contract not verified | `CreateBatchRequest::authorize()` already requires `batches.create` at [CreateBatchRequest.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22) | None in the contracted API scope | ALREADY — preserve in Task 1 |
| duplicate | A repeated create has a meaningful, non-mutating outcome | NV — lot documentation does not establish retry response semantics | NV — Batch documentation does not establish retry response semantics | NV — not verified | Duplicate returns a generic 422 without an outcome at [BatchController.php:138](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:138) | No `already_exists` result | MATCH — Task 1 |
| edit | Lot metadata updates require explicit update authority | Model write access is group-controlled | Document updates are permission-controlled | NV — exact batch-edit contract not verified | `UpdateBatchRequest::authorize()` already requires `batches.update` at [UpdateBatchRequest.php:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:13) | None in the contracted API scope | ALREADY — preserve in Task 1 |
| cancel | Lot deactivation requires explicit delete/deactivate authority | Model write/unlink access is group-controlled | Delete/cancel operations are permission-controlled | NV — exact batch-deactivation contract not verified | DELETE has no action guard at [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26) | Body-less route bypasses FormRequest authorization | MATCH — Task 1 |
| rerun | Role provisioning is deterministic and does not erase unrelated grants | NV — cited access documentation does not prove module-update idempotency | NV — cited manuals do not prove reseed idempotency | NV — not verified | Seeded roles are blindly synchronized at [RolesAndPermissionsSeeder.php:568](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:568) | Rerun can erase tenant-authored grants | MATCH — Task 2 |
| second company | Company B cannot observe company A’s lot | Access rules can be company-scoped, but exact lot guarantee is not established here: NV | User permissions support constrained document access; exact cross-company lot guarantee is not established here: NV | NV — not verified | Detail explicitly returns 404 on company mismatch at [BatchController.php:68](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:68), but trace adapters do not yet carry the predicate | Trace and read paths are inconsistent | MATCH — Task 1 |
| second location | A branch user sees only attributable branch stock/history | NV — cited pages do not directly establish this precise trace rule | User Permissions can restrict linked records such as Warehouse | NV — not verified | Detail loads all stock rows at [BatchController.php:112](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:112) | Scope not threaded through every read | MATCH — Task 1 |
| permission | Company-wide recall is held by a distinct unrestricted authority | NV — cited lot/access pages do not define a central recall role | NV — cited Batch/User Permission pages do not define a central recall role | NV — not verified | Manager currently receives global recall at [RolesAndPermissionsSeeder.php:653](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:653) | No distinct unrestricted general-manager role | MATCH — Task 2 |
| audit | Role assignment records actor, target, company, role, and time | NV — cited page establishes access control, not this audit payload | NV — not established by cited manuals | NV — not verified | Dedicated assignment emits `RoleAssigned` with the required values at [RoleController.php:365](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:365) | Existing assignment audit already covers the guarantee | ALREADY — preserve in Task 2 |

Second-of-everything: Task 1 creates company B through the real endpoint, selects a second POS-enabled location B2, and repeats batch creation with `already_exists`; Task 2 repeats the permission delta and tests an explicitly B2-restricted second-company membership; Task 6 repeats those guarantees in the post-activation browser lane.

## 5. Vocabulary and current-state evidence

Concepts: Lot (batch) (glossary ✅), Location (glossary ✅), Membership (glossary ✅), General manager (NEW — glossary row added in this lane).

Add this exact row to the existing five-column glossary table:

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **General manager** | A seeded tenant role containing the revised manager grants plus company-wide lot recall and all-location Treasury authority; every active company membership held by the assignee must be unrestricted. | Existing Spatie `roles`, marked by `roles.provisioning_source` / Identity | Settings → Users (assignment) | central manager |

<a id="r3-n-a"></a>
**R3-N-A:** NEW `App\Modules\Identity\Application\Services\LotActionPermissionDelta` is the sole role-definition writer. Settings → Users is the sole operator assignment surface. Settings → Roles may remain visible only as read-only inspection for the protected seeded role; it is not a second canonical editor. The code identifier `general_manager` is not a UI synonym.

<a id="r4-m-c"></a>
**R4-M-C:** the sole-writer ruling is an enforced task contract:

- The roles API emits marker-derived `is_provisioned_read_only`.
- The value is true only for the team-scoped `general_manager` whose `provisioning_source` is `w-lot-a-1a`; it is never inferred from the name alone.
- Any `RoleController::update()` request containing `name` or `permissions` for that role returns HTTP 422 with `error.code=PROVISIONED_ROLE_READ_ONLY` before validation can mutate the row or pivots.
- `RoleController::destroy()` returns the same status and code for the marked role before user-count or delete behavior.
- Rejected update tests refresh the role and compare its name, ordered permission names, role ID, and pivot cardinality byte-for-byte with the pre-request snapshot.
- Settings → Roles consumes the generated field, renders the role as read-only, omits edit/delete affordances, refuses `openEditModal`, and returns before `updateMutation.mutate()` if a protected role reaches `handleSubmit`.
- Existing protection for `super-admin`, `admin`, and `owner` remains unchanged.

Current update accepts arbitrary permissions at [RoleController.php:223](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223) and synchronizes them at [RoleController.php:245](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:245). Current delete begins at [RoleController.php:272](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:272). The current web protects only three names at [RolesPage.tsx:35](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:35), submits permissions at [RolesPage.tsx:178](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:178), and renders the general role-card action path at [RolesPage.tsx:253](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:253).

Do not add Recall request, Branch hold, Recall transition, or Global recall evidence rows in A-1a.

Current operative evidence:

- The BatchExpiry route group retains API authentication, Spatie team setup, tenant-claim enforcement, and module gating at [routes.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12).
- The uncovered read/delete/recall/trace guards are visible at [routes.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:14), [routes.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22), [routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:26), [routes.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29), and [routes.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:38).
- Create and update authorize through [CreateBatchRequest.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:22) and [UpdateBatchRequest.php:13](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:13).
- Grouped and reversed write-off routes use `batches.write-off` at [routes.php:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:18) and [routes.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/routes.php:34).
- Expiring reads an unvalidated location string at [BatchController.php:219](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:219); expired validates UUIDs and resolves scope at [BatchController.php:241](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:241) and [BatchController.php:252](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:252).
- Stock is read across all locations at [BatchController.php:271](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:271).
- Batch totals use relation accessors that issue unscoped aggregate queries and cast to float at [Batch.php:143](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:143) and are serialized at [BatchResource.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39).
- `LocationContext` returns `[]` for no active membership and `null` for unrestricted membership at [LocationContext.php:194](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationContext.php:194).
- `LocationScopeResolver` rejects requested IDs outside scope at [LocationScopeResolver.php:41](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Services/LocationScopeResolver.php:41).
- Permission teams are enabled and tenant-keyed at [permission.php:127](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:127); the configured team column is `tenant_id` at [permission.php:99](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:99).
- HTTP requests set the team ID from the authenticated tenant at [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22) and [SetPermissionsTeam.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28).
- Identity role-management and assignment routes are mounted behind authentication, team, and tenant-claim middleware at [Identity routes.php:58](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/routes.php:58).
- New registration invokes tenant initialization at [TenantProvisioningService.php:222](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:222), which runs `RolesAndPermissionsSeeder` when roles are absent at [TenantInitializationService.php:199](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:199) and [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213).
- Staging API has `SYNC_PERMISSIONS_ON_BOOT=true`, so every source push boots the API and invokes `tenants:seed --class=RolesAndPermissionsSeeder`; the exact boot branch is [entrypoint.sh:157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:157) through line 165, and the staging setting is recorded at [U-1 resolution:32](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:32). §11 therefore proves that each flag-off boot seed is inert for existing tenants and that Push 4 is the only existing-tenant role/permission change.
- Role team IDs are nullable at [2025_11_29_231806_create_permission_tables.php:37](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:37), and existing name uniqueness includes the team column at [2025_11_29_231806_create_permission_tables.php:44](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:44).
- User creation assigns a role before membership creation at [UserController.php:232](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232); update synchronizes roles before location scope at [UserController.php:380](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380).
- Dedicated assignment writes directly at [RoleController.php:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350).
- Both batch-create links are unconditional at [BatchListPage.tsx:86](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:86) and [BatchListPage.tsx:132](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:132).
- Web batch routes use the broad inventory key at [routes/index.tsx:1205](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:1205), navigation is module-only at [Sidebar.tsx:243](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:243), and detail action booleans ignore authorization at [BatchDetailPage.tsx:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:75).

<a id="r2-n1"></a>
**R2-N1:** operative evidence remains:

- Manager’s current recall grant: [RolesAndPermissionsSeeder.php:653](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:653).
- Exact permission check: [RequirePermission.tsx:57](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/auth/components/RequirePermission.tsx:57).
- Real second-company payload begins at [CreateCompanyTest.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:76).
- Viewer’s complete current grants, proving omission of `batches.view`: [RolesAndPermissionsSeeder.php:726](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:726) through line 763.

## 6. Shared contracts and named-symbol census

### 6.1 Activation contract

- Config key: `lot_action_permissions.enforce`.
- Environment key: `LOT_ACTION_PERMISSIONS_ENFORCE`.
- Default: `false`.
- Application code reads Laravel configuration only; it does not call `env()` outside the config file.
- While false:

  - New route middleware passes through immediately.
  - Newly location-scoped endpoints retain pre-activation read behavior.
  - Duplicate creation retains the existing payload.
  - Generic seeding uses the exact legacy permission catalogue and legacy role synchronization behavior.
  - Generic seeding does not create `general_manager`, remove manager recall, or add viewer/operator grants.

- Push 4 changes existing tenants only through `permissions:apply-lot-action-delta --apply`.
- No fleet-wide generic reseed runs while the flag is false.
- After API activation, fresh registration uses the canonical new matrix.
- Registration is paused during final pre-activation census/activation, then resumed only after post-activation recensus.

<a id="r2-b2"></a>
**R2-B2:** Push 3 is inert even for newly registered tenants because `RolesAndPermissionsSeeder::run()` selects its legacy helpers while the flag is false. Existing-tenant role changes are owned only by the Push-4 delta. Tenants created between Push 3 and the activation maintenance window are included by the final pre-activation recensus and delta; a post-activation recensus proves no tenant escaped.

### 6.2 Location and visibility contract

- `null` means every location in an active company membership.
- `[]` means no visible location.
- A non-empty list means exactly those UUIDs.
- `[]` is never converted into an omitted predicate.
- User-supplied location IDs are UUID-validated.
- Requested IDs pass through `LocationScopeResolver` with no Treasury bypass permission.
- Company mismatch remains indistinguishable from absence.
- Unrestricted users see zero-stock company lots, depleted history, and null-location history.
- Restricted users see lots with current allowed stock or attributable allowed-location history.
- Restricted users receive only allowed stock rows; null-location history is excluded.
- Empty scope returns no list, stock, expiry, product-stock, POS, or trace results and detail returns 404.

### 6.3 Decimal aggregate contract

- `BatchResource` sums only the loaded `batchStock` collection.
- It performs no fresh relationship query.
- `total_quantity` and `available_quantity` are decimal strings at scale four.
- No float conversion is permitted.
- `total_quantity` remains physical on-hand.
- `available_quantity` remains on-hand minus reserved.
- Neither becomes hold-aware.

### 6.4 Permission route matrix

<a id="r3-m-a"></a>
**R3-M-A:** the authoring contract limits new middleware enforcement to body-less recall/deactivate, traceability, and reads. The complete route matrix is:

| Existing surface | Permission after activation |
|---|---|
| GET `/api/v1/batches` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/expiring` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/expired` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}/stock` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/products/{productId}/batch-stock` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/pos/products/{productId}/batches` | NEW `BatchActionAccess:batches.view` |
| GET `/api/v1/batches/{uuid}/traceability` | NEW `BatchActionAccess:batches.traceability` |
| GET `/api/v1/partners/{partnerId}/batch-history` | NEW `BatchActionAccess:batches.traceability` |
| POST `/api/v1/batches` | Existing `CreateBatchRequest::authorize()` only; unchanged |
| PATCH `/api/v1/batches/{uuid}` | Existing `UpdateBatchRequest::authorize()` only; unchanged |
| DELETE `/api/v1/batches/{uuid}` | NEW `BatchActionAccess:batches.delete` |
| POST `/api/v1/batches/{uuid}/recall` | NEW `BatchActionAccess:batches.recall` |
| POST `/api/v1/batches/{uuid}/transfer` | Existing `TransferBatchStockRequest` authorization; unchanged |
| POST `/api/v1/batches/{uuid}/write-off` | Existing `WriteOffBatchRequest` authorization; unchanged |
| POST `/api/v1/batches/write-off-grouped` | Existing `can:batches.write-off`; unchanged |
| POST `/api/v1/stock-movements/{movementId}/reverse-write-off` | Existing `can:batches.write-off`; unchanged |

No `BatchActionAccess` middleware is added to create, update, transfer, single-lot write-off, grouped write-off, or reverse-write-off. No `batches.recall.request` route is added.

### 6.5 Named-symbol census at planning HEAD

Existing symbols verified at `b05506eef4f477d86d4745b219a8651b11068801`:

| Existing FQCN/symbol | Operative evidence |
|---|---|
| `App\Modules\BatchExpiry\Presentation\Controllers\BatchController::__construct()` | [BatchController.php:33](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:33) |
| `App\Modules\BatchExpiry\Presentation\Controllers\BatchTraceabilityController::__construct()` | [BatchTraceabilityController.php:28](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:28) |
| `App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface` | [BatchRepositoryInterface.php:10](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php:10) |
| `App\Modules\BatchExpiry\Infrastructure\Persistence\BatchRepository` | [BatchRepository.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:12) |
| `App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService` | [FEFOInventoryService.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:45) |
| `App\Modules\BatchExpiry\Presentation\Resources\BatchResource::toArray()` | [BatchResource.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:20) |
| `App\Modules\Document\Providers\DocumentServiceProvider::register()` | [DocumentServiceProvider.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php:30) |
| `App\Modules\POS\Providers\POSServiceProvider::register()` | [POSServiceProvider.php:38](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/POS/Providers/POSServiceProvider.php:38) |
| `Database\Seeders\RolesAndPermissionsSeeder::__construct()` | [RolesAndPermissionsSeeder.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:14) |
| `App\Modules\Identity\Presentation\Controllers\UserController::__construct()` | [UserController.php:55](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:55) |
| `App\Modules\Identity\Presentation\Controllers\RoleController::__construct()` | [RoleController.php:58](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:58) |
| `App\Modules\Identity\Presentation\Controllers\RoleController::index()` | [RoleController.php:123](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:123) |
| `App\Modules\Identity\Presentation\Controllers\RoleController::update()` | [RoleController.php:223](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223) |
| `App\Modules\Identity\Presentation\Controllers\RoleController::destroy()` | [RoleController.php:272](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:272) |
| `App\Modules\BatchExpiry\BatchExpiryServiceProvider::boot()` | [BatchExpiryServiceProvider.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php:23) |
| `App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam::handle()` | [SetPermissionsTeam.php:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22) |
| `App\Services\CompanyConfigService` | Imported by [RoleController.php:16](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:16) |
| `App\Modules\Tenant\Application\Services\IdentityIndexService` | Imported by [UserController.php:23](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:23) |
| `App\Modules\Tenant\Application\Services\TenantLinkSigner` | Imported by [UserController.php:24](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:24) |
| `App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService` | Imported at [BatchController.php:12](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:12) |
| `App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService` | Imported at [BatchController.php:14](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:14) |
| `Stancl\Tenancy\Commands\Run` VALUE_NONE boundary | [Run.php:40](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:40) and [Run.php:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:50) |
| `App\Console\Commands\DayOneCensusCommand` truthy normalization precedent | [DayOneCensusCommand.php:83](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/DayOneCensusCommand.php:83) |
| `AppRoutes` | [index.tsx:350](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/routes/index.tsx:350) |
| `buildNavigation(boolean): NavModule[]` | [Sidebar.tsx:145](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/components/organisms/Sidebar/Sidebar.tsx:145) |
| `BatchListPage()` | [BatchListPage.tsx:18](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchListPage.tsx:18) |
| `BatchDetailPage()` | [BatchDetailPage.tsx:15](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/batches/pages/BatchDetailPage.tsx:15) |
| `RolesPage()` | [RolesPage.tsx:61](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/features/settings/RolesPage.tsx:61) |
| `usePermissions()` | [usePermissions.ts:185](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/usePermissions.ts:185) |
| `MODULE_PERMISSIONS` | [usePermissions.ts:55](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/usePermissions.ts:55) |
| Generated `PERMISSIONS` map | [permissionsMap.generated.ts:5](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/src/hooks/permissionsMap.generated.ts:5) |
| Identity role-management and assignment routes | [Identity routes.php:58](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/routes.php:58) |

NEW symbols are declared with complete FQCNs and signatures in Tasks 1 and 2. NEW test classes and exact methods are declared in each task’s test inventory. Laravel auto-resolution is the binding unless an explicit provider binding is specified.

<a id="r2-b1"></a>
**R2-B1:** every constructor added or changed by this slice appears below with correct namespaces, promoted `private readonly` properties, and—where the parent is `Illuminate\Console\Command`—an explicit `parent::__construct()` call. Adapters with no dependencies and `GeneralManagerAssignmentGuard` deliberately declare no constructor.

## 7. Task 1 — API action permissions and location-safe reads

### 7.1 Files

Add:

- `apps/api/config/lot_action_permissions.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/LotActionPermissionActivation.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/ForwardDocumentBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/ForwardPosBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/BackwardDocumentBatchTraceData.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/DocumentBatchTraceReader.php`
- `apps/api/app/Shared/Contracts/BatchTraceability/PosBatchTraceReader.php`
- `apps/api/app/Modules/Document/Infrastructure/BatchTraceability/DocumentBatchTraceReaderAdapter.php`
- `apps/api/app/Modules/POS/Infrastructure/BatchTraceability/PosBatchTraceReaderAdapter.php`

Modify:

- `apps/api/app/Modules/BatchExpiry/Presentation/routes.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Repositories/BatchRepositoryInterface.php`
- `apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php`
- `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/Document/Providers/DocumentServiceProvider.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`

Add tests:

- `apps/api/tests/Feature/BatchExpiry/BatchActionPermissionsTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php`
- `apps/api/tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php`
- `apps/api/tests/Architecture/BatchTraceabilityModuleBoundaryTest.php`

### 7.2 Config and activation

`apps/api/config/lot_action_permissions.php` has no namespace:

```php
return [
    'enforce' => (bool) env('LOT_ACTION_PERMISSIONS_ENFORCE', false),
];
```

NEW `App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation`:

```php
final readonly class LotActionPermissionActivation
{
    public function __construct(
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {}

    public function enforced(): bool;
}
```

Binding: Laravel constructor auto-resolution.

NEW `App\Modules\BatchExpiry\Presentation\Middleware\BatchActionAccess`:

```php
final readonly class BatchActionAccess
{
    public function __construct(
        private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    ) {}

    public function handle(
        \Illuminate\Http\Request $request,
        \Closure $next,
        string $permission,
    ): \Symfony\Component\HttpFoundation\Response;
}
```

Binding: direct middleware class reference in `routes.php`.

The only accepted middleware arguments are:

```text
batches.view
batches.traceability
batches.delete
batches.recall
```

Unknown arguments return 403. Flag-off returns `$next($request)` before permission evaluation. `batches.create`, `batches.update`, and `batches.write-off` are deliberately not accepted by this middleware because their existing authorization is preserved.

### 7.3 Trace response compatibility

<a id="r2-m1"></a>
**R2-M1:** current response serialization is owned by `BatchTraceabilityController`: forward-document fields at [BatchTraceabilityController.php:65](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:65), forward-POS fields at [BatchTraceabilityController.php:80](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:80), the forward envelope at [BatchTraceabilityController.php:90](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:90), backward fields at [BatchTraceabilityController.php:141](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:141), and the backward envelope at [BatchTraceabilityController.php:155](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:155).

NEW `App\Shared\Contracts\BatchTraceability\ForwardDocumentBatchTraceData`:

```php
final readonly class ForwardDocumentBatchTraceData
{
    public function __construct(
        public readonly string $type,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
        public readonly string $partnerName,
        public readonly ?string $partnerId,
        public readonly string $productName,
        public readonly string $quantity,
    ) {}
}
```

`type` must equal `document`.

NEW `App\Shared\Contracts\BatchTraceability\ForwardPosBatchTraceData`:

```php
final readonly class ForwardPosBatchTraceData
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $receiptNumber,
        public readonly ?string $saleDate,
        public readonly ?string $customerName,
        public readonly ?string $customerIdentifier,
        public readonly ?string $batchNumber,
        public readonly string $quantity,
    ) {}
}
```

`type` must equal `pos_receipt`.

NEW `App\Shared\Contracts\BatchTraceability\BackwardDocumentBatchTraceData`:

```php
final readonly class BackwardDocumentBatchTraceData
{
    public function __construct(
        public readonly ?string $batchNumber,
        public readonly int $batchId,
        public readonly ?string $expiryDate,
        public readonly bool $isRecalled,
        public readonly bool $isExpired,
        public readonly string $productName,
        public readonly string $productId,
        public readonly string $quantity,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $documentDate,
    ) {}
}
```

The controller maps those DTO properties back to the existing snake-case JSON keys exactly. It preserves:

- `document_type` and `customer_identifier`.
- Forward `document_sales`, `pos_sales`, and `total_sales_count`.
- Forward batch `id`, `uuid`, `batch_number`, `product_name`, `expiry_date`, and `is_recalled`.
- Backward `expiry_date`, `is_recalled`, and `is_expired`.
- Existing nullability and string/boolean/integer JSON types.
- Existing top-level `data` envelopes.

NEW `App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader`:

```php
interface DocumentBatchTraceReader
{
    /** @return list<ForwardDocumentBatchTraceData> */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /** @return list<BackwardDocumentBatchTraceData> */
    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;

    /** @return list<int> */
    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

NEW `App\Shared\Contracts\BatchTraceability\PosBatchTraceReader`:

```php
interface PosBatchTraceReader
{
    /** @return list<ForwardPosBatchTraceData> */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /** @return list<int> */
    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

NEW `App\Modules\Document\Infrastructure\BatchTraceability\DocumentBatchTraceReaderAdapter`:

```php
final readonly class DocumentBatchTraceReaderAdapter implements
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader
{
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;

    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

No constructor is declared. It applies tenant and company predicates before line predicates. Effective location uses line location first and document location only when line location is null. Null effective locations are returned only for unrestricted reads.

NEW `App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter`:

```php
final readonly class PosBatchTraceReaderAdapter implements
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader
{
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
```

No constructor is declared. It applies receipt tenant, company, and persisted-location predicates. Null-location receipts are included only when `$locationIds === null`.

Add these exact bindings to the existing provider `register()` methods:

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader::class,
    \App\Modules\Document\Infrastructure\BatchTraceability\DocumentBatchTraceReaderAdapter::class,
);
```

```php
$this->app->bind(
    \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader::class,
    \App\Modules\POS\Infrastructure\BatchTraceability\PosBatchTraceReaderAdapter::class,
);
```

### 7.4 Repository and service signatures

Modify both interface and implementation:

```php
public function findVisibleByUuid(
    string $uuid,
    string $companyId,
    ?array $locationIds,
    array $historicallyVisibleBatchIds = [],
): ?\App\Modules\BatchExpiry\Domain\Entities\Batch;

public function getByProduct(
    string $tenantId,
    string $companyId,
    string $productId,
    bool $activeOnly = true,
    ?array $locationIds = null,
): \Illuminate\Support\Collection;

public function getByCompany(
    string $companyId,
    array $filters = [],
    ?array $locationIds = null,
    array $historicallyVisibleBatchIds = [],
): \Illuminate\Support\Collection;
```

Behavior:

- UUID and company always match.
- `null` retains company-wide zero-stock metadata.
- A list requires allowed current stock or a supplied historical-visible ID.
- `[]` returns nothing unless the historical ID set explicitly contains the batch.
- Every eager-loaded `batchStock` relation uses the same resolved location predicate.
- Product reads retain tenant, company, product, active-only, FEFO, and deterministic ID ordering.

Modify `App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService`:

```php
public function getExpiringProducts(
    string $companyId,
    int $daysThreshold = 30,
    string|array|null $locationId = null,
): \Illuminate\Support\Collection;

public function getBatchStockByLocation(
    string $batchId,
    ?array $locationIds = null,
): \Illuminate\Support\Collection;
```

Its existing constructor remains:

```php
public function __construct(
    private readonly \App\Modules\Product\Application\Contracts\ProductVariantLookup $variantLookup,
) {}
```

`getExpiredBatchesWithStock()` retains its current signature and fail-closed `[]` semantics. Expiring existence and eager-load predicates use the same location list.

Modify `BatchResource`:

```php
public function toArray(
    \Illuminate\Http\Request $request,
): array;

private function scopedTotalQuantity(): string;

private function scopedAvailableQuantity(): string;
```

### 7.5 Correct controller constructors and signatures

Modify `App\Modules\BatchExpiry\Presentation\Controllers\BatchController`:

```php
public function __construct(
    private readonly \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    private readonly \App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService $fefoService,
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\BatchExpiry\Application\Services\BatchStockService $batchStockService,
    private readonly \App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService $batchWriteOffService,
    private readonly \App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService $reverseWriteOffService,
    private readonly \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    private readonly \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
) {}
```

Add:

```php
private function resolvedReadLocationIds(
    \Illuminate\Http\Request $request,
    array $requestedLocationIds = [],
): ?array;

private function historicallyVisibleBatchIds(
    string $tenantId,
    string $companyId,
    array $locationIds,
): array;

private function findVisibleBatchOrFail(
    \Illuminate\Http\Request $request,
    string $uuid,
): \App\Modules\BatchExpiry\Domain\Entities\Batch|\Illuminate\Http\JsonResponse;
```

Modify:

```php
public function index(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\Resources\Json\AnonymousResourceCollection;

public function show(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function store(
    \App\Modules\BatchExpiry\Presentation\Requests\CreateBatchRequest $request,
): \Illuminate\Http\JsonResponse;

public function expiring(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\JsonResponse;

public function expired(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\JsonResponse;

public function stock(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function posAvailableBatches(
    \Illuminate\Http\Request $request,
    string $productId,
): \Illuminate\Http\JsonResponse;

public function productBatchStock(
    \Illuminate\Http\Request $request,
    string $productId,
): \Illuminate\Http\JsonResponse;
```

Flag-off returns legacy read and duplicate behavior. Flag-on validates `expiring.location_id`, resolves membership without bypass, filters all reads, and adds `meta.outcome="already_exists"` to the existing 422 duplicate response.

Modify `App\Modules\BatchExpiry\Presentation\Controllers\BatchTraceabilityController`:

```php
public function __construct(
    private readonly \App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface $batchRepository,
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\Company\Services\LocationScopeResolver $locationScopeResolver,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader $documentTraceReader,
    private readonly \App\Shared\Contracts\BatchTraceability\PosBatchTraceReader $posTraceReader,
) {}

public function forwardTrace(
    \Illuminate\Http\Request $request,
    string $uuid,
): \Illuminate\Http\JsonResponse;

public function backwardTrace(
    \Illuminate\Http\Request $request,
    string $partnerId,
): \Illuminate\Http\JsonResponse;

private function resolvedTraceLocationIds(
    \Illuminate\Http\Request $request,
): ?array;
```

Remove all imports of:

- `App\Modules\Document\Domain\Document`
- `App\Modules\Document\Domain\DocumentLine`
- `App\Modules\Document\Domain\Enums\DocumentType`
- `App\Modules\POS\Domain\ReceiptLineBatchAllocation`

### 7.6 Tests and denied-mutation evidence

NEW `Tests\Feature\BatchExpiry\BatchActionPermissionsTest`:

```php
final class BatchActionPermissionsTest extends \Tests\TestCase
{
    public function test_each_existing_batch_route_requires_its_exact_action_permission(): void;

    public function test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware(): void;

    public function test_flag_off_preserves_pre_activation_access_and_payloads(): void;

    public function test_cashier_denied_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_viewer_denied_delete_leaves_stock_history_reservations_and_gl_unchanged(): void;

    public function test_manager_denied_global_recall_leaves_stock_history_reservations_and_gl_unchanged(): void;
}
```

`test_each_existing_batch_route_requires_its_exact_action_permission()` tests the complete matrix in §6.4, including the existing authorization sources. It must not expect `BatchActionAccess` on create, update, transfer, or write-off.

NEW `Tests\Feature\BatchExpiry\BatchReadLocationScopeTest`:

```php
final class BatchReadLocationScopeTest extends \Tests\TestCase
{
    public function test_detail_excludes_other_branch_stock(): void;

    public function test_every_read_filters_other_branch_and_empty_scope(): void;

    public function test_zero_stock_company_lot_is_visible_only_to_unrestricted_actor(): void;

    public function test_depleted_lot_is_visible_only_through_attributable_history(): void;

    public function test_second_company_selected_second_location_and_duplicate_create_are_isolated(): void;
}
```

NEW `Tests\Feature\BatchExpiry\BatchExpiringLocationScopeTest`:

```php
final class BatchExpiringLocationScopeTest extends \Tests\TestCase
{
    public function test_expiring_validates_uuid_and_filters_loaded_stock(): void;

    public function test_empty_membership_scope_returns_no_expiring_lots(): void;
}
```

NEW `Tests\Feature\BatchExpiry\BatchTraceReaderContractTest`:

```php
final class BatchTraceReaderContractTest extends \Tests\TestCase
{
    public function test_document_and_pos_adapters_apply_company_and_location_scope(): void;

    public function test_nullable_location_is_visible_only_to_unrestricted_membership(): void;

    public function test_forward_and_backward_json_contracts_are_field_for_field_compatible(): void;
}
```

NEW `Tests\Architecture\BatchTraceabilityModuleBoundaryTest`:

```php
final class BatchTraceabilityModuleBoundaryTest extends \PHPUnit\Framework\TestCase
{
    public function test_batch_expiry_traceability_imports_only_shared_contracts(): void;
}
```

<a id="r2-m4"></a>
**R2-M4:** each denied mutation test snapshots, in deterministic primary-key order, the target `product_batches` row, `inventory_batch_stock`, `stock_reservations`, `document_lines`, `pos_receipt_line_batch_allocations`, `journal_entries`, and `journal_lines`. After the 403 it asserts strict equality of the normalized before/after arrays and unchanged row counts. It also asserts unchanged `is_active`, `is_recalled`, `recall_reason`, `recalled_at`, quantities, reservations, trace allocations, and GL totals. A 403 alone is not sufficient.

Red-first table:

| Exact file | Exact class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `tests/Feature/BatchExpiry/BatchActionPermissionsTest.php` | `BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission` | `$response->assertForbidden();` for a recall actor without `batches.recall` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_each_existing_batch_route_requires_its_exact_action_permission'` | PHPUnit PostgreSQL |
| same | `BatchActionPermissionsTest::test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware` | `self::assertSame($expectedMiddleware, $actualMiddleware);` with no `BatchActionAccess` on those routes | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchActionPermissionsTest::test_create_update_transfer_and_write_off_keep_existing_authorization_without_batch_action_middleware'` | PHPUnit PostgreSQL |
| `tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` | `BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock` | `self::assertNotContains($locationA2Id, array_column($response->json('data.batch_stock'), 'location_id'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'BatchReadLocationScopeTest::test_detail_excludes_other_branch_stock'` | PHPUnit PostgreSQL |
| `tests/Architecture/BatchTraceabilityModuleBoundaryTest.php` | `BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts` | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'BatchTraceabilityModuleBoundaryTest::test_batch_expiry_traceability_imports_only_shared_contracts'` | PHPUnit no-DB |

### 7.7 Convention-09 evidence

`BatchReadLocationScopeTest::test_second_company_selected_second_location_and_duplicate_create_are_isolated()` must:

1. Create company A through existing setup.
2. Register company B through `POST /api/v1/companies` using the real payload beginning at [CreateCompanyTest.php:76](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Company/CreateCompanyTest.php:76).
3. Create A1 and A2 through `POST /api/v1/locations`; make A2 `pos_enabled=true`.
4. Create B1 and B2 through the same endpoint; make B2 `pos_enabled=true`.
5. Create the same SKU and lot label independently in A and B.
6. Persist the restricted actor’s A membership with `allowed_location_ids=[A2]`.
7. Prove A2—not A1—is the selected location by placing stock and attributable trace history at A2 and asserting those exact IDs are visible.
8. Assert A1 and company-B stock/history identifiers are absent.
9. Repeat the company-A create request.
10. Assert 422, `meta.outcome=already_exists`, exactly one company-A lot, and unchanged quantity/reservation/history/GL snapshots.

<a id="r2-n2"></a>
**R2-N2:** Task 2’s second-company test creates B1 and POS-enabled B2, persists `allowed_location_ids=[B2]` on the company-B active membership, and asserts rejection is caused by that exact B2-restricted membership. B2 is the selected location, not decorative setup.

### 7.8 Implementation and reviewer gate

Order:

1. Add and capture the red-first assertions.
2. Add activation config and middleware.
3. Apply `BatchActionAccess` only to reads, traceability, delete, and recall.
4. Assert create, update, transfer, and write-off authorization remains unchanged.
5. Add the three complete trace DTOs and two interfaces.
6. Add and bind owning-module adapters.
7. Add response field-for-field compatibility tests.
8. Remove cross-module persistence imports.
9. Add repository scope parameters and filtered eager loads.
10. Thread scope through list/detail/stock/expiry/product/POS/trace reads.
11. Preserve unrestricted zero-stock and null-location history.
12. Replace float/unscoped totals.
13. Add the duplicate outcome behind the flag.
14. Run focused, architecture, denied-snapshot, and convention-09 tests.

<a id="r2-m8"></a>
**R2-M8:** Task 1 requires ACCEPT from both `tenancy-authz-reviewer` and `inventory-costing-reviewer`. The latter must explicitly approve four-decimal scoped totals and unchanged quantity, reservation, history, journal-entry, and journal-line snapshots on denial. Task 2, Task 6, and the combined diff require the same reviewer because they change role eligibility for batch reads/actions.

Rollback:

- Before activation, revert the Task-1 Push-3 commit and keep the flag false.
- After activation, disable the flag using the topology-specific procedure in §11.12, redeploy worker → API → scheduler, rebuild configuration cache, reset permission cache, and preserve additive data.
- Record that flag-off temporarily restores legacy API read/authorization behavior.

## 8. Task 2 — role delta and guarded general-manager assignment

### 8.1 Files

Add:

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`
- `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`
- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`
- `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`
- `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`
- `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php`
- `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`
- `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`

Modify:

- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`
- `docs/glossary.md`

Add tests:

- `apps/api/tests/Feature/Identity/LotActionSeededRoleMatrixTest.php`
- `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php`
- `apps/api/tests/Feature/Identity/LotActionPermissionDeltaTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerRoleProtectionTest.php`
- `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php`
- `apps/api/tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php`
- `apps/api/tests/Feature/Console/LotActionReseedMarkerTest.php`
- `apps/api/tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php`
- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

Registration evidence uses no new logging file or channel configuration. Task 2 adds the production call `\Illuminate\Support\Facades\Log::channel('stderr')->info($marker)` inside `RolesAndPermissionsSeeder::emitWlota1aReseedMarker()`, whose insertion point is immediately after the current `run()` at [RolesAndPermissionsSeeder.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:29). The pre-existing `stderr` channel is a Monolog `StreamHandler` targeting `php://stderr` at [logging.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/logging.php:97) through line 104, so the line reaches container logs independently of Artisan's console output buffer.

### 8.2 Migration

Anonymous migration, no namespace:

```php
return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void;

    public function down(): void;
};
```

Target: `roles`. Resolve the configured team column through `config('permission.column_names.team_foreign_key')`; at HEAD it is `tenant_id` at [permission.php:99](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:99).

| Column | SQL type | Nullable | Default | FK | Index |
|---|---|---:|---|---|---|
| `provisioning_source` | `VARCHAR(32)` | yes | SQL `NULL` | none | partial unique `roles_provisioning_source_team_unique` with configured team column |

PostgreSQL constraint:

```sql
CONSTRAINT roles_provisioning_source_check
CHECK (
    provisioning_source IS NULL
    OR (
        tenant_id IS NOT NULL
        AND provisioning_source = 'w-lot-a-1a'
        AND name = 'general_manager'
        AND guard_name = 'sanctum'
    )
)
```

PostgreSQL and SQLite partial unique index:

```sql
CREATE UNIQUE INDEX roles_provisioning_source_team_unique
ON roles (tenant_id, provisioning_source)
WHERE provisioning_source IS NOT NULL
```

SQLite triggers:

```text
roles_provisioning_source_insert_guard
roles_provisioning_source_update_guard
```

Each trigger rejects a non-null marker unless:

```text
NEW.tenant_id IS NOT NULL
NEW.provisioning_source = 'w-lot-a-1a'
NEW.name = 'general_manager'
NEW.guard_name = 'sanctum'
```

<a id="r3-m-b"></a>
**R3-M-B:** the marked-role invariant includes team identity. A row with `tenant_id=NULL`, `name=general_manager`, `guard_name=sanctum`, and `provisioning_source=w-lot-a-1a` is invalid in PostgreSQL and SQLite. The partial unique index permits exactly one non-null provisioning marker per tenant team while allowing different tenants to own their own marked role.

`up()` must:

1. Assert the roles table exists.
2. Resolve and validate the configured team column.
3. Add only an absent column.
4. Validate existing type, length, nullability, and default.
5. Compare the named PostgreSQL constraint after normalized whitespace.
6. Install and verify equivalent SQLite insert/update triggers.
7. Install and verify the team-scoped partial unique index.
8. Reject a pre-existing global marked role.
9. Be a no-op only for the exact schema, constraint, triggers, and index.

`down()` must refuse while any row is marked; drop only the named index, constraint/triggers, and column; and never delete a role, permission, or pivot.

### 8.3 Enums and result DTO

NEW `App\Modules\Identity\Domain\Enums\SystemRoleName`:

```php
enum SystemRoleName: string
{
    case GeneralManager = 'general_manager';
}
```

NEW `App\Modules\Identity\Domain\Enums\RoleProvisioningSource`:

```php
enum RoleProvisioningSource: string
{
    case Wlota1a = 'w-lot-a-1a';
}
```

NEW `App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome`:

```php
enum LotActionPermissionDeltaOutcome: string
{
    case Applied = 'APPLIED';
    case AlreadyApplied = 'ALREADY_APPLIED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';
}
```

NEW `App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult`:

```php
final readonly class LotActionPermissionDeltaResult
{
    public function __construct(
        public readonly \App\Modules\Identity\Domain\Enums\LotActionPermissionDeltaOutcome $outcome,
        public readonly string $reason,
    ) {}
}
```

<a id="r5-m-c"></a>
Add NEW TypeScript-exported `App\Modules\Identity\Application\DTOs\RoleData`. The configured raw DTO transformer preserves PHP property spelling at [typescript-transformer.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/typescript-transformer.php:34): for example, `emailVerifiedAt` at [UserData.php:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Application/DTOs/UserData.php:25) becomes `emailVerifiedAt` at [generated.d.ts:1017](/Users/houssamr/Projects/syneriva/apps/erp/packages/shared/types/generated.d.ts:1017). Therefore RoleData uses snake-case PHP properties, following the existing `total_debit`/`total_credit`/`is_balanced` precedent at [TrialBalanceData.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Application/DTOs/Reports/TrialBalanceData.php:66) through line 68 and its generated output at [generated.d.ts:259](/Users/houssamr/Projects/syneriva/apps/erp/packages/shared/types/generated.d.ts:259) through line 264:

```php
#[\Spatie\TypeScriptTransformer\Attributes\TypeScript]
final readonly class RoleData
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $guard_name,
        public readonly array $permissions,
        public readonly int $users_count,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly bool $is_provisioned_read_only,
    ) {}
}
```

The DTO property names mirror the existing roles wire keys at [RoleController.php:139](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:139) through line 147. Its array/JSON serialization and generated declaration must therefore expose exactly `id`, `name`, `guard_name`, `permissions`, `users_count`, `created_at`, `updated_at`, and `is_provisioned_read_only`, including:

```json
{
  "is_provisioned_read_only": true
}
```

`RoleController::index()`, `show()`, `store()`, and successful `update()` serialize through `RoleData` or its array form rather than repeating role arrays. Current index serialization begins at [RoleController.php:139](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:139).

The generated TypeScript member is:

```ts
is_provisioned_read_only: boolean
```

### 8.4 Delta service and permission-team boundary

**§8.4 addendum (rev 9, binding — supersedes any tenant-scoped-only resolution below):**

1. Resolution of the EXISTING catalogue (`admin`, `manager`, and every legacy role the delta edits) selects `name = ? AND guard_name = 'sanctum' AND (tenant_id IS NULL OR tenant_id = :tenantId)` and PRESERVES the row's `tenant_id` as found (NULL stays NULL). Role IDs, custom grants and `model_has_roles` rows are untouched. Two matching rows for one name/guard (one NULL, one tenant-scoped) is a collision → the existing collision failure branch (explicit non-zero result, transaction rolled back, no partial write).
2. Only the NEW marked `general_manager` row is created with `tenant_id = :tenantId` and `provisioning_source = 'w-lot-a-1a'`; the non-null-team marker constraint, partial unique index, triggers and `markedTenantCarriesWlota1aDelta($tenantId)` (§8.1/§8.6) are unchanged. An unmarked pre-existing `general_manager` (NULL-team or tenant-scoped) is a collision, never a candidate for adoption.
3. `RolesAndPermissionsSeeder` flag-off branches keep the legacy `firstOrCreate` shape for fresh/unmarked tenants (§8.6); the marked-tenant preservation branch recognises legacy roles under the same `(tenant_id IS NULL OR tenant_id = :tenantId)` predicate, so both mandatory PG preservation tests exercise the REAL legacy catalogue.
4. Tests: `LotActionPermissionDeltaTest` and `LotActionSeededRoleMatrixTest` run the delta against the actual legacy seeder output (NULL-team rows) as the primary fixture, keep one tenant-scoped-legacy case for the OR arm, and add the mixed NULL+scoped collision case. `RoleProvisioningSourceSchemaTest`'s null-team rejection applies to MARKED rows only; assert an unmarked NULL-team legacy role still inserts.
5. `RoleController` `CrossTenantRoute` annotations that overstate an automatic team global scope: out of this lane; ticket `docs/superpowers/tickets/2026-09-09-role-controller-team-scope-annotations.md` (written by the implementer, read by tenancy-authz-reviewer).


NEW `App\Modules\Identity\Application\Services\LotActionPermissionDelta`:

```php
final readonly class LotActionPermissionDelta
{
    public function __construct(
        private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    ) {}

    public function apply(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    public function verify(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    private function execute(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
        bool $write,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult;

    private function acquireTenantLock(string $tenantId): void;

    private function provisionGeneralManager(
        array $permissions,
        bool $write,
    ): \Spatie\Permission\Models\Role;

    private function synchronizeSeededRole(
        \Spatie\Permission\Models\Role $role,
        array $permissions,
        bool $newRole,
        bool $write,
    ): void;

    private function verifyCanonicalState(
        string $tenantId,
        array $permissionNames,
        array $rolePermissionGrants,
    ): void;
}
```

Binding: Laravel constructor auto-resolution.

<a id="r2-m2"></a>
**R2-M2:** `execute()` must use this boundary:

```php
$previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
$this->permissionRegistrar->setPermissionsTeamId($tenantId);

try {
    return \Illuminate\Support\Facades\DB::transaction(function () use (
        $tenantId,
        $permissionNames,
        $rolePermissionGrants,
        $write,
    ): \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult {
        $this->acquireTenantLock($tenantId);
        // Lock tenant-team roles in deterministic name/id order.
        // Read or create permissions and roles.
        // Apply only allowed additions and the explicit manager recall removal.
        // Verify the complete canonical postcondition before commit/return.
    });
} finally {
    $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
}
```

The PostgreSQL advisory lock is transaction-scoped and therefore remains held across read/write/verification. The SQLite lane relies on the enclosing transaction. Tests must cover concurrent first apply, cross-tenant discrimination, stale prior team context restoration, and exception-path restoration.

Rules:

- An unmarked existing `general_manager` is a collision and is never adopted.
- A global marked role is invalid and is never adopted.
- No force option exists.
- A marked role retains its bigint ID.
- Exactly one marked role exists per tenant team.
- Seeded roles gain missing canonical grants.
- Unrelated custom grants remain.
- The only explicit revocation is manager losing `batches.recall`.
- `verify()` writes nothing.
- `SKIPPED` and `FAILED` are never promotion-success outcomes.

### 8.5 Canonical and legacy matrices

Add exactly these catalogue permissions:

```text
batches.recall.request
treasury.manage_all_locations
```

Canonical delta:

| Seeded role | Result |
|---|---|
| `admin` | All permission names |
| `general_manager` | Revised manager set plus `batches.recall` and `treasury.manage_all_locations` |
| `manager` | `batches.view`, create, update, delete, write-off, traceability, request; no `batches.recall` |
| `cashier` | `batches.view` |
| `viewer` | `batches.view` |
| `operator` | `batches.view` |
| `technician` | No new batch grant |
| `accountant` | No new batch grant |

The flag-off legacy helpers must reproduce the HEAD catalogue and role arrays exactly, including manager global recall, no `general_manager`, and no new viewer/operator batch grant. They may be mechanically extracted from the current arrays but cannot call the canonical helpers.

### 8.6 Seeder contract

Modify `Database\Seeders\RolesAndPermissionsSeeder`:

```php
public function __construct(
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
    private readonly \App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation $activation,
    private readonly \App\Modules\Identity\Application\Services\LotActionPermissionDelta $lotActionPermissionDelta,
) {}

public function run(): void;

private function createPermissionsFrom(array $permissionNames): void;

private function createLegacyRoles(): void;

private function markedTenantCarriesWlota1aDelta(string $tenantId): bool;

/** @return list<string> */
private static function legacyPermissionNames(): array;

/** @return array<string, list<string>> */
private static function legacyRolePermissionGrants(): array;

/** @return list<string> */
public static function permissionNames(): array;

/** @return array<string, list<string>> */
public static function rolePermissionGrants(): array;

private function currentTenantId(): string;

private function emitWlota1aReseedMarker(
    string $tenantId,
    string $mode,
    \App\Modules\Identity\Application\DTOs\LotActionPermissionDeltaResult $result,
): void;
```

`emitWlota1aReseedMarker()` constructs the marker once, writes that same string to the command output for direct CLI evidence, and writes it to the durable container-stderr evidence channel with this exact production symbol:

```php
\Illuminate\Support\Facades\Log::channel('stderr')->info($marker);
```

The call belongs in the new helper inserted after the current `run()` boundary at [RolesAndPermissionsSeeder.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:29). It deliberately uses the existing `stderr` channel whose stream is `php://stderr` at [logging.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/logging.php:97) through line 104. This channel survives the `Artisan::call()` path at [TenantInitializationService.php:213](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213), where Laravel otherwise substitutes a `BufferedOutput` at [Application.php:165](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Console/Application.php:165) through line 167.

Behavior:

- Flag off first resolves `currentTenantId()`, then calls `markedTenantCarriesWlota1aDelta($tenantId)` before any canonical-role synchronization. That marker check must require one team-scoped tuple with the configured team column equal to `$tenantId`, `name=SystemRoleName::GeneralManager->value`, `guard_name=sanctum`, and `provisioning_source=RoleProvisioningSource::Wlota1a->value`. Its insertion point is immediately before the current destructive `$role->syncPermissions(...)` call at [RolesAndPermissionsSeeder.php:568](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:568); the current role loop begins at [RolesAndPermissionsSeeder.php:564](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php:564). The configured team column is `tenant_id` at [permission.php:99](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/permission.php:99).
- Flag off, fresh/unmarked tenant: call `createPermissionsFrom(self::legacyPermissionNames())`, then `createLegacyRoles()`, which alone may run the exact legacy `syncPermissions()` matrix, and emit one marker:

```text
WLOTA1A-RESEED tenant=<uuid> mode=LEGACY outcome=ALREADY_APPLIED reason=enforcement_off
```

- Flag off, marked tenant: do **not** call `createLegacyRoles()`, do not restore the legacy matrix, do not re-grant manager `batches.recall`, and do not synchronize any canonical role. Preserve the marked role ID, the complete applied canonical delta, every unrelated custom permission and grant, and every pivot cardinality exactly as found; emit:

```text
WLOTA1A-RESEED tenant=<uuid> mode=LEGACY outcome=ALREADY_APPLIED reason=marked_tenant_delta_preserved
```

- Flag on: invoke the canonical delta and emit:

```text
WLOTA1A-RESEED tenant=<uuid> mode=ACTIVATED outcome=APPLIED|ALREADY_APPLIED reason=<token>
```

- A failure throws and emits no success marker.
- The exporter continues to consume public canonical `permissionNames()` and `rolePermissionGrants()`.
- Push 4 does not invoke the generic seeder while the flag is false.
- Staging reseed checks use only named disposable tenants; a fleet-wide generic reseed is forbidden for this rollout.

### 8.7 Delta command

NEW `App\Console\Commands\ApplyLotActionPermissionDelta`:

```php
final class ApplyLotActionPermissionDelta extends \Illuminate\Console\Command
{
    protected $signature = 'permissions:apply-lot-action-delta
        {--apply : Apply the permission and role delta}
        {--verify : Verify the permission and role delta without writing}';

    protected $description =
        'Apply or verify W-LOT-A-1a lot-action permissions and the seeded general-manager role.';

    public function __construct(
        private readonly \App\Modules\Identity\Application\Services\LotActionPermissionDelta $delta,
    ) {
        parent::__construct();
    }

    public function handle(): int;

    private function optionEnabled(mixed $value): bool;
}
```

Register the command by modifying:

```php
public function \App\Modules\BatchExpiry\BatchExpiryServiceProvider::boot(): void;
```

Add `\App\Console\Commands\ApplyLotActionPermissionDelta::class` to the existing command list.

<a id="r2-m7"></a>
**R2-M7:** exactly one mode is mandatory:

| Invocation | Result |
|---|---|
| `--apply` only | Apply; emit one tenant marker; exit 0 only for `APPLIED` or `ALREADY_APPLIED` |
| `--verify` only | Read-only verify; emit one marker; exit 0 only for `ALREADY_APPLIED` |
| neither | emit `outcome=FAILED reason=invalid_mode`; exit 2 |
| both | emit `outcome=FAILED reason=invalid_mode`; exit 2 |
| collision/schema/invariant failure | emit `outcome=FAILED reason=<stable-token>`; exit 1 |
| any `SKIPPED` result | emit it and exit 1 |

Marker:

```text
WLOTA1A-PERMISSIONS tenant=<uuid> mode=APPLY|VERIFY outcome=APPLIED|ALREADY_APPLIED|SKIPPED|FAILED reason=<token>
```

<a id="r4-m-d"></a>
**R4-M-D:** `optionEnabled()` normalizes direct Artisan VALUE_NONE booleans and `tenants:run` strings:

```php
private function optionEnabled(mixed $value): bool
{
    return $value === true
        || $value === 1
        || $value === '1'
        || $value === 'true';
}
```

`handle()` computes:

```php
$apply = $this->optionEnabled($this->option('apply'));
$verify = $this->optionEnabled($this->option('verify'));
```

It then requires `$apply xor $verify`. It must not use strict `$this->option(...) === true`. `tenants:run` splits and forwards option values as strings at [Run.php:40](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:40) and [Run.php:50](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:50); the existing day-one command documents and handles the same boundary at [DayOneCensusCommand.php:83](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/DayOneCensusCommand.php:83).

Stable success reasons used by the wrapper tests are:

```text
canonical_delta_applied
canonical_state_matches
```

No option containing `force` is added.

### 8.8 Assignment guard, transactions, and correct constructors

NEW `App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard` declares no constructor:

```php
final readonly class GeneralManagerAssignmentGuard
{
    public function assertAssignable(
        \App\Modules\Identity\Domain\User $actor,
        string $targetUserId,
        string $companyId,
        array $effectiveRoleNames,
        ?array $effectiveAllowedLocationIds,
    ): void;

    public function assertLocationChangeAllowed(
        \App\Modules\Identity\Domain\User $actor,
        string $targetUserId,
        string $companyId,
        array $effectiveRoleNames,
        ?array $effectiveAllowedLocationIds,
    ): void;

    private function assertEveryActiveMembershipIsUnrestricted(
        string $targetUserId,
        string $companyId,
        ?array $effectiveAllowedLocationIds,
    ): void;
}
```

It throws validation errors with HTTP 422 semantics and code `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`. An explicit `null` is unrestricted; `[]` is restricted. Omitted fields use persisted values. Atomic demotion plus narrowing is allowed.

Modify `App\Modules\Identity\Presentation\Controllers\UserController`:

```php
public function __construct(
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Modules\Company\Services\LocationContext $locationContext,
    private readonly \App\Modules\Tenant\Application\Services\IdentityIndexService $identityIndexService,
    private readonly \App\Modules\Tenant\Application\Services\TenantLinkSigner $tenantLinkSigner,
    private readonly \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
) {}

public function store(
    \App\Modules\Identity\Presentation\Requests\CreateUserRequest $request,
): \Illuminate\Http\JsonResponse;

public function update(
    \App\Modules\Identity\Presentation\Requests\UpdateUserRequest $request,
    string $id,
): \Illuminate\Http\JsonResponse;
```

Modify `App\Modules\Identity\Presentation\Controllers\RoleController`:

```php
public function __construct(
    private readonly \App\Modules\Company\Services\CompanyContext $companyContext,
    private readonly \App\Services\CompanyConfigService $configService,
    private readonly \App\Modules\Identity\Application\Services\GeneralManagerAssignmentGuard $generalManagerAssignmentGuard,
    private readonly \Spatie\Permission\PermissionRegistrar $permissionRegistrar,
) {}

public function index(
    \Illuminate\Http\Request $request,
): \Illuminate\Http\JsonResponse;

public function show(
    \Illuminate\Http\Request $request,
    int $id,
): \Illuminate\Http\JsonResponse;

public function update(
    \App\Modules\Identity\Presentation\Requests\UpdateRoleRequest $request,
    int $id,
): \Illuminate\Http\JsonResponse;

public function destroy(
    \App\Modules\Identity\Presentation\Requests\DeleteRoleRequest $request,
    int $id,
): \Illuminate\Http\JsonResponse;

public function assignRole(
    \App\Modules\Identity\Presentation\Requests\AssignRoleRequest $request,
    string $userId,
): \Illuminate\Http\JsonResponse;

private function isMarkedProvisionedRole(
    \Spatie\Permission\Models\Role $role,
): bool;

private function isProtectedSystemRole(
    \Spatie\Permission\Models\Role $role,
): bool;

private function roleData(
    \Spatie\Permission\Models\Role $role,
): \App\Modules\Identity\Application\DTOs\RoleData;

private function provisionedRoleReadOnlyResponse(): \Illuminate\Http\JsonResponse;
```

<a id="r2-m3"></a>
**R2-M3:** each create, update, and dedicated assignment path captures the previous Spatie team ID, sets the authenticated tenant ID, executes one `DB::transaction`, and restores the prior team ID in `finally`. The transaction covers:

1. Target-user `FOR UPDATE`.
2. Active memberships ordered by ascending `company_id`, then locked.
3. Team-scoped role row lookup/lock.
4. Merge of omitted request values with persisted roles/location scope.
5. Final-state guard validation.
6. Membership write.
7. Role assignment/synchronization.
8. Existing audit row and `RoleAssigned` event emission.
9. Postcondition verification.

User creation creates the user and active membership, writes location scope, then validates and assigns. Invitation delivery stays after commit. Dedicated assignment requires an active membership in the current company and retains `AssignableRole`.

`isMarkedProvisionedRole()` is true only when all are true:

- `name=general_manager`
- `guard_name=sanctum`
- configured team column is non-null
- `provisioning_source=w-lot-a-1a`

Before `$request->validated()`, `update()` returns `provisionedRoleReadOnlyResponse()` when the role is marked and the request has either `name` or `permissions`. Before user-count evaluation, `destroy()` returns the same response for a marked role.

Exact error:

```json
{
  "error": {
    "code": "PROVISIONED_ROLE_READ_ONLY",
    "message": "Provisioned roles are read-only."
  }
}
```

Status: HTTP 422.

`roleData()` constructs every snake-case `RoleData` property, including marker-derived `is_provisioned_read_only`. `index()` maps its role collection through `roleData()` and serializes each DTO array; `show()` serializes the same DTO array. `store()` and successful `update()` reuse the same path, so the wire shape and generated declaration cannot diverge. `RoleData::is_provisioned_read_only` is computed with `isMarkedProvisionedRole()` and is not name-derived. Existing legacy protected-name behavior remains.

`isProtectedSystemRole()` is true for:

- Existing `super-admin`, `admin`, and `owner`.
- The marker-derived protected role described above.

Protected legacy roles cannot be renamed or deleted. The marked role additionally cannot have its permissions synchronized. All protections apply even with zero users.

### 8.9 Tests

NEW `Tests\Feature\Identity\LotActionSeededRoleMatrixTest`:

```php
final class LotActionSeededRoleMatrixTest extends \Tests\TestCase
{
    public function test_viewer_and_operator_gain_batch_view_in_canonical_matrix(): void;

    public function test_manager_loses_global_recall_and_gains_request_in_canonical_matrix(): void;

    public function test_general_manager_has_the_ruled_permission_delta(): void;

    public function test_flag_off_generic_seeder_retains_exact_legacy_role_behavior(): void;

    public function test_flag_on_fresh_provisioning_uses_canonical_matrix(): void;
}
```

NEW `Tests\Feature\Identity\RolesAndPermissionsSeederMarkedTenantTest`:

```php
final class RolesAndPermissionsSeederMarkedTenantTest extends \Tests\TestCase
{
    public function test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant(): void;

    public function test_flag_off_reseed_after_rollback_preserves_post_activation_state(): void;
}
```

Both methods use PostgreSQL and compare an ordered snapshot containing permission rows, role rows, `role_has_permissions` pivots, the marked role ID, and an explicit custom grant. The first applies the delta, switches enforcement off, runs the real seeder, and proves manager still lacks `batches.recall`; the second provisions under activation, records the post-activation snapshot, switches enforcement off as rollback does, runs the real seeder, and proves the snapshot is unchanged.

NEW `Tests\Feature\Identity\LotActionPermissionDeltaTest`:

```php
final class LotActionPermissionDeltaTest extends \Tests\TestCase
{
    public function test_first_apply_and_second_apply_have_explicit_outcomes(): void;

    public function test_rerun_preserves_role_id_and_custom_permissions(): void;

    public function test_unmarked_general_manager_collision_fails_closed(): void;

    public function test_concurrent_first_apply_creates_one_marked_role(): void;

    public function test_verify_is_read_only(): void;

    public function test_transaction_restores_previous_permission_team_on_success_and_exception(): void;

    public function test_two_tenants_with_same_role_names_are_discriminated_by_team(): void;

    public function test_each_tenant_team_has_exactly_one_marked_general_manager(): void;
}
```

NEW `Tests\Feature\Identity\GeneralManagerAssignmentTest`:

```php
final class GeneralManagerAssignmentTest extends \Tests\TestCase
{
    public function test_create_update_and_dedicated_assignment_reject_restricted_membership(): void;

    public function test_unrestricted_assignment_succeeds_after_membership_creation(): void;

    public function test_general_manager_cannot_later_be_location_narrowed(): void;

    public function test_atomic_demotion_and_location_narrowing_succeeds(): void;

    public function test_second_company_selected_b2_membership_must_also_be_unrestricted(): void;

    public function test_dedicated_assignment_restores_previous_permission_team(): void;

    public function test_concurrent_assignment_and_membership_narrowing_are_serialized(): void;
}
```

The concurrency test uses a barrier-controlled PostgreSQL race and proves bounded completion and one valid final state.

NEW `Tests\Feature\Identity\GeneralManagerRoleProtectionTest`:

```php
final class GeneralManagerRoleProtectionTest extends \Tests\TestCase
{
    public function test_marked_general_manager_cannot_be_renamed(): void;

    public function test_marked_general_manager_permissions_cannot_be_synchronized_through_role_update(): void;

    public function test_marked_general_manager_name_cannot_be_changed_through_role_update(): void;

    public function test_marked_general_manager_cannot_be_deleted(): void;

    public function test_delta_rerun_after_protection_preserves_role_identity(): void;
}
```

NEW `Tests\Feature\Identity\RoleIndexResponseContractTest`:

```php
final class RoleIndexResponseContractTest extends \Tests\TestCase
{
    public function test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived(): void;
}
```

That method makes one team-scoped request containing the marked provisioned role and one request in a separate tenant containing an unmarked role named `general_manager`. It asserts the marked value is `true`, the name-only value is `false`, the ordered key set is exactly `id`, `name`, `guard_name`, `permissions`, `users_count`, `created_at`, `updated_at`, `is_provisioned_read_only`, and no camel-case compatibility key is emitted.

NEW `Tests\Feature\Identity\GeneralManagerAssignmentWriterCensusTest`:

```php
final class GeneralManagerAssignmentWriterCensusTest extends \Tests\TestCase
{
    public function test_runtime_general_manager_assignment_sites_use_the_guard_and_team_boundary(): void;
}
```

NEW `Tests\Feature\Console\LotActionReseedMarkerTest`:

```php
final class LotActionReseedMarkerTest extends \Tests\TestCase
{
    public function test_flag_off_seeder_emits_one_legacy_marker_without_canonical_role_changes(): void;

    public function test_flag_on_fresh_seed_emits_one_activated_applied_marker(): void;

    public function test_activated_reseed_emits_one_already_applied_marker(): void;

    public function test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering(): void;
}
```

NEW `Tests\Feature\Console\ApplyLotActionPermissionDeltaCommandTest`:

```php
final class ApplyLotActionPermissionDeltaCommandTest extends \Tests\TestCase
{
    public function test_apply_emits_exact_applied_marker_and_exits_zero(): void;

    public function test_verify_emits_exact_already_applied_marker_and_is_read_only(): void;

    public function test_tenants_run_apply_string_one_selects_apply_mode(): void;

    public function test_tenants_run_verify_string_one_selects_verify_mode(): void;

    public function test_neither_mode_emits_invalid_mode_and_exits_two(): void;

    public function test_both_modes_emit_invalid_mode_and_exit_two(): void;

    public function test_unmarked_collision_emits_failed_marker_and_exits_one(): void;

    public function test_missing_schema_emits_failed_marker_and_exits_one(): void;
}
```

NEW `Tests\Feature\Migrations\RoleProvisioningSourceSchemaTest`:

```php
final class RoleProvisioningSourceSchemaTest extends \Tests\TestCase
{
    public function test_postgresql_schema_matches_exact_contract(): void;

    public function test_postgresql_rejects_marked_role_without_team(): void;

    public function test_postgresql_partial_unique_index_enforces_one_marker_per_team(): void;

    public function test_sqlite_triggers_reject_invalid_and_null_team_markers(): void;

    public function test_sqlite_partial_unique_index_enforces_one_marker_per_team(): void;

    public function test_down_refuses_while_a_marked_role_exists(): void;
}
```

Red-first assertions:

| Exact file | Exact class::method | First failing assertion | Command | Lane |
|---|---|---|---|---|
| `tests/Feature/Identity/LotActionSeededRoleMatrixTest.php` | `LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix` | `self::assertContains('batches.view', $grants['viewer']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_viewer_and_operator_gain_batch_view_in_canonical_matrix'` | PostgreSQL |
| same | `LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix` | `self::assertNotContains('batches.recall', $grants['manager']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionSeededRoleMatrixTest::test_manager_loses_global_recall_and_gains_request_in_canonical_matrix'` | PostgreSQL |
| `tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php` | `RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant` | `self::assertSame($postDeltaSnapshot, $this->orderedPermissionSnapshot());` after adding a custom grant and running the real flag-off seeder | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant'` | PostgreSQL |
| same | `RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_rollback_preserves_post_activation_state` | `self::assertSame($postActivationSnapshot, $this->orderedPermissionSnapshot());` after switching enforcement from on to off and running the real rollback-time seeder | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_rollback_preserves_post_activation_state'` | PostgreSQL |
| `tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php` | `RoleProvisioningSourceSchemaTest::test_postgresql_rejects_marked_role_without_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before inserting a null-team marker | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleProvisioningSourceSchemaTest::test_postgresql_rejects_marked_role_without_team'` | PostgreSQL |
| same | `RoleProvisioningSourceSchemaTest::test_postgresql_partial_unique_index_enforces_one_marker_per_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before inserting the second marker for one tenant | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleProvisioningSourceSchemaTest::test_postgresql_partial_unique_index_enforces_one_marker_per_team'` | PostgreSQL |
| same | `RoleProvisioningSourceSchemaTest::test_sqlite_triggers_reject_invalid_and_null_team_markers` | `$this->expectException(\Illuminate\Database\QueryException::class);` before the null-team insert | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'RoleProvisioningSourceSchemaTest::test_sqlite_triggers_reject_invalid_and_null_team_markers'` | SQLite |
| same | `RoleProvisioningSourceSchemaTest::test_sqlite_partial_unique_index_enforces_one_marker_per_team` | `$this->expectException(\Illuminate\Database\QueryException::class);` before the second same-team marker | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'RoleProvisioningSourceSchemaTest::test_sqlite_partial_unique_index_enforces_one_marker_per_team'` | SQLite |
| `tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two` | `$this->artisan('permissions:apply-lot-action-delta')->assertExitCode(2);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ApplyLotActionPermissionDeltaCommandTest::test_neither_mode_emits_invalid_mode_and_exits_two'` | PostgreSQL |
| `tests/Feature/Identity/GeneralManagerRoleProtectionTest.php` | `GeneralManagerRoleProtectionTest::test_marked_general_manager_permissions_cannot_be_synchronized_through_role_update` | `$response->assertStatus(422)->assertJsonPath('error.code', 'PROVISIONED_ROLE_READ_ONLY'); self::assertSame($beforePermissions, $role->refresh()->permissions()->orderBy('name')->pluck('name')->all());` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerRoleProtectionTest::test_marked_general_manager_permissions_cannot_be_synchronized_through_role_update'` | PostgreSQL |
| same | `GeneralManagerRoleProtectionTest::test_marked_general_manager_name_cannot_be_changed_through_role_update` | `$response->assertStatus(422)->assertJsonPath('error.code', 'PROVISIONED_ROLE_READ_ONLY'); self::assertSame('general_manager', $role->refresh()->name);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerRoleProtectionTest::test_marked_general_manager_name_cannot_be_changed_through_role_update'` | PostgreSQL |
| same | `GeneralManagerRoleProtectionTest::test_marked_general_manager_cannot_be_deleted` | `$response->assertStatus(422)->assertJsonPath('error.code', 'PROVISIONED_ROLE_READ_ONLY'); $this->assertDatabaseHas('roles', ['id' => $role->id]);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'GeneralManagerRoleProtectionTest::test_marked_general_manager_cannot_be_deleted'` | PostgreSQL |
| `tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php` | `ApplyLotActionPermissionDeltaCommandTest::test_tenants_run_apply_string_one_selects_apply_mode` | `$this->artisan('tenants:run', ['commandname' => 'permissions:apply-lot-action-delta', '--tenants' => [$tenantId], '--option' => ['apply=1']])->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$tenantId} mode=APPLY outcome=APPLIED reason=canonical_delta_applied");` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ApplyLotActionPermissionDeltaCommandTest::test_tenants_run_apply_string_one_selects_apply_mode'` | PostgreSQL |
| same | `ApplyLotActionPermissionDeltaCommandTest::test_tenants_run_verify_string_one_selects_verify_mode` | `$this->artisan('tenants:run', ['commandname' => 'permissions:apply-lot-action-delta', '--tenants' => [$tenantId], '--option' => ['verify=1']])->expectsOutputToContain("WLOTA1A-PERMISSIONS tenant={$tenantId} mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches");` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ApplyLotActionPermissionDeltaCommandTest::test_tenants_run_verify_string_one_selects_verify_mode'` | PostgreSQL |
| `tests/Feature/Identity/RoleIndexResponseContractTest.php` | `RoleIndexResponseContractTest::test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived` | `$response->assertJsonStructure(['data' => [['id', 'name', 'guard_name', 'permissions', 'users_count', 'created_at', 'updated_at', 'is_provisioned_read_only']]])->assertJsonPath('data.0.is_provisioned_read_only', true); self::assertSame(['id', 'name', 'guard_name', 'permissions', 'users_count', 'created_at', 'updated_at', 'is_provisioned_read_only'], array_keys($response->json('data.0')));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'RoleIndexResponseContractTest::test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived'` | PostgreSQL |
| `tests/Feature/Console/LotActionReseedMarkerTest.php` | `LotActionReseedMarkerTest::test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering` | `$stderrLogger->shouldHaveReceived('info')->once()->with("WLOTA1A-RESEED tenant={$tenantId} mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied");` after the real registration request returns the tenant UUID | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'LotActionReseedMarkerTest::test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering'` | PostgreSQL |

The permission-update test snapshots ordered permission names, role ID, name, and `role_has_permissions` cardinality before the PATCH; all four remain equal after the rejected call.

Task-2 convention-09 evidence persists `allowed_location_ids=[B2]`, attempts `general_manager`, and asserts 422 plus the stable error code. Delta rerun proves `APPLIED`, then `ALREADY_APPLIED`, one marked role, the same ID, no duplicate pivots, and preserved custom grants.

Task 2 requires ACCEPT from `tenancy-authz-reviewer` and `inventory-costing-reviewer`. Review must cover team identity, null-team rejection, partial-index cardinality, transaction extent, lock order, collision behavior, role identity, custom grants, manager recall removal, assignment paths, B2 selection, narrowing race, name/permission/delete protection, marker-derived DTO output, grant immutability after rejection, VALUE_NONE normalization, wrapper-mode tests, marker cardinality, and absence of hold behavior.

Rollback is forward-only: preserve the marker column, index, constraint, marked role ID, and API read-only protection; correct grants only through a new delta; never delete, rename, unmark, or edit pivots ad hoc.

## 9. Task 6 — web route/action gating and generated map

### 9.1 Files

Modify:

- `apps/web/src/routes/index.tsx`
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/features/batches/pages/BatchListPage.tsx`
- `apps/web/src/features/batches/pages/BatchDetailPage.tsx`
- `apps/web/src/features/settings/RolesPage.tsx`
- `apps/web/src/hooks/usePermissions.ts`
- `apps/web/src/hooks/permissionsMap.generated.ts`
- `packages/shared/types/generated.d.ts`
- `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`
- `apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx`

Add:

- `apps/web/src/routes/__tests__/BatchRoutePermissions.test.tsx`
- `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`
- `apps/web/src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`
- `apps/web/src/features/settings/RolesPage.test.tsx`
- `apps/web/e2e/batch-permissions.spec.ts`

No additional hook, handwritten role or trace API type, locale file, recall history, hold component, or request-recall action is permitted.

### 9.2 Production symbols

Modify existing `AppRoutes()` and add:

```ts
export const WLOTA1A_WEB_FINGERPRINT =
  'wlota1a-batch-permission-gating-v1' as const
```

Attach:

```tsx
handle={{ featureFingerprint: WLOTA1A_WEB_FINGERPRINT }}
```

Route gates:

| Route | Module | Permission |
|---|---|---|
| `/inventory/batches` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/:uuid` | `BatchExpiry` | `batches.view` |
| `/inventory/batches/new` | `BatchExpiry` | `batches.create` |
| `/inventory/batches/:uuid/edit` | `BatchExpiry` | `batches.update` |
| `/inventory/expiry-write-off` | `BatchExpiry` | retain `batches.write-off` |

Modify existing:

```ts
function buildNavigation(isAutomotiveVertical: boolean): NavModule[]
```

Batch child:

```ts
{
  key: 'batches',
  href: '/inventory/batches',
  icon: Pill,
  module: 'BatchExpiry',
  permission: 'batches.view',
}
```

Modify:

```ts
export function BatchListPage()
```

<a id="r2-m5"></a>
**R2-M5:** compute one value:

```ts
const canCreate = hasPermission('batches.create')
```

Use it to conditionally render both the header link and the empty-state link. A denied link is absent, not disabled/focusable.

Modify:

```ts
export function BatchDetailPage()
```

Final booleans:

```ts
const canEdit =
  hasPermission('batches.update') &&
  batch.is_active &&
  !batch.is_recalled

const canRecall =
  hasPermission('batches.recall') &&
  batch.is_active &&
  !batch.is_recalled &&
  !batch.is_expired

const canDelete =
  hasPermission('batches.delete') &&
  batch.is_active
```

Do not change `handleRecall()`.

Modify:

```ts
const SERVER_AUTHORITATIVE_PERMISSIONS: Set<Permission>
```

Add every batch permission:

```text
batches.view
batches.create
batches.update
batches.delete
batches.recall
batches.recall.request
batches.write-off
batches.traceability
```

Modify `MODULE_PERMISSIONS` with:

```ts
'batches.view': ['batches.view']
```

A present server permission payload is authoritative; role fallback must not recreate an absent batch permission.

Generated map expectations:

| Permission | Roles |
|---|---|
| `batches.view` | `admin`, `cashier`, `general_manager`, `manager`, `operator`, `viewer` |
| `batches.create` | `admin`, `general_manager`, `manager` |
| `batches.update` | `admin`, `general_manager`, `manager` |
| `batches.delete` | `admin`, `general_manager`, `manager` |
| `batches.recall.request` | `admin`, `general_manager`, `manager` |
| `batches.recall` | `admin`, `general_manager` |
| `batches.write-off` | `admin`, `general_manager`, `manager` |
| `batches.traceability` | `admin`, `general_manager`, `manager` |
| `treasury.manage_all_locations` | `admin`, `general_manager` |

Regenerate only through `php artisan permissions:export-frontend-map`; the current exporter reads both seeder helpers at [ExportFrontendPermissionsMap.php:29](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29).

Modify `RolesPage()` to consume:

```ts
type Role = App.Modules.Identity.Application.DTOs.RoleData
```

This alias points only to `packages/shared/types/generated.d.ts`; do not declare or merge a local `Role` interface. The generated property comes directly from the snake-case `public readonly` PHP property and is:

```ts
is_provisioned_read_only: boolean
```

Use:

```ts
const isReadOnlyRole = (role: Role) => role.is_provisioned_read_only
```

Requirements:

- `openEditModal(role)` returns immediately when `isReadOnlyRole(role)`.
- The protected role card has no edit click behavior or pointer affordance.
- Edit and delete buttons are absent for the protected role.
- The read-only badge is driven by `is_provisioned_read_only`, never by `role.name === 'general_manager'`.
- `handleSubmit()` returns before invoking `updateMutation.mutate()` if `editingRole?.is_provisioned_read_only`.
- Delete confirmation cannot be opened for the protected role.
- Existing legacy system-role presentation remains unchanged.
- No hand-authored local `Role` interface remains.

### 9.3 Exact Vitest and Playwright symbols

<a id="r2-m6"></a>
**R2-M6:** these titles are mandatory and exact.

`src/routes/__tests__/BatchRoutePermissions.test.tsx`:

```ts
describe('W-LOT-A-1a batch route permissions', () => {
  it('denies list and detail with inventory.view but without batches.view', ...)
  it('allows list and detail with batches.view', ...)
  it('denies create with batches.view alone', ...)
  it('allows create with batches.create', ...)
  it('denies edit with batches.view alone', ...)
  it('allows edit with batches.update', ...)
  it('denies every batch route when BatchExpiry is disabled', ...)
})
```

`src/features/batches/pages/__tests__/BatchPermissions.test.tsx`:

```ts
describe('W-LOT-A-1a batch action permissions', () => {
  it('hides the populated-list header create link without batches.create', ...)
  it('hides the empty-state create link without batches.create', ...)
  it('shows both create affordances only with batches.create', ...)
  it('hides edit without batches.update', ...)
  it('hides delete without batches.delete', ...)
  it('hides recall from the revised manager payload', ...)
  it('shows recall to an eligible general manager with batches.recall', ...)
  it('retains state-based action suppression when permission exists', ...)
})
```

`src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts`:

```ts
describe('W-LOT-A-1a generated seeded permission map', () => {
  it('matches the exact canonical batch and all-location role matrix', ...)
})
```

Add to `Sidebar.test.tsx`:

```ts
describe('W-LOT-A-1a batch navigation', () => {
  it('hides batch navigation without batches.view', ...)
  it('shows batch navigation with batches.view and BatchExpiry enabled', ...)
  it('hides batch navigation when BatchExpiry is disabled', ...)
})
```

Add to `usePermissions.moduleAccess.test.tsx`:

```ts
describe('W-LOT-A-1a server-authoritative batch permissions', () => {
  it('accepts batches.view as a valid ModuleKey', ...)
  it('uses a present server permission list as authoritative', ...)
  it('does not restore a missing batch permission from role fallback', ...)
})
```

`e2e/batch-permissions.spec.ts`:

```ts
test.describe('W-LOT-A-1a pre-activation web gating', () => {
  test('serves the fingerprint and gates routes and actions while API enforcement is off', ...)
})

test.describe('W-LOT-A-1a post-activation isolation', () => {
  test('uses selected location B2 and reports duplicate create as already_exists', ...)
})
```

`src/features/settings/RolesPage.test.tsx`:

```ts
describe('W-LOT-A-1a provisioned role read-only contract', () => {
  it('renders a marker-derived protected role without edit or delete affordances', ...)
  it('does not treat an unmarked general_manager name as provisioned read-only', ...)
  it('prevents update submission when a protected role reaches modal state', ...)
})
```

Every Roles-page fixture is typed with `App.Modules.Identity.Application.DTOs.RoleData` (or `satisfies` that generated type) and supplies `is_provisioned_read_only`; no hand-authored role response type or camel-case compatibility member is permitted.

First-failure and command table:

| File / exact `it` or `test` | First failing assertion | Exact command | Lane |
|---|---|---|---|
| `BatchRoutePermissions.test.tsx` — `denies list and detail with inventory.view but without batches.view` | `expect(screen.queryByText(/product batches/i)).not.toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx -t 'denies list and detail with inventory.view but without batches.view'` | Vitest |
| same — `allows list and detail with batches.view` | `expect(screen.getByText(/product batches/i)).toBeInTheDocument()` | same file with `-t 'allows list and detail with batches.view'` | Vitest |
| same — `denies create with batches.view alone` | `expect(screen.queryByText(/create batch/i)).not.toBeInTheDocument()` | same file with `-t 'denies create with batches.view alone'` | Vitest |
| same — `allows create with batches.create` | `expect(screen.getByText(/create batch/i)).toBeInTheDocument()` | same file with `-t 'allows create with batches.create'` | Vitest |
| same — `denies edit with batches.view alone` | `expect(screen.queryByText(/edit batch/i)).not.toBeInTheDocument()` | same file with `-t 'denies edit with batches.view alone'` | Vitest |
| same — `allows edit with batches.update` | `expect(screen.getByText(/edit batch/i)).toBeInTheDocument()` | same file with `-t 'allows edit with batches.update'` | Vitest |
| same — `denies every batch route when BatchExpiry is disabled` | `expect(screen.queryByText(/product batches/i)).not.toBeInTheDocument()` | same file with `-t 'denies every batch route when BatchExpiry is disabled'` | Vitest |
| `BatchPermissions.test.tsx` — `hides the populated-list header create link without batches.create` | `expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchPermissions.test.tsx -t 'hides the populated-list header create link without batches.create'` | Vitest |
| same — `hides the empty-state create link without batches.create` | `expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)` | same file with the exact title | Vitest |
| same — `shows both create affordances only with batches.create` | `expect(screen.getByRole('link', { name: /add batch/i })).toBeInTheDocument()` in each render mode | same file with the exact title | Vitest |
| same — `hides edit without batches.update` | `expect(screen.queryByRole('link', { name: /edit/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `hides delete without batches.delete` | `expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `hides recall from the revised manager payload` | `expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `shows recall to an eligible general manager with batches.recall` | `expect(screen.getByRole('button', { name: /recall/i })).toBeInTheDocument()` | same file with the exact title | Vitest |
| same — `retains state-based action suppression when permission exists` | `expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()` | same file with the exact title | Vitest |
| `BatchSeededPermissionMap.test.ts` — exact matrix title | `expect(PERMISSIONS['batches.view']).toEqual(['admin', 'cashier', 'general_manager', 'manager', 'operator', 'viewer'])` | `cd apps/web && pnpm vitest run src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | Vitest |
| `Sidebar.test.tsx` — each named batch-navigation test | Its expected absent/present batch link assertion | `cd apps/web && pnpm vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx -t '<exact title>'` | Vitest |
| `usePermissions.moduleAccess.test.tsx` — each named server-authoritative test | Its `canAccessModule`/`hasPermission` boolean assertion | `cd apps/web && pnpm vitest run src/hooks/__tests__/usePermissions.moduleAccess.test.tsx -t '<exact title>'` | Vitest |
| Playwright pre-activation exact test | `await expect(page.getByRole('link', { name: /add batch/i })).toHaveCount(0)` | `cd apps/web && pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'` | Playwright |
| Playwright post-activation exact test | `expect(duplicateResponse.meta.outcome).toBe('already_exists')` and exact B2 response assertions | `cd apps/web && pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'post-activation isolation'` | Playwright |
| `RolesPage.test.tsx` — `renders a marker-derived protected role without edit or delete affordances` | `expect(screen.queryByRole('button', { name: /edit/i })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/settings/RolesPage.test.tsx -t 'renders a marker-derived protected role without edit or delete affordances'` | Vitest |
| same — `does not treat an unmarked general_manager name as provisioned read-only` | `expect(screen.getByRole('button', { name: /edit/i })).toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/settings/RolesPage.test.tsx -t 'does not treat an unmarked general_manager name as provisioned read-only'` | Vitest |
| same — `prevents update submission when a protected role reaches modal state` | `expect(api.patch).not.toHaveBeenCalled()` | `cd apps/web && pnpm vitest run src/features/settings/RolesPage.test.tsx -t 'prevents update submission when a protected role reaches modal state'` | Vitest |

### 9.4 Browser split

<a id="r2-b3"></a>
**R2-B3:** while the backend flag remains false, run only the pre-activation Playwright test. It verifies the served fingerprint, direct-route web denial, sidebar gating, both create affordances, detail actions, and that a marker-derived protected role lacks edit/delete affordances without attempting a mutation. It does not expect `already_exists` or location-scoped API responses.

The post-activation test runs only after worker → API → scheduler activation and API read-back `ON`. It:

1. Registers company B.
2. Creates B1 and a second `pos_enabled` B2.
3. Persists the test viewer’s selected scope as `allowed_location_ids=[B2]`.
4. Places identifiable B2 stock/history and proves it is visible.
5. Proves B1 and company-A identifiers are absent.
6. Repeats company-B lot creation.
7. Asserts 422, `meta.outcome=already_exists`, one lot row, and unchanged stock/history/GL state.
8. Confirms the viewer can list but cannot create/edit/delete/recall.
9. Confirms unrestricted general manager can see recall.
10. Confirms no request-recall or hold surface exists.

### 9.5 Static checks, reviewers, and rollback

<a id="r2-m10"></a>
**R2-M10:** `apps/web/package.json` defines `typecheck` at [package.json:21](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/package.json:21) and `typecheck:e2e` at [package.json:22](/Users/houssamr/Projects/syneriva/apps/erp/apps/web/package.json:22). Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm typecheck
pnpm typecheck:e2e
pnpm lint
pnpm vitest run src/features/settings/RolesPage.test.tsx
```

The permission-map gate is the executable fail-closed block in §11.9. It generates two independent temporary artifacts, verifies each exact success line and exit status, requires byte identity between both artifacts and the committed map, and rejects `FAILED`, `SKIPPED`, missing output, or drift.

Task 6 requires ACCEPT from `frontend-conventions-reviewer`, `tenancy-authz-reviewer`, and `inventory-costing-reviewer`. Reviewers must cover API/web name parity, direct routes, navigation, both create links, action guards, server authority, map provenance, fingerprint retention, selected B2 isolation, unchanged inventory/GL evidence, generated role type, marker-derived field, absent controls, modal-submit backstop, absence of name-derived protection, and absence of hold UI.

Do not hand-edit generated permission or DTO types. After activation prefer forward correction; backend emergency rollback is flag-off, but protected-role write rejection and Roles-page read-only behavior remain deployed.

## 10. Complete source-push ledger

Each source file appears exactly once. U-1 is resolved as separate Dokploy applications, so the ledger ends at Push 5 and contains no compose edit or Push 6 ([U-1 resolution:7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7)).

### Push 1 — operations only

No source files.

### Push 2 — additive schema

- `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`
- `apps/api/tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php`

### Push 3 — dormant backend runtime and verification

All Task-1 API files and tests, plus:

- `apps/api/app/Modules/Identity/Domain/Enums/SystemRoleName.php`
- `apps/api/app/Modules/Identity/Domain/Enums/RoleProvisioningSource.php`
- `apps/api/app/Modules/Identity/Domain/Enums/LotActionPermissionDeltaOutcome.php`
- `apps/api/app/Modules/Identity/Application/DTOs/LotActionPermissionDeltaResult.php`
- `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php`
- `apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`
- `apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php`
- `apps/api/app/Console/Commands/ApplyLotActionPermissionDelta.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php`
- `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- `apps/api/app/Modules/BatchExpiry/BatchExpiryServiceProvider.php`
- `docs/glossary.md`
- `apps/api/tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php`
- `apps/api/tests/Feature/Identity/RoleIndexResponseContractTest.php`
- Every Task-2 test except the Push-2 schema test.

Push 3 is accepted only with the flag false, a successful fresh/unmarked legacy-seeder test, and both marked-tenant preservation tests green in PostgreSQL.

### Push 4 — operations only

No source files. Apply/verify the explicit delta, reset permissions, perform recensus, and run all censuses. Do not run the generic seeder while enforcement is false.

### Push 5 — web bundle before activation

All Task-6 modified and added files, including:

- `apps/web/src/features/settings/RolesPage.tsx`
- `apps/web/src/features/settings/RolesPage.test.tsx`
- `packages/shared/types/generated.d.ts`
- generated permission map
- split Playwright specification

SHA rules:

- Record and assign `PUSH1_SHA` through `PUSH5_SHA` as each push is promoted, including operations-only Pushes 1 and 4.
- Persist all five assignments in `/root/wlota1a/shas/push-shas.env`; every later host shell sources that file before using a SHA.
- Backend runtime evidence after Push 3 must match `PUSH3_SHA`; the final promoted and served web `CANDIDATE_SHA` is exactly `PUSH5_SHA`.
- Every source-bearing Push 2, Push 3, and Push 5 rebuilds the staging API, so each is also a `RolesAndPermissionsSeeder` boot run while `SYNC_PERMISSIONS_ON_BOOT=true` ([entrypoint.sh:157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:157) through line 165).
- A missing, empty, non-40-hex, or mismatched SHA blocks deployment.

## 11. Deployment variables and fail-closed fleet protocol

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). Manifest U-1 is resolved as separate Dokploy applications; there is no compose branch and no Push 6 ([U-1 resolution:7](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7)).

| Variable | W-LOT-A-1a value |
|---|---|
| `<slice>` | `wlota1a-permissions-roles-web` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php` — additive and self-guarding |
| Flags | `lot_action_permissions.enforce` / `LOT_ACTION_PERMISSIONS_ENFORCE` / `apps/api/config/lot_action_permissions.php` / `false` |
| Commands | NEW `permissions:apply-lot-action-delta {--apply} {--verify}` under `tenants:run`, marker `WLOTA1A-PERMISSIONS`; named disposable-tenant seeding only; permission-cache and permission-map gates |
| Censuses | Exact-once Push-1, Push-4, preactivation, and postactivation day-one/lot/phantom gates |
| Web changes | yes — fingerprint `wlota1a-batch-permission-gating-v1`; build SHA equals `PUSH5_SHA` |
| Device build | no |
| Queues | none |
| Push count | exactly five; Pushes 1 and 4 are operational; Push 6 does not exist |
| Env path | Update the full `env` text through Dokploy tRPC `application.update` for worker, API, and scheduler separately; redeploy each through `application.redeploy`; verify `application.one` and inside-container `printenv` independently |
| Application IDs | API `x5wfthp8-7cVbiUfI6Hq7`; worker `KKYDsAvk4UpYfJXVmsDj2`; scheduler `HSXqHvmo_vq7NAYIjrE3T`; web `mY6P_PHb4pw-2LdG1Y7Ml` |
| Container names | API `erp-staging-api-kghqex`; worker `erp-staging-worker-p2yjl1`; scheduler `erp-staging-scheduler-iqjxrv`; web `erp-staging-web-dqepfa` |
| Durable state | `/root/wlota1a/`; every manifest, helper, SHA, snapshot, and evidence file is host-persisted |
| Boot synchronization | API application has `SYNC_PERMISSIONS_ON_BOOT=true`; every source push is a seeder run ([entrypoint.sh:157](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/docker/entrypoint.sh:157) through line 165) |

<a id="r3-b-b"></a>
**R3-B-B:** every check below is executable and fail-closed. Every shell starts with `set -Eeuo pipefail`. A failed command, `test`, `grep`, `cmp`, parse, readback, marker check, census, SHA check, or direct command exits non-zero. Promotion stops immediately.

<a id="r4-b-b2"></a>
<a id="r5-b-b2"></a>
### 11.1 U-1 confirmation gate — mandatory before source-push dispatch

After the §11.2 helper bootstrap has installed the single durable `fail_gate` definition, run this self-contained block on the deployment-admin workstation. The API key location is [deploy-runbook.md:30](/Users/houssamr/Projects/syneriva/claude/deploy-runbook.md:30). It fetches all four exact application records through `application.one`, verifies application ID, environment, Dockerfile build type, and host container name, then explicitly transfers the records to the staging host. Environment values are never printed. This U-1 gate still completes before any source-push dispatch.

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
DOKPLOY_URL='https://app.dokploy.com'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
STAGING_HOST='root@157.180.71.252'
ENVIRONMENT_ID='Ree8z_4ixu7vAgBLdI_A_'

fetch_and_verify() {
  role="$1"
  application_id="$2"
  app_name="$3"
  output="${WORK}/${role}.json"

  curl --fail-with-body --silent --show-error \
    -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" \
    > "$output"

  test "$(jq -er '.applicationId' "$output")" = "$application_id"
  test "$(jq -er '.environmentId' "$output")" = "$ENVIRONMENT_ID"
  test "$(jq -er '.buildType' "$output")" = 'dockerfile'
  test "$(jq -er '.appName' "$output")" = "$app_name"
  jq -e 'has("env") and (.env | type == "string")' "$output" >/dev/null
}

fetch_and_verify api 'x5wfthp8-7cVbiUfI6Hq7' 'erp-staging-api-kghqex'
fetch_and_verify worker 'KKYDsAvk4UpYfJXVmsDj2' 'erp-staging-worker-p2yjl1'
fetch_and_verify scheduler 'HSXqHvmo_vq7NAYIjrE3T' 'erp-staging-scheduler-iqjxrv'
fetch_and_verify web 'mY6P_PHb4pw-2LdG1Y7Ml' 'erp-staging-web-dqepfa'

api_sync_value="$(
  jq -r '.env' "$WORK/api.json" \
    | awk -F= '$1 == "SYNC_PERMISSIONS_ON_BOOT" { print $2 }'
)"
test "$(printf '%s\n' "$api_sync_value" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1
test "$api_sync_value" = 'true'

ssh "$STAGING_HOST" 'install -d -m 700 /root/wlota1a/applications'
scp "$WORK"/api.json "$WORK"/worker.json "$WORK"/scheduler.json "$WORK"/web.json \
  "${STAGING_HOST}:/root/wlota1a/applications/"

ssh "$STAGING_HOST" 'bash -s' <<'REMOTE'
set -Eeuo pipefail
export LC_ALL=C
ROOT='/root/wlota1a'
source "$ROOT/helpers/fail-gate.sh"

cat > "$ROOT/applications.expected.tsv" <<'EXPECTED_APPLICATIONS'
api	x5wfthp8-7cVbiUfI6Hq7	Ree8z_4ixu7vAgBLdI_A_	dockerfile	erp-staging-api-kghqex
worker	KKYDsAvk4UpYfJXVmsDj2	Ree8z_4ixu7vAgBLdI_A_	dockerfile	erp-staging-worker-p2yjl1
scheduler	HSXqHvmo_vq7NAYIjrE3T	Ree8z_4ixu7vAgBLdI_A_	dockerfile	erp-staging-scheduler-iqjxrv
web	mY6P_PHb4pw-2LdG1Y7Ml	Ree8z_4ixu7vAgBLdI_A_	dockerfile	erp-staging-web-dqepfa
EXPECTED_APPLICATIONS

: > "$ROOT/applications.actual.tsv"
for role in api worker scheduler web; do
  jq -er --arg role "$role" \
    '[$role,.applicationId,.environmentId,.buildType,.appName] | @tsv' \
    "$ROOT/applications/${role}.json" >> "$ROOT/applications.actual.tsv"
done

cmp -s "$ROOT/applications.expected.tsv" "$ROOT/applications.actual.tsv" || fail_gate 'u1_application_records'
printf '%s\n' 'separate_applications' > "$ROOT/topology.txt"
test "$(grep -Fxc 'separate_applications' "$ROOT/topology.txt")" -eq 1
chmod 600 "$ROOT"/applications/*.json
printf 'WLOTA1A-U1 topology=separate_applications applications=4 outcome=PASS\n'
REMOTE
```

Any fetch, field, sync setting, transfer, or `cmp` mismatch exits non-zero and forbids source-push dispatch.

<a id="r5-b-b3"></a>
### 11.2 Durable host and container helpers

Run once from the deployment-admin workstation, before §11.1. It creates the durable host root and all host/container helper files used below. `fail_gate()` is defined exactly once, in `fail-gate.sh`; host, container, U-1, and workstation gates source that file rather than redefining it. Every later host block explicitly sources `host-helpers.sh` directly or through a committed durable wrapper; every later API-container block explicitly copies `fail-gate.sh` plus `container-helpers.sh` and sources the latter after resolving the current API container. No container-local file is treated as durable; source pushes replace all four application containers, while `/root` survives ([U-1 resolution:28](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:28)).

Each phase capture also runs the executable `php artisan tinker --execute=...` producer below **inside the current API container**. The verified application model is `App\Modules\Tenant\Domain\Tenant`; its canonical database resolver is `getDatabaseName()` at [Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264), and its inherited `getTenantKey()` accessor is declared at [Tenant.php:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Database/Models/Tenant.php:42). Manual construction is forbidden: the configured default is direct `prefix + tenant_id + suffix` at [tenancy.php:59](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/tenancy.php:59) through line 63, while Stancl first honors a persisted `db_name` override at [DatabaseConfig.php:66](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/DatabaseConfig.php:66) through line 68. The producer emits exactly one `<uuid>\t<database>` record per tenant; the phase directory is copied to `/root/wlota1a/manifests/<PHASE>/`, where `tenant-databases.tsv` is revalidated against `expected-tenants.txt` before any consumer can run.

```bash
set -Eeuo pipefail
STAGING_HOST='root@157.180.71.252'

ssh "$STAGING_HOST" 'bash -s' <<'REMOTE'
set -Eeuo pipefail
install -d -m 700 /root/wlota1a/{manifests,helpers,evidence,snapshots,backups,shas}

cat > /root/wlota1a/helpers/fail-gate.sh <<'FAIL_GATE_HELPER'
fail_gate() { printf 'WLOTA1A-GATE outcome=FAILED reason=%s\n' "$1" >&2; exit 1; }
FAIL_GATE_HELPER

cat > /root/wlota1a/helpers/host-helpers.sh <<'HOST_HELPERS'
set -Eeuo pipefail
export LC_ALL=C
ROOT='/root/wlota1a'
UUID_RE='[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
source "$ROOT/helpers/fail-gate.sh"

line_count() { wc -l < "$1" | tr -d ' '; }
reject_bad_output() {
  log="$1"
  if grep -Eq '(^|[ =:])(FAILED|SKIPPED|ERRORED)([ =:]|$)|missing database|does not exist|SQLSTATE|Unhandled|Exception' "$log"; then
    fail_gate "bad_output:${log}"
  fi
}

validate_tenant_database_mapping() {
  manifest="$1"; mapping="$2"; label="$3"
  test -s "$manifest" || fail_gate "tenant_database_manifest_empty:${label}"
  test -s "$mapping" || fail_gate "tenant_database_mapping_empty:${label}"
  if ! awk -F '\t' 'NF != 2 || $1 == "" || $2 == "" { exit 1 }' "$mapping"; then
    fail_gate "tenant_database_mapping_shape:${label}"
  fi
  manifest_count="$(line_count "$manifest")"
  mapping_count="$(line_count "$mapping")"
  test "$mapping_count" -eq "$manifest_count" || fail_gate "tenant_database_mapping_count:${label}"
  cut -f1 "$mapping" | sort > "$ROOT/evidence/${label}.tenant-database-uuids.actual.txt"
  sort "$manifest" > "$ROOT/evidence/${label}.tenant-database-uuids.expected.txt"
  cmp -s "$ROOT/evidence/${label}.tenant-database-uuids.expected.txt" \
    "$ROOT/evidence/${label}.tenant-database-uuids.actual.txt" \
    || fail_gate "tenant_database_mapping_uuid_set:${label}"
}

resolve_container() {
  app_name="$1"
  matches="$(docker ps --filter "name=${app_name}" --format '{{.ID}}')"
  test "$(printf '%s\n' "$matches" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1
  printf '%s\n' "$matches"
}

assert_marker_cmp() {
  manifest="$1"; actual="$2"; prefix="$3"; tail="$4"; label="$5"
  template="$ROOT/evidence/${label}.template.txt"
  expected="$ROOT/evidence/${label}.expected.txt"
  sorted_actual="$ROOT/evidence/${label}.actual.sorted.txt"
  test -f "$manifest"; test -f "$actual"
  cat > "$template" <<'EXPECTED_MARKER_TEMPLATE'
__PREFIX__ tenant=__TENANT__ __TAIL__
EXPECTED_MARKER_TEMPLATE
  : > "$expected"
  while IFS= read -r tenant_id; do
    awk -v prefix="$prefix" -v tenant="$tenant_id" -v tail="$tail" \
      '{gsub(/__PREFIX__/,prefix); gsub(/__TENANT__/,tenant); gsub(/__TAIL__/,tail); print}' \
      "$template" >> "$expected"
  done < "$manifest"
  sort -o "$expected" "$expected"
  sort "$actual" > "$sorted_actual"
  cmp -s "$expected" "$sorted_actual" || fail_gate "marker_cmp:${label}"
  test "$(line_count "$sorted_actual")" -eq "$(line_count "$manifest")"
}

assert_census_cmp() {
  manifest="$1"; actual="$2"; label="$3"
  template="$ROOT/evidence/${label}.template.txt"
  expected="$ROOT/evidence/${label}.expected.txt"
  sorted_actual="$ROOT/evidence/${label}.actual.sorted.txt"
  test -s "$manifest"; test -f "$actual"
  cat > "$template" <<'EXPECTED_CENSUS_TEMPLATE'
WLOTA1A-CENSUS-DAY tenant=__TENANT__ outcome=PASS
WLOTA1A-CENSUS-LOT tenant=__TENANT__ outcome=PASS
WLOTA1A-CENSUS-PHANTOM tenant=__TENANT__ outcome=PASS
EXPECTED_CENSUS_TEMPLATE
  : > "$expected"
  while IFS= read -r tenant_id; do
    sed "s/__TENANT__/${tenant_id}/g" "$template" >> "$expected"
  done < "$manifest"
  sort -o "$expected" "$expected"
  sort "$actual" > "$sorted_actual"
  cmp -s "$expected" "$sorted_actual" || fail_gate "census_cmp:${label}"
  test "$(line_count "$sorted_actual")" -eq "$((3 * $(line_count "$manifest")))"
}

capture_permission_snapshot() {
  manifest="$1"; mapping="$2"; label="$3"; output="$ROOT/snapshots/${label}.txt"
  pg="$(resolve_container 'erp-staging-postgres-8x7pbx')"
  tenant_output="$ROOT/snapshots/${label}.tenants"
  validate_tenant_database_mapping "$manifest" "$mapping" "${label}.snapshot"
  rm -rf "$tenant_output"; mkdir -p "$tenant_output"; : > "$output"
  while IFS=$'\t' read -r tenant_id tenant_database; do
    status=0
    set +e
    docker exec "$pg" psql -X -A -t -F '|' -v ON_ERROR_STOP=1 -U autoerp -d "$tenant_database" \
      -c "SELECT 'permission',id,name,guard_name FROM permissions ORDER BY id;
          SELECT 'role',id,name,guard_name,COALESCE(tenant_id::text,'NULL') FROM roles ORDER BY id;
          SELECT 'grant',role_id,permission_id FROM role_has_permissions ORDER BY role_id,permission_id;" \
      > "$tenant_output/${tenant_id}.stdout.txt" 2> "$tenant_output/${tenant_id}.stderr.txt"
    status=$?
    set -e
    printf '%s\n' "$status" > "$tenant_output/${tenant_id}.status"
  done < "$mapping"
  while IFS=$'\t' read -r tenant_id tenant_database; do
    test "$(cat "$tenant_output/${tenant_id}.status")" -eq 0 \
      || fail_gate "permission_snapshot_status:${label}:${tenant_id}"
    reject_bad_output "$tenant_output/${tenant_id}.stdout.txt"
    reject_bad_output "$tenant_output/${tenant_id}.stderr.txt"
    printf 'TENANT %s\n' "$tenant_id" >> "$output"
    cat "$tenant_output/${tenant_id}.stdout.txt" >> "$output"
  done < "$mapping"
  test -s "$output"
}

assert_preserved_post_delta_snapshot() {
  manifest="$1"; mapping="$2"; label="$3"
  actual="$ROOT/snapshots/${label}.actual.txt"
  expected="$ROOT/snapshots/${label}.expected.txt"
  template="$ROOT/snapshots/${label}.template.txt"
  tenant_output="$ROOT/snapshots/${label}.tenants"
  pg="$(resolve_container 'erp-staging-postgres-8x7pbx')"
  validate_tenant_database_mapping "$manifest" "$mapping" "${label}.preserved"
  rm -rf "$tenant_output"; mkdir -p "$tenant_output"; : > "$actual"
  while IFS=$'\t' read -r tenant_id tenant_database; do
    status=0
    set +e
    docker exec "$pg" psql -X -A -t -v ON_ERROR_STOP=1 -U autoerp -d "$tenant_database" -v tenant_id="$tenant_id" -c "
      SELECT 'WLOTA1A-PRESERVED tenant=' || :'tenant_id'
        || ' marker_count=' || (SELECT count(*) FROM roles WHERE tenant_id = :'tenant_id' AND name = 'general_manager' AND guard_name = 'sanctum' AND provisioning_source = 'w-lot-a-1a')
        || ' manager_recall=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='manager' AND p.name='batches.recall') THEN 'present' ELSE 'absent' END
        || ' manager_request=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='manager' AND p.name='batches.recall.request') THEN 'present' ELSE 'absent' END
        || ' viewer_view=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='viewer' AND p.name='batches.view') THEN 'present' ELSE 'absent' END
        || ' operator_view=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='operator' AND p.name='batches.view') THEN 'present' ELSE 'absent' END
        || ' general_manager_recall=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='general_manager' AND r.provisioning_source='w-lot-a-1a' AND p.name='batches.recall') THEN 'present' ELSE 'absent' END
        || ' general_manager_treasury=' || CASE WHEN EXISTS (SELECT 1 FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.tenant_id=:'tenant_id' AND r.name='general_manager' AND r.provisioning_source='w-lot-a-1a' AND p.name='treasury.manage_all_locations') THEN 'present' ELSE 'absent' END;" \
      > "$tenant_output/${tenant_id}.stdout.txt" 2> "$tenant_output/${tenant_id}.stderr.txt"
    status=$?
    set -e
    printf '%s\n' "$status" > "$tenant_output/${tenant_id}.status"
  done < "$mapping"
  while IFS=$'\t' read -r tenant_id tenant_database; do
    test "$(cat "$tenant_output/${tenant_id}.status")" -eq 0 \
      || fail_gate "preserved_snapshot_status:${label}:${tenant_id}"
    reject_bad_output "$tenant_output/${tenant_id}.stdout.txt"
    reject_bad_output "$tenant_output/${tenant_id}.stderr.txt"
    cat "$tenant_output/${tenant_id}.stdout.txt" >> "$actual"
  done < "$mapping"
  cat > "$template" <<'EXPECTED_PRESERVED_TEMPLATE'
WLOTA1A-PRESERVED tenant=__TENANT__ marker_count=1 manager_recall=absent manager_request=present viewer_view=present operator_view=present general_manager_recall=present general_manager_treasury=present
EXPECTED_PRESERVED_TEMPLATE
  : > "$expected"
  while IFS= read -r tenant_id; do
    sed "s/__TENANT__/${tenant_id}/g" "$template" >> "$expected"
  done < "$manifest"
  sort -o "$actual" "$actual"; sort -o "$expected" "$expected"
  cmp -s "$expected" "$actual" || fail_gate "preserved_post_delta:${label}"
}

prepare_source_push() {
  label="$1"; manifest="$2"; mapping="$3"
  api="$(resolve_container 'erp-staging-api-kghqex')"
  printf '%s\n' "$api" > "$ROOT/evidence/${label}.api-before.txt"
  capture_permission_snapshot "$manifest" "$mapping" "${label}.before"
}

verify_source_push_inert() {
  label="$1"; manifest="$2"; mapping="$3"
  before_api="$(cat "$ROOT/evidence/${label}.api-before.txt")"
  api=''
  for attempt in $(seq 1 90); do
    matches="$(docker ps --filter 'name=erp-staging-api-kghqex' --format '{{.ID}}')"
    if test "$(printf '%s\n' "$matches" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1 \
      && test "$matches" != "$before_api"; then api="$matches"; break; fi
    sleep 5
  done
  test -n "$api" || fail_gate "api_not_replaced:${label}"
  test "$(docker exec "$api" printenv SYNC_PERMISSIONS_ON_BOOT)" = 'true'
  test "$(docker exec "$api" printenv LOT_ACTION_PERMISSIONS_ENFORCE)" = 'false'
  capture_permission_snapshot "$manifest" "$mapping" "${label}.after"
  cmp -s "$ROOT/snapshots/${label}.before.txt" "$ROOT/snapshots/${label}.after.txt" \
    || fail_gate "boot_seed_changed_existing_tenant:${label}"
  printf 'WLOTA1A-BOOT-SEED push=%s sync=true enforce=false role_permission_delta=0 outcome=PASS\n' "$label"
}

capture_manifest_on_current_api() {
  phase="$1"; api="$(resolve_container 'erp-staging-api-kghqex')"
  docker cp "$ROOT/helpers/fail-gate.sh" "$api:/tmp/wlota1a-fail-gate.sh"
  docker cp "$ROOT/helpers/container-helpers.sh" "$api:/tmp/wlota1a-container-helpers.sh"
  docker exec --workdir /var/www/html "$api" bash -lc \
    "source /tmp/wlota1a-container-helpers.sh; capture_tenant_manifest '$phase'"
  rm -rf "$ROOT/manifests/$phase"
  docker cp "$api:/tmp/wlota1a-$phase" "$ROOT/manifests/$phase"
  test -s "$ROOT/manifests/$phase/expected-tenants.txt"
  validate_tenant_database_mapping "$ROOT/manifests/$phase/expected-tenants.txt" \
    "$ROOT/manifests/$phase/tenant-databases.tsv" "$phase"
}

validate_tenant_runner_evidence() {
  manifest="$1"; directory="$2"; label="$3"
  test -f "$directory/markers.txt" || fail_gate "marker_aggregate_missing:${label}"
  while IFS= read -r tenant_id; do
    test -f "$directory/${tenant_id}.log" || fail_gate "tenant_log_missing:${label}:${tenant_id}"
    test -f "$directory/${tenant_id}.status" || fail_gate "tenant_status_missing:${label}:${tenant_id}"
    status="$(cat "$directory/${tenant_id}.status")"
    printf '%s' "$status" | grep -Eq '^[0-9]+$' || fail_gate "tenant_status_malformed:${label}:${tenant_id}"
    test "$status" -eq 0 || fail_gate "tenant_command_status:${label}:${tenant_id}:${status}"
    test "$(grep -Fxc "Tenant: ${tenant_id}" "$directory/${tenant_id}.log" || true)" -eq 1 \
      || fail_gate "tenant_banner:${label}:${tenant_id}"
    reject_bad_output "$directory/${tenant_id}.log"
  done < "$manifest"
}

validate_census_evidence() {
  manifest="$1"; directory="$2"; label="$3"
  test -f "$directory/census.markers.txt" || fail_gate "census_aggregate_missing:${label}"
  while IFS= read -r tenant_id; do
    day="$directory/day-${tenant_id}.log"; lot="$directory/lot-${tenant_id}.log"; phantom="$directory/phantom-${tenant_id}.log"
    for command in day lot phantom; do
      status="$(cat "$directory/${command}-${tenant_id}.status")"
      printf '%s' "$status" | grep -Eq '^[0-9]+$' || fail_gate "census_status_malformed:${label}:${command}:${tenant_id}"
      test "$status" -eq 0 || fail_gate "census_status:${label}:${command}:${tenant_id}:${status}"
    done
    test "$(grep -Fxc "Tenant: ${tenant_id}" "$day" || true)" -eq 1 || fail_gate "census_tenant_banner:${label}:${tenant_id}"
    clean="$(grep -Ec "^DAY-ONE CENSUS ${tenant_id} ${UUID_RE}: CLEAN$" "$day" || true)"
    test "$clean" -gt 0; test "$clean" -eq "$(grep -Ec '^DAY-ONE CENSUS ' "$day" || true)"; reject_bad_output "$day"
    reject_bad_output "$lot"; test "$(grep -Fxc 'Tuples drifted: 0' "$lot" || true)" -eq 1
    test "$(grep -Fxc 'Net drift: 0.0000' "$lot" || true)" -eq 1
    test "$(grep -Fxc 'Absolute drift: 0.0000' "$lot" || true)" -eq 1
    test "$(grep -Fxc 'Read-only census: nothing was written.' "$lot" || true)" -eq 1
    reject_bad_output "$phantom"
    for literal in 'Phantom DEFAULT lots: 0' 'Total phantom quantity: 0.0000' \
      'Reservations re-pointed to real lots: 0' 'Reservations left on the DEFAULT lot: 0' \
      'Lots only partially reduced: 0' 'Lots skipped (excess changed under the lock): 0' \
      'Tuples still drifted: 0' 'Dry run: nothing was written. Re-run with --execute to apply.'; do
      test "$(grep -Fxc "$literal" "$phantom" || true)" -eq 1
    done
  done < "$manifest"
}

run_delta_on_current_api() {
  manifest="$1"; mode="$2"; label="$3"; api="$(resolve_container 'erp-staging-api-kghqex')"
  docker cp "$ROOT/helpers/fail-gate.sh" "$api:/tmp/wlota1a-fail-gate.sh"
  docker cp "$ROOT/helpers/container-helpers.sh" "$api:/tmp/wlota1a-container-helpers.sh"
  docker cp "$manifest" "$api:/tmp/wlota1a-expected-tenants.txt"
  docker exec --workdir /var/www/html "$api" bash -lc \
    "source /tmp/wlota1a-container-helpers.sh; run_delta /tmp/wlota1a-expected-tenants.txt '$mode' '$label'"
  rm -rf "$ROOT/evidence/$label"
  docker cp "$api:/tmp/wlota1a-$label" "$ROOT/evidence/$label"
  validate_tenant_runner_evidence "$manifest" "$ROOT/evidence/$label" "$label"
}

run_censuses_on_current_api() {
  manifest="$1"; label="$2"; api="$(resolve_container 'erp-staging-api-kghqex')"
  docker cp "$ROOT/helpers/fail-gate.sh" "$api:/tmp/wlota1a-fail-gate.sh"
  docker cp "$ROOT/helpers/container-helpers.sh" "$api:/tmp/wlota1a-container-helpers.sh"
  docker cp "$manifest" "$api:/tmp/wlota1a-expected-tenants.txt"
  docker exec --workdir /var/www/html "$api" bash -lc \
    "source /tmp/wlota1a-container-helpers.sh; run_censuses /tmp/wlota1a-expected-tenants.txt '$label'"
  rm -rf "$ROOT/evidence/$label"
  docker cp "$api:/tmp/wlota1a-$label" "$ROOT/evidence/$label"
  validate_census_evidence "$manifest" "$ROOT/evidence/$label" "$label"
}

run_reseed_on_current_api() {
  manifest="$1"; label="$2"; api="$(resolve_container 'erp-staging-api-kghqex')"
  docker cp "$ROOT/helpers/fail-gate.sh" "$api:/tmp/wlota1a-fail-gate.sh"
  docker cp "$ROOT/helpers/container-helpers.sh" "$api:/tmp/wlota1a-container-helpers.sh"
  docker cp "$manifest" "$api:/tmp/wlota1a-expected-tenants.txt"
  docker exec --workdir /var/www/html "$api" bash -lc \
    "source /tmp/wlota1a-container-helpers.sh; run_reseed /tmp/wlota1a-expected-tenants.txt '$label'"
  rm -rf "$ROOT/evidence/$label"
  docker cp "$api:/tmp/wlota1a-$label" "$ROOT/evidence/$label"
  validate_tenant_runner_evidence "$manifest" "$ROOT/evidence/$label" "$label"
}
HOST_HELPERS

cat > /root/wlota1a/helpers/container-helpers.sh <<'CONTAINER_HELPERS'
set -Eeuo pipefail
export LC_ALL=C
UUID_RE='[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
source /tmp/wlota1a-fail-gate.sh
reject_bad_output() {
  log="$1"
  if grep -Eq '(^|[ =:])(FAILED|SKIPPED|ERRORED)([ =:]|$)|missing database|does not exist|SQLSTATE|Unhandled|Exception' "$log"; then
    fail_gate "bad_output:${log}"
  fi
}
validate_tenant_database_mapping() {
  manifest="$1"; mapping="$2"; label="$3"
  test -s "$manifest" || fail_gate "tenant_database_manifest_empty:${label}"
  test -s "$mapping" || fail_gate "tenant_database_mapping_empty:${label}"
  if ! awk -F '\t' 'NF != 2 || $1 == "" || $2 == "" { exit 1 }' "$mapping"; then
    fail_gate "tenant_database_mapping_shape:${label}"
  fi
  test "$(wc -l < "$mapping" | tr -d ' ')" -eq "$(wc -l < "$manifest" | tr -d ' ')" \
    || fail_gate "tenant_database_mapping_count:${label}"
  cut -f1 "$mapping" | sort > "$out/tenant-database-uuids.actual.txt"
  sort "$manifest" > "$out/tenant-database-uuids.expected.txt"
  cmp -s "$out/tenant-database-uuids.expected.txt" "$out/tenant-database-uuids.actual.txt" \
    || fail_gate "tenant_database_mapping_uuid_set:${label}"
}
capture_tenant_manifest() {
  phase="$1"; out="/tmp/wlota1a-${phase}"; mkdir -p "$out"
  php artisan tenants:list --no-ansi 2>&1 | tee "$out/tenants.log"
  reject_bad_output "$out/tenants.log"
  grep '^\[Tenant\] ' "$out/tenants.log" > "$out/tenant-records.txt"
  test -s "$out/tenant-records.txt"
  awk '/^\[Tenant\] / { value=$0; sub(/^.*: /,"",value); sub(/ @ .*$/,"",value); print tolower(value) }' \
    "$out/tenant-records.txt" > "$out/tenant-ids.raw.txt"
  test "$(wc -l < "$out/tenant-ids.raw.txt" | tr -d ' ')" \
    -eq "$(wc -l < "$out/tenant-records.txt" | tr -d ' ')"
  if grep -Ev "^${UUID_RE}$" "$out/tenant-ids.raw.txt"; then
    fail_gate "malformed_tenant_uuid:${phase}"
  fi
  sort "$out/tenant-ids.raw.txt" > "$out/expected-tenants.txt"
  test "$(wc -l < "$out/expected-tenants.txt" | tr -d ' ')" -gt 0
  test "$(uniq "$out/expected-tenants.txt" | wc -l | tr -d ' ')" \
    -eq "$(wc -l < "$out/expected-tenants.txt" | tr -d ' ')"
  php artisan tinker --execute='foreach (\App\Modules\Tenant\Domain\Tenant::query()->orderBy("id")->get() as $t) { fwrite(STDOUT, $t->getTenantKey()."\t".$t->getDatabaseName()."\n"); }' \
    > "$out/tenant-databases.raw.tsv" 2> "$out/tenant-databases.stderr.log" \
    || fail_gate "tenant_database_mapping_producer:${phase}"
  test ! -s "$out/tenant-databases.stderr.log" || reject_bad_output "$out/tenant-databases.stderr.log"
  sort -k1,1 "$out/tenant-databases.raw.tsv" > "$out/tenant-databases.tsv"
  validate_tenant_database_mapping "$out/expected-tenants.txt" "$out/tenant-databases.tsv" "$phase"
}
run_delta() {
  manifest="$1"; mode="$2"; label="$3"; out="/tmp/wlota1a-${label}"; rm -rf "$out"; mkdir -p "$out"; : > "$out/markers.txt"
  while IFS= read -r tenant_id; do
    log="$out/${tenant_id}.log"
    status=0
    set +e
    php artisan tenants:run permissions:apply-lot-action-delta --tenants="$tenant_id" \
      --option="${mode}=1" --no-ansi > "$log" 2>&1
    status=$?
    set -e
    printf '%s\n' "$status" > "$out/${tenant_id}.status"
    cat "$log"
  done < "$manifest"
  grep -h '^WLOTA1A-PERMISSIONS tenant=' "$out"/*.log > "$out/markers.txt" || true
}
run_reseed() {
  manifest="$1"; label="$2"; out="/tmp/wlota1a-${label}"; rm -rf "$out"; mkdir -p "$out"; : > "$out/markers.txt"
  while IFS= read -r tenant_id; do
    log="$out/${tenant_id}.log"
    status=0
    set +e
    php artisan tenants:seed --tenants="$tenant_id" --force \
      --class='Database\Seeders\RolesAndPermissionsSeeder' --no-ansi > "$log" 2>&1
    status=$?
    set -e
    printf '%s\n' "$status" > "$out/${tenant_id}.status"
    cat "$log"
  done < "$manifest"
  grep -h '^WLOTA1A-RESEED tenant=' "$out"/*.log > "$out/markers.txt" || true
}
run_censuses() {
  manifest="$1"; label="$2"; out="/tmp/wlota1a-${label}"; rm -rf "$out"; mkdir -p "$out"
  : > "$out/census.markers.txt"
  while IFS= read -r tenant_id; do
    day="$out/day-${tenant_id}.log"; lot="$out/lot-${tenant_id}.log"; phantom="$out/phantom-${tenant_id}.log"
    day_status=0; set +e
    php artisan tenants:run tenant:census-day-one --tenants="$tenant_id" --option='fail-on-drift=1' --no-ansi > "$day" 2>&1
    day_status=$?; set -e
    test "$day_status" -ne 0 || printf 'WLOTA1A-CENSUS-DAY tenant=%s outcome=PASS\n' "$tenant_id" >> "$day"
    printf '%s\n' "$day_status" > "$out/day-${tenant_id}.status"; cat "$day"
    lot_status=0; set +e
    php artisan inventory:lot-drift-census --tenant="$tenant_id" --fail-on-drift --no-ansi > "$lot" 2>&1
    lot_status=$?; set -e
    test "$lot_status" -ne 0 || printf 'WLOTA1A-CENSUS-LOT tenant=%s outcome=PASS\n' "$tenant_id" >> "$lot"
    printf '%s\n' "$lot_status" > "$out/lot-${tenant_id}.status"; cat "$lot"
    phantom_status=0; set +e
    php artisan inventory:repair-phantom-default-batches --tenant="$tenant_id" --dry-run --no-ansi > "$phantom" 2>&1
    phantom_status=$?; set -e
    test "$phantom_status" -ne 0 || printf 'WLOTA1A-CENSUS-PHANTOM tenant=%s outcome=PASS\n' "$tenant_id" >> "$phantom"
    printf '%s\n' "$phantom_status" > "$out/phantom-${tenant_id}.status"; cat "$phantom"
  done < "$manifest"
  grep -h '^WLOTA1A-CENSUS-' "$out"/{day,lot,phantom}-*.log > "$out/census.markers.txt" || true
}
CONTAINER_HELPERS

cat > /root/wlota1a/helpers/record-sha.sh <<'RECORD_SHA'
set -Eeuo pipefail
push_no="$1"; sha="$2"; ledger='/root/wlota1a/shas/push-shas.env'
case "$push_no" in 1|2|3|4|5) ;; *) exit 1 ;; esac
printf '%s' "$sha" | grep -Eq '^[0-9a-f]{40}$'
test "$(grep -Ec "^PUSH${push_no}_SHA=" "$ledger" 2>/dev/null || true)" -eq 0
printf 'PUSH%s_SHA=%s\n' "$push_no" "$sha" >> "$ledger"
chmod 600 "$ledger"
RECORD_SHA
chmod 700 /root/wlota1a/helpers/*.sh

cat > /root/wlota1a/helpers/capture-manifest-phase.sh <<'CAPTURE_MANIFEST_PHASE'
set -Eeuo pipefail
PHASE="${PHASE:?set PHASE to p1, p2, p4, preactivation, or postactivation}"
case "$PHASE" in p1|p2|p4|preactivation|postactivation) ;; *) exit 2 ;; esac
ROOT='/root/wlota1a'
source "$ROOT/helpers/host-helpers.sh"
source "$ROOT/shas/push-shas.env"
capture_manifest_on_current_api "$PHASE"
test -s "$ROOT/manifests/$PHASE/expected-tenants.txt"
test -s "$ROOT/manifests/$PHASE/tenant-databases.tsv"
CAPTURE_MANIFEST_PHASE
chmod 700 /root/wlota1a/helpers/capture-manifest-phase.sh

cat > /root/wlota1a/helpers/backup-phase.sh <<'BACKUP_PHASE'
set -Eeuo pipefail
PHASE="${PHASE:?set PHASE to p2 or p4}"
case "$PHASE" in p2|p4) ;; *) exit 2 ;; esac
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/$PHASE/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/$PHASE/tenant-databases.tsv"
validate_tenant_database_mapping "$MANIFEST" "$MAPPING" "backup-${PHASE}"
PG="$(resolve_container 'erp-staging-postgres-8x7pbx')"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ACTUAL="$ROOT/evidence/backup-${PHASE}.actual.txt"; : > "$ACTUAL"
OUTPUT="$ROOT/evidence/backup-${PHASE}.tenants"; rm -rf "$OUTPUT"; mkdir -p "$OUTPUT"
while IFS=$'\t' read -r tenant_id tenant_database; do
  dump="$ROOT/backups/${PHASE}-${tenant_id}-${STAMP}.dump"
  status=0
  set +e
  docker exec "$PG" pg_dump -Fc -U autoerp -d "$tenant_database" \
    > "$dump" 2> "$OUTPUT/${tenant_id}.stderr.log"
  status=$?
  set -e
  printf '%s\n' "$status" > "$OUTPUT/${tenant_id}.status"
  if test "$status" -eq 0 && test -s "$dump"; then
    printf 'WLOTA1A-BACKUP tenant=%s outcome=PASS\n' "$tenant_id" > "$OUTPUT/${tenant_id}.marker.log"
  else
    : > "$OUTPUT/${tenant_id}.marker.log"
  fi
done < "$MAPPING"
grep -h '^WLOTA1A-BACKUP tenant=' "$OUTPUT"/*.marker.log > "$ACTUAL" || true
while IFS=$'\t' read -r tenant_id tenant_database; do
  status="$(cat "$OUTPUT/${tenant_id}.status")"
  test "$status" -eq 0 || fail_gate "backup_status:${PHASE}:${tenant_id}:${status}"
  reject_bad_output "$OUTPUT/${tenant_id}.stderr.log"
  test -s "$ROOT/backups/${PHASE}-${tenant_id}-${STAMP}.dump" || fail_gate "backup_empty:${PHASE}:${tenant_id}"
done < "$MAPPING"
assert_marker_cmp "$MANIFEST" "$ACTUAL" 'WLOTA1A-BACKUP' 'outcome=PASS' "backup-${PHASE}"
BACKUP_PHASE
chmod 700 /root/wlota1a/helpers/backup-phase.sh
REMOTE
```

`run_delta`, `run_reseed`, and `run_censuses` temporarily disable errexit around each tenant command, capture its complete combined output and numeric status in tenant-specific files, continue through the complete immutable manifest, and only then aggregate every raw matching prefix across all tenant logs. The host wrappers copy the whole output directory—including raw aggregate, every tenant log, and every status file—out of the current API container before `validate_tenant_runner_evidence` or `validate_census_evidence` checks status, tenant banners, bad output, and exact command summaries. Only after those host validations do callers run the committed-template `assert_marker_cmp` or `assert_census_cmp`; no expected-tenant grep can suppress an unexpected raw marker.

### 11.3 SHA recording, manifest transfer, backups, and reusable invocation

Immediately after each promoted push, run the matching complete two-line pair; this assigns the local variable and persists it on the host. Later host blocks always `source /root/wlota1a/shas/push-shas.env`.

```bash
PUSH1_SHA="$(git rev-parse HEAD)"; ssh root@157.180.71.252 "/root/wlota1a/helpers/record-sha.sh 1 '$PUSH1_SHA'"
PUSH2_SHA="$(git rev-parse HEAD)"; ssh root@157.180.71.252 "/root/wlota1a/helpers/record-sha.sh 2 '$PUSH2_SHA'"
PUSH3_SHA="$(git rev-parse HEAD)"; ssh root@157.180.71.252 "/root/wlota1a/helpers/record-sha.sh 3 '$PUSH3_SHA'"
PUSH4_SHA="$(git rev-parse HEAD)"; ssh root@157.180.71.252 "/root/wlota1a/helpers/record-sha.sh 4 '$PUSH4_SHA'"
PUSH5_SHA="$(git rev-parse HEAD)"; ssh root@157.180.71.252 "/root/wlota1a/helpers/record-sha.sh 5 '$PUSH5_SHA'"
```

Run each matching line only at that push's promotion. For Push 5, §11.8 performs the same assignment and host write inside the continuous before/promotion/after web shell; do not invoke the standalone Push-5 line a second time.

At the matching rollout boundary, invoke the reusable phase helper with one of these exact commands. The helper requires, validates, and consumes `PHASE`; it copies the helper into the current API, captures both the UUID manifest and canonical database mapping there, copies the whole manifest directory back, and requires non-empty immutable `expected-tenants.txt` and `tenant-databases.tsv` files. `validate_tenant_database_mapping` fails through the durable `fail_gate` unless both files have the same count and UUID set and every mapping row has exactly two non-empty tab-separated columns:

```bash
PHASE=p1 /root/wlota1a/helpers/capture-manifest-phase.sh
PHASE=p2 /root/wlota1a/helpers/capture-manifest-phase.sh
PHASE=p4 /root/wlota1a/helpers/capture-manifest-phase.sh
PHASE=preactivation /root/wlota1a/helpers/capture-manifest-phase.sh
PHASE=postactivation /root/wlota1a/helpers/capture-manifest-phase.sh
```

Run only the matching manifest line at that phase; running future lines early is forbidden. Before Pushes 2 and 4, invoke the durable `backup-phase.sh` installed verbatim in §11.2. It requires and consumes `PHASE`, performs one non-zero host backup per immutable-manifest UUID, materializes its committed one-line heredoc template with the literal `outcome=PASS` tail, and uses `cmp` so a missing, duplicate, or unexpected record exits non-zero. Exact invocations, each in its own host shell at the stated boundary:

```bash
PHASE=p2 bash /root/wlota1a/helpers/backup-phase.sh
PHASE=p4 bash /root/wlota1a/helpers/backup-phase.sh
```

### 11.4 Push 1 census and source-push boot-seed invariant

Run the `p1` manifest capture and this host block. The sourced function explicitly copies the manifest/helper into the current API, re-sources the helper there, and copies the complete output directory back before validation:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p1/expected-tenants.txt"
run_censuses_on_current_api "$MANIFEST" 'p1-census'
assert_census_cmp "$MANIFEST" "$ROOT/evidence/p1-census/census.markers.txt" 'p1-census'
```

`tenants:run` discards child exit status at [Run.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:54), so the helper also requires every exact child verdict.

<a id="r5-boot-sync"></a>
The staging API setting is truthy, not off. During operational Push 1, run this self-contained INITIAL-OFF workstation block. It defines every dependency, snapshots the exact immutable `p1` fleet before the API boot seed, mutates the full current environment independently for each application, performs `application.one` readback, redeploys sequentially worker → API → scheduler, verifies a replacement container and `printenv` after each redeploy, then snapshots the same fleet and requires byte identity before continuing:

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'
DOKPLOY_URL='https://app.dokploy.com'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"

ssh "$STAGING_HOST" 'bash -s' <<'INITIAL_OFF_BEFORE'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/p1/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p1/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'initial-off.before'
INITIAL_OFF_BEFORE

update_and_redeploy() {
  role="$1"; application_id="$2"; expected_name="$3"; desired="$4"
  before="$WORK/${role}.before.json"; env_file="$WORK/${role}.env"
  payload="$WORK/${role}.payload.json"; after="$WORK/${role}.after.json"
  old_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
  test "$(printf '%s\n' "$old_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$before"
  test "$(jq -er '.applicationId' "$before")" = "$application_id"
  test "$(jq -er '.environmentId' "$before")" = 'Ree8z_4ixu7vAgBLdI_A_'
  test "$(jq -er '.appName' "$before")" = "$expected_name"
  jq -r '.env' "$before" | awk -F= '$1 != "LOT_ACTION_PERMISSIONS_ENFORCE"' > "$env_file"
  printf 'LOT_ACTION_PERMISSIONS_ENFORCE=%s\n' "$desired" >> "$env_file"
  test "$(grep -Ec '^LOT_ACTION_PERMISSIONS_ENFORCE=' "$env_file")" -eq 1
  jq -nc --arg applicationId "$application_id" --rawfile env "$env_file" \
    '{json:{applicationId:$applicationId,env:$env}}' > "$payload"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/trpc/application.update" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' --data-binary "@$payload" >/dev/null
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$after"
  test "$(jq -er '.applicationId' "$after")" = "$application_id"
  test "$(jq -er '.appName' "$after")" = "$expected_name"
  test "$(jq -r '.env' "$after" | grep -Fxc "LOT_ACTION_PERMISSIONS_ENFORCE=${desired}")" -eq 1
  scp "$after" "${STAGING_HOST}:/root/wlota1a/applications/${role}-initial-off.json"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
    --data "$(jq -nc --arg applicationId "$application_id" --arg title "WLOTA1A-${role}-initial-off-$(date +%s)" \
      '{applicationId:$applicationId,title:$title,description:"W-LOT-A-1a initial OFF"}')" >/dev/null
  new_container=''
  for attempt in $(seq 1 90); do
    new_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
    if test "$(printf '%s\n' "$new_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1 \
      && test "$new_container" != "$old_container" \
      && test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"; then
      break
    fi
    sleep 5
  done
  test -n "$new_container"; test "$new_container" != "$old_container"
  test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"
}

update_and_redeploy worker 'KKYDsAvk4UpYfJXVmsDj2' 'erp-staging-worker-p2yjl1' false
update_and_redeploy api 'x5wfthp8-7cVbiUfI6Hq7' 'erp-staging-api-kghqex' false
update_and_redeploy scheduler 'HSXqHvmo_vq7NAYIjrE3T' 'erp-staging-scheduler-iqjxrv' false

ssh "$STAGING_HOST" 'bash -s' <<'INITIAL_OFF_AFTER'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/p1/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p1/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'initial-off.after'
cmp -s "$ROOT/snapshots/initial-off.before.txt" "$ROOT/snapshots/initial-off.after.txt" \
  || fail_gate 'initial_off_api_boot_seed_changed_permissions'
ACTUAL="$ROOT/evidence/initial-off.actual.txt"; EXPECTED="$ROOT/evidence/initial-off.expected.txt"; : > "$ACTUAL"
for row in 'worker erp-staging-worker-p2yjl1' 'api erp-staging-api-kghqex' 'scheduler erp-staging-scheduler-iqjxrv'; do
  set -- $row; container="$(resolve_container "$2")"
  value="$(docker exec "$container" printenv LOT_ACTION_PERMISSIONS_ENFORCE)"; test "$value" = 'false'
  printf 'WLOTA1A-INITIAL-OFF service=%s expected=false actual=%s outcome=PASS\n' "$1" "$value" >> "$ACTUAL"
done
cat > "$EXPECTED" <<'EXPECTED_INITIAL_OFF'
WLOTA1A-INITIAL-OFF service=worker expected=false actual=false outcome=PASS
WLOTA1A-INITIAL-OFF service=api expected=false actual=false outcome=PASS
WLOTA1A-INITIAL-OFF service=scheduler expected=false actual=false outcome=PASS
EXPECTED_INITIAL_OFF
cmp -s "$EXPECTED" "$ACTUAL" || fail_gate 'initial_off_readback'
test "$(docker exec "$(resolve_container 'erp-staging-api-kghqex')" printenv SYNC_PERMISSIONS_ON_BOOT)" = 'true'
INITIAL_OFF_AFTER
```

The exact Push-2, Push-3, and Push-5 pre/post invocations are committed in §§11.5, 11.6, and 11.8. Each block re-sources `host-helpers.sh`, names its immutable manifest and literal label, and directly invokes `prepare_source_push` or `verify_source_push_inert`; no placeholder invocation is authorized. Those blocks prove replacement API identity, `SYNC_PERMISSIONS_ON_BOOT=true`, `LOT_ACTION_PERMISSIONS_ENFORCE=false`, and byte-identical permissions/roles/grants. The explicit Push-4 delta is the only permitted existing-tenant difference.

### 11.5 Push 2 migration — fixed strings and committed literal comparison

Immediately before Push 2, run this exact host command after the `p2` backup:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p2/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p2/tenant-databases.tsv"
prepare_source_push 'p2' "$MANIFEST" "$MAPPING"
```

Immediately after Push 2 has replaced the API and `PUSH2_SHA` is persisted, run this complete host block. It performs the inertness check, literal copy-in of both required files, both migration passes in the current API, literal copy-back, and committed-template comparisons for both aggregate marker sets:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p2/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p2/tenant-databases.tsv"
verify_source_push_inert 'p2' "$MANIFEST" "$MAPPING"
API="$(resolve_container 'erp-staging-api-kghqex')"
docker cp "$ROOT/helpers/fail-gate.sh" "$API:/tmp/wlota1a-fail-gate.sh"
docker cp "$ROOT/helpers/container-helpers.sh" "$API:/tmp/wlota1a-container-helpers.sh"
docker cp "$MANIFEST" "$API:/tmp/wlota1a-p2-expected-tenants.txt"
docker exec --workdir /var/www/html "$API" bash -s <<'MIGRATION_P2'
set -Eeuo pipefail
source /tmp/wlota1a-container-helpers.sh
EXPECTED_TENANTS='/tmp/wlota1a-p2-expected-tenants.txt'; test -s "$EXPECTED_TENANTS"
OUT='/tmp/wlota1a-migration-p2'; rm -rf "$OUT"; mkdir -p "$OUT"
for pass in 1 2; do
  aggregate="$OUT/pass${pass}.markers.txt"; : > "$aggregate"
  while IFS= read -r tenant_id; do
    tenant_log="$OUT/pass${pass}-${tenant_id}.log"
    status=0
    set +e
    php artisan tenants:migrate-rolling --force --tenant="$tenant_id" --no-ansi > "$tenant_log" 2>&1
    status=$?
    set -e
    printf '%s\n' "$status" > "$OUT/pass${pass}-${tenant_id}.status"
    test "$status" -ne 0 \
      || printf 'WLOTA1A-MIGRATION-P2-PASS%s tenant=%s outcome=PASS\n' "$pass" "$tenant_id" >> "$tenant_log"
    cat "$tenant_log"
  done < "$EXPECTED_TENANTS"
  grep -h "^WLOTA1A-MIGRATION-P2-PASS${pass} tenant=" "$OUT"/pass${pass}-*.log > "$aggregate" || true
done
MIGRATION_P2
rm -rf "$ROOT/evidence/migration-p2"
docker cp "$API:/tmp/wlota1a-migration-p2" "$ROOT/evidence/migration-p2"
for pass in 1 2; do
  while IFS= read -r tenant_id; do
    tenant_log="$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.log"
    status="$(cat "$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.status")"
    test "$status" -eq 0 || fail_gate "migration_status:pass${pass}:${tenant_id}:${status}"
    reject_bad_output "$tenant_log"
    test "$(grep -Fxc 'Database-per-tenant mode is OFF' "$tenant_log" || true)" -eq 0
    test "$(grep -Fxc 'No tenants to migrate.' "$tenant_log" || true)" -eq 0
    test "$(grep -Fc 'No tenant found with id' "$tenant_log" || true)" -eq 0
    test "$(grep -Fxc 'Rolling tenant migrations across 1 tenant(s).' "$tenant_log" || true)" -eq 1
    test "$(grep -Ec '^→ ' "$tenant_log" || true)" -eq 1
    test "$(grep '^→ ' "$tenant_log" | grep -Fc "$tenant_id" || true)" -eq 1
    test "$(grep -Fxc 'Done. 1 tenant(s) migrated, 0 failed.' "$tenant_log" || true)" -eq 1
    cat > "$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.expected.txt" <<'EXPECTED_MIGRATION_STDOUT'
Rolling tenant migrations across 1 tenant(s).
Done. 1 tenant(s) migrated, 0 failed.
EXPECTED_MIGRATION_STDOUT
    {
      grep -Fx 'Rolling tenant migrations across 1 tenant(s).' "$tenant_log"
      grep -Fx 'Done. 1 tenant(s) migrated, 0 failed.' "$tenant_log"
    } > "$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.actual.txt"
    cmp -s "$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.expected.txt" \
      "$ROOT/evidence/migration-p2/pass${pass}-${tenant_id}.actual.txt" \
      || fail_gate "migration_literal_output:pass${pass}:${tenant_id}"
  done < "$MANIFEST"
done
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/migration-p2/pass1.markers.txt" \
  'WLOTA1A-MIGRATION-P2-PASS1' 'outcome=PASS' 'migration-p2-pass1'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/migration-p2/pass2.markers.txt" \
  'WLOTA1A-MIGRATION-P2-PASS2' 'outcome=PASS' 'migration-p2-pass2'
```

The valid literals are emitted at [RollingTenantMigrationCommand.php:73](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73) and [RollingTenantMigrationCommand.php:163](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:163). The PostgreSQL and SQLite schema commands are literal in §13; either non-zero exit blocks Push 3.

### 11.6 Push 3 flag-off, boot seed, and disposable legacy gate

Immediately before Push 3, execute:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p2/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p2/tenant-databases.tsv"
prepare_source_push 'p3' "$MANIFEST" "$MAPPING"
```

Immediately after Push 3 has replaced the API and `PUSH3_SHA` is persisted, execute:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p2/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p2/tenant-databases.tsv"
verify_source_push_inert 'p3' "$MANIFEST" "$MAPPING"
```

Then run this host readback; it proves API boot sync remains true while enforcement is OFF in all three separate applications.

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
ACTUAL="$ROOT/evidence/p3-flag-off.actual.txt"; EXPECTED="$ROOT/evidence/p3-flag-off.expected.txt"; : > "$ACTUAL"
for row in 'worker erp-staging-worker-p2yjl1' 'api erp-staging-api-kghqex' 'scheduler erp-staging-scheduler-iqjxrv'; do
  set -- $row; container="$(resolve_container "$2")"
  value="$(docker exec "$container" printenv LOT_ACTION_PERMISSIONS_ENFORCE)"; test "$value" = 'false'
  printf 'WLOTA1A-CONFIG service=%s expected=false actual=%s outcome=PASS\n' "$1" "$value" >> "$ACTUAL"
done
test "$(docker exec "$(resolve_container 'erp-staging-api-kghqex')" printenv SYNC_PERMISSIONS_ON_BOOT)" = 'true'
cat > "$EXPECTED" <<'EXPECTED_FLAG_OFF'
WLOTA1A-CONFIG service=worker expected=false actual=false outcome=PASS
WLOTA1A-CONFIG service=api expected=false actual=false outcome=PASS
WLOTA1A-CONFIG service=scheduler expected=false actual=false outcome=PASS
EXPECTED_FLAG_OFF
cmp -s "$EXPECTED" "$ACTUAL" || fail_gate 'p3_flag_off_readback'
```

Create the named disposable legacy tenant with the real public route declared at [Identity routes.php:26](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/routes.php:26) through line 28 and the exact required JSON fields from [RegisterRequest.php:39](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Requests/RegisterRequest.php:39) through line 75. The response exposes the tenant UUID at `data.user.tenantId`: `AuthController` returns the DTO envelope at [AuthController.php:554](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:554) through line 560, and `AuthUserData` maps `tenantId` from the persisted tenant ID at [AuthUserData.php:45](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:45) through line 48. Run this exact staging-host producer; it captures the response on the host, requires HTTP 201, validates the response with `jq`, extracts exactly one UUID, persists the one-line manifest under `/root/wlota1a/`, and transfers it into the current API with an explicit `docker cp` plus byte comparison:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
install -d -m 700 "$ROOT/evidence/registration-responses"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
EMAIL="wlota1a-legacy-${STAMP}@example.invalid"
RESPONSE="$ROOT/evidence/registration-responses/p3-disposable.json"
MANIFEST="$ROOT/manifests/p3-disposable.txt"
PAYLOAD="$ROOT/evidence/registration-responses/p3-disposable.request.json"
jq -nc --arg email "$EMAIL" '{
  name:"WLOTA1A Legacy Disposable",
  email:$email,
  password:"Wlota1a!Legacy2026",
  password_confirmation:"Wlota1a!Legacy2026",
  company_name:"WLOTA1A Legacy Disposable",
  country_code:"TN",
  vertical:"parapharmacy"
}' > "$PAYLOAD"
STATUS="$(curl --silent --show-error --output "$RESPONSE" --write-out '%{http_code}' \
  --request POST 'https://api.erp.otospex.dev/api/v1/auth/register' \
  --header 'accept: application/json' --header 'content-type: application/json' \
  --data-binary "@$PAYLOAD")"
test "$STATUS" = '201' || fail_gate "p3_disposable_registration_http_${STATUS}"
jq -e '.data.user.tenantId and (.data.user.tenantId | type == "string")' "$RESPONSE" >/dev/null \
  || fail_gate 'p3_disposable_registration_response_contract'
jq -er --arg uuid_re "^${UUID_RE}$" \
  '[.data.user.tenantId] | select(length == 1 and (.[0] | test($uuid_re))) | .[0]' \
  "$RESPONSE" > "$MANIFEST" || fail_gate 'p3_disposable_uuid_extraction'
test "$(line_count "$MANIFEST")" -eq 1 || fail_gate 'p3_disposable_uuid_count'
API="$(resolve_container 'erp-staging-api-kghqex')"
docker cp "$MANIFEST" "$API:/tmp/wlota1a-p3-disposable.txt"
docker exec "$API" cat /tmp/wlota1a-p3-disposable.txt > "$ROOT/evidence/p3-disposable.container.txt"
cmp -s "$MANIFEST" "$ROOT/evidence/p3-disposable.container.txt" || fail_gate 'p3_disposable_manifest_transfer'
printf '%s\n' 'WLOTA1A-REGISTRATION name=p3-disposable http=201 response=valid tenant_count=1 transfer=match outcome=PASS' \
  > "$ROOT/evidence/p3-disposable-registration.actual.txt"
cat > "$ROOT/evidence/p3-disposable-registration.expected.txt" <<'EXPECTED_P3_DISPOSABLE_REGISTRATION'
WLOTA1A-REGISTRATION name=p3-disposable http=201 response=valid tenant_count=1 transfer=match outcome=PASS
EXPECTED_P3_DISPOSABLE_REGISTRATION
cmp -s "$ROOT/evidence/p3-disposable-registration.expected.txt" \
  "$ROOT/evidence/p3-disposable-registration.actual.txt" || fail_gate 'p3_disposable_registration_record'
```

Then run:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p3-disposable.txt"
test "$(line_count "$MANIFEST")" -eq 1; grep -Eq "^${UUID_RE}$" "$MANIFEST"
run_reseed_on_current_api "$MANIFEST" 'p3-disposable-reseed'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/p3-disposable-reseed/markers.txt" \
  'WLOTA1A-RESEED' 'mode=LEGACY outcome=ALREADY_APPLIED reason=enforcement_off' 'p3-disposable-reseed'
```

The sourced helper explicitly copies the manifest and helper into the current API, re-sources it, runs `tenants:seed`, aggregates every raw `^WLOTA1A-RESEED tenant=` line before validation, and copies the evidence to the host. No explicit fleet-wide generic reseed is authorized; the unavoidable boot seed has already been proven inert by snapshot `cmp`.

### 11.7 Push 4 delta, exact markers, cache, and censuses

Capture `p4` and take its backup with these exact host commands:

```bash
PHASE=p4 /root/wlota1a/helpers/capture-manifest-phase.sh
PHASE=p4 /root/wlota1a/helpers/backup-phase.sh
```

Then run the exact host-managed delta/census sequence. Each sourced runner performs the explicit `docker cp`/container `source`/copy-back cycle; `assert_marker_cmp` and `assert_census_cmp` build expected files from committed literal heredoc templates plus the immutable `p4` manifest and exit non-zero on any `cmp` mismatch:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p4/expected-tenants.txt"
MAPPING="$ROOT/manifests/p4/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'p4.before-delta'
run_delta_on_current_api "$MANIFEST" 'apply' 'p4-apply'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/p4-apply/markers.txt" \
  'WLOTA1A-PERMISSIONS' 'mode=APPLY outcome=APPLIED reason=canonical_delta_applied' 'p4-apply'
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'p4.after-delta'
if diff -q "$ROOT/snapshots/p4.before-delta.txt" "$ROOT/snapshots/p4.after-delta.txt" >/dev/null; then
  fail_gate 'push4_delta_changed_nothing'
fi
run_delta_on_current_api "$MANIFEST" 'verify' 'p4-verify'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/p4-verify/markers.txt" \
  'WLOTA1A-PERMISSIONS' 'mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches' 'p4-verify'
run_censuses_on_current_api "$MANIFEST" 'p4-census'
assert_census_cmp "$MANIFEST" "$ROOT/evidence/p4-census/census.markers.txt" 'p4-census'
```

Reset and verify the permission cache with this complete current-container block. `permission:cache-reset` emits `Permission cache flushed.` at [CacheReset.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:20); the command exit, exact-line count, bad-output scan, literal heredoc, and `cmp` all fail closed. Push 4 performs no generic seed.

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
API="$(resolve_container 'erp-staging-api-kghqex')"
LOG="$ROOT/evidence/p4-permission-cache.log"
ACTUAL="$ROOT/evidence/p4-permission-cache.actual.txt"
EXPECTED="$ROOT/evidence/p4-permission-cache.expected.txt"
docker exec --workdir /var/www/html "$API" php artisan permission:cache-reset --no-ansi 2>&1 | tee "$LOG"
reject_bad_output "$LOG"
test "$(grep -Foc 'Permission cache flushed.' "$LOG" || true)" -eq 1
grep -Fo 'Permission cache flushed.' "$LOG" > "$ACTUAL"
cat > "$EXPECTED" <<'EXPECTED_PERMISSION_CACHE'
Permission cache flushed.
EXPECTED_PERMISSION_CACHE
cmp -s "$EXPECTED" "$ACTUAL" || fail_gate 'p4_permission_cache'
```

### 11.8 Push 5 permission-map, boot-seed, web SHA, and self-contained browser evidence

Before Push 5, run the complete two-generation map gate:

```bash
set -Eeuo pipefail
export LC_ALL=C
FAIL_GATE='/tmp/wlota1a-permissions-map-fail-gate.sh'
scp root@157.180.71.252:/root/wlota1a/helpers/fail-gate.sh "$FAIL_GATE"
source "$FAIL_GATE"
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
FIRST='/tmp/wlota1a-permissions-map-first.ts'; SECOND='/tmp/wlota1a-permissions-map-second.ts'
COMMITTED='../web/src/hooks/permissionsMap.generated.ts'
LOG1='/tmp/wlota1a-permissions-map-first.log'; LOG2='/tmp/wlota1a-permissions-map-second.log'
php artisan permissions:export-frontend-map --path="$FIRST" --no-ansi 2>&1 | tee "$LOG1"
test "$(grep -Fxc "Exported frontend permission map to ${FIRST}." "$LOG1" || true)" -eq 1
test "$(grep -Ec 'FAILED|SKIPPED|ERRORED' "$LOG1" || true)" -eq 0; test -s "$FIRST"
php artisan permissions:export-frontend-map --path="$SECOND" --no-ansi 2>&1 | tee "$LOG2"
test "$(grep -Fxc "Exported frontend permission map to ${SECOND}." "$LOG2" || true)" -eq 1
test "$(grep -Ec 'FAILED|SKIPPED|ERRORED' "$LOG2" || true)" -eq 0; test -s "$SECOND"
cmp -s "$FIRST" "$SECOND" || fail_gate 'permission_map_second_generation'
cmp -s "$FIRST" "$COMMITTED" || fail_gate 'permission_map_committed_artifact'
sha256sum "$FIRST" > /tmp/wlota1a-permissions-map.sha256
scp "$LOG1" "$LOG2" /tmp/wlota1a-permissions-map.sha256 \
  root@157.180.71.252:/root/wlota1a/evidence/
```

Immediately before Push 5, execute the exact pre-push invocation:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p4/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p4/tenant-databases.tsv"
prepare_source_push 'p5' "$MANIFEST" "$MAPPING"
```

Run web before/promotion/deploy/after in one continuous workstation shell. All paths and variables are defined here, and this block itself persists `PUSH5_SHA` at the moment Push 5 is promoted.

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'; DOKPLOY_URL='https://app.dokploy.com'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
scp "$STAGING_HOST:/root/wlota1a/helpers/fail-gate.sh" "$WORK/fail-gate.sh"
source "$WORK/fail-gate.sh"
cd /Users/houssamr/Projects/syneriva/apps/erp
CANDIDATE_SHA="$(git rev-parse HEAD)"; PUSH5_SHA="$CANDIDATE_SHA"
printf '%s' "$CANDIDATE_SHA" | grep -Eq '^[0-9a-f]{40}$'
BEFORE="$WORK/web-before.txt"; AFTER="$WORK/web-after.txt"; FINGERPRINT_JSON="$WORK/build-fingerprint.json"
curl --fail --silent --show-error https://erp.otospex.dev/ | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u > "$BEFORE"
test "$(wc -l < "$BEFORE" | tr -d ' ')" -eq 1
AUTO_APPLICATION="$WORK/web-auto-application.json"
AUTO_BASELINE="$WORK/web-deployment-ids.before.json"
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$AUTO_APPLICATION"
jq -e '[.deployments[]?.deploymentId | select(type == "string" and length > 0)] | sort | unique' \
  "$AUTO_APPLICATION" > "$AUTO_BASELINE"
scp "$AUTO_BASELINE" "$STAGING_HOST:/root/wlota1a/evidence/web-deployment-ids.before.json"
PUSH5_PUSHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
git push origin "${PUSH5_SHA}:refs/heads/dev"
test "$(git ls-remote origin refs/heads/dev | awk '{print $1}')" = "$PUSH5_SHA"
ssh "$STAGING_HOST" "/root/wlota1a/helpers/record-sha.sh 5 '$PUSH5_SHA'"
printf '%s\n' "$PUSH5_PUSHED_AT" > "$WORK/web-push5-started-at.txt"
AUTO_SELECTED="$WORK/web-auto-deployment.json"
AUTO_CURRENT="$WORK/web-auto-deployment.current.json"
for attempt in $(seq 1 120); do
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$AUTO_APPLICATION"
  jq -e --arg description "Commit: ${PUSH5_SHA}" --arg pushed_at "$PUSH5_PUSHED_AT" \
    --slurpfile baseline "$AUTO_BASELINE" '
    def epoch: sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601;
    ($baseline[0] // []) as $known
    | [.deployments[]
       | . as $deployment
       | select(.description == $description
           and (($known | index($deployment.deploymentId)) == null)
           and ((.createdAt | epoch) >= ($pushed_at | epoch)))]
    | sort_by(.createdAt) | first
    | select(.deploymentId != null)
  ' "$AUTO_APPLICATION" > "$AUTO_SELECTED" && break
  sleep 5
done
test -s "$AUTO_SELECTED" || fail_gate 'push5_auto_deployment_not_correlated_before_timeout'
AUTO_DEPLOYMENT_ID="$(jq -er '.deploymentId' "$AUTO_SELECTED")"
for attempt in $(seq 1 120); do
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$AUTO_APPLICATION"
  jq -e --arg deployment_id "$AUTO_DEPLOYMENT_ID" \
    '[.deployments[] | select(.deploymentId == $deployment_id)] | select(length == 1) | .[0]' \
    "$AUTO_APPLICATION" > "$AUTO_CURRENT" || fail_gate 'push5_auto_deployment_id_missing_or_duplicate'
  status="$(jq -er '.status' "$AUTO_CURRENT")"
  case "$status" in error|errored|failed|cancelled|canceled) fail_gate "push5_auto_deployment_terminal_status:${status}" ;; esac
  if test "$status" = 'done'; then cp "$AUTO_CURRENT" "$AUTO_SELECTED"; break; fi
  sleep 5
done
test "$(jq -er '.status' "$AUTO_SELECTED")" = 'done' || fail_gate 'push5_auto_deployment_done_timeout'
AUTO_ACTUAL="$WORK/web-auto-deployment.actual.tsv"
AUTO_EXPECTED="$WORK/web-auto-deployment.expected.tsv"
AUTO_TEMPLATE="$WORK/web-auto-deployment.template.tsv"
jq -er '[.deploymentId,.description,.status] | @tsv' "$AUTO_SELECTED" > "$AUTO_ACTUAL"
cat > "$AUTO_TEMPLATE" <<'EXPECTED_AUTO_DEPLOYMENT_TEMPLATE'
__DEPLOYMENT_ID__	Commit: __PUSH5_SHA__	done
EXPECTED_AUTO_DEPLOYMENT_TEMPLATE
sed -e "s/__DEPLOYMENT_ID__/${AUTO_DEPLOYMENT_ID}/g" -e "s/__PUSH5_SHA__/${PUSH5_SHA}/g" \
  "$AUTO_TEMPLATE" > "$AUTO_EXPECTED"
cmp -s "$AUTO_EXPECTED" "$AUTO_ACTUAL" || fail_gate 'push5_auto_deployment_record'
scp "$AUTO_SELECTED" "$AUTO_ACTUAL" "$AUTO_EXPECTED" "$AUTO_TEMPLATE" "$AUTO_BASELINE" \
  "$WORK/web-push5-started-at.txt" \
  "$STAGING_HOST:/root/wlota1a/evidence/"
ssh "$STAGING_HOST" 'bash -s' <<'PUSH5_INERTNESS'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/p4/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p4/tenant-databases.tsv"
verify_source_push_inert 'p5' "$MANIFEST" "$MAPPING"
assert_preserved_post_delta_snapshot "$MANIFEST" "$MAPPING" 'p5-preserved-post-delta'
PUSH5_INERTNESS
for attempt in $(seq 1 90); do
  curl --fail --silent --show-error https://erp.otospex.dev/ | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u > "$AFTER"
  test "$(wc -l < "$AFTER" | tr -d ' ')" -eq 1
  diff -q "$BEFORE" "$AFTER" >/dev/null || break
  sleep 5
done
if diff -q "$BEFORE" "$AFTER" >/dev/null; then fail_gate 'push5_served_asset_unchanged'; fi
NEW_ASSET="$(head -n 1 "$AFTER")"
test "$(curl --fail --silent --show-error "https://erp.otospex.dev${NEW_ASSET}" | grep -Fc 'wlota1a-batch-permission-gating-v1' || true)" -ge 1
curl --fail --silent --show-error https://erp.otospex.dev/build-fingerprint.json > "$FINGERPRINT_JSON"
export CANDIDATE_SHA
jq -e '.build_sha == env.CANDIDATE_SHA and .build_sha != "unknown"' "$FINGERPRINT_JSON" >/dev/null
EXPECTED_FEATURE_FINGERPRINT="$(node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint)"
test -n "$EXPECTED_FEATURE_FINGERPRINT"; export EXPECTED_FEATURE_FINGERPRINT
jq -e '.feature_fingerprint == env.EXPECTED_FEATURE_FINGERPRINT' "$FINGERPRINT_JSON" >/dev/null
printf '%s\n' 'WLOTA1A-WEB-EVIDENCE asset_changed=yes bundle_fingerprint=present build_sha=match feature_fingerprint=match outcome=PASS' \
  > "$WORK/web-served-evidence.actual.txt"
cat > "$WORK/web-served-evidence.expected.txt" <<'EXPECTED_WEB_SERVED_EVIDENCE'
WLOTA1A-WEB-EVIDENCE asset_changed=yes bundle_fingerprint=present build_sha=match feature_fingerprint=match outcome=PASS
EXPECTED_WEB_SERVED_EVIDENCE
cmp -s "$WORK/web-served-evidence.expected.txt" "$WORK/web-served-evidence.actual.txt" \
  || fail_gate 'push5_served_asset_fingerprint_record'
scp "$BEFORE" "$AFTER" "$FINGERPRINT_JSON" "$WORK/web-served-evidence.actual.txt" \
  "$WORK/web-served-evidence.expected.txt" "$STAGING_HOST:/root/wlota1a/evidence/"
```

The normal path contains no manual web redeploy: web `autoDeploy=true` is the resolved staging fact at [U-1 resolution:17](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:17). The live record shape is `description="Commit: <40-hex sha>"`, and `deployments[]` is unordered, as observed at [U-1 resolution:34](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:34) through line 38. Before the push, the block persists the complete existing `deploymentId` set to `/root/wlota1a/evidence/web-deployment-ids.before.json`; after the push, `description` correlation plus exclusion from that baseline is primary, and `createdAt >= PUSH5_PUSHED_AT` is only an inclusive whole-second secondary bound. A manual redeploy is permitted only as an incident fallback when the preceding automatic-deployment file already proves that same baseline-exclusion correlation and `status=done`, but the served asset remains stale. Record the incident ID and run this separate block; it records and persists a fresh pre-redeploy ID baseline before the redeploy and applies the identical description, not-in-baseline, inclusive-bound, pinned-ID, status-poll, and committed-record comparison before returning:

```bash
set -Eeuo pipefail
export LC_ALL=C
INCIDENT_ID="${INCIDENT_ID:?record the stale-edge incident ID}"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'; DOKPLOY_URL='https://app.dokploy.com'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
scp "$STAGING_HOST:/root/wlota1a/helpers/fail-gate.sh" "$WORK/fail-gate.sh"
source "$WORK/fail-gate.sh"
scp "$STAGING_HOST:/root/wlota1a/evidence/web-auto-deployment.json" "$WORK/automatic.json"
scp "$STAGING_HOST:/root/wlota1a/evidence/web-deployment-ids.before.json" "$WORK/automatic-baseline.json"
scp "$STAGING_HOST:/root/wlota1a/evidence/web-push5-started-at.txt" "$WORK/push5-started-at.txt"
scp "$STAGING_HOST:/root/wlota1a/shas/push-shas.env" "$WORK/push-shas.env"
source "$WORK/push-shas.env"; printf '%s' "$PUSH5_SHA" | grep -Eq '^[0-9a-f]{40}$'
jq -e --arg description "Commit: ${PUSH5_SHA}" --arg pushed_at "$(cat "$WORK/push5-started-at.txt")" \
  --slurpfile baseline "$WORK/automatic-baseline.json" '
  def epoch: sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601;
  ($baseline[0] // []) as $known
  | . as $deployment
  | .status == "done" and .description == $description
    and (.deploymentId | type == "string" and length > 0)
    and (($known | index($deployment.deploymentId)) == null)
    and ((.createdAt | epoch) >= ($pushed_at | epoch))
' "$WORK/automatic.json" >/dev/null || fail_gate 'fallback_requires_correlated_auto_completion'
BEFORE="$WORK/fallback-before.txt"; AFTER="$WORK/fallback-after.txt"
curl --fail --silent --show-error https://erp.otospex.dev/ \
  | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u > "$BEFORE"
test "$(wc -l < "$BEFORE" | tr -d ' ')" -eq 1
FALLBACK_TITLE="WLOTA1A-WEB-FALLBACK-${INCIDENT_ID}-$(date +%s)"
FALLBACK_APPLICATION="$WORK/fallback-application.json"
FALLBACK_BASELINE="$WORK/fallback-deployment-ids.before.json"
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$FALLBACK_APPLICATION"
jq -e '[.deployments[]?.deploymentId | select(type == "string" and length > 0)] | sort | unique' \
  "$FALLBACK_APPLICATION" > "$FALLBACK_BASELINE"
scp "$FALLBACK_BASELINE" "$STAGING_HOST:/root/wlota1a/evidence/fallback-deployment-ids.before.json"
FALLBACK_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
  --data "$(jq -nc --arg applicationId 'mY6P_PHb4pw-2LdG1Y7Ml' \
    --arg title "$FALLBACK_TITLE" --arg description "Commit: ${PUSH5_SHA}" \
    '{applicationId:$applicationId,title:$title,description:$description}')" >/dev/null
FALLBACK_SELECTED="$WORK/fallback.json"
for attempt in $(seq 1 120); do
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$FALLBACK_APPLICATION"
  jq -e --arg description "Commit: ${PUSH5_SHA}" --arg started_at "$FALLBACK_STARTED_AT" \
    --slurpfile baseline "$FALLBACK_BASELINE" '
    def epoch: sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601;
    ($baseline[0] // []) as $known
    | [.deployments[]
       | . as $deployment
       | select(.description == $description
           and (($known | index($deployment.deploymentId)) == null)
           and ((.createdAt | epoch) >= ($started_at | epoch)))]
    | sort_by(.createdAt) | first | select(.deploymentId != null)
  ' "$FALLBACK_APPLICATION" > "$FALLBACK_SELECTED" && break
  sleep 5
done
test -s "$FALLBACK_SELECTED" || fail_gate 'fallback_deployment_not_correlated_before_timeout'
FALLBACK_DEPLOYMENT_ID="$(jq -er '.deploymentId' "$FALLBACK_SELECTED")"
for attempt in $(seq 1 120); do
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml" > "$FALLBACK_APPLICATION"
  jq -e --arg deployment_id "$FALLBACK_DEPLOYMENT_ID" \
    '[.deployments[] | select(.deploymentId == $deployment_id)] | select(length == 1) | .[0]' \
    "$FALLBACK_APPLICATION" > "$FALLBACK_SELECTED" || fail_gate 'fallback_deployment_id_missing_or_duplicate'
  status="$(jq -er '.status' "$FALLBACK_SELECTED")"
  case "$status" in error|errored|failed|cancelled|canceled) fail_gate "fallback_terminal_status:${status}" ;; esac
  test "$status" != 'done' || break
  sleep 5
done
test "$(jq -er '.status' "$FALLBACK_SELECTED")" = 'done' || fail_gate 'fallback_done_timeout'
FALLBACK_ACTUAL="$WORK/fallback-deployment.actual.tsv"
FALLBACK_EXPECTED="$WORK/fallback-deployment.expected.tsv"
cat > "$WORK/fallback-deployment.template.tsv" <<'EXPECTED_FALLBACK_DEPLOYMENT_TEMPLATE'
__DEPLOYMENT_ID__	Commit: __PUSH5_SHA__	done
EXPECTED_FALLBACK_DEPLOYMENT_TEMPLATE
jq -er '[.deploymentId,.description,.status] | @tsv' "$FALLBACK_SELECTED" > "$FALLBACK_ACTUAL"
sed -e "s/__DEPLOYMENT_ID__/${FALLBACK_DEPLOYMENT_ID}/g" -e "s/__PUSH5_SHA__/${PUSH5_SHA}/g" \
  "$WORK/fallback-deployment.template.tsv" > "$FALLBACK_EXPECTED"
cmp -s "$FALLBACK_EXPECTED" "$FALLBACK_ACTUAL" || fail_gate 'fallback_deployment_record'
for attempt in $(seq 1 90); do
  curl --fail --silent --show-error https://erp.otospex.dev/ \
    | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | sort -u > "$AFTER"
  test "$(wc -l < "$AFTER" | tr -d ' ')" -eq 1
  diff -q "$BEFORE" "$AFTER" >/dev/null || break
  sleep 5
done
if diff -q "$BEFORE" "$AFTER" >/dev/null; then fail_gate 'fallback_served_asset_unchanged'; fi
NEW_ASSET="$(head -n 1 "$AFTER")"
test "$(curl --fail --silent --show-error "https://erp.otospex.dev${NEW_ASSET}" \
  | grep -Fc 'wlota1a-batch-permission-gating-v1' || true)" -ge 1
curl --fail --silent --show-error https://erp.otospex.dev/build-fingerprint.json > "$WORK/fallback-fingerprint.json"
export PUSH5_SHA
jq -e '.build_sha == env.PUSH5_SHA and .build_sha != "unknown"' "$WORK/fallback-fingerprint.json" >/dev/null
cd /Users/houssamr/Projects/syneriva/apps/erp
EXPECTED_FEATURE_FINGERPRINT="$(node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint)"
test -n "$EXPECTED_FEATURE_FINGERPRINT"; export EXPECTED_FEATURE_FINGERPRINT
jq -e '.feature_fingerprint == env.EXPECTED_FEATURE_FINGERPRINT' "$WORK/fallback-fingerprint.json" >/dev/null
printf '%s\n' 'WLOTA1A-FALLBACK-WEB-EVIDENCE asset_changed=yes bundle_fingerprint=present build_sha=match feature_fingerprint=match outcome=PASS' \
  > "$WORK/fallback-served-evidence.actual.txt"
cat > "$WORK/fallback-served-evidence.expected.txt" <<'EXPECTED_FALLBACK_SERVED_EVIDENCE'
WLOTA1A-FALLBACK-WEB-EVIDENCE asset_changed=yes bundle_fingerprint=present build_sha=match feature_fingerprint=match outcome=PASS
EXPECTED_FALLBACK_SERVED_EVIDENCE
cmp -s "$WORK/fallback-served-evidence.expected.txt" "$WORK/fallback-served-evidence.actual.txt" \
  || fail_gate 'fallback_served_asset_fingerprint_record'
scp "$FALLBACK_SELECTED" "$FALLBACK_ACTUAL" "$FALLBACK_EXPECTED" "$WORK/fallback-deployment.template.tsv" \
  "$FALLBACK_BASELINE" "$BEFORE" "$AFTER" "$WORK/fallback-fingerprint.json" \
  "$WORK/fallback-served-evidence.actual.txt" "$WORK/fallback-served-evidence.expected.txt" \
  "$STAGING_HOST:/root/wlota1a/evidence/"
```

Run only the pre-activation Playwright test while the three container readbacks remain false.

### 11.9 Rollout-window preactivation recensus

Pause public registration with Laravel's existing maintenance-mode mechanism. The API config selects the maintenance driver/store from `APP_MAINTENANCE_DRIVER` and `APP_MAINTENANCE_STORE` at [app.php:121](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/app.php:121) through line 124, and the existing Redis cache store is declared at [cache.php:75](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/cache.php:75) through line 79. `artisan down` accepts a reversible secret bypass at [DownCommand.php:25](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Console/DownCommand.php:25) through line 31; the maintenance middleware admits only that cookie while otherwise returning 503 at [PreventRequestsDuringMaintenance.php:64](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestsDuringMaintenance.php:64) through line 105. The cache-backed state survives the later API replacement, unlike the default container-local file driver.

Run this exact workstation block. It records the current API environment, snapshots the `p4` fleet, changes only the two maintenance variables through a full-env Dokploy update, redeploys the API, proves the boot seeder preserved the full post-delta snapshot plus the committed canonical expectation, enters maintenance with a durable secret, and proves an ordinary registration request receives 503:

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'; DOKPLOY_URL='https://app.dokploy.com'
APPLICATION_ID='x5wfthp8-7cVbiUfI6Hq7'; APP_NAME='erp-staging-api-kghqex'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
scp "$STAGING_HOST:/root/wlota1a/helpers/fail-gate.sh" "$WORK/fail-gate.sh"; source "$WORK/fail-gate.sh"
ssh "$STAGING_HOST" 'bash -s' <<'REGISTRATION_PAUSE_BEFORE'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/p4/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p4/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'registration-pause.before'
REGISTRATION_PAUSE_BEFORE
BEFORE="$WORK/api.before.json"; AFTER="$WORK/api.after.json"; ENV_FILE="$WORK/api.env"; PAYLOAD="$WORK/api.payload.json"
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=${APPLICATION_ID}" > "$BEFORE"
test "$(jq -er '.applicationId' "$BEFORE")" = "$APPLICATION_ID" || fail_gate 'registration_pause_application_id'
test "$(jq -er '.appName' "$BEFORE")" = "$APP_NAME" || fail_gate 'registration_pause_app_name'
scp "$BEFORE" "$STAGING_HOST:/root/wlota1a/applications/api-registration-pause.before.json"
jq -r '.env' "$BEFORE" | awk -F= '$1 != "APP_MAINTENANCE_DRIVER" && $1 != "APP_MAINTENANCE_STORE"' > "$ENV_FILE"
printf '%s\n' 'APP_MAINTENANCE_DRIVER=cache' 'APP_MAINTENANCE_STORE=redis' >> "$ENV_FILE"
test "$(grep -Fxc 'APP_MAINTENANCE_DRIVER=cache' "$ENV_FILE")" -eq 1 || fail_gate 'registration_pause_driver_env'
test "$(grep -Fxc 'APP_MAINTENANCE_STORE=redis' "$ENV_FILE")" -eq 1 || fail_gate 'registration_pause_store_env'
jq -nc --arg applicationId "$APPLICATION_ID" --rawfile env "$ENV_FILE" \
  '{json:{applicationId:$applicationId,env:$env}}' > "$PAYLOAD"
OLD_CONTAINER="$(ssh "$STAGING_HOST" "docker ps --filter name=${APP_NAME} --format '{{.ID}}'")"
curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/trpc/application.update" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' --data-binary "@$PAYLOAD" >/dev/null
curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
  --data "$(jq -nc --arg applicationId "$APPLICATION_ID" '{applicationId:$applicationId}')" >/dev/null
NEW_CONTAINER=''
for attempt in $(seq 1 90); do
  NEW_CONTAINER="$(ssh "$STAGING_HOST" "docker ps --filter name=${APP_NAME} --format '{{.ID}}'")"
  if test -n "$NEW_CONTAINER" && test "$NEW_CONTAINER" != "$OLD_CONTAINER" \
    && test "$(ssh "$STAGING_HOST" "docker exec ${NEW_CONTAINER} printenv APP_MAINTENANCE_DRIVER")" = 'cache' \
    && test "$(ssh "$STAGING_HOST" "docker exec ${NEW_CONTAINER} printenv APP_MAINTENANCE_STORE")" = 'redis'; then break; fi
  sleep 5
done
test -n "$NEW_CONTAINER" && test "$NEW_CONTAINER" != "$OLD_CONTAINER" || fail_gate 'registration_pause_api_redeploy_timeout'
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=${APPLICATION_ID}" > "$AFTER"
test "$(jq -r '.env' "$AFTER" | grep -Fxc 'APP_MAINTENANCE_DRIVER=cache')" -eq 1 || fail_gate 'registration_pause_driver_readback'
test "$(jq -r '.env' "$AFTER" | grep -Fxc 'APP_MAINTENANCE_STORE=redis')" -eq 1 || fail_gate 'registration_pause_store_readback'
scp "$AFTER" "$STAGING_HOST:/root/wlota1a/applications/api-registration-pause.after.json"
ssh "$STAGING_HOST" 'bash -s' <<'REGISTRATION_PAUSE_AFTER'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/p4/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/p4/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'registration-pause.after'
cmp -s "$ROOT/snapshots/registration-pause.before.txt" "$ROOT/snapshots/registration-pause.after.txt" \
  || fail_gate 'registration_pause_boot_seed_changed_post_delta_state'
assert_preserved_post_delta_snapshot "$MANIFEST" "$MAPPING" 'registration-pause-preserved-post-delta'
API="$(resolve_container 'erp-staging-api-kghqex')"
SECRET="$(openssl rand -hex 24)"; printf '%s\n' "$SECRET" > "$ROOT/evidence/registration-maintenance-secret.txt"
chmod 600 "$ROOT/evidence/registration-maintenance-secret.txt"
docker exec --workdir /var/www/html "$API" php artisan config:clear --no-ansi >/dev/null
docker exec --workdir /var/www/html "$API" php artisan down --secret="$SECRET" --retry=300 --no-ansi \
  > "$ROOT/evidence/registration-pause.log" 2>&1
test "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
  --request POST 'https://api.erp.otospex.dev/api/v1/auth/register' \
  --header 'accept: application/json' --header 'content-type: application/json' --data-binary '{}')" = '503' \
  || fail_gate 'registration_pause_public_request_not_503'
printf '%s\n' 'WLOTA1A-REGISTRATION-PAUSE maintenance_driver=cache maintenance_store=redis public_http=503 outcome=PASS' \
  > "$ROOT/evidence/registration-pause.actual.txt"
cat > "$ROOT/evidence/registration-pause.expected.txt" <<'EXPECTED_REGISTRATION_PAUSE'
WLOTA1A-REGISTRATION-PAUSE maintenance_driver=cache maintenance_store=redis public_http=503 outcome=PASS
EXPECTED_REGISTRATION_PAUSE
cmp -s "$ROOT/evidence/registration-pause.expected.txt" "$ROOT/evidence/registration-pause.actual.txt" \
  || fail_gate 'registration_pause_record'
REGISTRATION_PAUSE_AFTER
```

After that block exits zero, capture the host-persisted `preactivation` manifest with the literal phase invocation, then compare it with `p4` on the host. No container-local `p4` file is referenced.

```bash
PHASE=preactivation /root/wlota1a/helpers/capture-manifest-phase.sh

set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
P4="$ROOT/manifests/p4/expected-tenants.txt"; PRE="$ROOT/manifests/preactivation/expected-tenants.txt"
comm -23 "$P4" "$PRE" > "$ROOT/evidence/preactivation.missing.txt"
comm -13 "$P4" "$PRE" > "$ROOT/manifests/preactivation.new.txt"
test ! -s "$ROOT/evidence/preactivation.missing.txt"
```

Run the following complete host branch. It handles both an empty and non-empty new-tenant manifest, applies/verifies/censuses every new tenant when present, verifies/censuses the full preactivation fleet, aggregates the census helpers' complete raw `WLOTA1A-CENSUS-*` record before validation, and commits a rollout template/`cmp` even for the empty branch. Each runner performs literal helper/manifest copy-in, container `source`, and evidence copy-back through the functions defined in §11.2.

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
NEW="$ROOT/manifests/preactivation.new.txt"
PRE="$ROOT/manifests/preactivation/expected-tenants.txt"
test -f "$NEW"; test -s "$PRE"
ROLLOUT_ACTUAL="$ROOT/evidence/preactivation-rollout.actual.txt"; : > "$ROLLOUT_ACTUAL"

if test -s "$NEW"; then
  run_delta_on_current_api "$NEW" 'apply' 'preactivation-new-apply'
  assert_marker_cmp "$NEW" "$ROOT/evidence/preactivation-new-apply/markers.txt" \
    'WLOTA1A-PERMISSIONS' 'mode=APPLY outcome=APPLIED reason=canonical_delta_applied' 'preactivation-new-apply'
  run_delta_on_current_api "$NEW" 'verify' 'preactivation-new-verify'
  assert_marker_cmp "$NEW" "$ROOT/evidence/preactivation-new-verify/markers.txt" \
    'WLOTA1A-PERMISSIONS' 'mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches' 'preactivation-new-verify'
  run_censuses_on_current_api "$NEW" 'preactivation-new-census'
  assert_census_cmp "$NEW" "$ROOT/evidence/preactivation-new-census/census.markers.txt" 'preactivation-new-census'
  while IFS= read -r tenant_id; do
    printf 'WLOTA1A-ROLLOUT tenant=%s outcome=PASS\n' "$tenant_id" >> "$ROLLOUT_ACTUAL"
  done < "$NEW"
fi

assert_marker_cmp "$NEW" "$ROLLOUT_ACTUAL" 'WLOTA1A-ROLLOUT' 'outcome=PASS' 'preactivation-rollout'
run_delta_on_current_api "$PRE" 'verify' 'preactivation-full-verify'
assert_marker_cmp "$PRE" "$ROOT/evidence/preactivation-full-verify/markers.txt" \
  'WLOTA1A-PERMISSIONS' 'mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches' 'preactivation-full-verify'
run_censuses_on_current_api "$PRE" 'preactivation-full-census'
assert_census_cmp "$PRE" "$ROOT/evidence/preactivation-full-census/census.markers.txt" 'preactivation-full-census'
```

Because every source push ran the seeder while enforcement was false, this recensus is mandatory: newly registered rollout-window tenants may still need the explicit delta, while existing tenants must still satisfy the canonical verify and complete census templates.

### 11.10 Separate-application environment mutation and sequential activation

`application.saveEnvironment` is forbidden because it returns 400; direct tRPC `application.update` with the full `env` field is the working path ([deploy-runbook.md:38](/Users/houssamr/Projects/syneriva/claude/deploy-runbook.md:38) through line 40). Run this self-contained workstation block. It fetches the full record immediately before each update, replaces only the exact flag line, updates the full environment, re-reads it through `application.one`, transfers that record to the host, and redeploys sequentially worker → API → scheduler.

```bash
set -Eeuo pipefail
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
DOKPLOY_URL='https://app.dokploy.com'; STAGING_HOST='root@157.180.71.252'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
update_and_redeploy() {
  role="$1"; application_id="$2"; expected_name="$3"; desired="$4"
  before="$WORK/${role}.before.json"; env_file="$WORK/${role}.env"; payload="$WORK/${role}.payload.json"; after="$WORK/${role}.after.json"
  old_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
  test "$(printf '%s\n' "$old_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$before"
  test "$(jq -er '.environmentId' "$before")" = 'Ree8z_4ixu7vAgBLdI_A_'
  test "$(jq -er '.buildType' "$before")" = 'dockerfile'; test "$(jq -er '.appName' "$before")" = "$expected_name"
  jq -r '.env' "$before" | awk -F= '$1 != "LOT_ACTION_PERMISSIONS_ENFORCE"' > "$env_file"
  printf 'LOT_ACTION_PERMISSIONS_ENFORCE=%s\n' "$desired" >> "$env_file"
  test "$(grep -Ec '^LOT_ACTION_PERMISSIONS_ENFORCE=' "$env_file")" -eq 1
  jq -nc --arg applicationId "$application_id" --rawfile env "$env_file" \
    '{json:{applicationId:$applicationId,env:$env}}' > "$payload"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/trpc/application.update" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' --data-binary "@$payload" >/dev/null
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$after"
  test "$(jq -r '.env' "$after" | grep -Fxc "LOT_ACTION_PERMISSIONS_ENFORCE=${desired}")" -eq 1
  scp "$after" "${STAGING_HOST}:/root/wlota1a/applications/${role}-activation.json"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
    --data "$(jq -nc --arg applicationId "$application_id" --arg title "WLOTA1A-${role}-${desired}-$(date +%s)" \
      '{applicationId:$applicationId,title:$title,description:"W-LOT-A-1a activation"}')" >/dev/null
  new_container=''
  for attempt in $(seq 1 90); do
    new_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
    if test "$(printf '%s\n' "$new_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1 \
      && test "$new_container" != "$old_container" \
      && test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"; then
      break
    fi
    sleep 5
  done
  test -n "$new_container"; test "$new_container" != "$old_container"
  test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"
}
update_and_redeploy worker 'KKYDsAvk4UpYfJXVmsDj2' 'erp-staging-worker-p2yjl1' true
update_and_redeploy api 'x5wfthp8-7cVbiUfI6Hq7' 'erp-staging-api-kghqex' true
update_and_redeploy scheduler 'HSXqHvmo_vq7NAYIjrE3T' 'erp-staging-scheduler-iqjxrv' true
```

### 11.11 ON readback, activated registration markers, and postactivation recensus

After all three replacement containers are healthy, source host helpers and compare inside-container readback with the committed heredoc:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
DOKPLOY_ACTUAL="$ROOT/evidence/activation-dokploy.actual.txt"
DOKPLOY_EXPECTED="$ROOT/evidence/activation-dokploy.expected.txt"
: > "$DOKPLOY_ACTUAL"
for role in worker api scheduler; do
  test "$(jq -r '.env' "$ROOT/applications/${role}-activation.json" | grep -Fxc 'LOT_ACTION_PERMISSIONS_ENFORCE=true')" -eq 1
  printf 'WLOTA1A-DOKPLOY-ENV service=%s expected=true actual=true outcome=PASS\n' "$role" >> "$DOKPLOY_ACTUAL"
done
cat > "$DOKPLOY_EXPECTED" <<'EXPECTED_DOKPLOY_ON'
WLOTA1A-DOKPLOY-ENV service=worker expected=true actual=true outcome=PASS
WLOTA1A-DOKPLOY-ENV service=api expected=true actual=true outcome=PASS
WLOTA1A-DOKPLOY-ENV service=scheduler expected=true actual=true outcome=PASS
EXPECTED_DOKPLOY_ON
cmp -s "$DOKPLOY_EXPECTED" "$DOKPLOY_ACTUAL" || fail_gate 'activation_dokploy_readback'
ACTUAL="$ROOT/evidence/activation.actual.txt"; EXPECTED="$ROOT/evidence/activation.expected.txt"; : > "$ACTUAL"
for row in 'worker erp-staging-worker-p2yjl1' 'api erp-staging-api-kghqex' 'scheduler erp-staging-scheduler-iqjxrv'; do
  set -- $row; container="$(resolve_container "$2")"
  value="$(docker exec "$container" printenv LOT_ACTION_PERMISSIONS_ENFORCE)"; test "$value" = 'true'
  printf 'WLOTA1A-ACTIVATION service=%s expected=true actual=%s outcome=PASS\n' "$1" "$value" >> "$ACTUAL"
done
cat > "$EXPECTED" <<'EXPECTED_FLAG_ON'
WLOTA1A-ACTIVATION service=worker expected=true actual=true outcome=PASS
WLOTA1A-ACTIVATION service=api expected=true actual=true outcome=PASS
WLOTA1A-ACTIVATION service=scheduler expected=true actual=true outcome=PASS
EXPECTED_FLAG_ON
cmp -s "$EXPECTED" "$ACTUAL" || fail_gate 'activation_container_readback'
test "$(docker exec "$(resolve_container 'erp-staging-api-kghqex')" printenv SYNC_PERMISSIONS_ON_BOOT)" = 'true'
```

Before registering the activated disposable tenant, write the RFC-3339 start marker in its own host shell:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf '%s\n' "$STARTED_AT" > "$ROOT/evidence/activated-registration.started-at.txt"
grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' \
  "$ROOT/evidence/activated-registration.started-at.txt" || fail_gate 'activated_registration_timestamp_format'
printf '%s\n' 'WLOTA1A-TIMESTAMP name=activated-registration format=rfc3339-whole-second-utc outcome=PASS' \
  > "$ROOT/evidence/activated-registration-timestamp.actual.txt"
cat > "$ROOT/evidence/activated-registration-timestamp.expected.txt" <<'EXPECTED_ACTIVATED_REGISTRATION_TIMESTAMP'
WLOTA1A-TIMESTAMP name=activated-registration format=rfc3339-whole-second-utc outcome=PASS
EXPECTED_ACTIVATED_REGISTRATION_TIMESTAMP
cmp -s "$ROOT/evidence/activated-registration-timestamp.expected.txt" \
  "$ROOT/evidence/activated-registration-timestamp.actual.txt" || fail_gate 'activated_registration_timestamp_record'
```

Run this exact staging-host request through Laravel's maintenance-secret cookie. It uses the same route and required JSON contract cited in §11.6, captures the response on the host, requires HTTP 201, validates it with `jq`, extracts exactly one `.data.user.tenantId`, persists the one-line manifest, and explicitly copies and compares it inside the current API:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
install -d -m 700 "$ROOT/evidence/registration-responses"
SECRET="$(cat "$ROOT/evidence/registration-maintenance-secret.txt")"; test -n "$SECRET"
COOKIE="$ROOT/evidence/activated-registration.cookies.txt"
curl --silent --show-error --cookie-jar "$COOKIE" --output /dev/null \
  "https://api.erp.otospex.dev/${SECRET}"
grep -Fq 'laravel_maintenance' "$COOKIE" || fail_gate 'activated_registration_bypass_cookie'
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
EMAIL="wlota1a-activated-${STAMP}@example.invalid"
RESPONSE="$ROOT/evidence/registration-responses/activated-disposable.json"
PAYLOAD="$ROOT/evidence/registration-responses/activated-disposable.request.json"
MANIFEST="$ROOT/manifests/activated-disposable.txt"
jq -nc --arg email "$EMAIL" '{
  name:"WLOTA1A Activated Disposable",
  email:$email,
  password:"Wlota1a!Activated2026",
  password_confirmation:"Wlota1a!Activated2026",
  company_name:"WLOTA1A Activated Disposable",
  country_code:"TN",
  vertical:"parapharmacy"
}' > "$PAYLOAD"
STATUS="$(curl --silent --show-error --cookie "$COOKIE" --output "$RESPONSE" --write-out '%{http_code}' \
  --request POST 'https://api.erp.otospex.dev/api/v1/auth/register' \
  --header 'accept: application/json' --header 'content-type: application/json' \
  --data-binary "@$PAYLOAD")"
test "$STATUS" = '201' || fail_gate "activated_disposable_registration_http_${STATUS}"
jq -e '.data.user.tenantId and (.data.user.tenantId | type == "string")' "$RESPONSE" >/dev/null \
  || fail_gate 'activated_disposable_registration_response_contract'
jq -er --arg uuid_re "^${UUID_RE}$" \
  '[.data.user.tenantId] | select(length == 1 and (.[0] | test($uuid_re))) | .[0]' \
  "$RESPONSE" > "$MANIFEST" || fail_gate 'activated_disposable_uuid_extraction'
test "$(line_count "$MANIFEST")" -eq 1 || fail_gate 'activated_disposable_uuid_count'
API="$(resolve_container 'erp-staging-api-kghqex')"
docker cp "$MANIFEST" "$API:/tmp/wlota1a-activated-disposable.txt"
docker exec "$API" cat /tmp/wlota1a-activated-disposable.txt \
  > "$ROOT/evidence/activated-disposable.container.txt"
cmp -s "$MANIFEST" "$ROOT/evidence/activated-disposable.container.txt" \
  || fail_gate 'activated_disposable_manifest_transfer'
printf '%s\n' 'WLOTA1A-REGISTRATION name=activated-disposable http=201 response=valid tenant_count=1 transfer=match outcome=PASS' \
  > "$ROOT/evidence/activated-disposable-registration.actual.txt"
cat > "$ROOT/evidence/activated-disposable-registration.expected.txt" <<'EXPECTED_ACTIVATED_DISPOSABLE_REGISTRATION'
WLOTA1A-REGISTRATION name=activated-disposable http=201 response=valid tenant_count=1 transfer=match outcome=PASS
EXPECTED_ACTIVATED_DISPOSABLE_REGISTRATION
cmp -s "$ROOT/evidence/activated-disposable-registration.expected.txt" \
  "$ROOT/evidence/activated-disposable-registration.actual.txt" || fail_gate 'activated_disposable_registration_record'
```

`Artisan::call()` uses a `BufferedOutput` at [Application.php:165](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Console/Application.php:165) through line 167, so console output is not the evidence source. The Task-2 production call instead sends the exact marker through the existing `stderr` channel at [logging.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/logging.php:97) through line 104. Then capture the complete raw container log window, extract every structured-log marker without filtering by expected UUID, and compare it with the committed manifest template:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
API="$(resolve_container 'erp-staging-api-kghqex')"; MANIFEST="$ROOT/manifests/activated-disposable.txt"
test -s "$ROOT/evidence/activated-registration.started-at.txt"; test "$(line_count "$MANIFEST")" -eq 1
grep -Eq "^${UUID_RE}$" "$MANIFEST"
RAW="$ROOT/evidence/activated-registration.stderr.log"
MARKERS="$ROOT/evidence/activated-registration.markers.txt"
docker logs "$API" --since "$(cat "$ROOT/evidence/activated-registration.started-at.txt")" > "$RAW" 2>&1
grep -oE 'WLOTA1A-RESEED tenant=[^ ]+ mode=[^ ]+ outcome=[^ ]+ reason=[^ ]+' \
  "$RAW" > "$MARKERS" || true
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/activated-registration.markers.txt" \
  'WLOTA1A-RESEED' 'mode=ACTIVATED outcome=APPLIED reason=canonical_delta_applied' 'activated-registration'
```

Next, run the separate explicit reseed gate:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
MANIFEST="$ROOT/manifests/activated-disposable.txt"
run_reseed_on_current_api "$MANIFEST" 'activated-explicit-reseed'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/activated-explicit-reseed/markers.txt" \
  'WLOTA1A-RESEED' 'mode=ACTIVATED outcome=ALREADY_APPLIED reason=canonical_state_matches' 'activated-explicit-reseed'
```

This is a separate executable gate from the registration `APPLIED` record.

Capture `postactivation` from the replacement API with the literal phase invocation, then build and compare the exact expected manifest on the host:

```bash
PHASE=postactivation /root/wlota1a/helpers/capture-manifest-phase.sh

set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"; source "$ROOT/shas/push-shas.env"
cat "$ROOT/manifests/preactivation/expected-tenants.txt" "$ROOT/manifests/activated-disposable.txt" \
  | sort > "$ROOT/manifests/postactivation.expected.txt"
cmp -s "$ROOT/manifests/postactivation.expected.txt" "$ROOT/manifests/postactivation/expected-tenants.txt" \
  || fail_gate 'postactivation_manifest'
MANIFEST="$ROOT/manifests/postactivation/expected-tenants.txt"
run_delta_on_current_api "$MANIFEST" 'verify' 'postactivation-verify'
assert_marker_cmp "$MANIFEST" "$ROOT/evidence/postactivation-verify/markers.txt" \
  'WLOTA1A-PERMISSIONS' 'mode=VERIFY outcome=ALREADY_APPLIED reason=canonical_state_matches' 'postactivation-verify'
run_censuses_on_current_api "$MANIFEST" 'postactivation-census'
assert_census_cmp "$MANIFEST" "$ROOT/evidence/postactivation-census/census.markers.txt" 'postactivation-census'
```

Only then run this complete configuration-cache, permission-cache, and Horizon gate. Laravel's exact configuration messages are defined at [ConfigClearCommand.php:54](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigClearCommand.php:54) and [ConfigCacheCommand.php:84](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Console/ConfigCacheCommand.php:84); the exact permission-cache message is at [CacheReset.php:20](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/spatie/laravel-permission/src/Commands/CacheReset.php:20); and `horizon:status` returns non-zero unless it reaches the running branch at [StatusCommand.php:34](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/horizon/src/Console/StatusCommand.php:34) through line 50.

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
API="$(resolve_container 'erp-staging-api-kghqex')"
CONFIG_LOG="$ROOT/evidence/postactivation-config-cache.log"
CONFIG_ACTUAL="$ROOT/evidence/postactivation-config-cache.actual.txt"
CONFIG_EXPECTED="$ROOT/evidence/postactivation-config-cache.expected.txt"
{
  docker exec --workdir /var/www/html "$API" php artisan config:clear --no-ansi
  docker exec --workdir /var/www/html "$API" php artisan config:cache --no-ansi
  docker exec --workdir /var/www/html "$API" php artisan tinker --execute="exit(config('lot_action_permissions.enforce') === true ? 0 : 1);"
} 2>&1 | tee "$CONFIG_LOG"
reject_bad_output "$CONFIG_LOG"
{
  grep -Fo 'Configuration cache cleared successfully.' "$CONFIG_LOG"
  grep -Fo 'Configuration cached successfully.' "$CONFIG_LOG"
} > "$CONFIG_ACTUAL"
cat > "$CONFIG_EXPECTED" <<'EXPECTED_POSTACTIVATION_CONFIG'
Configuration cache cleared successfully.
Configuration cached successfully.
EXPECTED_POSTACTIVATION_CONFIG
cmp -s "$CONFIG_EXPECTED" "$CONFIG_ACTUAL" || fail_gate 'postactivation_config_cache'

PERMISSION_LOG="$ROOT/evidence/postactivation-permission-cache.log"
docker exec --workdir /var/www/html "$API" php artisan permission:cache-reset --no-ansi 2>&1 | tee "$PERMISSION_LOG"
reject_bad_output "$PERMISSION_LOG"
test "$(grep -Foc 'Permission cache flushed.' "$PERMISSION_LOG" || true)" -eq 1
grep -Fo 'Permission cache flushed.' "$PERMISSION_LOG" > "$ROOT/evidence/postactivation-permission-cache.actual.txt"
cat > "$ROOT/evidence/postactivation-permission-cache.expected.txt" <<'EXPECTED_POSTACTIVATION_PERMISSION_CACHE'
Permission cache flushed.
EXPECTED_POSTACTIVATION_PERMISSION_CACHE
cmp -s "$ROOT/evidence/postactivation-permission-cache.expected.txt" "$ROOT/evidence/postactivation-permission-cache.actual.txt" \
  || fail_gate 'postactivation_permission_cache'

WORKER="$(resolve_container 'erp-staging-worker-p2yjl1')"
docker exec --workdir /var/www/html "$WORKER" php artisan horizon:terminate --wait --no-ansi \
  > "$ROOT/evidence/postactivation-horizon-terminate.log" 2>&1
STATUS_LOG="$ROOT/evidence/postactivation-horizon-status.log"
: > "$STATUS_LOG"
for attempt in $(seq 1 60); do
  WORKER="$(resolve_container 'erp-staging-worker-p2yjl1')"
  if docker exec --workdir /var/www/html "$WORKER" php artisan horizon:status --no-ansi > "$STATUS_LOG" 2>&1 \
    && test "$(grep -Foc 'Horizon is running.' "$STATUS_LOG" || true)" -eq 1; then
    break
  fi
  sleep 5
done
cat > "$ROOT/evidence/postactivation-horizon.expected.txt" <<'EXPECTED_HORIZON_RUNNING'
Horizon is running.
EXPECTED_HORIZON_RUNNING
grep -Fo 'Horizon is running.' "$STATUS_LOG" > "$ROOT/evidence/postactivation-horizon.actual.txt"
cmp -s "$ROOT/evidence/postactivation-horizon.expected.txt" "$ROOT/evidence/postactivation-horizon.actual.txt" \
  || fail_gate 'postactivation_horizon_not_running'
```

Only after that block exits zero may registration resume. First deactivate the cache-backed maintenance state and prove an ordinary empty registration reaches validation (422, not maintenance 503). `artisan up` deactivates maintenance mode at [UpCommand.php:32](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/laravel/framework/src/Illuminate/Foundation/Console/UpCommand.php:32) through line 49:

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
API="$(resolve_container 'erp-staging-api-kghqex')"
docker exec --workdir /var/www/html "$API" php artisan up --no-ansi \
  > "$ROOT/evidence/registration-resume.log" 2>&1
STATUS="$(curl --silent --show-error --output "$ROOT/evidence/registration-resume.validation.json" \
  --write-out '%{http_code}' --request POST 'https://api.erp.otospex.dev/api/v1/auth/register' \
  --header 'accept: application/json' --header 'content-type: application/json' --data-binary '{}')"
test "$STATUS" = '422' || fail_gate "registration_resume_validation_http_${STATUS}"
printf '%s\n' 'WLOTA1A-REGISTRATION-RESUME stage=artisan-up public_http=422 outcome=PASS' \
  > "$ROOT/evidence/registration-resume.actual.txt"
cat > "$ROOT/evidence/registration-resume.expected.txt" <<'EXPECTED_REGISTRATION_RESUME'
WLOTA1A-REGISTRATION-RESUME stage=artisan-up public_http=422 outcome=PASS
EXPECTED_REGISTRATION_RESUME
cmp -s "$ROOT/evidence/registration-resume.expected.txt" "$ROOT/evidence/registration-resume.actual.txt" \
  || fail_gate 'registration_resume_record'
```

Then run this reversible Dokploy cleanup from the workstation. It fetches the current API env, restores only the two maintenance-variable lines to their exact pre-pause presence/value from `api-registration-pause.before.json`, preserves the now-true rollout flag and every unrelated variable, redeploys the API, and proves registration still reaches validation:

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'; DOKPLOY_URL='https://app.dokploy.com'
APPLICATION_ID='x5wfthp8-7cVbiUfI6Hq7'; APP_NAME='erp-staging-api-kghqex'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"
scp "$STAGING_HOST:/root/wlota1a/helpers/fail-gate.sh" "$WORK/fail-gate.sh"; source "$WORK/fail-gate.sh"
scp "$STAGING_HOST:/root/wlota1a/applications/api-registration-pause.before.json" "$WORK/before.json"
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=${APPLICATION_ID}" > "$WORK/current.json"
jq -r '.env' "$WORK/current.json" \
  | awk -F= '$1 != "APP_MAINTENANCE_DRIVER" && $1 != "APP_MAINTENANCE_STORE"' > "$WORK/restored.env"
jq -r '.env' "$WORK/before.json" \
  | grep -E '^(APP_MAINTENANCE_DRIVER|APP_MAINTENANCE_STORE)=' >> "$WORK/restored.env" || true
test "$(grep -Fxc 'LOT_ACTION_PERMISSIONS_ENFORCE=true' "$WORK/restored.env")" -eq 1 \
  || fail_gate 'registration_resume_preserve_rollout_flag'
jq -nc --arg applicationId "$APPLICATION_ID" --rawfile env "$WORK/restored.env" \
  '{json:{applicationId:$applicationId,env:$env}}' > "$WORK/payload.json"
OLD_CONTAINER="$(ssh "$STAGING_HOST" "docker ps --filter name=${APP_NAME} --format '{{.ID}}'")"
curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/trpc/application.update" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' --data-binary "@$WORK/payload.json" >/dev/null
curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
  --data "$(jq -nc --arg applicationId "$APPLICATION_ID" '{applicationId:$applicationId}')" >/dev/null
NEW_CONTAINER=''
for attempt in $(seq 1 90); do
  NEW_CONTAINER="$(ssh "$STAGING_HOST" "docker ps --filter name=${APP_NAME} --format '{{.ID}}'")"
  if test -n "$NEW_CONTAINER" && test "$NEW_CONTAINER" != "$OLD_CONTAINER" \
    && test "$(ssh "$STAGING_HOST" "docker exec ${NEW_CONTAINER} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = 'true'; then break; fi
  sleep 5
done
test -n "$NEW_CONTAINER" && test "$NEW_CONTAINER" != "$OLD_CONTAINER" || fail_gate 'registration_resume_api_redeploy_timeout'
curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
  "${DOKPLOY_URL}/api/application.one?applicationId=${APPLICATION_ID}" > "$WORK/after.json"
test "$(jq -r '.env' "$WORK/after.json" | grep -Fxc 'LOT_ACTION_PERMISSIONS_ENFORCE=true')" -eq 1 \
  || fail_gate 'registration_resume_rollout_readback'
jq -r '.env' "$WORK/after.json" > "$WORK/after.env"
cmp -s "$WORK/restored.env" "$WORK/after.env" || fail_gate 'maintenance_environment_exact_restoration'
STATUS="$(curl --silent --show-error --output "$WORK/validation.json" --write-out '%{http_code}' \
  --request POST 'https://api.erp.otospex.dev/api/v1/auth/register' \
  --header 'accept: application/json' --header 'content-type: application/json' --data-binary '{}')"
test "$STATUS" = '422' || fail_gate "registration_resume_post_redeploy_http_${STATUS}"
printf '%s\n' 'WLOTA1A-MAINTENANCE-RESTORE environment=exact rollout=true public_http=422 outcome=PASS' \
  > "$WORK/maintenance-restoration.actual.txt"
cat > "$WORK/maintenance-restoration.expected.txt" <<'EXPECTED_MAINTENANCE_RESTORATION'
WLOTA1A-MAINTENANCE-RESTORE environment=exact rollout=true public_http=422 outcome=PASS
EXPECTED_MAINTENANCE_RESTORATION
cmp -s "$WORK/maintenance-restoration.expected.txt" "$WORK/maintenance-restoration.actual.txt" \
  || fail_gate 'maintenance_restoration_record'
scp "$WORK/after.json" "$WORK/after.env" "$WORK/validation.json" \
  "$WORK/maintenance-restoration.actual.txt" "$WORK/maintenance-restoration.expected.txt" \
  "$STAGING_HOST:/root/wlota1a/evidence/"
```

Then run API smoke, post-activation Playwright, and §12.

### 11.12 Executable separate-application rollback

Run this self-contained ROLLBACK workstation block. It defines every dependency in its own shell, captures the exact postactivation permission state before the API boot seed, re-fetches and updates the full current env for each application, uses `application.update` and `application.one`, redeploys sequentially worker → API → scheduler, and verifies replacement-container `printenv`. It never reuses activation-era env text.

```bash
set -Eeuo pipefail
export LC_ALL=C
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
STAGING_HOST='root@157.180.71.252'
DOKPLOY_URL='https://app.dokploy.com'
DOKPLOY_API_KEY="$(jq -er '.mcpServers["dokploy-mcp"].env.DOKPLOY_API_KEY' /Users/houssamr/.claude.json)"

ssh "$STAGING_HOST" 'bash -s' <<'ROLLBACK_BEFORE'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/postactivation/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/postactivation/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'rollback.before'
ROLLBACK_BEFORE

update_and_redeploy() {
  role="$1"; application_id="$2"; expected_name="$3"; desired="$4"
  before="$WORK/${role}.before.json"; env_file="$WORK/${role}.env"
  payload="$WORK/${role}.payload.json"; after="$WORK/${role}.after.json"
  old_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
  test "$(printf '%s\n' "$old_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$before"
  test "$(jq -er '.applicationId' "$before")" = "$application_id"
  test "$(jq -er '.environmentId' "$before")" = 'Ree8z_4ixu7vAgBLdI_A_'
  test "$(jq -er '.appName' "$before")" = "$expected_name"
  jq -r '.env' "$before" | awk -F= '$1 != "LOT_ACTION_PERMISSIONS_ENFORCE"' > "$env_file"
  printf 'LOT_ACTION_PERMISSIONS_ENFORCE=%s\n' "$desired" >> "$env_file"
  test "$(grep -Ec '^LOT_ACTION_PERMISSIONS_ENFORCE=' "$env_file")" -eq 1
  jq -nc --arg applicationId "$application_id" --rawfile env "$env_file" \
    '{json:{applicationId:$applicationId,env:$env}}' > "$payload"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/trpc/application.update" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' --data-binary "@$payload" >/dev/null
  curl --fail-with-body --silent --show-error -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=${application_id}" > "$after"
  test "$(jq -er '.applicationId' "$after")" = "$application_id"
  test "$(jq -er '.appName' "$after")" = "$expected_name"
  test "$(jq -r '.env' "$after" | grep -Fxc "LOT_ACTION_PERMISSIONS_ENFORCE=${desired}")" -eq 1
  scp "$after" "${STAGING_HOST}:/root/wlota1a/applications/${role}-rollback.json"
  curl --fail-with-body --silent --show-error -X POST "${DOKPLOY_URL}/api/application.redeploy" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" -H 'content-type: application/json' \
    --data "$(jq -nc --arg applicationId "$application_id" --arg title "WLOTA1A-${role}-rollback-$(date +%s)" \
      '{applicationId:$applicationId,title:$title,description:"W-LOT-A-1a rollback OFF"}')" >/dev/null
  new_container=''
  for attempt in $(seq 1 90); do
    new_container="$(ssh "$STAGING_HOST" "docker ps --filter name=${expected_name} --format '{{.ID}}'")"
    if test "$(printf '%s\n' "$new_container" | sed '/^$/d' | wc -l | tr -d ' ')" -eq 1 \
      && test "$new_container" != "$old_container" \
      && test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"; then
      break
    fi
    sleep 5
  done
  test -n "$new_container"; test "$new_container" != "$old_container"
  test "$(ssh "$STAGING_HOST" "docker exec ${new_container} printenv LOT_ACTION_PERMISSIONS_ENFORCE")" = "$desired"
}

update_and_redeploy worker 'KKYDsAvk4UpYfJXVmsDj2' 'erp-staging-worker-p2yjl1' false
update_and_redeploy api 'x5wfthp8-7cVbiUfI6Hq7' 'erp-staging-api-kghqex' false
update_and_redeploy scheduler 'HSXqHvmo_vq7NAYIjrE3T' 'erp-staging-scheduler-iqjxrv' false

ssh "$STAGING_HOST" 'bash -s' <<'ROLLBACK_AFTER'
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
MANIFEST="$ROOT/manifests/postactivation/expected-tenants.txt"; test -s "$MANIFEST"
MAPPING="$ROOT/manifests/postactivation/tenant-databases.tsv"
capture_permission_snapshot "$MANIFEST" "$MAPPING" 'rollback.after'
cmp -s "$ROOT/snapshots/rollback.before.txt" "$ROOT/snapshots/rollback.after.txt" \
  || fail_gate 'rollback_api_boot_seed_changed_permissions'
assert_preserved_post_delta_snapshot "$MANIFEST" "$MAPPING" 'rollback-preserved-post-delta'
DOKPLOY_ACTUAL="$ROOT/evidence/rollback-dokploy.actual.txt"
DOKPLOY_EXPECTED="$ROOT/evidence/rollback-dokploy.expected.txt"; : > "$DOKPLOY_ACTUAL"
for role in worker api scheduler; do
  test "$(jq -r '.env' "$ROOT/applications/${role}-rollback.json" | grep -Fxc 'LOT_ACTION_PERMISSIONS_ENFORCE=false')" -eq 1
  printf 'WLOTA1A-DOKPLOY-ROLLBACK service=%s expected=false actual=false outcome=PASS\n' "$role" >> "$DOKPLOY_ACTUAL"
done
cat > "$DOKPLOY_EXPECTED" <<'EXPECTED_DOKPLOY_OFF'
WLOTA1A-DOKPLOY-ROLLBACK service=worker expected=false actual=false outcome=PASS
WLOTA1A-DOKPLOY-ROLLBACK service=api expected=false actual=false outcome=PASS
WLOTA1A-DOKPLOY-ROLLBACK service=scheduler expected=false actual=false outcome=PASS
EXPECTED_DOKPLOY_OFF
cmp -s "$DOKPLOY_EXPECTED" "$DOKPLOY_ACTUAL" || fail_gate 'rollback_dokploy_readback'
ACTUAL="$ROOT/evidence/rollback.actual.txt"; EXPECTED="$ROOT/evidence/rollback.expected.txt"; : > "$ACTUAL"
for row in 'worker erp-staging-worker-p2yjl1' 'api erp-staging-api-kghqex' 'scheduler erp-staging-scheduler-iqjxrv'; do
  set -- $row; container="$(resolve_container "$2")"
  value="$(docker exec "$container" printenv LOT_ACTION_PERMISSIONS_ENFORCE)"; test "$value" = 'false'
  printf 'WLOTA1A-ROLLBACK service=%s expected=false actual=%s outcome=PASS\n' "$1" "$value" >> "$ACTUAL"
done
cat > "$EXPECTED" <<'EXPECTED_ROLLBACK_OFF'
WLOTA1A-ROLLBACK service=worker expected=false actual=false outcome=PASS
WLOTA1A-ROLLBACK service=api expected=false actual=false outcome=PASS
WLOTA1A-ROLLBACK service=scheduler expected=false actual=false outcome=PASS
EXPECTED_ROLLBACK_OFF
cmp -s "$EXPECTED" "$ACTUAL" || fail_gate 'rollback_container_readback'
test "$(docker exec "$(resolve_container 'erp-staging-api-kghqex')" printenv SYNC_PERMISSIONS_ON_BOOT)" = 'true'
ROLLBACK_AFTER
```

Then run the rollback cache/Horizon gate below. Every command status is observed under `pipefail`; expected output is reduced to committed heredocs and compared with `cmp`; cached configuration is explicitly evaluated as false; and Horizon must return its exact running verdict.

```bash
set -Eeuo pipefail
ROOT='/root/wlota1a'; source "$ROOT/helpers/host-helpers.sh"
API="$(resolve_container 'erp-staging-api-kghqex')"
CONFIG_LOG="$ROOT/evidence/rollback-config-cache.log"
{
  docker exec --workdir /var/www/html "$API" php artisan config:clear --no-ansi
  docker exec --workdir /var/www/html "$API" php artisan config:cache --no-ansi
  docker exec --workdir /var/www/html "$API" php artisan tinker --execute="exit(config('lot_action_permissions.enforce') === false ? 0 : 1);"
} 2>&1 | tee "$CONFIG_LOG"
reject_bad_output "$CONFIG_LOG"
{
  grep -Fo 'Configuration cache cleared successfully.' "$CONFIG_LOG"
  grep -Fo 'Configuration cached successfully.' "$CONFIG_LOG"
} > "$ROOT/evidence/rollback-config-cache.actual.txt"
cat > "$ROOT/evidence/rollback-config-cache.expected.txt" <<'EXPECTED_ROLLBACK_CONFIG'
Configuration cache cleared successfully.
Configuration cached successfully.
EXPECTED_ROLLBACK_CONFIG
cmp -s "$ROOT/evidence/rollback-config-cache.expected.txt" "$ROOT/evidence/rollback-config-cache.actual.txt" \
  || fail_gate 'rollback_config_cache'

PERMISSION_LOG="$ROOT/evidence/rollback-permission-cache.log"
docker exec --workdir /var/www/html "$API" php artisan permission:cache-reset --no-ansi 2>&1 | tee "$PERMISSION_LOG"
reject_bad_output "$PERMISSION_LOG"
test "$(grep -Foc 'Permission cache flushed.' "$PERMISSION_LOG" || true)" -eq 1
grep -Fo 'Permission cache flushed.' "$PERMISSION_LOG" > "$ROOT/evidence/rollback-permission-cache.actual.txt"
cat > "$ROOT/evidence/rollback-permission-cache.expected.txt" <<'EXPECTED_ROLLBACK_PERMISSION_CACHE'
Permission cache flushed.
EXPECTED_ROLLBACK_PERMISSION_CACHE
cmp -s "$ROOT/evidence/rollback-permission-cache.expected.txt" "$ROOT/evidence/rollback-permission-cache.actual.txt" \
  || fail_gate 'rollback_permission_cache'

WORKER="$(resolve_container 'erp-staging-worker-p2yjl1')"
docker exec --workdir /var/www/html "$WORKER" php artisan horizon:terminate --wait --no-ansi \
  > "$ROOT/evidence/rollback-horizon-terminate.log" 2>&1
STATUS_LOG="$ROOT/evidence/rollback-horizon-status.log"; : > "$STATUS_LOG"
for attempt in $(seq 1 60); do
  WORKER="$(resolve_container 'erp-staging-worker-p2yjl1')"
  if docker exec --workdir /var/www/html "$WORKER" php artisan horizon:status --no-ansi > "$STATUS_LOG" 2>&1 \
    && test "$(grep -Foc 'Horizon is running.' "$STATUS_LOG" || true)" -eq 1; then break; fi
  sleep 5
done
grep -Fo 'Horizon is running.' "$STATUS_LOG" > "$ROOT/evidence/rollback-horizon.actual.txt"
cat > "$ROOT/evidence/rollback-horizon.expected.txt" <<'EXPECTED_ROLLBACK_HORIZON'
Horizon is running.
EXPECTED_ROLLBACK_HORIZON
cmp -s "$ROOT/evidence/rollback-horizon.expected.txt" "$ROOT/evidence/rollback-horizon.actual.txt" \
  || fail_gate 'rollback_horizon_not_running'
```

Preserve the additive schema, provisioning marker, role ID, protected-role API behavior, generated DTO type, and read-only Roles page. Never restore manager recall or edit Spatie pivots directly. Because `SYNC_PERMISSIONS_ON_BOOT=true` remains set, the rollback API boot also runs the seeder; both the exact full pre/post snapshot `cmp` (including custom grants) and the committed `EXPECTED_PRESERVED_TEMPLATE` comparison must pass before rollback is accepted.

## 12. Manifest §4 gate checklist — copied verbatim

- [ ] **Onboarding campaign GREEN** — `scripts/campaign-onboarding.sh` (local) or
      `scripts/campaign-onboarding.sh --web https://erp.otospex.dev --api https://api.erp.otospex.dev --country TN`
      (`docs/qa/ONBOARDING-CAMPAIGN.md:5-22`; flags `scripts/campaign-onboarding.sh:9-29`).
      Promotion reads the **ledger**, not the exit code (`ONBOARDING-CAMPAIGN.md:3`); the target
      must run a worker consuming `imports` + `fiscal-projections` (`:49`); registration is
      throttled and every run leaves a tenant behind (`:50,53`).
- [ ] **Day-one census CLEAN** — `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`,
      every verdict line clean; grep `DAY-ONE CENSUS` / `DRIFT(` because `tenants:run` discards exit
      codes (`docs/handoff/RUNBOOK-day-one-census.md:7-9,25`). CLAUDE.md rule 22.
- [ ] **Promotion-checklist rows** — migrations enumerated, each declared self-guarding
      (`PROMOTION-CHECKLIST-2026-08-26.md:22-67`); non-self-running seeders listed (`:69-74`);
      post-deploy censuses run (`:84-90`); Horizon queue coverage confirmed (`:94`).
- [ ] **Preflight green at host scope** — `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` on the
      laptop; full suite is VPS/CI only (`WORKFLOW.md:36,147-162`). A `paths` run with **no** paths
      skips PHPUnit and is not a green.
- [ ] **dev-push-guard behaviour understood** — force-push to `dev` denied; a behind/diverged local
      `dev` denied with the exact reconcile command (`.claude/hooks/git-dev-push-guard.sh:73-88`).
      Commit and push are separate Bash calls.
- [ ] **Fast-forward-only promotion** — `git log --oneline dev..origin/dev | wc -l` is `0` before
      promoting; never rewrite shared history (`PROMOTION-CHECKLIST-2026-08-26.md:15-20`; CLAUDE.md rule 21).
- [ ] **Backup taken on the host and verified non-zero** before any migrating/backfilling push (row I).

## 13. Verification commands

Task 1:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/BatchExpiry/BatchActionPermissionsTest.php \
  tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchExpiringLocationScopeTest.php \
  tests/Feature/BatchExpiry/BatchTraceReaderContractTest.php

./vendor/bin/phpunit -c phpunit.xml \
  tests/Architecture/BatchTraceabilityModuleBoundaryTest.php
```

Task 2:

```bash
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant'

./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_rollback_preserves_post_activation_state'

./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'LotActionReseedMarkerTest::test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering'

./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Identity/LotActionSeededRoleMatrixTest.php \
  tests/Feature/Identity/RolesAndPermissionsSeederMarkedTenantTest.php \
  tests/Feature/Identity/LotActionPermissionDeltaTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentTest.php \
  tests/Feature/Identity/GeneralManagerRoleProtectionTest.php \
  tests/Feature/Identity/RoleIndexResponseContractTest.php \
  tests/Feature/Identity/GeneralManagerAssignmentWriterCensusTest.php \
  tests/Feature/Console/LotActionReseedMarkerTest.php \
  tests/Feature/Console/ApplyLotActionPermissionDeltaCommandTest.php \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php \
  tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php

./vendor/bin/phpunit -c phpunit.xml \
  tests/Feature/Migrations/RoleProvisioningSourceSchemaTest.php

php artisan typescript:transform
```

Task 6:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm vitest run \
  src/routes/__tests__/BatchRoutePermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchPermissions.test.tsx \
  src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts \
  src/features/settings/RolesPage.test.tsx \
  src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx \
  src/hooks/__tests__/usePermissions.moduleAccess.test.tsx

pnpm typecheck
pnpm typecheck:e2e
pnpm lint
```

Pre-activation:

```bash
pnpm exec playwright test e2e/batch-permissions.spec.ts \
  --project=chromium \
  --grep 'pre-activation web gating'
```

Post-activation only:

```bash
pnpm exec playwright test e2e/batch-permissions.spec.ts \
  --project=chromium \
  --grep 'post-activation isolation'
```

Run the repository’s complete API PostgreSQL lane and web E2E lane after focused tests.

## 14. Combined reviewer gate and rollback

After Tasks 1 and 2 are green, dispatch:

- `tenancy-authz-reviewer`
- `inventory-costing-reviewer`

After Task 6/map/type regeneration is green, dispatch:

- `frontend-conventions-reviewer`
- `tenancy-authz-reviewer`
- `inventory-costing-reviewer`

Then run all three against the combined diff. Required result:

```text
ACCEPT
BLOCKER=0
MAJOR=0
```

The inventory reviewer must explicitly cite:

- Four-decimal scoped totals.
- Filtered eager-load totals.
- Unchanged batch stock.
- Unchanged reservations.
- Unchanged Document/POS history.
- Unchanged journal entries and lines.
- No eligibility or hold behavior.

The tenancy reviewer must explicitly cite:

- Every phase's `tenant-databases.tsv` is produced inside the current API by inherited `Tenant::getTenantKey()` at [Tenant.php:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Database/Models/Tenant.php:42) plus canonical `Tenant::getDatabaseName()` at [Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264), is copied to the host, and passes the one-to-one UUID/count/non-empty-database `fail_gate`; every `psql`/`pg_dump` consumer reads that mapping.
- Delta, reseed, census, migration, snapshot, and backup loops preserve every tenant's status and full output, aggregate raw prefixed markers, and copy container evidence to the host before status/bad-output rejection and committed-template `cmp`.
- No Task-1 middleware on create/update/transfer/write-off.
- Null-team marked roles rejected on PostgreSQL and SQLite.
- One marker per tenant team.
- Name, permission, and delete mutations rejected for the marked role.
- Roles index/show serialize through snake-case `RoleData`, preserve the exact existing wire keys, and derive `is_provisioned_read_only` from the marker.
- `RoleIndexResponseContractTest::test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived` passes in the PostgreSQL lane.
- `Log::channel('stderr')->info()` is the registration evidence channel backed by the existing `php://stderr` stream at [logging.php:97](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/config/logging.php:97) through line 104, and `LotActionReseedMarkerTest::test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering` passes in the PostgreSQL lane.
- Grants, role ID, and pivot cardinality unchanged after rejected updates.
- Exact A-1b/A-1c boundary.
- Exact wrapper normalization for string `'1'`.
- `RolesAndPermissionsSeeder::markedTenantCarriesWlota1aDelta()` checks the exact team/name/guard/provisioning marker before the current destructive synchronization seam, so flag-off boot seeding preserves the canonical delta and custom grants after Push 4 and rollback.
- `RolesAndPermissionsSeederMarkedTenantTest::test_flag_off_reseed_after_delta_preserves_canonical_grants_for_marked_tenant` and `test_flag_off_reseed_after_rollback_preserves_post_activation_state` pass in PostgreSQL.

The frontend reviewer must explicitly cite:

- Push 5 and its incident fallback persist a pre-trigger deployment-ID baseline, select `description="Commit: <sha>"` records absent from that baseline, use only an inclusive whole-second timestamp lower bound, pin one deployment ID, poll it to `done`, and compare the committed record and served-evidence templates.
- `RolesPage.tsx` consumes generated `is_provisioned_read_only`.
- The field is marker-derived, not name-derived.
- Protected edit/delete affordances are absent.
- Modal submission has an independent fail-closed backstop.
- The generated role type is not hand-authored.

Emergency rollback follows only the self-contained separate-application procedure in §11.12: it defines its own workstation dependencies and updater, captures exact pre/post API permission snapshots, requires both their byte identity and the committed preserved post-delta snapshot template, updates the full current env and performs `application.one` readback for worker → API → scheduler, verifies every replacement container with `printenv`, and requires the Dokploy, container, configuration-cache, permission-cache, and Horizon heredoc `cmp` gates. Never delete, rename, unmark, restore manager `batches.recall`, or mutate the protected role through direct SQL; never roll back the marker migration over a marked row; never hand-edit Spatie pivots or generated types.

## 15. W-LOT-A-1b and W-LOT-A-1c boundaries

W-LOT-A-1b begins only after this slice is accepted and activated. It owns only:

- Recall-request and branch-hold tables.
- Initial status enum/CHECK accepting `requested`, `recalled`, `released`, and `rejected`.
- Request/hold creation and local branch holds.
- The `requested → recalled` transition.
- Recall escalation, operation UUIDs, replay, concurrency, and append-only request/recalled evidence.
- Eligibility changes only for POS sale, direct batch transfer, and StockTransfer allocation.
- Delivery, write-off, stock-count, and return eligibility changes are deferred to named later lanes.
- Company-wide recall-service replacement.
- Request/history/capability routes and request/history UI.
- The existing web recall payload defect.

W-LOT-A-1b must not implement:

- `requested → released`.
- `requested → rejected`.
- Release or reject permissions.
- Release or reject authority checks.
- Release or reject reasons/evidence.
- Release or reject routes.
- Release or reject UI.
- Delivery, write-off, stock-count, or return eligibility without a separately named and sourced lane.

W-LOT-A-1c begins only after W-LOT-A-1b is accepted. It is the exact and sole owner of:

- `requested → released`.
- `requested → rejected`.
- General-manager-only release and reject authority.
- Mandatory release/reject reason.
- Append-only release/reject evidence.
- Requesting-branch self-release prohibition.
- Release/reject routes.
- Release/reject UI.

A-1a supplies only the prerequisite permission split, unrestricted general-manager invariant, protected-role read-only enforcement, safe batch reads, and trace contracts.

## 16. Dispatch order

1. Record actual `DISPATCH_SHA`; run the repin procedure on drift.
2. Preserve unrelated untracked files.
3. Run the §11.2 bootstrap to install the single durable `fail_gate` definition and host/container helpers under `/root/wlota1a/`.
4. Run §11.1 before source-push dispatch and verify the exact API, worker, scheduler, and web Dokploy application records.
5. Record only `separate_applications`; confirm the five-push ledger has no Push 6.
6. Promote Push 1 and immediately persist `PUSH1_SHA`.
7. Capture the non-empty Push-1 tenant UUID manifest and canonical `Tenant::getDatabaseName()` mapping ([Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264)) on the current API, `docker cp` both to the host, and pass the one-to-one mapping `fail_gate`.
8. Run exact day-one, lot-drift, and phantom record-set `cmp` gates.
9. Run the self-contained §11.4 INITIAL-OFF block; record `SYNC_PERMISSIONS_ON_BOOT=true`, perform full-env update/readback and replacement-container verification for all three exact applications, and require the pre/post API permission snapshot `cmp`.
10. Add Task-1 red tests and capture failures.
11. Implement Task 1 behind the false flag.
12. Prove create/update/transfer/write-off authorization remains unchanged.
13. Run Task-1 tests and both reviewers.
14. Add Task-2 red tests, including both marked-tenant flag-off reseed preservation cases, marked-role mutation, wrapper string-option, roles-index response-contract, and fresh-registration stderr-marker tests.
15. Implement/test the additive migration and database invariants.
16. Implement fresh/unmarked legacy-off seeding, the exact marked-tenant preservation branch before `syncPermissions()`, canonical delta, team boundaries, and assignment transactions.
17. Add snake-case readonly `RoleData`, generated `is_provisioned_read_only`, controller DTO serialization, and read-only enforcement.
18. Run Task-2 PostgreSQL concurrency, response-contract, protection, wrapper, team, and PostgreSQL/SQLite schema tests.
19. Obtain preliminary reviewer ACCEPT.
20. Capture Push-2 tenants and their canonical database mapping, take one verified non-zero host backup per mapped database, and persist every per-tenant backup status/output plus the pre-Push-2 permissions snapshot and API container ID.
21. Promote Push 2 and immediately persist `PUSH2_SHA`.
22. Prove the replacement API still has boot sync true/enforcement false and that its boot seed changed no existing permission, role, or grant.
23. Run both migration passes without early loop exit, aggregate their raw prefixed markers, copy all per-tenant outputs/statuses and aggregates to the host, then verify status, bad-output rejection, literal output, and committed marker templates.
24. Persist the pre-Push-3 permissions snapshot and API container ID.
25. Promote Push 3 with the flag false and immediately persist `PUSH3_SHA`.
26. Prove the replacement API boot seed is inert for existing tenants.
27. Execute the exact three-application OFF aggregate `cmp`; create the disposable legacy tenant with the committed `curl`/JSON/`jq` producer; persist, transfer, and compare its one UUID and literal registration-response record; then collect/copy all reseed output before executing its legacy marker `cmp`.
28. Recapture Push-4 tenants plus canonical database names and take one verified host backup per mapped database.
29. Promote operations-only Push 4 and immediately persist `PUSH4_SHA`.
30. Capture the pre-delta permission snapshot; apply and verify the explicit delta once per expected tenant.
31. Prove Push 4 changed the existing-tenant snapshot, reset permission cache, and run complete census gates.
32. Add Task-6 red tests, including `RolesPage.test.tsx`.
33. Implement batch gating plus marker-derived Roles-page read-only behavior.
34. Regenerate permission and DTO types and require byte identity with committed artifacts.
35. Run Vitest, both real typecheck scripts, lint, and reviewers.
36. Persist the pre-Push-5 post-delta permissions snapshot and API container ID.
37. Promote Push 5 with backend enforcement false and immediately persist `PUSH5_SHA`.
38. Prove the replacement API boot seed is inert and did not undo Push 4 by full snapshot equality plus the committed post-delta heredoc expectation.
39. Before Push 5, persist the existing web `deploymentId` baseline to the host; set `CANDIDATE_SHA=PUSH5_SHA`; correlate the unordered automatic web records on `description="Commit: ${PUSH5_SHA}"`, absence from that baseline, and only secondarily `createdAt >=` the whole-second push timestamp; pin that `deploymentId`, poll only it until `status=done`, compare `(deploymentId, description, status)` with the committed template, then compare the literal changed-asset/bundle/build/fingerprint evidence record. Do not manually redeploy on the normal path; the incident fallback repeats the identical fresh-baseline protocol.
40. Run only the pre-activation browser test.
41. Pause registration with the reversible cache-backed Laravel maintenance-mode/Dokploy API-app env procedure and compare the literal ordinary-registration-503 record.
42. Capture the host-persisted preactivation manifest; run the literal empty/non-empty rollout branch; explicitly apply/verify/census any new tenants; then verify/census the full fleet through committed template `cmp` gates.
43. Fetch the worker's full current env, update only the flag through tRPC `application.update`, re-read, redeploy, and verify.
44. Repeat the full-env update/re-read/redeploy for API.
45. Repeat the full-env update/re-read/redeploy for scheduler.
46. Compare the aggregate inside-container ON evidence byte-for-byte with the expected heredoc.
47. Capture and compare the literal RFC-3339 timestamp-format record; use the maintenance-secret cookie to execute the committed registration `curl`, validate the host response with `jq`, persist/transfer/compare exactly one UUID and its literal registration-response record, capture every structured stderr `WLOTA1A-RESEED` marker from the log window, and `cmp` the initial activated `APPLIED` record against the committed manifest template.
48. Explicitly reseed that tenant and `cmp` the separate activated `ALREADY_APPLIED` marker.
49. Capture the postactivation manifest; require exactly the preactivation fleet plus the disposable tenant.
50. Re-copy helpers/manifests into the replacement API; run full verify and day-one/lot/phantom `cmp` gates.
51. Run the exact configuration-cache, permission-cache, and Horizon command/exit/heredoc-`cmp` gates; execute `artisan up` and compare the literal 422 resume record; restore only the saved maintenance env lines through Dokploy, redeploy, require exact full-environment equality with the intended restoration, and compare the literal maintenance-restoration/422 record.
52. Run post-activation API and Playwright duplicate/B2/location gates.
53. Run the manifest §4 checklist.
54. Archive application records, Push 1–5 SHAs, UUID manifests, tenant-database mappings, per-tenant statuses/full outputs, backups, snapshots, literal migration and registration comparisons, raw marker aggregates, expected-marker templates, censuses, pre-trigger deployment-ID baselines, automatic/fallback web-deployment records, structured registration stderr, generated hashes, served asset/fingerprint comparisons, OFF/ON/rollback comparisons, maintenance restoration, cache/Horizon comparisons, and reviewer verdicts under `/root/wlota1a/`.
55. Dispatch W-LOT-A-1b separately.
56. Dispatch W-LOT-A-1c only after W-LOT-A-1b acceptance.

## 17. Final verification checklist

- [ ] Planning SHA `c76435df4a98193dfacb80ad9c25839169179509` and its commit subject are recorded.
- [ ] Actual `DISPATCH_SHA` is recorded and named seams are re-censused after drift.
- [ ] Only Tasks 1, 2, and 6 are implemented.
- [ ] The round-7 gate and resolved U-1 fact sheet are read in full and every item is CLOSED.
- [ ] All prior closures and rejected false positives are preserved.
- [ ] RD2, Q4, and Q10 remain quoted verbatim.
- [ ] A-1a retains its no-hold boundary.
- [ ] A-1b implements only request/hold and `requested → recalled`.
- [ ] The complete A-1b ownership list is reproduced byte-for-byte in §§2 and 15, including POS sale, direct batch transfer, and StockTransfer allocation.
- [ ] Delivery, write-off, stock-count, and return eligibility are deferred to named later lanes.
- [ ] A-1c is the sole release/reject owner.
- [ ] Every changed constructor uses correct FQCNs and readonly promotion.
- [ ] NEW command constructors call `parent::__construct()`.
- [ ] `BatchActionAccess` accepts only view, traceability, delete, and recall.
- [ ] No Task-1 middleware is added to create/update/transfer/write-off.
- [ ] Push 3 is inert for existing and newly registered unmarked tenants.
- [ ] Generic flag-off seeding gives the exact legacy matrix only to fresh/unmarked tenants.
- [ ] A flag-off seeder run on a tenant carrying the team-scoped `general_manager` / `provisioning_source=w-lot-a-1a` marker performs no role synchronization, does not re-grant manager `batches.recall`, and preserves the canonical delta plus custom grants.
- [ ] Both named `RolesAndPermissionsSeederMarkedTenantTest` methods pass in PostgreSQL.
- [ ] Existing-tenant role changes occur only through the Push-4 delta.
- [ ] `SYNC_PERMISSIONS_ON_BOOT=true` is recorded from the API application and inside the API container.
- [ ] Every source push is treated as an API boot seeder run.
- [ ] Host permission/role/grant snapshot `cmp` proves Pushes 2, 3, and 5 are inert for existing tenants while enforcement is false.
- [ ] The Push-5 post-delta full snapshot proves custom grants are unchanged, and its canonical snapshot matches the committed preserved-state heredoc.
- [ ] Empty scope never becomes unrestricted.
- [ ] Restricted reads expose only selected-location stock/history.
- [ ] Selected B2 is persisted as `allowed_location_ids=[B2]`.
- [ ] Batch totals are scoped four-decimal strings.
- [ ] Trace DTOs preserve every current response field and envelope.
- [ ] BatchExpiry imports no Document/POS persistence model.
- [ ] Denied recall/delete tests compare stock, reservation, history, and GL snapshots.
- [ ] Manager loses global recall and gains request permission.
- [ ] Marked general manager has recall and all-location Treasury authority.
- [ ] PostgreSQL and SQLite reject global marked roles.
- [ ] One marker is permitted per tenant team.
- [ ] Role delta lock/read/write/verify is one transaction.
- [ ] Delta restores Spatie team context.
- [ ] Marked-role name updates return `PROVISIONED_ROLE_READ_ONLY`/422.
- [ ] Marked-role permission updates return `PROVISIONED_ROLE_READ_ONLY`/422.
- [ ] Marked-role delete returns `PROVISIONED_ROLE_READ_ONLY`/422.
- [ ] Rejected updates preserve name, grants, role ID, and pivot cardinality.
- [ ] Roles API emits marker-derived `is_provisioned_read_only`.
- [ ] `RoleData` has only snake-case `public readonly` wire properties.
- [ ] Role index/show serialize through `RoleData` without changing `id`, `name`, `guard_name`, `permissions`, `users_count`, `created_at`, or `updated_at`.
- [ ] `RoleIndexResponseContractTest::test_roles_index_wire_keys_are_unchanged_and_is_provisioned_read_only_is_marker_derived` passes in PostgreSQL.
- [ ] `LotActionReseedMarkerTest::test_fresh_registration_writes_activated_applied_marker_to_stderr_channel_despite_artisan_buffering` passes in PostgreSQL.
- [ ] Generated TypeScript contains `is_provisioned_read_only: boolean`.
- [ ] `RolesPage.tsx` is in the Task-6 inventory and Push-5 ledger.
- [ ] Protected edit/delete affordances are absent.
- [ ] Protected modal submission is blocked independently.
- [ ] An unmarked `general_manager` name is not treated as marker-protected.
- [ ] `LotActionPermissionDelta` is the sole protected-role definition writer.
- [ ] Settings → Users is the sole assignment surface.
- [ ] Settings → Roles is read-only for the protected role.
- [ ] CLI normalizes `true`, `1`, `'1'`, and `'true'`.
- [ ] Apply and verify string-one wrapper tests pass in PostgreSQL.
- [ ] Neither/both modes still exit 2.
- [ ] U-1 confirmation executes before source-push dispatch and matches all four exact Dokploy application records.
- [ ] Staging is recorded only as `separate_applications`; no compose branch or Push 6 exists.
- [ ] Full-env tRPC updates, `application.one` readback, and `application.redeploy` execute separately for worker, API, and scheduler.
- [ ] INITIAL-OFF and ROLLBACK are independent self-contained blocks with exact IDs/appNames, false invocations, replacement-container readback, and pre/post API permission snapshot `cmp`.
- [ ] Every required application has both Dokploy env readback and inside-container `printenv` readback.
- [ ] `PUSH1_SHA` through `PUSH5_SHA` are assigned at promotion and persisted under `/root/wlota1a/`.
- [ ] Every later SHA gate sources the host ledger and the served web build equals `PUSH5_SHA`.
- [ ] Host backup shell defines and consumes `PHASE`, sources the durable complete marker helper, and invokes the literal-template `cmp` gate.
- [ ] Every phase runs the tenant/database producer inside the current API using inherited `Tenant::getTenantKey()` at [Tenant.php:42](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Database/Models/Tenant.php:42) and canonical `Tenant::getDatabaseName()` at [Tenant.php:264](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Tenant/Domain/Tenant.php:264); `/root/wlota1a/manifests/<PHASE>/tenant-databases.tsv` has the same count and UUID set as `expected-tenants.txt`, contains no empty database column, and passes an explicit `fail_gate`.
- [ ] No tenant database name is synthesized from a UUID; every permission snapshot, preserved-state query, registration-pause/rollback verification, and backup loop reads `<uuid>\t<database>` from the phase mapping.
- [ ] Every manifest, helper definition, snapshot, and evidence file survives under `/root/wlota1a/`.
- [ ] Every replacement-container shell receives required files through explicit `docker cp` and re-sources its helper.
- [ ] Every migration pass uses fixed-string exact-line checks.
- [ ] Literal valid migration output is compared with `cmp`.
- [ ] Every static escaped-output ERE in §11 was replaced by fixed-string matching.
- [ ] OFF evidence is an aggregate file compared byte-for-byte with a heredoc.
- [ ] ON evidence is an aggregate file compared byte-for-byte with a heredoc.
- [ ] Push-4 day-one execution is a complete command sequence.
- [ ] Rollout recensus executes both empty and non-empty branches with explicit apply, verify, census, raw-marker aggregation, copy-back, and `cmp` commands.
- [ ] `fail_gate` is defined once in §11.2, sourced by host/container/workstation gates, and every positive `cmp -s` has an explicit `|| fail_gate '<name>'` branch.
- [ ] Every §11 gate exits non-zero on failure.
- [ ] Every expected tenant manifest is non-empty.
- [ ] Every expected UUID has exactly one success marker.
- [ ] No missing, duplicate, unexpected, failed, skipped, or errored marker passes.
- [ ] Every per-tenant delta, reseed, census, migration, snapshot, and backup command captures numeric status and complete tenant-specific output without early loop exit; container evidence is copied to the host before status validation or bad-output rejection.
- [ ] Every raw prefixed marker is aggregated across all tenant outputs before validation; every raw `WLOTA1A-CENSUS-*` line is collected into one census aggregate and no expected-tenant grep can hide an unexpected UUID.
- [ ] Every backup, migration, delta, verify, census, rollout, timestamp, registration response/pause/resume, maintenance restoration, served asset/fingerprint, reseed, and activation record is compared with a committed literal-template expected record through `cmp`.
- [ ] Push 2 and Push 4 have verified non-zero host backups.
- [ ] Delta apply and verify have exact per-tenant markers.
- [ ] No fleet-wide generic reseed runs while enforcement is false.
- [ ] Every day-one, lot-drift, and phantom summary is executable and gated.
- [ ] Manifest §4 appears verbatim.
- [ ] Both create affordances use one `canCreate`.
- [ ] Every Task-6 exact Vitest/Playwright symbol exists.
- [ ] Roles-page read-only Vitest symbols exist.
- [ ] Generated permission map matches canonical helpers.
- [ ] Fresh generated DTO type matches the committed generated type.
- [ ] `pnpm typecheck` and `pnpm typecheck:e2e` pass.
- [ ] Pre-activation browser evidence contains no flag-on API expectation.
- [ ] Post-activation browser evidence runs only after API activation.
- [ ] Served asset hash changes.
- [ ] Before Push 5, the complete set of existing web `deploymentId` values is persisted to a host evidence file; after the push, selection requires `description="Commit: ${PUSH5_SHA}"` and an ID absent from that baseline, with `createdAt >=` the whole-second push timestamp used only as a secondary bound.
- [ ] Push 5 pins the selected new `deploymentId`, polls only it to `status=done`, and compares `(deploymentId, description, status)` with a committed template before the committed served asset/fingerprint evidence `cmp`; the normal path performs no manual web redeploy.
- [ ] The stale-edge incident fallback first revalidates the automatic baseline correlation, persists its own pre-redeploy ID baseline, and applies the identical description/not-in-baseline/inclusive-bound/pinned-ID selection to its one permitted manual redeploy.
- [ ] Served bundle contains `wlota1a-batch-permission-gating-v1`.
- [ ] Served `build_sha` equals the topology-selected `CANDIDATE_SHA`.
- [ ] Activation evidence is exactly worker → API → scheduler.
- [ ] Postactivation and rollback configuration-cache, permission-cache, and Horizon commands have explicit exit rules and committed heredoc `cmp` evidence.
- [ ] Registration pause uses the reversible cache-backed maintenance-mode env/redeploy procedure; ordinary registration is 503 while paused, the operator request uses the secret bypass cookie, resume/restoration returns ordinary requests to 422 validation, the final full environment equals the intended restored environment, and all three states have literal-record `cmp` gates.
- [ ] Both disposable registrations use exact `curl` JSON producers, host-captured/`jq`-validated responses, exactly one `.data.user.tenantId`, durable one-line manifests, explicit `docker cp`, and named `cmp` failures.
- [ ] Initial activated registration writes through `Log::channel('stderr')->info()`, aggregates the raw structured-log marker despite `Artisan::call()` buffering, and passes an executable `APPLIED` marker `cmp`.
- [ ] Explicit activated reseed emits and passes a separate `ALREADY_APPLIED` marker `cmp`.
- [ ] Rollback updates the three separate applications through full-env tRPC calls, redeploys worker → API → scheduler, and proves aggregate OFF output.
- [ ] Rollback full snapshot equality preserves custom grants and its canonical snapshot matches the committed preserved post-delta heredoc; manager recall is not restored.
- [ ] All three required reviewers return ACCEPT with zero BLOCKER and MAJOR findings.
