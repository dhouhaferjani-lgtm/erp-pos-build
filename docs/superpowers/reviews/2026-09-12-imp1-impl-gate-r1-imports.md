# IMP-1 — imports history + error-line export — implementation gate r1 (imports seam)

- **Date:** 2026-09-12
- **Lane:** `lane/imp1-history-export`, tip `4300d9ff362f279664d8f2839dfb1b5c15b9e384`
- **Base:** `630afa86ff4e6ee7ccb7dcc86242fbe834dbcc87` (`git merge-base dev lane/imp1-history-export`)
- **dev at review time:** `1903a5de67576b7c7dbde7f9cac93bba926d4fd7`
- **Read from:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/imp1-history-export` (verified at tip; worktree left clean)
- **Reviewer:** imports-reviewer (adversarial, code-grounded). No merge, no auto-fix performed.
- **Authorities:** spec `docs/superpowers/specs/2026-08-29-imports-hardening-design.md` §4.10 (`:1273-1360`) + lane G-1 (`:2763-2790`); brief `docs/handoff/CODEX-DISPATCH-IMP-1-imports-history-and-error-export-2026-09-10.md`; handback `.worktrees/imp1-history-export/docs/handoff/HANDBACK-IMP-1-2026-09-10.md`.

## VERDICT

**ACCEPT-WITH-FIXES — 0 blockers / 5 majors / 8 minors.**

The §4.10 backend contract is genuinely built and genuinely tested; all four required test legs are green
(PG and SQLite, both classes, run by me). `FailedRowsExportService` is gone, not shadowed. The gate is not
an ACCEPT because three majors must land in a fix round before this lane is merged into `dev`:
**M1** (the lane's own browser gate asserts the defect dev just fixed and will go red on the merged tree),
**M2** (raw PHP/SQLSTATE exception text is now rendered to operators on two screens), and
**M3** (correction exports are written to disk and never deleted — they outlive job discard and the
retention purge, contrary to the spec's own retention row).

---

## Interaction with dev `477c877a3` ("Phase 0.1.6: Fix queued supplier balance currency scaling")

dev's commit changes `apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php`
(`private function scale()` → `moneyScale(string $currency)`, `max($this->scaleResolver->getScale($currency), 3)`,
call sites `:94,:157,:239-260,:353,:410,:448`) and adds
`tests/Feature/Import/PartiesImportBalancesTest.php::test_queued_tnd_supplier_balance_uses_target_company_currency_without_company_context`.
That is the parties-finalize defect IMP-1's staging confirmation exposed
(`docs/superpowers/reviews/2026-09-11-imp1-staging-job-confirmation.md`).

**Statement, explicitly:**

1. **No code conflict.** The lane touches none of dev's three files; `git diff 630afa86f...4300d9ff3 --name-only`
   contains neither `ArApOpeningService.php` nor `PartiesImportBalancesTest.php`. Declared conflicts remain
   `feature-lane-manifest.json` and `.github/workflows/ci.yml` only.
2. **`ImportStatus::PartiallyCompleted`, the terminal writer and the history counters are orthogonal to the fix.**
   The partial classification is driven purely by counters + `error_message`
   (`ImportJobClaimService.php:167-169`), not by which exception fired. After dev's fix the parties finalize no
   longer throws, so a 200/0/0 suppliers job simply lands `completed` with `error_message = NULL` — which is the
   *correct* output of the same rule. Nothing in the lane's status machinery assumes the defect.
3. **`ProcessImportJobStatusTest` expectations still hold on the merged tree — verified empirically.**
   `test_finalize_exception_preserves_imported_counters_and_marks_partial_completion`
   (`ProcessImportJobStatusTest.php:100-111`) injects a synthetic `RuntimeException` through `failed()`; it never
   depended on the currency defect. `test_parties_job_posts_ar_opening_batch_after_async_row_loop` (`:274-295`)
   posts a real AR batch and passes with and without the fix. I staged dev's two files into the lane worktree and
   re-ran on PG: `ProcessImportJobStatusTest` 7/7, `PartiesImportBalancesTest` 5/5 (tails below), then restored the
   worktree to `4300d9ff3` clean.
4. **One artefact of the lane DOES break on the merged tree — see M1.**
   `apps/web/e2e/imports/imp1-history-export.spec.ts:64-70` hard-codes the defect as expected behaviour for the
   `imp1-suppliers-balances-200.xlsx` scenario: it requires the history row to read `Completed with errors` (`:66`)
   and the row's `role="alert"` to contain the literal string `CurrencyScaleResolver` (`:68`). With `477c877a3`
   on the tree that import succeeds cleanly, so those assertions fail. The stale bullet in
   `docs/superpowers/tickets/2026-09-11-imp1-followups.md:5` ("Supplier finalization currency context … Fix the
   Document/Accounting currency propagation with its own tests") and the evidence file
   `docs/superpowers/reviews/2026-09-10-imp1-evidence/supplier-finalization.json` are likewise superseded.

---

## 1. §4.10 row-export contract

**Selection** — `ImportRowExportService.php:30-34`: `is_valid = false` **OR**
`outcome IN (failed, opening_locked)` **OR** the shared portable warning scope. The scope is extracted exactly
once, `ImportRow::scopeHasWarnings()` (`ImportRow.php:102-110`, sqlite `whereNotNull('warnings')->where('warnings','!=','[]')`
vs `jsonb_array_length(warnings) > 0`), and `ImportController::countWarningRows()` (`:1104-1107`) now delegates to it —
the export, the history counts and the detail breakdown cannot drift (G-R18 satisfied). `pending` rows are excluded.
Empty selection returns `null` (`:35-37`) → coded 404 (`ImportController.php:838`).

**Stored cell strings, no float** — `:63` `array_map(fn (string $column): string => $source->values[$column] ?? '', $columns)`.
`ImportRowSourceData::fromStorage()` yields `array<string,string>` only. No `(float)`, no `number_format`, no
`round()` anywhere in the new writer or the changed controller paths; PHPStan level 8 on the whole Import module
is clean (run below), so `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale` are green. **Rule 19 holds.**
(Nuance recorded as m8: the "stored string" is the *normalized* one — `ImportService.php:318` persists the
`NumericFieldNormalizer` output into `import_rows.data` — so `1.234,56` exports as `1234.56`. No money is
corrupted and re-import is stable, but §4.10's gloss "leaves exactly as it arrived" is not literally true and no
test pins an FR-locale cell.)

**Mapped source headers in source order** — `:41-46` walks `ImportJobOptionsData::sourceHeaders` (persisted at
upload, `ImportController.php:274`; parsed order preserved through JSONB by
`ImportJobOptionsData::fromStorage():49` `array_values(array_filter(..., is_string(...))))`), then `:47-54` unions
any column discovered across **all** rows via cursor, then `:55-58` drops every column not in the reverse mapping
(unmapped source columns absent, OQ-G-7), then `:59` reverse-maps to the operator's spelling.
Pinned by `ImportRowExportTest.php:98` (`['Produit','Référence','Prix','_status','_code','_message']`),
`:168-176` (union + `_provided` absent), `:196-206` (`Name,SKU,Sale_Price` case preserved end-to-end, which also
pins the `preserveHeaders: true` parser change, `SpreadsheetParserService.php:75,186`).

**Appended `_status`, `_code`, `_message`, error-outranks-warning** — `:60` header row, `:114-135` the rule:
an `import_error_code`, or `is_valid = false`, or `outcome IN (failed, opening_locked)` ⇒ `error` +
`validation_failed` fallback + the first message of the first field in the stored `errors` bag (`:118-120`,
`array_values(...)[0][0]`, no re-ranking); otherwise `warning` + the **first** warning in stored order (`:132-134`).
Pinned at `ImportRowExportTest.php:100-101` — the fixture row 1 deliberately carries *both* a validation error and
a warning and exports as `error` (`:238-240`), row 2 carries two warnings and exports the first (`:241-242`).
Unit codes appended for `unit_unknown|unit_ambiguous|unit_default_missing` (`:123-128`, test `:219-228`,
`UnitResolver.php:88` now populates `accepted`).

**Container** — CSV: BOM `:75`, `fputcsv($stream, $values, ',', '"', '', "\r\n")` `:77` (comma + CRLF).
Pinned `ImportRowExportTest.php:96-99` (BOM asserted, `explode("\r\n")` yielding exactly 3 lines is a real CRLF pin)
and byte-stability on regeneration `:102`. XLSX: `setCellValueExplicit(..., DataType::TYPE_STRING)` `:94`,
pinned `:105-114` (`'001'` survives as text, `'12.500'` not re-rendered).

**Verdict item 1: MET.**

## 2. One correction writer (convention 11)

`apps/api/app/Modules/Import/Services/FailedRowsExportService.php` is **deleted** (201 lines removed; the diff
shows no replacement reference). `grep -rn FailedRowsExportService apps docs` in the worktree returns only
historical documentation. `ImportController` injects `ImportRowExportService $rowExportService` (`:62`) and is the
only caller (`:776`, `:836`). `ImportRowWarningsTest.php:113-115` was amended per the spec's explicit instruction
(`assertNull` → non-null path containing `warning,price_conflict`). The result workbook stays a **distinct**
concept and is labelled so on both surfaces — `correction.fullReport` = "Full report" / "Rapport complet" /
"التقرير الكامل" (`ImportCorrectionActions.tsx:42`; `locales/en/import.json:400`), with the row export as the
primary action. `ResultWorkbookService` is untouched, which matches owner ruling 1 of the brief (full report
stays as-is; the All-rows sheet is ticketed at `tickets/2026-09-11-imp1-followups.md:7`).
**Verdict item 2: MET.** (Naming registration gap → M5.)

## 3. Re-import

- `reimport_of` accepted (`ImportController.php:139`, `uuid`), loaded by `(tenant_id, id)` (`:204`) ⇒ cross-tenant
  is a 404 (`:205-207`); **company** mismatch ⇒ 409 `IMPORT_COMPANY_MISMATCH` (`:208-210`, `companyMismatch()`);
  **entitlement** re-checked on the referenced job's type (`:211`); **type** equality enforced with a coded 422
  (`:212-214`).
- Header containment: `array_diff(array_keys($reimport->column_mapping), $parseResult['headers']) === []`
  (`:263`) ⇒ mapping pre-applied; otherwise `reimport_notice = 'reimport_headers_changed'` (`:267`) and the
  operator falls back to the normal mapping step (FE `ImportWizardPage.tsx:590-601` toasts
  `correction.headersChanged` and resumes the suggestion path). Non-blocking, as §4.10 requires.
- `mapping_not_injective` refused on **both** `store` (`:201`, via `validatedColumnMapping():1069-1089`) and
  `updateOptions` (`:567`), 422 with the colliding sources named (`assertInjectiveMapping():1092-1104`).
- **Convention 09.** Second company: `ImportRowExportTest.php:122-127` (export 409) and `:185-193`
  (re-import 409). Re-run/idempotency: `:102` (byte-identical export on regeneration) and `:155-165`
  (the round trip executed **twice**, ending `assertDatabaseCount('products', 2)` — i.e. the second run does not
  duplicate). Second *location* is not applicable to this surface (no location writes in the diff).
  Round-trip proof `:142-166` is real: upload → execute → export → `str_replace('invalid','10.250')` → re-upload
  with `reimport_of` → `assertEquals($mapping, $next->column_mapping)` and `assertSame('10.250', …data['sale_price'])`.

**Verdict item 3: MET.** Gaps: no test on the type-mismatch 422 or the entitlement arm (m3).

## 4. Routes, sync/async, authorization

- `Route::get('/imports/{id}/failed-rows.{format}')->where('format','csv|xlsx')`
  (`ImportServiceProvider.php:107`), inside the single group carrying
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']`
  (`:93-94`). Rule 12 satisfied; no new permission needed; `imports.manage` is the existing seeded gate.
- The route is **job-id based**, so async jobs are reachable — `failed_rows_csv_url` is no longer the gate
  (`ImportController.php:779` now always sets it; the FE stopped reading it and links unconditionally,
  `ImportCorrectionActions.tsx:38`). Pinned `ImportRowExportTest.php:116-121`.
- Per-request entitlement re-check on the job's type (`:833`) plus `companyMismatch` (`:828`).
  **Cross-company GET of another company's job → 409, pinned `ImportRowExportTest.php:127`** (verified green on PG).
- Empty selection → `404 {'error':{'code':'no_rows_to_fix'}}` (`:837-839`); FE maps that 404 to
  `correction.noRows` (`ImportCorrectionActions.tsx:23`). No test pins the 404 (m3).

**Verdict item 4: MET.**

## 5. `ImportStatus::PartiallyCompleted` + terminal writer

- Enum case added (`ImportStatus.php:14`) and folded into `isTerminal()` (`:25`); no magic strings in PHP —
  except `ImportRowExportService.php:134` (m2).
- **Durable write is atomic and idempotent:** the mapping lives inside the existing CAS statement
  (`ImportJobClaimService::terminalUpdate():167-169` → `:171-185`, `where('status', Importing)->update(...)`),
  so a second writer affects 0 rows and is logged `import_jobs.terminal_write_lost` (`:114`, `:138`).
  Re-running cannot re-post or re-stamp.
- **Reclassification is consistent across the three read surfaces:** detail/list payload
  (`ImportController::formatJob():1027-1032`), history status filter
  (`ImportController::index():90-93`, a parameterised SQL `CASE` so *historical* rows stored `failed`/`completed`
  filter as `partially_completed`), stored status (`ImportJobClaimService:167`). Pinned
  `ImportRowExportTest.php:208-217`: a historical `failed` job with `successful_rows = 2` reads
  `data.status = partially_completed`, appears under `?status=partially_completed` and **disappears** from
  `?status=failed`. Green on PG — the `CASE … END = ?` predicate is exercised on the real driver.
- **Ripple census:** `EnrichImportedProductsJob:65,214`; `ImportCompleted::isPartialSuccess():42-43` (and
  `isSuccess():34` / `isFailure():51` correctly leave partial out of both); `ReapStuckImportsCommand` untouched —
  it only sweeps `importing`, with `ReapStuckImportsTest.php:90,101` extended to prove a
  `partially_completed` job is never rewritten. `ImportController::execute()` cannot restart a partial job
  (`canStartImport()` = Pending|Validated only). FE: history glyph/tone/chips
  (`ImportHistoryPage.tsx:21,31,92`), wizard (14 call sites), progress store, generated types.
  `grep ImportStatus::Completed|Failed` across `apps/api/app` shows no missed reader.
- **Counters are truthful.** `terminalUpdate` writes recomputed `ImportCountersData` (`:177-180`) and
  `failed()` re-reads the job before broadcasting (`ProcessImportJob.php:372-383`), so the completion event
  carries the DB counters, not `0/total`. Handback fixtures 9/1/2 and 95/0/5 match the browser evidence
  (`…-history.txt`).

**Verdict item 5: MET** — with M4 (the same predicate written three times) and M2 (what the surfaced
`error_message` actually contains).

## 6. Rule 19 / rule 20 in the queued path

- The lane introduces **no** currency-scale resolution: `grep -n 'getScale\|CurrencyScale\|QuantityScale'` over the
  changed API files returns nothing. No bare no-arg `getScale()` is added in an import phase.
- `ProcessImportJob` gains one line (`:362`, `ImportErrorCode::InternalError` on the `failed()` hook) and makes no
  `CompanyContext` assumption; it already resolves the company explicitly from `$this->companyId`.
- **Test hole (m1):** `ProcessImportJobStatusTest.php:96` binds `CompanyContext` in `setUp()` and no test in this
  lane clears it. The parties case (`:274-295`) therefore runs the worker *with* an ambient company — precisely
  the masking rule 20 forbids, and precisely why the staging defect escaped. dev's `477c877a3` test
  (`PartiesImportBalancesTest`, `app(CompanyContext::class)->clear()` before `handle()`) is the correct pattern and
  now covers the gap on the merged tree, so this is a minor rather than a major.

## 7. Web side (light pass — a frontend gate should still run)

- All new strings via `t()` with **en/fr/ar** (`locales/{en,fr,ar}/import.json`, block `correction.*` +
  `status.partially_completed`). AR is machine-drafted per the session-G ruling; RTL checked in the browser gate
  (`imp1-history-export.spec.ts:165-166` asserts `dir="rtl"` with the AR label).
- No new `useQuery`/`useQueries` added, so `tenantScopedKey` is not implicated; `importApi.getJob` is called
  imperatively inside the upload handler (`ImportWizardPage.tsx:591`).
- No double-unwrap: `importApi.getJob` uses `apiGet` and the result is consumed directly (`:593`).
- Downloads are **blobs**: `authenticatedDownload` (`lib/api.ts:446-454`, `responseType: 'blob'`) is used for both
  formats and for the full report (`ImportCorrectionActions.tsx:21,38,43`) — no `apiGet` JSON path.
- Hand-rolled `ImportStatus` unions deleted in favour of the generated DTO (`types.ts:41`,
  `stores/importProgressStore.ts:6`) — a convention-11 improvement.
- Design tokens only; `data-testid`s preserved. `packages/shared/types/generated.d.ts` regenerated (`:1030-1032`, `:1065`).
- **M2 lives here:** `ImportHistoryPage.tsx:159` and `ImportWizardPage.tsx:1459` render `job.error_message` verbatim.

## 8. Manifest / CI

`apps/api/tests/feature-lane-manifest.json`: `Import.classes` 39 → 40, `gated_ceiling` 1254 → 1255, with a new
`imp1_note` key recording the raise and the reason. Both `ImportRowExportTest` and `ProcessImportJobStatusTest`
are named in the live `backend-test-pgsql` `--filter` allowlist in `.github/workflows/ci.yml` while the Import
feature lane is parked (precedent `ci.yml:1132-1142`). The union must be **recomputed against dev at merge**,
not textually merged — the handback says so (`HANDBACK:43`) and the declared conflict on this file confirms it.
**Verdict item 8: MET.**

---

## Empirical leg

Database: **PostgreSQL 15.15 (Homebrew) at `127.0.0.1:5432`**, private DB `autoerp_test_y` (created for this
review, owner `autoerp`) used for BOTH `DB_DATABASE` and `DB_CENTRAL_DATABASE`; `pg_stat_activity` empty before
use. SQLite legs via the default `phpunit.xml`. One PHPUnit process at a time, by path, one class per invocation,
serially. No full suite, no `--parallel`, no Playwright.

```
# PG — DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test_y DB_CENTRAL_DATABASE=autoerp_test_y
#      php artisan test -c phpunit-pgsql.xml tests/Feature/Import/ImportRowExportTest.php
  PASS  Tests\Feature\Import\ImportRowExportTest
  ✓ csv exports only errors and warnings in source layout with stable b… 4.89s
  ✓ xlsx uses same selection and preserves text cells                    0.85s
  ✓ async export route and second company isolation                      1.07s
  ✓ duplicate mapping is refused on upload and options                   0.85s
  ✓ corrected export round trip preserves mapping and is idempotent      0.94s
  ✓ headers are unioned from all rows and unmapped columns are omitted   0.75s
  ✓ reimport refuses another company and missing headers return a notic… 1.06s
  ✓ automatic mapping preserves original header spelling                 0.82s
  ✓ historical job with committed rows and finalize error reads as part… 0.84s
  ✓ unit error export includes accepted codes                            0.77s
  Tests:    10 passed (49 assertions)   Duration: 12.91s

# PG — php artisan test -c phpunit-pgsql.xml tests/Feature/Import/ProcessImportJobStatusTest.php
  PASS  Tests\Feature\Import\ProcessImportJobStatusTest
  ✓ finalize exception preserves imported counters and marks partial co… 6.51s
  ✓ partial execution failure completes instead of failing whole job     0.94s
  ✓ failed rows includes validation skipped rows                         0.93s
  ✓ zero successes marks job failed                                      0.92s
  ✓ queue persists duplicate skips separately from failures              0.94s
  ✓ async worker dispatches enrichment after it wins terminal finalizat… 1.74s
  ✓ parties job posts ar opening batch after async row loop              0.95s
  Tests:    7 passed (29 assertions)   Duration: 13.00s

# SQLite — php artisan test tests/Feature/Import/ImportRowExportTest.php
  Tests:    10 passed (49 assertions)   Duration: 10.13s   (same 10 test names, all ✓)

# SQLite — php artisan test tests/Feature/Import/ProcessImportJobStatusTest.php
  Tests:    7 passed (29 assertions)    Duration: 7.81s    (same 7 test names, all ✓)
```

**Merged-tree interaction legs** (dev's `477c877a3` files staged into the lane worktree, run on PG, then the
worktree restored to `4300d9ff3` — `git status --porcelain` empty, `git rev-parse HEAD` = `4300d9ff3`):

```
  PASS  Tests\Feature\Import\PartiesImportBalancesTest
  ✓ parties import posts ar and ap opening balance batches               5.85s
  ✓ unlocked ar batch records balance warning without blocking partner…  0.97s
  ✓ finalize import retry does not duplicate posted documents            1.22s
  ✓ queued tnd supplier balance uses target company currency without co… 1.53s
  ✓ locked opening balances make balance rows invalid at validation      1.08s
  Tests:    5 passed (54 assertions)   Duration: 10.74s

  PASS  Tests\Feature\Import\ProcessImportJobStatusTest
  Tests:    7 passed (29 assertions)   Duration: 11.97s
```

**Static analysis** (independent of the handback's claim):

```
php -d memory_limit=1G ./vendor/bin/phpstan analyse app/Modules/Import --no-progress
 [OK] No errors
```

Nothing the handback claims green came back red. The handback's own caveat stands and is accurate: §14 preflight
was completed in pieces, not as one end-to-end exit-0 run (`HANDBACK:38`).

---

## Findings

| id | sev | file:line | claim | required fix |
|---|---|---|---|---|
| **M1** | Major | `apps/web/e2e/imports/imp1-history-export.spec.ts:64,66,68-69` | The lane's browser gate pins the *defect* dev fixed in `477c877a3`: the `imp1-suppliers-balances-200.xlsx` scenario requires the history row to read `Completed with errors` and its `role="alert"` to contain the literal `CurrencyScaleResolver`. On the merged tree that import succeeds (`completed`, `error_message = NULL`), so three assertions go red and the lane's stated gate is unrunnable. `tickets/2026-09-11-imp1-followups.md:5` and `reviews/2026-09-10-imp1-evidence/supplier-finalization.json` are stale for the same reason. | Re-point the suppliers scenario to assert `Completed` + `200 imported / 0 failed` with **no** alert; keep the partial-status assertions on `imp1-partial.csv` / `imp1-partial-async.csv` (which fail on row data, not on finalize). Close the ticket bullet citing `477c877a3`; annotate the evidence file as superseded. |
| **M2** | Major | `apps/web/src/features/import/pages/ImportHistoryPage.tsx:159`; `apps/web/src/features/import/pages/ImportWizardPage.tsx:1459`; source `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:357`, `apps/api/app/Modules/Import/Services/ImportService.php:610-614` | `job.error_message` is rendered verbatim to the operator. Its content is `'Job failed: '.$exception->getMessage()` or a bare finalize `getMessage()` — i.e. PHP class names, file paths and full SQLSTATE text including key values. Gap-matrix finding 4 and §4.10's coded channel exist precisely to remove raw SQLSTATE from operator surfaces; the lane stamps `error_code` (`ImportJobClaimService:181`) but never surfaces or translates it. The handback (`:24`) and the browser gate treat "shows CurrencyScaleResolver" as the success criterion. | Render a translated sentence keyed on `import_jobs.error_code` (`import.errors.<code>`, en/fr/ar); keep the raw message server-side in `error_detail`/logs, or expose it only behind an explicit "technical details" disclosure. Add a test that the history payload's operator-facing message contains no `SQLSTATE`/class-name substring. |
| **M3** | Major | `apps/api/app/Modules/Import/Services/ImportRowExportService.php:68,84,97-99`; `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:840-847,942`; `apps/api/app/Modules/Import/Infrastructure/Commands/PurgeExpiredImportArtifactsCommand.php:53-56` | Every download writes `imports/rows/{jobId}.{csv,xlsx}` to the `local` disk and nothing ever deletes it: `downloadFailedRows` reads the bytes back and streams them without `deleteFileAfterSend`; `destroy()` deletes only `$job->file_path`; the retention purge deletes only `$job->file_path`. The file holds the operator's raw rows (partner names/codes, tax ids, balances) and therefore survives both job discard and the documented retention window. Spec §4.10's retention row (`spec:1812`) rules generated exports **ephemeral** (`deleteFileAfterSend`) "so there is nothing to purge", and the deleted `FailedRowsExportService::cleanup()` was the previous mitigation. | Stream from a temp path with `deleteFileAfterSend(true)` (or write to `sys_get_temp_dir()` and unlink in a `finally`), **or** extend `PurgeExpiredImportArtifactsCommand` and `destroy()` to delete `imports/rows/{id}.*`. Add a test that no artefact remains after the download and after job discard. |
| **M4** | Major | `apps/api/app/Modules/Import/Services/ImportJobClaimService.php:167-169`; `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:1027-1032`; `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:90-93` | The predicate `successful_rows > 0 AND (failed_rows > 0 OR error_message IS NOT NULL) ⇒ partially_completed` is implemented **three** times — once in PHP on the durable write, once in PHP on the read model, once as raw SQL in the history filter. They agree today; any future edit to one silently desynchronises the stored status, the badge and the filter chips. Convention 11 (one concept, one expression). | Extract one authority — e.g. `ImportStatus::derive(ImportCountersData, ?string $message)` plus a matching `ImportJob` query scope that renders the same expression — and have all three call it. Add a test that the filter and the detail payload agree for a stored `completed` job with `failed_rows > 0`. |
| **M5** | Major | `docs/glossary.md` (88 lines, no matching row); `docs/modules/imports.md` (901 lines, untouched by the lane) | The lane introduces operator-facing nouns and an API surface that are registered nowhere: the correction export ("rows to fix" / `failed-rows.{format}`), the "Full report" as the now-explicitly-secondary concept, the `partially_completed` / "Completed with errors" status, and the `reimport_of` field. Convention 11 requires each noun in `docs/glossary.md` under its exact name with its synonyms declared; rule 4's module doc `docs/modules/imports.md` (the doc CLAUDE.md points at for imports) documents none of it. | Add glossary rows for **Rows-to-fix export** (synonyms: correction file, failed-rows CSV, error-line export), **Result workbook / Full report**, and **Partially completed import**; add an imports.md section for the `failed-rows.{format}` route, `reimport_of`, and the status. |
| m1 | minor | `apps/api/tests/Feature/Import/ProcessImportJobStatusTest.php:96,274-295` | `setUp()` binds `CompanyContext` and no test in this lane clears it, so the queued parties path is exercised *with* an ambient company — the masking rule 20 forbids, and the reason the staging defect escaped. (dev's `477c877a3` test now covers the gap on the merged tree.) | Add `app(CompanyContext::class)->clear()` before `runJob()` in the parties case, mirroring `PartiesImportBalancesTest`. |
| m2 | minor | `apps/api/app/Modules/Import/Services/ImportRowExportService.php:134` | Warning rows emit the raw stored English `detail` as `_message` and the magic string `'internal_error'` as the `_code` fallback. §4.10 requires `_message` to be "the translated operator message for that one code"; rule 9 forbids the literal. | Use `ImportErrorCode::InternalError->value`; translate via `__('import.warnings.'.$code)` with the stored detail as the fallback. |
| m3 | minor | `apps/api/tests/Feature/Import/ImportRowExportTest.php` (absent cases) | No test pins: the coded 404 `no_rows_to_fix` (`ImportController.php:837-839`), the `opening_locked` arm of the selection (`ImportRowExportService.php:32`), `reimport_type_mismatch` (`ImportController.php:212`), or the entitlement re-check on the referenced job (`:211`). The `opening_locked` arm is the one that says "the master row WAS written; only the opening was refused" — the highest-value row in the file. | Add four short cases; the `opening_locked` one especially. |
| m4 | minor | `apps/api/app/Modules/Import/Services/ImportService.php:610-614,626`; `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:201` | The sync path now swallows `\Throwable` from `finalizeImport()`, keeps only `getMessage()`, never `report()`s (stack trace lost), and always stamps `ImportErrorCode::InternalError`, flattening a coded domain failure such as `units_not_seeded`. The async path at `:201` is **not** wrapped at all — it relies on `failed()`; the asymmetry is undocumented. | `report($exception)` before swallowing; map known exception types to their `ImportErrorCode`; document (or unify) the sync/async asymmetry in a comment. |
| m5 | minor | `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:265-274` | When no mapping is supplied the controller now derives `header => strtolower(trim(header))` and then runs the injectivity check, so a file with e.g. `Prix` and `PRIX` is refused **422 `mapping_not_injective`** naming a mapping the operator never built. Previously that file imported (last-wins). | Either exempt the auto-derived mapping from the refusal (de-duplicate with a suffix and warn), or emit a distinct code/message that names the two *source headers* as the problem. |
| m6 | minor | `apps/web/src/locales/ar/import.json:188-190` | The lane adds an AR `status` block containing only `partially_completed`; the other six status labels have no Arabic and fall back to English. Pre-existing gap, self-ticketed at `docs/superpowers/tickets/2026-09-11-imp1-followups.md:9` — recorded for completeness. | Translate the remaining six in the ticketed follow-up. |
| m7 | minor | `apps/api/app/Modules/Import/Services/ImportRowExportService.php:108-111`; `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:779` | `getDownloadUrl()` is now unconditional, so the execute response's `failed_rows_csv_url` is always non-null even when the endpoint 404s. The FE stopped reading it, but the field's contract changed silently for any other consumer, and the returned path carries no `/api/v1` prefix. | Either drop the field from the response (REALIGNMENT-LOG it) or keep it null when `generate()` would return null. |
| m8 | minor | `apps/api/app/Modules/Import/Services/ImportService.php:318`; `apps/api/app/Modules/Import/Services/ImportRowExportService.php:63` | Export cells echo the **normalized** stored string, not the operator's original spelling (`NumericFieldNormalizer` output is persisted into `import_rows.data` at validation). No float is involved and re-import is stable, so rule 19 holds — but §4.10's "leaves exactly as it arrived" is not literally true and no test pins an FR-locale cell (`1.234,56` / the `7.140` boundary) through export → re-import. | Add one round-trip case with an FR-locale money cell; correct the spec sentence or the handback wording to "the stored (normalized) string". |

### Checked and clean (no finding)

- **Opening-balance sign quadrants.** The diff touches neither `PartiesRowMapper`, `PartiesBalancesPhase`,
  `ArApOpeningService` nor `ProductOpeningStockPhase`; the AR/AP direction, the non-negative magnitude, the
  sign → `document_type` mapping and the zero → no-open-item rule are untouched by this lane. dev's `477c877a3`
  is the only change in that area and it only widens the money scale.
- **Number normalization.** `NumericFieldNormalizer` untouched; no new locale rule; the `7.140` passthrough is
  intact.
- **Opening stock / batch-tracked default lot.** Untouched; no `if (batchTracked) continue;` introduced.
- **Upsert key precedence.** Untouched (`upsertWithTypeMerge`, `findIdBySku`).
- **Gate.** Every import route, including the new `failed-rows.{format}`, is inside the one
  `can:imports.manage` group with the full middleware chain.
- **PHPStan level 8** clean on the whole Import module.

---

## What to fix before merge

Land M1 (re-point the suppliers browser scenario off the now-fixed currency defect and close the stale ticket
bullet), M2 (stop rendering raw exception text to operators) and M3 (make the correction export ephemeral or
purgeable); M4/M5 and the minors can follow in the same fix round. Then recompute the manifest union against
dev rather than textually merging `1255`.
