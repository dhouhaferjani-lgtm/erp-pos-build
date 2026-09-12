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
