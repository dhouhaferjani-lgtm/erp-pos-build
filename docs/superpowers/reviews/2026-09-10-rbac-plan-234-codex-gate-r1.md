# AutoERP RBAC plans — Codex PLAN gate round 1

`git rev-parse --short HEAD` → `d089578cc`

Code basis: `dev` at `630afa86f`; `lane/w-lot-a-1a` at `52f5ad796`; `lane/t2-receipt-spine` at `951a7637e`; `lane/t1-transfers-edge` at `86273346a`.

## Wave 2

### BLOCKER

1. **`LastAdminFloor` violates the shared writer-lock protocol and omits a required lock set.** The plan makes the floor the first statement in the mutation transaction, but inherited role-grant writers must acquire `PermissionWriteLock` first, using the existing `wlota1a:<tenant>` key and deterministic role order. The proposed implementation locks candidate users but not `model_has_roles`, despite the accepted lock order requiring users and pivots. The dedicated service-account role writers likewise lack an explicit shared lock, transaction and locked re-read. This can deadlock or permit a concurrent last-admin violation.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:447-453,532-536,611-624,1349-1354`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1038-1067,1167,1598-1606`; `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:197`.

2. **Lane 2a knowingly ships authorization helpers with no behavior.** `refuseServiceTarget()` returns `null`, while the human-only portion of `LastAdminFloor` is also deferred until `principal_kind` exists. These are production-facing structural stubs, not implementation-complete commits. Either land the schema before their callers or move the helpers and callers together into 2b.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:174-175,271-277,404-405,571-587`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:335-349`.

3. **The promised resolver is not implementable as written.** The concrete `EffectivePermissionResolver` example declares `forSubject()` and `forTarget()` with semicolons and no bodies. It does not specify the owner-grant lookup, `permission:` parsing, unknown-ability handling, token-id selector semantics or DTO assembly needed to prove `owner grants ∩ token scope`. It must either be a real interface with a named implementation or include the complete algorithm and tests.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:835-869`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:203-247`.

4. **Several named failure types/events have no deliverable.** `LastAdminFloorViolation`, `LastAdminFloorViolationException` and `AdminPermissionFloorViolation` appear in the implementation text, but are absent from the created-file inventory. More importantly, EC-13 requires a stable `RoleUpdateRefused` event with `role_id`, `reason`, `principal_id` and `token_id`; the plan creates only RoleCreated/Updated/Deleted and AuthorizationDenied.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:145-151,398-400,455-457,545,609,638-654,674-700,744-750`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2078-2082`.

### MAJOR

1. **Human-token TTL is declared but not implemented.** The plan chooses explicit per-row TTLs—human 90 days, service 365 days, POS one year—but Task 2b-5 does not modify the human login issuance path. Current code still passes `null` for human tokens and therefore applies the global 30-day policy.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:247-251,1365-1373`; `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:146-156,294-310`; `apps/api/config/sanctum.php:43-53`.

2. **Migration rollback does not restore the prior users schema on SQLite.** Up creates a PostgreSQL CHECK or an SQLite trigger pair; down says only to drop “the CHECK” before dropping columns. It must explicitly drop both SQLite triggers and restore every prior nullable/index/constraint state in reverse order.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1149-1170`.

3. **The last-admin writer census contradicts the file inventory.** The inventory says `RoleController::assignRole` and `UserController::activate` receive the floor. The later writer table says assignment is exempt and does not account for activation/restoration. Growing set A is a legitimate exemption, but it must be declared and mechanically represented; otherwise the proposed census fails its own rule.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:174-175,400,611-624`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1598-1604`.

4. **Authorization-denial source detection is unspecified.** The middleware promises to record only authorization-gate 403s, but “assert the 403 came from an authorization gate” is not an implementation mechanism. It needs a concrete exception/tag/hook distinguishing `can:`, `RequireAnyPermission` and `Gate::authorize` denials from controller/domain 403s, plus negative tests for non-authz 403s.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:894-904,958-975`.

5. **The 2a entry condition weakens an accepted programme gate.** Wave 2 substitutes “one deploy cycle” for Wave 1’s required one-week staging soak. Phase zero may verify completion, but it cannot redefine it.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:265-269`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:151-154`.

6. **The generic-target census is over-broad and internally contradictory.** It claims every public method resolving a route-bound user must call the refusal, while the accepted contract keeps user reads open. Restrict the static rule to the enumerated write methods and explicitly exempt reads and create-without-target.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:203-206,1348-1356`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:337-349`.

7. **The role-name entry-condition test is falsifiable, but its baseline dependency is not pinned.** Comparing a fixed baseline and a live grep is a valid pre-token-issuance gate. However, Wave 2 invents `tests/PHPStan/baselines/role-name-authorization-baseline.json`; the current Wave 1 plan does not declare that exact path or schema. Re-pin it after Wave 1 is corrected.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1018-1045`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1641-1653`.

### MINOR

1. **`pinHolders()` editing instructions imply three predicate insertions although `pinData()` and `hasPins()` already consume the shared helper.** State that the human predicate is added once, inside `pinHolders()`, with three behavioral tests.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:227,1438-1446`; `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57,170-176,336-344`.

2. **Q-w2-5 says “all three keys” while allowing one to exist.** Phase zero can scaffold only absent keys, but the wording and expected count should be conditional rather than fixed.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:279-285`.

## Wave 3

### BLOCKER

1. **The route arithmetic is wrong.** A standards-compliant CSV parse yields 1,054 rows with:

   - `CONTROLLER = 130`
   - `FORMREQUEST = 46`
   - total sweep = **176**, not 171.

   The CSV has valid quoted fields; the alleged five “unescaped comma” artifacts do not exist. A fresh `route:list` at `dev:630afa86f` produced the same 1,054 method/URI pairs as the CSV. Correct cluster deltas are Identity **7+7**, Workshop **14+15**, POS **91+1**; the other listed cluster counts are unchanged.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:188-199,264,278,291-298,321-325,535`; `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv:1`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1482`.

2. **Task 3-6 cannot empty the permission-literal baseline within its declared file boundary.** It says Tasks 3-1/3-2 have already converted 608 route middleware strings, but those tasks cover only the 176 controller/FormRequest classifications. Hundreds of pre-existing middleware literals and 309 call sites remain. “Convert whatever remains” without enumerating their owning files, tests and commits is not executable or reviewable.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:444-469`.

3. **The generated per-role matrix has no valid-request source.** `PermissionRegistry` can map permissions to roles, but it cannot manufacture route parameters, prerequisite records or valid mutation payloads. The proposed 8 × ~800 real-request grid therefore cannot distinguish authorization denial from routing, validation or domain failure. Add a route/action fixture registry with setup, parameters, payload and expected mutation—or define a smaller registry-generated action matrix that remains falsifiable.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:531-544`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2273-2282`.

### MAJOR

1. **The POS premise is stale.** `fetchDiscountPermissions` is not tests-only: the production store imports it, calls it inside `resolveOnlineDiscountPermissions()`, and uses that function on offline acceptance, online PIN verification, setup and refresh. The remaining role-name ladder at PIN setup is real, but Task 3-3 must preserve the already-wired terminal-aware flow and remove the residual ladder after its regression test—not reimplement a feature that already exists.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:205-209,253-258,349-365`; `apps/pos/src/stores/operatorStore.ts:4,128-155,269-294,354-369,439-446`.

2. **The glossary table does not preserve the accepted §2 bindings.** It omits the human Profile API-token surface; reports no operator surface for Permission, Permission verb, Manifest and Registry; understates Grant and Membership; and changes General manager’s canonical surface from Settings → Users to Settings → Roles despite saying the existing row must be adopted verbatim. Replace the summary table with the accepted §2 rows or mechanically assert equivalence.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:405-438`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:108-127`.

3. **The `MembershipRole` fallback is mislabeled.** Code confirms the sole production authorization read is `$membership?->isOwner()` in `UserController`. If it cannot be removed, it may be documented as the accepted sole authorization exception; it cannot be placed in `ALLOWED_NON_AUTHZ_READS`.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:390-401`; `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-939`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:123,2265`.

4. **Marketplace re-gating lacks an exact middleware migration and backend proof.** Current seller-admin routes sit under tenant `auth:sanctum`/tenant middleware and `can:marketplace.admin`. The plan must specify the central `auth:sanctum-admin`/`super_admin` route pipeline, removal of tenant context middleware, and tests proving central super-admin allow plus tenant-admin deny.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:517-527`; `apps/api/app/Modules/Marketplace/Presentation/routes.php:87-105`; `apps/api/routes/api.php:37-48,56-97`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1953`.

5. **The POS counting command is not route arithmetic.** `grep -rc` counts textual authorization calls, including multiple calls per route and non-route call sites. The per-controller baseline must come from the live router/classification, with grep used only as a secondary implementation census.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:329-339`.

### MINOR

1. **The POS ordering cross-reference is stale.** The text says the ladder is removed in “Task 3-2 step POS-last”, while the actual ladder removal is Task 3-3. The intended ordering—source first, ladder second—is otherwise correct.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:205-209,349-365`.

2. **The retirement title double-counts `reports.view`.** The authoritative list contains 22 total entries including `reports.view`; it is not “22 deprecated keys and `reports.view`”.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:473-479`.

3. **Commit decomposition is not exact.** Every POS controller uses the same `Phase 0.6.2` subject, and several non-POS “one commit each” steps provide no distinct subjects. This conflicts with the promised independently revertible units.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:291-299,321-341`; `AGENTS.md:16`.

## Wave 4

### BLOCKER

1. **R4 is impossible after a RETIRED Task 3-7 disposition.** The plan correctly says the campaign must branch on RETIRED versus DEFERRED, but R4 unconditionally creates/asserts a surviving, struck-through deprecated grant. After retirement and pruning, a fresh R0 tenant cannot receive or render that grant. Run the struck-through EC-5 browser scenario only for DEFERRED; for RETIRED, assert registry/row/UI absence and point EC-5’s preservation behavior to pre-retirement Wave 1 evidence.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:55-58,236-239,348`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:486-501`.

2. **The required same-tenant end-to-end rerun has no executable semantics.** R0 always registers a fresh tenant, while later legs rename, revoke, deactivate and delete state. No reuse flag, per-leg reset, idempotent fixture algorithm or skip contract is supplied. The onboarding campaign explicitly warns that reuse is for single-leg debugging, not a green rerun after mutating legs.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:98,340-365,391-401`; `docs/qa/ONBOARDING-CAMPAIGN.md:47-58`.

3. **The campaign runner cannot execute the complete register.** Task 4-2 says existing tests are referenced, not copied into `tests/Feature/EdgeCases/Rbac`, but its command runs only that new directory, one Redis test and broad frontend folders. The script promise to run “the PHPUnit lanes” therefore has no command manifest for most existing EC homes. Add a machine-readable row-to-command manifest and an accumulator that runs every named existing and new test while preserving all results.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:285-318,318-329,365`.

### MAJOR

1. **R9 lacks a runnable POS harness.** The declared Playwright config belongs to `apps/web`, preflights only API/web, and has no web server. `apps/pos` has no Playwright dependency or script. “Drive the dev IziPOS build” needs a concrete POS URL/server or Tauri driver, lifecycle and offline-network mechanism; otherwise the device portion must be explicitly NOT_SCRIPTABLE and covered by its POS test plus a separately documented manual device replay.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:109-119,334-365`; `apps/pos/package.json:6-15,49-73`.

2. **The separate campaign duplicates runner infrastructure.** Separate promotion gates, ledgers and known-red lists are defensible, but duplicating argument parsing, preflights, run-id generation and ledger mechanics creates a second implementation of the same campaign protocol. Extract shared runner/ledger primitives or define an explicit parity/version contract while retaining separate RBAC and onboarding journeys.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:109-119,158-164,338-365`; `scripts/campaign-onboarding.sh:3-63`; `docs/qa/ONBOARDING-CAMPAIGN.md:41-45`.

3. **Several browser rows overclaim their EC coverage.** R2 does not exercise EC-4’s service-admin exclusion or EC-4a’s membershipless user. R8 does not induce EC-16a’s central token-delete failure and queued retry, and it does not exercise EC-32’s login, impersonation and all PIN-writer refusals. Keep those rows in their PG/staging homes and narrow the browser mappings.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:285-305,346,352-364`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2085,2109`.

4. **Global browser assertions are unsatisfiable.** Not every browser leg has a refusal toast, and not every denial represents an attempted row write. Require toast text only for UI refusal paths; require unchanged rows only for rejected mutations. Keep zero-console/zero-5xx universal.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:74-86,442-443`.

5. **EC-32c is assigned to the wrong test family.** EC-32c is the marked-tenant `permissions:ensure` behavior; EC-32d is the entrypoint boot-status pair. The current table groups both with service-account tests and names only the boot-status pair. Assign EC-32c to its ensure/marked-tenant PG test and EC-32d to the shell test.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:302-304,411`; `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2112-2113`.

### MINOR

1. **Several file boundaries are placeholders rather than paths.** The evidence filename retains `2026-09-xx`; browser files use `R0..R9-*`; tests use `*`; and the promotion-checklist reference is a wildcard although the repository has a dated concrete file. Resolve every path before implementation.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:115-118,125-127,225,268,336`.

2. **The Convention 09 citation is off by two lines.** Data-meaning guidance is at lines 49–50; line 52 begins the architecture-ratchet section.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:86`; `docs/conventions/09-SECOND-OF-EVERYTHING.md:49-52`.

## Cross-plan consistency

### BLOCKER

1. **Shared write-lock ordering is not consistently inherited from 0b into Wave 2.** Wave 3’s prune writer correctly says `PermissionWriteLock::acquire()` first and preserves role ordering; Wave 2’s floor/service-role writers do not. All participating writers must use the same key and order before any floor-specific locking.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:611-624,1349-1354`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:466`; `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:197`.

### MAJOR

1. **Commit ordinals need re-pinning after Wave 1 is corrected.** The current Wave 1 plan declares `feat(...)` subjects, not a `Phase 0.3.*` series. Waves 2–4 may not assume `0.4/0.5/0.6/0.7` until the predecessor history is real. `AGENTS.md` defines syntax, not these programme ordinals. This is a downstream re-pin finding, not a repetition of the Wave 1 gate result.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:358,605,801,1755`; `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:94`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:180-186`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:64-66,141-145`; `AGENTS.md:16`.

2. **Exact Wave 1 baseline artifacts are not consumable yet.** Wave 2 hardcodes a PHPStan baseline path; Wave 3 describes an “ESLint companion baseline” as belonging to `apps/api/phpstan.neon`. Wave 1 currently names neither exact ESLint file nor stable schema. Both downstream consumers must be re-pinned after the Wave 1 plan supplies concrete outputs.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1641-1653`; `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1025`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:349-352`.

3. **None of the three plans supplies exact per-commit `git add` lists.** A global instruction to use explicit paths does not define atomic boundaries. File tables contain globs, ellipses and placeholders, while tasks promise multiple commits. Each commit needs a concrete subject, exact add list, prerequisite and rollback point.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:94-101`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:80-84`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:64-72`.

### Q-w disposition

All 17 Q-w items are plan-author/mechanical decisions. None creates a fifth owner question.

- **Must be corrected or re-pinned:** Q-w2-1, Q-w2-3, Q-w2-4, Q-w3-1, Q-w3-2, Q-w3-4 and Q-w4-1.  
  Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:255-285`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:180-209`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:138-145`.

- **Plan-author decisions that may stand:** Q-w2-2, Q-w2-5, Q-w2-6, Q-w3-3, Q-w3-5, Q-w3-6, Q-w4-2, Q-w4-3, Q-w4-4 and Q-w4-5, subject to the implementation corrections above.  
  Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:270-290`; `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:199-220`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:147-170`.

## Citation audit

All concrete `dev:` and lane references were checked against the declared tips. The following are wrong, stale or insufficiently pinned:

1. The CSV “unescaped commas” explanation and 127/44 result are wrong; the quoted CSV parses to 130/46.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:188-197`.

2. `fetchDiscountPermissions` being “tests only” is stale. It is used in production.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:207-209,253-258`; `apps/pos/src/stores/operatorStore.ts:4,128-155`.

3. Wave 2’s inventory incorrectly says the floor is wired to `RoleController::assignRole` and `UserController::activate`; the task’s own later writer table disagrees.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:174-175,611-624`.

4. The PHPStan role-name baseline path is not currently declared by Wave 1.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1025`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1641-1653`.

5. `apps/api/phpstan.neon` is not an exact location for an ESLint companion baseline.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:352`.

6. Wave 4’s evidence, browser, test and promotion-checklist paths contain placeholders/globs.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:115-118,125-127,225,336`.

7. Wave 4 cites Convention 09 line 52 for text located at lines 49–50.  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:86`; `docs/conventions/09-SECOND-OF-EVERYTHING.md:49-52`.

The important current-code anchors are otherwise accurate: login credential check at `AuthController.php:253-258`; POS PIN hash check at `PosAuthController.php:87-90`; `pinHolders()` at `PosAuthController.php:46-57`; RoleController read/write methods at the cited ranges; `MembershipRole`’s sole authz read at `UserController.php:929`; global API middleware and priority in `bootstrap/app.php:143-188`; and the current tenant Marketplace route stack at `Marketplace/Presentation/routes.php:87-105`.

## Rejected false positives

1. **The 57/58 Wave 4 register explanation is valid.** There are 57 active table IDs; the prose count reaches 58 by retaining withdrawn EC-22.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:147-156,285-317`.

2. **POS is correctly scheduled last.** Its CSV classification is 91 controller plus one FormRequest row; only the global denominator is wrong.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:199-203,321-340`.

3. **The FormRequest conversion rule is sound.** Authorization moves to route middleware and `authorize()` becomes true or an exact duplicate, with allow/deny mutation assertions.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:278-286`.

4. **`MembershipRole` really has only one production authorization read.**  
   Citations: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:259-263`; `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-939`.

5. **`ScopedTokenIssuanceEntryConditionTest` is falsifiable in concept.** Its baseline plus live-source scan can fail when residual role-name authorization remains; only the exact baseline artifact needs re-pinning.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:1018-1045`.

6. **Service-account route cardinality is coherent.** The eight base routes plus the membership and role pair yield the intended surface.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:195-201,1295-1302`.

7. **The service-recipient and seat-counter censuses are complete in scope.** The plan covers invitation, reset and notification selectors, all six counters, and per-tier limits including trial.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:202-207,225-231`.

8. **Token-scope middleware placement is correct.** It is attached globally, ordered after tenant-claim enforcement, tested on live routes, and explicitly no-ops for central subjects without tenant queries.  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:193-210,226-230`.

9. **The plan correctly locates `EffectivePermissionResolver` in Wave 2a, not Wave 1.**  
   Citation: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:140-144`.

## Preserve

The revisions must retain the accepted r9 bindings:

- Direction B and D1–D8.
- Tenant-scoped roles, membership-carried company/location scope and legacy NULL-team roles without rehoming.
- W-LOT marker/CHECK/default-off behavior, its exact lock key and deterministic role order.
- Separate `matchesPreWave0b()` and `matchesVersion0()`.
- Exact deltas only for pristine system roles; never automatic grants to custom/customised roles.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two sync triggers.
- Dry-run write-free but failure-preserving.
- Settled `TenantFleetRunner` selector behavior.
- Human/service principals and `owner grants ∩ token scope`.
- Human-only last-admin default pending OQ-1.
- Central Sanctum rows and per-row TTL precedence.
- `roles.view` response shaping.
- Immutable/versioned audit events.
- Two additive migrations, generated TS permissions, en/fr/ar labels.
- All six accepted 0a tasks and the accepted manifest overlap.

Citation: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-210`.

## Owner decisions required

Only the accepted four remain:

1. OQ-1: whether service accounts count toward the last-admin floor.
2. OQ-2: default service-token TTL.
3. OQ-3: whether any of the eleven SoD baseline combinations should be retired.
4. OQ-4: whether `SYNC_PERMISSIONS_ON_BOOT` becomes production-default true after the full Wave 1 soak.

The Wave 2–4 defects above are plan-writing corrections under settled behavior and must not be escalated as new owner decisions.  
Citation: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:212-221`.

VERDICT: CHANGES-REQUIRED