# Codex plan gate r4 — RBAC waves 2, 3, 4 plans rev 4 (gpt-5.6-sol, high, read-only, 2026-09-11)

Repository HEAD: `aa90f77bd` (`git rev-parse --short HEAD`).

Shared-checkout `dev` inspected at `33796cc08`. Worktree was clean. Artisan was not booted in the audit worktree; executable router work used the shared checkout as instructed.

## Wave 2

### Rev-3 closure table

| r3 item | r4 status | Evidence |
|---|---|---|
| B1 — missing denial-recorder body | **NOT CLOSED** | A body exists, but it cannot implement all three producers and is not a complete class: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1807-2010,2155-2168`. |
| B2 — impossible container census | **PARTIAL** | The AST count is satisfiable, but the claimed relocation pin is not: `…rbac-wave-2.md:2112-2151`. |
| B3 — ungated `Gate::authorize()` omitted | **PARTIAL** | `GateEvaluated` correctly covers the thrown-gate path, but the common recorder remains unusable by the two response-producing middleware paths: `…rbac-wave-2.md:2012-2071,2155-2168`. |
| B4 — invalid `AuditService::record()` call | **CLOSED against the current producer** | `recordTenantWide()` now uses Wave 1’s current signature: `…rbac-wave-2.md:3112-3130`; producer at `…rbac-wave-1.md:5564-5572`. |
| B5 — unpersistable `ApiTokenRevoked` | **CLOSED against the current producer** | Event carries tenant/actor and subscriber uses `persistTenantWideEvent()`: `…rbac-wave-2.md:3238-3337`; producer at `…rbac-wave-1.md:5616-5648`. |
| M1 — wrong last-admin-floor invocation | **CLOSED against current 0b names** | Actual `$user/$roleName/$actor` shape is specified: `…rbac-wave-2.md:938-950,3831`. |
| M2 — incompatible queue oracles | **CLOSED** | Real database-queue and fake-queue cases are separated: `…rbac-wave-2.md:3341-3345`. |
| M3 — conditional membership census | **CLOSED** | `UserController::activate` is the single fixed exemption. |
| M4 — conditional file paths | **PARTIAL** | The Horizon path is fixed, but `TokenScopeData.php` remains subject to a Phase-0 removal decision: `…rbac-wave-2.md:3834`. |
| m1 — floor factory signature | **CLOSED** | Two-argument form is consistently stated. |
| m2 — withdrawn denial middleware in inventory | **CLOSED** | Inventory now names `DeniedAbilityTrail`, not the withdrawn middleware. |

### BLOCKER

1. **The denial-recorder implementation still cannot cover the three accepted producers.** `recordGateDenial()` rejects every call whose `$previous` is not an `AuthorizationException` at `…rbac-wave-2.md:1882-1915`. The plan nevertheless requires `RequireAnyPermission` and `EnforceTokenScope`—which build 403 responses without throwing—to call it directly and “state their own cause” at `:2155-2168`. The method has no cause parameter, recomputes cause itself at `:1991-1995`, and the plan contains no direct call-site implementation for either middleware; the only printed invocation is the exception-renderer call at `:2100-2103`.

   The advertised “full” class is also incomplete as pasted. Its only import is `RedisFactory` at `:1812-1815`, while it uses `Request`, `Response`, `Throwable`, `AuthorizationException`, `User`, `Log`, `EventDispatcher`, `CurrentAccessTokenId`, `CompanyContext`, and the resolver. It first declares a one-argument constructor at `:1850`, then prints a replacement six-argument constructor outside the class block at `:2000-2010`. This cannot be handed to an implementer as the claimed complete body.

### MAJOR

1. **Lane 2a has an unresolved dependency inversion.** `Phase 0.4.4` changes `RoleController::userRoles()` to call `EffectivePermissionResolver::forTarget()` at `…rbac-wave-2.md:1236-1245`, but the resolver is not introduced until `Phase 0.4.6` at `:1428-1786`. The plan says the “default order runs 2a-6 first” at `:1245`, contradicting the document and ordinal order. Either the resolver must move before `0.4.4`, or that commit must carry the resolver files and tests.

2. **`CreateServiceAccountRequest` remains a partial fragment.** Its authorization behavior and typed 403/422 reuse are comments, not an `authorize()` or failed-authorization implementation, at `…rbac-wave-2.md:2794-2801`. The dedicated role-action table later reintroduces the obsolete undefined name `$currentUser` at `:2845-2849`, despite the corrected `$actor = $this->user()` contract at `:2803-2822`.

3. **The single-site census does not pin relocation as claimed.** It counts one `Container::getInstance()` AST call and compares the call’s source line with a marker whose literal value is also just `Container::getInstance()` at `…rbac-wave-2.md:2114-2143`. Relocating the call to any other line containing the same expression still passes. The accepted composition-root exception is preserved; only the claimed census proof needs correction.

4. **The Identity service-account route stack duplicates global scope middleware.** Task 2b-3 attaches `EnforceTokenScope` to the global `api` group and priority list at `…rbac-wave-2.md:2691-2761`, while Task 2b-4 explicitly adds it again inside a group that already contains `api` at `:2787-2788`. The target stack should state whether duplicate execution is intended and test it, or remove the redundant explicit entry.

### MINOR

1. The denial-test description says “one positive and six negatives” while the surrounding plan describes multiple positives plus the robustness case: `…rbac-wave-2.md:2170-2189`.

2. The notification census calls the result “five selectors” but later enumerates six basename-resolved paths. Reconcile the selector-versus-caller terminology at `…rbac-wave-2.md:2617-2685`.

3. Phase 0 still retains known-signature forks for `PermissionDefinition::legacy` and generated inventory output even though the currently pinned producer can be read exactly: `…rbac-wave-2.md:2290-2305`. Re-pin rather than preserve dead alternatives.

**Wave 2 verdict: CHANGES-REQUIRED.** The database, floor, resolver, seat, UI, event and 2b-order design is largely sound, but denial auditing—the accepted write-denial control—still lacks a coherent executable contract.

## Wave 3

### Rev-3 closure table

| r3 item | r4 status | Evidence |
|---|---|---|
| B1 — classifier crashes on closures | **CLOSED** | Extracted classifier completed with zero reflection errors. |
| B2 — classifier returns 138, not 176 | **CLOSED** | It reproduced all seven audit buckets and baseline 176. |
| B3 — matrix/fixture machinery is prose | **NOT CLOSED** | More code is printed, but it references nonexistent services/enum cases, depends on undeclared test properties, and still supplies no `RolePermissionMatrixTest` body: `…rbac-wave-3.md:1592-1647,1696-2024,2026-2221,2307-2324`. |
| M1 — first-red claim is false | **NOT CLOSED** | The replacement contradicts its own decrement protocol: `…rbac-wave-3.md:905-908`. |
| M2 — abbreviated add lists | **PARTIAL** | Cluster/POS lists are now explicit, but later module/caller lists are still generated at Phase 0 or commit time: `…rbac-wave-3.md:1361-1374,1423-1475,1535-1537`. |
| M3 — unusable `$SCRATCH` | **CLOSED** | It now uses `CLAUDE_SCRATCHPAD` or `mktemp` with guards. |
| m1 — POS FormRequest attributed to wrong controller | **CLOSED** | It is correctly assigned to `ManagerPinController` at `Phase 0.6.37`: `…rbac-wave-3.md:274-320,1067-1072`. |

### BLOCKER

1. **The enforcement ratchet cannot satisfy its own workflow.** The plan says `ENFORCEMENT_STYLE_CEILING` is committed at the final value `0`, making the first test red until all 176 sites are converted, at `…rbac-wave-3.md:905-907`. The next paragraph says every cluster decrements the ceiling as a running total at `:908`, and the recipe repeats that at `:900`. A constant already at zero cannot be decremented 176 times. As written, every intermediate cluster commit remains red and the decrement instruction is impossible. Use a 176→0 running ceiling, with the pre-implementation red observed before the first commit.

2. **The claimed complete route-action fixture implementation is invalid against `dev`.**

   - It constructs `WorkOrderStatus::Open` and writes `notes` at `…rbac-wave-3.md:1833-1844`; `dev:apps/api/app/Modules/Workshop/WorkOrder/Domain/Enums/WorkOrderStatus.php:11-23` has no `Open`, and the work-order factory/model uses `internal_notes`.
   - It writes a `Batch.status` field at `…rbac-wave-3.md:1913-1930`; `dev:apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:19-40` instead exposes `is_active`, `is_expired`, and `is_recalled`.
   - It calls nonexistent `MenuService`, `PromotionService`, and `ReplenishmentRequestService` classes at `…rbac-wave-3.md:1901-1909,1948-1959,1981-1999`. On `dev`, menu creation is directly in `MenuController::store()` (`dev:apps/api/app/Modules/Menu/Presentation/Controllers/MenuController.php:73-94`), promotion creation is in `PromotionController::store()` (`dev:apps/api/app/Modules/Promotion/Presentation/Controllers/PromotionController.php:83-95`), and the actual service names are `MenuResolutionService`, `PromotionManagementService`, and the replenishment capture/query/fulfillment services.
   - `RouteActionFixtureContractTest` invokes `$this->company` and `$this->actor` at `…rbac-wave-3.md:2148-2156`, but `dev:apps/api/tests/TestCase.php:9-49` declares neither. Deferring this to Phase 0 at `…rbac-wave-3.md:2221` means the printed test is not the claimed implementation.
   - `RolePermissionMatrixTest.php` itself is still only an algorithm at `…rbac-wave-3.md:1592-1647,2307-2324`; no class body is supplied.

### MAJOR

1. **The first-red closure is not merely editorial.** Committing a zero ceiling during the first cluster makes the branch intentionally red through 35 further conversion commits, contradicting the plan’s per-cluster test/reviewer gate and repository CI expectations: `…rbac-wave-3.md:893-908,2378-2381`.

2. **Several “exact” commit lists remain future-generated.** `SystemRoleName` callers are enumerated only at commit time (`…rbac-wave-3.md:1361-1374`), permission-literal commits use a variable module tail (`:1423-1475`), and the deprecated-key manifest ownership is adjusted during Phase 0 (`:1535-1537`). These may be legitimate producer-dependent inventories, but the dispatch brief must contain their resolved outputs before implementation starts.

3. **The cleanups inventory retains the stale POS premise.** It still says `fetchDiscountPermissions` “exists and is never called” and commands Wave 3 to wire it at `…rbac-wave-3.md:196`. The same plan correctly proves it is already production-wired at `:326-347`; code confirms calls at `dev:apps/pos/src/stores/operatorStore.ts:128-145,269,294-296,368-369,446`. The only remaining ladder is `:354-363`.

### MINOR

1. The plan says four modules use `app(...)`, but its code contains three such service calls; Coupon deliberately uses none: `…rbac-wave-3.md:1901-2024`.

2. The “all four PHP blocks pass `php -l`” claim at `…rbac-wave-3.md:2596` proves syntax only. It must not be presented as evidence that the referenced classes, enum cases, fields, or test fixtures resolve.

**Wave 3 verdict: CHANGES-REQUIRED.** The route classifier, CSV arithmetic, module order, POS-last allocation and Manager-PIN attribution are now verified, but the ratchet and the wave’s final regression matrix are not executable as specified.

## Wave 4

### Rev-3 closure table

| r3 item | r4 status | Evidence |
|---|---|---|
| B1 — invalid/incomplete YAML | **CLOSED** | Both parsers accept the extracted complete manifest. |
| B2 — 58/57 contradiction | **CLOSED** | 58 retained, 57 executable, EC-22 withdrawn is represented consistently. |
| B3 — runner is prose | **NOT CLOSED** | A runner exists, but its selectors can silently produce empty green runs and its staging safety is outside the runner: `…rbac-wave-4.md:1205-1373`. |
| B4 — invalid `tenants:run` command | **CLOSED in command shape** | Registered `rbac:row-census` and `rbac:dry-run-probe` are supplied: `…rbac-wave-4.md:1452-1518`. |
| B5 — unsafe staging replay | **NOT CLOSED** | Isolation preflight and Horizon handling remain separate prose, not executable runner behavior: `…rbac-wave-4.md:1521-1592` versus `:1233-1245,1297-1323`. |
| M1 — non-exact Task 4-2 add list | **NOT CLOSED** | The variable tail is still generated later: `…rbac-wave-4.md:981-1005`. |
| M2 — contradictory library extraction | **NOT CLOSED** | The printed library omits items it claims to extract, and no onboarding re-point body is supplied: `…rbac-wave-4.md:1101-1161`. |
| M3 — wrong leg-reference arithmetic | **CLOSED** | Parsed result is 22 references across 22 rows. |
| M4 — race-prone queue replay | **PARTIAL** | The sequence is better specified, but it is not integrated and has no cleanup trap: `…rbac-wave-4.md:1563-1589`. |
| m1 — residual placeholders | **NOT CLOSED** | `<N>`, an ellipsis password, and an unresolved R8 route remain: `…rbac-wave-4.md:310-311,333,1050`. |
| m2 — R4 retired-branch contradiction | **CLOSED** | Browser and PG assertions are now separated: `…rbac-wave-4.md:1026-1041`. |

### BLOCKER

1. **Selector validation can silently certify an empty campaign.**

   - Repeated `--leg` overwrites `ONLY_LEG` at `…rbac-wave-4.md:1209-1221`; the only multiplicity check looks for a comma at `:1233-1237`. I executed `--reuse --leg R2 --leg R3`; it did not produce `reason=reuse_is_single_leg`.
   - An unknown selected leg is never validated. The only unknown-leg check validates manifest references at `:1261-1266`, not `$onlyLeg`. The declared-leg loop skips every leg, lane/manual work is suppressed at `:1274-1292`, the process-substitution loop consumes nothing at `:1325-1357`, and the script exits with `red=0` at `:1372-1373`.
   - Unknown `--row` behaves equivalently: no matching row produces an empty green ledger.
   - The asserted contract at `:1381,1667` is therefore false.

2. **`--lanes` unexpectedly runs all destructive browser legs.** Task 4-2 advertises `--lanes pg,sqlite,redis,vitest,posvitest,shell` as “the non-browser rows” at `…rbac-wave-4.md:971-975`. The implementation filters only records whose kind is `LANE` at `:1327-1330`; all nine `LEG` records still execute. The explanatory text confirms this at `:1377`. A tester asking for non-browser verification must not create and mutate a campaign tenant.

3. **The staging safety contract is not part of the supplied runner.** The runner checks only that `--ssh`, `--api-container`, and `--staging-tenant` are non-empty at `…rbac-wave-4.md:1233-1243`, then performs only the generic HTTP preflight at `:1245`. It never executes the tenant/database/Redis checks printed separately at `:1550-1562`, never registers the claimed disposable tenant, never proves the databases are empty, and never runs the supervisor pause/resume sequence at `:1563-1589`.

   Even the printed database “empty” check only verifies that one database name exists at `:1555-1557`; it does not inspect either database for application rows. The safety claim in the r4 change log at `:1869-1870` is therefore not executable.

### MAJOR

1. **The staging shell path conflates host and container roots.** `--remote-root` is used as an SSH-host checkout path for PG/Redis at `…rbac-wave-4.md:1305-1310`, then reused inside `docker exec` at `:1311-1313`. No contract proves `/var/www/autoerp` is also the container path. Use a separate container workdir or `docker exec -w`.

2. **The Horizon recovery sequence can leave a supervisor paused.** The plan pauses `identity-revocation`, runs several fallible commands, and only then resumes at `…rbac-wave-4.md:1563-1586`. There is no `trap`/`finally` cleanup. A failed SSH/test command can strand the supervisor despite the prose assertion at `:1588`.

3. **The R8 MCP probe still contains a placeholder and discards the denial reason.** The URL contains `<key the OWNER holds, the TOKEN excludes>` at `…rbac-wave-4.md:1046-1051`, despite the claim that placeholders were removed. `curl -o /dev/null` also asserts only status, contradicting the data-meaning rule at `:122`; it cannot distinguish token narrowing from another 403.

4. **The manifest guard is still only six prose rules.** `RbacCampaignManifestCoverageTest.php` is staged at `…rbac-wave-4.md:981-988`, but no implementation body is supplied; the rules are described at `:956-962`. This matters because several manifest homes intentionally name files to be created in Wave 4, including `GeneralManagerLocationScopeTest.php`, `PermissionRenameTest.php`, and `ModuleGatingOrderTest.php`. Task 4-2’s residual “coverage delta” must be resolved before dispatch, not left as a future factory at `:991-1005`.

5. **The shared-library extraction claim is still inaccurate.** `campaign-lib.sh` claims to extract the unknown-argument arm and exit-code contract at `…rbac-wave-4.md:1108-1113,1159`, but the supplied library contains only a pair consumer and preflight functions at `:1121-1156`. Unknown-argument handling and final exit remain in `campaign-rbac.sh`; the changed `campaign-onboarding.sh` body and `CampaignLibParityTest.sh` body are not supplied. The `bash -n` result therefore does not prove onboarding parity.

6. **Task 4-4 cannot produce uniquely addressable fix commits.** Every discovered production fix is assigned the identical subject prefix `Phase 0.7.4` at `…rbac-wave-4.md:1411-1420`. That meets the superficial grammar in `AGENTS.md:15-16`, but multiple unrelated fixes become indistinguishable in phase history. Allocate `0.7.4a`, `0.7.4b`, or a reserved range before the campaign begins.

### MINOR

1. The evidence template still contains `<N>` at `…rbac-wave-4.md:328-350`.

2. Wave 4’s Wave-3 ordinal inventory is stale. It says Wave 3 ends at `0.6.46` with a tail beginning at `0.6.47` at `…rbac-wave-4.md:80,199-208`; Wave 3 rev 4 assigns its matrix to `0.6.47` and the literal-conversion tail from `0.6.48` at `…rbac-wave-3.md:112-115,2320-2324`.

3. The staging checklist permits `not replayed (declared)` at `…rbac-wave-4.md:1687`, but the runner writes `not replayed — destructive leg, single-run contract` at `:1316-1317`. Pin one exact ledger value.

**Wave 4 verdict: CHANGES-REQUIRED.** The manifest arithmetic is genuinely repaired, but the supplied runner can silently green empty selections, unexpectedly run destructive legs, and reach staging without executing its isolation preflight.

## Cross-plan consistency

1. **Current producer consumption is aligned, but all three plans require a successor re-pin before dispatch.** The currently committed pins are Wave 0b rev 6.3 `5257eb0f1` and Wave 1 rev 6.3 `949dfabdf`; the inspected downstream uses of `PermissionWriteLock`, `recordTenantWide()`, `persistTenantWideEvent()`, `PermissionRegistry`, `TenantFleetRunner`, `SyncScope`, and the scaffold signature match those current declarations (`…rbac-wave-2.md:15-49`; `…rbac-wave-3.md:31-44`; `…rbac-wave-4.md:15,33-41`). Because both producer plans have now received CHANGES-REQUIRED, Wave 2/3/4 must re-pin to their successor revisions and re-run the signature census. I do not re-report the producer findings themselves.

2. **`EffectivePermissionResolver` is correctly owned by Wave 2a.** Wave 2 creates it at `…rbac-wave-2.md:1428-1786`; Wave 3 consumes it only after Wave 2 at `…rbac-wave-3.md:42,50-55`; Wave 4 attributes it to 2a at `…rbac-wave-4.md:38`. No downstream plan incorrectly treats it as a Wave-1 deliverable.

3. **Lane 2b’s commit order is correct.** `0.5.2` census → `0.5.3` scope → `0.5.4` limits → `0.5.5` revocation/TTL → `0.5.6` service-account surface → `0.5.7` issuance → `0.5.8` POS → `0.5.9` frontend is dependency-safe at `…rbac-wave-2.md:2481-2502,2617-3500`.

4. **The programme phase families do not collide.** `0.4`, `0.5`, `0.6`, and `0.7` are distinct from 0a’s `0.1`, 0b’s `0.2`, and Wave 1’s `Phase 1.*`; this conforms to `AGENTS.md:15-16`. Wave 4’s stale Wave-3 tail and repeated `0.7.4` fix commits still require correction.

5. **The 17 Q-w decisions are engineering rulings, not owner policy.** Q-w2-1…6, Q-w3-1…6, and Q-w4-1…5 remain consistent with the accepted spec. None should be escalated. The implementation defects above do not create new policy questions.

## Citation audit

Verified against code or executable evidence:

- Login resolves the user at `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-256` and reaches `Hash::check` at `:258`; the planned principal-kind test must precede it.
- POS population is `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56`; PIN hashing is at `:87-90`.
- The sole authorization read of `MembershipRole::isOwner()` is `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`.
- `fetchDiscountPermissions()` is wired at `dev:apps/pos/src/stores/operatorStore.ts:128-145,269,294-296,368-369,446`; the residual role ladder is `:354-363`.
- RFC-4180 parsing of `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv` yields 1,054 rows: 642 MIDDLEWARE, 142 AUTH_ONLY, 130 CONTROLLER, 68 SUPERADMIN_ONLY, 46 FORMREQUEST, 18 PUBLIC, 8 POLICY; baseline `130 + 46 = 176`.
- Extracting Wave 3’s generator from `…rbac-wave-3.md:402-826` and running it from the shared checkout yielded the same seven buckets, zero reflection errors, and 176 baseline rows.
- The generator reported POS as 92 baseline routes across 24 CONTROLLER classes plus one FORMREQUEST host, `ManagerPinController`: 25 distinct classes.
- POS has five route files, four used by these 25 classes, exactly as `…rbac-wave-3.md:313-320` states.
- Spec §7 declares 58 retained rows at `…catalogue-design.md:2095-2097`; EC-22 is withdrawn with its number retained at `:2136`.
- Extracted Wave 4 YAML parsed with both PyYAML and Symfony YAML: version 2, 8 lanes, 9 legs, 58 rows, 57 executable, one withdrawn, 22 leg references across 22 rows.
- Extracted `campaign-lib.sh` and `campaign-rbac.sh` were both `bash -n` clean; the runner was `shellcheck -S error` clean.
- Executed early contracts: `--reuse` without a leg, `--staging-replay` without SSH, missing `--lanes` value, and unknown top-level argument all returned the documented exit 2 outcomes.

Wrong or stale citations/statements:

- Wave 3’s cleanup inventory falsely says `fetchDiscountPermissions` is unwired: `…rbac-wave-3.md:196`.
- Wave 3’s fixture implementations cite classes/fields that do not exist on `dev`: `…rbac-wave-3.md:1833-1844,1901-1909,1913-1930,1948-1959,1981-1999`.
- Wave 3’s claim that the base `Tests\TestCase` supplies `$company/$actor` is false: `…rbac-wave-3.md:2148-2156` versus `dev:apps/api/tests/TestCase.php:9-49`.
- Wave 4’s Wave-3 phase tail is stale: `…rbac-wave-4.md:80,199-208`.
- Wave 4’s “extracted verbatim” inventory overstates what `campaign-lib.sh` contains: `…rbac-wave-4.md:1108-1113,1159`.
- Wave 2’s relocation-marker assertion overstates what its test proves: `…rbac-wave-2.md:2136-2143`.
- Wave 4’s “isolated databases are empty” statement is unsupported by its existence-only command: `…rbac-wave-4.md:1555-1557`.

No additional fully qualified `dev:apps/...:<line>` reference inspected was missing or outside the referenced file’s range; the failures above are semantic staleness or nonexistent planned artefacts rather than malformed line ranges.

## Rejected false positives

- **The CSV arithmetic is correct.** There are no unescaped-comma defects; the old `awk -F,` arithmetic was the defect.
- **The Wave-3 generator is genuinely repaired.** It executed successfully and reproduced 1,054/176 with zero reflection errors.
- **Twenty-five POS classes is correct.** Twenty-four host the 91 CONTROLLER rows; `ManagerPinController` hosts the one FORMREQUEST row.
- **`VerifyManagerPinRequest` belongs at `Phase 0.6.37`.** Its present `authorize()` is authentication-only, so converting it changes access and correctly requires its own final POS commit/reviewer.
- **`GateEvaluated` is the right observational hook.** Unlike `Gate::after`, the event listener cannot alter authorization. Preserve it.
- **The composition-root `Container::getInstance()` exception is accepted.** The problem is only that the proposed marker does not prove relocation.
- **The principal-kind design is complete in direction:** separate closed-domain and shape checks, SQLite parity, invalid-kind tests, and five-step rollback.
- **Seat coverage is complete:** all six counters and every tier, including trial, are named.
- **58 retained / 57 executable / one withdrawn is the correct interpretation.**
- **A separate RBAC campaign is acceptable.** Appending these legs to onboarding is not required.
- **EC-31 is honestly split between POS Vitest and a documented real-device replay.** A fabricated web Playwright leg would be worse.
- **The 2b reordering is correct and should not be reverted.**

## Preserve

- Principal-kind checks before both login and PIN hashing, plus `pinHolders()` human-only filtering.
- Nullable service credentials with database CHECK/trigger parity and explicit destructive rollback.
- Dedicated `/service-accounts/*` writes, generic-user typed 422 refusals, and `AssignableRole` escalation prevention.
- Notification, invitation, verification, reset, POS and human-seat exclusion censuses.
- `permission:<key>` narrowing, mandatory `tenant:` claim, explicit TTLs, unknown-scope reporting, and no `*`.
- `EnforceTokenScope` attached to the global API group, priority ordering, coverage test, and non-tenant no-op.
- The shrink-only PHPStan/ESLint role-name baselines and falsifiable token-issuance entry condition.
- `forSubject` versus `forTarget`, owned-token selector validation, self projection through the request token, and roles-view authorization.
- `roles.is_system`, `LastAdminFloor`, the shared `wlota1a:<tenant>` lock key, deterministic `roles.name` then `roles.id` order, and the full writer census.
- Stable `DomainEvent` role events, audit attribution, Redis Lua read-and-clear, and `GateEvaluated`.
- Names-only shaping for bare assigners; server-authoritative `usePermissions`; deletion of `uiAliasPermissions.ts`; generated permission union.
- Wave-2 Playwright coverage for Roles/effective-permissions and service-account/token UI.
- The executed Wave-3 router classifier, 176-site module grouping, POS last, five POS route files, per-cluster review gates, FormRequest conversion rule, MembershipRole reconciliation, §2 glossary parity, three retirement preconditions, and Sidebar `permission`→`moduleKey`.
- The complete 58-row YAML manifest, nine declared legs, 22 leg references, EC-22 representation, conditional toast/unchanged-row rules, and the separate campaign/evidence document.
- The 2b commit order and phase-family allocation.

## Owner decisions required

Only the accepted Wave-2 policy decisions require owner confirmation:

1. **OQ-1:** service principals do not count toward the last-admin floor. Confirm before Wave 2a merges: `…rbac-wave-2.md:288-293,495-994`.

2. **OQ-2:** service-token TTL 365 days, human-token TTL 90 days, POS terminal TTL one year unchanged. Confirm before `Phase 0.5.7`: `…rbac-wave-2.md:294-299,2882-2902,3375-3392`.

No Q-w item is escalated. OQ-3/OQ-4 are not reopened by these waves.

## Dispatch assessment

- **Wave 2:** not dispatchable. The denial recorder cannot serve two of its three mandated producers and the printed implementation is incomplete; the 2a resolver dependency also needs a deterministic commit order.
- **Wave 3:** not dispatchable. The executable classifier is good, but the ceiling protocol is contradictory and the matrix’s supposedly complete fixtures fail against `dev`; the matrix class itself remains unspecified.
- **Wave 4:** not dispatchable. Selector errors can silently return green, `--lanes` runs destructive legs, and the runner does not execute its staging-isolation preflight.
- **Programme:** all three plans must re-pin to the successor Wave-0b/Wave-1 revisions after the newly required producer changes land, then regenerate all Phase-0-dependent paths and signatures into the dispatch briefs.

VERDICT: CHANGES-REQUIRED