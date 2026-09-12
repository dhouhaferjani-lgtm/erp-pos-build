# IMP-1 — imports history + rows-to-fix export — implementation gate **round 3** (fix-round-2 verification), imports seam

- **Date:** 2026-09-12
- **Reviewer:** imports-reviewer (adversarial, code-grounded)
- **Lane:** `lane/imp1-history-export` @ `46527aa76` — worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/imp1-history-export`
- **Base of the fix round:** `6776ed772` · **dev tip at review:** `89f39b93f`
- **Round 2 register:** `docs/superpowers/reviews/2026-09-12-imp1-impl-gate-r2-imports.md` (ACCEPT-WITH-FIXES, 0B/2M/6m: M2-R, M3-R, m-A..m-F)
- **Fixer handback:** `docs/handoff/HANDBACK-IMP-1-2026-09-10.md` §"Fix round 2" (lines 180-357), follow-ups `docs/superpowers/tickets/2026-09-11-imp1-followups.md`
- **Scope of this round:** M3-R, M2-R (backend + generated types), server-side filter/pagination, the re-import reuse rule, the m-B..m-F disposition, blast radius, empirical re-run.

## VERDICT

**spec ✅ + quality ACCEPT — 0 blockers / 0 majors / 5 minors**

Both round-2 majors are genuinely closed, not relocated. M3-R was fixed at the *structural* level the register asked for (per-request token + `.part`→rename + `deleteFileAfterSend(true)` + prefix sweeps), and I verified empirically that the zero-byte-200 failure class is now unreachable by construction: with `response()->download()` a vanished artefact raises before any body is sent (I measured **500**, not a 0-byte 200 — see the empirical leg), and the new guard converts that into an actionable coded 404. M2-R is closed on all four surfaces, with the enum case (`company_context_missing`) that m-A asked for, its test, and en/fr/ar copy; `packages/shared/types/generated.d.ts` is **byte-identical** to a fresh `php artisan typescript:transform` in the lane (empty diff). All six PG classes are green, PHPStan level 8 is clean over the whole `app/Modules/Import` tree, and the blast radius is import-owned.

The five minors are all coverage/ergonomics, none of them touches money, sign direction, row selection, idempotency or tenancy. The most substantive one is that the handback's "no seam exists" claim for the new 404 guard is **false** — I wrote the missing test in ~12 lines and it passes on PG (recipe below).

---

## Per-item status

| Item | Status | Evidence |
|---|---|---|
| **1. M3-R — per-request artefact** | **CLOSED** | Token: `ImportRowExportService.php:76` (`self::artifactPath($job, $format, (string) Str::uuid())`), path shape `imports/rows/{jobId}.{token}.{format}` at `:141-149`; rationale comment `:71-75` names the r2 defect. Staging + atomic publish: `:77` (`$staging = $path.'.part'`), CSV `:93`, **xlsx writes to the staging path** `:106-108` (`(new Xlsx(...))->save(Storage::disk('local')->path($staging))`), single rename `:114` (`->move($staging, $path)`), `generate()` returns the path it wrote `:116`. Controller: `:854` takes that path, `:885` `->deleteFileAfterSend(true)` — only this request's file. Coded 404 on a NULL/0-byte read: `:863-873` (`correction_export_unavailable` + `Log::warning('import_jobs.correction_export_missing')`); the empty-selection 404 keeps its own code at `:856` (`no_rows_to_fix`). Prefix sweeps: `deleteArtifacts()` `ImportRowExportService.php:130-139` (`files('imports/rows')` filtered on `str_starts_with($file, $prefix)`, prefix `= 'imports/rows/'.$job->id.'.'` at `:143`), called by `destroy()` `ImportController.php:981` and by the purge at `PurgeExpiredImportArtifactsCommand.php:59`. Tests moved to prefix form: `PurgeExpiredImportArtifactsTest.php:273-291` (three expired artefacts incl. a **second csv for the same job**, one live kept), and the new `ImportRowExportTest.php:309-321` (`test_a_download_deletes_only_the_artefact_it_generated` — an in-flight xlsx survives a completed csv download), `:323-342` (`test_sequential_downloads_…` asserts `PK` magic and `>1000` bytes for both cycles, BOM for csv), `:293-307` (nothing left after either download), `:352-361` (discard sweeps both). Guard reachability: **reachable and testable** — see finding **N-1**. |
| **2. M2-R / m-A — coded failure channel** | **CLOSED** | `ImportErrorCode::CompanyContextMissing` added `ImportErrorCode.php:32` and marked job-level `:38-40`; stamped at `ProcessProductImageImport.php:109` with the rule-9 rationale `:105-108`; pinned by `PartiesImportTypeTest.php:252` (`assertSame(ImportErrorCode::CompanyContextMissing, $job->error_code)`). Coded payloads: `ImportController.php:536` + `:645` (`job_error_code` / `job_error_detail` on `errors` and `error-summary`), `:1105-1106` on `formatJob`. `missing_columns` carried end-to-end: DTO `ImportErrorDetailData.php:54-58` (**pure addition**, nullable, last constructor arg), stamped on the header refusal `ImportController.php:313-315`, pinned on all three payloads by `ImportRowExportTest.php:258-281`. FE: `errorCodes.ts:19` + `satisfies Record<ImportErrorCode, true>` `:20`, `jobErrorMessage.ts:60-65` (job-scoped copy + `missing_columns` interpolation); all 16 codes present in en/fr/ar and `errors.job.{internal_error,missing_columns,validation_failed}` in all three. Fourth surface converted: `GlobalImportProgress.tsx:21-27` + `:109-113` renders `importJobErrorMessage(...)`, never `progress.errorMessage`; store carries `errorCode` (`importProgressStore.ts:21-22`, `:143-145`). Exhaustive grep over `apps/web/src` finds **no** import surface rendering `error_message`. Generated types: `git diff` after `php artisan typescript:transform` in the lane is **EMPTY**. |
| **3. Server-side status filter + pagination** | **CLOSED** | Validation with bounds `ImportController.php:77-83` (`page ≥ 1`, `per_page` 1..100, `status`/`type` via `new Enum(...)`, `q` ≤ 255). Tenant + company scope `:93-95` (`where tenant_id` + `company_id = current OR NULL`). Predicate still derived from the one authority `:98-100` → `ImportJobOutcome::effectiveStatusExpression()` (`ImportJobOutcome.php:59-72`, terminal `IN` list generated from `ImportStatus::cases()` `:61-64`, `isTerminal()` includes `PartiallyCompleted` `ImportStatus.php:25`). Meta per convention 01 `:118-126` (`current_page`,`last_page`,`per_page`,`total`,`from`,`to`). Historical reclassification pinned: `ImportRowExportTest.php:233-256` — a job stored `Failed` with `successful_rows = 2` answers `?status=partially_completed` (`:254`) and **not** `?status=failed` (`:255`), plus `meta.from/to/per_page` (`:248-251`) and `?per_page=1` (`:253`). FE consumes the server window: `queries.ts:28-41` (filter/page/per_page in the cache identity, tenant/company still the suffix via `tenantScopedKey`), `importApi.ts:25-35`, `ImportHistoryPage.tsx:52-65` (filter change resets the page) and `:261-271`. Invalidation still matches the longer key (`_invalidation.ts:27-34` is prefix+suffix based). |
| **4. Re-import reuse rule — one writer** | **CLOSED (one pin missing, N-2)** | Server decides and reports: `ImportController.php:270-279` (reuse when every saved source header still exists, else `$reimportNotice = 'reimport_headers_changed'`), emitted at `:340`. Reuse does **not** bypass header validation — the refusal is 422 `validation_failed` with `errors.missing_columns` (`:305-325`), pinned by `ImportRowExportTest.php:197-220` (`test_a_reused_mapping_that_misses_a_required_target_is_refused_with_the_column_list`: asserts `error.code`, `errors.missing_columns=['name']`, `data.error_detail.missing_columns=['name']`, and that the saved mapping was still applied). Second company refused 409 `IMPORT_COMPANY_MISMATCH`: `ImportController.php:216-218` → pinned `ImportRowExportTest.php:186-195`. Other tenant → 404 `import_not_found` (`:213-215`): **not pinned anywhere** — N-2. Wizard no longer re-derives the rule (`ImportWizardPage.options.test.tsx:206-215` asserts it renders the server's `reimport_notice`). |
| **5a. m-B** (sync finalize `report()` + coded code) | **STILL OPEN, ticketed** | `ImportService.php:618-626` unchanged; grep over `apps/api/tests` finds no case driving a `CodedImportRowException` out of `finalizeImport()`. Registered `tickets/2026-09-11-imp1-followups.md:25`. Correctly declared "not done". |
| **5b. m-C** (SQL authority, remaining quadrants) | **PARTIALLY CLOSED, ticketed** | One more data-level quadrant landed (`?status=failed` must **not** return the reclassified job — `ImportRowExportTest.php:255`). The two others (stored `completed` + `failed_rows>0` must not answer `?status=completed`; a clean `failed` must answer `?status=failed`) remain Playwright-only (`imp1-history-export.spec.ts`, private harness, not in CI). Ticket `:26`. |
| **5c. m-D** (evidence annotation) | **PARTIALLY CLOSED, ticketed** | The refreshed tail is now a committed artefact: `docs/superpowers/reviews/2026-09-10-imp1-evidence/phase-b/browser-green-fixround2.txt` (6/6, harness line, 1.3m), and the phase-b suppliers artefact reads `Completed / 200 imported … 9/12/2026 6:36 PM`. Still un-annotated: `phase-b/browser-green.txt` (pre-fix 6-passed tail) and the phase-a root `imp1-suppliers-balances-200.xlsx-history.txt`, which still reads `Failed`; `supplier-finalization.SUPERSEDED.md` names only `supplier-finalization.json`. Ticket `:27`. |
| **5d. m-E** (`error_message` disclosed) | **DOC HALF CLOSED, disclosure open, ticketed** | Still published at `ImportController.php:1107` (and `:536`/`:645` siblings). The doc claim is now truthful — `docs/modules/imports.md:867-884` says "still *disclosed* to the client … but **no surface renders it**" and carries the four-surface table. Ticket `:28`. Rate: **minor, accepted** — the payload is behind `can:imports.manage`, and the register's objection was the false doc claim, which is gone. |
| **5e. m-F** (wizard Vitest) | **STILL OPEN, ticketed** | `ImportHistoryPage.errorMessage.test.tsx` now covers the history page (`:62-105`), `ValidationResults` (`:107-130`) and `GlobalImportProgress` (`:132-169`) with `not.toContain('SQLSTATE')`; `ImportWizardPage.tsx`'s own execute/completion alerts still have no case (grep: `SQLSTATE` appears in no wizard test). Ticket `:29`. |
| **6. Blast radius** | **CLEAN** | `git diff --name-only 6776ed772..46527aa76` = 71 paths. Production code touched: 5 backend files, all under `app/Modules/Import`; 12 frontend files, all under `apps/web/src/features/import` **except** `components/organisms/GlobalImportProgress/GlobalImportProgress.tsx` and `stores/importProgressStore.ts` — both are the import progress widget/store, i.e. import-owned in substance, rate **acceptable**. Shared files: `packages/shared/types/generated.d.ts` (+1 DTO field, +1 enum member; regeneration verified identical), `apps/web/src/locales/{en,fr,ar}/import.json` (import namespace only), `apps/web/tools/i18n-completeness-baseline.json` (**removal-only**, −6 `ar|import|status.*` gaps — shrink, never grows), docs/evidence/ticket. **No** money/quantity code, no migration, no seeder, no other module. Precision scan of the diff for `(float)` / `parseFloat` / `Number(` / `number_format` / `toFixed` on the changed lines: **zero hits**. |

---

## New findings

### [MINOR] N-1 — the `correction_export_unavailable` guard *is* testable; the "no seam" claim is wrong, and I wrote the test

`apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:863-873`; claim under review: `docs/handoff/HANDBACK-IMP-1-2026-09-10.md:354-357` and `docs/superpowers/tickets/2026-09-11-imp1-followups.md:23` ("`ImportRowExportService` is `final` with no interface, so nothing can make `generate()` return a path that is then removed").

The claim confuses *mocking the service* with *faking the disk*. `ImportRowExportTest` already calls `Storage::fake('local')` in `setUp` (`ImportRowExportTest.php:87`), and Laravel's `FilesystemManager::set()` lets a test swap that faked disk for a partial mock of it. No interface, no subclass, no change to the `final` class. Proven on this tree (PG, `autoerp_test_y`): **OK (1 test, 2 assertions)**.

```php
// tests/Feature/Import/ImportRowExportTest.php — add beside the other artefact cases
public function test_a_vanished_artefact_is_refused_with_a_code_not_an_empty_body(): void
{
    $job = $this->exportJob();

    $real = Storage::disk('local');
    $spy = Mockery::mock($real)->makePartial();   // FilesystemAdapter is not final
    $spy->shouldReceive('exists')->andReturnFalse();
    Storage::set('local', $spy);                  // generate() still writes through the partial

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/imports/'.$job->id.'/failed-rows.csv')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'correction_export_unavailable');
}
```

**Why it matters:** the guard is the only thing standing between a vanished artefact and a raw 500 on the operator's single feedback channel, and the FE has a dedicated branch for it (`ImportCorrectionActions.tsx:23`) that is currently pinned by nothing on the server side. A future refactor that drops the guard reddens no test.

**Worth recording for the file:** with the new `response()->download()` the fall-through is **not** the r2 zero-byte 200 — I blinded the guard (`exists()`→true, `size()`→42, `path()`→a vanished file) and measured `SCRATCH status without guard: 500`. So M3-R's failure class is dead by construction; the guard is defence-in-depth plus a better code. That is why this is minor, not major.

**Required fix:** add the test above (and, optionally, its `size() === 0` twin). Correct the "no seam" wording in the ticket/handback. *(My scratch class was deleted; `git status` in the worktree is clean.)*

### [MINOR] N-2 — the cross-tenant `import_not_found` refusal is pinned by nothing

`ImportController.php:213-215` (re-import lookup) and `:841-843` (download lookup) both answer `404 {"error":{"code":"import_not_found"}}`; `docs/modules/imports.md:905-907` documents the two distinct 404 codes as a contract ("the FE reads the body code rather than branching on the status"), and `ImportCorrectionActions.tsx:22` maps it to `correction.importNotFound`. `grep -rn "import_not_found" apps/api/tests` → **no hits**. The tenant scoping itself is real (`where('tenant_id', $tenantId)` on both lookups), but nothing regresses it, and this is the one place where a slip would be a cross-tenant read.

**Required fix:** one case in `ImportRowExportTest` — a second tenant's job id on `POST /imports?reimport_of=` and on `GET /imports/{id}/failed-rows.csv`, asserting `404` + `error.code = import_not_found` (and that no artefact is written).

### [MINOR] N-3 — the rows-per-page control shows a value the page is not using

`apps/web/src/features/import/pages/ImportHistoryPage.tsx:42` sets `DEFAULT_PER_PAGE = 20`, but `apps/web/src/components/ui/OffsetPagination.tsx:25` offers `[10, 25, 50, 100]` and renders a controlled `<Select value={perPage}>` (`:72`). With `perPage = 20` no `<option>` matches, so the browser displays **10** while the server returns 20 rows and `meta.per_page` says 20. The operator reads a false page size.

**Required fix:** set `DEFAULT_PER_PAGE = 25` (or 10) so the default is one of the offered options — a one-line change in the import page, not in the shared control.

### [MINOR] N-4 — `deleteArtifacts()` scans the whole flat artefact directory once per job

`ImportRowExportService.php:130-139` lists **all** of `imports/rows` (every tenant's artefacts share that one flat directory) and filters in PHP; `PurgeExpiredImportArtifactsCommand.php:52-59` calls it inside `lazyById(500)->each()`, i.e. once per expired job, per tenant — O(jobs × directory). Correctness is fine (the trailing `.` in the prefix `ImportRowExportService.php:143` makes UUID prefixes unambiguous, and no content crosses tenants), but the nightly purge degrades quadratically once orphans accumulate.

**Required fix (cheap, optional before merge):** make the artefact `imports/rows/{jobId}/{token}.{format}` and let `deleteArtifacts()` be `deleteDirectory('imports/rows/'.$job->id)`. Bounded listing, same semantics, and the purge test only changes its path strings.

### [MINOR] N-5 — two writers for the downloaded filename; the server's is dead for the web client

`ImportController.php:875` builds `{original_filename}-rows-to-fix.{format}` for `Content-Disposition`, but `ImportCorrectionActions.tsx:125` passes `import-${jobId}-rows-to-fix.${format}` to `authenticatedDownload`, and `apps/web/src/lib/api.ts:451` uses the caller's name unconditionally (`link.download = filename ?? …`). The operator gets a UUID-named file; the server's (better) name is never seen except by curl. Convention 11 "one surface per concept" reading: one name, two writers.

**Required fix:** drop the second argument in `ImportCorrectionActions.tsx:125` and have `authenticatedDownload` prefer the response's `Content-Disposition` filename, **or** delete the server-side `sprintf` and own the name on the client. Either way, one writer.

---

## Empirical leg

PostgreSQL **native @ 127.0.0.1:5432** (credentials read from the MAIN checkout `apps/api/.env`, not stored here; the worktree `.env` points at the lane's stopped 5433 container), `DB_DATABASE=DB_CENTRAL_DATABASE=autoerp_test_y`, config `phpunit-pgsql.xml`, **one class per invocation, serially, no full suite, no `--parallel`** (another agent holds Vitest).

```
PG  Tests\Unit\Import\ImportJobOutcomeTest                 OK  9 tests, 16 assertions   1.61s
PG  Tests\Feature\Import\ImportRowExportTest               OK 16 tests, 100 assertions 19.20s
PG  Tests\Feature\Import\ProcessImportJobStatusTest        OK  7 tests, 29 assertions  11.26s
PG  Tests\Feature\Import\PurgeExpiredImportArtifactsTest   OK  7 tests, 42 assertions   9.60s
PG  Tests\Feature\Import\PartiesImportTypeTest             OK  7 tests, 38 assertions  11.05s
PG  Tests\Feature\Import\PartiesImportBalancesTest         OK  5 tests, 54 assertions   8.33s
```

Every count matches the handback's GREEN table exactly (`HANDBACK-IMP-1-2026-09-10.md:258-263`). (`ImportJobOutcomeTest` reports 538 pre-existing PHPUnit *deprecations* — a repo-wide baseline, not a lane regression.)

Static / generated:

```
phpstan analyse app/Modules/Import --level=8        [OK] No errors
php artisan typescript:transform                    Transformed 557 PHP types
git diff -- packages/shared/types/generated.d.ts    (empty)
```

N-1 probe (scratch class, deleted afterwards; worktree `git status` clean):

```
PG  test_scratch_missing_artefact_is_refused_with_a_code   OK (1 test, 2 assertions)   26.70s
PG  guard blinded (exists→true, size→42, path→vanished)    SCRATCH status without guard: 500
```

Not re-run by this gate: Playwright (private harness, containers stopped, browser profile held by another agent) and Vitest (concurrent agent). The six-scenario tail is accepted from the committed artefact `phase-b/browser-green-fixround2.txt`, whose scenario line numbers (`:163`, `:244`) match the committed spec.

---

## Merge-order note (for the integration owner)

- **Already in the lane, do not re-apply:** `apps/api/tests/feature-lane-manifest.json` carries the raise (`gated_ceiling 1254 → 1255`, `Import.classes 39 → 40`, new `imp1_note`) and `.github/workflows/ci.yml` carries **+2 names** in the `backend-test-pgsql --filter` allowlist (`ImportRowExportTest`, `ProcessImportJobStatusTest`; neither is on dev `89f39b93f`). Both are one-line/whole-key edits on files every other lane also touches — **expect conflicts and resolve as a UNION**, re-deriving `gated_ceiling` after the merge rather than taking either side.
- The Import **feature lane selector is a whole directory** (`./vendor/bin/phpunit tests/Feature/Import/`, `ci.yml:2264-2265`), and the lane is still PARKED behind `vars.SELF_HOSTED_RUNNER_READY`, so the `--filter` allowlist is the only live gate for the two names above. The new **unit** class `tests/Unit/Import/ImportJobOutcomeTest.php` needs no entry — `ci.yml:420` runs `--testsuite=Unit` wholesale.
- `packages/shared/types/generated.d.ts` (+1 field, +1 enum member): after any merge, re-run `php artisan typescript:transform` and expect an empty diff; do not hand-resolve that file.
- `apps/web/tools/i18n-completeness-baseline.json` is removal-only here (−6 entries); on conflict take the **union of removals**, never re-add.
- `docs/glossary.md` (+4 rows, from fix round 1) and `docs/modules/imports.md` remain append-style conflict surfaces.
- The cherry-picked dev commit `477c877a3` (`ArApOpeningService`) is patch-id-identical to lane `fcc7a8c3f` (proved in gate r2) and deduplicates on merge.

## What to fix before merge

Nothing blocking. Land the two cheap tests — the `correction_export_unavailable` guard (**N-1**, recipe and green run above) and the cross-tenant `import_not_found` refusal (**N-2**) — and change `DEFAULT_PER_PAGE` to a value the pagination control actually offers (**N-3**); N-4/N-5 can ride the follow-up ticket.
