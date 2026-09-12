# Codex plan gate r6 — RBAC waves 2, 3, 4 plans (2 rev 6.1 / 3 rev 6.1 / 4 rev 6) (gpt-5.6-sol, high, read-only, 2026-09-11)

`git rev-parse --short HEAD` → **`70384d7e0`**

Code reference: `dev` → `33796cc08`.

## Wave 2

### Rev-5 closure table

| Rev-5 item | Status in rev 6/6.1 | Evidence |
|---|---|---|
| B1 — bare production permission literals and invalid enum names | **CLOSED in the operative task bodies.** All new production checks use generated enum cases with `->value`; the eleven-key origin table is present. | `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:131-146`; production fragments at `:1278`, `:1781-1784`, `:2573-2576`, `:3127`, `:3149`, `:3160`, `:3179-3180` |
| m1 — denial-recorder prose described only the wrapped `AuthorizationException` producer | **CLOSED.** The three producer shapes now match the supplied implementation. | `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2405`; change-log disposition at `:4254` |
| Rev 6 → 6.1 Q-w2-7 re-spelling | **Operative task bodies CLOSED; top-level contract still contradicts the ruling.** The tasks scaffold the three renamed keys and make no `legacy()` call, but the producer-contract table still says the POS key cannot be generated and must be hand-added. | Correct ruling at `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:3,145-146,382-401,2561-2566,3067-3078`; contradictory instruction at `:41` |

### BLOCKER

None.

### MAJOR

#### W2-M1 — the top-level producer contract still directs a forbidden hand edit

The `PermissionManifest` contract first says the scaffold has no `--legacy` option and that service-account keys are generated, but then says `pos.override_discount_limit` cannot be produced and must be hand-added as an explicit `PermissionDefinition`. That is the exact opposite of amendment A-2 and the operative Task 2a-8 body, which correctly derives it as resource `pos`, verb `Override`, qualifier `discount_limit`, with no `legacy()` use.

An implementer following the producer-contract table and one following Task 2a-8 would produce different commits. Delete the stale hand-add sentence and state that all three A-2 keys derive through `permissions:scaffold`.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:41` versus `:145`, `:2561-2566`; accepted amendment at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:39-70`.

#### W2-M2 — one prescribed commit subject violates the plan’s own numeric patch rule

The plan rejects a non-numeric segment because the repository pattern is “three integers,” but Task 2a-2a prescribes `Phase 0.4.2a`. The lane allocation itself—`0.4` and `0.5`—is collision-free; the defect is the patch component. Allocate an unused numeric patch and update cross-references.

Evidence: `AGENTS.md:15-16`; numeric-shape ruling at `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:317-330`; invalid subject at `:1116`.

### MINOR

#### W2-m1 — known fallback scaffold typo

The fallback command uses module `Inventory`, resource `countings`, which derives `countings.create`, not `inventory.countings.create`. It must be:

```text
php artisan permissions:scaffold Inventory inventory.countings create --template=manager --template=general_manager
```

This is the user-declared non-blocking editorial leftover, but it matters because the Wave-1 Inventory enum does not declare `InventoryCountingsCreate`; the fallback will actually be taken.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2572`; Wave-1 Inventory enum block at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4079-4107`.

#### W2-m2 — stale pre-amendment spec statements remain in operative prose

The plan repeatedly says the accepted spec still carries the old POS/token spellings or a hyphenated counting spelling. Rev 9.2 already contains amendment A-2, and the programme execution plan already uses `service-accounts.tokens.create`.

Stale sites:

- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:144`
- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:368`
- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:401`
- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2569`
- `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:4239-4243`

Current sources: `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:39-70,498-510`; `docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md:193-200`.

#### W2-m3 — stale Wave-1 producer pin

Wave 2 remains pinned to Wave 1 rev 6.3 `949dfabdf`. The current dispatch-ready producer is rev 6.4 `70384d7e0`. This is a mechanical re-pin only: rev 6.4 changed one residual’s spelling and no consumed artifact or signature.

Evidence: stale pin at `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:15,41-47,147`; producer change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11151`.

**Wave 2 verdict: CHANGES-REQUIRED.**

## Wave 3

### Rev-5 closure table

| Rev-5 item | Status in rev 6/6.1 | Evidence |
|---|---|---|
| B1 — complete fixture JSON absent | **CLOSED structurally.** The embedded file parses as 20 sorted, unique entries with no unresolved tokens. | `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:1765-2272` |
| B2 — one existence predicate could not model delete/count | **CLOSED structurally.** `expect.kind` contains 8 create, 8 update, 2 delete and 2 count entries, with distinct predicates. | `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3310-3373` |
| B3 — invented `pos_pin_hash` column | **CLOSED.** `IdentityFixture::serviceAccount()` now writes `pos_pin`. | `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:2437-2461`; `dev:apps/api/app/Modules/Identity/Domain/User.php:40,81-97,115-126` |
| M1 — operative correction table disagreed with fixtures | **CLOSED.** The JSON now uses `workshop_work_orders`, `scheduling_appointments`, and `product_batches.is_recalled`. | Rev-5 disposition at `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3708` |
| Rev 6 → 6.1 key spelling | **CLOSED.** Fixture permission is `service-accounts.tokens.create`; route name `service-accounts.tokens.issue` correctly remains unchanged. | `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3,2127-2150,3653-3663` |

### BLOCKER

#### W3-B1 — the role matrix never gives either HTTP actor company access

`RolePermissionMatrixTest::setUp()` creates a company and seeds roles. The test creates the matrix principal and fixture admin and assigns Spatie roles, but creates no `UserCompanyMembership` for either actor and sends no `X-Company-Id`.

On authenticated API routes, `CompanyContextMiddleware` chooses the header or the user’s default membership and returns `403 NO_COMPANY_ACCESS` when no membership exists. Consequently:

- expected allow cells fail before the action gate;
- expected deny cells can pass with 403 for the wrong reason;
- the mutation oracle cannot distinguish permission denial from missing company access.

The repository’s existing RBAC test explicitly creates the membership after creating its admin, demonstrating the required fixture shape.

Evidence:

- incomplete setup: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3224-3255`
- request: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3282-3307`
- global middleware: `dev:apps/api/bootstrap/app.php:144-153,166-184`
- refusal: `dev:apps/api/app/Http/Middleware/CompanyContextMiddleware.php:93-106,122-141`
- established test shape: `dev:apps/api/tests/Feature/Identity/RBACTest.php:61-74`

Both actors need active membership in `$this->company`; the request should use the same company-context convention as production. Deny cells should additionally assert the expected denial ability/code, not merely any 403.

#### W3-B2 — the supplied matrix class is missing a production-reached import

The class uses `Http::fake()` for the enrichment fixture but does not import `Illuminate\Support\Facades\Http`. That cell resolves `Http` as `Tests\Feature\Authorization\Http` and fails before making the request. The default arm also uses unqualified `LogicException` without importing it.

Evidence: imports at `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3172-3184`; uses at `:3264-3270` and `:3369-3371`.

The rev-6 claim that the full supplied class is runnable is therefore false.

### MAJOR

None beyond the matrix blockers.

### MINOR

#### W3-m1 — stale accepted-spec and producer revision labels

The plan still labels the accepted spec rev 9 and its producer pin Wave 1 rev 6.3. These should be rev 9.2 and Wave 1 rev 6.4 `70384d7e0`. The Wave-1 name consumed by Wave 3—`service-accounts.tokens.create`—is already correct, so this is mechanical.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:15,25`; current spec amendment at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:39-70`; Wave-1 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11151`.

**Wave 3 verdict: CHANGES-REQUIRED.**

## Wave 4

### Rev-5 closure table

| Rev-5 item | Status in rev 6 | Evidence |
|---|---|---|
| B1 — manifest guard wrong root, 22/58 contradiction, no PHPUnit filter resolution | **CLOSED.** Root, distinct referencing-row count and non-empty `--list-tests --filter` checks are implemented. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:976-1195` |
| B2 — disposable marker invoked through an impossible tenant command | **CLOSED in the implementation.** Central migration/model changes and dedicated mark/check commands replace the invalid census option. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1250-1364,1747-1778` |
| B3 — isolated databases were neither created nor cleaned | **Partially closed.** Creation and trap cleanup now exist, but the migration and lane wrappers do not bind the declared remote connection environment, so the isolated pair is not actually the pair exercised. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1780-1818,1895-1927,1988-2004` |
| B4 — EC-16a not integrated | **NOT CLOSED.** Functions are invoked, but observation occurs before the forced-failure row and does not assert or correlate the queued job. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1843-1888,2040-2069` |
| B5 — impossible Task 4-3 add list | **CLOSED.** Phantom parity-test path is gone and the guard file is restaged when its exemption shrinks. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2147-2170` |
| m1 — reuse with `--lanes` | **CLOSED and exercised.** | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1690-1699` |
| m2 — inconsistent shared-library ownership | **NOT fully closed editorially.** The supplied library is correct, but two active descriptions still assign the ledger accumulator/unknown-argument/final-exit contract to it. | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1388,1602-1606` versus `:1494-1516` |
| m3 — duplicate `die2()` | **CLOSED.** | `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1640-1642` |
| m4 — executable password ellipsis | **CLOSED.** | Rev-5 disposition at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2628` |

### BLOCKER

#### W4-B1 — the staging wrapper does not transmit the manifest’s environment

The manifest declares the PG lane with `DB_CONNECTION=pgsql`, host, port, tenant database and central database, and the Redis lane with host, port, DB 14 and `CACHE_STORE=redis`. The runner exports those values only in the local subshell surrounding `eval`; the generated command itself is an SSH command. Ordinary SSH does not forward arbitrary exported variables.

The remote wrappers set only:

- PG: `DB_DATABASE`, `DB_CENTRAL_DATABASE`, `RBAC_CAMPAIGN_TENANT`;
- Redis: `REDIS_DB=14`.

They omit `DB_CONNECTION`, DB host/port and Redis host/port/cache store. The migration preflight makes the same omission.

This is load-bearing because production uses `DB_CONNECTION=central`; the `central` connection reads `DB_CENTRAL_DATABASE`, while `pgsql` reads `DB_DATABASE`. As written, `php artisan migrate --force` can migrate only the central database while the freshly created `autoerp_staging_campaign` remains unused. The PG tests can likewise run with the deployed connection instead of the manifest’s isolated lane.

Evidence:

- required lane environment: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:486-504`
- incomplete migration environment: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1814-1818`
- incomplete remote wrappers: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1988-2004`
- environment exported only locally: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2057-2065`
- connection selection: `dev:apps/api/config/database.php:87-99,105-133`; `dev:apps/api/config/tenancy.php:50`

The remote command must carry an explicit, shell-escaped lane environment, and the two-database migration strategy must be demonstrated against AutoERP’s actual central/tenant connection topology.

#### W4-B2 — EC-16a observes the queue before the row creates the job

The documented safe sequence is:

1. pause the supervisor;
2. execute the forced central-delete failure;
3. observe the pending job, correlated by its `tenantId`;
4. resume and observe retry/removal.

The runner instead calls `ec16a_observe` immediately after pausing and only then executes the test. It therefore cannot observe the job produced by that test.

Worse, `ec16a_observe()` merely prints every job’s id, display name and attempts. It:

- does not assert that a matching job exists;
- does not decode or compare `tenantId`;
- succeeds with zero rows;
- does not bind the isolated DB environment.

Evidence:

- correct prose sequence: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2327-2345`
- non-falsifiable observer: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1875-1881`
- incorrect invocation order: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2040-2069`
- claimed tenant correlation: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2310`

This is the rev-5 B4 condition in executable form, not a new cosmetic issue.

#### W4-B3 — `staging_replay: required` is never consumed by the runner

The manifest has 19 rows marked `staging_replay: required`, and the plan says that field generates the replay list. The runner passes only `MANIFEST`, `ONLY_ROW`, `ONLY_LEG` and lane selectors into `plan()`; it never passes `STAGING_REPLAY` or tests the row’s `staging_replay` field. A staging replay therefore plans the entire campaign rather than the declared 19-row replay set.

The manual branch also executes before `staging_wrap()`, writing `execution_target:"manual"`. The verification contract permits exactly `staging:ssh`, `staging:manual`, `local-only (declared)` or `not replayed (declared)` and forbids `manual`.

Evidence:

- 19-row declaration: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:470,646-961`
- claim that it drives the replay: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:974,2399-2411`
- planner ignores the field: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1937-1980`
- manual bypass: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2027-2033`
- exact target contract: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2467`

### MAJOR

#### W4-M1 — the committed evidence template is still a one-row stub

Task 4-1 says the evidence document contains one row per retained id and that its first column is generated from the reconciled list. The supplied body contains EC-1 followed by `| … |`, with no generator or coverage assertion tying the eventual evidence document to the manifest.

This is distinct from legitimate run-time placeholders such as `<runId>` and `<sha>`: those are values only a run can fill. The omitted 57 row definitions are known at plan time.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:332-363`.

Supply all 58 template rows or provide the executable generator and an equality guard over the evidence row ids.

#### W4-M2 — campaign fix subjects use non-numeric patch suffixes

Task 4-4 reserves `Phase 0.7.4a`, `0.7.4b`, etc. This conflicts with the same programme’s assertion that the `major.minor.patch` shape uses numeric segments. The `0.7` lane allocation itself is correct and collision-free; the finding concerns the fix-commit patch identifiers.

Evidence: `AGENTS.md:15-16`; `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:325-330`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2182,2657`.

### MINOR

#### W4-m1 — shared-library ownership prose remains contradictory

The detailed library body correctly owns only the pair guard, preflights and run-id/environment exports. Active prose still says the library supplies the ledger accumulator and, in the runner header, the unknown-argument/final-exit contract.

Evidence: incorrect descriptions at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1388,1602-1606`; correct inventory at `:1494-1516,1556-1559`.

#### W4-m2 — onboarding-script ranges remain stale

The source is 63 lines. Its actual final regions are:

- fiscal fail-fast: `:50-55`;
- `set +e`/run/capture/`set -e`: `:56-59`;
- two path echoes: `:61-62`;
- final exit: `:63`.

The plan states `:53-57`, `:58-61`, and `:63-65`.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:188`; `scripts/campaign-onboarding.sh:50-63`.

#### W4-m3 — rev-5 staging-preflight prose survived the rev-6 implementation

The Task 4-5 table still says the disposable check uses `rbac:row-census --field=is_disposable` and that both databases must pre-exist with `sum(n_live_tup)` checks. Rev 6 correctly replaced those with `rbac:tenant-is-disposable` and runner-created empty databases.

Evidence: stale table at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2322-2324`; implementation at `:1761-1818`.

#### W4-m4 — `legs[].covers` equality is verified manually but not guarded

The parsed manifest currently has correct `covers` arrays, but rule 3 only checks that an unreferenced leg has `covers: []`; it never asserts that a referenced leg’s `covers` equals the set of rows referencing it. The later verification claim says this invariant was asserted for all nine legs.

Evidence: incomplete guard at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1157-1194`; stronger claimed invariant at `:2682`.

#### W4-m5 — stale spec and producer labels

The evidence template says spec rev 9 rather than rev 9.2, and the plan pins Wave 1 rev 6.3 rather than rev 6.4.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:15,345`; current Wave-1 change log at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11151`.

**Wave 4 verdict: CHANGES-REQUIRED.**

## Cross-plan consistency

1. **Producer status is unchanged.** Wave 0b rev 6.3 `5257eb0f1` and Wave 1 rev 6.4 `70384d7e0` remain DISPATCH-READY. No producer implementation change is owed.

2. **All three plans need a mechanical Wave-1 re-pin.** They still describe rev 6.3 `949dfabdf` as latest. Rev 6.4 changes only the R-w1-1 spelling to `service-accounts.tokens.create`; it moves no manifest, enum, signature, test or runtime contract. Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:11149-11151`.

3. **The Wave-1 names consumed downstream are otherwise correct.**

   - Existing Wave-1 cases: `IdentityPermission::RolesView`, `IdentityPermission::UsersManageLocationAccess`.
   - Wave-2-scaffolded cases: `InventoryPermission::InventoryCountingsCreate`, `InventoryPermission::InventoryCountingsOverrideOwnership`, `POSPermission::PosOverrideDiscountLimit`, `IdentityPermission::ServiceAccountsCreate`, `ServiceAccountsView`, `ServiceAccountsUpdate`, `ServiceAccountsDelete`, `ServiceAccountsTokensCreate`, `ServiceAccountsTokensDelete`.
   - Wave 3 consumes `service-accounts.tokens.create`.
   - Wave 4 consumes none of the A-2 keys.

   Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:137-146`; Wave-1 Identity enum at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:4583-4603`; Wave-1 POS naming at `:4446-4467`.

4. **No new production permission check uses a nonexistent, unaccounted-for enum case.** `InventoryCountingsCreate` is absent from the current Wave-1 manifest, but Wave 2 explicitly owns its conditional scaffold. The defect is only the malformed fallback invocation identified as W2-m1.

5. **Lane ordinals do not collide.** `0.1` is 0a, `0.2` is 0b, Wave 1 uses `1.x`, and `0.4`/`0.5`/`0.6`/`0.7` are free. The required corrections concern alphanumeric patch suffixes, not lane allocation. Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:317-330`; `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:80,205`.

6. **The prompt’s “17 Q-w points” is now stale.** The plans contain 18: seven in Wave 2, six in Wave 3 and five in Wave 4. Q-w2-7 is the added eighteenth and is settled by amendment A-2. None requires owner escalation.

7. **Exact add-list status:** the prior Wave-4 phantom parity-test path and missing guard restage are corrected at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:2147-2170`. Wave 3’s fixture and matrix paths are explicit at `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3408-3414`. No new missing `git add` target was found. The invalid Phase subjects remain task-level defects.

## Citation audit

I mechanically checked **197 fully qualified `ref:path:line` or repository `path:line` references across the three plans**, caching 87 referenced files. Contextual shorthand anchors were then checked around the findings above.

Wrong or stale live citations/inferences:

| Plan location | Result |
|---|---|
| `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:41` | Stale A-2 derivation inference: falsely says POS must be hand-added. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:144,368,401,2569,4239-4243` | Stale claims about spellings still present in the spec/programme plan. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2572` | Wrong scaffold resource; derives the wrong key. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:25` | Spec revision stale: rev 9, should be rev 9.2. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:188` | Several subranges overrun or point at the wrong current onboarding lines. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:345` | Spec revision stale. |
| `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:3` | Known editorial leftover: heading says rev 6.4, revision line still says rev 6.2. |

The Wave-4 absolute vendor citation is valid in the shared checkout: `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor/stancl/tenancy/src/Commands/Run.php:23-26`. The scanner’s apparent repository-path miss was caused by treating the tail of that absolute path as repository-relative.

Six-plus representative fixture route checks against `dev` passed:

- role create/update/delete and user-role assignment: `dev:apps/api/app/Modules/Identity/routes.php:59-80`;
- menu create: `dev:apps/api/app/Modules/Menu/Presentation/routes.php:14-18`;
- batch recall: `dev:apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22-31`;
- replenishment request create: `dev:apps/api/app/Modules/Replenishment/Presentation/routes.php:14-19`;
- work-order update/complete: `dev:apps/api/app/Modules/Workshop/WorkOrder/Presentation/routes.php:30-49`;
- enrichment submission: `dev:apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:89-90`;
- voucher goodwill issue: `dev:apps/api/app/Modules/Voucher/Presentation/routes.php:25-27`.

Fresh route arithmetic resolved **18 of 20** fixture keys on `dev`; the two unresolved routes are precisely Wave 2b’s service-account creation and token-issuance routes.

## Rejected false positives

1. **Reject 127 controller / 44 FormRequest as authoritative.** Those are produced by naïvely splitting the standards-compliant CSV on commas. A real CSV parse and a fresh generator run both produce **130 controller + 46 FormRequest = 176**. The generated total is 1,054 routes with 142 AUTH_ONLY, 130 CONTROLLER, 46 FORMREQUEST, 642 MIDDLEWARE, 8 POLICY, 18 PUBLIC and 68 SUPERADMIN_ONLY. Generator body: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:404-833`.

2. **Reject “POS has only 24 conversion classes.”** The fresh grouping gives **92 POS rows across 25 classes**: 24 controller classes plus `ManagerPinController` carrying the FormRequest conversion. The plan’s POS-last allocation is correct. Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:331-345,1100-1125,3470`.

3. **Reject the central-token query as a Wave-3 blocker.** Under the matrix’s `DB_CONNECTION=pgsql` compatibility lane, Stancl’s `central_connection` is also `pgsql`; `CentralPersonalAccessToken` and `DB::table()` therefore use the same test connection. Evidence: `dev:apps/api/config/tenancy.php:50`; `dev:apps/api/config/database.php:105-122`; `dev:apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`.

4. **Reject the service-account route name as stale.** Permission `service-accounts.tokens.create` changed; route name `service-accounts.tokens.issue` is a separate identifier and is intentionally unchanged. Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:2127-2150`.

5. **Reject a claim that the Wave-4 manifest remains a placeholder.** It parses as 58 rows, 57 executable, one withdrawn EC-22, eight lanes, nine legs, 22 leg references across 22 rows, 34 PHPUnit filtered homes, and 19 staging-required rows. The remaining evidence-template stub is a separate artifact.

6. **Reject a blanket “runner parser is untested” finding.** Six extracted flag contracts were exercised successfully: unknown argument, missing value, staging replay without SSH, reuse without leg, reuse combined with lanes, and repeated leg all exited 2 with the expected reason. These successes do not repair the staging environment or EC-16a sequencing blockers.

7. **Reject any claim that Wave 0b or Wave 1 needs a producer fix round.** Both remain accepted and dispatch-ready; only downstream revision labels require re-pinning.

## Preserve

### Wave 2

Preserve these sound plan contracts while correcting the findings:

- The `principal_kind` migration includes a closed domain CHECK, service-shape CHECK, SQLite equivalents and ordered destructive rollback. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:182-183,475`.
- Login inserts the kind refusal between user resolution and `Hash::check`; POS PIN holders become human-only before the loop reaches its `Hash::check`. Current insertion points are `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-258` and `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56,87-90`.
- The dedicated service-account routes, `CreateServiceAccountRequest`, actor-bound `AssignableRole`, generic-user and RoleController target refusals, and `pinHolders()` exclusion are all covered. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:232,255-279,3179-3228`.
- The notification/invitation/reset census covers the five selectors/six paths and excludes service principals. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:266`.
- Human-seat exclusion covers every listed counter, and `max_service_accounts` is present in every tier including trial. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:279,291,374-378`.
- Token narrowing, explicit TTLs, mandatory `tenant:` issuance, global `EnforceTokenScope`, priority, strict non-tenant no-op and coverage test remain correctly allocated. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:255,270-271,288-291,3298-3334`.
- `ScopedTokenIssuanceEntryConditionTest` is falsifiable as a joint gate: editing the baseline alone leaves the shrink-only PHPStan rule red on the production sites. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:2581-2634`.
- `EffectivePermissionResolver::forSubject`/`forTarget`, token-id ownership, self-only narrowing and self-or-`roles.view` endpoint authorization remain intact. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:194-195,1473-1660`.
- Preserve the central best-effort membership revocation, queued retry, tenant-wide audit path, LastAdminFloor writer census and Wave-0b lock key/order. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:549-575,992-1010,3332-3773`.
- Preserve DomainEvent inheritance/stable role event names, central attribution in `AuditService`, denial dedup through atomic Redis operations, names-only shaping, server-authoritative `usePermissions`, generated TS union and deletion of `uiAliasPermissions.ts`. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:198-216,232-244`.
- Preserve the listed Playwright coverage and destructive rollback warning.

### Wave 3

- Preserve the executable enforcement generator and the **176** ceiling schedule. Fresh execution reproduced its claimed numbers.
- Preserve module order, per-cluster reviewer gates and POS last. `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:181-193,331,3480-3503`.
- Preserve E-4: FormRequest authorization moves to route middleware and may remain only as an exact duplicate or `true`. `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:132-136`.
- Preserve the already-wired `fetchDiscountPermissions` flow and remove only the residual ladder after its regression pin. `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:203,333-345,878-880`.
- Preserve the finding that `UserController.php:929` is the sole authorization read of `MembershipRole`; current code confirms it controls `LOCATION_ESCALATION`. `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`.
- Preserve verbatim §2 glossary bindings, deprecated-key retirement conditions, the Sidebar `permission` → `moduleKey` rename and per-module reviewer gates. `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:1435-1466,1537-1594,1598-1643`.
- Preserve the complete 20-entry fixture JSON and its four expectation kinds; fix the matrix harness rather than reopening the fixture format.

### Wave 4

- Preserve the reconciled **58 retained / 57 executable / one withdrawn EC-22** register. Independent extraction of spec §7 (`:2134-2201`) gives exactly 58 table rows, 57 non-struck rows and EC-22 withdrawn. `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2134-2201`.
- Preserve the parsed manifest’s eight lanes, nine legs, 22 references, 34 PHPUnit filtered homes and 19 staging-required rows. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:470-961`.
- Preserve the universal browser capture of 5xx responses and console errors, with toast and unchanged-row assertions conditional on a real refusal/mutation surface. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:88-121`.
- Preserve all nine named web Playwright legs and the honest POS split into Vitest plus documented device replay. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:170-176,1396-1450`.
- Preserve the separate RBAC campaign rather than appending it to onboarding. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:251-259`.
- Preserve the central `tenants.is_disposable` marker, mark/check commands, operator-only marking, DB creation and trap cleanup. Correct the connection binding rather than removing the isolation contract. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:1250-1364,1747-1818,1895-1927`.
- Preserve the manifest coverage test’s repaired root, 58/22 distinction and non-empty PHPUnit filter check. `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:976-1195`.
- Preserve the six passing runner flag contracts and the accumulated ledger semantics.

## Owner decisions required

Only the two accepted Wave-2 owner questions remain legitimate owner items:

1. **OQ-1:** service accounts do not count toward the last-admin floor by default. Ask before 2a merges because 2a ships the column and floor behavior. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:303-309`.

2. **OQ-2:** service tokens default to 365 days, human tokens to 90 days and POS tokens remain one year. `docs/superpowers/plans/2026-09-10-rbac-wave-2.md:311-315`.

No Q-w item requires escalation. Q-w2-7 is settled by amendment A-2. The current total is 18 Q-w points, not 17.

## Dispatch assessment

| Wave | Assessment | Entry condition after plan corrections |
|---|---|---|
| Wave 2 | **CHANGES-REQUIRED** — stale A-2 producer instruction and invalid patch subject | Wave 1 merged and full soak complete; 2b additionally waits for 2a and the scoped-token entry-condition test |
| Wave 3 | **CHANGES-REQUIRED** — supplied regression matrix cannot exercise the intended authorization layer | Both Wave-2 lanes merged/deployed; Phase-0 inventories and fixture reconciliation regenerated |
| Wave 4 | **CHANGES-REQUIRED** — staging isolation, EC-16a and replay-selection contracts are not implemented by the supplied runner | Waves 1–3 deployed to staging; disposable campaign tenant and corrected isolated remote execution available |

Overall: **2 BLOCKER / 2 MAJOR** attributable to Wave 3, **3 BLOCKER / 2 MAJOR** attributable to Wave 4, and **0 BLOCKER / 2 MAJOR** attributable to Wave 2, plus the listed editorial minors. The plans are not dispatchable in their current revisions.

VERDICT: CHANGES-REQUIRED