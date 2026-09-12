# Codex plan gate r1 — RBAC wave 0b plan rev 1 (+0b-15) and wave 1 plan rev 1 (gpt-5.6-sol, high, read-only, 2026-09-10)

`git rev-parse --short HEAD` → **`d089578cc`**

Measured refs:

| Ref | Current tip | `git diff --stat dev...<ref>` |
|---|---:|---:|
| `dev` | `630afa86f` | — |
| `lane/w-lot-a-1a` | `52f5ad796` | 83 files, 4,714 insertions, 663 deletions |
| `lane/t2-receipt-spine` | `951a7637e` | 100 files, 13,144 insertions, 546 deletions |
| `lane/t1-transfers-edge` | `86273346a` | ancestor of `dev` |

Static/code-backed gate only. No files were changed and no Artisan boot was attempted in this worktree.

## Wave 0b

### BLOCKER

#### B0b-1 — the version-0 oracle omits all nineteen additions from `admin`

The accepted design explicitly defines `admin`’s version-0 state as the post-A-1a grants plus **all nineteen** Wave-0b additions. The plan instead declares additions only for `manager` and `general_manager`, and its test explicitly requires `admin` to receive an empty addition list. That makes an ensure-provisioned pristine admin compare unequal during Wave-1 adoption and become `adopted_customised`, contradicting the required eight-pristine-role state and Wave 1’s own supersession expectation.

Evidence:

- Omission and rationale: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:498-522`.
- Test positively asserting the wrong state: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:873-878`.
- Binding accepted rule: `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:894-902,2451`.
- The lane actually defines admin as the complete permission set: `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91-102`.

Required correction: `WAVE_0B_ADDITIONS['admin']` must contain all nineteen additions, while only the seven manager keys remain deployable through `--grant-to`.

#### B0b-2 — Task 1 deliberately commits a known-red test

`Wave0bAdditionConstantTest` is staged in Task 1 while the plan states its catalogue assertion remains red until Tasks 5–6. The task’s run list simply omits the red file. This creates a knowingly failing intermediate commit and invalidates the “independently mergeable task” and red/green evidence model.

Evidence:

- The test checks that all seven deployed keys already exist: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:881-888`.
- The plan declares it red until Task 6: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:892-898`.
- The red test is nevertheless included in Task 1’s `git add`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:906-911`.
- Task 5 still says the same assertion remains red for six keys: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2541-2548`.

Move that assertion/test into the commit that declares the complete set, or land the relevant catalogue declarations with Task 1.

#### B0b-3 — `PermissionWriteLockCoverageTest` does not prove writer coverage

The proposed census is neither mechanically complete nor method-sensitive:

- It searches raw source substrings, omits the requested generic `firstOrCreate` pattern, and enumerates files rather than call sites: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2277-2283,2403-2425`.
- A whole file passes merely because it contains `PermissionWriteLock` anywhere. A newly added unlocked method in `RoleController` or `UserController` would therefore pass: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2347-2356`.
- The row-order assertion likewise accepts one correctly ordered query anywhere in a file, not one per writer: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2384-2400`.
- The plan acknowledges false matches and leaves the necessary lexical/AST narrowing as future prose rather than supplying it: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2438`.

A fresh grep at the current W-LOT tip finds the real role/permission runtime writers in `LotActionPermissionDelta`, `RoleController`, `UserController`, `ResetTenantCommand`, `TenantInitializationService`, migrations and seeders, but also dozens of unrelated model `firstOrCreate` calls and comments. For example:

- Lane delta writers: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:70,83,154-180`.
- Role controller writers: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:213-221,265,385,430`.
- User controller writers: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:268,409`.
- Reset writer: `lane/w-lot-a-1a:apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:89-95`.

The census must identify exact calls/method bodies and prove every runtime call reaches `acquire()` before its decision reads and write.

#### B0b-4 — the new admin-only gates leave live frontend callers knowingly issuing 403s

Task 6 changes only the services edit route guard and label/map artifacts, despite adding admin-only gates to Channels, Categories, Progression and company creation: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2578-2618`.

Actual callers remain accessible to non-admins:

- Channels routes use only `moduleKey="inventory"`: `dev:apps/web/src/routes/index.tsx:2243-2307`; `ChannelListPage` immediately requests `/channels` without a permission guard: `dev:apps/web/src/features/channels/pages/ChannelListPage.tsx:17-25`.
- Categories uses only the inventory module guard: `dev:apps/web/src/routes/index.tsx:1189-1197`; its page immediately loads the tree and exposes create/update/delete controls: `dev:apps/web/src/features/categories/CategoriesPage.tsx:19-30,127-146`.
- Progression pages have no permission guard at all: `dev:apps/web/src/routes/index.tsx:3202-3219`.
- `/company-onboarding` requires only authentication: `dev:apps/web/src/routes/index.tsx:548-558`; every caller sees “Add company”: `dev:apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:57-60,113-120`.

The plan even accepts a manager’s Categories and company-create requests reaching the server and returning 403: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3217-3221`. That conflicts with the requested caller contract and with the plan’s own reviewer gate requiring no surface to issue a request it cannot satisfy: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3327-3333`.

There is also an existing route mismatch the proposed happy-path probe cannot pass: the backend exposes `/service-categories` at `dev:apps/api/app/Modules/Service/Presentation/routes.php:37-54`, while the live UI calls `/services/categories` at `dev:apps/web/src/features/services/ServiceCategoryListPage.tsx:58,66,79,92` and `dev:apps/web/src/features/services/ServiceForm.tsx:70-72`.

Task 6 needs guards/action shaping for every affected caller and Playwright coverage for these UX-visible changes, not only 0b-15.

#### B0b-5 — a missing admin role reports success

The contract says admin is granted unconditionally, but the implementation merely appends `admin` to `missingRoles` and continues. Its final outcome is based only on `created` and `granted`, and commands fail only for `Blocked` or `Failed`. A tenant with no resolvable admin can therefore emit `APPLIED`/`ALREADY_CURRENT`, exit zero, and be marked deploy-safe even though no administrator holds the new route keys.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1676-1679,1848-1854,1882-1893,2082-2088`.

Missing `admin` must be `FAILED` or `BLOCKED`, and both applying and dry-run paths must retain that non-zero result.

#### B0b-6 — the two-invocation status marker has no atomic success condition

The deploy requires two fleet commands but provides no executable `cmd1 && cmd2` wrapper controlling the status file. Step 3 merely says to write `ok` “on success”; it does not define success as both invocations succeeding. A failed first invocation followed by a successful second invocation can therefore overwrite the status as healthy.

Evidence: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:193-197,3391-3399`.

This also contradicts Q-0b-1’s statement that `permissions:ensure-fleet` itself writes the status file: the supplied command only renders tenant and aggregate markers and returns an exit code; it contains no status-file write: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:171-175,2124-2178`.

### MAJOR

#### M0b-1 — TenantFleetRunner tests are incomplete and do not match their advertised shape

The selector algorithm itself is mostly sound, but its supplied test is not parameterised over fleet commands. It instantiates `TenantFleetRunner` directly and contains no data provider for Wave 1 to extend: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1452-1496`. Wave 1 nevertheless promises to “extend” that nonexistent provider: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1323`.

The test covers an absent database but has no `migrations_behind` case, despite that being half of the readiness predicate: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1139,1395-1410,1594-1604`.

It also calls `provisionTenants()` and `dropTenantDatabase()`, neither of which exists in the current trait; the plan leaves their implementation to prose: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1498-1625`; actual trait methods are `provisionTenantDatabase`, `provisionTenantDatabaseWithSchema`, and `withinTenantDatabase` at `apps/api/tests/Traits/ProvisionsTenantDatabases.php:30-117`.

#### M0b-2 — test-first evidence is generally not executable as written

Tasks 1–4 place substantial implementation before tests and then manufacture red evidence by deleting/restoring already-written branches:

- Task 1 implementation precedes its tests: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:277-586`.
- Task 3’s required red is produced by deleting the ambiguity arm after the complete runner was already supplied: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1272-1450,1631`.
- Task 4 supplies runner, commands and writer changes before its tests and describes the relevant red as “before Steps 4–6”: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1704-2249,2451-2459`.

That does not establish that the first failing assertion failed for the claimed production reason at dev tip.

#### M0b-3 — several tasks are prose stubs or use placeholder paths

Examples:

- Task 4’s controller changes contain `/* … */` and generic `$id` pseudocode that cannot implement all five differently-shaped methods: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2190-2222`.
- Task 6’s staging command contains the literal placeholder `<the module test files you touched>`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2633-2638`.
- Task 13 names three convention-09 tests without supplying their fixture/implementation changes: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2878-2889`.

#### M0b-4 — commit staging and subjects are not task-exact

Only Tasks 1–7 and 13b provide `git add` commands. Tasks 8–14 otherwise have no exact staging lists; Task 6 has a placeholder path: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:908,1091,1635,2463,2548,2635,2692,3125`.

The global instruction says to translate older subjects at execution time, but most task-local subjects still use `feat(...)`, `test(...)`, or `docs(...)`, including Tasks 1–11 and the handback: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:49,911,1094,1638,2466,2638,2695,2734,2760,2797,2836,2888,3256`. Dispatchable text should give the final `Phase 0.2.<task>:` subject at each commit site.

#### M0b-5 — PostgreSQL and static-analysis commands do not consistently use the repository harness

PG commands export database variables but invoke the default PHPUnit configuration rather than `phpunit-pgsql.xml`; the repository’s PG configuration explicitly documents `php artisan test -c phpunit-pgsql.xml`: `apps/api/phpunit-pgsql.xml:2-16`, versus plan commands such as `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:44-47,1627-1631`.

The final PHPStan/Pint commands omit several touched modules and tests—Service, Channel, Product, Progression, PurchaseHub, Company, BatchExpiry, Document, and their associated feature tests: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3208-3213`.

### MINOR

- The “one start read” contract says nothing rereads the directory except the closing gauge, but the implementation re-queries each selected tenant with `Tenant::query()->find()`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1137,1310-1335`. The reread is defensible for deletion detection; the contract wording should describe it.
- `EnsurePermissionsResult::markerLine()` omits `mode`; the fleet command appends it after `reason`, while the single-tenant command never adds it: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1748-1760,2071-2073,2161-2163`.
- The declared create-only contract says `Permission::firstOrCreate`, but the supplied implementation performs `exists()` followed by `Permission::create`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1676,1833-1845`.
- `EnsurePermissionsOutcome::Failed` is not constructed by the runner. Only `DomainException` is mapped, so the single-tenant form can throw an uncaught non-domain exception rather than emit its promised `FAILED` marker: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1715-1721,1903-1913,2049-2088`.

#### Task-by-task assessment

| Task | Assessment |
|---|---|
| 1 | **FAIL** — wrong admin v0 constant and knowingly red committed test: `:488-522,873-911`. |
| 2 | **PARTIAL** — key/hash/transaction contract is correct, but implementation precedes its red test and subject is wrong: `:934-1094`. |
| 3 | **FAIL** — helper methods absent, no command provider, no migrations-behind test: `:1452-1638`. |
| 4 | **FAIL** — missing-admin success, incomplete lock census, pseudocode writers: `:1666-2466`. |
| 5 | **PARTIAL** — credit-note fix is sound, but it inherits Task 1’s intentionally red commit: `:2500-2551`. |
| 6 | **FAIL** — incomplete frontend guard census, route mismatch, placeholder staging path: `:2574-2638`. |
| 7 | **PASS subject to renaming commit** — correctly stacks unconditional `can:` beside lane middleware: `:2663-2695`. |
| 8 | **PARTIAL** — gate choice is concrete, but the T2 citation is stale and no exact staging command is supplied: `:2722-2734`. |
| 9 | **PARTIAL** — response contract is concrete; staging and final subject are not: `:2738-2760`. |
| 10 | **PARTIAL** — caller dispositions are named, but no exact `git add` is supplied: `:2764-2817`. |
| 11 | **PARTIAL** — glossary rows are specified, but no exact staging command/final subject: `:2819-2836`. |
| 12 | **PASS with current-ref remeasurement** — the task is correctly narrowed to the anti-growth `env:` line: `:2840-2874`. |
| 13 | **FAIL** — three behavior tests remain prose-only: `:2878-2889`. |
| 13b / 0b-15 | **PASS** — corrected guard keys and E2E obligation are present: `:2892-3168`. |
| 14 | **FAIL** — incomplete analysis scope and non-atomic two-command status handling: `:3172-3256,3387-3399`. |

## Wave 1

### BLOCKER

#### B1-1 — catalogue arithmetic is stale: current post-0b count is 325, not 323

Static execution of current `dev`’s public method returns **304** keys (`dev:apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559`). The current W-LOT wrapper adds two unique keys and therefore returns **306**: `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91-94`. Wave 0b then adds nineteen, producing **325**.

Wave 1 instead hardcodes `304 + 19 = 323` and incorrectly says the W-LOT keys are already inside the current 304: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:213-215,241-246,805-807,912-924`.

This stops Task 4 at its own precondition and guarantees incomplete manifests if ignored.

#### B1-2 — the manifest migration is not supplied and its incremental commit scheme cannot stay green

Task 4 provides one manifest containing an ellipsis and only a prefix-to-module mapping; it does not enumerate all 325 concrete definitions, template defaults, legacy flags, deprecations, or SoD metadata: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:805-905`.

It also requires full-registry parity after every module group and commits each group separately. Any partial group necessarily fails the full 325-key equality until the final group: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:930-942`. `PermissionRegistryCoverageTest` is similarly introduced before the owning manifests exist: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:788-801`.

#### B1-3 — `TemplateDeltaApplier` violates zero-write idempotency

The applier unconditionally updates `roles.template_version`, even when the role’s grants and version are already current: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1131-1147`.

That directly contradicts the second-run invariant of zero writes and `ALREADY_CURRENT`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1207-1209,1798`.

The version update must be conditional on an actual version change and included in the mutation accounting.

#### B1-4 — the Wave-0b stopgap is not deleted in the same commit that lands sync

The binding cutover requires the ensure files and deploy rows to disappear in the commit that introduces their replacement. Instead:

- `PermissionSyncService` commits in Task 9: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1215-1251`.
- Commands commit in Task 10: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1255-1327`.
- Ensure and `DryRunRollback` are deleted later in Task 14: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1531-1546`.

Task 10 also says sync dry-run reuses Wave-0b’s rollback sentinel, while Task 14 deletes it without introducing a Wave-1 replacement: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1313-1315,1531`.

#### B1-5 — the human deploy order runs sync before its schema exists

The human-facing deploy table puts `permissions:sync-fleet --dry-run` before the roles migration: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1916-1922`. Sync adoption and delta logic reads/writes `template_key`, `template_version`, `customised_at`, and `is_system`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1186-1193`.

The machine-readable YAML correctly orders migration before sync: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1708-1710,1723-1729`. The prose and YAML therefore contradict each other, and the first dry run can fail on missing columns.

#### B1-6 — `recordTenantWide()` references an undefined actor parameter

The proposed signature has no `$actorUserId` argument but its implementation requirement passes `userId: $actorUserId`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1394-1398`.

This is not compilable and cannot persist operator attribution for template re-apply. The existing `AuditService::record()` accepts an explicit nullable user ID at `dev:apps/api/app/Modules/Compliance/Services/AuditService.php:43-51`; the new method needs the same explicit parameter.

#### B1-7 — most of the wave remains prose or placeholder implementation

The user-required minimal implementation is absent for:

- all manifests and enums: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:805-981`;
- `ReportDeadPermissions`: `:985-1008`;
- the migration body and SQLite triggers: `:1012-1043`;
- the nine-step sync service: `:1215-1251`;
- both command bodies: `:1255-1327`;
- subscriber and audit-service implementations: `:1394-1406`;
- role controller changes: `:1410-1427`;
- the seeder, whose public methods are literal semicolon stubs: `:1488-1520`;
- scaffold, PHPStan rules, AST walker, label exporter, and deploy-sequence test: `:1579-1755`.

These cannot be dispatched as exact implementation instructions under the stated gate criteria.

### MAJOR

#### M1-1 — all task-local commit subjects violate the required convention

Every supplied Wave-1 subject uses `feat(...)`, including Tasks 1–18: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:358,605,801,942,981,1008,1043,1157,1251,1327,1406,1427,1466,1546,1602,1654,1686,1755`.

They must be rewritten as `Phase 1.<task>: <imperative summary>`.

#### M1-2 — no Wave-1 task provides an exact `git add` list

The plan requires path-scoped commits globally but contains no `git add` command for any Wave-1 task: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:77-82,1826`.

This is especially unsafe for Task 4’s approximately thirty module providers/manifests and Task 14’s mixed additions/deletions.

#### M1-3 — inherited lock proof remains invalid and `TemplateDeltaApplier` can pass it by comment

The applier explicitly does not take the lock and relies on its callers: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1105-1113`. Wave 1 nevertheless adds the applier itself to the file-level `LOCKED` set: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:63,1426`.

Because its docblock contains `PermissionWriteLock`, Wave 0b’s proposed test would mark it locked without proving either caller’s order. This must be fixed in Wave 0b’s mechanical call-site census before Wave 1 can inherit it.

#### M1-4 — `permissions:scaffold` does not fully define deterministic output

The command promises “four artifacts” but lists a manifest, an enum and three locale files—five artifacts. It does not specify the concrete insertion marker/AST rewrite, JSON encoding flags, final newline/atomic replacement, or how a brand-new module’s provider tag is created: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1579-1601`.

The two-worktree test alone cannot make an unspecified rewrite safe.

#### M1-5 — the deploy-sequence test would not reliably catch this plan’s own contradiction

The YAML is correct, but Step 2 only says to parse unspecified “deploy-runbook rows” and check that named commands appear somewhere in the YAML; it does not assert exact order equality with this plan’s Deploy table: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1692-1700,1750-1754`.

Consequently, the erroneous dry-run-before-migration row at `:1920-1922` can pass the proposed presence-only comparison.

#### M1-6 — PostgreSQL/configuration and red-test commands are not task-complete

Wave 1 defines a PG environment globally but provides almost no executable task-level PHPUnit, PHPStan or Pint commands. The final checklist gives suite names and placeholders such as `./vendor/bin/pint --test <every touched backend path>` rather than exact paths: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:75-76,1762-1765,1793-1821`.

PG tests also need the repository’s explicit `phpunit-pgsql.xml` harness: `apps/api/phpunit-pgsql.xml:2-16`.

### MINOR

- Task 4’s title, parity fixture description and comments consistently say 323 and must all move to the generated current count, not merely the preflight expectation: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:134,213-215,805,922`.
- Task 16’s wording “PHPStan cannot see route files” is overbroad: current PHPStan analyzes `app/`, so it sees module route files under `app/Modules`; only top-level `routes/` is excluded. The following sentence supplies the correct distinction: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1625-1627`; actual paths are `dev:apps/api/phpstan.neon:5-8`.
- The YAML’s first 0b command contains a trailing space after the ellipsis, which undermines byte-exact comparisons: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1713-1719`.

#### Task-by-task assessment

| Task | Assessment |
|---|---|
| 1 | **PARTIAL** — the 32-case enum includes `Close` and `Pay`, but red command, exact staging and final subject are absent/wrong: `:252-358`. |
| 2 | **PARTIAL** — nullable verb/stored `behavesAs` design is correct; task-local execution/staging is incomplete: `:362-605`. |
| 3 | **FAIL as an independent commit** — coverage/parity guards arrive before complete manifests: `:609-801`. |
| 4 | **FAIL** — wrong count, one ellipsis manifest, and necessarily red incremental commits: `:805-942`. |
| 5 | **FAIL completeness** — one sample enum with ellipsis, not all module enums: `:946-981`. |
| 6 | **FAIL completeness** — 22-key set is correct, command implementation is prose: `:985-1008`. |
| 7 | **FAIL completeness** — schema contract is sound, migration/triggers/down body absent: `:1012-1043`. |
| 8 | **FAIL** — unconditional version update breaks rerun idempotency: `:1047-1157`. |
| 9 | **FAIL completeness** — nine-step rules are strong, implementation is a constructor and comments only: `:1161-1251`. |
| 10 | **FAIL** — command bodies absent and rollback sentinel later deleted: `:1255-1327`. |
| 11 | **FAIL compilation** — undefined `$actorUserId`; subscriber remains prose: `:1331-1406`. |
| 12 | **FAIL completeness** — controller actions/race integration are prose-only: `:1410-1427`. |
| 13 | **PARTIAL** — post-lane 18-row derivation is correct; owner ruling and concrete test implementation remain outstanding: `:1431-1466`. |
| 14 | **FAIL** — semicolon seeder stubs and cutover occurs several commits after sync lands: `:1470-1575`. |
| 15 | **FAIL completeness** — deterministic file-edit contract underspecified: `:1579-1602`. |
| 16 | **FAIL completeness** — neither PHPStan rule nor AST evaluator is supplied: `:1606-1654`. |
| 17 | **FAIL completeness** — exporter/label command/test remain prose: `:1658-1686`. |
| 18 | **FAIL** — YAML exists in prose, but the test is not supplied and comparison is presence-only: `:1690-1755`. |
| 19 | **FAIL dispatchability** — commands contain placeholders and cannot repair earlier red intermediate commits: `:1759-1827`. |

## Cross-plan consistency

| Contract | Result |
|---|---|
| `PermissionRenameMap::{sources,targets,to,canonicalise}` | **Consistent.** Produced at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:266-268,304-375`; consumed at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:227-240`. |
| `LegacyRoleBaseline::{postA1aGrants,wave0bAdditions,version0,matchesPreWave0b,matchesVersion0}` | **Signature-consistent, semantically broken for admin.** `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:269-273,498-560`; Wave-1 adoption at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1186`. |
| `PermissionWriteLock::{acquire,key}` | **Consistent key/hash and row order.** Plan: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:997-1015`; current lane: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116,131-135`. Coverage proof remains invalid. |
| `TenantFleetRunner::run(array,string,Closure): FleetRunResult` | **Consistent.** Producer: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1302-1339`; Wave-1 reuse: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1255-1257`. |
| Selector command test provider | **Inconsistent.** Wave 1 promises to extend a provider that Wave 0b never creates: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1452-1496`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1323`. |
| Wave-0b `DryRunRollback` | **Inconsistent.** Wave 1 reuses it at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1314` and deletes it at `:1531` without replacement. |
| Stopgap deletion “same commit as sync” | **Inconsistent.** Binding promise at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3377`; actual Wave-1 split at `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1251,1327,1531-1546`. |
| Scanner class from corrected Wave 0a | **Consistent consumer name.** 0b consumes `RouteCoverageClassifier`, `RouteCoverage`, and `RoutePermissionCoverageScanner`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2918,2962`. It does not consume the stale `RouteCoverageScanner` class name. |
| `Clock` substitution | **Acceptable and explicit.** `Illuminate\Support\DateFactory` is deliberately substituted and recorded: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1229-1235`. |
| Seeder preservation branch | **Consistent with the lane.** Wave 1 keeps the flag-off marked-tenant return: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:205-211,1497-1505`; actual lane branch: `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:52-55,72-77`. |
| Three tenant types / convention 09 | **Present and correctly distinguished.** `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:166-177,1237-1243`. Wave 0b’s real second-company and stock-adjustment second-location paths are named at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2878-2889`; rerun is at `:160-161,2440-2448`. |

## Citation audit

### Stale or wrong citations

- Both plans’ ref registers are stale. Wave 0b records `dev 2f99fef26`, W-LOT `5d847b2ba`, and T2 `d7123fa30`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:19-29`. Current tips are `630afa86f`, `52f5ad796`, and `951a7637e`.
- Wave 1 records HEAD `2ca6c1a9c`, dev `105b1b22b`, W-LOT `5d847b2ba`, and T2 `d7123fa30`: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:17`. Current values are stated at the top of this register.
- T2’s counting-submit route is no longer `:295-297`, as cited at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:118,2724`; it is currently `lane/t2-receipt-spine:apps/api/app/Modules/Inventory/Presentation/routes.php:307-308`.
- W-LOT `UserController` writer lines are stale in `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:121,2224`. Current locations are transactions at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:225,375` and writers at `:268,409`.
- Current rename sources are at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:272,292,499-501,560-565`, not the older positions carried by the accepted-spec derivation.

### Re-measured citations that remain correct

- W-LOT row-lock order: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116`.
- W-LOT advisory key: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:131-135`.
- W-LOT batch route middleware: `lane/w-lot-a-1a:apps/api/app/Modules/BatchExpiry/Presentation/routes.php:27,30`.
- W-LOT activation bypass/default: `lane/w-lot-a-1a:apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php:16-26`; `lane/w-lot-a-1a:apps/api/config/lot_action_permissions.php:5`.
- W-LOT RoleController writer lines remain `:213,221,241,265,285,312,373,385,430`: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`.
- T2’s generic 403 message remains at `lane/t2-receipt-spine:apps/api/app/Http/Middleware/RequireAnyPermission.php:21-31`.
- Current manifest values are:

| Ref | gated ceiling | Identity | Inventory |
|---|---:|---:|---:|
| `dev` | 1254 | 33 | 129 |
| `lane/w-lot-a-1a` | 1264 | 39 | 127 |
| `lane/t2-receipt-spine` | 1267 | 32 | 141 |

Sources: `dev:apps/api/tests/feature-lane-manifest.json:9,817-819,837`; `lane/w-lot-a-1a:apps/api/tests/feature-lane-manifest.json:9,819-821`; `lane/t2-receipt-spine:apps/api/tests/feature-lane-manifest.json:9,817-819,836`.

The plans’ rule to recompute from the merged file set instead of textually merging ceilings is correct: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:235-240,3195-3200`; `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1763-1765`.

### Route arithmetic

The 0b route arithmetic is correct against current code:

- Service: 6 writes / 5 reads — `dev:apps/api/app/Modules/Service/Presentation/routes.php:22-54`.
- Channel: 6 writes / 4 reads — `dev:apps/api/app/Modules/Channel/Presentation/routes.php:51-65`.
- Categories: 4 writes / 3 reads — `dev:apps/api/app/Modules/Product/routes.php:31-40`.
- Progression: 4 writes / 4 reads — `dev:apps/api/app/Modules/Progression/Presentation/routes.php:12-27`.
- Purchase Hub: 1 write — `dev:apps/api/app/Modules/PurchaseHub/Presentation/routes.php:12-21`.
- Company creation: 1 write — `dev:apps/api/app/Modules/Company/routes.php:23-32`.
- Batch: 2 writes — current lane route cited above.
- Counting: 1 write — current T2 route cited above.
- Identity: 4 reads — `dev:apps/api/app/Modules/Identity/routes.php:59,61,64,78`.

Total: **25 writes and 20 reads**, yielding the planned `152 → 127` and `146 → 126`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3176-3193`.

## Rejected false positives

- **Do not report the known Wave-0a T2 defects against these plans.** They are being repaired separately. Wave 0b consumes the corrected class name `RoutePermissionCoverageScanner`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2918`.
- **0b-15’s known wording defect is applied.** UsersPage uses `users.assign-roles`; RolesPage’s two queries, `/settings/roles`, and the Settings card use `roles.view`: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:3062-3077,3150-3154`.
- **The lock key and row order match current W-LOT code.** No second lock domain was invented: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:997-1015`; `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116,131-135`.
- **`Operate` and `Manage` are valid closed verbs.** The plan’s `channels.operate` and `progression.manage` are not naming violations; the manage-vs-CRUD rule is separately stated at `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2604`.
- **The BatchExpiry plan correctly preserves `BatchActionAccess` and adds unconditional `can:` gates beside it**, rather than misclassifying the flag-conditional middleware: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:2663-2685`.
- **Tenant selector resolution itself has the correct union semantics:** `get(['id','slug'])`, exact id-or-slug match, duplicate collapse, unmatched/ambiguous failure, readiness failure, and late-tenant population gauge: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:1310-1447`. The findings concern its incomplete tests/helpers.
- **No plan deploy uses `tenants:run permissions:ensure` or `tenants:run permissions:sync`.** The machine YAML forbids both: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1702-1710`.
- **The 22-key deprecation list matches the accepted set**, including post-rename spellings and active exclusions: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:985-997`.
- **The post-lane SoD table legitimately has eighteen rows.** The accepted eleven pre-lane combinations acquire seven mirrored `general_manager` rows from W-LOT; this is not eighteen new decisions: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1441-1456`.
- **Q-w1-3 correctly preserves the lane’s marked-tenant seeder branch.** Deleting it would invalidate the lane’s preservation test: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:205-211`.
- **The DateFactory substitution is deliberate and bounded**, not an accidental signature drift: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:1229-1235`.

## Preserve

All corrections must preserve the accepted register’s binding list at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:190-210`:

- Direction B and D1–D8.
- Tenant-scoped roles; company/location scope remains membership-carried.
- Legacy NULL-team roles are never re-homed.
- W-LOT `provisioning_source`, CHECK, default-off preservation branch, advisory key, and row order.
- Distinct `matchesPreWave0b()` and `matchesVersion0()` predicates.
- Exact template deltas only for pristine system roles.
- No automatic template grants or removals for custom/customised roles, except audited replacement grants.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two sync triggers: provisioning and flagged fleet deploy.
- Dry-run remains write-free and failure-preserving.
- The settled TenantFleetRunner selector behavior.
- Human/service principal separation, central Sanctum storage and per-token TTL precedence.
- `roles.view` payload shaping.
- Immutable versioned audit events on `audit_events`.
- Additive migrations, generated TypeScript permissions, and complete en/fr/ar label coverage.
- All accepted Wave-0a tasks and the single accepted manifest overlap.

## Owner decisions required

The seven plan-local design points resolve as follows:

| Decision | Assessment |
|---|---|
| Q-0b-1 | **No owner decision.** The deploy-checklist reader is settled; repair the false claim that the command writes the file and make the two-command wrapper atomic: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:171-177,3391-3399`. |
| Q-0b-2 | **Default is consistent.** Reusing `update` for service deletion preserves the accepted nineteen/seven split. No new owner question is permitted by the r9 register: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:179-185`; `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:214-221`. |
| Q-0b-3 | **Default is consistent.** `channels.operate` is in the closed enum and distinguishes operational actions from configuration: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:187-191`. |
| Q-0b-4 | **Two invocations are required.** A flat `--grant-to` list cannot express the admin/template split: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md:193-197`. |
| Q-w1-1 | **No owner decision.** `purchase-hub.orders` already matches the accepted regex; update the explanatory list only: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:199-203`. |
| Q-w1-2 | **Not a decision; arithmetic defect.** Recompute from current code and use 325: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:213-215`. |
| Q-w1-3 | **Preserve the branch.** This is binding lane compatibility: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:205-211`. |

The accepted owner questions remain exactly OQ-1–OQ-4; no fifth question should be invented: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md:212-221`.

For these waves:

- **OQ-3 / O-5 must be answered before Task 13 pins its SoD baseline**, as the plan itself states: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:185-189,1460-1463`.
- **OQ-4 / O-6 is decided after the one-week soak**, not before dispatch: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:191-193,1928`.
- OQ-1 and OQ-2 belong to Wave 2b and do not block 0b/1: `docs/superpowers/plans/2026-09-10-rbac-wave-1.md:195-197`.

## Dispatch assessment

Neither plan is dispatch-ready.

Wave 0b must, at minimum:

1. Put all nineteen additions into admin’s v0 arithmetic.
2. Eliminate the knowingly red Task-1 commit.
3. Replace the raw file-substring writer census with exact call-site/method coverage.
4. Fail closed when admin is missing.
5. supply the fleet helpers, migrations-behind test, and real two-command data provider.
6. Guard every frontend caller and add E2E coverage for all UX-visible gate changes.
7. Resolve the `/service-categories` versus `/services/categories` mismatch.
8. Make the two-invocation status write depend atomically on both exit codes.
9. Replace placeholders, exact all staging lists, finalise commit subjects, and use the PG harness.

Wave 1 must, at minimum:

1. Recompute the post-0b catalogue as 325 and provide all definitions/enums.
2. Restructure manifest commits so every commit is green.
3. Make `TemplateDeltaApplier` genuinely zero-write on rerun.
4. Land sync and stopgap deletion atomically, with a surviving Wave-1 dry-run sentinel.
5. Put migration before every dry-run/apply sync.
6. Correct `recordTenantWide()`’s actor signature.
7. Supply the migration, sync, command, event, controller, scaffold, static-guard, exporter and deploy-test implementations.
8. Make deploy-document validation exact-order, not command-presence only.
9. Add final `Phase 1.<task>:` subjects, exact `git add` lists, and explicit PHPUnit/PHPStan/Pint commands.

VERDICT: CHANGES-REQUIRED