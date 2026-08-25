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