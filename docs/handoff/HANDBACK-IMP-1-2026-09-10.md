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
