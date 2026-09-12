# IMP-1 — imports history + rows-to-fix export — implementation gate **round 2** (fix-round verification), imports seam

- **Date:** 2026-09-12
- **Reviewer:** imports-reviewer (adversarial, code-grounded)
- **Lane:** `lane/imp1-history-export` @ `6776ed772` — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/imp1-history-export`
- **Base of the fix round:** `4300d9ff3` · **dev tip at review:** `a041c2315`
- **Round 1 register:** `docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r1-imports.md` (ACCEPT-WITH-FIXES, 0B/5M/8m)
- **Fixer handback:** `docs/handoff/HANDBACK-IMP-1-2026-09-10.md` §"Fix round 1"
- **Scope of this round:** M1–M5 + m1/m2/m4 verification, cherry-pick hygiene, blast radius, empirical re-run.

## VERDICT

**spec ✅ (materially) + quality ACCEPT-WITH-FIXES — 0 blockers / 2 majors / 6 minors**

The fix round is honest work: every claim in the handback that I could check reproduced, all five PG classes are green on this tree, PHPStan level 8 is clean over the touched `app/` files, TypeScript is clean, and the cherry-pick is patch-id-identical to dev. Two majors remain, both of them *residuals of the very findings the round claimed to close*:

- **M2 is two-thirds closed.** The raw server text M2 exists to keep off screens still reaches a screen — through the realtime widget, not through the two pages that were fixed. The deferral reason given ("needs a broadcast-contract change") does not hold for the minimal fix.
- **M3 traded an unbounded artefact for a race.** The correction file is now deleted, but from a deterministic per-job path, unconditionally across BOTH formats, after the bytes are read — so two overlapping downloads of the same job can hand an operator a **200 OK with a zero-byte (or truncated-xlsx) correction file**. Confirmed empirically that the missing-file read returns `NULL` on this disk config.

Neither is a blocker (no wrong money, no double-post, no cross-tenant leak, no dropped row). Both should be fixed before merge; the second is cheap.

---

## Per-item status

| Item | Status | Evidence |
|---|---|---|
| **M1** browser scenario re-pointed off the fixed currency defect | **ACCEPT** | `apps/web/e2e/imports/imp1-history-export.spec.ts:68-75` asserts `Completed`, `not … 'Completed with errors'`, `row.getByRole('alert')` count **0**, `'200'`; counts row at `:64` gives `['200 imported','0 failed']`. Refreshed evidence `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/imp1-suppliers-balances-200.xlsx-history.txt:3-5` reads `Completed` / `200 imported / 0 skipped / 0 failed / 200 total` (stamped 9/12/2026 5:34 PM). Ticket bullet closed citing `477c877a3` (`docs/superpowers/tickets/2026-09-11-imp1-followups.md:4`); `supplier-finalization.SUPERSEDED.md:3` annotates the old JSON without deleting it. Residual: see **m-D**. |
| **M2** coded failure channel, never raw text | **PARTIAL → residual major M2-R** | Backend invariant holds: every `error_message` writer in the Import module also stamps an `ImportErrorCode` — `ImportJobClaimService.php:185-187`, `ProcessImportJob.php:362`, `ImportController.php:302-304` and `:336-338`, `ProcessProductImageImport.php:106-110`. `formatJob` publishes `error_code` (`ImportController.php:1073`). FE renders the coded channel on both converted surfaces (`ImportHistoryPage.tsx:160-164`, `ImportWizardPage.tsx:1370-1375` and `:1461`) via `jobErrorMessage.ts:24-34`; `errorCodes.ts:3-19` is `satisfies Record<ImportErrorCode, true>` so a new PHP case breaks `tsc` (15 enum cases, all present in en/fr/ar + `unknown`). **Third surface still raw — see M2-R.** Test pinned as asked: `ProcessImportJobStatusTest.php:100-111` (`failed()` → `partially_completed`, counters kept, `assertSame(ImportErrorCode::InternalError, $job->error_code)` at `:110`); API side `ImportRowExportTest.php:208-224`. |
| **M3** correction export is ephemeral | **ACCEPT-WITH-RESIDUAL → major M3-R** | `ImportRowExportService.php:121-135` (`deleteArtifacts`, `artifactPath`, `FORMATS`), download deletes at `ImportController.php:843-846`, discard at `:949`, purge at `PurgeExpiredImportArtifactsCommand.php:53-60`. Tests pin all three: `ImportRowExportTest.php:237-249` (gone after download, both formats), `:251-261` (gone after discard), `PurgeExpiredImportArtifactsTest.php:266-283` (expired swept, live kept). **The path is per-job and deterministic and the delete is unconditional across both formats — see M3-R.** |
| **M4** one authority for the partial-completion rule | **ACCEPT** (coverage minor **m-C**) | `app/Modules/Import/Domain/ImportJobOutcome.php:25-72`. Durable writer calls it: `ImportJobClaimService.php:168-173` inside `terminalUpdate()` (both the injected `finalize()` and the static `finalizeFromFailedHandler()` funnel through it). Read model: `ImportController.php:1035-1040`. SQL filter: `ImportController.php:94-96`. **Answer to "generated or hand-written?"** — *half generated*: the terminal `IN` list is genuinely derived from `ImportStatus::cases()` (`ImportJobOutcome.php:61-64`), so a new terminal case cannot be forgotten; the predicate itself (`successful_rows > 0 AND (failed_rows > 0 OR error_message IS NOT NULL)`, `:67`) is a **second hand-written encoding** of `isPartiallyCompleted()` (`:27`) — it cannot be derived from PHP, and living in the same class beside its twin is the best achievable. Drift is partly fenced: `ImportJobOutcomeTest.php:26-42` pins the PHP predicate over 7 cases and `:73-95` pins the SQL string; changing one alone reddens one of them. |
| **M5** glossary + module doc | **ACCEPT** | `docs/glossary.md:79-82` — four rows: **Rows to fix** (synonyms *correction file / correction export / failed-rows CSV / error-line export / lignes à corriger*, single writer named, `FailedRowsExportService` declared deleted-not-shadowed), **Full report** (explicitly secondary), **Partially completed** (synonym *Completed with errors*), **Re-import of**. `docs/modules/imports.md:838-935` — §4.10 section: terminal outcomes + the one-authority rule and the explicit "do not simplify the SQL filter to a column comparison" warning, the coded-not-raw channel with the consequence for any new failure path, the `failed-rows.{format}` route with its gate/selection/column/cell contract, and the retention rule (download / discard / purge). |
| **m1** queue reality in the parties test | **ACCEPT** | `ProcessImportJobStatusTest.php:291-295` — `app(CompanyContext::class)->clear()` before `runJob()`, with the rule-20 rationale in the comment. Class green on PG with the cherry-picked fix (below). |
| **m2** magic string + `_message` channel | **ACCEPT** | `ImportRowExportService.php:162` uses `ImportErrorCode::InternalError->value`; `:164` + `:179-185` resolve `import.warnings.<code>` with the stored per-row detail as fallback. No copy seeded — deliberate and ticketed with the constraint (`tickets/2026-09-11-imp1-followups.md:12`). I agree with the call: today's details name the actual values, and generic per-code copy in a correction file would be a regression. |
| **m4** sync finalize keeps the coded reason and reports | **ACCEPT (code) / no test — m-B** | `ImportService.php:616-627`: `report($exception)` at `:620` *before* the swallow, `CodedImportRowException` keeps `->errorCode` at `:624-626`, `InternalError` only as the fallback, and the sync/async asymmetry is documented at `:610-613`. Passed to the writer at `:638`. No test exercises either arm (see **m-B**). |
| **Cherry-pick hygiene (item 7)** | **CLEAN** | `git patch-id --stable` is **identical** for dev `477c877a3` and lane `fcc7a8c3f` (`ac8946c9714957e8ec6baabf270de260957f134e`), same author/date/subject, same 3-file / +191 −19 stat. The eventual merge deduplicates. (Note for whoever runs it: `git diff 477c877a3 fcc7a8c3f` is *not* the right check — it compares whole trees across ancestries and shows ~140 unrelated files. Use `patch-id`.) |
| **Blast radius (item 8)** | **CLEAN** | `git diff --name-only 4300d9ff3..6776ed772` = 43 paths. Outside `app/Modules/Import` / `apps/web/src/features/import` / `apps/web/e2e/imports`: **(1)** `apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php` — Document module, but it is exactly the cherry-picked dev commit (patch-id proven), zero merge risk, rate **acceptable**; **(2)** `docs/glossary.md` — shared file, append-only +4 rows in one table, rate **acceptable** (trivial conflict surface with other lanes touching the same table); **(3)** `docs/modules/imports.md`, `docs/handoff/*`, `docs/superpowers/tickets/*`, `docs/superpowers/reviews/2026-09-10-imp1-evidence/*` — lane/import-owned, rate **acceptable**. **No** CI, manifest, preflight, `packages/shared/types/generated.d.ts`, or unrelated-module production code was touched — the shared `.github/workflows/ci.yml` / `tests/feature-lane-manifest.json` union is correctly left for the integration owner. |

---

## New findings

### [MAJOR] M2-R — the raw server text still reaches a screen, on the third surface

`apps/web/src/components/organisms/GlobalImportProgress/GlobalImportProgress.tsx:103-105`

```tsx
{progress.errorMessage && (
  <p className={`mt-2 text-xs ${colorTokens.intent.danger.text} line-clamp-2`}>
    {progress.errorMessage}
  </p>
)}
```

**Reachability is end-to-end, not theoretical.** `ProcessImportJob.php:357` builds `'Job failed: '.$exception->getMessage()` → `:382` passes it to `broadcastCompleted()` → `ImportCompleted.php:71` puts it on the wire as `error_message` → `importProgressStore.ts:139-141` stores it as `errorMessage` → the widget above renders it verbatim, on **every page**, for the duration of the completion toast. That is the exact payload M2 quoted as the defect (`SQLSTATE[23505] … "products_sku_company_unique" … /var/www/app/Modules/Import/Services/ImportService.php:318`).

**Why it matters:** M2's stated invariant — and now the documented contract at `docs/modules/imports.md:866-876` ("The web never renders `error_message`") — is false as written. The doc asserts a guarantee the code does not provide, which is worse than the original defect because the next reader will trust it.

**Why the deferral does not hold:** the handback defers this as "a broadcast-contract change" (carry `error_code` on `ImportCompleted` and through the store). That is the *complete* fix. The *minimal* fix needs no contract change at all: the widget is a transient progress toast with no diagnostic role — render a single translated sentence (`t('errors.unknown')` or a dedicated `wizard.progress.failed`) when `progress.errorMessage` is truthy, and drop the raw string. One line, no payload change, and it makes the doc true today. Ship the coded broadcast in its own lane afterwards.

**Required fix:** replace the raw render at `GlobalImportProgress.tsx:103-105` with a translated generic sentence, **or** narrow the sentence in `docs/modules/imports.md:869-871` to name the two surfaces that actually honour it and cite the widget as the known exception with its ticket.

### [MAJOR] M3-R — deterministic artefact path + unconditional both-format delete = a zero-byte correction file on concurrent downloads

`apps/api/app/Modules/Import/Services/ImportRowExportService.php:132-135` (`artifactPath` = `imports/rows/{jobId}.{format}` — per **job**, not per request), `:121-130` (`deleteArtifacts` deletes **both** formats regardless of which one was requested), `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:839-853` (generate → read → delete-both → stream).

Interleave two requests for the same job (same operator clicking CSV then switching the format select to Excel and clicking again while the first is still generating — `generate()` cursors **every** row of the job at `ImportRowExportService.php:50` before writing, so the window is seconds on a large import — or simply two operators on the same failed import):

```
R1  generate(csv)   → put imports/rows/J.csv
R2  generate(xlsx)  → (Xlsx writer opens/writes imports/rows/J.xlsx)
R1  get(J.csv)      → bytes OK
R1  deleteArtifacts → deletes J.csv AND J.xlsx      ← kills R2's artefact
R2  get(J.xlsx)     → NULL
R2  streamDownload(echo null) → HTTP 200, 0 bytes, "…-rows-to-fix.xlsx"
```

`Storage::disk('local')` is configured `'throw' => false` (`apps/api/config/filesystems.php:37`), so the missing read is silent. **Confirmed empirically on this tree:**

```
$ php -r '… Storage::disk("local")->get("imports/rows/does-not-exist.csv") …'
NULL
```

The same-format interleave is worse for `xlsx`: `ImportRowExportService.php:103` has PhpSpreadsheet write **directly to the final path**, so a concurrent reader can capture a half-written zip and ship a corrupt workbook — again with a 200.

**Why it matters:** the correction file is the operator's only feedback channel for a partially failed import (the lane's own glossary row says so). An empty or corrupt one, delivered with a success status and a correct filename, reads as "there is nothing to fix" — it silently converts a partial import into an apparently clean one. This is precisely the failure class the r1 M3 finding was raised to prevent, relocated rather than removed.

**Required fix:** make the artefact per-**request**, not per-job. `artifactPath(ImportJob $job, string $format, string $token)` → `imports/rows/{jobId}.{token}.{format}` with `$token = (string) Str::uuid()`; `generate()` returns the path it wrote; the controller deletes **that path only**. Keep `deleteArtifacts()` for discard/purge but make it a prefix sweep (`Storage::disk('local')->files('imports/rows')` filtered on `str_starts_with($file, 'imports/rows/'.$job->id.'.')`) — note `PurgeExpiredImportArtifactsTest.php:273-282` and `ImportRowExportTest.php:245/248/257/261` assert exact names and must move to the prefix form. Alternatively `response()->download($abs, $name)->deleteFileAfterSend(true)` on a `tempnam()` path, which removes the read-into-memory step as well. Also guard the null read at `ImportController.php:843` — a `NULL` there must be a 404/500, never a 200 with an empty body.

---

### [MINOR] m-A — a known failure condition is stamped `InternalError`, and M2 now hides the actionable copy it used to show

`apps/api/app/Modules/Import/Application/Jobs/ProcessProductImageImport.php:89-112`: the condition is named (`$message = 'company_context_missing: Re-upload this ProductImages import so it can be processed in a company context.'`, `:90`) but stamped `ImportErrorCode::InternalError` (`:109`). Before M2 the history page rendered that message, so the operator was told what to do; after M2 they get `errors.internal_error` ("an unexpected error occurred"). Rule 9 wants the enum case. **Fix:** add `ImportErrorCode::CompanyContextMissing = 'company_context_missing'`, stamp it here, add the en/fr/ar `errors.company_context_missing` copy (the `satisfies Record<ImportErrorCode, true>` in `errorCodes.ts:19` will force the FE map). The reclassification risk is nil here (`:99` guards `whereNull('worker_started_at')`, so `successful_rows` is 0 and `ImportJobOutcome` cannot reclassify).

### [MINOR] m-B — m4 shipped without a test (rule 2)

`ImportService.php:620` (`report()`) and `:624-626` (coded code preserved) have no red→green case; the handback's red→green table lists M2/M3/M4 only. Nothing pins that a `CodedImportRowException` out of `finalizeImport()` lands its own code, nor that the exception is reported. **Fix:** one feature test on the sync path — force `finalizeImport()` to throw a `CodedImportRowException` (e.g. the opening-locked arm at `ImportService.php:1127`), assert `import_jobs.error_code` is that code (not `internal_error`), status `partially_completed` with counters intact, and `Log`/`ExceptionHandler` fake sees the report.

### [MINOR] m-C — the SQL half of the one authority is pinned in one quadrant only

`ImportJobOutcomeTest.php:73-95` asserts the SQL *string*, not its behaviour against data. The only data-level assertion is `ImportRowExportTest.php:222-223` (a stored-`failed`+committed+message job appears under `?status=partially_completed` and not under `?status=failed`) — one of the four combinations. The other quadrants (stored `completed` with `failed_rows > 0` must NOT answer `?status=completed`; a clean `failed` must NOT answer `?status=partially_completed`) are covered only by the Playwright lane (`imp1-history-export.spec.ts:155-162`), which runs on a private harness and not in CI. **Fix:** extend `ImportRowExportTest::test_historical_job_…` with the two missing rows and assert the filter returns exactly the classified set.

### [MINOR] m-D — pre-fix evidence left un-annotated beside the refreshed evidence

`docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/browser-green.txt:8-14` is a 6-passed tail from the **pre-fix** run, under the old suppliers assertions (`Completed with errors` + alert), and is untouched by the fix round (`git diff 4300d9ff3..6776ed772 --stat --` on it is empty). The root-level `…/imp1-suppliers-balances-200.xlsx-history.txt:4-5` still reads `Failed / 200 imported`. Only `supplier-finalization.json` got a SUPERSEDED sibling. The fix round's own browser tail (2 scenarios, `2 passed (28.9s)`) exists only in the handback prose, not as a committed artefact. **Fix:** commit the 2-scenario tail as `phase-b/browser-green-fixround1.txt` and add one line to `supplier-finalization.SUPERSEDED.md` naming `browser-green.txt` and the phase-a `-history.txt`/`-completion.png` for the suppliers file as pre-fix.

### [MINOR] m-E — `error_message` is still shipped to the browser

`ImportController.php:1074` publishes the raw text (exception class names, absolute server paths, full SQLSTATE incl. key values) in the job payload for every `imports.manage` operator; `types.ts:73` documents it as "never rendered". Not rendering it is not the same as not disclosing it — it is one DevTools tab away, and `ImportRowExportTest.php:220` now pins that it is published. Deliberate per the handback ("support channel"), so minor, but the doc at `docs/modules/imports.md:866-868` should say *disclosed to the client but never rendered*, or the field should be gated/truncated.

### [MINOR] m-F — the wizard's converted panels have no Vitest coverage

`ImportWizardPage.tsx:1370-1375` and `:1461` were converted with the history page, but the red→green case lives only in `ImportHistoryPage.errorMessage.test.tsx`. `ImportWizardPage.options.test.tsx:667-672` even fixtures `error_message: 'Import failed'` with `error_code: null` and asserts nothing about what is rendered. **Fix:** one wizard case — complete step with `error_code: 'internal_error'` + a SQLSTATE `error_message`, assert `errors.internal_error` and `expect(document.body.textContent).not.toContain('SQLSTATE')`.

---

## Empirical leg

PostgreSQL **native 15.15 @ 127.0.0.1:5432** (the worktree `.env` points at the lane's stopped container on 5433; credentials taken read-only from the MAIN checkout `apps/api/.env`, not stored here), `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_test_y`, config `phpunit-pgsql.xml`, **one class per invocation, serially, no full suite, no `--parallel`**.

```
PG  Tests\Unit\Import\ImportJobOutcomeTest              9 passed (16 assertions)   3.01s
PG  Tests\Feature\Import\ImportRowExportTest           12 passed (60 assertions)  27.63s
PG  Tests\Feature\Import\ProcessImportJobStatusTest     7 passed (29 assertions)  18.21s
PG  Tests\Feature\Import\PurgeExpiredImportArtifactsTest 7 passed (41 assertions) 12.52s
PG  Tests\Feature\Import\PartiesImportBalancesTest       5 passed (54 assertions) 14.70s
```

Every count matches the handback exactly. Named cases that matter:
- `✓ finalize exception preserves imported counters and marks partial completion` (M2/M4 on the `failed()` path)
- `✓ correction export never outlives the download` / `✓ discarding a job removes its correction exports` (M3)
- `✓ expired sweep also removes correction row exports` (M3 purge)
- `✓ parties job posts ar opening batch after async row loop` (m1, with `CompanyContext` cleared)
- `✓ queued tnd supplier balance uses target company currency without co…` (the cherry-picked fix, independently green here)

Static / types:

```
phpstan --level=8 (app/ files touched by the round: ImportJobOutcome, ImportRowExportService,
  ImportJobClaimService, ImportService, ImportController, PurgeExpiredImportArtifactsCommand,
  ProcessProductImageImport, ArApOpeningService)                      [OK] No errors
tsc --noEmit -p apps/web/tsconfig.json                                EXIT=0, no output
vitest ImportHistoryPage.errorMessage.test.tsx                        3 passed (1.23s), 0 zombie workers
```

Note on the handback's "phpstan … and the four test classes → [OK]": `apps/api/phpstan.neon:6-8` sets `paths: [app/]`, so **tests are outside the analysed scope**. Pointing PHPStan at the test files directly reports 20 pre-existing `argument.type` errors (e.g. `ProcessImportJobStatusTest.php:109`, `assertStringContainsString(string, string|null)`); these are **not** a CI failure and **not** a finding — recorded only so the handback line is not read as "tests are level-8 clean".

Playwright was **not** re-run by this gate (private harness, stopped PG/Redis containers, another agent holds the browser profile). M1 is verified from the committed spec + the refreshed `-history.txt` artefacts, which carry today's timestamps and the required outcome.

---

## What to fix before merge

Render a translated sentence instead of `progress.errorMessage` in `GlobalImportProgress.tsx:103-105` (or narrow the "never rendered" claim in `docs/modules/imports.md`), and make the correction-export artefact per-request (`imports/rows/{jobId}.{uuid}.{format}`) with the controller deleting only the path it wrote and treating a `NULL` read as an error, not a 200.
