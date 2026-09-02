# K-8/K-9/K-11/K-12/K-13 branch code gate — round 1 (adversarial, imports-reviewer)

- **Target:** worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/k89-units`, branch `fix/k89-units-validation-mapping`, full uncommitted diff (76 tracked files + 21 untracked) against branch base `6bec260a24cb4a28df331666b40f6ecf1ceb7763`.
- **Method:** static reading only. No suites run, nothing modified. Every claim below cites a file:line read in this worktree.
- **Sources read:** all `LANE-K8*/K9*/K11*/K12*/K13*` briefs + fix rounds, `lane-k8/k9/k11/k12/k13-summary.md`, `WAVE-1-imports-enrichment-CHECKLIST.md`, K-11 §E gate conditions.

## VERDICT: spec ⚠️ (materially delivered, 3 conditions not fully met) + quality **CHANGES-REQUESTED**

Five Important findings; none is a money-direction, sign, or double-post defect. The four sign quadrants, the `7.140` passthrough rule, the default-lot opening-stock path, and the `imports.manage` / `units.manage` gates are all intact. The blockers are counter honesty (gate r2 C7), an unbounded query on a list endpoint that directly contradicts K-8-FR2, a canonicalization asymmetry between census and write, a missing error-locale entry, and rows that now fall off the result workbook entirely.

---

## Findings

### K89-R1-01 [Important] — `formatJob()` runs an unbounded per-job row load on the history LIST endpoint
`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:992` adds `'error_summary' => $this->unitErrorSummary->summarize($job)` **unconditionally**, three lines below the pre-existing `warning_summary` which is deliberately gated (`:989-991` `$withWarningSummary && $job->status->isTerminal()`). `formatJob` is mapped over the paginated list at `:93` (`per_page` up to 100). `ImportUnitErrorSummaryService::summarize()` (`apps/api/app/Modules/Import/Services/ImportUnitErrorSummaryService.php:20-22`) issues `$job->rows()->where('import_error_code','unit_unknown')->get(['data','import_error_detail'])` — no limit, and `data` is the full row JSON.
**Why it matters:** on the owner's real workload (859 `unit_unknown` rows per job) a 20-job history page materialises ~17k row JSON blobs per request. K-8-FR2 exists precisely because this path was blowing `max_execution_time`; this reintroduces the class of problem on a different endpoint. The single-job call sites (`:396`, `:469`, `:575`) are fine.
**Fix:** aggregate in SQL (`selectRaw('count(*)')->groupBy(...)`) and gate the summary to single-job responses the way `warning_summary` already is.

### K89-R1-02 [Important] — preview `valid_rows`/`invalid_rows` double-count already-invalid conflict rows (gate r2 **C7** not fully met)
`ImportController.php:368-372` computes `$barcodeConflictValidRows = min($validRowCount, barcode_identity_conflict_rows)` and `:390-391` does `valid_rows = $validRowCount - that`, `invalid_rows = $invalidRowCount + that`. But `DuplicateCensusService::isBarcodeCensusEligible()` (`DuplicateCensusService.php:514-532`) **deliberately admits rows that are invalid for `unit` only**, so `barcode_identity_conflict_rows` mixes valid and unit-invalid rows.
**Why it matters:** in a mixed-state file (one unit text mapped, another not), the subtraction removes rows from `valid_rows` that were never valid and adds them to `invalid_rows` a second time; `min()` only prevents a negative. The stated invariant "preview conflict count === execution failed rows" then fails, because execution only touches valid rows. The observed 102/757 browser number is correct only because that file was uniformly in one state.
**Fix:** count `is_valid = true AND row_number ∈ conflict groups` (the census already persists `barcode_groups.rows`, `DuplicateCensusData.php:113-124`) and pin a mixed-state test.

### K89-R1-03 [Important] — barcode canonicalization applied at the census boundary only; the write path persists the raw cell
`DuplicateCensusService::canonicalBarcode()` (`:534-...`) normalizes `123.0` / `1.23E+12` to the digit string **for grouping**, but the write path passes the raw value through (`ImportService.php:1043` `is_string($data['barcode'] ?? null) ? $data['barcode'] : null`), and both `ProductService::assertBarcodeAvailable()` and the new partial unique index (`database/migrations/tenant/2026_09_01_120000_enforce_company_scoped_product_barcodes.php:68-72`) key on the raw string.
**Why it matters:** (a) a `multi_location` group whose members carry `123` and `123.0` is merged by the census but writes two different `products.barcode` values — with blank SKUs that yields TWO products while the rows are labelled `merged_line`, silently defeating the merge the operator confirmed; (b) float-artifact strings are persisted as product identity at rest (only warned about, `ImportService.php:846-874`), inside a column that now carries a uniqueness contract.
**Fix:** canonicalize once at staging (`addRow` / `addRowsBatch`) so census, write, guard and index all observe the same identifier.

### K89-R1-04 [Important] — `invalid_number` has no `errors.*` locale entry, and no contract test exists for the error vocabulary
`ImportErrorCode::InvalidNumber` (`apps/api/app/Modules/Import/Domain/Enums/ImportErrorCode.php:28`) is the code K-13 emits for the largest refusal class on the real file (311 rows). The `errors` block of `apps/web/src/locales/{en,fr,ar}/import.json` carries every other code (`units_not_seeded` … `internal_error`, plus the new `barcode_identity_conflict`) and **none for `invalid_number`** — verified in all three locales.
It slipped because `apps/web/src/features/import/__tests__/ImportWarningLocales.test.ts:14-21` pins warning parity via `WARNING_TRANSLATION_KEYS`, and there is **no** `ERROR_TRANSLATION_KEYS` equivalent. The K-11 brief's "6-file vocabulary contract" is therefore only half enforced.
**Fix:** add the three locale entries and an `ImportErrorCode` parity test mirroring the warning one.

### K89-R1-05 [Important] — the result workbook now omits unprocessed rows entirely
`apps/api/app/Modules/Import/Services/ResultWorkbookService.php:24-34`: Imported = `outcome IN (imported, merged_line)`; Skipped = `outcome IN (duplicate_skipped, duplicate_loser)`; Rejected = `is_valid = false OR outcome IN (failed, opening_locked)`. A row left at `pending` (worker lost / job aborted mid-run — a state the codebase explicitly models, `ImportErrorCode::WorkerLost`) is `is_valid = true`, has no `import_error` and no terminal outcome, so it appears on **no sheet**.
**Why it matters:** the previous predicate over-reported such a row as Imported (correctly flagged by gate r1); this fix under-reports it to invisibility. The workbook is the operator's only per-row feedback channel for a batch import.
**Fix:** route leftovers to the Skipped sheet with a `not_processed` reason, and assert "every row appears exactly once across the three sheets".

### K89-R1-06 [Important] — regex ceilings added to columns outside every K-lane's scope, converting previously-accepted values into refusals
`apps/api/app/Modules/Import/Domain/Enums/ImportType.php` adds a scale regex to `Products.sale_price` (`:207`), `Products.purchase_price` (`:210`), `Products.tax_rate` (`:228`), `StockLevels.quantity` (`:235`), `CompositeItems.base_price` (`:251`), `CompositeItems.tax_rate` (`:255`), `CompositeItems.manual_cost` (`:256`). Only `sale_price_excl_tax` / `_incl_tax` / `quantity` / `margin` carried one before.
**Why it matters:** a 4-decimal supplier `purchase_price` or a 3-decimal `tax_rate` that imported yesterday now fails `invalid_number` unless it lands inside the ε window. This is a defensible rule-19 tightening, but it is an un-briefed behaviour change on two import types (`StockLevels`, `CompositeItems`) that no K lane owns, and `lane-k13-summary.md` does not name it.
**Not affected (verified):** `Parties.opening_balance{,_customer,_supplier}` (`:189-191`) and `OpeningBalances.debit/credit` (`:244-245`) already carried the scale-3 signed ceiling and are unchanged.
**Fix:** state the decision in `docs/modules/imports.md` + the summary, or narrow to the columns K-13 actually needed.

### K89-R1-07 [Important] — `UomController::indexUnits()` scope silently re-based onto `UnitCatalogQuery::visibleUnits()`
`apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:118-126` replaces `tenant_id IS NULL OR tenant_id = $tenantId` with `whereIn('id', $visibleUnitIds)`. `lane-k9-summary.md` asserts "K-9 does not … implement G-13 unit re-scoping", yet this changes the payload of a shared endpoint consumed by ProductForm, UnitDecimalSettings and the wizard.
**Why it matters:** if `visibleUnits()` returns `[]` for a company whose units are not provisioned, `whereIn('id', [])` returns zero units where the old predicate returned the global set — every unit dropdown and the Units settings page go empty. No test is cited for the unprovisioned-company case.
**Fix:** add that test, or fall back to the previous predicate when the visible set is empty.

### K89-R1-08 [Minor] — `buildBarcodeGroups()` defeats its own chunking
`DuplicateCensusService.php:421-459` chunks 1000 rows but accumulates every eligible row — the full Eloquent model plus its `data` array — into `$byBarcode`, so peak memory is O(all rows). Harmless at 859 rows, unbounded for the 50k-row catalogue import `addRowsBatch()` is built for. Accumulate only `row_number`, canonical barcode, location code and an identity hash.

### K89-R1-09 [Minor] — per-row census rehydration inside the execution transaction
`ImportService.php:651-655` rebuilds `DuplicateCensusData::fromStorage($job->options['duplicate_census'])` for **every** row, and `barcodeGroupForRow()` (`DuplicateCensusData.php:113-124`) falls back to a linear scan across all groups whenever the `rows` index misses. Hoist the census out of the loop.

### K89-R1-10 [Minor] — validation/execution alias asymmetry for numeric-looking unit texts
`UnitCatalogQuery::explicitMappingTargetIds()` (`:75-93`) builds the snapshot from `pluck('target_unit_id','source_text')`; PHP coerces a numeric-string array key to `int`, so the `is_string($sourceText)` filter drops it — while the live path `explicitMappingTarget()` (`:54-73`) queries `where('source_text', $sourceText)` and still finds it. A unit text like `10` would fail validation and succeed at execution — the exact preview/execution inversion K-8 exists to prevent. Cast the key back to string instead of filtering it out.

### K89-R1-11 [Minor] — hand-rolled FE union shadows a generated enum, in the same file that just fixed that class of bug
`apps/web/src/features/import/types.ts:92` correctly aliases `DuplicateBucket` to the generated union (gate r2 C10), but `:224` hand-rolls `classification: 'multi_location' | 'barcode_identity_conflict'` although `BarcodeGroupClassification` **is** generated (`packages/shared/types/generated.d.ts:1057`). Rule 22 / one-surface-per-concept. (`UnitErrorSummary` at `:55` has no generated DTO behind it, so that one is acceptable — but the backend summary should become a `Data` object so it can be generated.)

---

## Adjudication — the "known unrelated failure" claimed by `lane-k11-summary.md`

`tests/Feature/Import/ImportTypesTest.php:438` — `test_opening_balance_import_marks_missing_account_row_invalid_without_aborting` (declared at `:414`; the summary's `..._the_job` suffix is a transcription error).

**Ruling: TRULY PRE-EXISTING on base `6bec260a2`. Not introduced by this branch.** Chain, all four seams unmodified by the diff:
1. `ImportService::stageOpeningBalance()` returns the row id without touching the account, so the row terminates as `outcome = imported`.
2. `ImportService::finalizeImport()` (`:963-1000`) demotes **only** on `error: opening_locked` (`:988-998`); the missing-account path produces `error: validation_failed` (`AccountingBalancesPhase.php:440`) and never rewrites `outcome`.
3. `ImportCountersData::fromJob()` counted `outcome = imported` on the base too — `git show 6bec260a2:apps/api/app/Modules/Import/Domain/Data/ImportCountersData.php:23`. This branch's only change there is adding `MergedLine`, which is inert for this fixture.
4. Base `validateJob()` has no opening-balance extra validation (`git show 6bec260a2:…/ImportService.php`, tail of `validateJob`) — the row is valid and is executed.

**However, this is a live product defect on `dev`, not merely a red test:** a GL opening-balance import that posts nothing reports `imported_count = 1` and terminal status `Completed`, contradicting the requirement documented at `ImportTypesTest.php:409-412` ("the job's terminal LABEL is Failed because nothing posted"). Most likely regressed by the G-4 outcome refactor (`fad74078a`), which moved the counters from `is_imported` to `outcome` without teaching `finalizeImport` to demote non-posted opening-balance rows. **Open it as its own lane; do not fold it into this branch.**

---

## Rules 19 / 20 / 22 on the new services and migrations

- **Rule 19 — PASS (with K89-R1-06).** `NumericFieldNormalizer::normalizeWithReport/normalizeFloatNoise/expandDecimal` (`:39`, `:120`, `:159`) are entirely string + bcmath; no float round-trip, no `(float)` cast. `ProductPriceResolver::plainDecimal()` (`:157`) refuses exponent/`+`-signed operands with a coded exception before the first `bc*` call, closing the row-376 `ValueError`. The `7.140` passthrough rule survives: `normalizeLocaleValue` (`:97-117`) keeps the three original locale patterns and the else-passthrough. Percent columns are no longer exempted by a hardcoded regex but by `decimalScale()` (`:82-95`), which is equivalent for dot-decimal input and now also strips float noise at scale 2 — an improvement. `ProductOpeningStockPhase` keeps its injected `$scale` and `CurrencyScale::bcformatStrict(..., 4)` for quantity.
- **Rule 20 — PASS.** `validateJob()` takes the unit-resolution run from `$job->company_id` (`ImportService.php:203-205`), not from `CompanyContext` — correct for the `ProcessImportJob` queue path. `UnitResolver::forRun()` (`UnitResolver.php:26-34`) carries no static state and refuses cross-company reuse (`:39-41`). The residual `companyContext->requireCompanyId()` at the end of `validateJob` (census) is pre-existing and unchanged.
- **Rule 22 — PASS.** `products (company_id, barcode)` partial unique carries `company_id`; `unit_text_mappings` unique is `(company_id, source_text)` — both ratchet-safe, no waiver needed. Second-company coverage: `CreateProductTest::test_same_barcode_in_a_second_real_company_is_legal_and_never_leaks_the_first_company_holder`, `ProductsImportPipelineTest::test_validation_uses_each_company_visible_unit_catalog_and_is_idempotent`. Second-location + re-run: `test_confirmed_multi_location_group_under_skip_lands_each_line_once_and_reruns_idempotently`. `docs/glossary.md` updated for both barcode scopes and the alias table.
- **Opening-stock invariants — intact.** `ProductOpeningStockPhase.php:42-43` now includes `MergedLine`; the default-lot comment and the deliberate *absence* of a batch-tracked skip are preserved at `:74-77`; `OpeningAlreadyExistsException` is still swallowed as `opening_exists`, and each line posts with its own `sourceId: $row->id`, so re-run idempotency holds per location.
- **Gating — PASS.** The two new Uom routes sit inside the existing `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` group (`Uom/Presentation/routes.php:9,21-23`) and both handlers call `authorizeAbility('units.manage')` (`UomController.php:42,52`); the permission is seeded and pinned by `UomPermissionSeedingTest`. No import route lost `can:imports.manage`.
- **Tenant-migration guard — covers both new migrations for the central-table rule; idempotency only for one.** `tests/Unit/Migrations/TenantMigrationsNeverReferenceCentralTablesTest::test_tenant_migrations_never_reference_central_tables` globs `database/migrations/tenant/*.php`, so `2026_09_01_100000_create_unit_text_mappings_table.php` **and** `2026_09_01_120000_enforce_company_scoped_product_barcodes.php` are both scanned. The dedicated idempotency case (`:69-108`) covers only `unit_text_mappings`; the barcode migration's idempotency is proven separately by `ProductBarcodeUniquenessTest:51` (`…keeps oldest twin, clears losers, logs once and is idempotent`). Acceptable, but the guard test would be stronger as a loop over every tenant migration.

---

## Merge arithmetic against `k67` (branch `fix/k67-import-wizard-enrichment`, identical base `6bec260a2`)

Collision files the merger must **re-derive, not resolve textually**:

| File | What the merger must recheck |
|---|---|
| `apps/api/tests/feature-lane-manifest.json` | k89 claims Product 58→59 / `gated_ceiling` 1236→1237 (one new Feature class, `ProductBarcodeUniquenessTest`). k67 adds `ImportTimeEnrichmentTest`, `ImportCompanyPinTest`, `ApplyCatalogEnrichmentJobTest`, `CreateProductPlatformBacklinkTest`, `EnrichmentImagePersisterTest`, `ProductSubmissionCorrelationTest`, `ProductSubmissionControllerTest`. **Sum both deltas onto dev's current value**; do not accept either side's ceiling. |
| `Import/Domain/Enums/ImportWarningCode.php` | Union both case sets (k89 adds `multi_location`, `barcode_float_corruption_suspected`, `numeric_normalized`; k67 adds the enrichment codes), then re-run `typescript:transform` and re-check `warningCodes.ts` + the three `import.json` `warnings` blocks against `ImportWarningLocales.test.ts`. |
| `packages/shared/types/generated.d.ts` | **Regenerate after the merge**; never merge textually. Note k89's regen also drags in three unrelated Treasury enums (`RepositoryCensusCode`, `RepositoryLocationAttributionVerdict`, `RepositoryNormalisationAction`) that were absent from the base file. |
| `Import/Presentation/Controllers/ImportController.php` | Both sides add response fields and both touch `store`/`updateOptions` validation and `formatJob`. Re-verify K89-R1-01 does not get re-introduced on k67's list path. |
| `Import/Application/Jobs/ProcessImportJob.php`, `Product/Presentation/Controllers/ProductController.php` | Both branches edit. |
| `apps/api/tests/Feature/Import/ProductsImportPipelineTest.php` | Both add methods; union, then re-count for the manifest. |
| `ImportWizardPage.tsx`, `ImportHistoryPage.tsx`, `warningCodes.ts`, `ProductForm.tsx(+.test)`, `ImportWizardPage.options.test.tsx` | Both branches edit; `ProductForm.tsx` gets k67's platform backlink and k89's barcode-conflict prompt. |
| `apps/web/src/locales/{en,fr,ar}/{import,inventory}.json` | Six-file union; then add the missing `errors.invalid_number` (K89-R1-04). |
| `docs/glossary.md`, `docs/modules/imports.md` | Both branches append. |
| **Migration timestamp collision** | k67 `2026_09_01_100000_add_enrichment_summary_to_import_jobs.php` vs k89 `2026_09_01_100000_create_unit_text_mappings_table.php` — identical batch timestamp, ordered only by filename. No dependency today, but rename one before merging so `tenants:migrate` ordering is not accidental. |

---

## What to fix before merge

Fix K89-R1-01 (gate `error_summary` off the list endpoint and aggregate it in SQL), K89-R1-02 (count only *valid* conflict rows in the preview delta), K89-R1-03 (canonicalize the barcode at staging, not just in the census), K89-R1-04 (`errors.invalid_number` in en/fr/ar + an error-vocabulary parity test) and K89-R1-05 (no import row may disappear from the workbook) in one fix round; record K89-R1-06/07 as written decisions with tests; the `ImportTypesTest:438` red is pre-existing on `dev` and must open as its own lane, not be absorbed here.
