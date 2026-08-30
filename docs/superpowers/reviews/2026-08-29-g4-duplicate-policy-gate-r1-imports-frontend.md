# G-4 adversarial gate r1 — duplicate policy, merge, resolvers, and row outcomes

Reviewed worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g4-duplicates`  
Branch/base: `feat/g4-duplicate-policy-merge` / `68c698f1a`  
Review mode: source read-only; this register is the only intentional workspace write.  
Verdict: **FAIL**

The lane is not safe to rebase or stage. Required pinned regressions fail, execute-time duplicate resolution is not authoritative inside each row transaction, the required fault-injection coverage is not present, within-file placement identity is wrong, and the unit tier implementation can report a false ambiguity. Frontend policy persistence also races execution.

## Findings register

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| G4-R1-01 | BLOCKER | `apps/api/app/Modules/Import/Services/ImportService.php:383-446`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:165-176` | Both sync and queued execution compute one duplicate plan before the row loop and pass it into every transaction. `processPendingRow()` only re-resolves when the plan is null. An entity committed after preview or by an earlier/concurrent row is therefore invisible to later decisions; `skip`, `override`, `preview_drift`, and the promised authoritative execute-time resolution are stale. | Resolve the current row's master-data identity inside that row's transaction. Keep a separately derived within-file winner map only if necessary; do not reuse a pre-loop database-resolution census as the entity decision. Add a test that inserts/changes the competing entity after preview and before execution. |
| G4-R1-02 | BLOCKER | `apps/api/app/Modules/Import/Services/DuplicateCensusService.php:23-52`; `apps/api/app/Modules/Import/Services/DuplicateCensusService.php:63-79` | The census is not the specified all-row, 500-row batched resolver. It materializes all valid pending rows, performs one resolver chain per row, then materializes all job rows again; only the bucket `UPDATE` statements are batched. Invalid/non-pending rows are absent from the plan and are counted as `new` by the outer loop. This is unbounded memory plus N-query resolution, and the persisted summary can misclassify rows. | Chunk rows in 500-row batches, collect normalized SKU/barcode/name keys, resolve each arm with batched `whereIn` queries, classify every census row deliberately, persist its bucket, and aggregate without loading the whole file. Assert query count and a census containing invalid rows. |
| G4-R1-03 | BLOCKER | `apps/api/app/Modules/Import/Services/DuplicateCensusService.php:124-144` | Within-file coalescing keys on raw `location_code` text (or the job option), not resolved `location_id`. Aliases/case/spelling that resolve to the same location evade later-row-wins, while identical text in unresolved contexts can be merged incorrectly. The approved placement identity is `(product_id, location_id)`. | Resolve location through the Shared contract during census/execution planning and key on the resolved ID. Treat unresolved placement through its existing coded outcome rather than a raw-string identity. Add same-location/different-spelling and different-location tests. |
| G4-R1-04 | BLOCKER | `apps/api/tests/Feature/Import/ImportOutcomeAtomicityTest.php:81-125`; `apps/api/tests/Feature/Import/ImportOutcomeAtomicityTest.php:127-159` | The four required kill/resume atomicity tests do not exist. The “correction” test merely pre-seeds a warning, and the skip test performs one normal call; neither kills execution after entity commit and before the next row, then resumes. Only the rollback/failure test genuinely injects a fault inside the row transaction. Passing this class therefore does not prove no double entity/correction and no repeated skip. | Add four real fault points around commit/resume: imported entity, opening correction, duplicate skip, and failed post-rollback. Each must stop between the committed decision and the next row, invoke the worker again, and assert the terminal row is not selected or applied twice. |
| G4-R1-05 | BLOCKER | `apps/api/app/Modules/Import/Services/ImportService.php:705-730`; `apps/api/app/Modules/Import/Services/ImportService.php:939-951` | Opening-balance rows are marked `imported` as placeholders in the row loop and can later be changed to `failed` by finalization. The approved outcome machine permits only `imported -> opening_locked`; other terminal outcomes must be committed with the entity/batch decision. This also moves the failure write outside the required failed-row post-rollback path. | Do not publish `imported` before the file-level opening transaction succeeds. Commit each final row outcome with the batch decision (or retain a non-terminal staging state), reserving `imported -> opening_locked` as the sole terminal transition. |
| G4-R1-06 | BLOCKER | `apps/api/app/Modules/Import/Domain/ImportJob.php:29-32`; `apps/api/app/Modules/Import/Domain/ImportJob.php:56-81`; `apps/api/app/Modules/Import/Services/ImportService.php:409-420`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:199-212` | Outcome equations calculate `skipped`, but there is no `import_jobs.skipped_rows` column/model field and neither sync nor queue finalization persists it. Queue progress also counts every non-`imported` terminal outcome as a failure at lines 175-181. A completed skip-only job can have correct status transiently while its durable/API counters report neither skipped nor accurate failures. | Reconcile schema ownership with G-6a/G-6b, add and persist `skipped_rows`, derive all final counters from outcome only, and broadcast skipped separately or ensure the public progress equation cannot label skips as failures. |
| G4-R1-07 | BLOCKER | `apps/api/app/Modules/Product/Application/Services/ProductService.php:387-412`; `apps/api/tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php:155-185` | A soft-deleted barcode twin now causes `sku_held_by_deleted_product` using the twin's unrelated SKU. The pinned contract says barcode is non-unique and only the SKU that will actually be written may block creation. The pinned test fails at line 175. | Preserve the G-3a refusal token only when the soft-deleted row holds the effective SKU. A trashed row sharing only barcode must not block a new product with a different effective SKU. |
| G4-R1-08 | MAJOR | `apps/api/app/Modules/Product/Application/Services/ProductService.php:416-430` | The name-only resolver arm omits `withTrashed()`, unlike the SKU/barcode query. A blank-key re-import can ignore a deleted normalized-name holder and create a second record, so the promised deterministic deleted-holder treatment is incomplete. | Include trashed rows in the name arm and apply an explicit, stable deleted-holder outcome consistent with G-3a; add a name-only deleted-holder pin. |
| G4-R1-09 | BLOCKER | `apps/api/app/Modules/Uom/Application/Services/UnitCatalogQuery.php:20-43`; `apps/api/app/Modules/Import/Services/UnitResolver.php:29-41`; `apps/api/app/Modules/Product/Application/Services/ProductUnitBackfillService.php:28-40` | The catalog emits tenant units with tier `tenant`, but the resolver's winning-tier filter looks only for `company`. Therefore an exact tenant/system code collision is reported `unit_ambiguous` instead of selecting the company-visible/tenant tier. The migration backfill applies no tier precedence at all and will also count that cross-tier case as ambiguous. Current tests cover duplicates in one system tier, not the winning-tier case. | Define the tier vocabulary once, make the visible tenant/company tier win before ambiguity is evaluated, and reuse exactly the same resolver semantics in backfill. Add tenant-vs-system same-code tests for runtime and migration. |
| G4-R1-10 | MAJOR | `apps/api/database/migrations/tenant/2026_08_31_100200_backfill_product_unit_ids.php:13-34`; `apps/api/app/Modules/Uom/Application/Services/UnitCatalogQuery.php:20-25` | The unit backfill guards only `products.unit`/`unit_id`, then resolves a service that calls `Company::findOrFail()`. A brownfield tenant missing dependent UOM/company tables or containing an orphaned `company_id` can abort the fleet migration, contrary to the self-guarding/never-throw requirement. | Guard every required table/column, handle missing/orphan companies per company or row, log their census, and ensure an unexpected legacy row cannot abort other tenants. Keep the migration forward-only. |
| G4-R1-11 | BLOCKER | `apps/api/app/Modules/Import/Services/ImportService.php:313-329`; `apps/api/tests/Feature/Import/ImportReExecutionGuardTest.php:239-295`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:74-77` | The required `ImportReExecutionGuardTest` is red: one error because the pinned worker call supplies `UnitsProvisioningService` while `handle()` now requires `UnitCatalogQueryInterface`, and one failure because `getValidRows()` no longer enforces its documented `is_imported=false` replay guard when legacy state is `is_imported=true/outcome=pending`. The user sanctioned only the ProcessImportJobStatus amendment. | Preserve the replay guard alongside `outcome=pending`, and reconcile the worker seam without silently editing this pin. If changing the pin is unavoidable, obtain explicit owner approval and document it as a second sanctioned amendment. |
| G4-R1-12 | BLOCKER | `apps/api/app/Shared/Contracts/ProductServiceInterface.php:28-37`; `apps/api/tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php:71-121`; `apps/api/tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php:279-294` | Five additional pinned tests error because the new `ProductUpsertResultData` return value is passed where a product ID string is required or compared directly with a string. Together with G4-R1-07 this class reports 5 errors and 1 failure, so the required regression leg is not green. | Reconcile every direct caller with the approved DTO seam (normally `->productId`) while preserving assertions. Because these are pinned tests and only one amendment was pre-authorized, record owner approval before changing the pins or retain backward compatibility. |
| G4-R1-13 | MAJOR | `apps/api/tests/Feature/Import/CoalescingMergeTest.php:12-64`; `apps/api/app/Modules/Import/Services/ImportService.php:754-803` | The required thin re-import proof is absent. The new test exercises only an in-memory array merger; it never imports a populated product/partner, re-imports a sparse row, or proves database columns, `unit_id`, type, and governing tax configuration remain correct. | Add database-backed sparse product and partner re-import tests, including all three `default_tax_configuration_id` provenance cases and create-vs-update type behavior. Keep `PartnerCodeUpsertTest` and the existing tax pins green. |
| G4-R1-14 | MAJOR | `apps/api/app/Modules/Import/Domain/Data/ImportRowSourceData.php:9-18`; `apps/api/app/Modules/Import/Domain/Casts/ImportRowSourceCast.php:19-38`; `apps/api/app/Modules/Import/Services/ImportService.php:705-706` | The row-data DTO declares nested `_results`, while the writer stores a flat map. The cast explicitly bypasses the DTO to preserve that incompatible shape and passes `_placement_plan` through as untyped JSON. Legacy fixtures pass, but rule 3's exact typed JSON contract is not met for the touched `data` JSONB column. | Make `_results` and placement-plan shapes explicit DTO fields (including a legacy hydration path), then have all writers/readers use that single stored shape. Remove cast-level untyped escape hatches. |
| G4-R1-15 | MAJOR | `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:293-317`; `apps/web/src/features/import/pages/ImportWizardPage.tsx:982-1007` | Preview always returns a `duplicates` object. When no census exists (including non-product imports), `counts` is `[]`; the UI adds undefined bucket values and can render `NaN`. It also presents duplicate policy where no product census was run. | Return a complete zero-filled counts object or omit `duplicates` when not applicable, and conditionally render the RUL-1 panel only for a valid census. Add a non-product and missing-census Vitest/API test. |
| G4-R1-16 | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:982-1033` | The API supplies `matched_by_name`, but the RUL-1 summary never renders that list. Operators cannot inspect which rows used the weakest identity arm before choosing override/skip. | Render the matched-by-name row list with translated copy and a bounded/expandable presentation; assert it in Vitest. |
| G4-R1-17 | BLOCKER | `apps/web/src/features/import/pages/ImportWizardPage.tsx:618-650` | Policy persistence is fire-and-forget both on radio change and validation completion. The wizard advances immediately, so Execute can dispatch before PATCH stores `skip`; backend execution then uses its default/previous policy. This violates “preview choice once” and makes the UI choice non-authoritative. | Use one awaited mutation, disable Next/Execute while it is pending, advance only after PATCH succeeds, and surface a translated persistence error without starting execution. Cover a deferred PATCH promise in Vitest. |
| G4-R1-18 | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:618-628` | Cancel navigates directly to `/settings/import`; it does not offer the required Discard action for the durable preview job. | Route Cancel through the existing discard confirmation/delete flow, then navigate only after the operator confirms or explicitly keeps the draft. |
| G4-R1-19 | MAJOR | `apps/web/src/features/import/__tests__/ImportWizardPage.duplicates.test.tsx:87-113`; `apps/web/src/features/import/pages/ImportWizardPage.tsx:765-1179` | Selector contract implementation exists, but the new Vitest does not assert every owned selector: it omits explicit skip presence, preview Next, Execute step, and `import-wizard-execute`, and never traverses the execute boundary. The shared `apps/web/e2e/campaign/selectors.ts` is absent in this branch (expected from Session I), so there is no compile-time selector reconciliation yet. | Extend the test to assert override/skip/cancel plus the owned step/next/execute IDs and awaited policy persistence. After Session I lands, consume the shared selector constants without editing that file. |
| G4-R1-20 | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:48-56`; `apps/web/src/features/import/pages/ImportWizardPage.tsx:1245-1261` | New warning translations exist in en/fr/ar, but `KNOWN_WARNING_CODES` omits this lane's `code_generated`, `matched_by_name`, `duplicate_in_file`, `preview_drift`, `unit_defaulted`, and other new codes. Completion therefore renders generic `warnings.other` instead of the translated messages. | Replace the hand-maintained partial set with an exhaustive enum/generated mapping, or add every supported warning code and a test for each G-4 warning. |
| G4-R1-21 | MINOR | `apps/api/app/Modules/Import/Services/UnitResolver.php:61-76` | `unit_ambiguous` candidates advertise a `category` field but hard-code it to an empty string, so the operator detail is incomplete/misleading. | Add category to `UnitCatalogEntryData` and populate it from UOM, or remove the field consistently from the typed error-detail contract and UI. |
| G4-R1-22 | NOTE | `apps/api/app/Modules/Import/Services/ProductOpeningStockPhase.php:41-45`; `apps/api/app/Modules/Import/Services/UnitResolver.php:20-87`; `apps/api/app/Modules/Product/Application/Services/ProductUnitBackfillService.php:28-34` | Several high-risk requirements are correctly implemented: opening-stock finalization selects only `outcome=imported`, so duplicate skips do not post; runtime unit matching and backfill comparison are trim-then-EXACT/case-sensitive; blank create defaults to `pc`; unknown-unit accepted codes come from the same visible catalogue. No `(float)`, `floatval`, or `is_float` was found in touched files, and Import has no direct UOM model import. | Keep these behaviors and pins intact while fixing the blockers above. |
| G4-R1-23 | NOTE | `apps/api/database/migrations/tenant/2026_08_31_100000_add_outcome_to_import_rows.php:16-102`; `apps/api/database/migrations/tenant/2026_08_31_100100_add_error_code_to_import_rows.php:13-54` | Outcome and row-error migrations are forward-only, self-guard their owned table/columns/indexes, and the legacy outcome census follows the approved precedence. G-4's `import_rows.import_error_code` is distinct from G-6a's planned `import_jobs.error_code`; timestamps `2026_08_30_100200` (G-3b), `2026_08_30_100400` (G-6a), then G-4's `2026_08_31_*` are orderable without DDL overlap. | Preserve the distinct column names and migration order during rebase. |

## Outcome / writer / transaction audit

| terminal outcome | current writer | transaction observation | gate result |
|---|---|---|---|
| `imported` | `ImportService::processPendingRow()` | entity and row outcome share the row transaction | structurally correct for ordinary product/partner rows; execute-time identity is stale because the supplied plan was made before the transaction |
| `duplicate_skipped` | `ImportService::processPendingRow()` | row outcome is committed in the row transaction; no entity writer runs | correct transaction shape; authoritative re-resolution and real kill/resume proof are missing |
| `duplicate_loser` | `ImportService::processPendingRow()` | row outcome is committed in the row transaction | correct transaction shape; winner key uses raw location text and the pre-loop plan is stale |
| `failed` (execute) | `ImportService::processPendingRow()` catch | entity transaction rolls back, then one separate row update records failure | correct shape in the one injected rollback test |
| `failed` (validate) | validation update | validation errors and terminal outcome are written together | scoped tests pass |
| `opening_locked` | finalization result application | allowed `imported -> opening_locked` transition | present |
| opening-balance `failed` | `ImportService::finalizeImport()` | changes already-terminal `imported -> failed` after the row loop | forbidden; G4-R1-05 |

`outcomeCounts()` itself implements the approved equations at `ImportService.php:541-562`. Durable job persistence is incomplete because there is no `skipped_rows` field (G4-R1-06).

## Rebase reconciliation

1. **G-3b / M4 (`2026_08_30_100200`)** — retain G-3b's `company_id`/`source_hash` additions, pinned-company ownership, M4 entitlement, and every store/show/preview/update/validate/execute/download gate. G-4 did not edit `index()` or `formatJob()` (zero matching hunks versus `68c698f1a`), so take those methods wholesale from G-3b. Re-check that duplicate census and options PATCH use the job's pinned company, not a mutable current-company context.
2. **G-6a / M6c (`2026_08_30_100400`)** — the DDL is distinct: G-6a owns job-level `error_code`/`error_detail` plus claim/reaper clocks; G-4 owns row-level `import_error_code`/`import_error_detail`. G-6a owns the first shared `ImportErrorDetailData` declaration, so merge the field union into that class rather than keeping parallel DTOs.
3. **`ProcessImportJob` conflict** — take G-6a claim/release/reaper/CAS logic as the governing skeleton, then integrate G-4's row outcomes and counters into `ImportJobClaimService::finalize()`. Do not retain G-4's current in-job claim block at `ProcessImportJob.php:130-158`, and do not overwrite G-6a's claim logic. Resolve the missing `skipped_rows` schema/API ownership before merge.
4. **Controller execute conflict** — merge G-4's failed-before-start refusal at `ImportController.php:513-518` into G-6a's claim/refusal endpoint. Preserve G-3b entitlement and G-6a CAS behavior. `index()` and `formatJob()` remain untouched by G-4.
5. **Session I selectors** — `apps/web/e2e/campaign/selectors.ts` is not present on this branch. After Session I lands, reconcile exact values for `import-preview-duplicate-summary`, all three policy IDs, wizard step/next, and execute. G-4 must consume, not edit, that concurrent owner file.
6. **G-2/G-13 seams** — G-4 owns `ProductUpsertResultData`; reconcile all direct callers/pins before G-2 extends the same DTO. G-13 may replace only the body behind `UnitCatalogQueryInterface`; preserve the Shared contract and correct the tenant/company winning-tier vocabulary first.

## Command evidence

All PostgreSQL commands were run serially by test path and prefixed with `DB_DATABASE=autoerp_test_g5 DB_CENTRAL_DATABASE=autoerp_test_g5`.

| command / leg | result |
|---|---|
| New G-4 Import classes plus `UnitCatalogQueryTest` on SQLite | PASS — 28 tests, 137 assertions |
| Full requested scoped SQLite regression aggregation (never full suite) | **FAIL** — 85 tests, 617 assertions, 6 errors, 2 failures, 4 skipped |
| `ImportReExecutionGuardTest` isolated | **FAIL** — 7 tests, 25 assertions, 1 error, 1 failure |
| `ProductUpsertKeyPrecedenceTest` isolated | **FAIL** — 10 tests, 23 assertions, 5 errors, 1 failure |
| Remaining requested SQLite paths (`ProductsImportPipelineTest`, `PartiesImportBalancesTest`, `ImportRowWarningsTest`, `ProcessImportJobStatusTest`, RoundTrip, `PartnerCodeUpsertTest`, `UnitsInvariantTest`, and the other requested pins) | PASS — 68 tests, 569 assertions, 4 skipped |
| PG `ImportRowOutcomeBackfillTest` | PASS — 2 tests, 10 assertions |
| PG `ImportRowCodedErrorTest` | PASS — 1 test, 11 assertions |
| PG `ImportJsonbCastHydrationTest` | PASS — 5 tests, 16 assertions |
| PG `ImportOutcomeAtomicityTest` | PASS — 4 tests, 29 assertions, but coverage is not the required fault model (G4-R1-04) |
| PG `DuplicateCensusTest` | PASS — 2 tests, 15 assertions |
| PG `UnitResolutionTest` | PASS — 6 tests, 33 assertions |
| PG `ProductIdentityResolutionTest` | PASS — 4 tests, 9 assertions |
| PG `CoalescingMergeTest` | PASS — 3 tests, 6 assertions, but it is not a database re-import (G4-R1-13) |
| PG `UnitCatalogQueryTest` | PASS — 1 test, 8 assertions |
| PG aggregate | PASS — 28 tests, 137 assertions |
| PHPStan on 57 touched PHP files | PASS — 0 errors |
| Pint `--test` on touched PHP files | PASS |
| Feature manifest checker | PASS — 1,471 Feature classes / 74 groups; ceiling `1212 -> 1221`, Import `25 -> 33`, Uom `8 -> 9` |
| `.github/workflows/ci.yml` YAML parse and anchored filter | PASS — parseable; all 8 new Import classes plus the UOM class are anchored |
| Deptrac | Existing baseline: 183 violations, 13,511 uncovered, 14,328 allowed, 0 errors; no touched-class edge was reported |
| Touched-file money/quantity float grep | PASS — 0 matches for `(float)`, `floatval`, `is_float` |
| Import-to-UOM direct import grep | PASS — 0 direct UOM entity/service imports; Shared contract is used |
| `cd apps/web && pnpm vitest run src/features/import` | PASS — 11 files, 60 tests (existing React `act()` warnings) |
| `cd apps/web && pnpm typecheck` | PASS |
| ESLint on touched web files only | PASS — 0 errors, 19 warnings |
| `node apps/web/tools/audit-tanstack-keys.mjs` | PASS — 0 unscoped keys, 0 new/stale suppressions |
| `CACHE_STORE=array php artisan typescript:transform --output=...` in an alternate output + byte comparison | PASS — 3,157 lines; byte-for-byte equal to `packages/shared/types/generated.d.ts` |
| New en/fr/ar G-4 locale keys | PASS — duplicate/error/warning keys are present in all three locales. The pre-existing broader Arabic import catalogue remains smaller (80 scalar keys versus 250 en/fr) but the lane's keys are present. |
| React Doctor | INCONCLUSIVE — default npm cache failed with root-owned-cache `EPERM`; retry with a private cache produced no output and was terminated after repeated 30-second polls. Vitest/typecheck/touched ESLint/query-key audit above completed independently. |
| `git diff --check` | PASS |
| Worktree mutation audit | 72 dirty entries before and after review; 34 tracked diff files. No source file was intentionally changed by this gate. |

## Staging-safety census and operator action

- `2026_08_31_100000_add_outcome_to_import_rows` reports four mutually exclusive legacy buckets: `imported` for `is_imported=true`; `failed_validation` for non-imported invalid rows; `failed_execution` for non-imported valid rows with `import_error`; and `pending` for non-imported valid rows without an execution error. Historical rows cannot be inferred as duplicate skips/losers. The sum must equal the pre-migration legacy row count for each tenant.
- `2026_08_31_100100_add_error_code_to_import_rows` adds nullable machine-readable row fields and intentionally does not invent codes for legacy free-text errors. Existing error rows are therefore expected to have null `import_error_code` until a later explicit classification migration.
- `2026_08_31_100200_backfill_product_unit_ids` reports `mapped + ambiguous + unknown` for products with null `unit_id` and non-null free-text `unit`. Exact case-sensitive code is required. On tenant #1, legacy values such as `piece` do **not** alias to `pc`; they remain `unit_id=NULL` and increment `unknown`. With roughly 855 imported products, the operator must export the census and affected product IDs, map each legacy spelling to an approved visible exact code (for “piece”, normally confirm and normalize to `pc`), correct the source rows in a reviewed repair, rerun the same backfill service/repair, and require `ambiguous=0` plus an explicitly accepted `unknown=0` (or documented exceptions) before enabling code that assumes `unit_id`. Do not case-fold or add aliases during this repair. G4-R1-09 and G4-R1-10 must be fixed before fleet execution.

## Final verdict

**FAIL** — do not rebase, stage, or merge. Clear all BLOCKER rows, make both pinned regression classes green without unauthorized test amendments, add the real kill/resume and sparse re-import coverage, then rerun this exact scoped matrix on SQLite and the private PostgreSQL database.

## Gate r2 (Codex)

- **Reviewed state:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/g4-duplicates`, branch `feat/g4-duplicate-policy-merge`, HEAD/base `68c698f1a680e5feefa3aadd7a6a8b22be41ff26`, dirty/uncommitted. Source was read-only; this register is the only intentional file write.
- **Resume discipline:** continued from the existing fix-round command record; completed the dependency, rebase, migration-safety, and line audit without rerunning the already-green scoped matrix.
- **PostgreSQL isolation:** the recorded PG runs were serial by path and every invocation used `DB_DATABASE=autoerp_test_g5 DB_CENTRAL_DATABASE=autoerp_test_g5`.

### VERDICT: FAIL

All 23 r1 findings are closed, including the authoritative row-transaction decision, real resume faults, durable skip counts, migration diagnostics, sparse re-imports, typed row JSON, and frontend policy contract. One new migration blocker remains: the unit backfill combines a leading `company_id` sort with an `id`-only `chunkById` cursor, which can silently omit eligible products in a multi-company tenant and publish an incomplete census.

### R1 finding disposition

| ID | status | r2 evidence |
|---|---|---|
| G4-R1-01 | **CLOSED** | `ImportService.php:440-519` calls `DuplicateCensusService::decide()` inside each row transaction and emits `preview_drift` at `:462-470`; the preview-race pin is `DuplicateCensusTest.php:174-195`. |
| G4-R1-02 | **CLOSED** | `DuplicateCensusService.php:24,37-90,170-207` is a 500-row chunked pass with batched SKU/barcode/name and location lookups; invalid rows are excluded and the query ceiling is pinned at `DuplicateCensusTest.php:103-138`. |
| G4-R1-03 | **CLOSED** | Placement keys use resolved product identity plus resolved `location_id` (`DuplicateCensusService.php:60-70,190-207,266-286`); trim-equivalent and unresolved-location cases are pinned at `DuplicateCensusTest.php:140-171`. |
| G4-R1-04 | **CLOSED** | Four post-commit/rollback resume cases cover ordinary write, duplicate skip, correction warning, and failed-row continuation (`ImportOutcomeAtomicityTest.php:191-314`), using the throwing decorator at `:456-479`. |
| G4-R1-05 | **CLOSED** | Finalization performs only the permitted imported-to-`opening_locked` terminal rewrite (`ImportService.php:691-726`); product opening selection is imported-only (`ProductOpeningStockPhase.php:41-45`), and all other refusals remain warnings/results. |
| G4-R1-06 | **CLOSED** | Guarded `skipped_rows` DDL is at `2026_08_31_100000_add_outcome_to_import_rows.php:26-31`; model/default/API/sync/queue persistence is at `ImportJob.php:32,67,82`, `ImportController.php:693-717`, `ImportService.php:416-423`, and `ProcessImportJob.php:206-217`. |
| G4-R1-07 | **CLOSED** | The deleted-holder guard checks only the SKU actually written (`ProductService.php:99-115,465-475`); the unrelated trashed-barcode pin is `ProductUpsertKeyPrecedenceTest.php:155-186`. |
| G4-R1-08 | **CLOSED** | The normalized-name arm uses `withTrashed()` and refuses only a trashed row holding the derived SKU (`ProductService.php:429-455`), pinned at `ProductUpsertKeyPrecedenceTest.php:224-254`. |
| G4-R1-09 | **CLOSED** | One exact winning-tier helper implements company > tenant > system and same-tier ambiguity (`UnitCatalogEntryData.php:22-51`), shared by runtime and backfill (`UnitResolver.php:20-76`; `ProductUnitBackfillService.php:53-65`). Tier pins are `UnitResolutionTest.php:126-195`. |
| G4-R1-10 | **CLOSED** | The unit migration guards all required tables/columns, catches unexpected legacy state, and reports without throwing (`2026_08_31_100200_backfill_product_unit_ids.php:14-69`); missing companies are counted per row (`ProductUnitBackfillService.php:37-51`). See new G4-R2-01 for a separate pagination defect. |
| G4-R1-11 | **CLOSED** | Replay selection requires both `is_imported=false` and `outcome=pending` (`ImportService.php:312-329`); `ProcessImportJob::handle()` retains the two required parameters and adds only an optional catalogue injection (`ProcessImportJob.php:75-80,113-115`). |
| G4-R1-12 | **CLOSED** | `upsert()` remains string-returning and delegates to the new DTO method (`ProductService.php:63-80`; `ProductServiceInterface.php:28-48`); the pinned precedence class is green. |
| G4-R1-13 | **CLOSED** | DB-backed sparse product and partner re-imports preserve type/unit/prices/contact/address (`ImportOutcomeAtomicityTest.php:316-386`); the three governing tax-configuration re-import pins in `ProductsImportPipelineTest.php:979-1098` are green. |
| G4-R1-14 | **CLOSED** | Flat `_results` and `_placement_plan` hydrate and serialize through explicit DTOs (`ImportRowSourceData.php:7-99`; `ImportPlacementPlanData.php:7-87`; `ImportPlacementSegmentData.php:7-52`) and the cast has no shape bypass (`ImportRowSourceCast.php:9-21`). |
| G4-R1-15 | **CLOSED** | API omits `duplicates` without a census (`ImportController.php:294-319`); FE renders the panel only when present (`ImportWizardPage.tsx:1003-1078`), pinned at `ImportWizardPage.duplicates.test.tsx:176-201`. |
| G4-R1-16 | **CLOSED** | Matched-by-name rows render a ten-row bounded initial view with expansion (`ImportWizardPage.tsx:1034-1051`), asserted at `ImportWizardPage.duplicates.test.tsx:132-135`. |
| G4-R1-17 | **CLOSED** | Policy selection is local; exactly one awaited PATCH occurs on Next, with pending/error gates (`ImportWizardPage.tsx:615-649,1103-1112,1222-1228`), including deferred and rejected PATCH pins at `ImportWizardPage.duplicates.test.tsx:150-224`. |
| G4-R1-18 | **CLOSED** | Cancel opens the durable-job discard confirmation and deletes only after confirmation (`ImportWizardPage.tsx:615-619,651-664,1454-1464`), pinned at `ImportWizardPage.duplicates.test.tsx:226-247`. |
| G4-R1-19 | **CLOSED** | The selector test traverses preview to execute and asserts summary, override/skip/cancel, step, Next, and `import-wizard-execute` (`ImportWizardPage.duplicates.test.tsx:110-148`). |
| G4-R1-20 | **CLOSED** | `WARNING_TRANSLATION_KEYS` is compile-time exhaustive against the generated enum (`warningCodes.ts:1-31`); en/fr/ar parity is pinned by `ImportWarningLocales.test.ts:8-28`. |
| G4-R1-21 | **CLOSED** | Unit candidates now carry the joined category and tier (`UnitCatalogQuery.php:24-36`; `UnitResolver.php:50-66`), with same-tier candidate detail pinned at `UnitResolutionTest.php:149-168`. |
| G4-R1-22 | **CLOSED / retained** | Imported-only opening selection, exact case-sensitive unit matching, shared catalogue use, and no touched money/quantity float casts remain intact (`ProductOpeningStockPhase.php:41-45`; `UnitCatalogEntryData.php:26-41`). |
| G4-R1-23 | **CLOSED / retained** | Outcome and coded-error migrations remain guarded and forward-only (`2026_08_31_100000_add_outcome_to_import_rows.php:17-114`; `2026_08_31_100100_add_error_code_to_import_rows.php:13-54`). Their console output is facade-guarded; on rebase, switch it to `App\Shared\Database\MigrationOutput`. |

### New finding

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| **G4-R2-01** | **BLOCKER** | `apps/api/app/Modules/Product/Application/Services/ProductUnitBackfillService.php:25-30`; test gap `apps/api/tests/Feature/Import/UnitResolutionTest.php:171-229` | The backfill orders by `company_id`, then calls `chunkById(500)` with an `id`-only cursor. Laravel removes only the existing `id` order and retains `company_id` as the leading order. After page one it applies `id > last_id`; UUIDs in a later company that sort at or below that last ID are silently skipped. The reported `mapped + ambiguous + unknown + missing_company` census can therefore be smaller than the eligible population. Existing tests use only a few rows/one company and cannot falsify the second page. | Page solely by a stable unique ID, process each company independently, or use a correct composite `(company_id,id)` cursor. Add a >500-row, multi-company PostgreSQL pin whose eligible count equals the complete census and whose every row is visited. |

### Deptrac audit

Direct Deptrac remains **183 violations / 0 errors**; the latest no-cache report was 13,537 uncovered and 14,347 allowed. The ratchet's `SharedContracts on ModuleDomain 36 -> 37` edge is exactly `App\Shared\Contracts\TaxDefaultResolverInterface::resolveDefaultTaxForNewProduct()` -> `App\Modules\Company\Domain\Company` at `TaxDefaultResolverInterface.php:24`. `git blame` attributes it to base commit `831a455245`; the same contract already had the same `Company` edge at `:15`, so it is an existing baseline shape, not a G-4 Shared-contract leak. No G-4-touched/new Shared contract references a Module Domain type.

### Rebase reconciliation required

1. **G-3b / company pin / M4.** Take G-3b's filtered, company-scoped `ImportController::index()` (`feat/g3b-import-company-pin`, `ImportController.php:59-94`) instead of G-4's untouched base method. Union G-4's `formatJob()` counters/warnings (`ImportController.php:693-717`) with G-3b's company/unattributed response. Add `ModuleEntitlementCheck` to every G-4-touched preview/options/execute surface. Union `ImportJob.php` company/source fields with G-4's `skipped_rows` and typed casts. Keep M4 `2026_08_30_100200_add_company_to_import_jobs.php`, but use current dev's `MigrationOutput` helper; never retain the older bare `echo` implementation.
2. **Adopt-on-execute + G-6a claim.** G-3b's standalone adoption is `ImportController.php:621-635`; G-6a owns the locked claim in `ImportJobClaimService.php:25-56`. Fold NULL-company adoption, status transition, and claim clocks into one guarded update, then preserve the sibling-company `IMPORT_COMPANY_MISMATCH` loser response. G-4's failed-before-start refusal and duplicate-policy execution must sit after entitlement/company checks and inside the claimed lifecycle.
3. **G-6a worker/finalize skeleton.** Replace G-4's local claim block (`ProcessImportJob.php:135-162`) with `ImportJobClaimService`; retain G-4's per-row `processPendingRow()` call and outcome-derived counters (`:166-217`) and publish them through G-6a's single terminal CAS. Reconcile `ImportService::executeImport()` with G-6a's sync claim/start/release path rather than keeping a second transition writer.
4. **M6c + shared data.** Union G-4's guarded `skipped_rows` addition (`2026_08_31_100000...:26-31`) with G-6a M6c `2026_08_30_100400_add_lifecycle_columns_to_import_jobs.php`; both landing orders remain safe. Merge, do not duplicate, `ImportErrorDetailData`; retain G-6a lifecycle/error fields plus G-4 row-error unit candidates. Union lifecycle/company/skipped fillables, casts, generated declarations, and API counters.
5. **Shared merge surfaces.** Reconcile `.github/workflows/ci.yml`, `apps/api/tests/feature-lane-manifest.json`, and `packages/shared/types/generated.d.ts` by set/content union. Current dev ceiling is **1215**, Import **25**, Uom **8**, Migrations **11**. G-4 adds Import +8 and Uom +1, so post-rebase values are **1224 / Import 33 / Uom 9 / Migrations 11**. The current dirty branch still reads **1221 / 33 / 9 / 11** because it was cut from ceiling 1212.

### Migration staging safety

- `2026_08_31_100000` has an exhaustive pre-update census (`imported`, validation-failed, execution-failed, pending), guarded row/job DDL, and a forward-only no-op `down()` (`:17-114`). Require the four buckets to sum to the pre-migration row count.
- `2026_08_31_100100` adds nullable coded-error fields without inventing codes for legacy text and is independently guarded/forward-only (`:13-54`).
- `2026_08_31_100200` guards its dependent schema and catches missing/orphan companies (`:14-69`; service `:37-51`), but is **not fleet-safe until G4-R2-01 is fixed**. Tenant #1's roughly 855 imported products with free-text units are expected to land predominantly in `unknown`; values such as `piece` must not alias to `pc`. Before enabling `unit_id` assumptions, export affected IDs and the census, review a repair that normalizes each approved spelling to an exact visible code, rerun the backfill, and require `ambiguous=0` plus `unknown=0` or explicit signed exceptions. Also require `mapped + ambiguous + unknown + missing_company` to equal the eligible pre-run population.

### Command evidence

| command / leg | result |
|---|---|
| Requested SQLite matrix by path (new census/outcome/atomicity/resolver/coalescing/unit classes; pipeline, parties, warnings, worker/re-execution, RoundTrip, upsert/unit/opening pins) | **PASS — 143 passed, 4 skipped, 995 assertions** |
| Requested PostgreSQL migration/outcome/census/unit/atomicity paths, serial with the mandated DB prefix | **PASS — 44 passed, 208 assertions** |
| PHPStan on touched PHP | **PASS — 0 errors** |
| Pint `--test` on touched PHP | **PASS** |
| Feature manifest checker | **PASS — 1,480 Feature classes / 74 groups**; dirty-branch ceiling/groups `1221 / Import 33 / Uom 9 / Migrations 11`; required dev union `1224 / 33 / 9 / 11` |
| `.github/workflows/ci.yml` parse + anchored filter | **PASS** — YAML parseable; eight Import classes plus `UnitCatalogQueryTest` are present |
| Deptrac direct | Existing baseline only: **183 violations / 0 errors**; exact 36->37 edge identified above |
| `cd apps/web && pnpm vitest run src/features/import` | **PASS — 12 files, 67 tests** |
| `cd apps/web && pnpm typecheck` | **PASS** |
| ESLint on touched web files | **PASS — 0 errors, 20 warnings** |
| `node tools/audit-tanstack-keys.mjs` (web tool path) | **PASS** |
| `CACHE_STORE=array php artisan typescript:transform` | **PASS — 544 types; no further diff** |
| `git diff --check` | **PASS** |

Do not rebase, stage, or merge until G4-R2-01 is fixed and its large multi-company PostgreSQL regression is green. Then rerun the unit migration/backfill path plus the scoped static/manifest checks; the already-green full matrix need not be broadened into a full suite.

### Attempt-2 continuation (authoritative correction)

The `## Gate r2 (Codex)` section above was already present when this resume reached the register, so it is continued here rather than duplicated. The r1 disposition table remains unchanged: G4-R1-01 through G4-R1-23 are **CLOSED** (with G4-R1-22/23 retained). The statement that only one new blocker remained is superseded by the additional resolver finding below.

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| **G4-R2-02** | **BLOCKER** | `apps/api/app/Modules/Product/Application/Services/ProductResolver.php:56-62,93-103,120-125`; `apps/api/app/Modules/Product/Application/Services/ProductService.php:386-397,429-455`; callers `apps/api/app/Modules/Import/Services/DuplicateCensusService.php:170-184,209-219` | The batched resolver used by the preview census does not preserve the single-row resolver's deleted-holder refusal. For a supplied SKU, `resolveMany()` returns the first `withTrashed()` row as `existing_sku` even when it is deleted; for the name-only arm it filters deleted rows and returns `new`, including when the deleted row holds the SKU derived from that name. The authoritative execute path uses `resolve()` and refuses both cases. Consequently preview can invite `skip` for a deleted SKU holder (or report a name-only row as new), then execution fails it before applying the selected policy. No census test covers either deleted-holder arm (`DuplicateCensusTest.php:46-195`); the existing refusal pin exercises only the single resolver (`ProductIdentityResolutionTest.php:76-93`). | Make `resolveMany()` and `resolve()` return the same per-input identity/refusal semantics without restoring N+1 queries. Represent a deleted-holder refusal per row through a Shared DTO/coded result so one bad row does not abort the whole chunk. Add census and execute-policy pins for a deleted supplied-SKU holder and a deleted name-derived-SKU holder. |

Additional attempt-2 command evidence (source remained read-only):

| command / leg | result |
|---|---|
| Requested SQLite paths rerun as one scoped aggregation | **PASS — 140 passed, 4 skipped, 945 assertions** |
| `ImportPreviewTest` separately | **PASS — 7 passed, 63 assertions**; combined observed rerun total **147 passed, 4 skipped, 1,008 assertions** |
| Requested PostgreSQL classes, serial by path; every PHP invocation prefixed `DB_DATABASE=autoerp_test_g5 DB_CENTRAL_DATABASE=autoerp_test_g5` | **PASS — 44 passed, 208 assertions** |
| PHPStan level 8 on 82 touched PHP files / Pint `--test` on the same set | **PASS — 0 errors** / **PASS** |
| Feature manifest checker / CI YAML+anchored-filter check | **PASS — 1,480 classes / 74 groups** / **PASS**; required post-rebase union remains **1224 / Import 33 / Uom 9 / Migrations 11** |
| Deptrac ratchet | **183 violations / 0 errors**; `SharedContracts -> ModuleDomain 36 -> 37` is the base `TaxDefaultResolverInterface.php:24` method reusing the already-existing `Company` edge at `:15`, not a G-4 edge |
| Import Vitest / typecheck / touched ESLint / TanStack key audit | **67 passed** / **PASS** / **0 errors, 20 warnings** / **PASS** |
| `CACHE_STORE=array php artisan typescript:transform` | **PASS — 544 types**; SHA-256 unchanged (`bf1e441e6bf1b795c955e2014511a311cef6111fbc250303f41d3bb0f7624d14`) |
| `git diff --check` | **PASS** |

### Corrected final verdict: FAIL

Do not rebase, stage, or merge. G4-R2-01 can silently omit unit-backfill rows after the first page, and G4-R2-02 makes preview duplicate/refusal semantics disagree with authoritative execution. After both are fixed, run their new PostgreSQL regressions plus the scoped migration/census/resolver/static/manifest checks; do not broaden to a full suite.

## Gate r3 (Codex)

Re-review target: snapshot `383ff6bec475ab96595fefed5dbc10b1e576da06` plus the dirty fix-round-2 overlay. Source was reviewed read-only. This section is the only write.

### Finding closure audit

| finding | r3 status | verification |
|---|---|---|
| G4-R1-01 | **CLOSED** | Authoritative identity is re-resolved inside each row transaction and drift is warned (`apps/api/app/Modules/Import/Services/ImportService.php:441-470`); the race pin remains at `apps/api/tests/Feature/Import/DuplicateCensusTest.php:175-195`. |
| G4-R1-02 | **CLOSED** | The census remains a valid/pending-only, 500-row chunked pass with batched identity/location resolution (`apps/api/app/Modules/Import/Services/DuplicateCensusService.php:40-93,174-210`); the query-ceiling pin remains at `DuplicateCensusTest.php:104-139`. |
| G4-R1-03 | **CLOSED** | Within-file placement still keys product identity with resolved `location_id` (`DuplicateCensusService.php:291-312`), with alias/unresolved coverage at `DuplicateCensusTest.php:141-173`. |
| G4-R1-04 | **CLOSED** | Four real throwing-decorator resume cases remain (`apps/api/tests/Feature/Import/ImportOutcomeAtomicityTest.php:191-314,456-479`). |
| G4-R1-05 | **CLOSED** | The only terminal rewrite remains `imported -> opening_locked` (`ImportService.php:692-726`); opening selection remains imported-only (`apps/api/app/Modules/Import/Services/ProductOpeningStockPhase.php:41-45`). |
| G4-R1-06 | **CLOSED** | G-4's `skipped_rows` addition is column-guarded (`apps/api/database/migrations/tenant/2026_08_31_100000_add_outcome_to_import_rows.php:26-31`) and counters remain outcome-derived in sync/queue finalization (`ImportService.php:416-423`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:206-217`). |
| G4-R1-07 | **CLOSED** | Deleted-holder refusal applies only when the trashed row holds the effective SKU (`apps/api/app/Modules/Product/Application/Services/ProductService.php:99-115,377-420`); the unrelated-barcode pin remains (`apps/api/tests/Feature/Product/ProductUpsertKeyPrecedenceTest.php:155-186`). |
| G4-R1-08 | **CLOSED** | The name arm remains `withTrashed()` with the effective-SKU guard (`ProductService.php:429-455`), pinned at `ProductUpsertKeyPrecedenceTest.php:224-254`. |
| G4-R1-09 | **CLOSED** | Exact tier selection remains company > tenant > system with same-tier ambiguity in the shared helper (`apps/api/app/Shared/DTOs/UnitCatalogEntryData.php:26-51`), used by runtime and backfill (`apps/api/app/Modules/Import/Services/UnitResolver.php:20-76`; `apps/api/app/Modules/Product/Application/Services/ProductUnitBackfillService.php:51-63`). |
| G4-R1-10 | **CLOSED** | All three migrations remain guarded/forward-only; unit backfill guards dependencies and catches legacy exceptions (`apps/api/database/migrations/tenant/2026_08_31_100200_backfill_product_unit_ids.php:20-69`). Console output is guarded, not bare echo (`2026_08_31_100000_add_outcome_to_import_rows.php:102-104`; `2026_08_31_100200_backfill_product_unit_ids.php:81-85`). |
| G4-R1-11 | **CLOSED** | Replay selection requires `is_imported=false` and `outcome=pending` (`ImportService.php:323-330`); the worker retains two required arguments with only optional catalogue injection (`ProcessImportJob.php:75-79`). |
| G4-R1-12 | **CLOSED** | `upsert()` still returns `string`; `upsertWithResult()` is additive (`ProductService.php:70-82`; `apps/api/app/Shared/Contracts/ProductServiceInterface.php:28-48`). |
| G4-R1-13 | **CLOSED** | DB-backed sparse re-import pins remain at `ImportOutcomeAtomicityTest.php:316-386`; all three tax-governor pins remain at `apps/api/tests/Feature/Import/ProductsImportPipelineTest.php:979-1098`. |
| G4-R1-14 | **CLOSED** | `_results` and `_placement_plan` remain typed/hydrated (`apps/api/app/Modules/Import/Domain/Data/ImportRowSourceData.php:7-99`; `ImportPlacementPlanData.php:7-87`; `ImportPlacementSegmentData.php:7-52`; `apps/api/app/Modules/Import/Domain/Casts/ImportRowSourceCast.php:9-21`). |
| G4-R1-15 | **CLOSED** | API omits census-less `duplicates` (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:294-319`) and FE hides the panel (`apps/web/src/features/import/pages/ImportWizardPage.tsx:1003-1078`). |
| G4-R1-16 | **CLOSED** | The matched-by-name list remains bounded to ten with expansion (`ImportWizardPage.tsx:1034-1051`). |
| G4-R1-17 | **CLOSED** | There remains one awaited policy PATCH with Next/Execute disabled while pending (`ImportWizardPage.tsx:625-649,1103-1112,1222-1228`). |
| G4-R1-18 | **CLOSED** | Cancel still uses confirmation before deleting the durable draft (`ImportWizardPage.tsx:615-619,651-664,1454-1464`). |
| G4-R1-19 | **CLOSED** | Selector-contract coverage still asserts summary, both policies, cancel, step/Next/execute, and `import-wizard-execute` (`apps/web/src/features/import/__tests__/ImportWizardPage.duplicates.test.tsx:110-148`). |
| G4-R1-20 | **CLOSED** | Warning translation keys remain exhaustive against the generated enum and en/fr/ar parity is pinned (`apps/web/src/features/import/warningCodes.ts:1-31`; `apps/web/src/features/import/__tests__/ImportWarningLocales.test.ts:8-28`). |
| G4-R1-21 | **CLOSED** | Ambiguity candidates still carry category and tier (`apps/api/app/Modules/Uom/Application/Services/UnitCatalogQuery.php:24-36`; `UnitResolver.php:50-66`). |
| G4-R1-22 | **CLOSED / retained** | Imported-only opening selection, exact case-sensitive unit matching, shared catalogue use, and absence of touched float casts remain intact (`ProductOpeningStockPhase.php:41-45`; `UnitCatalogEntryData.php:26-41`). |
| G4-R1-23 | **CLOSED / retained** | Outcome/error migrations remain guarded, distinct, and forward-only (`2026_08_31_100000_add_outcome_to_import_rows.php:17-114`; `2026_08_31_100100_add_error_code_to_import_rows.php:13-54`). |
| G4-R2-01 | **CLOSED** | Backfill now uses id-only `lazyById(500)` with no competing order (`ProductUnitBackfillService.php:25-64`). The two-company/1,001-row PostgreSQL pin visits and accounts for all 1,001 rows (`apps/api/tests/Feature/Import/UnitResolutionTest.php:232-279`). |
| G4-R2-02 | **NOT CLOSED** | `resolve()` and `resolveMany()` now share one `resolveInput()` ladder (`apps/api/app/Modules/Product/Application/Services/ProductResolver.php:19-28,31-53,63-138`) and the Shared contract is clean, but the census discards `resolution->failure`: only execute calls `throwIfResolutionFailed()` (`DuplicateCensusService.php:112-120`), while `bucketForResolution()` converts every failed/null match to `new` (`:260-267`). The purported parity test independently hard-codes the mismatch: supplied deleted holder, name-derived deleted holder, and ambiguous barcode preview as `new`, then execute as `failed` (`DuplicateCensusTest.php:198-260`). This preserves the original operator-visible preview/execute disagreement instead of closing it. |

### New findings

| ID | severity | file:line | finding | required change |
|---|---|---|---|---|
| **G4-R3-01** | **MAJOR** | `apps/api/autoerp_test_g5` (tracked by snapshot `383ff6bec`; blob `3ff34659de8f9329d4a7aebe94546019a866d12f`, 6,819,840 bytes); `apps/api/.gitignore:1-24` | The snapshot commit contains a 6.5 MB local SQLite test database. Even though its sampled application tables are empty, it is generated state, bloats history, and creates an unsafe precedent for accidentally committing tenant/test data. | Remove the database artifact from the branch before merge and ignore the local test-database naming pattern. Do not rewrite or delete it as part of this read-only gate. |

### R2-specific and architecture checks

- The r2-01 pagination defect is fixed and the required PostgreSQL 1,001-row/two-company regression is green. `lazyById(500)` is ID ordered by the framework; there is no explicit conflicting `orderBy` (`ProductUnitBackfillService.php:25-64`).
- There is one resolver ladder, used by batch census and single-row execution (`ProductResolver.php:19-28,31-53,63-138`; `DuplicateCensusService.php:174-188,213-223`). However, one ladder is not sufficient while the census consumer drops its typed failure. G4-R2-02 therefore remains a blocker.
- `ProductIdentityFailure` is a Shared backed enum and `ProductIdentityResolutionData`/`ProductResolverInterface` contain only scalars, Shared enums, and Shared DTOs (`apps/api/app/Shared/Enums/ProductIdentityFailure.php:7-11`; `apps/api/app/Shared/DTOs/ProductIdentityResolutionData.php:7-20`; `apps/api/app/Shared/Contracts/ProductResolverInterface.php:7-26`). Fresh deptrac remains **183 violations / 0 errors**. The apparent `SharedContracts -> ModuleDomain 36 -> 37` edge is `apps/api/app/Shared/Contracts/TaxDefaultResolverInterface.php:24 -> App\\Modules\\Company\\Domain\\Company`, reusing the pre-existing edge at `:15`; blame identifies `831a455245` for `:24`. No G-4 Shared-to-ModuleDomain edge was introduced.
- React Doctor scoped to the eight changed web files reports **No issues found**, score 93, zero findings.

### Migration staging safety

The three migrations are structurally staging-safe: owned tables/columns/indexes are guarded, data repair is forward-only, exceptions do not stop the fleet, and diagnostics are console-only (`2026_08_31_100000_add_outcome_to_import_rows.php:17-114`; `2026_08_31_100100_add_error_code_to_import_rows.php:13-54`; `2026_08_31_100200_backfill_product_unit_ids.php:20-85`). The facade-guarded `fwrite` calls are acceptable on this pre-helper branch. On rebase they **must** switch to `App\Shared\Database\MigrationOutput` at exactly:

- `apps/api/database/migrations/tenant/2026_08_31_100000_add_outcome_to_import_rows.php:102-104`
- `apps/api/database/migrations/tenant/2026_08_31_100200_backfill_product_unit_ids.php:81-85`

Tenant #1's approximately 855 imported products with free-text units are expected to produce a material `unknown` census because backfill is deliberately trim-then-exact and case-sensitive (`ProductUnitBackfillService.php:51-63`). Before staging rollout, export the affected IDs/free-text values, review the emitted `mapped/ambiguous/unknown/missing_company` census, normalize only operator-approved exact codes, rerun the repair, and require the four counters to equal the eligible-row total. Do not silently alias values such as `piece` to `pc`; require `ambiguous=0` and `unknown=0`, or signed operator exceptions, before treating the migration as reconciled.

### Rebase reconciliation against current dev

G-4 must reconcile, not overwrite, these exact dev surfaces:

- G-3b controller ownership/entitlement/adopt-on-execute: `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:49-100,137,300-336,397-403,461-467,519-525,558-690,715-721,762-768,810-816,871-877,1092-1103`.
- G-6a claim/finalization service, including guarded `skipped_rows`: `apps/api/app/Modules/Import/Application/Services/ImportJobClaimService.php:25-181`; retain its claim/start/finalize skeleton while merging G-4's transactional `processPendingRow()` and outcome counters into `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:76-149,156+`.
- Union the dev `ImportJob` company/source/lifecycle/error/skipped fields (`apps/api/app/Modules/Import/Domain/ImportJob.php:23-42,65-113`) and merge, rather than duplicate, `apps/api/app/Modules/Import/Domain/Data/ImportErrorDetailData.php:22-46`.
- Preserve G-3b M4 company adoption/backfill (`apps/api/database/migrations/tenant/2026_08_30_100200_add_company_and_source_to_import_jobs.php:49-57,108-116,176-184`) and G-6a M6c lifecycle/error plus guarded `skipped_rows` (`2026_08_30_100400_add_lifecycle_fields_to_import_jobs.php:58-67`).
- Union `.github/workflows/ci.yml`, `apps/api/tests/Feature/manifest.json`, and generated TypeScript; do not take either side wholesale.

Using the prompt's dev ceiling, the required union remains **1,224 total / Import 33 / Uom 9 / Migrations 11** (`1215/25/8/11 + G-4's 9 tests`). The live local `dev` has advanced to **1,223 / 30 / 8 / 13**; all eight G-4 Import classes plus its Uom class are absent there, so the live-ref union is now **1,232 / 38 / 9 / 13**. The dirty G-4 manifest checker itself reports **1,480 Feature classes / 74 groups**; its current category ceiling is **1,221 / Import 33 / Uom 9 / Migrations 10**. Recompute the ratchet from the actual merge base during rebase.

### Fresh command evidence

| command / leg | result |
|---|---|
| Requested SQLite paths only: all new Import census/outcome/atomicity/resolver/coalescing/unit classes; pipeline, parties, warnings, worker/re-execution, RoundTrip, upsert/unit/opening pins | **PASS — 142 passed, 4 skipped, 974 assertions** |
| `ImportPreviewTest` supplemental API pin | **PASS — 7 passed, 63 assertions**; combined observed SQLite total **149 passed, 4 skipped, 1,037 assertions** |
| Requested PostgreSQL paths, serial by path; every invocation prefixed `DB_DATABASE=autoerp_test_g5 DB_CENTRAL_DATABASE=autoerp_test_g5 DB_CONNECTION=pgsql` | **PASS — 46 passed, 237 assertions**; includes the 1,001-row/two-company pin |
| PHPStan on touched PHP | **PASS — 0 errors** |
| Pint `--test` on touched PHP | **PASS** |
| Feature manifest checker | **PASS — 1,480 Feature classes / 74 groups**; all CI filters anchored and uniquely matched |
| `.github/workflows/ci.yml` parse and filter audit | **PASS** — YAML parseable; G-4 PostgreSQL filters present |
| Deptrac direct | **183 violations / 0 errors / 14,348 allowed / 13,541 uncovered**; no G-4 SharedContracts-to-Domain edge |
| `cd apps/web && pnpm vitest run src/features/import` | **PASS — 12 files, 67 tests** |
| `cd apps/web && pnpm typecheck` | **PASS** |
| ESLint on the eight touched web files | **PASS — 0 errors, 20 warnings** |
| `node tools/audit-tanstack-keys.mjs` | **PASS — 0 acknowledged, 0 new, 0 stale** |
| `NPM_CONFIG_CACHE=/private/tmp/g4-react-doctor-cache npx react-doctor@latest --verbose --scope changed --base 68c698f1a` | **PASS — eight files, zero findings, score 93** |
| `CACHE_STORE=array php artisan typescript:transform` | **PASS — 545 types; generated declaration SHA-256 unchanged** (`2e34d87e36a2e78df9afb7f051832ed3a72e9833d53667594b1fd1bca5f549cd`) |
| `git diff --check` | **PASS** |

### VERDICT: FAIL

Do not rebase, stage, or merge. G4-R2-02 remains behaviorally open: the preview census reports resolver refusals as `new`, while authoritative execution fails those rows. Fix the census/result contract and replace the mismatch-enshrining test with independently derived parity expectations. Also remove and ignore the tracked SQLite database in G4-R3-01. Then rerun the scoped census/resolver PostgreSQL pins, manifest/static checks, and generated-type no-diff check; never broaden this lane gate to the full suite.

## Gate r4 (Codex, narrow)

Re-check target: snapshot `fad74078a97005adcf89513de14b114e52c7273a` plus the dirty fix-round-3 overlay. Scope was limited to r3's two open items. Source was reviewed read-only; this section is the only intentional review write.

### Open-item closure audit

| finding | r4 status | verification |
|---|---|---|
| G4-R2-02 | **CLOSED** | Census now maps a typed resolver failure to `DuplicateBucket::Refused`, increments that bucket, and persists a row-number/code advisory (`apps/api/app/Modules/Import/Services/DuplicateCensusService.php:63-94,275-295`). Preview returns both the stored duplicate census and the per-row advisory (`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:294-319`), pinned at `apps/api/tests/Feature/Import/ImportPreviewTest.php:204-236`. RUL-1 renders the `refused` count and distinct codes only when the count is positive (`apps/web/src/features/import/pages/ImportWizardPage.tsx:1022-1042`); the positive and hidden-at-zero cases are pinned at `apps/web/src/features/import/__tests__/ImportWizardPage.duplicates.test.tsx:133-136,159-181`, and non-empty en/fr/ar keys are pinned at `apps/web/src/features/import/__tests__/ImportWarningLocales.test.ts:8-33`. Execute re-resolves authoritatively inside the row transaction and converts the same coded resolver failure to `failed` after rollback (`ImportService.php:441-450,521-534`; `DuplicateCensusService.php:298-315`). The six-case parity table is hand-written for SKU, barcode, name-only, supplied deleted holder, name-derived deleted holder, and ambiguous barcode; it independently fixes preview bucket/code and execute outcome/code, then asserts both sides and exact refusal-code equality (`DuplicateCensusTest.php:199-288`). It expects three `refused` census rows and three coded execution failures. Final sync/queue equations remain outcome-derived, so those refusals enter `failed`, not `skipped` (`ImportService.php:550-571`; `ProcessImportJob.php:206-216`). |
| G4-R3-01 | **CLOSED** | `git ls-tree -r HEAD --name-only | grep autoerp_test_` returned no path. Root `.gitignore:79-80` carries `apps/api/autoerp_test_*`. No matching file exists at `apps/api` top level after all runs. Final `git status --short` contains only the 16 tracked fix-round lane files already listed in the fix notes; no artifact, untracked file, or unrelated path appeared. |

### Fresh command evidence

| command / leg | result |
|---|---|
| Requested SQLite paths by path: census plus API preview, outcome/atomicity/resolver/coalescing/unit classes, pipeline, upsert precedence, re-execution guard, worker status, and all RoundTrip classes | **PASS — 119 passed, 4 skipped, 861 assertions** |
| Requested PostgreSQL census/resolver/outcome/unit paths, serial by path; every invocation prefixed `DB_DATABASE=autoerp_test_g5 DB_CENTRAL_DATABASE=autoerp_test_g5 DB_CONNECTION=pgsql` | **PASS — 46 passed, 255 assertions** |
| PHPStan level 8 on the dirty fix-round touched PHP | **PASS — 0 errors** |
| Pint `--test` on the dirty fix-round touched PHP | **PASS** |
| Feature manifest checker | **PASS — 1,480 Feature classes / 74 groups**; all filters anchored and uniquely matched against 1,880 test classes |
| `cd apps/web && pnpm vitest run src/features/import` | **PASS — 12 files, 67 tests** |
| `cd apps/web && pnpm typecheck` | **PASS** |
| ESLint on all eight G-4-touched TypeScript/TSX files | **PASS — 0 errors, 20 warnings** |
| `CACHE_STORE=array php artisan typescript:transform` | **PASS — 545 types; generated declaration SHA-256 unchanged** (`fe93295dfc16d54416d317abe729c6356bbe66aa50d4dbfe3e22c85e4605820d`) |
| Artifact/state audit and `git diff --check` | **PASS** — tracked-tree grep empty, ignore present, no materialized `apps/api/autoerp_test_*`, only the expected 16 dirty lane files, and no whitespace errors |

### VERDICT: PASS

Both r3 open items are closed. This narrow r4 found no remaining imports-reviewer or frontend-conventions-reviewer blocker in scope.
