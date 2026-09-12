# Codex plan gate r3 — RBAC waves 2, 3, 4 plans rev 3 (gpt-5.6-sol, high, read-only, 2026-09-11)

Declared repository HEAD: `cbc95ecd8`.

The audit was performed read-only. Laravel route arithmetic and the supplied Wave 3 generator were run from `/Users/houssamr/Projects/syneriva/apps/erp/apps/api`. Representative PHP blocks were checked with `php -l`; the Wave 4 manifest excerpt was parsed independently as YAML.

## Wave 2

### Rev-2 closure table

| Rev-2 finding | Rev-3 disposition |
|---|---|
| B1 — open-ended `principal_kind` domain | **CLOSED.** Separate `principal_kind IN ('human','service')` and service-shape constraints are specified, both SQLite triggers carry both clauses, and raw invalid-kind insert/update cases are required on both drivers (`docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2285-2348`). |
| B2 — nonexistent scaffold CLI | **CLOSED.** Task 2a-8 now uses the producer’s positional signature and correctly hand-edits `pos.discount_unlimited` because no `--legacy` option exists (`…rbac-wave-2.md:1965-2016`). This remains correct against Wave 1 rev 6.3. |
| B3 — unbuildable 2b order | **NOT CLOSED end-to-end.** The dependency order itself is corrected, but the supplied `0.5.5` implementation cannot pass PHPStan or run because its audit call omits required arguments (`…rbac-wave-2.md:2225-2238,2829-2837`). |
| B4 — denial middleware cannot see rendered 403 | **PARTIAL.** Moving the hook to the existing render callback is correct, but the called recorder method is not supplied, the stated container census cannot pass, and ungated controller `Gate::authorize()` denials remain unidentifiable (`…rbac-wave-2.md:1788-1943`). |
| M1 — false lock-order oracle | **CLOSED.** Direct-floor and controller-path assertions now distinguish one from two advisory calls (`…rbac-wave-2.md:3444`). |
| M2 — nullable refusal role id | **PARTIAL.** The factory signature is corrected to require `int $roleId`, but the writer table still prescribes a role object/nonexistent variable where the method requires a role-name string (`…rbac-wave-2.md:591,838,923`). |
| M3 — self read bypasses token projection | **CLOSED** by the `forSubject`/`forTarget` split and self-selector dispatch (`…rbac-wave-2.md:1410-1769`). |
| M4 — unscoped token represented as empty scope | **CLOSED.** DTO assembly is based on scope presence, not merely token presence (`…rbac-wave-2.md:3447`). |
| M5 — undefined FormRequest actor | **CLOSED.** `$actor = $this->user()` precedes `AssignableRole` construction (`…rbac-wave-2.md:2530-2558`). |
| M6 — missing role-event bodies | **CLOSED.** All three role events have concrete `DomainEvent` bodies and stable names (`…rbac-wave-2.md:1156-1365`). |
| M7 — revocation left as prose | **NOT CLOSED.** Three PHP blocks now exist and pass `php -l`, but their audit contract is invalid and the subscriber/tenant attribution path is still undefined (`…rbac-wave-2.md:2675-2975`). |
| M8 — contradictory service-target census | **CLOSED.** The closed seven-write list is authoritative and reads remain allowed (`…rbac-wave-2.md:2567-2591`). |
| M9 — missing exact add lists | **PARTIAL.** Most lists are now present, but at least one remains conditional—“drop it from this list” depending on implementation—and other paths are resolved only after commands run (`…rbac-wave-2.md:2997,3000-3011`). |
| m1–m3 — rollback count, exemption count, OQ-1 timing | **CLOSED** (`…rbac-wave-2.md:3152-3174,3454-3460`). |
| m4 — producer re-pin | **REOPENED by producer movement.** The plan still pins 0b rev 6 and Wave 1 rev 6.1, not the accepted rev 6.3 producers (`…rbac-wave-2.md:11`). |

### BLOCKER

1. **Task 2a-7 still does not supply a compilable denial recorder.** `AuthorizationDenialRecorder` ends after `shouldEmit()` (`…rbac-wave-2.md:1809-1854`), while the render hook calls an undefined `recordGateDenial()` (`…rbac-wave-2.md:1881-1888`). The file inventory promises that method, but no body exists (`…rbac-wave-2.md:181`). The rev-3 change log therefore overstates closure of B4 (`…rbac-wave-2.md:3438`).

2. **The single-site container proof is unsatisfiable against current code.** The proposed census says the combined occurrences of `Container::getInstance()`, `app(` and `resolve(` must be exactly one in `bootstrap/app.php` and zero in `app/` (`…rbac-wave-2.md:1896,3169`). Current bootstrap already contains `app()` calls at `dev:apps/api/bootstrap/app.php:218,221-222,240`, and application code has existing container-helper calls such as `dev:apps/api/app/Modules/Accounting/Presentation/Requests/CreateJournalEntryRequest.php:31`. The composition-root exception itself is accepted; its purported single-site test must instead identify the exact sanctioned AST call, not count unrelated helpers or methods.

3. **The render-hook attribution rule omits the spec’s ungated `Gate::authorize()` arm.** The accepted contract requires every write-verb refusal from `can:`, `require.any.permission`, or `Gate::authorize()` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1745-1749`). Rev 3 emits only when the previous exception is the custom `PermissionDeniedException` or the route already carries `can:`/`require.any.permission:` (`…rbac-wave-2.md:1900-1909`). A normal controller-side `Gate::authorize()` on an AUTH_ONLY route has neither signal. Its positive test deliberately chooses a controller denial on a `can:`-gated route and therefore cannot expose this omission (`…rbac-wave-2.md:1920-1927`).

4. **`Phase 0.5.5` is not green in isolation.** The supplied service calls:

   `AuditService::record(eventType: ..., metadata: ...)`

   while the live signature requires `companyId`, `userId`, `eventType`, `aggregateType`, and `aggregateId` before the optional payload/metadata (`…rbac-wave-2.md:2829-2837`; `dev:apps/api/app/Modules/Compliance/Services/AuditService.php:43-50`). `php -l` passes because this is syntactically valid named-argument PHP, but execution and PHPStan fail.

5. **`ApiTokenRevoked` cannot be persisted through the stated subscriber.** The event carries principal/token/reason/status but no company or tenant (`…rbac-wave-2.md:2937-2968`), while the current subscriber’s persistence method requires a non-null company id (`dev:apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1186-1209`). The plan says only “register it” and provides no handler or tenant-wide persistence body (`…rbac-wave-2.md:2971-2974`). The accepted spec already defines the necessary company-less pattern—`recordTenantWide()` plus `persistTenantWideEvent()`—for console events (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1732-1737`). The queued job also carries no tenant/company context (`…rbac-wave-2.md:2887-2913`).

### MAJOR

1. **The last-admin writer recipe uses the wrong argument shape.** `assertRoleRemovalSurvives()` requires `(User $target, string $roleName, string $tenantId)` (`…rbac-wave-2.md:591-598`), but writer row 1 prescribes `($target, $role, $tenantId)` (`…rbac-wave-2.md:923`). Current code resolves `$user` and `$roleName`, not `$target` or `$role` (`dev:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:390-398`). This must be re-pinned after Wave 1 and written as the actual call.

2. **The EC-16a queue oracle is internally inconsistent.** It requires `QUEUE_CONNECTION=database` so the job is really pending, but also prescribes `Queue::assertPushed`, an assertion normally provided by `Queue::fake()`—which bypasses the database connection (`…rbac-wave-2.md:2974,3194-3196`). Use a real `jobs`-table/Horizon-visible assertion for the database lane, or explicitly separate a fake dispatch test from the real pending-window test.

3. **The membership census remains conditional rather than closed.** The change log claims an empty `ASSERTED_EXEMPT`, while the task says to add the activation restore “if the census flags it,” and verification allows either outcome (`…rbac-wave-2.md:2975,3193,3450`). A static census contract needs one exact map at dispatch.

4. **The “eighteen blocks, zero placeholders” claim is not exact.** `TokenScopeData.php` is conditionally retained or removed from the `0.5.7` add list (`…rbac-wave-2.md:3000-3011`), and the Horizon guard path is discovered later (`…rbac-wave-2.md:2997`). Resolve these at Phase 0 and print the resulting literal list before dispatch.

### MINOR

1. The file inventory still states `LastAdminFloorViolation::make(string $code)` although the supplied implementation is `make(string $code, int $roleId)` (`…rbac-wave-2.md:165,838`).

2. Task 2a-7’s file header still says to create `RecordAuthorizationDenial.php` and modify bootstrap middleware/priority, contradicting the rev-3 withdrawal four pages later (`…rbac-wave-2.md:1773-1779,1898,1941-1942`).

**Wave 2 verdict: CHANGES-REQUIRED.**

## Wave 3

### Rev-2 closure table

| Rev-2 finding | Rev-3 disposition |
|---|---|
| B1 — no classifier generator | **NOT CLOSED.** A syntactically valid generator is printed, but it crashes on the live router and, after bypassing the crash, does not reproduce the audit classification (`docs/superpowers/plans/2026-09-10-rbac-wave-3.md:376-617`). |
| B2 — invalid named fixtures | **CLOSED.** Fixtures are re-keyed to `METHOD uri`, with optional route names (`…rbac-wave-3.md:1210-1222`). |
| B3 — fixture lacks permission key | **PARTIAL.** The schema now has `permission` and `permission_source`, but its `generator` source is unusable until B1 is fixed (`…rbac-wave-3.md:1280-1287,1567`). |
| M1 — undefined `$SCRATCH` | **PARTIAL.** It is now assigned, but the command contains a literal `<session-id>` placeholder and is not runnable without manual substitution (`…rbac-wave-3.md:365-370`). |
| M2 — wrong locale filenames | **CLOSED.** The task stages the three `common.json` files (`…rbac-wave-3.md:1045-1070`). |
| M3 — placeholder add lists | **NOT CLOSED.** The table still uses `…`, “+ tail,” and substitution-at-commit-time; only three of twenty-four POS lists are printed (`…rbac-wave-3.md:696-711,714-722,786-809`). |
| M4 — reviewer gates not per cluster | **CLOSED.** A per-cluster tenancy plus owning-domain gate is present (`…rbac-wave-3.md:1384-1415`). |
| M5 — fixture implementations absent | **NOT CLOSED.** One factory is shown; nineteen factories, the contract-test implementation, and the unfixtured-route generator remain prose (`…rbac-wave-3.md:1224-1321`). |
| m1 — residual 127 references | **CLOSED.** The CSV result is consistently 130/46 (`…rbac-wave-3.md:233-268`). |
| m2 — wrong baseline directory | **CLOSED** (`…rbac-wave-3.md:949`). |
| m3 — Marketplace deprecation sequencing | **CLOSED in the task**, with the removal deferred (`…rbac-wave-3.md:1109,1509`). |
| m4 — producer re-pin | **REOPENED by producer movement.** The plan still pins rev 6/rev 6.1 rather than both accepted rev 6.3 producers (`…rbac-wave-3.md:11`). |

### BLOCKER

1. **The supplied generator crashes on the current live router.** It maps a closure action to `Closure::__invoke` (`…rbac-wave-3.md:522-525`) and then reflects it because `class_exists()`/`method_exists()` passes the guard (`…rbac-wave-3.md:536-537`). Executing the printed generator in the shared checkout produced:

   ```text
   ReflectionException: Method Closure::__invoke() does not exist
   ```

   The rev-3 change log’s claim that B1 has an executable full generator is false (`…rbac-wave-3.md:1565`).

2. **Even after adding only a diagnostic `Closure` exclusion, the generator does not reproduce 176.** It produced:

   ```text
   rows: 1054
     193 AUTH_ONLY
     136 CONTROLLER
       2 FORMREQUEST
     642 MIDDLEWARE
      18 PUBLIC
      63 SUPERADMIN_ONLY
   baseline: 138
   ```

   instead of the promised `142/130/46/642/8/18/68`, baseline 176 (`…rbac-wave-3.md:629-641`). One direct cause is that `$checksIn` visits `MethodCall|StaticCall` but not `NullsafeMethodCall` (`…rbac-wave-3.md:441-486`); current FormRequests use the omitted form, e.g. `return $this->user()?->can('promotions.manage')` (`dev:apps/api/app/Modules/Promotion/Presentation/Requests/StorePromotionRequest.php:15-18`). The `POLICY` bucket also disappears entirely under the current classifier.

3. **The matrix remains a prose stub.** `RouteActionFixtures` contains an ellipsis instead of methods, only `WorkshopFixture::openWorkOrder()` is shown, and the other nineteen factories are deferred to the implementer (`…rbac-wave-3.md:1230-1278`). The worked factory also references `WorkOrder` and `WorkOrderStatus` without importing them (`…rbac-wave-3.md:1238-1269`). `generate-unfixtured-routes.php` is only described as a “short sibling,” with no body (`…rbac-wave-3.md:1289-1321`). The rev-3 change log’s “supplied all three” statement is therefore false (`…rbac-wave-3.md:1577`).

### MAJOR

1. **The first-red statement is not truthful.** `EnforcementStyleRatchetTest` is described as asserting that the current set is a subset of the freshly generated full baseline and does not exceed its ceiling; on that exact full baseline it is green, not “red on the full baseline” (`…rbac-wave-3.md:684-686`). The plan must identify the actual first failing assertion.

2. **The claimed exact add lists remain templates.** Non-POS rows use abbreviated prefixes and “+ tail” (`…rbac-wave-3.md:696-711`); the run commands still contain `<Module>` (`…rbac-wave-3.md:730-737`); and twenty-one of the twenty-four POS add lists are to be formed later by substitution (`…rbac-wave-3.md:783-809`). This directly contradicts rev-3 change-log M3 (`…rbac-wave-3.md:1575`).

3. **`$SCRATCH` is still not an exact command.** It is assigned to a path containing literal `<session-id>` (`…rbac-wave-3.md:365-370`). Use the actual session scratchpad or a safe `mktemp -d` command; do not leave a replacement token in the dispatch recipe.

### MINOR

1. **The POS count needs precise wording.** Standards-compliant CSV parsing confirms 91 `CONTROLLER` rows across 24 controller classes plus one `FORMREQUEST` row hosted by a 25th controller. The FormRequest belongs to `ManagerPinController::verify`, not `PosAuthController`; the plan incorrectly says it gates PosAuth and stages it with the PosAuth test (`…rbac-wave-3.md:773,795-801`; `dev:apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php:33-40`). The allocation may still attach the route/FormRequest change to that commit, but the citation and test ownership must say so accurately.

**Wave 3 verdict: CHANGES-REQUIRED.**

## Wave 4

### Rev-2 closure table

| Rev-2 finding | Rev-3 disposition |
|---|---|
| B1 — rerun not tenant-scoped | **PARTIAL.** The fleet calls now use R0’s tenant, but the `tenants:run` command is invalid and the query-log proof is not implementable as written (`docs/superpowers/plans/2026-09-10-rbac-wave-4.md:745-766`). |
| B2 — no `--leg` selector | **PARTIAL.** The interface and outcomes are specified, but no parser/runner implementation or focused shell test is supplied (`…rbac-wave-4.md:115-133,685-690`). |
| B3 — scalar manifest cannot express multiple homes | **PARTIAL.** `homes[]` is the correct model, but the supplied manifest excerpt is invalid YAML and incomplete (`…rbac-wave-4.md:380-519`). |
| B4 — destructive legs repeat | **PARTIAL.** First-class legs and dedup semantics are correct in design, but remain prose in the absent runner body; the supporting arithmetic is also wrong (`…rbac-wave-4.md:386-444,583,687`). |
| B5 — local-only “staging” replay | **NOT CLOSED safely.** SSH lanes are described, but no isolated staging database/Redis/tenant contract exists (`…rbac-wave-4.md:768-810`). |
| M1 — EC-32d cwd | **CLOSED.** `shell.cwd` is now `apps/api` (`…rbac-wave-4.md:400-404,506-510`). |
| M2 — incomplete manifest | **NOT CLOSED.** The “complete” material is a prose table, not complete YAML (`…rbac-wave-4.md:518-584`). |
| M3 — wildcard add list | **NOT CLOSED as an exact plan list.** It is replaced by a command whose output must later be pasted, not by literal paths (`…rbac-wave-4.md:611-626`). |
| M4 — inaccurate library extraction | **PARTIAL.** The detailed modified-files row is accurate, but the file inventory and not-touched section still contradict it (`…rbac-wave-4.md:146,165,170`). |
| M5 — R4 database assertion has no lane | **PARTIAL.** Moving it to PG is correct, but the remote PG contract does not identify an isolated database or the campaign tenant (`…rbac-wave-4.md:650-662,774-784`). |
| m1 — wrong vertical | **CLOSED.** R0 uses `parapharmacy`, matching the onboarding fixture (`…rbac-wave-4.md:263-267`; `dev:docs/qa/ONBOARDING-CAMPAIGN.md:22`). |
| m2 — producer re-pin | **REOPENED by producer movement** (`…rbac-wave-4.md:11`). |

### BLOCKER

1. **The manifest excerpt is not valid YAML.** Parsing `…rbac-wave-4.md:389-518` fails at the malformed flow mapping:

   ```yaml
   playwright:{cwd: apps/web, env: []}
   ```

   (`…rbac-wave-4.md:403`). More importantly, the YAML stops at a prose ellipsis (`…rbac-wave-4.md:518`); the material below is a Markdown summary table, not the remaining `rows:` objects with executable command strings (`…rbac-wave-4.md:521-584`).

2. **The 58/57/EC-22 contract is internally impossible.** The spec physically retains 58 register rows, of which 57 are active and EC-22 is withdrawn (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2095-2099,2136`). The YAML example includes EC-22 (`…rbac-wave-4.md:476-479`), while the “complete 57-row” table omits it (`…rbac-wave-4.md:523-583`). Verification simultaneously requires `rows | length == 57` and EC-22 to be recorded (`…rbac-wave-4.md:836-850`). Correct the two dimensions explicitly: **58 manifest/evidence register rows, 57 executable active scenarios, one `WITHDRAWN` row**.

3. **The campaign runner is still an implementation stub.** `campaign-rbac.sh` is reduced to a six-step prose algorithm, and `--leg`, leg dedup, result attribution, lane execution, accumulation, SSH dispatch, and `execution_target` have no shell implementation (`…rbac-wave-4.md:685-690`). No runner-contract test is listed beyond onboarding-library parity (`…rbac-wave-4.md:692-714`). This cannot establish the settled `--reuse --leg`, dedup, or SSH behavior.

4. **The tenant row-count command is invalid against the installed `tenants:run` interface.** The plan passes a single quoted string containing `tinker --execute=...` as the command name (`…rbac-wave-4.md:759-764`). Stancl accepts one `{commandname}` and separate repeatable `--argument`/`--option` values, then calls that exact command name (`/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:23-26,35-54`). The suggested fallback—a standalone PHP file “invoked by path”—also is not an Artisan command. The query-log instruction cannot wrap `permissions:sync-fleet` from a separate `tenants:run` process either (`…rbac-wave-4.md:764-765`). Supply an actual tenant-aware Artisan probe or instrument the dry-run command itself.

5. **The SSH staging replay has no safe target isolation.** It invokes `php artisan test` in the deployed tree without specifying an isolated staging test database, an R0 tenant selector, or a fixture lifecycle, yet claims to exercise “the deployed PG” and “real tenant directory” (`…rbac-wave-4.md:774-781`). The Redis row similarly says to use deployed `REDIS_*` while `AuthorizationDenialDedupTest` explicitly flushes its configured DB during setup/teardown (`…rbac-wave-4.md:779`; `…rbac-wave-2.md:1913-1919`). The Wave 4 global rule requires isolated DB 14 (`…rbac-wave-4.md:107-113`). Running this contract against the application Redis database could delete live cache/queue state. Require explicit isolated PG/central DB names, a dedicated Redis DB, a campaign tenant, and controlled Horizon worker coordination.

### MAJOR

1. **The exact add-list closure is false.** Task 4-2 prints only five fixed paths, then requires a later `git status | awk` result to be pasted (`…rbac-wave-4.md:611-626`). Phase 0 may derive the delta, but dispatch must contain the resulting literal list.

2. **The extraction inventory contradicts itself.** The created-files table says `campaign-lib.sh` extracts `--reuse` and a ledger accumulator (`…rbac-wave-4.md:146`); the detailed code census correctly says neither exists today (`…rbac-wave-4.md:165,685-686`; `dev:scripts/campaign-onboarding.sh:9-29,41-45,56-63`). The “Deliberately NOT touched” section then says `campaign-onboarding.sh` is never edited, while Task 4-3 explicitly edits and stages it (`…rbac-wave-4.md:168-170,692-697`).

3. **The leg-reference arithmetic is false.** The complete table contains 22 leg references across 22 rows, not “31 across twelve rows” (`…rbac-wave-4.md:523-583`). The nine declared legs plus 22 row references appears to have been mislabeled as 31 references. The dedup requirement stands; its test must assert the real number.

4. **The staging queue test is under-specified and race-prone.** It leaves `QUEUE_CONNECTION` at the deployed value and asks for job appearance, retry, and token disappearance (`…rbac-wave-4.md:781`). A live Horizon worker may consume the row before the test observes the pending state, and the Wave 2 EC-16a test currently mixes a real database queue with `Queue::assertPushed` (`…rbac-wave-2.md:2974`). Define worker pause/resume, tenant context, job correlation, and cleanup.

### MINOR

1. Residual placeholders remain in executable-looking examples: `<the exact refusal text>` in the mandatory toast assertion and `<the api container name>` in the staging invocation (`…rbac-wave-4.md:92-101,786-795`).

2. R4’s RETIRED prose first says all three assertions remain in the leg, then immediately moves the database assertion to PG (`…rbac-wave-4.md:650-661`). Keep only the final two-browser-plus-one-PG contract.

**Wave 4 verdict: CHANGES-REQUIRED.**

## Cross-plan consistency

1. **All three producer notes require re-pinning.** They still name Wave 0b rev 6 `960548c60` and Wave 1 rev 6.1 `ff2a949e9` (`…rbac-wave-2.md:11`; `…rbac-wave-3.md:11`; `…rbac-wave-4.md:11`). Accepted producers are Wave 0b rev 6.3 `5257eb0f1` and Wave 1 rev 6.3 `949dfabdf`. The intervening producer revisions are editorial/assertion fixes and do not move the consumed public contracts, so the re-pin is primarily mechanical.

2. **One producer residual must enter the Wave 2 dispatch brief.** Wave 1 rev 6.3 records that its RoleController fragments use `$writeLock`, while the accepted 0b producer declares `$permissionWriteLock`; it explicitly requires dispatch-time reconciliation (`949dfabdf:docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11088,11153,11204`). Wave 2 then edits that same controller. Re-pin and settle the property name before applying Wave 2’s lock/floor fragments.

3. **No other consumed producer signature moved.** The Wave 2 positional scaffold call, `PermissionSyncService::sync(SyncScope,bool)`, `TemplateDeltaApplier::applyTo`, `PermissionWriteLock::acquire/key`, the `wlota1a:` key, name→id role order, census support classes, and baseline paths remain compatible with the accepted rev 6.3 producers (`…rbac-wave-2.md:1967-1999`; `5257eb0f1:docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1325-1337`).

4. **The ordinals are valid and non-colliding.** `0.4`, `0.5`, `0.6`, and `0.7` conform to the required three-segment subject form (`AGENTS.md:15-16`). `0.3` and `0.5.1` are deliberate gaps; Wave 1 uses `Phase 1.x`; the Wave 3 POS shift and variable tail do not collide (`…rbac-wave-2.md:2225-2238`; `…rbac-wave-3.md:91-112`; `…rbac-wave-4.md:178-187`).

5. **The 2b execution-order table is authoritative and correctly sequenced in dependency terms:** `0.5.2` census, `0.5.3` scope, `0.5.4` limits, `0.5.5` revocation/TTL, `0.5.6` service-account surface, `0.5.7` issuance, `0.5.8` POS, `0.5.9` frontend (`…rbac-wave-2.md:2225-2238`). Its implementation defect is in `0.5.5`, not the ordering.

6. **The 17 Q-w rulings do not need owner escalation.** Q-w2-1…6, Q-w3-1…6, and Q-w4-1…5 remain engineering decisions consistent with the accepted direction, except Q-w4-2 needs its arithmetic wording corrected to 58 retained rows/57 active scenarios (`…rbac-wave-2.md:295-356`; `…rbac-wave-3.md:224-351`; `…rbac-wave-4.md:178-225`). That correction is not a policy decision.

## Citation audit

Verified correct against code:

- Login selects the user at `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-256` and reaches `Hash::check` at `:258`; the principal-kind refusal must precede that expression.
- POS population and hash sites are `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56,87-90`; the plan correctly places the human predicate in `pinHolders()` and the kind check before the hash.
- Laravel’s authorization renderer receives the converted exception, request, route and response at `dev:apps/api/bootstrap/app.php:264-308`. The render-hook placement is valid.
- `AuditService::record()`’s required signature is `dev:apps/api/app/Modules/Compliance/Services/AuditService.php:43-50`.
- The sole external authorization use of `MembershipRole::isOwner()` is `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`; other grep hits are enum/model definitions or writes.
- Marketplace’s current tenant stack is `dev:apps/api/app/Modules/Marketplace/Presentation/routes.php:87-105`, and the intended central stack is `dev:apps/api/routes/api.php:56-59`.
- `fetchDiscountPermissions()` is production-wired at `dev:apps/pos/src/stores/operatorStore.ts:128-145,269,294-296,368-369,446`; the residual role ladder is at `:354-363`.
- The onboarding runner contains only basic flags/preflights/run-id/exit handling (`dev:scripts/campaign-onboarding.sh:5-63`), and the onboarding fixture is Parapharmacy/Tunisia (`dev:docs/qa/ONBOARDING-CAMPAIGN.md:22`).
- Convention 09’s data-meaning rule is correctly cited at `docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`.

Wrong or stale citations/statements:

- Wave 2’s LastAdminFloor inventory signature and remove-role invocation are stale (`…rbac-wave-2.md:165,923` versus `:591,838` and current RoleController `:390-398`).
- Wave 2’s denial-task file list still names the withdrawn middleware (`…rbac-wave-2.md:1773-1779`).
- Wave 3’s POS FormRequest belongs to `ManagerPinController`, not PosAuth (`…rbac-wave-3.md:773,795`; `dev:apps/api/app/Modules/POS/Presentation/Controllers/ManagerPinController.php:33-40`).
- Wave 4’s file inventory and not-touched section contradict the accurate onboarding extraction census (`…rbac-wave-4.md:146,165,170`).
- Wave 4’s 57-row and 31-reference statements are false as written (`…rbac-wave-4.md:521-584,850`).
- The three producer-pin paragraphs are stale (`…rbac-wave-2.md:11`; `…rbac-wave-3.md:11`; `…rbac-wave-4.md:11`).

Every fully qualified `dev:apps/...:<line>` citation in the three plans resolves to an existing path and an in-range line on `dev`. The defects above are semantic or stale-content citations, not missing-file/range failures.

## Rejected false positives

- **The old header SHAs are provenance records, not current producer claims.** The rev-3 rejection of the round-2 “stale header SHA” item is sound where the text explicitly records the SHA at which facts were measured and Phase 0 requires re-pinning. This does not excuse the separate “Producer pins for this revision” paragraphs, which expressly claim current producer versions.
- **The CSV arithmetic is correct.** An RFC-4180 parse gives 1,054 API rows: 642 MIDDLEWARE, 142 AUTH_ONLY, 130 CONTROLLER, 68 SUPERADMIN_ONLY, 46 FORMREQUEST, 18 PUBLIC, 8 POLICY. The sweep is 130+46=176 (`…rbac-wave-3.md:233-268`). The defect is the new generator, not the CSV.
- **“24 POS controllers” is correct when it means the 91 CONTROLLER rows.** They span 24 controller classes; the extra FORMREQUEST route is hosted by `ManagerPinController`. POS remains last and the ordinal shift to `0.6.13…36` is correct (`…rbac-wave-3.md:270-309`).
- **The render-hook exception to constructor injection is accepted.** `bootstrap/app.php` is the composition root and is the correct observation point (`…rbac-wave-2.md:1857-1898`). Only the implementation and census proof are rejected.
- **The principal-kind database ruling is closed:** two PG constraints, two two-clause SQLite triggers, invalid-kind raw tests, and complete prior-schema restoration remain the right design (`…rbac-wave-2.md:2285-2348`).
- **`ScopedTokenIssuanceEntryConditionTest` remains falsifiable** and conversion precedes issuance (`…rbac-wave-2.md:1947-2146,3010-3012`).
- **Seat coverage is complete in intent:** all six counters and every tier, including trial, are enumerated (`…rbac-wave-2.md:3016-3055`).
- **Creating a second RBAC campaign is acceptable.** It has separate mutable state and findings while sharing stable runner primitives (`…rbac-wave-4.md:200-208`).
- **R9 correctly does not exist.** POS uses vitest plus a dated physical-device replay because the repository has no POS Playwright harness (`…rbac-wave-4.md:679-684`).
- **`php -l` is not the failure here.** The Wave 2 service, job, and event blocks and the Wave 3 generator block all report no syntax errors. Their failures are missing symbols/contracts and runtime semantics.

## Preserve

Preserve the accepted r9 register binding in full (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-205`), including Direction B; D1–D8; tenant-scoped roles; NULL-team compatibility; exact template deltas; custom/customised-role preservation; the one W-LOT lock key and deterministic order; owner-grants ∩ token-scope narrowing; central Sanctum storage; per-row TTLs; human-only last-admin floor pending OQ-1; `roles.view` names-only shaping; immutable audit events; and generated TypeScript permission types.

Also preserve these genuine rev-3 closures:

- Separate principal-kind domain and shape constraints, including SQLite parity.
- Wave 1’s real positional scaffold signature and direct legacy-key declaration.
- `forSubject` versus `forTarget`, including `token_id` ownership and null unscoped projection.
- The corrected 2b execution order and new `0.5.9` commit.
- Roles/system-role protection, six seat counters, every tier, PIN population centralization, notification-recipient census, and UI fallback deletion.
- Standards-compliant 130/46 CSV arithmetic, POS-last ordering, 24-controller allocation, per-cluster reviewer gates, glossary parity, MembershipRole reconciliation, deprecation conditions, and Sidebar `permission`→`moduleKey`.
- First-class `homes[]` and `legs[]` concepts, one execution per leg, `--leg`, tenant-scoped command rerun, separate campaign, `parapharmacy`, and explicit staging execution targets.
- The 58 retained/57 active edge-case interpretation; repair the representation rather than changing the accepted count.

## Owner decisions required

Only the two accepted Wave 2 policy questions require owner input:

1. **OQ-1:** service principals do not count toward the last-admin floor. Because that behavior now lands in 2a, confirm it before 2a merges (`docs/superpowers/plans/2026-09-10-rbac-wave-2.md:283-288,324-342`).

2. **OQ-2:** service-token TTL 365 days, human-token TTL 90 days, POS one year unchanged. Confirm before `Phase 0.5.7` issuance lands (`…rbac-wave-2.md:289-294,2637-2670`).

No Q-w item is escalated. OQ-3 and OQ-4 remain prior programme matters and are not reopened by these plans.

## Dispatch assessment

- **Wave 2:** not dispatchable. The denial recorder is incomplete, its census is unsatisfiable, ungated `Gate::authorize()` denials are missed, and `0.5.5` cannot persist its audit events or pass PHPStan.
- **Wave 3:** not dispatchable. The live generator crashes and produces 138 rather than 176 after a diagnostic bypass; the fixture/matrix machinery and add lists remain incomplete.
- **Wave 4:** not dispatchable. The manifest is invalid and incomplete, the 58/57 invariant is contradictory, the runner remains prose, tenant rerun commands are invalid, and the SSH replay lacks safe staging isolation.
- All three plans must re-pin to Wave 0b rev 6.3 `5257eb0f1` and Wave 1 rev 6.3 `949dfabdf`; Wave 2 must additionally reconcile the producer’s lock-property name before its controller patches are applied.

VERDICT: CHANGES-REQUIRED