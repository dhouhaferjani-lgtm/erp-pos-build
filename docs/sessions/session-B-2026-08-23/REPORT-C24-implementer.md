Lane B2-5 / C-24(i) complete. Not merged.

## 1. Branch / worktree / commit

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-c24-period-close`
- Branch: `fix/sb2-c24-manual-period-close`, base `8bdff9e93`
- Commit: **`77984ddb3`** — `feat(sb2-c24): manual single-period close releases the auto-lock hold a reopen creates`
- Working tree clean. Nothing pushed. `origin/dev` untouched.
- Note: `dev` advanced by one commit during the lane (`f369f10be`, a handoff-doc-only commit); the branch is one behind and merges cleanly on that basis.

## 2. Red → green evidence (PG 5433, own DB `autoerp_c24_test`, by path, one file per run)

**RED — `tests/Feature/Company/FiscalPeriodCloseEndpointTest.php` on the base (no production code yet):**
```
Tests:    14 failed, 1 passed (19 assertions)
Duration: 32.47s
```
Every failure is `Expected response status code [200/422/403] but received 404` — the route does not exist. The one vacuous pass is `a period of another company is not reachable` (expects 404, gets 404 because nothing is routed); it becomes a real assertion once the route lands. The integration pin failed exactly where it matters:
```
FAILED  ... > reopen then…
Expected response status code [200] but received 404.
at tests/Feature/Company/FiscalPeriodCloseEndpointTest.php:365
  361▕  // 3. The human settles the correction through the NEW manual close.
  ➜ 365▕  ])->assertOk();
```
Steps 1–2 of that test (reopen, then `lockExpiredPeriods()` leaves the period Open and the fiscal year un-closed) **passed on the base** — i.e. the red output itself proves the "stays Open forever" state before the fix.

**GREEN — same file, after implementation:**
```
Tests:    15 passed (51 assertions)   Duration: 36.77s
✓ accountant can close an ended open period          ✓ it refuses when an earlier period is still open
✓ closing keeps the reopen history on the row        ✓ a closed predecessor does not block the close
✓ it refuses to close a locked period                ✓ it refuses to close a period that has not ended
✓ it refuses to close an already closed period       ✓ viewer / manager cannot close (403)
✓ it refuses when the fiscal year is closed          ✓ admin can close a period
✓ reason is optional and not persisted               ✓ a too short reason is rejected
✓ a period of another company is not reachable
✓ reopen then manual close releases the scheduler hold on the fiscal…
```

**Regressions (all by path, all green):**
- `tests/Feature/Company/FiscalPeriodReopenEndpointTest.php` — 12 passed (41 assertions)
- `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php` — 17 passed (39 assertions)
- `tests/Feature/Fiscal/LockExpiredFiscalPeriodsCommandTest.php` — 5 passed (15 assertions)
- `tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php` — 3 passed (19 assertions) — pins the regenerated map hash

**Other gates:** PHPStan level 8 on all five changed/new PHP source files → `[OK] No errors`. Pint `--test` clean on everything I touched. `php tools/feature-lane-manifest-check.php` → OK. deptrac 183 violations, **zero** naming any new class (only pre-existing `Accounting\Domain\Services\GeneralLedgerService → FiscalPeriodResolverService`).

## 3. Production changes (file:line)

| File | What |
|---|---|
| `apps/api/app/Modules/Company/Application/Services/FiscalPeriodCloseService.php` (new, 157 L) | `close()` :82 — `DB::transaction` + `lockForUpdate()` re-read; five typed refusals in order Locked / not-Open / year-closed / not-ended / predecessor-open (:112); writes `status`, `status_changed_from='open'`, `status_actor='user:<id>'`, `closed_at`, `closed_by`; clears nothing. `hasOpenPredecessor()` :149 mirrors reopen's `hasClosedOrLockedSuccessor` (`start_date <`, `status = Open`). `Log::info('Fiscal period closed', …)`. |
| `.../Domain/Enums/FiscalPeriodCloseRefusalCode.php` (new) | `FISCAL_PERIOD_CLOSE_{LOCKED,NOT_OPEN,FISCAL_YEAR_CLOSED,PREDECESSOR_OPEN,NOT_ENDED}` |
| `.../Domain/Exceptions/FiscalPeriodCloseRefusedException.php` (new) | Named factories carrying the enum + `fiscalPeriodId`; service named in prose only (deptrac) |
| `.../Presentation/Requests/CloseFiscalPeriodRequest.php` (new) | `reason` → `sometimes, nullable, string, min:3, max:500`; authorize on the route |
| `.../Presentation/Controllers/FiscalPeriodController.php` | ctor +`FiscalPeriodCloseService` :35; `close()` :86; extracted `payload()` :128 so **both** edges answer with the identical shape (reopen's response bytes unchanged — its 12 tests still green) |
| `apps/api/app/Modules/Company/routes.php:90-93` | `POST fiscal-periods/{id}/close`, `whereUuid('id')`, `can:fiscal-periods.close`, name `fiscal-periods.close` |
| `apps/api/database/seeders/RolesAndPermissionsSeeder.php:300` (catalogue), `:828` (accountant grant) | `fiscal-periods.close` |
| `apps/web/src/hooks/permissionsMap.generated.ts:83` | regenerated via `php artisan permissions:export-frontend-map`; hash `2afca599…` → `1fb08ab1…`. **The only web file touched.** |
| `apps/api/tests/feature-lane-manifest.json` | Company `classes` 32→33, `gated_ceiling` 1180→1181, with the deliberate-raise note |
| `.github/workflows/ci.yml` | `FiscalPeriodCloseEndpointTest` appended to the backend-pgsql `--filter` allowlist + its comment block |

**⚠ One step beyond the literal brief, flagged for the gate:** the brief named only the manifest under step 6, but `feature-lane-tenancy/Company` is PARKED, so without the `ci.yml` allowlist entry the new class runs in no CI job — and the manifest note the brief told me to write documents that entry (it is exactly what Q-10 did for the reopen sibling under gate C-3). I added it; revert that hunk if the gate disagrees. `ci.yml` is not a web file, so it does not breach the web-file restriction.

## 4. Migration

**No.** No schema change, no census, no fleet-abort risk.

**Deploy note (extends S-20):** re-run `RolesAndPermissionsSeeder` per tenant and reset the permission cache so `fiscal-periods.close` exists and is granted — otherwise `can:fiscal-periods.close` 403s for everyone including admin (admin holds it via `Permission::all()`, which is evaluated at seed time). Same shape as the S-20 step Q-10 already owes for `fiscal-periods.reopen`; the two can be done in one pass.

## 5. Judgement call kept, as instructed

**`PERIOD_NOT_ENDED` is IN.** `close()` refuses unless `end_date < Carbon::today()` — a period is closed after it ends; one ending today or later still accepts postings by definition. Pinned by `test_it_refuses_to_close_a_period_that_has_not_ended`. It is fully isolated: dropping it means deleting one `if` in `FiscalPeriodCloseService` (~:107), one enum case and one factory, and one test. Nothing else depends on it. There is no company-local-date model in this module (the scheduler compares against bare `Carbon::now()`), so app timezone is the boundary — stated in the docblock.

## 6. Residuals seen, NOT touched

1. **No `close_reason` column** — `reason` is validated and logged, never persisted (brief-mandated). An additive `close_reason` column is Slice-D-adjacent; the request already validates to the reopen column's bounds (3..500) so a future column can adopt the payload unchanged.
2. **C-24 (iii) no FE page** and **C-24 (iv) no domain event** stay open. The reopen has no domain event either; emitting one for only half the edge would be worse.
3. **`FISCAL_YEAR_CLOSED` is a genuine dead end for an Open period inside a closed year** — it can neither be closed manually nor reopened. Unreachable through the product today (STEP 2 holds the year close for exactly this row), so it can only arise from a seeded/imported `is_closed = true`. Kept per brief; worth a program-level look with the year-level reopen.
4. **Refusal messages are literal English, not `messages.*` keys** — inherited from the reopen family; no fiscal-period lang namespace exists. The CODE is the contract.
5. **Pre-existing Pint drift in `app/Modules/Company/Domain/Company.php`** (`unary_operator_spaces`, `not_operator_with_successor_space`, `phpdoc_align`) — outside my diff, left alone.
6. **Throwaway DB `autoerp_c24_test` left in place** on 127.0.0.1:5433 so the reviewer can re-run without a fresh migrate; drop it when the gate closes.

---

Micro fix round done. Not merged.

## SHA

**`371c8808f`** — `fix(sb2-c24): document the refusal precedence as contract and pin NOT_ENDED over PREDECESSOR_OPEN`, on top of `77984ddb3`, branch `fix/sb2-c24-manual-period-close`, worktree `.worktrees/sb2-c24-period-close`. Tree clean, nothing pushed.

## C-1 — precedence

Order kept as ruled: **LOCKED → NOT_OPEN → FISCAL_YEAR_CLOSED → NOT_ENDED → PREDECESSOR_OPEN**. It was already correct in code but undocumented and unpinned. Now stated in four places — `FiscalPeriodCloseService` docblock (numbered 1–5, with the rationale that a condition true of *this period alone* outranks one true only of its *relationship to other rows*), an inline comment at the two `if`s naming the pin, `FiscalPeriodCloseRefusalCode` (bullets reordered to evaluation order + an explicit "THE ORDER ABOVE IS THE EVALUATION ORDER" paragraph, and that a caller gets the *first* blocking reason, not the only one), and `FiscalPeriodController::close()` / `CloseFiscalPeriodRequest` (the latter also separating the `error.errors` malformed-body 422 from the `error.code` refused-transition 422).

**RED** (two `if`s temporarily swapped in place, no stash):
```
FAILED  FiscalPeriodCloseEndpointTest > a period that has not ended refuses on…
Failed asserting that two strings are identical.
-'FISCAL_PERIOD_CLOSE_NOT_ENDED'
+'FISCAL_PERIOD_CLOSE_PREDECESSOR_OPEN'
at tests/Feature/Company/FiscalPeriodCloseEndpointTest.php:289
Tests:    1 failed (4 assertions)
```
Four assertions ran, so both preconditions (`end_date >= today`, and an earlier still-Open period) were verified true before the refusal was read — the test cannot pass by one condition quietly lapsing. Predecessor taken from the *previous* fiscal year so the fixture also holds in January.

**GREEN** after restore — `Tests: 16 passed (56 assertions)`, including `✓ a period that has not ended refuses on not ended even behind an ope…`.

**Restore verified mechanically:** `git diff -U0` on the service, filtered to non-comment lines, is **empty** — this commit contains zero executable-line changes.

## Re-runs

| Gate | Result |
|---|---|
| `FiscalPeriodCloseEndpointTest` (PG, by path) | 16 passed / 56 assertions |
| `FiscalPeriodReopenEndpointTest` (PG, by path) | 12 passed / 41 assertions |
| PHPStan L8, 5 touched files | `[OK] No errors` |
| Pint `--test`, all touched files | clean (one auto-fix applied to `CloseFiscalPeriodRequest`: `fully_qualified_strict_types` + `ordered_imports`) |
| `tools/feature-lane-manifest-check.php` | OK — 33/1181, every `--filter` entry anchored and uniquely matched |

## C-2 — corrected residual list (supersedes the list in my previous report)

1. **No `close_reason` column** — `reason` validated (3..500) and logged, never persisted. Additive column is Slice-D-adjacent.
2. **C-24 (iii) no FE page; C-24 (iv) no domain event.** The reopen has no event either.
3. ~~`FISCAL_YEAR_CLOSED` is a permanent dead end~~ — **WITHDRAWN, my previous report overstated this.** `FiscalPeriodAutoLockService::lockPeriodsInClosedFiscalYears()` accepts `status IN (Open, Closed)` and excludes only `Open AND reopened_at IS NOT NULL`, so an Open period inside a closed year is swept `Open → Locked` on the next nightly run and **self-heals**. The single row that does not is `Open` *with* a `reopened_at` stamp inside a closed year — unreachable in-product, since STEP 2 holds the year close for exactly that shape.
4. **NEW, and the one that was missing: a Draft JE inside a manually closed period becomes permanently unpostable one nightly run later.** `FiscalPeriodResolverService:290` treats **Closed and Locked alike** as blocking, so the close blocks posting immediately; it stays recoverable through `fiscal-periods.reopen` only until the nightly run locks the period, after which the reopen refuses (`PERIOD_LOCKED`) and the draft can never post. Ruled acceptable on the Q-10 precedent — naming it, not fixing it.
5. **Gate I-2 — the oldest-first invariant is not lock-protected across rows.** `hasOpenPredecessor()` (`FiscalPeriodCloseService.php:149`) is an unlocked `exists()`; `lockForUpdate()` covers only the row being closed, so two concurrent closes of adjacent periods can each observe the other as settled. Fix idiom is `pg_advisory_xact_lock(hashtextextended(company_id, 0))` as at `GeneralLedgerService.php:3796`. **Not implemented** (also latent in the reopen's mirror `hasClosedOrLockedSuccessor()`).
6. **Gate I-3 — no minimum age on the manual close.** The scheduler waits the country window (`CountryFiscalRulesProvider::periodAutoLockMonths`); a human can close the day after `end_date`. Deliberate — the endpoint exists to finish a correction — but it is an unpoliced divergence from the country window. **Not implemented.**
7. **Refusal messages are literal English, not `messages.*` keys** — inherited from the reopen family; the CODE is the contract.
8. **Pre-existing Pint drift in `app/Modules/Company/Domain/Company.php`** — outside the diff, untouched.
9. **Throwaway DB `autoerp_c24_test` left on 127.0.0.1:5433** so the gate can re-run without a fresh migrate; drop when closed.