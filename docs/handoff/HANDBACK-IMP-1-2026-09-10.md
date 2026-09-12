# IMP-1 handback

status: review

Branch: `lane/imp1-history-export`; worktree: `.worktrees/imp1-history-export`; base: local `dev` at `630afa86f`. Do not push or merge. The orchestrator owns imports-reviewer + frontend-conventions-reviewer and merge.

## Delivered

- One correction writer, `apps/api/app/Modules/Import/Services/ImportRowExportService.php:26`, replaces FailedRowsExportService. CSV and XLSX select failed/opening-locked and warning rows, echo stored cell strings, retain mapped source headers in source order, and append `_status`, `_code`, `_message`. CSV has BOM/comma/CRLF; XLSX cells are explicit strings. Accepted unit codes accompany unit errors. Full report remains its existing three sheets.
- Shared portable warning scope: `apps/api/app/Modules/Import/Domain/ImportRow.php:106`. Job-id export routes work for sync and async; empty selection returns coded 404. Source mappings must be injective. Re-import mapping reuse is scoped to tenant, company, entitlement and type; changed headers return a notice and require normal mapping. `ImportJobOptionsData.sourceHeaders` retains order across PostgreSQL JSONB storage. `ImportCorrectionData` generates the frontend mapping contract.
- `ImportStatus::PartiallyCompleted` and the atomic terminal writer distinguish committed rows with row/finalization errors. History and completion show job errors and truthful counters; historical terminal failures with imported rows also display partial completion. The server status filter accounts for those historical jobs; descending UUID breaks same-second history ordering ties. Enrichment, completion events, polling and the global progress card recognize the state. The reaper cannot rewrite terminal jobs. There is no separate existing import-detail route; the wizard completion is the detailed surface.
- Shared history/completion controls offer CSV/Excel, primary “Download rows to fix”, a source-column caveat, corrected-file re-upload, and secondary Full report. Next waits for saved re-import mapping retrieval, with a delayed-response regression test. New text is en/fr/ar; Arabic is machine-drafted and visually checked in RTL.

## Red → green evidence

Phase A commit: `310d86848` (fixtures, baseline evidence, browser gate). Implementation commit: `1a691eec9`. Baseline report: `docs/superpowers/reviews/2026-09-10-imp1-imports-history-export-evidence.md`. Phase B artifacts: `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/`.

| Phase A red / required regression | Phase B proof |
|---|---|
| CSV missing BOM and ruled diagnostic headers | ImportRowExportTest.php:89; downloaded `imp1-partial.csv-rows.csv` and async CSV |
| Warning-only row omitted | Same test; partial export contains 4 rows: 2 errors + 2 warnings (barcode and duplicate loser) |
| Source layout and exact string values | ImportRowExportTest.php:105, :168, :196; CSV/XLSX parity inspection |
| Completion export absent, including async | Browser gate sync/async completion actions + screenshots |
| Supplier 200 imported / 0 failed shown as Failed | ProcessImportJobStatusTest.php:100; supplier browser history/completion now partial with CurrencyScaleResolver error |
| Duplicate targets and re-import scope | ImportRowExportTest.php:130, :142, :178; second-company 409 and repeat round trip |
| Historical failure and status filter | ImportRowExportTest.php:208 |
| Company isolation, ordering, filters, FR/AR | Browser gate last scenario; locale and empty-company PNGs |

The baseline brief's “no reason columns” claim was refuted: the old CSV already had error_type/error_reason, but not the ruled contract. The full report was the apparent “same file” action. Duplicate SKU is a skip, so the partial fixture truthfully yields **9 imported / 1 skipped / 2 failed**, not 8/3. Async yields **95/0/5**. Missing-header upload is 422/failed; all-invalid upload is 201/validated with execution blocked. That existing all-invalid state is ticketed.

## Verification

- ImportRowExportTest: SQLite and PostgreSQL, 10 tests / 49 assertions each; source bytes, warning selection, XLSX, mapping refusal, historical state/filter, API round trip, company isolation.
- ProcessImportJobStatusTest: SQLite and PostgreSQL, 7 tests / 29 assertions each.
- SQLite files individually: ReapStuckImportsTest 7/25; ImportRowWarningsTest 2/11; UnitResolutionTest 12/50; SpreadsheetParserServiceTest 12/27; SpreadsheetParserDateCellTest 5/6.
- Vitest files individually: ImportWizardPage.options 19; ImportHistoryPage 1; ImportHistoryPage.counts 1; ImportWizardPage.duplicates 8. TypeScript passes. Scoped ESLint has zero errors; existing warnings remain.
- React Doctor changed-file scan against base: 90/100, one existing wizard control-flow complexity warning; no new regression. The nonblocking commit hook also printed its staged-warning notice; the independently captured scoped scan remains 90/100 with the existing complexity warning. Design-system audit: 797 acknowledged, 0 new, 0 stale; baseline shrank by 5 entries.
- Scoped preflight passed Pint, Import-module PHPStan, focused PHPUnit, generated types, TypeScript, ESLint, TanStack/design/i18n audits, manifest/liveness checks and selected Vitest. Its final POS parity step initially stopped because this worktree lacked the POS node_modules link. Restored the local dependency link and ran both POS parity files separately: 10 + 19 tests pass. The final §14.3 chokepoint gate passes after updating the changed finalize-call anchor. Thus the remaining gates were completed separately; this is not a claim of a fresh end-to-end preflight exit 0. No full PHPUnit/Vitest suite was run. The preflight's built-in detector-liveness bundle ran under its existing script defaults.
- Browser gate: six sequential Chromium scenarios on private :8012/:5176, fresh tenant and fictitious fixtures. API/worker/Vite are stopped after evidence capture.

## Manifest and CI

Import ceiling **39 → 40** for ImportRowExportTest; gated union **1254 → 1255**. Checker census: 1520 Feature classes, 74 groups, 1931 classes across suites. ImportRowExportTest and ProcessImportJobStatusTest are named in the live backend-test-pgsql allowlist while the feature lane is parked. Recompute union against current dev at merge; do not textually merge the ceiling.

## Remaining external verification and tickets

The exact PharmaBio staging job was **not queried**: the available runbook did not provide a verified staging SQL access recipe. The local suppliers-with-balances fixture reproduces the failure without fault injection; it does not prove the staging job had the identical exception. An operator with staging access should compare that job's error_message/error_code read-only.

`docs/superpowers/tickets/2026-09-11-imp1-followups.md` covers G-8 CSV conventions, the underlying supplier currency-context/finalization defect, all-invalid history semantics, optional All rows report sheet, staging confirmation, and historical source spelling after original-file purge. Successful supplier rows do not imply opening balances posted. `docs/handoff/REALIGNMENT-LOG-IMP-1-2026-09-10.md` records the API/export changes.

## Resume recipe

1. Enter `.worktrees/imp1-history-export`; retain branch and isolated dependencies. Never operate in the owner's main checkout.
2. For PostgreSQL tests, first check pg_stat_activity for `autoerp_test_z` is idle. Use local example credentials, host127.0.0.1/port5433, and set BOTH DB_DATABASE and DB_CENTRAL_DATABASE to autoerp_test_z. Run one test file at a time; RefreshDatabase resets this lane database. Stop workers before tests.
3. For browser verification, start exactly one API with `php artisan serve --port=8012`, one database worker (`--queue=imports,default --tries=1 --timeout=300 --memory=512`), and Vite with `pnpm exec vite --config vite.local.config.ts --host localhost` on5176. Run `pnpm exec playwright test --config e2e/imports/pw.config.ts` from apps/web. It registers a fresh tenant and writes phase-b evidence. Stop all three processes afterwards.
4. Re-run covering tests individually after changes. For preflight use PREFLIGHT_SCOPE=paths and the individual test-path variables; keep laptop restrictions. Keep browser traces local: they contain authentication material and are gitignored.

---

## Fix round 1

Gate register: `docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r1-imports.md` (ACCEPT-WITH-FIXES, 0 blockers / 5 majors / 8 minors). **M1–M5 and minors m1, m2, m4 are taken. m3 and m5–m8 are ticketed, not taken.**

Base `4300d9ff3` (clean tree at start). No merge of `dev`, no rebase, no push; the shared CI/manifest files are untouched and stay for the integration owner to reconcile at merge.

### Commits

| SHA | Finding group | Subject |
|---|---|---|
| `fcc7a8c3f` | step 0 | **cherry-pick** of dev `477c877a341a8f228c01489c04243befc229d3e5` — "Phase 0.1.6: Fix queued supplier balance currency scaling" (original message and authorship kept; clean, no conflicts) |
| `6ad71c46f` | **M1** | Phase 9.1.4: Re-point the suppliers browser scenario off the fixed currency defect |
| `19bc55519` | **M2** | Phase 9.1.5: Show operators a coded import failure, never the raw server text |
| `24974999f` | **M3** | Phase 9.1.6: Stop correction exports outliving the job that produced them |
| `d93785949` | **M4** | Phase 9.1.7: Express the partial-completion rule exactly once |
| `8d9537cbf` | **M5 + m1/m2/m4** | Phase 9.1.8: Register the §4.10 nouns and close gate r1 minors m1, m2 and m4 |
| (this section) | — | handback + lane realignment log + refreshed browser evidence |

### What each finding did

**M1** — `apps/web/e2e/imports/imp1-history-export.spec.ts:66-75`: the suppliers scenario asserted the defect dev had just fixed (`Completed with errors` + a `role="alert"` containing the literal `CurrencyScaleResolver`). It now asserts the truthful post-fix outcome: `Completed`, **not** `Completed with errors`, `row.getByRole('alert')` count **0**, and 200 imported / 0 failed. The partial-status assertions stay on `imp1-partial.csv` / `imp1-partial-async.csv`, which fail on row data rather than on finalize. `tickets/2026-09-11-imp1-followups.md`'s supplier-finalization bullet is closed citing `477c877a3` (the staging-confirmation item stays open); `reviews/2026-09-10-imp1-evidence/supplier-finalization.json` is annotated historical by a sibling `supplier-finalization.SUPERSEDED.md` and is **not** deleted.

**M2** — `ImportController::formatJob()` now publishes `error_code`; both operator surfaces render `apps/web/src/features/import/jobErrorMessage.ts` → translated `errors.<code>` (en/fr/ar already carry every generated code) with the existing generic `errors.unknown` for an absent or unrecognised one. `error_message` stays in the payload as the support channel and is never rendered. The wizard's execute-step panel (`ImportWizardPage.tsx:1367`) carried the same raw render as the completion panel (`:1459`) and was converted with it. Backend: `ProcessImportJob::failed()` and both upload failure paths already stamped a code; `ProcessProductImageImport`'s company-context refusal did not and now stamps `InternalError`, so a non-null `error_message` implies a non-null `error_code`. No new locale keys were needed. `packages/shared/types/generated.d.ts` is **unchanged** (`ImportJob` is a hand-rolled FE interface, not a generated DTO — pre-existing, ticket-worthy but out of scope).

**M3** — `ImportRowExportService` now owns its artefact lifecycle (`deleteArtifacts()`, one `artifactPath()`/`FORMATS` pair). The download deletes the artefact as soon as the bytes are captured — the response already streamed from memory, so it is ephemeral by construction; `destroy()` and `imports:purge-expired` delete any orphan left by a request that died between `generate()` and the stream. The purge sweeps rows artefacts **before** the source gate, so a source that cannot be deleted does not strand them.

**M4** — `App\Modules\Import\Domain\ImportJobOutcome` is the one authority: `isPartiallyCompleted()`, `effectiveStatus()` (terminal statuses only) and `effectiveStatusExpression()` (the same rule as SQL, terminal `IN` list generated from `ImportStatus::cases()`). All three former sites call it — `ImportJobClaimService::terminalUpdate()`, `ImportController::formatJob()`, `ImportController::index()`. Pure and stateless, so the static `failed()` hook path reaches it with no dependency to inject (rule 13 untouched). **The SQL filter was deliberately kept rather than switched to the stored column**: it also has to reclassify jobs written before `PartiallyCompleted` existed, which `ImportRowExportTest`'s historical-job case pins.

**M5** — `docs/glossary.md` gains four rows (Rows to fix — with *correction file / correction export / failed-rows CSV / error-line export / lignes à corriger* declared as synonyms; Full report as the explicitly secondary concept; Partially completed; Re-import of). `docs/modules/imports.md` gains a §4.10 section: terminal outcomes and the one-authority rule, the coded-not-raw failure channel and what it demands of any new failure path, the `failed-rows.{format}` route with its selection/column/cell contract, the retention rule, the full report, and the re-import round trip.

**m1** — `ProcessImportJobStatusTest` now clears `CompanyContext` before `runJob()` in the parties case (rule 20). It passes with the context cleared, which independently confirms the cherry-picked fix inside this class.
**m2** — `ImportRowExportService::diagnostic()` uses `ImportErrorCode::InternalError->value` instead of the magic string and resolves `_message` through `__('import.warnings.<code>')` with the stored per-row detail as fallback. **No copy is seeded yet, deliberately**: today's details name the actual values ("provided 12.00 vs derived 11.90"), which generic per-code copy would throw away in the one file whose purpose is telling the operator what to change on that row. Ticketed with that constraint.
**m4** — the sync finalize catch now `report()`s before swallowing and keeps a `CodedImportRowException`'s own code instead of flattening to `InternalError`; the sync/async asymmetry is stated in a comment where it lives.

### Not done (ticketed in `docs/superpowers/tickets/2026-09-11-imp1-followups.md`)

- Gate r1 minors **m3** (four missing test cases incl. the `opening_locked` arm), **m5** (auto-derived lowercase mapping 422), **m6** (six untranslated AR status labels), **m7** (`failed_rows_csv_url` unconditionally non-null), **m8** (normalized vs original cell spelling).
- **A third raw-`error_message` surface found while fixing M2:** `apps/web/src/components/organisms/GlobalImportProgress/GlobalImportProgress.tsx:103-105` renders the WebSocket `progress.errorMessage` verbatim. Fixing it means carrying `error_code` on the `ImportCompleted` broadcast payload and through `importProgressStore` — a broadcast-contract change, so it needs its own lane. Same gap makes the wizard's realtime merge fall back to the generic sentence while a job is finishing.
- **Job-level copy reuses the row-scoped `errors.*` catalogue** ("The row did not pass validation." on a job-level refusal). A wording decision, not a safety one — no raw text reaches a screen either way.
- The **manifest/CI union** is untouched: recompute against current `dev` at merge, per the original handback.
- `docs/handoff/REALIGNMENT-LOG-IMP-1-2026-09-10.md` records the two API changes (`error_code` on the job payloads; the failed-rows artefact becoming ephemeral). The **parent repo's** `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` was not touched — outside this worktree, and the integration owner holds it.

### Red → green

| Finding | RED | GREEN |
|---|---|---|
| M2 (web) | `ImportHistoryPage.errorMessage.test.tsx` — `Expected element to have text content: errors.internal_error / Received: Job failed: SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "products_sku_company_unique" … /var/www/app/Modules/Import/Services/ImportService.php:318`; second case same for `errors.unknown`. **2 failed / 1 passed** | **3 passed** |
| M2 (api) | `ImportRowExportTest::test_historical_job_…` — `Failed asserting that null is identical to 'internal_error'.` at `:219`. **1 failed (3 assertions)** | **1 passed (10 assertions)** |
| M3 (download/discard) | `ImportRowExportTest` — `Failed asserting that two arrays are identical. -Array &0 [] +Array &0 [ 0 => 'imports/rows/01a09665-….csv', 1 => 'imports/rows/01a09665-….xlsx' ]` at `:261`. **2 failed (6 assertions)** | **2 passed (8 assertions)** |
| M3 (purge) | `PurgeExpiredImportArtifactsTest` — `Found unexpected file or directory at path [...]` at `:281`. **1 failed (2 assertions)** | **1 passed (4 assertions)** |
| M4 | `ImportJobOutcomeTest` — `Error: Class "App\Modules\Import\Domain\ImportJobOutcome" not found` at `:73` / undefined method. **9 failed (0 assertions)** | **9 passed (16 assertions)** |

### Verification

PostgreSQL **native 127.0.0.1:5432**, `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_test_y`, config `phpunit-pgsql.xml`, one class per invocation, serially. No full suite, no `--parallel`.

```
PG  ImportRowExportTest                 12 passed (60 assertions)   23.05s
PG  ProcessImportJobStatusTest           7 passed (29 assertions)   10.55s
PG  PartiesImportBalancesTest            5 passed (54 assertions)    7.91s
PG  PurgeExpiredImportArtifactsTest      7 passed (41 assertions)    6.85s
PG  Unit/Import/ImportJobOutcomeTest     9 passed (16 assertions)    0.05s

SQLite  ImportRowExportTest             12 passed (60 assertions)   11.06s
SQLite  ProcessImportJobStatusTest       7 passed (29 assertions)    9.68s
```

Static, over exactly the PHP files touched in this round (`ArApOpeningService`, `ProcessProductImageImport`, `ImportJobOutcome`, `PurgeExpiredImportArtifactsCommand`, `ImportController`, `ImportJobClaimService`, `ImportRowExportService`, `ImportService`, and the four test classes):

```
phpstan (level 8)   [OK] No errors
pint --test         {"result":"pass"}
```

Generated types: `php artisan typescript:transform` → `Transformed 557 PHP types to TypeScript`; **`packages/shared/types/generated.d.ts` is unchanged** (`git status --short` empty after the run) — this round added no DTO field, only a key on a hand-assembled controller payload.

Web, from `apps/web`:

```
pnpm typecheck   clean (no output)
pnpm lint        EXIT=0 — ✖ 6407 problems (0 errors, 6407 warnings)   [warnings pre-existing]
pnpm audit:keys  Gate C: 0 without an approved tenant scope; 0 acknowledged, 0 new, 0 stale
vitest (by file) ImportHistoryPage.errorMessage 3 · ImportHistoryPage 1 · ImportHistoryPage.counts 1 ·
                 ImportWizardPage.options 19 · ImportWizardPage.duplicates 8 ·
                 ImportErrorLocales 3 · ImportWarningLocales 4  →  7 files, 39 passed
```

No `node (vitest` stragglers afterwards (`ps aux | grep -c '[n]ode (vitest'` → `0`).

### Browser gate (M1)

The lane's own harness, exactly as the Resume recipe describes: own config `apps/web/e2e/imports/pw.config.ts`, private ports API **:8012** / Vite **:5176**, one queue worker (`--queue=imports,default --tries=1 --timeout=300 --memory=512`), fresh tenant registered by `beforeAll`.

Two dependencies had to be brought up first — they were **stopped**, not missing: the lane's PostgreSQL (`autoerp_postgres`, host **5433**, DB `autoerp_test_z`, stopped 07:23 today) and its Redis (`autoerp_redis`, host **6380**; `SESSION_DRIVER=redis`). Without Redis, registration returns `500 {"error":{"code":"INTERNAL_ERROR","message":"Connection refused [tcp://127.0.0.1:6380]"}}` — the first run failed there, before any assertion. Both containers were **stopped again afterwards**, restoring the pre-run state.

Scope: the two scenarios the fix round requires, via `--grep 'imp1-partial\.csv|imp1-suppliers-balances-200\.xlsx'` — the full six-scenario run was not re-executed.

```
Running 2 tests using 1 worker
  ✓  1 … IMP1 history and rows-to-fix: imp1-partial.csv (8.8s)
  ✓  2 … IMP1 history and rows-to-fix: imp1-suppliers-balances-200.xlsx (17.4s)
  2 passed (28.9s)
```

The suppliers history row, captured live (`…/phase-b/imp1-suppliers-balances-200.xlsx-history.txt`):

```
Business partners  imp1-suppliers-balances-200.xlsx
Completed
200 imported / 0 skipped / 0 failed / 200 total
```

— `Completed`, not `Completed with errors`, and no error alert: M1's required outcome, proven on the cherry-picked tree rather than asserted. The partial row still reads `Completed with errors` with `9 imported / 1 skipped / 2 failed / 12 total`.

Refreshed evidence (committed): `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/` — for each of the two scenarios, `-completion.png`, `-completion-actions.png`, `-history-loading.png`, `-history.png`, `-history.txt`, `-full-report.xlsx`, plus `imp1-partial.csv-rows.xlsx`. `imp1-partial.csv-rows.csv` is **byte-identical** to the previous run and therefore shows no diff — an incidental re-confirmation of the export's stable bytes.

Processes stopped afterwards; `lsof -nP -iTCP:8012 -sTCP:LISTEN` and `-iTCP:5176` both return nothing, and 5433/6380 were released with the containers.

---

## Fix round 2

**Base:** `6776ed772` (clean tree at start). **Registers answered:** imports gate r2
`docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r2-imports.md` (M2-R, M3-R, m-A) and frontend gate r1
`docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r1-frontend.md` (M1–M10, m1–m7).

### Commits

| SHA | Subject | Closes |
|---|---|---|
| `cfeb6f795` | Phase 9.1.10: Close the coded-failure channel on every operator surface | M2-R, M1, M2b, M10, m1, m-A |
| `fef4ff27a` | Phase 9.1.11: Make a correction export private to the request that wrote it | M3-R |
| `d50b01a07` | Phase 9.1.12: Tell the operator the truth about a failed correction download | M3, M4 |
| `5375a03a0` | Phase 9.1.13: Let the server own the history filter and the page window | M5 |
| `248a09736` | Phase 9.1.14: Give the re-import reuse rule a single writer | M6, M7, m7 |
| `9fd4217ed` | Phase 9.1.15: One main element per screen on the history table | M8, m4 (component) |
| `76f8090d5` | Phase 9.1.16: Make the re-run scenario assert the re-run | M9, m6 |
| `da84bad5f` | Phase 9.1.17: Close gate r1 minors m2, m3 and the wizard half of m4 | m2, m3, m4 (wizard) |
| `09eb7b639` | Phase 9.1.18: Record what fix round 2 closed and what it deliberately did not | deferred register |
| `f9406becd` | Phase 9.1.19: Refresh the browser evidence for the full six-scenario run | browser gate |

### Every surface that renders a job error, and how each is sourced

The fix round 1 enumeration named two surfaces and was wrong. There are **four**, and all four now read the
coded channel through `apps/web/src/features/import/jobErrorMessage.ts`:

| Surface | Source of the code | Note |
|---|---|---|
| history row alert — `ImportHistoryPage.tsx` | `GET /imports` → `formatJob()` `error_code` + `error_detail` | was already coded; now also job-scoped copy |
| wizard execute + completion alerts — `ImportWizardPage.tsx` | `GET /imports/{id}` → `formatJob()` | was already coded; now also job-scoped copy |
| validation-step job banner — `ValidationResults.tsx` (via `ErrorViewer`) | `GET /imports/{id}/error-summary` → new `job_error_code` / `job_error_detail` | **was rendering `job_error_message` raw** (M1) |
| global progress toast — `GlobalImportProgress.tsx` | the progress store: `error_code` from the wizard's API feeder; the WebSocket `ImportCompleted` broadcast carries no code, so that feeder resolves to the generic sentence | **was rendering `progress.errorMessage` raw** (M2-R / M2b) |

`error_message` is still published on the wire for support (m-E, ticketed); `docs/modules/imports.md` now says
"disclosed but never rendered" and carries this table. The `errors` payload gained `job_error_code` /
`job_error_detail` too, for symmetry with `error-summary`.

**Broadcast contract: unchanged.** The minimal fix the imports reviewer named was taken — the widget renders a
translated sentence rather than the raw string. `ImportCompleted` was **not** restructured (rule 8); carrying
`error_code` on it is ticketed as its own lane.

### Route taken for M6/M7

**The escape hatch, deliberately, and narrowed.** The rule now has one writer: `ImportController::store()`
decides reuse-or-not and reports `reimport_notice`, which the wizard renders as `correction.headersChanged`;
the frontend's own `toast.info` for that rule is gone, and the frontend no longer re-derives the rule.

What survives on the client is a *presentation* question the server cannot answer before the upload exists —
"can the operator skip the mapping step?" — because the mapping step is what creates the job
(`handleMappingComplete` → `createImport`), so a server-side "you must map again" verdict after creation has
no step to route back to without a second job. That pre-check now (a) has its own try/catch, (b) reports
`correction.originalUnavailable` and keeps the operator's file and selection on failure, and (c) also requires
the saved mapping to cover the required targets, through a helper shared with the mapping step's own validity
gate. M9's browser assertion ("the mapping step is skipped on attempt 1") depends on that skip existing.

**m7 server half: verified, not changed.** `ImportRowExportTest::test_a_reused_mapping_that_misses_a_required_target_is_refused_with_the_column_list`
proves a re-applied mapping still goes through header validation and is refused 422 `validation_failed` with
`errors.missing_columns` — so the pre-check's new condition keeps the operator out of a refusal they could not act on.

### Generated types

`packages/shared/types/generated.d.ts` **is** in the commit (`cfeb6f795`), because a DTO field and an enum case
were added: `ImportErrorDetailData.missing_columns` and `ImportErrorCode::CompanyContextMissing`. The
regeneration is exactly two lines; `errorCodes.ts` is `satisfies Record<ImportErrorCode, true>`, so the new case
forced the frontend map and the en/fr/ar copy.

### RED → GREEN

Backend (PG native `127.0.0.1:5432`, `autoerp_test_y`, `phpunit-pgsql.xml`, one class per invocation):

```
RED  ImportRowExportTest::test_job_level_failure_publishes_its_code_and_detail_on_every_payload
     Failed asserting that null is identical to Array &0 [ 0 => 'name' ]   (Tests: 1, Assertions: 3, Failures: 1)
RED  PartiesImportTypeTest::test_legacy_product_images_job_fails_…
     Error: Undefined constant App\Modules\Import\Domain\Enums\ImportErrorCode::CompanyContextMissing
RED  ImportRowExportTest::test_a_download_deletes_only_the_artefact_it_generated
     Unable to find a file or directory at path [imports/rows/01a09693-….xlsx].   (Tests: 3, Assertions: 21, Failures: 1)

GREEN  ImportRowExportTest                    16 passed (100 assertions)  19.7s
GREEN  ProcessImportJobStatusTest              7 passed  (29 assertions)  13.5s
GREEN  PurgeExpiredImportArtifactsTest         7 passed  (42 assertions)   9.9s
GREEN  ImportJobOutcomeTest                    9 passed  (16 assertions)   0.03s
GREEN  PartiesImportTypeTest                   7 passed  (38 assertions)  10.8s
GREEN  PartiesImportBalancesTest               5 passed  (54 assertions)  11.2s

SQLite (default phpunit.xml):
GREEN  ImportRowExportTest                    16 passed (100 assertions)  13.9s
GREEN  PurgeExpiredImportArtifactsTest         7 passed  (42 assertions)   5.7s
```

Frontend (Vitest, default pool, one file per invocation; `ps aux | grep 'node (vitest'` → 0 afterwards):

```
RED  ImportHistoryPage.errorMessage.test.tsx        5 failed | 3 passed (8)
RED  ImportCorrectionActions.test.tsx               5 failed | 2 passed (7)
RED  ImportHistoryPage.actions.test.tsx (M4)        2 failed | 1 passed (3)
RED  importApi.envelope + actions (M5)              3 failed | 7 passed (10)
RED  ImportWizardPage.options.test.tsx (M6/M7)      3 failed | 19 passed (22)
RED  ImportCorrectionActions + actions (M8)         2 failed | 13 passed (15)

GREEN  ImportErrorLocales.test.ts                3 passed
GREEN  ImportHistoryPage.errorMessage.test.tsx    8 passed
GREEN  ImportCorrectionActions.test.tsx           9 passed
GREEN  ImportHistoryPage.actions.test.tsx         6 passed
GREEN  ImportWizardPage.options.test.tsx         22 passed
GREEN  importApi.envelope.test.ts                 5 passed
GREEN  tenantScope.test.tsx                      16 passed
```

### Static

```
phpstan --level=8  (ProcessProductImageImport, ImportErrorDetailData, ImportErrorCode,
                    ImportController, ImportRowExportService)                    [OK] No errors
pint --test        (those five + the three touched test classes)                 {"result":"pass"}
pnpm typecheck                                                                   exit 0, no output
pnpm lint                                                                        ✖ 6416 problems (0 errors, 6416 warnings)
pnpm audit:keys    Gate C: 0 — 0 acknowledged, 0 new, 0 stale
audit:design-system  797 violations — 797 acknowledged, 0 NEW, 0 stale
audit:i18n:local   OK — 2810 known gaps (was 2816: the six ar `status.*` entries m2 authored; removal-only)
```

Two notes on the figures. **Warnings 6407 → 6416**: nine new *warnings* (no-unsafe-type-assertion on the
error-body cast, `??` on payload fields typed non-nullable, template-expression nits) — the gate is 0 errors,
which holds. **Design-system 0 new**: M4/M8 delete JSX and the baseline would have gone stale on the history
status chip, whose raw-`<button>` source the baseline keys on — the page-reset now goes through one
`setStatusFilter` wrapper so that JSX is byte-identical and the baseline neither grows nor goes stale.

### Playwright — FULL six-scenario run, all green

Lane harness `apps/web/e2e/imports/pw.config.ts`: API :8012, Vite :5176, one queue worker
(`--queue=imports,default --tries=1 --timeout=300 --memory=512`), containers `autoerp_postgres` (5433) and
`autoerp_redis` (6380) started for the run, fresh tenant registered by `beforeAll`.

```
Running 6 tests using 1 worker
  ✓  1 IMP1 history and rows-to-fix: imp1-success.csv                 (8.9s)
  ✓  2 IMP1 history and rows-to-fix: imp1-partial.csv                 (8.5s)
  ✓  3 IMP1 history and rows-to-fix: imp1-partial-async.csv          (13.0s)
  ✓  4 IMP1 history and rows-to-fix: imp1-suppliers-balances-200.xlsx (15.4s)
  ✓  5 corrected export reuses mapping and can be re-run             (14.7s)
  ✓  6 history filters and second-company isolation                  (15.8s)
  6 passed (1.3m)
```

The first attempt of the run **failed scenario 1**, and the failure was real information: the spec waited
unconditionally for "Download rows to fix" on the completion step, which M4 now hides for a clean import. The
scenario asserts both directions since `f9406becd` (present for the partial fixtures, `toHaveCount(0)` for the
clean ones) — that is the assertion M4 is worth.

M9's point, captured live in
`docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/corrected-attempt-{1,2}-history.txt` — two distinct
jobs (different `reimport_of` hrefs), identical counters:

```
/settings/import/products?reimport_of=01a096b1-4ef9-70eb-b244-58fa558f3a74
Products  imp1-corrected.csv  Completed  4 imported / 0 skipped / 0 failed / 4 total
/settings/import/products?reimport_of=01a096b1-6344-718d-8b8f-94cb5c31d4e7
Products  imp1-corrected.csv  Completed  4 imported / 0 skipped / 0 failed / 4 total
```

Evidence committed: `…/phase-b/browser-green-fixround2.txt` (the tail above, with the harness line), refreshed
per-scenario `-completion.png` / `-completion-actions.png` / `-history*.png` / `-history.txt` /
`-full-report.xlsx` / `-rows.{csv,xlsx}`, `history-fr.png`, `history-ar-rtl.png`, `second-company-empty.png`,
`corrected-attempt-{1,2}.png`, and `reimport-rows-to-fix.csv` (the re-run scenario's own input — m6's
cross-test coupling is gone).

Processes stopped afterwards; `lsof -nP -iTCP:8012 -sTCP:LISTEN`, `:5176`, `:5433` and `:6380` all return
nothing, and both containers are `Exited (0)` again.

### Not done — see `docs/superpowers/tickets/2026-09-11-imp1-followups.md`

frontend **m5** (no `ImportJobData` DTO) and imports **m-B**, **m-C**, **m-D**, **m-E**, **m-F**, as instructed.
Two items the round itself opened are registered there as well: the `ImportCompleted` broadcast still carrying
no `error_code` (so the WebSocket feeder resolves to the generic sentence), and the new missing-artefact
`404 correction_export_unavailable` guard having **no automated test** — `ImportRowExportService` is `final`
with no interface, so nothing can make `generate()` return a path that is then removed; the guard is code-only
until a seam exists.
