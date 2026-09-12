# IMP-1 — imports history + error-line export · implementation gate r2 (fix-round verification) · `apps/web` frontend conventions

| Field | Value |
|---|---|
| Reviewer | Claude Opus 5 (1M) — adversarial frontend-conventions gate |
| Date | 2026-09-12 |
| Lane | `lane/imp1-history-export` @ `46527aa76` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/imp1-history-export`, clean) |
| Round scope | `git diff 6776ed772..46527aa76` — 23 `apps/web/` files (+833/−85) plus `ImportController.php`, generated types, evidence, tickets |
| Answers | `docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r1-frontend.md` (0B/10M/7m) |
| Handback read | `docs/handoff/HANDBACK-IMP-1-2026-09-10.md` §"Fix round 2" |
| Authorities | conventions 01/05/06/09/11 · CLAUDE.md rules 9/11/14/18 · `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` (OQ-11, one-main-element) · `docs/glossary.md:79-82` |

## Verdict

**ACCEPT-WITH-FIXES** — **0 BLOCKER · 1 MAJOR · 3 minor**

Every r1 finding I raised is genuinely closed, and closed with tests that assert meaning rather than shape:
the coded-failure channel now reaches **all four** surfaces (the handback's corrected enumeration is accurate —
I re-grepped it), the 404 branch reads the blob body's `error.code`, the rows-to-fix control is hidden on clean
imports on both surfaces, the status filter and page window are server-side and pinned on the wire, the
re-import rule has one writer with the server's `reimport_notice` driving the toast, the history row lost its
filled primary and its per-row caveat, and the re-run scenario now asserts the skip and the counters. The
mechanical leg is honest: 0 errors, design-system baseline **unchanged in this round** (0 new / 0 stale), i18n
baseline **shrank by exactly the six ar `status.*` keys the round authored**, no suppression comments, no alias
indirection, no `--write-baseline`.

The one MAJOR is **new and cross-layer**, and it falsifies one sentence of the handback: the m7 pre-check now
routes the operator to the mapping step when the saved mapping misses a required target — but
`ImportController::store()` **discards the mapping the operator then submits** and re-applies the original,
so the operator lands in the same 422 they were routed away from, permanently. The frontend half is right;
the server needs one condition plus a test before merge.

---

## Item-by-item — every r1 finding

| id | r1 severity | status at `46527aa76` | evidence |
|---|---|---|---|
| **M1** — `ValidationResults` renders raw `job_error_message` | MAJOR | **CLOSED** | `apps/web/src/features/import/components/ValidationResults.tsx:23-27` builds the message through `importJobErrorMessage(t, {error_code, error_message, error_detail})`; `:72-83` renders only that, with the "never `job_error_message`" comment. Backend publishes the code: `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:536-537` (`errors`) and `:645-646` (`error-summary`). SQLSTATE pin extended to this surface: `apps/web/src/features/import/__tests__/ImportHistoryPage.errorMessage.test.tsx:104-127` (asserts `errors.job.internal_error`, and `document.body.textContent` contains neither `SQLSTATE` nor `ImportService.php`). |
| **M2b** — wizard feeds raw `error_message` into the global card | MAJOR | **CLOSED** | `apps/web/src/features/import/pages/ImportWizardPage.tsx:508-510` now forwards **only** `error_code` (`...(apiJobData.error_code ? { error_code: … } : {})`); the raw forward is gone. `apps/web/src/components/organisms/GlobalImportProgress/GlobalImportProgress.tsx:24-27` resolves both feeders through `importJobErrorMessage`. Pinned both ways at `ImportHistoryPage.errorMessage.test.tsx:129-158`: raw-only feeder → `errors.unknown`, coded feeder → `errors.job.internal_error`, never `SQLSTATE`. WebSocket feeder resolving to the generic sentence is the declared, ticketed residual (`docs/superpowers/tickets/2026-09-11-imp1-followups.md`) — acceptable, rule 8 respected. |
| **M3** — every 404 says "no rows to fix" | MAJOR | **CLOSED** | `apps/web/src/features/import/components/ImportCorrectionActions.tsx:45-61` decodes the Blob body (`readBlob` at `:31-43` handles jsdom) and reads `error.code`; `:20-28` give the two actions **separate** maps (`ROWS_ERROR_KEYS` incl. `correction_export_unavailable`, `REPORT_ERROR_KEYS`), `:87-100` apply per-action fallbacks. New copy `correction.importNotFound` / `correction.reportUnavailable` in en/fr/ar. Pinned at `__tests__/ImportCorrectionActions.test.tsx:43-81`, including "does not tell the operator there are no rows to fix when the full report is missing". |
| **M4** — dead "Download rows to fix" on clean imports | MAJOR | **CLOSED** | Gate `(failed_rows ?? 0) + (warning_rows ?? 0) > 0` at `ImportHistoryPage.tsx:249` and `ImportWizardPage.tsx:1567`; the control is **hidden**, Full report stays (`ImportCorrectionActions.tsx:106,141-157`). I verified the gate matches the service predicate end-to-end: `ImportRowExportService::generate()` (`apps/api/app/Modules/Import/Services/ImportRowExportService.php:34-41`) selects `is_valid=false` ∪ `outcome ∈ {Failed, OpeningLocked}` ∪ `hasWarnings`, and validation stamps every `is_valid=false` row `outcome=Failed` (`ImportService.php:233-243`), so `failed_rows`+`warning_rows` covers all three arms — no hidden-but-downloadable case. Vitest pins both directions (`ImportHistoryPage.actions.test.tsx:88-110`, warning-only case included) and Playwright asserts `toHaveCount(0)` for clean fixtures / present for partial (`e2e/imports/imp1-history-export.spec.ts:55-65`). |
| **M5** — client-side status filter over page 1 | MAJOR | **CLOSED** | `importApi.list()` builds `status`/`page`/`per_page` (`api/importApi.ts:25-40`) and keeps the documented `api.get` + `response.data` carve-out for the `{data,meta}` envelope (convention 01). `useImportJobs` key is `tenantScopedKey([...lists(), status, page, per_page])` (`api/queries.ts:31-42`) — tenant/company remain the suffix. Client-side `filter` is gone (`ImportHistoryPage.tsx:61-68`); `OffsetPagination` renders from `meta` (`:261-272`), backed by new `meta.from`/`meta.to` (`ImportController.php:122-123`). Wire assertion `importApi.envelope.test.ts:46-61`; key + invalidation assertions `tenantScope.test.tsx:147-167`. |
| **M6** — `getJob` failure blamed on the operator's file | MAJOR | **CLOSED** | `ImportWizardPage.tsx:611-634`: the pre-check has its own `try/catch`; the catch logs, toasts `correction.originalUnavailable`, and falls through to `setSourceColumns(headers)` + `suggestMapping` — the selection is **not** cleared (only the outer parse-headers catch at `:655-662` does that). Pinned at `ImportWizardPage.options.test.tsx:166-186`: file stays ready, `toast.error` never called, Next enabled, mapping step reachable. |
| **M7** — two writers of the re-import reuse rule | MAJOR | **CLOSED (route documented)** | The rule's single writer is `ImportController::store()` (`ImportController.php:270-279`), whose `reimport_notice` now drives the toast: `ImportWizardPage.tsx:701-704`; the frontend's own reuse toast is gone. `grep -rn "reimport_notice" apps/web/src` now hits (`types.ts:198`, `ImportWizardPage.tsx:702`, tests). The surviving client-side question is presentation-only ("can the mapping step be skipped"), declared in the code comment `:613-617` and in the handback. `docs/glossary.md:82` already describes the server's verdict wording. Pinned at `options.test.tsx:201-215`. **But see M-NEW-1** — the server's half of this rule now defeats the operator. |
| **M8** — N filled primaries + N caveats in the table | MAJOR | **CLOSED (with a residual, see m-NEW-3)** | `ImportCorrectionActions` gained `layout: 'panel' \| 'row'` (`:63-105`): the row variant uses `variant="secondary"` (`:119`) and omits the caveat (`:138`); the history states it once above the table (`ImportHistoryPage.tsx:138`); the completion step keeps `variant="primary"` (`ImportWizardPage.tsx:1564-1570`). Pinned: `ImportHistoryPage.actions.test.tsx:78-87` (caveat count 1 with two rows), `ImportCorrectionActions.test.tsx:94-110`. |
| **M9** — re-run test asserted neither reuse nor a count | MAJOR | **CLOSED** | `e2e/imports/imp1-history-export.spec.ts:130-165`: mapping step asserted absent on both attempts (`expect(ui.step.mapping).toHaveCount(0)`), history counters captured per attempt and compared (`countersOf(counters[2]) === countersOf(counters[1])`), `0 failed` asserted, 90 s timeouts at `:136,:145,:152`, and the test now downloads **its own** input (`:123-128`) — the cross-test artefact coupling is gone. Committed evidence `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/corrected-attempt-{1,2}-history.txt` shows two **distinct** `reimport_of` job ids with identical `4 imported / 0 skipped / 0 failed / 4 total`, timestamped 18:36:37 / 18:36:42 on 2026-09-12 (this round's run). |
| **M10** — row-scoped copy on job-level failures | MAJOR | **CLOSED** | `jobErrorMessage.ts:23-26,60-65`: `JOB_SCOPED_CODES` routes `validation_failed`/`internal_error` to `errors.job.*`, and `error_detail.missing_columns` is interpolated into `errors.job.missing_columns`. Backend carries the list: `ImportController.php:310-315` (`ImportErrorDetailData::from(['missing_columns' => …])`). Copy in en/fr/ar (`locales/*/import.json`). Interpolation proved, not assumed: the test's `t` echoes options, and `ImportHistoryPage.errorMessage.test.tsx:181-192` / `:113-126` assert `errors.job.missing_columns:name, sku`. |
| m1 — "This historical import error has no current code." | minor | **CLOSED** | Reworded scope-neutrally in all three locales ("The import failed. Contact support with the import reference." / fr / ar). |
| m2 — ar `status.*` gap under the completion H2 | minor | **CLOSED** | Six keys added (`locales/ar/import.json:191-197`) and `tools/i18n-completeness-baseline.json` loses exactly those six entries — **removal-only**, verified by diff; audit reports 2810 known gaps (was 2816). |
| m3 — `partially_completed` pill shade + glyph | minor | **CLOSED** | `ImportHistoryPage.tsx:25` `AlertTriangle` + `intent.warning.textStrong`, matching sibling pills (`:29-37`). |
| m4 — `Button` atom overridden with its own variant + conflicting geometry | minor | **CLOSED** | `ImportCorrectionActions.tsx:118-133,143-156` use `variant`/`size` with only `className="gap-2"`; `ImportWizardPage.tsx:924-938` likewise. No `rounded*`/`px-*`/`py-*` overrides remain on those atoms. |
| m6 — Playwright substring/timeout/coupling nits | minor | **CLOSED** | `'200 imported'` (`spec.ts:82`), 90 s timeouts, own artefact (see M9). |
| m7 — reuse guard ignored required-target coverage | minor | **CLOSED on the client, DEFEATED on the server** | `mappingCoversRequiredTargets` (`ImportWizardPage.tsx:133-147`) shared with the mapping step's validity gate (`:859`) and applied at `:624`; pinned at `options.test.tsx:188-199`. The server does not honour the corrected mapping — **M-NEW-1**. |
| m5 — no `ImportJobData` DTO | minor | **DEFERRED, honestly** | Registered in `docs/superpowers/tickets/2026-09-11-imp1-followups.md` with the reasoning intact. Convention 11 is still not violated (no generated DTO is shadowed). |

### Baseline honesty and mechanism audit

- `apps/web/tools/audit-design-system-baseline.json`: **byte-identical** across `6776ed772..46527aa76`; the lane's total remains the 5 r1-verified removals vs the merge base. Audit output: `797 acknowledged, 0 new, 0 stale`. M4/M8 deleted and rewrote JSX without going stale because the history status chip's JSX was kept byte-identical behind the `setStatusFilter` wrapper (`ImportHistoryPage.tsx:52-55`) — I confirmed that is a real wrapper, not a detector dodge.
- `grep -nE "^\+" | grep -E "eslint-disable|@ts-|: any|as any|write-baseline"` over the round's `apps/web/` diff: **no hits**. No alias table re-exporting `tokens.*`, no renamed-equivalent literals, no suppression comments carrying detector keywords.
- i18n baseline: removal-only (six entries), and the ratchet's own fail-closed cases exercise in `test:tools` (213 passed).
- Rule 18: every colour added this round is `semanticColorTokens` (`ImportCorrectionActions.tsx:139,142`, `ValidationResults.tsx:75-79`, `ImportHistoryPage.tsx:25,35,138`); no interpolated variant prefix or opacity modifier on a token (`no-dead-tailwind-token-interpolation` RuleTester green).
- i18n: no hardcoded user-facing string in the round's JSX; all new copy is `t()`-keyed and present in en/fr/ar.

---

## Findings

| id | sev | file:line | claim | required fix |
|---|---|---|---|---|
| **M-NEW-1** | MAJOR | `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:271-278` ⟷ `apps/web/src/features/import/pages/ImportWizardPage.tsx:611-634,690-712` | On a `reimport_of` upload, whenever the original mapping's source headers still exist in the new file the server **overwrites the `column_mapping` the client submitted** with the original's (`$columnMapping = $reimport->column_mapping;`). The m7 pre-check added this round deliberately routes the operator to the mapping step when the saved mapping misses a required target — the operator maps the missing column, `handleMappingComplete` posts it (`importApi.ts:56-58`), and the server throws it away, producing the same `422 validation_failed / errors.missing_columns` refusal every time. Concretely: a products import fails "missing required columns: name"; the history row offers **Re-upload corrected file**; the operator uploads a file that *adds* the `Name` column; original keys ⊆ new headers → original mapping re-applied → `name` unmapped → 422, forever. The handback's claim that the pre-check "keeps the operator out of a refusal they could not act on" is therefore false — it only defers the refusal by one step. `ImportRowExportTest::test_a_reused_mapping_that_misses_a_required_target_is_refused_with_the_column_list:197-219` documents the refusal but posts **no** `column_mapping`, so this path is untested. | In `store()`, re-apply the original mapping **only when the request carries none** (`$this->validatedColumnMapping($request) === null`); when the client submitted a mapping, keep it and still emit `reimport_notice` on a header change. Add a feature test: re-import with an explicit mapping that adds the previously-missing target → **201**, `column_mapping` = the submitted one. Frontend needs no change. |
| m-NEW-2 | minor | `apps/web/src/features/import/api/queries.ts:139-141` | `useCreateImport`'s `onError` toasts `error.message` — for the 422 above, axios's untranslated English `"Request failed with status code 422"`. The response carries `error.code = validation_failed` and `errors.missing_columns`, i.e. exactly the actionable copy this round authored (`errors.job.missing_columns`), and none of it is rendered. Rule 11 (untranslated user-facing text) and the round's own "coded channel on every operator surface" claim. Untouched pre-existing code, so not a regression — but it is the operator's only feedback in the M-NEW-1 loop. | Map the create-import failure through the coded channel: read `error.response.data.error.code` + `errors.missing_columns` and render `errors.job.missing_columns` / `errors.job.<code>`, falling back to `messages.uploadError`; never surface `error.message`. |
| m-NEW-3 | minor | `apps/web/src/features/import/components/ImportCorrectionActions.tsx:105-157` mounted at `ImportHistoryPage.tsx:246-251`; caveat at `ImportHistoryPage.tsx:138` | M8's residual. The row variant is no longer a filled primary, but each terminal row still carries **four** controls (format `Select`, secondary Download, "Re-upload corrected file" link, ghost Full report) — the r1 directive asked for a compact icon/menu row-action with the format inside it. Separately, the table-level caveat renders whenever any job exists, including a history where **no** row exposes a rows-to-fix control (the caveat then describes an action nobody can take). One-main-element is improved, not reached. | Collapse the row to a single action control (icon/menu) holding format + download + full report + re-upload; render the caveat only when at least one visible row has `(failed_rows ?? 0) + (warning_rows ?? 0) > 0`. |
| m-NEW-4 | minor | `apps/web/src/features/import/types.ts:193-199`; `apps/web/src/features/import/pages/ImportWizardPage.tsx:702` | `reimport_notice` is typed `string \| null` and compared against the bare literal `'reimport_headers_changed'` — a magic string on a wire contract the server owns (rule 9's spirit; nothing fails if either side renames it). | Type it as a literal union (`'reimport_headers_changed'`) — ideally a backend enum surfaced through `php artisan typescript:transform` — so a rename fails typecheck on both sides. |

---

## Command tails (lane worktree, `apps/web`, `46527aa76`)

```
$ pnpm typecheck
> tsc --noEmit
(no output)                                                              exit 0

$ pnpm lint
✖ 6416 problems (0 errors, 6416 warnings)
  0 errors and 722 warnings potentially fixable with the `--fix` option.
[sweep-progress] Gate C — queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 797
[gate-summary] Design-system baseline: 797 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale)
i18n completeness OK — 55 namespaces, en=9542, fr=9559, ar=5189; 2810 known gap(s) held at the baseline.   (r1: 2816)
no-dead-tailwind-token-interpolation / no-hardcoded-step / no-literal-decimal-places /
no-parsefloat-on-money / no-untranslated-literal / no-hardcoded-entity-route: all RuleTester cases passed
tools tests: Test Files 9 passed (9) · Tests 213 passed (213)
```

**Warning delta 6407 → 6416 (+9) — audited, none is an error and none is in a new directory.** Attribution by
mapping each warning's line number onto the round's `-U0` hunks:

| site | rule | new? |
|---|---|---|
| `components/ImportCorrectionActions.tsx:55` | `no-unsafe-type-assertion` (the `{error:{code?:unknown}}` body cast) | new (hunk `+14,69`) |
| `pages/ImportHistoryPage.tsx:249:43`, `:249:68` | `no-unnecessary-condition` (`?? 0` on non-nullable counters) — the M4 gate | new (hunk `+246,6`) |
| `pages/ImportWizardPage.tsx:1567:41`, `:1567:70` | same, the panel M4 gate | new (hunk `+1564,5`) |
| remaining ~4 | `no-unsafe-type-assertion` / template-expression nits inside the round's **new and extended test files** (`ImportCorrectionActions.test.tsx`, `importApi.envelope.test.ts`, `tenantScope.test.tsx`, `ImportHistoryPage.errorMessage.test.tsx`) | new |

All are in pre-existing directories (`src/features/import/`, `src/components/organisms/GlobalImportProgress/`),
so the token ESLint rule's new-directory error mode never applies — corroborated by the **0 errors** total.
Method note: this is diff-hunk attribution against the current warning set, not a re-run of ESLint at
`6776ed772` (that would need a second installed worktree); the classes match the handback's description exactly.

Vitest, DEFAULT pool, **one file per invocation** (every import test file touched this round):

```
src/features/import/__tests__/ImportCorrectionActions.test.tsx        9 passed  (229ms)
src/features/import/__tests__/ImportHistoryPage.actions.test.tsx      6 passed  (261ms)
src/features/import/__tests__/ImportHistoryPage.errorMessage.test.tsx 8 passed  (165ms)
src/features/import/__tests__/ImportWizardPage.options.test.tsx      22 passed  (1.92s)
src/features/import/__tests__/importApi.envelope.test.ts              5 passed  (3ms)
src/features/import/__tests__/tenantScope.test.tsx                   16 passed  (554ms)
src/features/import/__tests__/ImportErrorLocales.test.ts              3 passed  (2ms)

$ ps aux | grep 'node (vitest' | grep -v grep | wc -l
       0
```

Every count matches the handback's RED→GREEN table exactly. Playwright: **not run** (per instruction);
the committed six-scenario tail `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/browser-green-fixround2.txt`
reports `6 passed (1.3m)` on the lane harness (API :8012 / Vite :5176 / PG 5433 / Redis 6380), and the M9
counter artefacts are dated 2026-09-12 18:36, consistent with that run.

---

## Merge condition

The frontend leg is done. Land **M-NEW-1** (one condition in `ImportController::store()` plus the feature
test that posts a corrected mapping on a `reimport_of`) before merge; m-NEW-2..4 may travel as a follow-up
ticket beside m5 and m-B..m-F. No re-review of the `apps/web` diff is required for the three minors.
