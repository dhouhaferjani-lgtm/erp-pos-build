# Gate r3 — Lane F1 (F-BUG-1) import location / default-location code — tenancy+authz lens

**VERDICT: MERGEABLE — both r2 blocking findings (P1 PHPStan-red, P2 show-endpoint hydration) are CLOSED. 1× P2 is a MERGE-TIME instruction, not a branch defect: local `dev` moved and both shared CI-coverage files conflict; a naive resolution silently un-runs a test. 3× P3 carried (2 knowingly deferred by the fix brief, 1 doc-consistency).**

Reviewer: tenancy-authz-reviewer (adversarial, code-grounded). Branch `fix/f-bug-1-import-location` @ `f0e25441c` (r2 was `6f36f777f`, r1 `afd2aaf71`). Merge-base `a4ceeb0f5`. **Local `dev` has since advanced to `b4f6aedc5`** — the true lane diff is `git diff dev...HEAD` (24 files); the two-dot `git diff dev` the brief suggested shows ~50 phantom deletions that are just dev's newer commits.
Every claim below was read in the file cited or produced by a command run in this worktree.

Fix before merge (one line): nothing on the branch — resolve the `ci.yml` `--filter` and `feature-lane-manifest.json` conflicts against `b4f6aedc5` as a **UNION** (`gated_ceiling: 1203`, Import `23`, Company `34`, filter carries BOTH F1's entry and dev's five `*RoundTripTest` entries).

---

## r2 findings — disposition

| r2 finding | Status | Evidence |
|---|---|---|
| **[P1]** loosened `@warnings` docblock ⇒ 2 new PHPStan level-8 errors in `ResultWorkbookService.php:119-120` | **CLOSED** | Docblock restored to the strict `dev` shape: `apps/api/app/Modules/Import/Domain/ImportRow.php:19` — `@property list<array{code: string, detail: string}>\|null $warnings`. Defensiveness moved into the loop: `ImportController.php:713-721` (`! is_array($warning) → continue`; `! is_string($code) \|\| $code === '' → continue`). **`./vendor/bin/phpstan analyse --no-progress app/Modules/Import app/Modules/Company` → `[OK] No errors`** (run in this worktree; `vendor` is a real directory, not the stale symlink of the worktree gotcha). Adversarially pinned: `ProductsImportPipelineTest.php:250-259` now feeds a scalar element (`'Legacy scalar warning'`), an empty `code`, a non-string `code` (`42`) and a code-less array on the SAME row, and asserts the summary is exactly `['price_conflict' => 1]` (`:281`). |
| **[P2]** `show` hydrated warning rows on the 2-second in-flight poll | **CLOSED** | `ImportController.php:672-674` — `'warning_summary' => $withWarningSummary && $job->status->isTerminal() ? … : null`. Gate uses the **enum method**, not a magic string: `ImportStatus::isTerminal()` (`app/Modules/Import/Domain/Enums/ImportStatus.php:22-25` → `Completed`/`Failed`), and `status` is a non-nullable column with a default (`database/migrations/tenant/2025_11_30_150000_create_import_tables.php:18`) cast to the enum (`ImportJob.php:87`), so `->isTerminal()` cannot fatal on null. `warning_rows` remains the driver-aware COUNT (`:687-697`), unchanged. Pinned by the renamed `test_import_list_and_processing_show_omit_warning_summary_while_terminal_show_counts_valid_codes_per_row` (`ProductsImportPipelineTest.php:238`): job forced to `Importing` (`:262`) ⇒ `data.warning_summary` is `null` (`:274`); forced to `Completed` (`:276`) ⇒ exact map (`:282`). **No consumer regression:** the ONLY reader of `warning_summary` in the whole web app is the complete-step panel, `apps/web/src/features/import/pages/ImportWizardPage.tsx:1192` (grep over `apps/web/src`), so the six non-terminal `formatJob(..., true)` call sites (`:187,:206,:215,:402,:538,:569`) losing the field breaks nothing. |
| **[P2, imports gate]** wizard stalls when the completion refetch fails | **CLOSED** | `ImportWizardPage.tsx:482-495` — the refetch now falls through to `enterCompleteStep()` on BOTH the `isError` result (`:484-488`) and the rejection handler (`:489-492`), and the `failed` realtime status also triggers the refetch (`:482`). `terminalTransitionJobsRef` is no longer deleted on failure, which is correct now that every path transitions. Pinned by two real tests: `ImportWizardPage.options.test.tsx:361` (rejected refetch still reaches `wizard.complete.title`, and asserts the exact `console.error` call) and `:424` (a `failed` job holds the promise open, asserts the complete step is NOT entered, then resolves and asserts it is — a genuine ordering pin, not a smoke test). |
| **[P3]** migration test laned into the PARKED `Company` group | **CLOSED in CI, note stale** | `.github/workflows/ci.yml:1087` — `BackfillDefaultLocationCodeF1MigrationTest` is now in the `backend-pgsql --filter` allowlist (inserted after `FiscalPeriodCloseEndpointTest`), with a justifying comment block at `:966-970` matching the two `FiscalPeriod*EndpointTest` siblings' precedent. `phpunit-pgsql.xml:26-28` laned the whole `tests/Feature` directory, so `tests/Feature/Company/Migrations/` is in scope. `php tools/feature-lane-manifest-check.php` → **OK**, and it explicitly certifies "every `--filter` entry is anchored and uniquely matched against 1857 test classes" — that closes the anchoring/uniqueness ask mechanically. Only the manifest **note** is stale — see P3 below. |
| **[P3]** per-element warning shape unguarded | **CLOSED** | `ImportController.php:713-721`, see P1 row. |
| **[P3]** `useScopedLocations()` `isError`/`isLoading` discarded (`ImportWizardPage.tsx:263`) | **NOT CLOSED (deliberate)** | Unchanged: `const { data: scopedLocations = [] } = useScopedLocations()` (`:262`). Excluded from the fix brief. Still unreachable by any persona (r2 trace). |
| **[P3]** no test for "stock section visible, zero locations returned" | **NOT CLOSED (deliberate)** | The 9 `it(` blocks in `ImportWizardPage.options.test.tsx` (`:192,205,219,243,269,289,361,424,496`) still contain no empty-list case. |
| **[P3]** import jobs tenant-scoped, never company-scoped | **NOT CLOSED (deferred to F2)** | Unchanged — `ImportController.php` still keys on `where('tenant_id', …)`. Carried by the F2 lane. |

---

## Round-2-specific checks the parent asked for

**PHPStan across both modules.** `./vendor/bin/phpstan analyse --no-progress app/Modules/Import app/Modules/Company` → `[OK] No errors`. This is the check r2's P1 proved the lane's own touched-files-only run could not make.

**The `ci.yml` allowlist edit.** Anchored + uniquely matched: certified by `tools/feature-lane-manifest-check.php` checks C+D (`tools/feature-lane-manifest-check.php:34-36`), which resolve every filter entry against all 1857 test classes. Exactly one class carries the name (`tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php:16`). Two occurrences in `ci.yml` (`:966` comment, `:1087` filter) — no stray third.

**Manifest disposition vs the allowlist.** Not contradictory, but **incomplete** — see P3-1. The `Company` note (`apps/api/tests/feature-lane-manifest.json:739`) still describes the class as "the **path-run pin** … the parked tenancy lane does not execute it until SELF_HOSTED_RUNNER_READY is enabled" and never says it is named in the `backend-pgsql --filter` allowlist, unlike both of its siblings in the same note ("Named in the backend-pgsql --filter allowlist … because feature-lane-tenancy/Company is PARKED").

**Terminal-status gate uses the enum.** Yes — `ImportStatus::isTerminal()`, no string literal anywhere in the change (`ImportController.php:672`; enum at `ImportStatus.php:22-25`). The test drives status via `ImportStatus::Importing` / `ImportStatus::Completed` enum cases (`ProductsImportPipelineTest.php:262,276`), not strings.

**Anything else touching tenancy/authz in round 2.** Nothing. Round 2 touches 6 files; `git diff dev...HEAD | grep -E "^\+.*(can:|module:|Permission::|givePermissionTo|RolesAndPermissionsSeeder)"` is **empty**, and no `routes.php` or seeder is in the lane's file list. `show()`'s tenant scoping (`ImportController.php:231-235`: `requireCompany()` → `where('tenant_id', $company->tenant_id)`) is untouched. `$row->getAttribute('warnings')` (`:707`) is exactly equivalent to `$row->warnings` — `warnings` is a first-class `'array'` cast (`ImportRow.php:67`), so the cast still applies; no raw-JSON regression.

## Deploy safety for push → auto-`tenants:migrate` on all 8 staging tenants

- **Migration is self-guarded:** `Schema::hasTable('locations')` (`2026_08_29_100000_backfill_default_location_code_f1.php:20`) then a per-column `Schema::hasColumn` loop over `id/company_id/code/is_default` (`:24-28`). A tenant DB missing any of them is a clean no-op.
- **Idempotent:** the selection predicate is `is_default = true AND (code IS NULL OR code = '')` (`:31-34`), and the UPDATE re-asserts the same predicate in its own WHERE (`:57-63`). A second run matches zero rows and writes no `updated_at` — pinned by `BackfillDefaultLocationCodeF1MigrationTest.php:60-78` (re-runs and asserts the timestamps are byte-identical).
- **Collision-safe:** a company that already has a `MAIN` on another location is skipped and censused (`:43-55`), never written — so the partial unique index on `(company_id, code)` (`2025_11_30_105000_create_locations_table.php:68`) cannot be violated. Pinned by `…MigrationTest.php:81-105` (asserts the default keeps `code = null` AND that the census line is both echoed and `Log::warning`ed).
- **Cursor-vs-update is safe on PostgreSQL:** `DB::table(...)->cursor()` (`:36`) over libpq buffers client-side, and the loop only updates rows already selected. Default locations are ~1 per company, so the unbuffered-read footprint is trivial.
- **Reversible-by-design no-op `down()`** (`:71-74`), correct for a data backfill.
- **Confined to the tenant lane:** the file lives in `database/migrations/tenant/`, and `config/tenancy.php:197` pins `--path => database_path('migrations/tenant')`, so the central directory/auth DB can never see it. No central-connection read or write anywhere in the lane.
- **No seeder / permission step owed.** The lane adds no `can:` guard, no `module:` guard, no permission and no role grant (grep above is empty), so there is no silent-403 exposure and no `RolesAndPermissionsSeeder` re-sync for the 8 existing tenants. The only new backend surface, `is_active` on the picker payload (`LocationController.php:123`), rides the already-ungated `company/locations` route.
- **Operator step (unchanged from r1/r2):** grep the per-tenant `tenants:migrate` output for `default-location-code-collision` lines. A censused tenant keeps a NULL-coded default; its wizard simply offers the other, already-coded `MAIN` location.
- **New-company path cannot collide:** `CompanyController.php:126` writes `'code' => 'MAIN'` on the first location of a company created moments earlier in the same block, so no pre-existing `MAIN` can be in that company.

## Gates run in this worktree

- `./vendor/bin/phpstan analyse --no-progress app/Modules/Import app/Modules/Company` → **[OK] No errors**
- `./vendor/bin/phpunit tests/Feature/Import/ProductsImportPipelineTest.php tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php tests/Feature/Company/LocationListEndpointsTest.php` → **OK (41 tests, 320 assertions)**
- `./vendor/bin/pint --test` on the 5 touched PHP files → `{"result":"pass"}`
- `php tools/feature-lane-manifest-check.php` → **OK** (1457 Feature classes / 74 groups; every filter entry anchored + uniquely matched)
- `npx vitest run src/features/import src/features/locations` → **14 files / 72 tests pass**
- `npx eslint src/features/import/pages/ImportWizardPage.tsx src/features/import/__tests__/ImportWizardPage.options.test.tsx` → **0 errors**, 20 pre-existing warnings (none on the changed lines; `console.error` is not rule-flagged). Full `pnpm lint` NOT run (parent instruction).
- `npx tsc --noEmit` → clean.
- PG leg NOT executed locally: there is no `.env.testing` in this worktree and `.env` points at the live dev database (`DB_DATABASE=autoerp`, port 5433) — running `phpunit-pgsql.xml` with `RefreshDatabase` would have wiped it. **Cannot verify** the PostgreSQL execution of `BackfillDefaultLocationCodeF1MigrationTest` first-hand; the S-17 caveat the lane wrote into `ci.yml:970` ("CI has not been observed green on this branch") is the correct and honest disposition.

---

## Findings

### [P2] MERGE-TIME — `.github/workflows/ci.yml:1087` and `apps/api/tests/feature-lane-manifest.json:9,739` both conflict with local `dev` (`b4f6aedc5`); a naive resolution silently un-runs a CI-gated test

`git merge-tree --write-tree dev HEAD` reports **content conflicts in exactly those two files** (stages 1/2/3 for each). Since the merge-base `a4ceeb0f5`, dev's G-7 RoundTrip lane edited the SAME `--filter` line and the SAME ceilings:

| | dev (`b4f6aedc5`) | F1 branch |
|---|---|---|
| `gated_ceiling` | `1197 → 1202` | `1197 → 1198` |
| group `classes` | `Import` `18 → 23` | `Company` `33 → 34` |
| `--filter` | appends `ProductsRoundTripTest\|PartiesRoundTripTest\|OpeningBalancesRoundTripTest\|CompositeItemsRoundTripTest\|ProductImagesZipRoundTripTest` | inserts `BackfillDefaultLocationCodeF1MigrationTest` |

**Failure scenario:** the `--filter` value is a single ~15 000-character line, so git offers no partial merge — the resolver takes one whole side. Taking "ours"/dev drops `BackfillDefaultLocationCodeF1MigrationTest` and the ONLY live CI execution of a backfill that auto-runs on all 8 staging tenants goes dark; taking "theirs"/F1 drops the five RoundTrip acceptance-net classes. **Neither is caught by any gate**: `tools/feature-lane-manifest-check.php` checks C+D are one-directional (filter entry → exactly one class, anchored, `:34-36`) — nothing asserts that a laned class IS present in the filter. The manifest side is louder but still wrong: taking one ceiling alone leaves the check red or the other group's ceiling understated.

**Fix (merge instruction, no branch change):** resolve both files by UNION — one `--filter` line carrying dev's five entries AND F1's entry; `gated_ceiling: 1203`; `Import.classes: 23`; `Company.classes: 34`; keep both comment blocks. Then re-run `php tools/feature-lane-manifest-check.php` (must be OK) and `grep -c 'BackfillDefaultLocationCodeF1MigrationTest\|ProductsRoundTripTest' .github/workflows/ci.yml` before committing the merge.

### [P3] `apps/api/tests/feature-lane-manifest.json:739` — the Company-group note calls the new class a "path-run pin" and omits the allowlist sentence both of its siblings carry

The note reads "…**the path-run pin** for the collision-safe, idempotent default-location MAIN code backfill; the parked tenancy lane does not execute it until SELF_HOSTED_RUNNER_READY is enabled." That was true at `6f36f777f`; at `f0e25441c` the class runs live in `backend-pgsql` (`ci.yml:1087`). The two `FiscalPeriod*EndpointTest` entries in the very same note both end with "Named in the backend-pgsql --filter allowlist … because feature-lane-tenancy/Company is PARKED."

**Failure scenario:** the manifest is the record a future ceiling raise or CI-budget trim reads. A maintainer trimming the filter sees "path-run pin" and concludes the entry is redundant — deleting it is silent (see P2). The note is the only place that documents the coupling. Since the merger must hand-edit this file anyway (P2), folding in the sentence is free.

### [P3] `apps/web/src/features/import/pages/ImportWizardPage.tsx:262` — `useScopedLocations()` `isError`/`isLoading` still discarded (carried from r1/r2, deliberately out of the fix brief)

`const { data: scopedLocations = [] } = useScopedLocations()`. On a 500/network failure the list is `[]`, `stockLocationCode` resolves to `''` (`:285-297`), the PATCH omits `location_code`, and the import posts opening stock with no location. Still unreachable by any persona that can load the wizard (r2's persona trace: `company/locations` is ungated and its middleware is a strict subset of the import routes'), and partially mitigated by the `location_unresolved` counter on the complete step (`:1192`). Worth an `isError` branch before the team's manual staging pass.

### [P3] `apps/web/src/features/import/__tests__/ImportWizardPage.options.test.tsx` — no test combines a `quantity` mapping with the `beforeEach` default of zero locations, so the empty-list rendering path above is unpinned (carried; deliberately out of the fix brief)

---

## Test-quality notes (round 2)

- The renamed pipeline test (`ProductsImportPipelineTest.php:238-283`) is a genuine falsifier on THREE axes at once: the non-terminal `null`, the terminal map, and four separate malformed warning shapes. Real upload → real rows → real HTTP; no mocks, no fake payloads.
- `ImportWizardPage.options.test.tsx:424-495` holds the refetch promise open with a hand-rolled deferred, asserts the complete heading is ABSENT before resolution and PRESENT after — it would fail if the code transitioned eagerly. That is the deny-side of the ordering contract, not a happy path.
- `:361-423` asserts the exact `console.error(message, error)` pair, so the "log once and fall through" contract is pinned rather than assumed; `afterEach(vi.restoreAllMocks)` (`:146-148`) was added so the console spy cannot leak into sibling tests.
- The round-1 test that asserted the OPPOSITE behaviour ("keeps the execute step after a failed final refetch and retries") was correctly replaced, not left contradicting the new contract.
- No authz deny-path test is owed: the lane introduces no `can:`/`module:` guard. `LocationListEndpointsTest` already carries the deny paths for the sibling routes.
