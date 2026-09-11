# Codex plan gate r5 — RBAC waves 2, 3, 4 plans rev 5 (gpt-5.6-sol, high, read-only, 2026-09-11)

Repository state inspected:

- `git rev-parse --short HEAD` → **`4fbe3cddd`**
- `git rev-parse --short dev` → **`33796cc08`**
- Worktree: clean.
- Wave 0b rev 6.3 **`5257eb0f1`** and Wave 1 rev 6.3 **`949dfabdf`** are final and DISPATCH-READY. No producer change or successor re-pin is owed.

## Wave 2

### Rev-4 closure table

| Rev-4 item | Rev-5 status | Evidence |
|---|---|---|
| B1 — denial recorder rejected direct-response producers and was incomplete | **CLOSED** | The supplied class has one constructor, complete imports, `recordGateDenial(..., ?DenialCause $cause)`, and the three producer shapes; it passes `php -l`: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1811-2074`. Direct calls are supplied for `RequireAnyPermission` and `EnforceTokenScope`: `:2258-2355`. |
| M1 — resolver referenced before creation | **CLOSED** | `0.4.4` now shapes only; `0.4.6` creates the resolver and re-points callers: `…rbac-wave-2.md:1236-1248,1719-1760,4114`. |
| M2 — partial `CreateServiceAccountRequest` and undefined actor | **CLOSED for the r4 finding** | `authorize()` and `failedAuthorization()` are supplied, and `$actor` is resolved: `…rbac-wave-2.md:3023-3079,3135`. A separate PHPStan failure remains below. |
| M3 — relocation marker proved text, not location | **CLOSED** | The marker is deleted in favour of AST expression and closure-containment assertions: `…rbac-wave-2.md:2179-2254,4116`. |
| M4 — duplicate scope middleware asserted idempotent without proof | **CLOSED** | Vendor resolution order is correctly pinned and the coverage test requires exactly one resolved instance: `…rbac-wave-2.md:2952-2960,4117`; vendor sources are `apps/api/vendor/laravel/framework/src/Illuminate/Routing/Router.php:844-881,1472-1487` and `…/SortedMiddleware.php:63`. |
| m1 — denial-test count mismatch | **CLOSED** | Three positives, six negatives and one robustness case are now stated: `…rbac-wave-2.md:2374-2383,4118`. |
| m2 — five selectors versus six directory-sensitive paths | **CLOSED** | The two counts are separated: `…rbac-wave-2.md:2891,4119`. |
| m3 — dead `PermissionDefinition::legacy()` fork | **CLOSED** | The rev-6.3 signature is pinned: `…rbac-wave-2.md:2496-2507,4120`. |

### BLOCKER

1. **Wave 2 introduces unbaselined bare permission literals, so its own per-commit PHPStan contract cannot stay green.** New production call sites use:

   - `can('roles.view')`: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1236,1739-1740`
   - `can('pos.discount_unlimited')`: `…rbac-wave-2.md:2514`
   - `can('service-accounts.create')` and `can('users.manage_location_access')`: `…rbac-wave-2.md:3035-3050,3066-3070`

   Wave 1’s final `ForbidPermissionStringLiteral` matches `can()` and emits on every new, non-baselined site; the baseline absorbs only existing exact file/line/key sites: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:9024-9036,9141-9161`. Tests and generated enum/manifest paths are exempt, but these controllers, requests and services are not: `…rbac-wave-1.md:8984-8986`.

   The implementations must use the relevant generated enum cases’ `->value`. This is a consumer-plan correction, not a Wave-1 producer change.

### MAJOR

None beyond the blocker.

### MINOR

1. **The denial-recorder prose retains the superseded one-producer predicate.** It says emission requires `$e->getPrevious() instanceof AuthorizationException`, contradicting the newly accepted explicit-`$cause` and wrapped-exception arms: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2355-2358` versus `:1844-1868,2074,2258-2264`.

**Wave 2 verdict: CHANGES-REQUIRED.** The r4 findings are substantively closed, but the new permission literals make several planned implementation commits fail Wave 1’s already-final PHPStan rule.

## Wave 3

### Rev-4 closure table

| Rev-4 item | Rev-5 status | Evidence |
|---|---|---|
| B1 — ceiling starts at zero and cannot ratchet | **CLOSED** | It starts at measured 176, has a truthful pre-commit `176 > 175` first red, and reaches zero across 37 green commits: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:918-965`. |
| B2 — invalid fixture implementations and absent matrix body | **NOT CLOSED** | A matrix body now exists, but its registry is still prose and its delete/count semantics cannot run; one factory still names a nonexistent field: `…rbac-wave-3.md:1668-1757,1883-1895,2489-2664`. |
| M1 — first-red protocol contradicted CI-clean commits | **CLOSED** | The red happens before `0.6.1`; `0.6.1` commits the green ceiling at 175: `…rbac-wave-3.md:918-926`. |
| M2 — generated per-commit inventories deferred until implementation | **CLOSED subject to the stated dispatch-brief entry condition** | The three producer-dependent inventories must be pasted into the dispatch brief before work starts: `…rbac-wave-3.md:125-132`. |
| M3 — stale “fetchDiscountPermissions is unwired” premise | **CLOSED** | The plan now records the four production call paths and limits the edit to the residual ladder: `…rbac-wave-3.md:203,333-356,1343-1354`. |
| m1 — “four modules” versus three `app(...)` calls | **CLOSED** | The nonexistent services were removed and the correction is explicit: `…rbac-wave-3.md:2154-2166`. |
| m2 — `php -l` overclaimed semantic validity | **CLOSED in wording** | Syntax and resolution evidence are now separated: `…rbac-wave-3.md:2168,2933-2934,2944`. The resolution claim itself remains false for `pos_pin_hash`. |

### BLOCKER

1. **The committed fixture registry is still not supplied.** The plan prints one JSONC schema object and a twenty-row summary table, then tells the implementer to build the twenty JSON entries: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:1668-1706,1710-1733,1751-1757`. There is no complete `route-action-fixtures.json` payload containing the actual `permission`, `permission_source`, `params`, request bodies and expectations for all twenty routes. Consequently, the claimed first red and the supplied contract/matrix classes are not reproducible from the plan.

2. **The supplied matrix cannot model its own delete and count rows.**

   - Entries 3 and 13 are deletes whose row must exist before the request: `…rbac-wave-3.md:1716,1726`.
   - The test universally requires `expectMatches()` to be false before every request: `…rbac-wave-3.md:2589-2594`.
   - An expectation without `column` is defined as `query()->exists()`: `…rbac-wave-3.md:2630-2638`.
   - The note expressly assigns that existence form to entry 3: `…rbac-wave-3.md:2664`.

   Entry 3 therefore fails before its DELETE is sent. The same representation also cannot express the promised pivot-count transitions for entries 2 and 7: `…rbac-wave-3.md:1715,1720,2630-2638`.

3. **`IdentityFixture::serviceAccount()` still writes a nonexistent user attribute.** It supplies `pos_pin_hash`: `…rbac-wave-3.md:1883-1895`. At `dev`, the field is `pos_pin`, including the model property, fillable entry and cast: `dev:apps/api/app/Modules/Identity/Domain/User.php:30-40,81-97,115-126`. Wave 2 makes that column nullable; it does not rename it.

### MAJOR

1. **The operative correction history contradicts the current route table.** The current table correctly gives entry 15 `product_batches/is_recalled` and entry 19 `POST replenishment-requests`: `…rbac-wave-3.md:1728,1732`. The immediately following “corrections” table still says `batches/status` and `actions.create-transfer`: `:1742,1745`. Until the full JSON artifact exists, these contradictory instructions leave the implementer two different fixture definitions.

### MINOR

None additional.

**Wave 3 verdict: CHANGES-REQUIRED.** The route sweep and ceiling are now sound, but the final role matrix—this wave’s primary regression artifact—remains non-executable.

## Wave 4

### Rev-4 closure table

| Rev-4 item | Rev-5 status | Evidence |
|---|---|---|
| B1 — selectors could silently green an empty run | **CLOSED for the tested arms** | Unknown leg/row/lane, repeated leg and empty intersection are guarded: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1465-1545,1748-1755,1781-1789`. |
| B2 — `--lanes` ran destructive legs | **CLOSED** | The filter now applies to both `LEG` and `LANE` records: `…rbac-wave-4.md:1710-1715`. |
| B3 — staging preflight existed only in prose | **NOT CLOSED** | A function exists, but its disposable-tenant command cannot run against the command supplied by the plan, and database lifecycle is still absent: `…rbac-wave-4.md:1552-1618,1883-1893,1961`. |
| M1 — host root reused as container root | **CLOSED in runner** | `--container-root` and `docker exec -w` are implemented: `…rbac-wave-4.md:1449-1451,1685-1692`. |
| M2 — Horizon pause lacked a cleanup trap | **PARTIAL** | A safe manual trap is printed: `…rbac-wave-4.md:2006-2021`; the runner never invokes that sequence. |
| M3 — unresolved R8 probe | **CLOSED** | The plan pins `GET /roles`, denial body and the unscoped 200 control: `…rbac-wave-4.md:1242-1265,2287`. |
| M4 — manifest guard was prose | **NOT CLOSED** | A body exists but is deterministically red and does not perform the promised filter resolution: `…rbac-wave-4.md:956-1156`. |
| M5 — library extraction claim and parity test | **PARTIAL** | The parity script has a body: `…rbac-wave-4.md:1378-1410`; several inventories and the runner header still claim the library owns code it does not: `:163-165,184,1418-1424`. |
| M6 — duplicate `0.7.4` fix ordinals | **CLOSED** | Fixes use `0.7.4a` through `0.7.4z`: `…rbac-wave-4.md:1829-1838`. |
| m1 — residual placeholders | **PARTIAL** | `<N>` and R8 are resolved, but the Phase-0 registration command still contains executable password ellipses: `…rbac-wave-4.md:306-314`. |
| m2 — stale Wave-3 ordinal inventory | **CLOSED** | Wave 3 matrix/tail are now `0.6.47`/`0.6.48+`: `…rbac-wave-4.md:77-82,2291-2293`. |
| m3 — staging-target spelling | **CLOSED** | Runner and checklist use `not replayed (declared)`: `…rbac-wave-4.md:1693-1698,2121`. |

### BLOCKER

1. **`RbacCampaignManifestCoverageTest` cannot pass.**

   - `dirname(base_path())` is annotated as “apps/api → repo root,” but one `dirname` produces `<repo>/apps`; appending lane cwd `apps/api` produces `<repo>/apps/apps/api/...`: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1034-1053`.
   - It asserts that all manifest row ids number 22, even though rule 1 already requires 58 rows: `…rbac-wave-4.md:1012-1032,1093-1094`.
   - Rule 2 promises PHPUnit `--list-tests`/`--filter` resolution, but its implementation performs only file existence and shell executable checks: `…rbac-wave-4.md:956-958,1034-1075`.

   `php -l` proves only syntax; it does not rescue any of these behavioral failures. Rev 5’s M4 closure claim is false: `…rbac-wave-4.md:2278,2288`.

2. **The disposable-tenant preflight is impossible against the command the plan supplies.** The runner invokes:

   `php artisan rbac:row-census --tenant=… --field=is_disposable`

   at `…rbac-wave-4.md:1566-1573`. The registered command has no `--tenant` or `--field` options and only prints five tenant-database table counts under `tenants:run`: `:1883-1893`. Moreover, `dev`’s central `Tenant` model has no `is_disposable` fillable field, cast or custom column: `dev:apps/api/app/Modules/Tenant/Domain/Tenant.php:85-118,125-140,151-188`.

   The fake-SSH happy path therefore validated mocked text, not a command the repository can produce; the claimed exercised closure is not valid: `…rbac-wave-4.md:1790-1799,2276-2284`.

3. **The staging database lifecycle promised by the lane contract is absent.** The plan says the replay preflight creates the two isolated databases and drops them afterward: `…rbac-wave-4.md:1957-1964`. The supplied preflight only requires them to exist and reads `pg_stat_user_tables`; it never creates or drops either database: `:1575-1592`. It also calls `sum(n_live_tup)` a real row count, although that is a statistics estimate rather than an exact emptiness proof. The staging replay therefore cannot establish or clean the isolation it claims.

4. **EC-16a’s queue-safe staging sequence is not integrated into the runner.** `staging_preflight()` only assigns `HORIZON_SUPERVISOR_PAUSE`: `…rbac-wave-4.md:1602-1618`. The runner’s `pg` wrapper executes every PG row identically and never reads that variable: `:1676-1701`. The pause, observation and cleanup trap exist only as a separate manual snippet: `:1981-2023`. Thus `--staging-replay` will execute EC-16a without the promised paused observation window, and the “local-only when unsupported” branch is never written to its ledger.

5. **Task 4-3’s exact commit cannot be made.**

   - `RetiredKeyAbsenceTest.php` remains in `WAVE_4_CREATES`, whose `assertFileDoesNotExist` arm is supposed to shrink in the commit that creates each file: `…rbac-wave-4.md:987-1004,1054-1061,1156`.
   - Task 4-3 creates that test but does not stage an update to `RbacCampaignManifestCoverageTest.php`, leaving the guard red: `:1802-1824`.
   - The same exact add list includes nonexistent `tests/CampaignLibParityTest.sh`, despite the actual supplied file being `scripts/campaign-lib-parity-test.sh`: `:1378-1410,1804-1823`.

### MAJOR

None beyond the blockers.

### MINOR

1. **The reuse contract contradicts the implementation.** The global table says `--reuse --leg R4 --lanes pg` exits 2 with `reuse_is_single_leg`: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:141-150`. The parser only refuses a missing, repeated or comma-separated leg: `:1475-1480,1500-1504`; the explicit leg later suppresses lane records and therefore runs R4 successfully: `:1657-1664,1710-1715`. I exercised this path after selector validation; it survives with exit 0.

2. **Library ownership remains stated three incompatible ways.** The detailed note correctly says unknown-argument handling and final exit remain in each runner: `…rbac-wave-4.md:1375-1378`. The created-files table, modified-files table and runner header still say the library owns the unknown-argument and exit-code contract: `:163-165,184,1418-1424`.

3. **The runner defines `die2()` twice:** `…rbac-wave-4.md:1454,1458`.

4. **The Phase-0 registration command retains an ellipsis password and is not paste-runnable:** `…rbac-wave-4.md:306-314`. Template fields such as `<sha>` in the evidence document are legitimate fields to fill; this password appears inside an executable command.

**Wave 4 verdict: CHANGES-REQUIRED.** The manifest arithmetic and basic selectors are repaired, but the coverage guard, staging safety contract, queue replay and exact commit sequence remain non-runnable.

## Cross-plan consistency

1. **No producer re-pin is required.** All three plans consume Wave 0b rev 6.3 `5257eb0f1` and Wave 1 rev 6.3 `949dfabdf`, and correctly reject the r4 stale-context claim: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:15-50,4122-4124`; `…rbac-wave-3.md:31-55,2946`; `…rbac-wave-4.md:33-41,2295`. The consumed `PermissionWriteLock`, registry, sync, tenant-wide audit, scaffold and baseline names match those final producer plans.

2. **The only new Wave-1 interaction is a downstream violation, not producer debt.** Wave 2 must consume Wave 1’s permission enums at its new authorization sites; Wave 1’s literal rule must not be changed or its baseline enlarged: `…rbac-wave-2.md:1236,1739,2514,3035-3070`; `…rbac-wave-1.md:9141-9161`.

3. **Dependency order is otherwise coherent.** Programme entry conditions remain Wave 1 → 2a → 2b → 3 → 4: `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:175-217,279-282`. Wave 3 and Wave 4 remain blocked on successful preceding waves, independently of their own plan defects.

4. **Phase families do not collide.** Wave 2 uses `0.4`/`0.5`, Wave 3 uses `0.6`, and Wave 4 uses `0.7`; 0a uses `0.1`, 0b `0.2`, Wave 1 `1.x`, and `0.3` is deliberately unused: `…rbac-wave-2.md:115,304`; `…rbac-wave-3.md:92-115`; `…rbac-wave-4.md:77-82`. The subjects follow `AGENTS.md:15-16`.

5. **Exact-add closure fails only where reported above.** Wave 2’s and Wave 3’s task lists are explicit, with their documented Phase-0 generated inventories required in the dispatch brief. Wave 4 Task 4-3 contains both a wrong path and an omitted guard edit: `…rbac-wave-4.md:1802-1824`.

6. **The 17 Q-w points remain engineering rulings.** Q-w2-1…6, Q-w3-1…6 and Q-w4-1…5 remain consistent with the accepted spec. None requires owner escalation.

## Citation audit

Mechanical audit of the three rev-5 plans found **405 fully qualified `dev:<path>:<line>` references**. Every referenced object exists at `dev` and every range is within the file except:

- `dev:scripts/campaign-onboarding.sh:1-70` at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:184`; the file ends at line 63. The same historical overrun appears at `:2385`.

Seven basename/ellipsis citations are not exact paths and should be expanded:

- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:3055,3111,3135,3203,3222,4115`
- `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:2998`

Semantic citation failures or stale statements are:

- Wave 2’s superseded denial predicate: `…rbac-wave-2.md:2355-2358`.
- Wave 3’s `batches/status` and replenishment-action correction rows: `…rbac-wave-3.md:1742,1745`.
- Wave 3’s nonexistent `pos_pin_hash`: `…rbac-wave-3.md:1883-1895` versus `dev:apps/api/app/Modules/Identity/Domain/User.php:40,81-97,115-126`.
- Wave 4’s false `dirname(base_path())` repository-root comment: `…rbac-wave-4.md:1034-1053`.
- Wave 4’s false 22-row assertion: `…rbac-wave-4.md:1093-1094`.
- Wave 4’s stale library inventories: `…rbac-wave-4.md:163-165,184,1418-1424`.
- Wave 4’s impossible `rbac:row-census` invocation: `…rbac-wave-4.md:1566-1573,1883-1893`.
- Wave 4’s claimed create/drop database lifecycle: `…rbac-wave-4.md:1961` versus the supplied preflight at `:1575-1592`.

Code facts reverified:

- Login resolves the user at `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-256` and hashes at `:258`; the principal-kind refusal must precede line 258.
- PIN candidates are selected at `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56,87`; `Hash::check` is at `:90`; the human-kind restriction must be in `pinHolders()`.
- `RequireAnyPermission` builds and returns a 403 without throwing at `dev:apps/api/app/Http/Middleware/RequireAnyPermission.php:14-35`.
- The sole authorization read of `MembershipRole::isOwner()` remains `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`.

## Rejected false positives

- **No producer work is owed.** Wave 0b and Wave 1 are final at the supplied rev-6.3 commits.
- **`recordGateDenial()` is repaired.** Its complete class is syntax-clean and accommodates the thrown, wrapped and explicit-cause shapes: `…rbac-wave-2.md:1811-2074`.
- **The duplicated `EnforceTokenScope` declaration executes once.** Laravel expands middleware groups before `uniqueMiddleware()`: `…rbac-wave-2.md:2952-2960`.
- **The route arithmetic is 176, not 171:** 1,054 API routes; 642 MIDDLEWARE, 142 AUTH_ONLY, 130 CONTROLLER, 68 SUPERADMIN_ONLY, 46 FORMREQUEST, 18 PUBLIC, 8 POLICY.
- **The re-extracted generator is correct.** It reproduced 176 with zero reflection errors, and its POS grouping is 92 rows across 25 classes: `…rbac-wave-3.md:918-965,1107-1108,2922-2933`.
- **The 37-row ceiling table is arithmetically sound.** Its deltas sum to 176, every “before” equals the preceding result, and the final row writes zero: `…rbac-wave-3.md:924-964`.
- **The 18 current fixture route keys resolve.** Entries 8 and 9 are legitimately Wave-2b routes: `…rbac-wave-3.md:1710-1749,2168`. Route resolution does not validate fixture bodies, which is why `pos_pin_hash` remains a finding.
- **The manifest arithmetic is sound:** 58 retained rows, 57 executable, EC-22 withdrawn, nine legs, 22 leg references across 22 rows. Each current `legs[].covers` list matches the references: `…rbac-wave-4.md:952-954`.
- **The repaired selector arms work.** Repeated leg, unknown leg, unknown row, unknown lane and reuse-without-leg all exited 2 when exercised: `…rbac-wave-4.md:1781-1789`.
- **A separate RBAC campaign remains acceptable.** It need not be appended to onboarding.
- **EC-31’s POS split is honest.** The POS app has no Playwright harness, so POS Vitest plus the documented physical-device replay is preferable to a fabricated browser leg: `…rbac-wave-4.md:267-272,1268-1272`.

## Preserve

- The principal-kind closed-domain and service-shape invariants, PG CHECKs, SQLite trigger parity, destructive five-step rollback, and restoration of the prior `users` schema: `…rbac-wave-2.md:2757-2817`.
- Principal-kind refusal before login/PIN hashing and human-only `pinHolders()`.
- Dedicated service-account routes, typed generic-user and RoleController refusals, `AssignableRole`, all writer/recipient selector censuses, all six seat counters, and `max_service_accounts` in every tier including trial.
- `permission:<key>` narrowing, explicit TTLs, mandatory `tenant:` claim, global `EnforceTokenScope`, priority, non-tenant no-op, and resolved-stack coverage.
- `forSubject()` versus `forTarget()`, owned `token_id` selection, self-read request-token projection, `roles.view` authorization, names-only shaping and server-authoritative frontend permissions.
- `roles.is_system`, `LastAdminFloor`, the shared `wlota1a:<tenant>` lock, deterministic role-name/id locking and complete writer census.
- Stable role mutation domain events, tenant-wide attribution, atomic Redis denial dedup and the `GateEvaluated` trail.
- Wave-2 Playwright coverage and deletion of frontend role/alias fallbacks.
- Wave-3’s live-router generator, 176-site ordering, POS last, FormRequest rule, module reviewer gates, MembershipRole reconciliation, glossary parity, retirement conditions and Sidebar `permission` → `moduleKey`.
- Wave-4’s 58-row manifest, 57/1 EC-22 accounting, nine browser legs, conditional toast/unchanged-row assertions, universal 5xx/console capture, evidence document and separate campaign structure.

## Owner decisions required

Only the two accepted Wave-2 policy decisions remain owner items:

1. **OQ-1:** confirm that service principals do not count toward the last-admin floor before Wave 2a merges: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:288-293,507-525`.

2. **OQ-2:** confirm service-token TTL 365 days, human-token TTL 90 days and unchanged one-year POS terminal TTL before `Phase 0.5.7`: `…rbac-wave-2.md:294-299,3375-3392`.

No Q-w point is escalated.

## Dispatch assessment

- **Wave 2 — CHANGES-REQUIRED.** Entry condition remains Wave 1 merged and soaked. Replace every new production permission literal with its generated enum case before dispatch.
- **Wave 3 — CHANGES-REQUIRED.** Entry condition remains both Wave-2 lanes merged and deployed. Supply the full JSON registry, correct `pos_pin`, and give create/update/delete/count expectations distinct executable semantics.
- **Wave 4 — CHANGES-REQUIRED.** Entry condition remains Wave 3 merged. Repair the manifest guard, define a real disposable-tenant marker and command, integrate EC-16a into the runner, implement database creation/cleanup, and correct Task 4-3’s exact add list.

VERDICT: CHANGES-REQUIRED