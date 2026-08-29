# Lane F1 — adversarial gate r3 (imports-reviewer), after fix round 2

**VERDICT: MERGEABLE — 0 × P1, 0 × P2, 4 × P3 (all follow-ups, none blocking). Both r2 blockers are closed with real pins; the round-2 diff introduces no new correctness defect.**

- Branch `fix/f-bug-1-import-location` @ `f0e25441c` (fix round 2) on `6f36f777f` (round 1) on `afd2aaf71`, merge-base `dev` = `a4ceeb0f5`.
  (`git diff dev` is misleading here — `dev` has advanced to `b4f6aedc5`; the lane diff is `git diff a4ceeb0f5..HEAD`, round 2 alone is `git diff 6f36f777f`: 6 files, +114/−30.)
- r2 record: `docs/superpowers/reviews/2026-08-29-f1-import-location-gate-r2-imports.md`. Fix brief: `docs/sessions/session-F-testing-2026-08-29/LANE-F1-FIX-ROUND-2.md`.
- Every claim below was read at the cited line in this worktree.

---

## Commands run

| Command | Result |
|---|---|
| `phpunit tests/Feature/Import/ProductsImportPipelineTest.php tests/Feature/Company/Migrations/BackfillDefaultLocationCodeF1MigrationTest.php` | **OK 30 tests / 281 assertions** |
| `phpstan analyse --no-progress app/Modules/Import` | **[OK] No errors** (r2 P1-R1 red is gone) |
| `phpstan analyse app/Modules/Company/Presentation/Controllers database/migrations/tenant/2026_08_29_100000_*.php` | **[OK] No errors** |
| `phpstan analyse` (FULL `app/`, level 8, memory 2G) | **3 errors — all INHERITED**, in files this lane does not touch: `app/Console/Commands/…and.php:142,203` and `app/Modules/BatchExpiry/Notifications/CriticalBatchExpiryNotification.php:44` (`Cannot call method toDateString() on Carbon\Carbon\|null`). Neither file nor `phpstan-baseline.neon` appears in `git diff a4ceeb0f5 --name-only`, so this is base-state red, not lane red. **Recorded as an inherited-red waiver — the lane itself is PHPStan-clean.** |
| `pint --test` (ImportController, ImportRow, ProductsImportPipelineTest) | `{"result":"pass"}` |
| `php tools/feature-lane-manifest-check.php` | **pass** — "every `--filter` entry is anchored and uniquely matched against 1857 test classes" (this is the machine check the orchestrator asked for on the ci.yml edit) |
| `pnpm vitest run src/features/import` | **10 files / 58 tests passed** |
| `pnpm typecheck` | clean |
| `pnpm lint` | NOT RUN (per instruction) |

Stray vitest workers: none left (`ps aux \| grep '[v]itest'` → 0 after `pkill -9 -f vitest`).

---

## r2 findings — disposition

### P1-R1 — `ImportRow` PHPDoc loosening → PHPStan red in `ResultWorkbookService` — **CLOSED**
- Docblock reverted to the strict shape: `apps/api/app/Modules/Import/Domain/ImportRow.php:19` `@property list<array{code: string, detail: string}>|null $warnings`.
- The defensiveness moved to the reader instead: `ImportController.php:707` reads through `$row->getAttribute('warnings')` (returns `mixed`, so the guards are not dead code under the strict docblock), `:709` rejects a non-array/empty `warnings`, `:714-716` skips a non-array element, `:718-721` skips a `code` that is not a non-empty **string**.
- `phpstan analyse app/Modules/Import` → **No errors**, which per the r2 evidence includes `ResultWorkbookService.php:119-120`. Full-`app/` phpstan carries only the 3 inherited errors above.
- Pinned by data, not by assertion-free happy path: `ProductsImportPipelineTest.php:250-261` seeds one row whose `warnings` JSON carries a good `price_conflict` pair **plus** `{"detail":…}` (no code), a bare scalar `'Legacy scalar warning'`, `['code' => '', …]` and `['code' => 42, …]`; `:281` asserts the whole summary is exactly `['price_conflict' => 1]` — i.e. all four malformed shapes are skipped and nothing throws.

### P2-R2 — failed completion refetch permanently stalls the wizard — **CLOSED**
- `ImportWizardPage.tsx:483-492`: both settlement paths now call `enterCompleteStep()` — `:484-488` on `result.isError` (single `console.error`, then complete) and `:489-492` on rejection (single `console.error`, then complete). `terminalTransitionJobsRef` is **no longer deleted** on failure (`:472` is the only mutation), so the guard cannot be re-entered and there is no double transition; `enterCompleteStep` still respects `isWizardMountedRef` (`:474`).
- The replacement test reproduces the exact r2 stall shape and is not the false-confidence test r2 called out: `ImportWizardPage.options.test.tsx:361` sets `nextJobData = completedJob` **before** the WS `updateProgress` (so `apiJobData.status` is already `'completed'`, the dep that could never change again), rejects the refetch once, and asserts `wizard.complete.title` renders plus exactly one `console.error('Import wizard: final job refetch failed', refetchError)`.
- Residual (P3-R9 below): the fall-through lands on the complete step with the pre-finalize payload.

### P3-R3 — the `failed` branch is never refetched — **CLOSED**
`ImportWizardPage.tsx:482` now gates on `realtimeProgress?.status === 'completed' || realtimeProgress?.status === 'failed'`. Pinned by `ImportWizardPage.options.test.tsx:424` "refetches a failed job before transitioning to complete", which holds the refetch promise open, asserts `wizard.complete.title` is **absent**, releases it, then asserts it renders — a real ordering assertion.
Backend ordering re-verified for the failed path too: `ProcessImportJob.php:196-201` writes the terminal status **before** `broadcastCompleted` at `:204` (and the same order on the error paths at `:280-291` / `:317-328`), with no transaction wrapping — so a WS-triggered refetch always hits `show` after the status write.

### P3-R4 — unbounded warning-row hydration on the 2 s `show` poll — **CLOSED for the hot path (residual P3-R8)**
`ImportController.php:672-674` `'warning_summary' => $withWarningSummary && $job->status->isTerminal() ? … : null` (`ImportStatus::isTerminal` = Completed|Failed, `ImportStatus.php:22-25`). The per-poll cost during `importing` is now only `countWarningRows` (a COUNT, `:686-696`). Pinned at `ProductsImportPipelineTest.php:262,274` (an `Importing` job's `show` returns `warning_summary: null`) and `:277-282` (the same job flipped to `Completed` returns the map).

### P3-R7 — the migration test runs in no CI lane — **CLOSED**
`.github/workflows/ci.yml:1087` adds `BackfillDefaultLocationCodeF1MigrationTest` to the `backend-test-pgsql --filter` allowlist (between `FiscalPeriodCloseEndpointTest` and `StagedDeploymentBootTest`), with the precedent-matching rationale comment at `:966-970`. That job is **live**, not parked: `ci.yml:588` `if: … || github.base_ref == 'dev' || …` on `ubuntu-latest` — unlike `feature-lane-tenancy` (`:1976`), which is gated on `vars.SELF_HOSTED_RUNNER_READY`. `feature-lane-manifest-check.php` passes and explicitly certifies every `--filter` entry as anchored and uniquely matched, and `grep -c` finds the name exactly twice (comment + filter). The S-17 caveat ("CI has not been observed green on this branch") is stated in the comment and remains true — see P3-R10.

### Carried forward unchanged (not in the round-2 brief)
- **P2-2 per-row shelf codes still win** — `ProductOpeningStockPhase.php:246-248` and `ImportType.php:199` untouched; **owner-deferred to Session G by the brief's "Do NOT do" section**. Not a merge blocker, but it is still the original F-BUG-1 field failure mode; the (now working) completion panel is the only mitigation.
- **P3-3 `KNOWN_WARNING_CODES` gaps** — `ImportWizardPage.tsx:48-56` unchanged; `margin_without_cost`, `opening_exists`, `category_restored`, `expiry_in_past`, `balance_not_posted` still fall to `warnings.other` (visible fallback, `:1198`).
- **P3-4 backend half of the inactive-location filter** — `LocationService::findIdByCode` still has no `is_active` filter.
- **P3-R5 the PG branch of `countWarningRows`** (`ImportController.php:696`) is still exercised by nothing (SQLite suite).

---

## New findings in round 2

### P3-R8 — `ResultWorkbookService::formatWarnings` is now the ONLY reader that does not tolerate the malformed warnings round 2 says are possible
`apps/api/app/Modules/Import/Services/ResultWorkbookService.php:112-124`:
```php
static fn (array $warning): string => sprintf('%s: %s', $warning['code'], $warning['detail'])
```
Under `declare(strict_types=1)` (`:3`), the scalar element that round 2's own test writes into the DB (`ProductsImportPipelineTest.php:256` `'Legacy scalar warning'`) would raise a **TypeError** here — the operator's result-workbook download fails outright — and a `{"detail": …}` element emits `Undefined array key 'code'` and writes `": detail"` into the sheet. Round 2 chose brief option (a) (revert the docblock, guard the controller), which is legitimate and makes PHPStan honest, but it leaves the two readers of the same column on opposite defensiveness policies.
Reachability is genuinely narrow: `ImportService::addRowWarning` (`ImportService.php:93-98`) is the only writer and always writes two strings; `warnings` is not user-supplied (only `data` is). So this needs legacy/hand-written rows — exactly the population the new test simulates.
**Fix (2 lines):** `$warning['code'] ?? 'warning'` / `$warning['detail'] ?? ''` behind an `is_array($warning)` guard, or type the closure `mixed`.

### P3-R9 — after the fall-through, the completion panel can render "N rows completed with warnings" above an EMPTY list, and nothing ever re-fetches it
This is the interaction of round-2 items 2 and 3, and it is not a legacy-data case:
1. WS says `completed`; the last successful poll of `show` happened while the job was still `importing`, so that cached payload has `warning_rows > 0` (the COUNT runs on every poll, `ImportController.php:671`) and `warning_summary: null` (the new `isTerminal` gate, `:672-674`).
2. The final refetch fails → `ImportWizardPage.tsx:488/491` enters the complete step with that stale payload.
3. `ImportWizardPage.tsx:1183` renders the section on `warning_rows > 0`, `:1189` prints the count, and `:1192` maps `warning_summary ?? {}` → the `<ul>` at `:1191-1201` is empty.
4. Nothing repairs it: `refetchInterval` is turned off by `setIsImporting(false)` (`:478`), and `apps/web/src/lib/queryClient.ts:6-8` sets `staleTime: 5 min` + `refetchOnWindowFocus: false`. The operator is told there are warnings and is shown none, for the rest of the session.
Impact is bounded — counts are honest, and the "View results" workbook download sits right below at `:1205-1210` — and the brief pre-approved the fall-through ("the panel simply shows whatever it has, or nothing"). **Cheapest fix:** render a fallback `<li>` ("details unavailable — download the result workbook") when the summary is empty but `warning_rows > 0`, or attempt one delayed retry before falling through.

### P3-R10 — the newly allowlisted migration test has still never been observed green on PostgreSQL
`BackfillDefaultLocationCodeF1MigrationTest` now runs under `phpunit-pgsql.xml` (its `<testsuite name="Feature">` includes `tests/Feature`, so selection is real). It was verified here on **SQLite only**. Two PG-specific surfaces are untested locally: the `->cursor()` at `2026_08_29_100000_backfill_default_location_code_f1.php:36` interleaved with `UPDATE`s on the same connection (fine for buffered PDO pgsql, but unproven), and the `updated_at` string comparisons at `BackfillDefaultLocationCodeF1MigrationTest.php:51-58,71-78`. The ci.yml comment states the S-17 caveat honestly; this is a note for the first CI run, not a code defect.

### P3-R11 — the "rows with warnings" headline can still exceed the sum of the per-code list (r2 P3-R6, restated)
`countWarningRows` (`ImportController.php:686-696`) counts any row with a non-empty `warnings` array; `warningSummary` (`:714-721`) drops non-array / codeless / non-string-code / empty-code elements. A row carrying **only** malformed entries is counted in the headline and contributes nothing to the list. Round 2 made this deliberate and pinned the mixed case (`warning_rows: 1` + `price_conflict: 1`); the all-malformed row remains inconsistent. Legacy-only, cosmetic. Same fallback-`<li>` fix as P3-R9 covers the visible symptom.

---

## Verified correct in round 2 (do not re-litigate)

- **`isTerminal` gating does not starve any consumer.** `warning_summary` was introduced by this lane (`git show a4ceeb0f5:…ImportController.php` has `warning_rows` only, no `warning_summary`), and the sole reader anywhere in `apps/web` / `apps/pos` is the complete step (`ImportWizardPage.tsx:1192`), which is only reachable on a terminal status. `types.ts:59` is `Record<string, number> | null` and the read is `?? {}`-safe.
- **The synchronous `execute` response still carries the summary.** `ImportService::executeImport` writes the terminal status at `ImportService.php:381-387` **before** returning, and `ImportController.php:527` re-reads `$job->fresh()` after it, so `formatJob($freshJob, true)` passes the `isTerminal` gate. The sync wizard path additionally invalidates the detail query (`api/queries.ts:143-154`) and goes straight to `complete` (`ImportWizardPage.tsx:665-669`), so it refetches `show` on an already-terminal job — warnings surface on the <100-row path the team actually tests.
- **Post-WS refetch ordering.** `ProcessImportJob.php:186` finalize → `:196-201` status write → `:204` broadcast, un-transacted. Any refetch triggered by the WS event necessarily observes the terminal status and therefore the finalized summary.
- **No double transition / no leaked guard.** `terminalTransitionJobsRef` is added once at `:472` and never deleted; the effect body is gated on `currentStep === 'execute'` (`:469`) and on the ref (`:470`); `refetchJob().then(onFulfilled, onRejected)` runs exactly one branch, so exactly one `console.error` and one `enterCompleteStep()`. A hung refetch cannot stall forever either — `apps/web/src/lib/api.ts:240` sets `timeout: 30000`.
- **`getAttribute('warnings')` is not a behaviour change** — it returns the same cast array as `$row->warnings`; it only widens the static type so the new guards are analysable.
- **Precision contract clean.** Round 2 touches no money/quantity path: no `(float)`, `parseFloat`, `Number(...)`, no scale resolution, no bare no-arg `getScale()`. Opening-stock posting is untouched by this commit.
- **Gating untouched.** No new import route; `ImportServiceProvider.php:67` still carries `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'can:imports.manage']`.
- **Tests assert behaviour, not payload echoes.** The two new/rewritten wizard tests assert rendered headings and ordering around a controlled promise; the backend test asserts the summary map across a status transition with adversarial JSON.

---

## What to fix before merge

Nothing blocks. Take P3-R8 (2-line guard in `ResultWorkbookService::formatWarnings`) and P3-R9 (fallback `<li>` when `warning_rows > 0` but the summary is empty) as a cheap follow-up, and keep the owner-deferred P2-2 (per-row shelf codes silently import zero stock) at the top of the Session G list — it is still the original F-BUG-1 field symptom.
