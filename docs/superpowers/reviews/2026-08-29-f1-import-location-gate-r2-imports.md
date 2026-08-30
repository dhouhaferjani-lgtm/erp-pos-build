# Lane F1 — adversarial gate r2 (imports-reviewer), after fix round 1

**VERDICT: CHANGES — 1 × P1, 1 × P2, 6 × P3. One-line blocker: the `ImportRow` PHPDoc loosening turns full-repo PHPStan level 8 RED (2 unbaselined errors in an untouched file).**

- Branch `fix/f-bug-1-import-location` @ `6f36f777f` (fix round 1) on `afd2aaf71` (r1 subject), base `dev` = `a4ceeb0f5`.
- Diff reviewed: `git diff dev...HEAD` — 24 files, +1002/−38. Every claim below was read at the cited line in this worktree.
- r1 record: `docs/superpowers/reviews/2026-08-29-f1-import-location-gate-r1-imports.md`. Fix brief: `docs/sessions/session-F-testing-2026-08-29/LANE-F1-FIX-ROUND-1.md`.

---

## Commands run

| Command | Result |
|---|---|
| `phpunit tests/Feature/Company/CreateCompanyTest.php tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php` | OK 17 tests / 95 assertions |
| `phpunit tests/Feature/Import/ProductsImportPipelineTest.php tests/Feature/Company/LocationListEndpointsTest.php` | OK 39 tests / 302 assertions |
| `phpstan analyse app/Modules/Import app/Modules/Company/Presentation/Controllers/LocationController.php database/migrations/tenant/2026_08_29_100000_*.php` | **2 ERRORS** — see P1-R1 |
| `pint --test` (Import module + touched files) | pass |
| `php tools/feature-lane-manifest-check.php` | OK — 1457 classes / 74 groups; gated ceiling raised 1197→1198 |
| `pnpm vitest run src/features/import src/features/locations` | 14 files / 71 tests passed |
| `pnpm typecheck` | clean |
| `eslint` (5 changed web files) | **0 errors**, 20 warnings — all pre-existing; the r1 P3-9 warning is gone |
| `pnpm lint` | NOT RUN (box saturated, per orchestrator instruction) |
| `audit-i18n-completeness` | not re-run — no locale change in the fix commit |

Stray vitest workers killed (`pkill -9 -f vitest`, verified 0 remaining).

---

## r1 findings — disposition

### P1-1 nullable `location.code` crashes the options step — **CLOSED**
Fixed at the API boundary, which is the right layer:
- `apps/web/src/features/locations/api/locations.ts:11` `code: string | null` on `RawLocation`, `:41` on `RawTransactionLocation`, `:52` `code: raw.code ?? ''`, `:86` same for `getTransactionLocations`.
- `apps/web/src/features/locations/api/scopedLocations.ts:16` `code: string | null` raw, `:29` `code: row.code ?? ''`.
- `apps/web/src/features/locations/hooks/useManagementLocations.ts:13,31` same.
- The three `.trim()` sites now consume a guaranteed string: `ImportWizardPage.tsx:159, 286, 290, 296`.
- Pinned: `locations.test.ts` — "normalizes a nullable location code at the API boundary" (`:79-85`), the transaction variant (`:87-99`), and the scoped mapper asserting `code: ''` from `code: null` (`:101-133`); wizard case "uses scoped company locations, disables a null code, and excludes inactive locations" feeds `code: null` and asserts the option is `toBeDisabled()`.
- Corroboration that the type is no longer a lie: the `@typescript-eslint/no-unnecessary-condition` warning r1 saw at the old `:288` is gone from the eslint run above (P3-9 **CLOSED**).
- Bonus: two other latent crash sites are incidentally fixed, because both read through `mapLocation` — `apps/web/src/components/ui/LocationField.tsx:65` and `apps/web/src/features/locations/components/LocationSelectorMulti.tsx:91` (`.code.toLowerCase()`).

### P1-2 async imports never show warnings — **CLOSED**
`ImportWizardPage.tsx:463-497`: on a terminal status the transition is now guarded by `terminalTransitionJobsRef` (`:322`, `:472`), and when the terminal signal came from the WebSocket (`realtimeProgress?.status === 'completed'`, `:483`) it `refetch()`es the job (`:484`) and only enters the complete step in the resolve handler (`:490`).

I verified the backend ordering this depends on: `ProcessImportJob.php:186` `finalizeImport(...)` runs **before** the status write at `:194-200` and before `broadcastCompleted` at `:203`. So any response that reports `status: completed` — refetch or poll — necessarily carries the finalized `warning_summary`. The fix is correct for the right reason, not by luck.

Pinned by `ImportWizardPage.options.test.tsx` "refetches the finalized job before showing WebSocket completion warnings": it holds the refetch promise open, asserts the warnings heading is absent, releases it, then asserts `wizard.complete.warnings` + `warnings.location_unresolved` render. That is a real assertion of the *post*-refetch payload.

### P1-3 `warningStats` on the 20-job list — **CLOSED**
- `ImportController.php:660` `formatJob(ImportJob $job, bool $withWarningSummary)`; `:65` list passes `false`; all eight single-job sites pass `true` (`:187, 206, 215, 239, 402, 538, 569, 825`). I enumerated every call site — 9 total, exactly one `false`. Correct.
- `:671` `warning_rows` goes back through `countWarningRows` (`:684-695`), which is **byte-identical to the version on `dev`** (`git show dev:…ImportController.php:681-691`) — a COUNT with the sqlite/pgsql split, no hydration. Zero regression surface.
- `:672` `warning_summary` is `null` on the list; `apps/web/src/features/import/types.ts:59` widened to `Record<string, number> | null`; the only reader is `ImportWizardPage.tsx:1192` `Object.entries(jobData?.warning_summary ?? {})` — null-safe. Grep confirms no other consumer in `apps/web` or `apps/pos`.
- Pinned: `ProductsImportPipelineTest::test_import_list_omits_warning_summary_while_show_counts_rows_per_code` asserts `data.0.warning_summary` is `null` on the list and `data.warning_summary.price_conflict === 1` on `show`.

### P2-1 green path unpinned — **CLOSED, and pinned properly**
`ProductsImportPipelineTest::test_products_import_uses_job_location_option_when_rows_have_no_location_code` uploads `name,sku,type,quantity,purchase_price` with **no** `location_code` column, sets `options.location_code = 'MAIN'`, executes, and asserts a real `MovementType::Opening` `StockMovement` with `location_id === $this->location->id` **and** `StockLevel.quantity === '3.0000'` at that location. `$this->location` is the `code: 'MAIN'` warehouse created in `setUp` (`:98-101`). The sibling `test_per_row_location_code_overrides_the_job_location_option` asserts the movement lands on `BRANCH` and that `StockLevel` count at MAIN is `0`. Both are behaviour assertions on real models, not payload echoes. This is the pin that was missing.

Precedence re-verified in the code under test: `ProductOpeningStockPhase.php:246-248` prefers `$data['location_code']` and falls back to `$options['location_code']`.

### P2-2 per-row shelf codes still win — **NOT CLOSED (deferred by the brief to Session G)**
`ProductOpeningStockPhase.php:246-248` unchanged; `ImportType.php:199` still validates `location_code` as a bare `nullable|string|max:100` with no resolvability check. The team's staging file (mapped `location_code` full of shelf codes `ZF1161`/`ZF625`) will still import zero stock; the completion panel is the only mitigation — and that panel now works on async jobs (P1-2), so the deferral is at least survivable. **Carry forward to Session G.**

### P2-3 occurrences vs rows — **CLOSED**
`ImportController.php:704-720`: per row, codes are collapsed into `$rowCodes[$code] = true` (`:715`) and the summary is incremented once per distinct code per row (`:718-720`). The eight locale strings that say "rows" (`apps/web/src/locales/en/import.json` `warnings.*`) are now truthful. The new backend test proves it: a row carrying two `price_conflict` entries yields `price_conflict: 1`.

### P3s
- **P3-1 vacuous idempotency assertion — CLOSED.** The migration now writes `updated_at` (`2026_08_29_100000_backfill_default_location_code_f1.php:64-67`), so the assertion has teeth: the test proves `updated_at` *does* change on the first run (`BackfillDefaultLocationCodeF1MigrationTest.php:51-54`), that the non-default row is untouched (`:55-58`), and that a re-run leaves both timestamps identical (`:69-78`). Docblock added (`:13-17`).
- **P3-2 CI selection — STILL OPEN**, see P3-R7 below.
- **P3-3 `KNOWN_WARNING_CODES` gaps — STILL OPEN.** `ImportWizardPage.tsx:48-56` unchanged; `category_restored`, `margin_without_cost`, `opening_exists`, `expiry_in_past`, `expiry_ignored_*`, `balance_not_posted` still fall to `warnings.other`. Not in the brief; visible via the fallback.
- **P3-4 inactive locations — HALF CLOSED.** The picker now excludes them (`ImportWizardPage.tsx:264-267`, pinned by the "excludes inactive locations" case). The backend half is untouched: `LocationService::findIdByCode` still has no `is_active` filter, so a per-row `location_code` naming a deactivated location still posts opening stock into it.
- **P3-5 / P3-6 / P3-7 / P3-8 / P3-10 — STILL OPEN**, all pre-existing or out of the brief's scope. The migration `echo` (`:52`) is still there and the test still depends on it.

---

## New findings in the fix

### P1-R1 — the `ImportRow` PHPDoc loosening breaks PHPStan level 8 in an untouched file

`apps/api/app/Modules/Import/Domain/ImportRow.php:19` changed
`list<array{code: string, detail: string}>|null` → `list<array{code?: string, detail?: string}>|null`
to make `$warning['code'] ?? ''` (`ImportController.php:711`) type-clean. It does — and it makes an unrelated file red:

```
app/Modules/Import/Services/ResultWorkbookService.php
  119  Offset 'code' might not exist on array{code?: string, detail?: string}.   offsetAccess.notFound
  120  Offset 'detail' might not exist on array{code?: string, detail?: string}. offsetAccess.notFound
```

- `phpstan.neon:6-8` analyses all of `app/` at level 8; `grep ResultWorkbookService phpstan-baseline.neon` → **not baselined**. `./vendor/bin/phpstan` and therefore `./scripts/preflight.sh` and CI are RED on this branch.
- The fix commit message claims "PHPStan clean on touched files" — true and irrelevant: `ResultWorkbookService.php` is not a touched file, which is exactly why it was missed.
- It is not only a lint problem. `ResultWorkbookService.php:112-124` reads `$warning['code']` and `$warning['detail']` unguarded, so a warning entry that genuinely lacks a key emits `Undefined array key` and writes `": detail"` into the result workbook — the operator's only feedback channel for a batch import. PHPStan is reporting a real hole that the loosened docblock just made visible.
- **Fix (either, not both):** (a) revert `ImportRow.php:19` to the strict shape and drop the now-unnecessary `?? ''` at `ImportController.php:711` — `ImportService::addRowWarning` (`ImportService.php:95-97`) is the only writer and always sets both keys; or (b) keep the defensive shape and guard `ResultWorkbookService::formatWarnings` (`$warning['code'] ?? 'warning'`, `$warning['detail'] ?? ''`). (b) is the better shape for legacy rows, but it must be done, not left red.

### P2-R2 — a failed completion refetch can permanently stall the auto-transition, and leaves the 2 s poll running forever

`ImportWizardPage.tsx:483-495`. On refetch failure the code releases the guard (`:487`, `:493` `terminalTransitionJobsRef.current.delete(jobId)`) and returns, relying on the effect re-firing. The effect's deps are `[realtimeProgress?.status, apiJobData?.status, currentStep, jobId, markStepCompleted, refetchJob]` (`:497`) — `markStepCompleted` is a stable `useCallback` (`:387`) and `refetchJob` is react-query-stable, so the **only** thing that can retrigger it is one of the two status strings changing.

Failure scenario (this is the normal WebSocket-down path, which the code itself calls the fallback at `:329`):
1. The 2 s poll returns `status: 'completed'` → `apiJobData?.status === 'completed'`.
2. The local sync effect (`:445-465`) calls `completeImport(...)`, which sets the progress store to `completed` → `realtimeProgress?.status === 'completed'`.
3. The terminal effect now takes the **refetch** branch (`:483`, because `realtimeProgress?.status === 'completed'`), even though nothing came off the wire.
4. The refetch returns `isError` (transient 5xx/offline blip — or a 500 from `show` itself on a large job, see P3-R4). The guard is released.
5. Neither `realtimeProgress?.status` nor `apiJobData?.status` will ever change again (both are already the string `'completed'`; `apiJobData` object identity churns on each poll but the dep is the string). **The effect never re-runs. The wizard sits on the execute step indefinitely.**

Consequences: `setIsImporting(false)` never runs, so `refetchInterval: 2000` (`:331`) polls `GET /imports/{id}` — which now hydrates every warning row (`ImportController.php:704`) — every two seconds for as long as the tab is open. Not P1 because there is a visible escape: `:1135-1143` renders a "View results" button whenever `jobData.status === 'completed'`, and the polled payload is finalized and correct, so the operator loses the automatic navigation, not the data.

The new test "keeps the execute step after a failed final refetch and retries before completion" does **not** cover this: it retriggers the effect by flipping the mocked `nextJobData` from `status: 'validated'` to `status: 'completed'` between the two `updateProgress` calls, i.e. it only exercises the branch where `apiJobData.status` had not yet reached the terminal value. It gives false confidence in the retry.

**Fix:** make the retry independent of the deps changing — e.g. on `isError`, `setIsImporting(true)` is already true, so instead schedule an explicit retry (`setTimeout`/`refetchInterval` continuation) or simply enter the complete step anyway on refetch failure (the polled `apiJobData` is finalized in this branch, so the pre-fix behaviour is correct here) rather than stalling. Add a test where the stale job already reports `status: 'completed'` before the WS event.

### P3-R3 — the `failed` branch is never refetched
`ImportWizardPage.tsx:483` gates the refetch on `realtimeProgress?.status === 'completed'`; a `failed` terminal status takes the `else` at `:494` and transitions immediately. Because `finalizeImport` also runs on the failed path (`ProcessImportJob.php:186`, before the `ImportStatus::Failed` write at `:194-200`), the warning panel — which renders on any `warning_rows > 0`, `ImportWizardPage.tsx:1183` — shows counts from the last mid-loop poll. Impact is small (a job is only `Failed` when `importedCount === 0`, i.e. the finalize phases have almost nothing to warn about), but the asymmetry is unnecessary: refetch on both terminal statuses.

### P3-R4 — the unbounded warning-row hydration moved, it did not shrink
`ImportController.php:704` `$job->rows()->whereNotNull('warnings')->get(['warnings'])` still hydrates every warning row, and it now runs on `show`, which the wizard polls **every 2 seconds** during execution (`ImportWizardPage.tsx:331`) and once more on completion. r1 measured 75 MB of decoded warning arrays alone for a 100k-row job before Eloquent hydration. The brief only asked for the list, and the list is fixed — but the per-job path is now the hot one. Bound it with the grouped PG query (`jsonb_array_elements` + `GROUP BY`) r1 suggested, or compute the summary only when `status` is terminal.

### P3-R5 — the PG branch of `countWarningRows` is exercised by nothing
`ImportController.php:694` `whereRaw('jsonb_array_length(warnings) > 0')` only runs on `pgsql`; the whole Feature suite is SQLite, so `:686-691` is the only branch under test, and the new list/show test pins only that one. This is restored-from-`dev` code so the risk is low, but it is precisely the SQLite-masks-PostgreSQL shape: an aggregate whose production driver is never executed in CI.

### P3-R6 — a row whose warnings all lack `code` inflates the headline with an empty list
`countWarningRows` counts any row with a non-empty `warnings` array; `warningSummary` skips entries with no `code` (`:712-714`). A legacy row carrying only codeless warnings therefore contributes to `wizard.complete.warnings` ("N rows completed with warnings") while adding nothing to the `<ul>` below it. Cosmetic, legacy-only.

### P3-R7 — the new migration test still runs in no CI lane (r1 P3-2, restated with the fix's own evidence)
`apps/api/tests/feature-lane-manifest.json` raises `gated_ceiling` 1197→1198 and `Company.classes` 33→34, and the new note says outright: "the parked tenancy lane does not execute it until SELF_HOSTED_RUNNER_READY is enabled". Its two siblings named in the same note (`FiscalPeriodCloseEndpointTest`, `FiscalPeriodReopenEndpointTest`) were each added to the `backend-pgsql --filter` allowlist *for exactly this reason*; `BackfillDefaultLocationCodeF1MigrationTest` was not. The MAIN backfill that every tenant will run therefore has no executing guard. Cheap to fix; follow the precedent the note itself cites.

---

## Verified correct (do not re-litigate in r3)

- **The wizard's location source is genuinely ungated.** `useScopedLocations.ts:15` `enabled: Boolean(tenantId && companyId)` — **no permission predicate**; that gate lives only on `useManagementLocations.ts:37` (`users.manage_location_access`), which the wizard does not use. The endpoint behind it, `Route::get('company/locations', …'scopedIndex')` (`apps/api/app/Modules/Company/routes.php:95-96`), carries only the group chain `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`:24`) — no `can:`, no `module:`. Contrast the endpoint it replaced: `GET /locations` sits behind `module:Inventory` **and** `can:inventory.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:30,33-35`). The tenancy P1/P2 is closed, and there is **no** regression of that class. Pinned by `expect(mockApiGet).toHaveBeenCalledWith('/company/locations')`.
- **`is_active` on `scopedIndex` is additive and complete.** `LocationController.php:123` adds it to `pickerPayload`, which serves **both** `company/locations` and `company/locations/all` (`:41`, `:57`) — so `useManagementLocations.ts:34`'s new `isActive` read is actually fed, not `undefined`. Grep finds exactly two web consumers of these two routes (`scopedLocations.ts:23`, `useManagementLocations.ts:27`), both updated; no POS consumer; no OpenAPI fixture references the path. `LocationListEndpointsTest::pickerRow` updated and green.
- **Scope parity across the endpoint swap.** `scopedIndex` → `LocationScopeResolver::resolve` → `LocationContext::getAllowedLocationIds` (`LocationScopeResolver.php:33,57-66`), the same membership scoping the old `index` applied (`LocationController.php:88`). A location-restricted operator still cannot open stock into a location they are not assigned to.
- **Empty-picker path is safe.** `stockLocationCode` returns `''` when no coded active location exists (`ImportWizardPage.tsx:287-289`) and the PATCH omits `location_code` entirely in that case (`:613-615`), so no empty string reaches `'options.location_code' => ['sometimes','string','max:100']`.
- **Precision contract clean.** No `(float)`, `parseFloat`, `Number(...)` on money/quantity anywhere in the fix diff. Opening stock still formats through `CurrencyScale::bcformatStrict` at quantity scale 4 / company-currency scale, with the scale taken from the explicit company currency — queue-safe, no bare no-arg `getScale()`.
- **Gating untouched.** No new import route; `ImportServiceProvider.php:67` still carries `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']`.
- **Backend suite green by path:** 56 tests / 397 assertions across the four named files. **Web suite green:** 71/71.

---

## What to fix before merge

Resolve the PHPStan level-8 red introduced by `ImportRow.php:19` — either revert the docblock and drop `?? ''`, or guard `ResultWorkbookService.php:119-120` (P1-R1) — and make the failed-refetch path retry or fall through instead of stalling the wizard on the execute step (P2-R2). P3-R7 (name the migration test in the `backend-pgsql --filter` allowlist) is a two-line follow-up worth taking in the same round.
