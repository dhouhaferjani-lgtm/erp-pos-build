# IMP-1 Phase A evidence — 2026-09-10

Base: `630afa86f`, isolated `lane/imp1-history-export` worktree. API :8012, Vite :5176, PostgreSQL :5433 / autoerp_test_z (idle checked before creation), one database queue worker. Fixtures contain invented entities only. No staging writes, push or merge.

## Captured baseline

Artifacts are in `2026-09-10-imp1-evidence/` beside this document. The browser spec uses actual wizard uploads and authenticated UI download buttons.

| Assertion | Result | Artifact |
|---|---|---|
| Success file completes; history and full report download | PASS | imp1-success.csv-history.txt, matching PNG and full-report.xlsx |
| Partial file counters | PASS: 9 imported / 1 skipped / 2 failed | imp1-partial.csv-history.txt |
| Async final counters | PASS: 95 imported / 0 skipped / 5 failed | imp1-partial-async.csv-history.txt |
| Missing required name header refused at upload | PASS: HTTP 422 | imp1-hard-fail.csv-upload.json |
| All-invalid file is accepted for validation | PASS: HTTP 201, validated, 3 rejected; not a terminal failure | imp1-all-invalid.csv-upload.json |
| Correction CSV has UTF-8 BOM | RED: both start with row_number, no BOM | artifact-inspection.json; *-rows.csv |
| Correction CSV has _status/_code/_message | RED: both instead have error_type/error_reason | artifact-inspection.json; *-rows.csv |
| Warning-only row included | RED: IMP1-WARNING absent, partial export has only 2 rows | imp1-partial.csv-rows.csv |
| Completion offers Download rows to fix | RED: absent on sync and async completion | *-completion.png; phase-a-browser.txt |
| Async CSV reachable from history | PASS: downloaded 5 rejected rows | imp1-partial-async.csv-rows.csv |
| Full report is separate Imported / Skipped / Rejected workbook | PASS | *-full-report.xlsx; artifact-inspection.json |
| Supplier finalize failure has truthful status | RED: status failed with 200 imported / 0 skipped / 0 failed | supplier-finalization.json |

The supplier failure is reproduced without fault injection: the queued finalization calls CurrencyScaleResolver without currency or CompanyContext. The raw job error is recorded in supplier-finalization.json. The reported staging record has not been queried: the cited staging runbook provides no host/access recipe and the available SSH host exposes a development checkout, not a verified staging release. Local reproduction establishes this failure path independently, not the identity of the staging cause.

The owner's “same file” symptom is explained by the full-report primary action: it contains all row outcomes in three sheets. The existing correction CSV is already a subset with error_type/error_reason, contradicting the dispatch's claim that it has no reason columns. It still lacks the ruled columns, source layout and warning rows. Duplicate SKU within this fixture is a skipped duplicate, not a third invalid row; the expected count is corrected to 9/1/2.

Initial harness attempts used `pcs` instead of the installed `pc` unit, missed the partial-import confirmation dialog, or encountered a stopped Vite server. These are harness/setup failures, excluded from product RED claims. Browser traces stay local because they contain authentication material.

Remaining evidence coverage to complete alongside implementation: second-company empty history, status-filter assertions, XLSX row-export parity, corrected-file round trip, and regression of the supplier status. No green claim is made for these yet.


## Phase B verification — 2026-09-11

All Phase A product REDs are green. Artifacts below are under `2026-09-10-imp1-evidence/phase-b/`; the baseline files remain unchanged.

| Assertion | Result | Proof |
|---|---|---|
| CSV BOM and ruled headers | PASS | Both `*-rows.csv`; `artifact-inspection.json` |
| Warnings included with error precedence | PASS | Partial export: 4 rows, 2 errors + 2 warnings. The duplicate loser also carries a warning; barcode warning remains included. |
| CSV and Excel same cells / strings | PASS | Both `*-rows.xlsx` match corresponding CSV cells exactly; explicit string cell types |
| Full report stays separate | PASS | Imported/Skipped/Rejected remain 9/1/2, 95/0/5, 200/0/0 for partial/async/suppliers |
| Sync and async completion export controls | PASS | `*-completion-actions.png`; both formats downloaded from history |
| Supplier status anomaly | PASS locally | `imp1-suppliers-balances-200.xlsx-history.txt`: Completed with errors, 200/0/0, actual CurrencyScaleResolver job error visible |
| Corrected export re-upload and re-run | PASS | Browser corrected-file scenario bypasses mapping and completes twice; `corrected-attempt-*.png`; backend round-trip asserts idempotent writes |
| Ordering and status filters | PASS | Browser asserts newest first and Completed / Completed with errors / Failed selections |
| Second company | PASS | Browser/API empty history and `second-company-empty.png`; backend denies cross-company export and re-import |
| French and Arabic controls | PASS | `history-fr.png`, `history-ar-rtl.png`; new text visible, RTL document direction asserted |
| Missing header / all invalid | PASS, unchanged semantics | `imp1-hard-fail.csv-upload.json` 422/failed; `imp1-all-invalid.csv-upload.json` 201/validated |

Focused correction tests pass on SQLite and PostgreSQL: 10 tests / 49 assertions each. Status tests pass on both: 7 / 29. Reaper regression includes partially_completed among protected terminal states. Review details, other gates, manifest numbers and resume commands are in `docs/handoff/HANDBACK-IMP-1-2026-09-10.md`. Staging identity confirmation remains explicitly outstanding there.

Visual inspection also corrected the partial/failed completion icon and subtitle: they no longer claim unconditional success. Existing untranslated Arabic history labels and the wide horizontally scrolling table predate this lane; new correction controls and partial-state text are translated and visible in the captured RTL view.

The final browser pass exposed and pinned a re-import readiness race: Next is now disabled until saved mapping retrieval completes (a delayed-response Vitest regression covers it). Same-second history rows now use descending UUID as a deterministic secondary order.
