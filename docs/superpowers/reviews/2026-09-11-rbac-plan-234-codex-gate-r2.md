# Codex plan gate r2 — RBAC waves 2, 3, 4 plans rev 2 (gpt-5.6-sol, high, read-only, 2026-09-11)

Declared repository HEAD: `3dc54ac86`.

The three rev-2 plans are at `16d923992`. Current `dev` is `33796cc08`; its application tree remains byte-identical to the plans’ cited `630afa86f` for `apps/api`, `apps/web`, `apps/pos`, and `packages/shared`. I verified code citations with `git show dev:<path>` and ran the fresh route arithmetic from the shared checkout.

## Wave 2

### Rev-1 closure table

| Rev-1 finding | Rev-2 disposition |
|---|---|
| B1-1 — floor lock protocol | **NOT CLOSED.** The shared key and semantic lock order are now correct, but callers acquire the advisory lock and then `LastAdminFloor` acquires it again, invalidating the promised SQL-order test (`2026-09-10-rbac-wave-2.md:647-649,852-864`; wave-0b producer `:1316-1337`). |
| B1-2 — 2a structural stubs | **CLOSED** by moving `principal_kind` and real predicates into Task 2a-0 (`2026-09-10-rbac-wave-2.md:155-190,315-333`). A new schema defect remains below. |
| B1-3 — resolver without bodies | **CLOSED** for implementation shape: interface and concrete bodies are supplied (`2026-09-10-rbac-wave-2.md:1233-1495`). Two semantic defects remain below. |
| B1-4 — failure types/events absent | **NOT CLOSED.** Exception/value-object bodies exist, but `RoleUpdated` and `RoleDeleted` remain prose, and `RoleUpdateRefused` can be constructed without `role_id` (`2026-09-10-rbac-wave-2.md:575-689,796-809,1156-1191`). |
| M1-1 — human TTL not implemented | **CLOSED** (`2026-09-10-rbac-wave-2.md:2143-2155,2331-2334`). |
| M1-2 — incomplete rollback | **CLOSED** in the migration body (`2026-09-10-rbac-wave-2.md:1919-1936`), although several summaries still call it four-step. |
| M1-3 — writer inventory conflict | **NOT CLOSED.** The main table is improved, but the census contains three exemptions while verification claims exactly two (`2026-09-10-rbac-wave-2.md:866-878,2314`). |
| M1-4 — unspecified denial source | **NOT CLOSED.** The proposed middleware cannot observe authorization exceptions after Laravel renders them outside the middleware pipeline (`2026-09-10-rbac-wave-2.md:1614-1635`; `dev:apps/api/bootstrap/app.php:212-270`). |
| M1-5 — weakened soak | **CLOSED**; both lanes retain the one-week gate (`2026-09-10-rbac-wave-2.md:49-55,307-313`). |
| M1-6 — overly broad service-target census | **NOT CLOSED.** The closed list is correct at `:961-979`, but Task 2b-4 again requires every public method resolving a user to refuse service targets (`2026-09-10-rbac-wave-2.md:2123-2125`). |
| M1-7 — vacuous entry-condition test | **CLOSED.** The real sorted-list baseline is read, and the assertion is falsifiable (`2026-09-10-rbac-wave-2.md:57-59,1707-1769`). |
| m1-1 — duplicated `pinHolders` wording | **CLOSED** with one predicate in `pinHolders()` (`2026-09-10-rbac-wave-2.md:2243-2246`). |
| m1-2 — conditional new-key wording | **CLOSED** textually (`2026-09-10-rbac-wave-2.md:335-341`), but its scaffold commands do not match the producer CLI. |

### BLOCKER

1. **The `principal_kind` constraint admits arbitrary values.** The migration supplies only the shape check `principal_kind = 'human' OR (...)`; a service-shaped row with `principal_kind = 'robot'` satisfies it. The accepted spec separately requires `principal_kind IN ('human','service')`, including equivalent SQLite triggers. The proposed test never attempts an invalid kind (`2026-09-10-rbac-wave-2.md:1905-1913,1938-1942`; `2026-09-10-roles-permissions-catalogue-design.md:2012-2029`).

2. **Task 2a-8 invokes a nonexistent wave-1 scaffold interface.** Wave 2 uses named `--module`, `--resource`, `--verb`, and `--legacy` options (`2026-09-10-rbac-wave-2.md:1672-1688`). Wave 1 rev 6.1 declares positional `{module} {resource} {verb}`, plus `--qualifier`, `--template=*`, `--sod-group`, and `--dry-run`; it declares no `--legacy` option (`2026-09-10-rbac-wave-1.md:8485-8495,8529-8536`). The conversion-before-token-issuance entry condition therefore cannot be reached as written.

3. **Lane 2b’s numbered commits are not independently buildable.** Task 2b-4 calls `PlanEnforcementService::canAddServiceAccount()`, which is introduced only by Task 2b-6, and routes membership removal through `MembershipRevocationService`, introduced by Task 2b-5 (`2026-09-10-rbac-wave-2.md:2050-2127,2174-2198,2202-2228`). `Phase 0.5.4` cannot compile and green before `0.5.5` and `0.5.6`.

4. **Authorization-denial auditing is attached at a point that cannot observe the rendered 403.** `RecordAuthorizationDenial` expects `$next($request)` to return the response after an exception renderer has annotated the request (`2026-09-10-rbac-wave-2.md:1614-1635`). In the current Laravel bootstrap, authorization exceptions leave the middleware pipeline and are converted/rendered by `withExceptions`; the middleware does not receive that rendered response (`dev:apps/api/bootstrap/app.php:212-270`). Re-reading route `can:` middleware also cannot identify a controller-side `Gate::authorize()` denial. The stated positive test is red for framework-flow reasons, not product behavior.

### MAJOR

1. **The deterministic SQL-order assertion omits the second advisory-lock statement.** Writers acquire `PermissionWriteLock` before calling the floor, and the floor unconditionally calls it again (`2026-09-10-rbac-wave-2.md:647-649,852-864`). The producer’s `acquire()` always executes `pg_advisory_xact_lock` inside PG transactions (`2026-09-10-rbac-wave-0b.md:1316-1337`). The actual query order is advisory, advisory, roles, users, pivot—not the asserted advisory, roles, users, pivot (`2026-09-10-rbac-wave-2.md:505,2301-2304`). PostgreSQL reentrancy makes the behavior safe, but the red/green oracle is false.

2. **`RoleUpdateRefused.role_id` is not guaranteed.** Floor methods call the violation factory without a role identifier; the factory defaults `roleId` to `null` (`2026-09-10-rbac-wave-2.md:575-689,796-809`). This contradicts the verification requiring `role_id` on every refusal (`2026-09-10-rbac-wave-2.md:2306`) and EC-13’s accepted attribution contract.

3. **Self effective-permission reads bypass request-token projection.** The plan resolves every selector through `forTarget()` and returns `token_scope: null` when `token_id` is omitted (`2026-09-10-rbac-wave-2.md:1501-1509`). The accepted contract requires `{id}=caller` with no selector to use `forSubject()` and project the request token (`2026-09-10-roles-permissions-catalogue-design.md:1777-1781`). The proposed endpoint test checks only status, so it can green with the wrong result.

4. **An unscoped token produces an empty token projection instead of `token_scope: null`.** `scopeKeys()` returns `null` when no `permission:` abilities exist, but DTO assembly keys only on whether a token object exists (`2026-09-10-rbac-wave-2.md:1383-1420,1473-1479`). The owner-grants intersection itself is sound; the DTO must use `$scope === null` to distinguish unscoped from narrowed tokens.

5. **`CreateServiceAccountRequest` references undefined `$currentUser`.** Its `rules()` creates `new AssignableRole($currentUser)` without initializing that variable (`2026-09-10-rbac-wave-2.md:2077-2096`). Current `AssignRoleRequest` explicitly obtains `$actor = $this->user()` before constructing the rule (`dev:apps/api/app/Modules/Identity/Presentation/Requests/AssignRoleRequest.php:28-35`).

6. **Two required role-event implementations remain prose.** `RoleCreated` has a complete body, but `RoleUpdated` and `RoleDeleted` are only instructed to follow it (`2026-09-10-rbac-wave-2.md:1156-1191`). That does not meet the requested no-stub, compilable-task standard.

7. **Membership revocation is still a prose design, not a complete task.** The plan states best-effort central deletion, retry, pending audit evidence, and `ApiTokenRevoked`, but supplies no service/job/event bodies; its exact add list omits the revocation event, subscriber changes, pending-event artifact, and writer-census test (`2026-09-10-rbac-wave-2.md:2174-2198`).

8. **The service-target census remains internally inconsistent.** The correct seven-write closed list appears at `:961-979`; Task 2b-4 later reintroduces the rejected “every public method resolving a users row” rule, which would include reads (`2026-09-10-rbac-wave-2.md:2123-2125`).

9. **The plan does not provide exact staging lists for most commits.** Tasks 2a-1, 2a-4, 2a-5, 2a-9, 2b-2, 2b-3, 2b-4, and 2b-6 through 2b-8 end with subjects but no exact `git add` block (`2026-09-10-rbac-wave-2.md:471,1138,1219,1850,1984,2046,2127,2228-2278`). This contradicts the rev-2 change log’s closure of cross-M3.

### MINOR

1. The file inventory and summaries still say the migration has a four-step `down()`, while the implementation correctly has five operations (`2026-09-10-rbac-wave-2.md:156,333,419,1919-1936,2423`).

2. The census map exempts three methods, including the future provisioning service, while the verification checklist says exactly two (`2026-09-10-rbac-wave-2.md:866-878,2314`).

3. OQ-1 still says to ask before 2b merges, although its human-floor behavior now ships in 2a (`2026-09-10-rbac-wave-2.md:276-279,2454`).

4. The producer note still calls wave 1 rev 6 `177b3d7d7` the latest. The current producer is rev 6.1 `ff2a949e9`; its only relevant change is the missing `SyncScope` import, so no consumed public signature changed (`2026-09-10-rbac-wave-2.md:7,25-41`).

**Minor-only status: No. Wave 2 retains four blockers.**

## Wave 3

### Rev-1 closure table

| Rev-1 finding | Rev-2 disposition |
|---|---|
| B3-1 — incorrect 127/44 arithmetic | **CLOSED.** Proper CSV parsing gives 130 controller plus 46 FormRequest routes, total 176 (`2026-09-10-rbac-wave-3.md:273-329`). |
| B3-2 — literal baseline lacked sites/files | **NOT CLOSED.** A by-file command exists, but depends on undefined `$SCRATCH`; staging paths still contain placeholders (`2026-09-10-rbac-wave-3.md:579-610`). |
| B3-3 — matrix had no request fixtures | **NOT CLOSED.** A schema and twenty examples were added, but most named routes do not exist, factory bodies are absent, and the schema cannot identify controller/FormRequest permission keys (`2026-09-10-rbac-wave-3.md:749-805`). |
| M3-1 — stale POS premise | **CLOSED.** The plan now preserves and wires `fetchDiscountPermissions()` before removing the fallback ladder (`2026-09-10-rbac-wave-3.md:482-512`; `dev:apps/pos/src/stores/operatorStore.ts:128-155,269,294,368,446`). |
| M3-2 — incomplete glossary | **CLOSED** with all §2 nouns and a parity test (`2026-09-10-rbac-wave-3.md:833-866`). |
| M3-3 — MembershipRole mislabeled | **CLOSED.** The only production authorization read is correctly identified at `UserController.php:929` (`2026-09-10-rbac-wave-3.md:521-526`; `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`). |
| M3-4 — vague Marketplace migration | **CLOSED** with exact old and new middleware stacks and tests (`2026-09-10-rbac-wave-3.md:705-727`). |
| M3-5 — POS counting error | **CLOSED** in the 176 arithmetic and POS-last ordering (`2026-09-10-rbac-wave-3.md:273-329,453-512`). |
| m3-1 — bad cross-reference | **CLOSED** in rev 2’s module sequence. |
| m3-2 — `reports.view` count | **CLOSED** by treating the POS translation occurrence as non-permission-shaped (`2026-09-10-rbac-wave-3.md:673`). |
| m3-3 — commit decomposition | **NOT CLOSED.** Subjects are more explicit, but exact add lists still contain placeholders (`2026-09-10-rbac-wave-3.md:368-375,442,533,674-684`). |

### BLOCKER

1. **The 176-route enforcement baseline has no executable generator.** Phase 0 captures `route:list` and runs the existing coverage test, then instructs the implementer to “generate” a classification that route metadata alone cannot infer for controller and FormRequest checks (`2026-09-10-rbac-wave-3.md:316-366`). No analyzer body or exact command connects controller/FormRequest source to the route registry.

2. **The first twenty route fixtures are not a compilable starting registry.** At current `dev`, only seven listed names resolve. Coupon, promotion, scheduling, and batch-recall routes are unnamed; `vouchers.issue` should be `vouchers.issue-goodwill`; no `replenishment-requests.approve` route exists; and platform-integration names differ (`2026-09-10-rbac-wave-3.md:775-798`; `dev:apps/api/app/Modules/Coupons/Presentation/routes.php:12-23`; `dev:apps/api/app/Modules/Promotions/Presentation/routes.php:12-21`; `dev:apps/api/app/Modules/Scheduling/Presentation/routes.php:66-79`; `dev:apps/api/app/Modules/Vouchers/Presentation/routes.php:27`; `dev:apps/api/app/Modules/Replenishment/Presentation/routes.php:14-31`). “Re-pin in Phase 0” does not say which routes will be named, replaced, or removed.

3. **The fixture schema cannot generate the promised authorization expectation.** It records route, method, URI, setup, payload, and mutation evidence, but not the permission key (`2026-09-10-rbac-wave-3.md:751-768`). A live route can expose `can:` middleware, but not controller-side or FormRequest authorization checks—the very enforcement styles this wave preserves. The statement that `templateGrants()` decides each route’s expectation therefore has no defined route-to-key input (`2026-09-10-rbac-wave-3.md:771-805`).

### MAJOR

1. **The literal-site generation command is not runnable as printed.** It writes through `tee "$SCRATCH/..."`, but Wave 3 never initializes `SCRATCH` (`2026-09-10-rbac-wave-3.md:589-600`).

2. **The retirement task names wrong frontend locale files.** It stages `src/locales/{en,fr,ar}/permissions.json`, while wave 1 generates labels into each locale’s `common.json` (`2026-09-10-rbac-wave-3.md:674-682`; `2026-09-10-rbac-wave-1.md:8624-8625,10239-10242`).

3. **“Exact add list” remains false.** The plan uses `<Cluster>`, `<each …>`, and manifest placeholders in Tasks 3-1, 3-2, 3-4, 3-6, and 3-7 (`2026-09-10-rbac-wave-3.md:368-375,442,533,579-610,674-682`).

4. **Reviewer gates are not applied per converted cluster.** The programme requires `tenancy-authz-reviewer` plus the owning-module reviewer for each cluster (`2026-09-10-rbac-programme-execution-plan.md:199-209`). Wave 3 describes a whole-diff tenancy review and special reviews for only selected modules, not a gate attached to each module commit (`2026-09-10-rbac-wave-3.md:879-949`).

5. **The route-action registry still lacks implementation bodies.** The twenty entries are a table, and `RouteActionFixtures.php` is described but not supplied. The `unfixtured-routes.json` format and initial ceiling are also unspecified (`2026-09-10-rbac-wave-3.md:773-805`). Consequently, the first red test’s expected failure is not reproducible from the plan.

### MINOR

1. Three residual summaries still use the obsolete 127 denominator despite the accepted 176 correction (`2026-09-10-rbac-wave-3.md:78,256,411`).

2. The literal-baseline section still points at `tests/PHPStan/baselines/*`; the producer path is `tests/Architecture/baselines/*` (`2026-09-10-rbac-wave-3.md:579`; `2026-09-10-rbac-wave-1.md:10226-10234`).

3. Task 3-8 says `marketplace.admin` “joins Task 3-7’s deprecation set,” but Task 3-7’s authoritative 22-key list excludes it and runs first. It should be explicitly deprecated by Task 3-8 rather than described as joining the already-closed set (`2026-09-10-rbac-wave-3.md:649-684,722-734`).

4. Its producer note also requires a mechanical re-pin from wave 1 rev 6 to rev 6.1; no consumed signature changed (`2026-09-10-rbac-wave-3.md:7`).

**Minor-only status: No. Wave 3 retains three blockers.**

## Wave 4

### Rev-1 closure table

| Rev-1 finding | Rev-2 disposition |
|---|---|
| B4-1 — impossible retired-key browser assertion | **CLOSED** by the RETIRED/DEFERRED branch (`2026-09-10-rbac-wave-4.md:466-474`). |
| B4-2 — contradictory rerun semantics | **NOT CLOSED.** Browser replay was withdrawn correctly, but the command-level rerun is not tenant-targeted and `--reuse` has no leg selector (`2026-09-10-rbac-wave-4.md:497-500,548-562`). |
| B4-3 — incomplete campaign runner | **NOT CLOSED.** The manifest is an illustrative fragment, cannot represent multi-lane rows, and would repeat shared mutating commands (`2026-09-10-rbac-wave-4.md:370-426`). |
| M4-1 — nonexistent POS Playwright harness | **CLOSED** with vitest plus `NOT_SCRIPTABLE` manual replay (`2026-09-10-rbac-wave-4.md:490-495`). |
| M4-2 — duplicate campaign runner | **NOT CLOSED.** Shared-library direction is correct, but the plan says it will extract options and a ledger accumulator that do not exist in the onboarding script (`2026-09-10-rbac-wave-4.md:454,497`; `scripts/campaign-onboarding.sh:3-63`). |
| M4-3 — browser coverage overclaims | **CLOSED** by moving non-browser scenarios to their proper lanes (`2026-09-10-rbac-wave-4.md:464,478-495`). |
| M4-4 — unconditional global assertions | **CLOSED** through conditional applicability (`2026-09-10-rbac-wave-4.md:82-103`). |
| M4-5 — EC-32c wrong lane | **NOT CLOSED.** The lane classification is corrected, but the manifest’s shell command has the wrong working-directory-relative path and multi-lane rows remain unrepresentable (`2026-09-10-rbac-wave-4.md:405-414`). |
| m4-1 — placeholder paths | **NOT CLOSED** (`2026-09-10-rbac-wave-4.md:437-445`). |
| m4-2 — Convention 09 citation | **CLOSED** (`2026-09-10-rbac-wave-4.md:49-50`). |

### BLOCKER

1. **The command-level rerun is not scoped to R0’s tenant.** It invokes `permissions:sync-fleet` without a tenant selector and uses a default-connection `tinker` query for row counts (`2026-09-10-rbac-wave-4.md:548-562`). In a database-per-tenant system, that neither selects R0’s tenant database nor proves same-tenant idempotence. The dry-run “query-log count” also has no supplied harness.

2. **`--reuse` cannot express the rule it is meant to enforce.** The parser gains `--reuse`, `--lanes`, and `--row`, but no `--leg` or equivalent browser-leg selector (`2026-09-10-rbac-wave-4.md:497-500`). Since the default browser run contains nine legs and `--row` selects EC manifest rows, “refuse more than one leg” is not implementable as written.

3. **The manifest schema cannot represent the lane table.** Rows such as EC-5, EC-14, EC-28, and EC-31 have multiple execution homes, while the YAML schema has one scalar `lane` and one scalar `command`; only EC-31 receives an ad hoc `also` field, and even that omits its PG command (`2026-09-10-rbac-wave-4.md:315-350,370-419`).

4. **The runner would execute shared mutating browser legs repeatedly.** Several EC rows map to one browser leg—R2 covers EC-1/2/3/4b and R4 covers EC-5/9/10—yet the manifest model is row→command and the runner says it executes every row’s command (`2026-09-10-rbac-wave-4.md:415-426,464-473,497-499`). No leg entity or command-deduplication rule prevents repeated destructive execution on a single-use tenant.

5. **The staging replay does not execute the non-browser lanes against staging.** `--api` and `--web` only redirect HTTP targets; the manifest’s PHPUnit, Redis, shell, database, queue, and boot-status commands still run locally (`2026-09-10-rbac-wave-4.md:565-580`). The checklist therefore cannot establish staging database topology, queue retry, Redis atomicity, or entrypoint behavior.

### MAJOR

1. **The EC-32d command resolves from the wrong directory.** The file is staged as `apps/api/tests/EdgeCases/entrypoint-boot-status.sh`, while the manifest uses `tests/EdgeCases/entrypoint-boot-status.sh` with `cwd: .` (`2026-09-10-rbac-wave-4.md:405-414,437-444`). The manifest guard will fail.

2. **The manifest is still a prose placeholder.** It ends with “one entry per enumerated id” rather than supplying all 57 entries (`2026-09-10-rbac-wave-4.md:415-425`). The coverage guard is described, not implemented, so Phase 0 cannot truthfully start with a red test over a complete artifact.

3. **Task 4-2’s supposedly exact add list contains a wildcard placeholder.** `<each new tests/Feature/EdgeCases/Rbac/*.php file, named>` is not a path (`2026-09-10-rbac-wave-4.md:437-445`).

4. **The shared-library extraction premise is inaccurate.** The existing onboarding runner contains basic argument parsing, URL preflights, and run-id handling, but no `--reuse`, `--lanes`, `--row`, or ledger accumulator (`scripts/campaign-onboarding.sh:3-63`; `2026-09-10-rbac-wave-4.md:497`). `CampaignLibParityTest` checks only help and preflight-failure output, not successful invocation, environment propagation, exit accumulation, or existing evidence behavior.

5. **R4’s RETIRED arm requires a direct tenant-database query but defines no database access path for a remote campaign.** The browser leg is otherwise HTTP-driven; a local query would inspect the wrong database in staging (`2026-09-10-rbac-wave-4.md:468-473,565-580`).

### MINOR

1. R0 says it mirrors the onboarding campaign but posts `vertical: pharmacy`; the onboarding fixture is explicitly Parapharmacy (`2026-09-10-rbac-wave-4.md:232-242`; `docs/qa/ONBOARDING-CAMPAIGN.md:22,317-325`). Both are valid enum values, but this is not the claimed same fixture.

2. Its producer note requires the same mechanical wave-1 rev 6→6.1 re-pin as Waves 2 and 3 (`2026-09-10-rbac-wave-4.md:7`).

**Minor-only status: No. Wave 4 retains five blockers.**

## Cross-plan consistency

1. **Producer pin:** all three plans say wave 1 rev 6 `177b3d7d7` is latest. Current producer rev 6.1 is `ff2a949e9` (`2026-09-10-rbac-wave-2.md:7`; `…wave-3.md:7`; `…wave-4.md:7`). Rev 6.1 changes only imports, so `PermissionSyncService::sync(SyncScope,bool)`, `SyncScope`, `SyncRollback`, `verifyAdmin`, `RoleSyncedV1`, the 327-key catalogue, `permissions:sync --compat`, baseline paths, and `LOCKED_ENTRY_POINTS` remain correctly consumed. The scaffold CLI mismatch is the one substantive producer-consumer break.

2. **Wave-0b pin:** rev 6 `960548c60` is correctly named, and the consumed `PermissionWriteLock`, W-LOT key, census constants, and fleet-runner signature did not move (`2026-09-10-rbac-wave-2.md:7,25-38`). The post-lock query oracle is nevertheless incorrect because `acquire()` is invoked twice.

3. **Ordinals:** `Phase 0.4`, `0.5`, `0.6`, and `0.7` conform to the repository format (`AGENTS.md:15-16`). Wave 1 uses `Phase 1.<task>`; `0.3` and `0.5.1` are deliberate gaps (`2026-09-10-rbac-wave-2.md:107,2459`). This is settled and non-colliding.

4. **Exact staging:** all three plans still violate their claim of exact `git add` lists through missing blocks or literal placeholders (`2026-09-10-rbac-wave-2.md:471-2278`; `…wave-3.md:368-682`; `…wave-4.md:437-445`).

5. **Task truthfulness:** representative full PHP blocks for `LastAdminFloor`, `EffectivePermissionResolver`, and `RoleCreated` pass `php -l` when wrapped with their declared namespace/import context (`2026-09-10-rbac-wave-2.md:523-809,1233-1495,1156-1188`). The failures reported above are semantic/load-order defects or omitted bodies, not superficial fragment-lint false positives.

6. **The 17 Q-w rulings:** Q-w2-1 through Q-w2-6, Q-w3-1 through Q-w3-6, and Q-w4-1 through Q-w4-5 remain consistent with the accepted spec. They do not require new owner escalation. Defects above are implementation-plan corrections, not policy questions (`2026-09-10-rbac-wave-2.md:286-350`; `…wave-3.md:88-142`; `…wave-4.md:122-174`).

## Citation audit

Verified correct against code:

- Login query and `Hash::check`: `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-258`.
- POS holder query, PIN hash check, PIN writers, and `hasPins`: `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57,87-90,120-146,295-307,336-344`.
- Sole production MembershipRole authorization read: `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`.
- Global API middleware group and priority: `dev:apps/api/bootstrap/app.php:143-188`.
- Marketplace current tenant stack and central target stack: `dev:apps/api/app/Modules/Marketplace/Presentation/routes.php:87-105`; `dev:apps/api/routes/api.php:56-59`.

Wrong or stale citations:

- All three base headers still declare worktree HEAD `72498e83f` and `dev` `630afa86f`; actual HEAD is `3dc54ac86` and `dev` is `33796cc08` (`2026-09-10-rbac-wave-2.md:43`; `…wave-3.md:38`; `…wave-4.md:35`). The cited application code has not changed, so line content remains valid.
- Wave 2’s “four-step down” references are stale; the body has five operations (`2026-09-10-rbac-wave-2.md:156,333,419,1919-1936,2423`).
- Wave 2’s verification says exactly two census exemptions while the map contains three (`2026-09-10-rbac-wave-2.md:866-878,2314`).
- Wave 3 retains three obsolete 127-route references (`2026-09-10-rbac-wave-3.md:78,256,411`).
- Wave 3 names the wrong baseline directory and nonexistent locale files (`2026-09-10-rbac-wave-3.md:579,679-681`).
- Wave 4’s EC-32d manifest path is incorrect relative to its declared working directory (`2026-09-10-rbac-wave-4.md:405-414`).

## Rejected false positives

- **176 is correct.** A real CSV parse gives 642 `MIDDLEWARE`, 142 `AUTH_ONLY`, 130 `CONTROLLER`, 68 `SUPERADMIN_ONLY`, 46 `FORMREQUEST`, 18 `PUBLIC`, and 8 `POLICY`: 1,054 API rows total. The enforcement sweep is 130 controller plus 46 FormRequest routes. A fresh shared-checkout `route:list --json` produced 1,092 total routes and the same 1,054 normalized API method/URI pairs.
- **The edge-case register has 57 enumerated rows.** The prose count of 58 includes withdrawn EC-22. Wave 4’s WITHDRAWN handling is correct (`2026-09-10-rbac-wave-4.md:315-350,419-425`).
- **The migration rollback body now restores the prior users schema.** Only its labels are stale (`2026-09-10-rbac-wave-2.md:1919-1936`).
- **`ScopedTokenIssuanceEntryConditionTest` is falsifiable.** It loads the real sorted baseline and names the three forbidden source files (`2026-09-10-rbac-wave-2.md:1707-1769`).
- **The resolver’s core narrowing equation is present.** Owner grants are intersected with known `permission:` abilities, and unknown abilities are separated; the defects are self-selector and DTO-null semantics (`2026-09-10-rbac-wave-2.md:1333-1495`).
- **Seat coverage is complete in intent.** All six counters and every plan tier, including trial, are named (`2026-09-10-rbac-wave-2.md:2211-2227`).
- **The POS ladder-removal order is now correct.** `fetchDiscountPermissions()` already exists and is wired before legacy-role removal (`2026-09-10-rbac-wave-3.md:482-512`; `dev:apps/pos/src/stores/operatorStore.ts:128-155,269,294,368,446`).
- **R9 is correctly not Playwright.** The vitest plus dated device replay is consistent with the repository harness (`2026-09-10-rbac-wave-4.md:490-495`).
- **Creating a second campaign is acceptable.** The RBAC campaign exercises different mutable state; sharing the stable runner primitives remains the right direction (`2026-09-10-rbac-wave-4.md:450-454`).

## Preserve

Preserve the accepted r9 contracts: Direction B; D1–D8; tenant-scoped roles and memberships; NULL-team semantics; the single W-LOT advisory-lock key and ordering; exact template deltas without custom/customised grants; `admin = activeKeys()`; both sync triggers; dry-run failure preservation; fleet selectors; owner-grants ∩ token-scope narrowing; human-only admin floor pending OQ-1; central Sanctum storage and per-row TTLs; names-only `roles.view` shaping; immutable domain events; the two schema migrations; generated TypeScript permission union and labels; and the wave-0a foundations (`2026-09-10-rbac-spec-codex-gate-r9.md:190-205`).

Also preserve these rev-2 corrections:

- `principal_kind` schema in 2a and no structural helper stubs.
- `EffectivePermissionResolverInterface` plus implementation bodies.
- Floor lock order: shared lock, roles, users, `model_has_roles`.
- The 176-route sweep and POS-last order.
- The 57-row/58-prose campaign accounting.
- RETIRED/DEFERRED branching.
- Single-run-per-fresh-tenant browser semantics.
- R9 as vitest plus documented manual replay.
- `Phase 0.4/0.5/0.6/0.7.<task>` ordinals.

## Owner decisions required

Only the accepted Wave-2 policy questions require owner input:

1. **OQ-1:** whether service principals count toward the last-admin floor. The plan defaults to **no** (`2026-09-10-rbac-wave-2.md:276-279`). Because the human-only floor now ships in 2a, this decision must be obtained before **2a**, not merely before 2b as stated at `:2454`.

2. **OQ-2:** token TTL defaults—service 365 days, human 90 days, POS one year (`2026-09-10-rbac-wave-2.md:280-284,2455`). Decide before 2b issuance behavior lands.

No Q-w point should be escalated. OQ-3 and OQ-4 are programme-level prior owner obligations, not questions these plans may reopen.

## Dispatch assessment

- **Wave 2:** not dispatchable—four blockers, including an invalid database invariant, a broken producer CLI call, an uncompilable task order, and an unreachable denial-audit design.
- **Wave 3:** not dispatchable—three blockers in the enforcement generator and request-fixture matrix.
- **Wave 4:** not dispatchable—five blockers in rerun targeting, reuse selection, manifest modeling/execution, and staging replay.
- The rev-2 change logs overstate closure in all three plans, particularly around exact staging lists and executable test fixtures.

VERDICT: CHANGES-REQUIRED