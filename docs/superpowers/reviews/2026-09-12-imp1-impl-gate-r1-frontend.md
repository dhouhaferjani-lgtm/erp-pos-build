# IMP-1 — imports history + error-line export · implementation gate r1 · `apps/web` frontend conventions

| Field | Value |
|---|---|
| Reviewer | Claude Opus 5 (1M) — adversarial frontend-conventions gate |
| Date | 2026-09-12 |
| Lane | `lane/imp1-history-export` @ `6776ed772` |
| Base | `git merge-base dev lane/imp1-history-export` = `630afa86f` (confirmed) |
| Scope | `git diff 630afa86f...6776ed772 -- apps/web/` (19 files, +584/−148). Cherry-picked dev commit `477c877a3` touches **no** `apps/web/` file (verified `git show --stat`) — excluded as instructed. |
| Worktree read | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/imp1-history-export` (`git rev-parse HEAD` = `6776ed772`, clean) |
| Authorities | spec `docs/superpowers/specs/2026-08-29-imports-hardening-design.md` §4.10 · brief `docs/handoff/CODEX-DISPATCH-IMP-1-...-2026-09-10.md` · gate r1 imports register `docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r1-imports.md` (M2) · handback `docs/handoff/HANDBACK-IMP-1-2026-09-10.md` §"Fix round 1" · conventions 01/05/06/11 · CLAUDE.md rules 11/14/18/19 · `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` (OQ-11, one-main-element) |

## Verdict

**CHANGES-REQUIRED** — **0 BLOCKER · 10 MAJOR · 7 minor**

The mechanical leg is genuinely clean: typecheck 0, lint 0 errors / 6407 warnings (exactly the handback's figure), `audit:keys` 0, design-system 797 acknowledged / **0 new / 0 stale**, quantity 0, i18n OK, every touched Vitest file green, no vitest stragglers. The baseline shrink is honest (§7). The glossary/§4.10 noun registration (commit `8d9537cbf`) is real and thorough.

What blocks acceptance is not the plumbing, it is **truthfulness of the operator surfaces the lane built**. M2 (raw server text reaching operators) is declared closed but a **third, unenumerated surface still renders it** (`ValidationResults.tsx:67-72`), and the wizard itself **actively feeds** the raw text into the one surface the handback admits is still raw (`ImportWizardPage.tsx:493`) — that half is pure FE and was not blocked by any broadcast contract. Around that, five operator-facing behaviours are untruthful or dead: a 404 handler that says "no rows to fix" for *any* 404 on *either* download, a "Download rows to fix" primary shipped on imports that have nothing to fix, a status filter that filters 20 client-side rows while the lane's own backend gained a server-side filter, an unrelated `getJob` failure blamed on the operator's file, and a re-import mapping rule written twice with the server's notice dropped.

---

## Item-by-item

### 1. History page + wizard completion step — primary/secondary actions, caveat, re-upload, `reimport_of`, en/fr/ar

**Largely met.** `apps/web/src/features/import/components/ImportCorrectionActions.tsx:27-46` is the single component mounted on both surfaces — history row (`ImportHistoryPage.tsx:224`) and wizard completion (`ImportWizardPage.tsx:1531`). Primary "Download rows to fix" (`:35`, filled), CSV/Excel picker (`:31-34`, `Select` atom, `useId` + `sr-only` label), source-column caveat (`:40`), corrected-file re-upload `Link` carrying `?reimport_of=` (`:42`), secondary ghost "Full report" (`:43`). Glossary rows 79-81 of `docs/glossary.md` declare exactly this primary/secondary split.

`reimport_of` is carried end to end: read at `ImportWizardPage.tsx:276`, applied at `:593-605`, forwarded to the mutation at `:665` → `queries.ts:107-114` → `importApi.ts:37-44` (`formData.append('reimport_of', …)`), accepted at `ImportController.php:142,208`.

Copy: **all `t()`**, no hardcoded strings in the new/changed JSX. `correction.*` (11 keys) + `status.partially_completed` land in **en, fr and ar** — verified by parsing all three JSON files; **no duplicate JSON keys** in any locale (ar's new `"status"` object is the file's first, so nothing is shadowed). `pnpm audit:i18n:local` passes, and the i18n ratchet fails closed on new gaps, so no new key is English-fallback.

Gap: the completion H2 now renders `status.failed` (`ImportWizardPage.tsx:1454`), and `ar|import|missing|status.failed` is a **pre-existing baselined gap** (`tools/i18n-completeness-baseline.json`) — see m2.

### 2. Download handling — blob through the shared client, filename, coded 404

Blob path is correct: `ImportCorrectionActions.tsx:21` → `lib/api.ts:446-454` `authenticatedDownload` uses `api.get(url, { responseType: 'blob' })`, i.e. the raw axios client, **not** `apiGet` (no double-unwrap, convention 01 respected — `importApi.list` at `importApi.ts:24-28` keeps its documented `api.get` carve-out for `{data,meta}`). Filenames are truthful defaults (`import-<id>-rows-to-fix.<fmt>`, `import-<id>-result.xlsx`); the backend sends a better one (`ImportController.php:847`) but `authenticatedDownload` ignores `Content-Disposition` — acceptable, not a finding.

**Error handling is status-based, not code-based** → **M3**. `ImportCorrectionActions.tsx:23` maps *every* 404 to `correction.noRows`, but `downloadFailedRows` returns 404 for **two** codes — `import_not_found` (`ImportController.php:827`) and `no_rows_to_fix` (`:841`) — and the **same handler is used for the Full-report button** (`:43`), whose endpoint 404s only with `import_not_found` (`ImportController.php:986`). A purged/foreign job, or a missing result workbook, tells the operator "There are no rows to fix for this import." The `responseType: 'blob'` makes `error.response.data` a `Blob`, so the code is only reachable via `await blob.text()` — that is the required fix, not a status heuristic.

### 3. M2 — coded message, never `error_message`

**The two named surfaces are fixed.** `ImportHistoryPage.tsx:161-163` and `ImportWizardPage.tsx:392,1370-1372,1461` both route through `importJobErrorMessage()` (`jobErrorMessage.ts:24-34`), which reads `error_code` only, guards it against the generated `ImportErrorCode` union via `ERROR_TRANSLATION_KEYS` (`errorCodes.ts`), and degrades to `errors.unknown`. `error_message` is never read for display in either file. The backend hands both channels over (`ImportController.php:1073-1074`).

**The pinning test is real and meaningful** — `__tests__/ImportHistoryPage.errorMessage.test.tsx:19-20` uses a genuine SQLSTATE-23505 string with a constraint name, key values, a connection name, an absolute PHP path and a line number; `:62-65` assert the translated key renders *and* `document.body.textContent` contains no `SQLSTATE`; `:68-79` pin both degradation paths (null code, unknown code) including "no `boom`". 3/3 green. Not a class-name assertion — it asserts rendered text meaning.

**Enumeration of remaining raw renders** (`grep -rn "error_message\|errorMessage" src/ --include='*.tsx' --include='*.ts'`, production code only) — the handback names **one**; there are **two**:

| Surface | Rating | Note |
|---|---|---|
| `GlobalImportProgress.tsx:103-105` (`progress.errorMessage`) | **MAJOR (M2b)** — half of it is *not* contract-blocked | Two feeders. (a) the WebSocket `ImportCompletedBroadcast` — genuinely needs a broadcast-contract change, as the handback says. (b) **`ImportWizardPage.tsx:493` forwards `apiJobData.error_message` verbatim into `completeImport()`** → `importProgressStore.ts:139-141` → rendered raw. (b) is pure frontend, under this lane's control, and was not taken — so the wizard shows the operator a translated code in its completion alert while its *own* global toast card shows the raw SQLSTATE for the same job. |
| `ValidationResults.tsx:67-72` (`summary.job_error_message`) | **MAJOR (M1) — unenumerated** | `job_error_message` is literally `$job->error_message` (`ImportController.php:524` and `:631`). Reachable: `ImportController.php:336` writes `'Failed to parse file: '.$e->getMessage()` at job level. So the wizard's validation step renders exactly the class-names/paths payload M2 forbids, on a surface the handback's "what was deliberately left" list does not mention. |

Also **M10**: the coded catalogue the job level now borrows is **row-scoped copy**. `errors.validation_failed` = *"The row did not pass validation."* is emitted for a **job-level** failure whose real cause is `'Missing required columns: '.implode(', ', …)` (`ImportController.php:302-304`); `errors.internal_error` = *"The row could not be imported…"* is emitted for a job-level parse failure (`:335-338`). The operator loses the one piece of genuinely actionable, non-sensitive information (which columns are missing) and is told "row" for a whole-file failure. The `errors.missing_columns` array the API already returns is never rendered.

### 4. Convention 11 — hand-rolled FE type beside a generated DTO

**No generated import-job DTO exists.** `packages/shared/types/generated.d.ts:1029-1057` declares only `ImportCorrectionData`, `ImportErrorDetailData`, `UnitCandidateData` under `App.Modules.Import.Domain.Data`; there is no `ImportJobData`. The backend assembles the payload as an inline array in `ImportController.php:1032-1078`, so there is nothing to transform. **Not a duplicate-of-a-DTO finding.**

The lane moves in the right direction: `types.ts:41` and `stores/importProgressStore.ts:6` replace two hand-rolled `ImportStatus` unions with `App.Modules.Import.Domain.Enums.ImportStatus`; `types.ts:70-72` types `error_code` from the generated `ImportErrorCode`; `types.ts:52` extends `Partial<ImportCorrectionData>`; `generated.d.ts` was regenerated in-lane. **Risk of the remaining ~25 hand-rolled fields: MINOR** (m5) — drift between `formatJob()` and the interface is caught only by tests, and `Partial<>` makes `column_mapping` optional where the backend always emits it.

### 5. TanStack + the delayed-response regression test

`pnpm audit:keys` → `Gate C … : 0` new, 0 stale. The lane adds **no** `useQuery`/`useQueries`; `useImportJobs` (`api/queries.ts:31-35`) keeps `tenantScopedKey([...importKeys.lists()])`. The only changed hook is the `useCreateImport` **mutation** (`queries.ts:103-118`), which needs no key. `importApi.getJob(reimportOf)` is called imperatively from `handleFileSelect` — outside the cache, legitimately.

**The delayed-response test is real**: `__tests__/ImportWizardPage.options.test.tsx:154-163`. It hands `getJob` a promise it controls, asserts `mockGetJob` was called with `'original-job'`, asserts **Next is disabled while unresolved**, resolves inside `act`, asserts Next is **enabled**, clicks it, and asserts the **mapping step is skipped** (`queryByRole('button', {name:'apply-mapping'})` absent). That pins the actual regression (`setSourceColumns([])` at `ImportWizardPage.tsx:585` + the early `return` at `:604`) rather than a render snapshot. 19/19 green.

### 6. Status semantics — `partially_completed` / "Completed with errors"

Rendered consistently: history glyph + tone (`ImportHistoryPage.tsx:22,32`), filter chip (`:93`), counters gate (`:167`), actions gate (`:223`); wizard merge/transition/execute/complete (`ImportWizardPage.tsx:373,438,462,492,502,516,791,1349,1358,1432,1450,1454`); global card (`GlobalImportProgress.tsx:16-19,77`). The status is derived **once** server-side (`ImportJobOutcome::effectiveStatus`, `ImportController.php:1034-1039`) — no FE re-derivation. en/fr/ar copy present. `ImportWizardPage.tsx:394` correctly re-prioritises `jobData.failed_rows` over `importResults.execution_error_count` so counters stay truthful after finalize demotion; `options.test.tsx:201` is now `it.each(['failed','partially_completed'])`.

**The server status filter option is present on the backend and unused by the frontend** → **M5**. `ImportController.php:78,94-95` validates `status` and translates it through `ImportJobOutcome::effectiveStatusExpression` (so legacy `completed`/`failed` rows classify correctly in SQL). The frontend never sends it: `importApi.list()` (`importApi.ts:24-28`) takes no arguments and passes no query string, and `ImportHistoryPage.tsx:41,44-48` filters `jobs.data` **client-side** over the server's default `per_page = 20` first page, with **no pagination UI anywhere on the page**. "Completed with errors" can therefore read as empty while such jobs exist. The Playwright filter scenario (`imp1-history-export.spec.ts:155-162`) passes identically under either implementation, so it does not cover this.

### 7. Rule 18 + design-system audit

No new hardcoded Tailwind colours: every colour in the diff is `semanticColorTokens` / `textColors`; `colorClasses` is not touched; `ImportHistoryPage.tsx:239` even migrates a leftover `textColors.disabled` → `colorTokens.text.disabled`. No interpolated variant prefixes or opacity modifiers on tokens (`no-dead-tailwind-token-interpolation` RuleTester: 5 valid / 5 invalid, green).

**Baseline honesty — replayed entry-level. Clean.** `tools/audit-design-system-baseline.json` loses exactly 5 C3 entries and gains none:

| Removed entry | Justification at `6776ed772` |
|---|---|
| `ImportHistoryPage` `downloadFailedRowsUrl` button | code deleted (old `:220-249` → `ImportCorrectionActions`) |
| `ImportHistoryPage` `downloadResultWorkbookUrl` button | code deleted |
| `ImportWizardPage` `import-complete-download-rows_export_csv` button | code deleted (old `:1498-1523`) |
| `ImportWizardPage` `import-complete-download-workbook` button | code deleted |
| `ImportWizardPage` `import-wizard-next` (upload step) | raw `<button>` → `Button` atom (`ImportWizardPage.tsx:891-905`) — the remediation C3 exists to force |

The audit's own output corroborates: `797 acknowledged, 0 new, **0 stale baseline entries**` — a fabricated removal would have surfaced as stale. **No `--write-baseline` absorption, no alias/suppression indirection** (grepped the diff: no re-export tables, no detector-keyword suppression comments, no renamed-but-equivalent literals). Atoms used: `Button`, `Select`, `DataTable`, `PageHeaderTitle`.

Caveat (m4): the `Button` atom is used but then over-ridden with the classes its own variant already supplies, plus conflicting geometry — `ImportCorrectionActions.tsx:35` re-declares `intent.primary.bgStrong`+`text.inverse` (identical to `variant="primary"`) and adds `rounded px-3 py-2` against the atom's `rounded-[var(--radius-button)]` and `size="md"` `px-4 py-2`; `:43` sets `text.subtle` against ghost's `text.muted`; `ImportWizardPage.tsx:901` does the same with `rounded-lg px-4 py-2`. Two `border-radius`/`padding` utilities on one element resolve by CSS source order, not by string position — the rendered geometry is not determined by the code.

### 8. Playwright spec `e2e/imports/imp1-history-export.spec.ts`

**`testIgnore`d correctly**: `playwright.config.ts:7` now excludes `'**/imports/**'` alongside `'**/request-hygiene/**'`; a dedicated `e2e/imports/pw.config.ts` drives it. Not run here, per instruction.

**Data-meaning: mostly good.** `:64-65` pin exact counter strings per fixture (`'9 imported','1 skipped','2 failed'`, `'95 imported','5 failed'`, `'200 imported','0 failed'`); `:86-90` read the downloaded CSV **bytes** and assert the UTF-8 BOM, the `_status,_code,_message` trailer and that a warning-only row (`IMP1-WARNING`) is exported; `:157-162` assert filter membership *and* exclusion; `:176-184` assert second-company isolation at both API and UI; `:163-173` assert fr copy, ar copy and `html[dir=rtl]`.

**M1 fix confirmed**: `:67-75` the suppliers scenario now expects **success** — `toContainText('Completed')`, `not.toContainText('Completed with errors')`, and `row.getByRole('alert')` **count 0** — with a comment tying it to dev `477c877a3`.

**Gap → M9**: the test named *"corrected export reuses mapping and can be re-run"* (`:101-131`) asserts **neither**. The mapping-reuse claim rests only on `:113` (`href` contains `reimport_of=`) — it never asserts the mapping step was skipped or that the mapping was pre-applied — and the re-run loop `:117-130` runs twice and asserts nothing but "the complete step is visible", producing only screenshots. The requirement is *a count after a re-run*; the test asserts "no exception". Also weak: `:74` `toContainText('200')` matches any `200` in the row (the row also carries `200 imported`, so it is redundant rather than wrong), `:128` uses the default 20 s expect timeout for an async import where `:26` uses 90 s, and the re-run test consumes an artefact written by an earlier test file-system side effect (`:114`).

---

## Command tails (lane worktree, `apps/web`)

```
$ pnpm typecheck
> tsc --noEmit
(no output)                                                   exit 0

$ pnpm lint
✖ 6407 problems (0 errors, 6407 warnings)                     # handback figure matches exactly
  0 errors and 722 warnings potentially fixable with --fix
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 797
[gate-summary] Design-system baseline: 797 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
i18n completeness OK — 55 namespaces, authored keys: en=9535, fr=9552, ar=5176 authored (1925 behind aliases); 2816 known gap(s) held at the baseline.
no-dead-tailwind-token-interpolation / no-hardcoded-step / no-literal-decimal-places / no-parsefloat-on-money / no-untranslated-literal / no-hardcoded-entity-route: all RuleTester cases passed
tools tests: Test Files 9 passed (9) · Tests 213 passed (213)   exit 0
```

Vitest, DEFAULT pool, **one file at a time**:

```
src/features/import/__tests__/ImportHistoryPage.errorMessage.test.tsx   3 passed  (133ms)
src/features/import/__tests__/ImportWizardPage.options.test.tsx        19 passed  (1847ms)
src/features/import/__tests__/ImportWizardPage.duplicates.test.tsx       8 passed  (809ms)
src/features/import/__tests__/ImportHistoryPage.test.tsx                 1 passed
src/features/import/__tests__/ImportHistoryPage.counts.test.tsx          1 passed
src/features/import/__tests__/tenantScope.test.tsx                      15 passed
src/features/import/__tests__/ImportErrorLocales.test.ts                 3 passed
src/features/import/__tests__/ImportWizardPage.uploadErrors.test.tsx    11 passed

$ ps aux | grep 'node (vitest' | grep -v grep
(empty — no stragglers, nothing to kill)
```

Playwright: **not run** (per instruction; Docker dependencies stopped).

---

## Findings

| id | sev | file:line | claim | required fix |
|---|---|---|---|---|
| **M1** | MAJOR | `apps/web/src/features/import/components/ValidationResults.tsx:67-72` | Renders `summary.job_error_message` raw. That field is `$job->error_message` (`apps/api/.../ImportController.php:524,631`), written at `:336` as `'Failed to parse file: '.$e->getMessage()` — the exact class-names/paths/SQLSTATE payload M2 forbids. This surface is **absent from the handback's enumeration**, so M2 is reported closed while a third operator surface still leaks. | Route it through `importJobErrorMessage(t, { error_code, error_message })`; add `error_code` to the `error-summary` / errors `meta` payloads; extend `ImportHistoryPage.errorMessage.test.tsx`'s SQLSTATE pin to `ValidationResults`. Correct the handback's enumeration. |
| **M2b** | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:493` | The wizard forwards `apiJobData.error_message` verbatim into `completeImport()` → `stores/importProgressStore.ts:139-141` → rendered raw at `GlobalImportProgress.tsx:103-105`. This feeder is **pure frontend** and is not blocked by the broadcast contract the handback cites, so the same page shows a coded message in its completion alert and the raw SQLSTATE in its own global card. | Stop forwarding `error_message` from `:493`; carry `error_code` in `ImportProgress` and translate in `GlobalImportProgress` via `importJobErrorMessage`. The WebSocket feeder may remain a declared, ticketed residual. |
| **M3** | MAJOR | `apps/web/src/features/import/components/ImportCorrectionActions.tsx:23` (handler shared by `:35` and `:43`) | Any 404 renders `correction.noRows` ("There are no rows to fix for this import."), but `downloadFailedRows` 404s with **two** codes — `import_not_found` (`ImportController.php:827`) and `no_rows_to_fix` (`:841`) — and the **Full report** button shares the handler while its endpoint 404s only with `import_not_found` (`:986`). Purged/foreign jobs and missing workbooks get factually wrong copy. The brief required a **coded** message. | Read the error body (`await (error.response.data as Blob).text()` → JSON `error.code`) and branch on the code; give `import_not_found` its own translated string in en/fr/ar; give the Full-report action its own error mapping. |
| **M4** | MAJOR | `apps/web/src/features/import/pages/ImportHistoryPage.tsx:223-224`; `ImportWizardPage.tsx:1529-1531` | "Download rows to fix" is now rendered on **every** terminal job, including fully clean imports. The previous gates (`(job.failed_rows ?? 0) > 0` and `importResults?.failed_rows_csv_url`) were removed. `ImportRowExportService::generate()` returns `null` when no row is invalid/failed/`opening_locked`/warned, so the primary action on a clean import is a guaranteed 404 + toast. Owner OQ-11: dead controls are **hidden**, not shipped. | Gate the rows-to-fix action on `(failed_rows ?? 0) + (warning_rows ?? 0) > 0` (mirroring the service's row predicate) and hide it otherwise; keep "Full report" visible. |
| **M5** | MAJOR | `apps/web/src/features/import/pages/ImportHistoryPage.tsx:41,44-48,93`; `src/features/import/api/importApi.ts:24-28` | The status filter (now including `partially_completed`) filters `jobs.data` client-side over the server's **default 20-row first page**, and the page has **no pagination UI**. The lane's own backend gained a correct server-side filter (`ImportController.php:78,94-95`, `ImportJobOutcome::effectiveStatusExpression`) that the frontend never calls. "Completed with errors" can read empty while such jobs exist beyond page 1 — an untruthful filter. | Pass `status` (and `page`/`per_page`) to `importApi.list()`, include them in the `tenantScopedKey([...])`, render the `meta` pagination, and drop the client-side `filter`. Add a Vitest that asserts the request carries `status=partially_completed`. |
| **M6** | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:593-605` vs the catch at `:627-639` | A failure of `importApi.getJob(reimportOf)` (purged/foreign original → 404) falls into the **parse-headers** catch: `describeUploadFailure` (`:88-113`) reports `wizard.upload.serverError {status:404}` and `:637-638` clear the operator's file selection. The user is blamed for the file they just picked, with no route forward. This re-introduces exactly the class the BUG-004 comment at `:628-633` documents — an unrelated failure mapped onto "invalid file". | Wrap the `getJob` call in its own try/catch; on failure show a `correction.*` message ("the original import is no longer available — continue without the saved mapping") and fall through to `setSourceColumns(headers)` + `suggestMapping`, preserving the selected file. |
| **M7** | MAJOR | `apps/web/src/features/import/pages/ImportWizardPage.tsx:596-605` vs `apps/api/.../ImportController.php:265-274` | The "re-apply the original mapping unless the headers changed" rule has **two writers**: the frontend re-implements it against `getJob().column_mapping`, and the server implements it authoritatively and emits `reimport_notice: 'reimport_headers_changed'` (`:271,329`) — which **no frontend file reads** (`grep -rn "reimport_notice\|reimportNotice" apps/web/src` → 0 hits). Convention 11: an undeclared second writer of one rule; the authoritative signal is dropped. The FE duplicate is also what creates M6. | Delete the frontend duplicate and the `getJob` round-trip; let the server pre-apply the mapping and surface `reimport_notice` from the create response as the `correction.headersChanged` toast. Update the `docs/glossary.md` "Rows to fix" row if the frontend must keep a pre-check. |
| **M8** | MAJOR | `apps/web/src/features/import/pages/ImportHistoryPage.tsx:224` → `ImportCorrectionActions.tsx:28-45` | Every terminal history row now renders a **filled primary button + a format `<select>` + a caveat paragraph + a link + a ghost button** inside a table cell. N rows means N competing filled primaries and N repetitions of the same caveat sentence. Owner ruling (2026-08-10): one main element per screen; added accents that compete with it are the signal-overload complaint driving the UI audit. `docs/glossary.md:79-80` itself frames the filled primary as the completion-step treatment. | Keep `ImportCorrectionActions` as-is on the **wizard completion step**; on the history row collapse to a compact row-action (icon/menu) with the format choice inside it and the caveat shown once above the table, not per row. |
| **M9** | MAJOR | `apps/web/e2e/imports/imp1-history-export.spec.ts:101-131` (esp. `:113`, `:117-130`) | The test named "corrected export reuses mapping **and can be re-run**" asserts neither. Mapping reuse is covered only by an `href` substring check; the two re-run attempts assert nothing but "the complete step is visible" and emit screenshots. The requirement is a **count after a re-run** (idempotency); the test asserts "no exception". | Assert the mapping step is skipped (no `apply-mapping` control) on attempt 1, and assert the history counters after attempt 1 vs attempt 2 (e.g. attempt 2 imports 0 / skips N) so a regression in `reimport_of` idempotency fails the test. |
| **M10** | MAJOR | `apps/web/src/locales/{en,fr,ar}/import.json` `errors.validation_failed` / `errors.internal_error`, consumed at `ImportHistoryPage.tsx:161-163` and `ImportWizardPage.tsx:1461` | Job-level failures are rendered with **row-scoped** copy: "The row did not pass validation." for a job whose actual cause is `'Missing required columns: sku, name'` (`ImportController.php:302-304`), and "The row could not be imported…" for a whole-file parse failure (`:335-338`). The wrong noun, and the only actionable non-sensitive detail (which columns) is discarded — the API already returns `errors.missing_columns` and nothing renders it. | Add job-scoped variants (`errors.job.validation_failed` / `errors.job.internal_error`) in en/fr/ar and select on scope in `importJobErrorMessage`; carry the missing-column list through `error_detail` and interpolate it. |
| m1 | minor | `apps/web/src/locales/en/import.json` `errors.unknown`; `jobErrorMessage.ts:33` | "This historical import error has no current code." is shown for **live** failures whenever `error_code` is null — reachable because `ImportJobClaimService.php:187` writes `'error_code' => $code?->value` (nullable) and `ImportWizardPage.tsx:380` merges a WebSocket `errorMessage` with no code. The copy asserts a fact about the job that is false. | Reword to a scope-neutral generic sentence in en/fr/ar (e.g. "The import failed. Contact support with the import reference."). |
| m2 | minor | `apps/web/src/features/import/pages/ImportWizardPage.tsx:1454` | `status.failed` is promoted from a small pill label to the **completion-step H2**, and `ar\|import\|missing\|status.failed` is a baselined ar gap (`tools/i18n-completeness-baseline.json`) — Arabic operators get an English headline. | Add the six `status.*` keys to `src/locales/ar/import.json` (the lane already edits that file) and shrink the baseline accordingly. |
| m3 | minor | `apps/web/src/features/import/pages/ImportHistoryPage.tsx:22,32` | The `partially_completed` pill uses `intent.warning.text` (`text-yellow-600`) on `bg-yellow-100` while **every sibling pill** uses `*.textStrong` (`:30,31,33,34`) — a neighbouring-shade substitution rather than the established contract, and yellow-600 on yellow-100 is borderline for AA. The glyph is `XCircle` (the failure mark) for a partial **success**. | Use `intent.warning.textStrong`; use a distinct glyph (e.g. `AlertTriangle`) so partial success does not read as failure. |
| m4 | minor | `apps/web/src/features/import/components/ImportCorrectionActions.tsx:35,43`; `pages/ImportWizardPage.tsx:901` | The `Button` atom is used, then overridden with the classes its variant already supplies **plus conflicting geometry** (`rounded` / `rounded-lg` vs the atom's `rounded-[var(--radius-button)]`; `px-3 py-2` / `px-4 py-2` vs `size` padding). Two `border-radius`/padding utilities on one element resolve by CSS source order — the rendered geometry is undetermined by the source. | Use `variant="primary" size="sm"` / `variant="ghost" size="sm"` and delete the colour and geometry overrides; keep only layout classes the atom does not set. |
| m5 | minor | `apps/web/src/features/import/types.ts:52-80` | `ImportJob` remains a ~25-field hand-rolled interface. **No generated DTO is shadowed** (`packages/shared/types/generated.d.ts:1029-1057` has no `ImportJobData`; `ImportController.php:1032-1078` assembles an inline array), so convention 11 is not violated today — but drift between the controller array and the interface is caught only by tests, and `extends Partial<ImportCorrectionData>` makes `column_mapping` optional where the backend always emits it. | Introduce an `ImportJobData` DTO on the backend, `php artisan typescript:transform`, and let `types.ts` re-export it. |
| m6 | minor | `apps/web/e2e/imports/imp1-history-export.spec.ts:74,128,114` | `toContainText('200')` is a substring match satisfied by the already-asserted `200 imported`; `:128` uses the 20 s default expect timeout where `:26` needs 90 s for the same async completion; `:114` reads an artefact written by an earlier test in the file (serial-order coupling). | Assert `'200 imported'`; give `:128` the 90 s timeout; regenerate or assert the artefact's presence explicitly. |
| m7 | minor | `apps/web/src/features/import/pages/ImportWizardPage.tsx:598` | The reuse guard checks only that every saved **source** header still exists in the new file. It does not check that all required **target** columns are mapped, and silently ignores columns the operator added to the corrected file — then `:895-898` skips the mapping step entirely, so the operator never sees or fixes either case. | Also require the mapped targets to cover `TARGET_COLUMNS[importType]` required set, and fall through to the mapping step (not the toast-and-skip path) when the corrected file introduces unmapped headers. |

---

## Notes for the next round

- Re-run `pnpm lint` after the fixes and confirm the design-system baseline still shows **0 new / 0 stale**: M4's re-gating deletes JSX, M8's collapse rewrites `ImportCorrectionActions`, and both must shrink (never grow) the baseline.
- M1, M2b and M10 are one theme — the coded-failure channel is only half-built. Fixing them together is cheaper than three passes, and M1 needs `error_code` added to the errors/`error-summary` meta payloads (a backend change; coordinate with the imports lane).
- M6 and M7 collapse into one change: deleting the frontend mapping duplicate removes the misattributed `getJob` failure. The `options.test.tsx:154-163` delayed-response test would then need re-pointing at whatever pre-check survives — do not delete it without a replacement.
