# BRIEF — Lane B2-5 / C-24(i): manual single-period close `POST /fiscal-periods/{id}/close` (Company lane)

Follow `LANE-PROTOCOL.md`. Worktree (parent creates): `.worktrees/sb2-c24-period-close`, branch
`fix/sb2-c24-manual-period-close`, base = dev tip at dispatch. PG 5433 `autoerp`/`autoerp_secret`, own DB
`autoerp_c24_test`. Tool calls < 90 s, one test file per run, by path, never the suite, no stash, never push.

## Why
Q-10 (`f5cae1f12`, LEDGER C-24 (i)) made the nightly `FiscalPeriodAutoLockService` SKIP any period with
`reopened_at` set and HOLD the close of its fiscal year (`closeFiscalYears()` `:237-238`). The reopen docblock
(`FiscalPeriodReopenService.php:18-32`) states the honest consequence: there is NO manual close, so a reopened
period — and its fiscal year — stay open forever. `lockPeriodsInClosedFiscalYears()` `:268-283` already
anticipates "reopened but Closed again by a human" (it excludes only `Open AND reopened_at IS NOT NULL`), so a
manual `Open → Closed` write is the exact missing edge: once it lands, the year hold releases by itself and the
existing scheduler resumes. Nothing else in the scheduler changes.

## Shape — mirror `FiscalPeriodReopenService` / `FiscalPeriodController::reopen` / `ReopenFiscalPeriodRequest` one-for-one
1. `FiscalPeriodCloseService::close(FiscalPeriod $period, string $userId, ?string $reason): FiscalPeriod`
   (`app/Modules/Company/Application/Services/`), transactional, row re-read `lockForUpdate()`. Refusals via a
   NEW typed `FiscalPeriodCloseRefusedException` + `FiscalPeriodCloseRefusalCode` enum (copy the reopen pair):
   - `PERIOD_LOCKED` — Locked is terminal (never Locked → Closed);
   - `PERIOD_NOT_OPEN` — only `Open → Closed`;
   - `FISCAL_YEAR_CLOSED` — `fiscalYear.is_closed`;
   - `PREDECESSOR_OPEN` — an EARLIER period of the same company is still Open (ordering invariant, the mirror
     of reopen's `successorSettled`: periods settle in sequence; closing ahead of an open predecessor lets the
     scheduler's "oldest first" assumption drift);
   - `PERIOD_NOT_ENDED` — `end_date` is not before today (company-local date is fine; use `Carbon::today()`):
     a period is closed after it ends. **Judgement call for the reviewer** — if the treasury gate rules that
     early close is legitimate, drop this code; keep it in for now and say so in the report.
   Write: `status = Closed`, `status_changed_from = 'open'`, `status_actor = 'user:'.$userId`,
   `closed_at = now()`, `closed_by = $userId`. **Clear nothing**: `reopened_at/reopened_by/reopen_reason`
   stay as history (LEDGER C-24 wording "stamps closed_by, clears nothing"; the scheduler predicate above
   depends on `reopened_at` being kept). Optional `reason` → if provided, store in… there is NO
   `close_reason` column and this lane ships NO migration — accept it in the request, log it, do not persist;
   note this as a residual (a `close_reason` column is a Slice-D-adjacent additive migration).
   Emit `Log::info('Fiscal period closed', …)` like reopen (domain event = C-24 (iv) residual, unchanged).
2. Controller method `FiscalPeriodController::close(CloseFiscalPeriodRequest $request, string $id)` —
   company-scoped lookup exactly like `reopen()` (404 for another company's period), 422 envelope
   `{error:{code, message}}` with the typed code on refusal, 200 with the same payload shape as reopen.
3. Route in `app/Modules/Company/routes.php` next to `:71-74`: `POST fiscal-periods/{id}/close`,
   `whereUuid('id')`, `->middleware('can:fiscal-periods.close')`, name `fiscal-periods.close`.
4. Permission `fiscal-periods.close` in `RolesAndPermissionsSeeder` at BOTH places `fiscal-periods.reopen`
   appears (`:291` catalogue, `:816` role grant — accountant + admin, same as reopen). Regenerate the FE map
   (`permissionsMap.generated.ts` — find the command Q-10 used: `git show f5cae1f12 --stat`, grep the CI hash
   check) so the CI hash check stays green; that file is the ONLY web file you touch.
5. Tests — red first, by path: NEW `tests/Feature/Company/FiscalPeriodCloseEndpointTest.php` modelled on
   `FiscalPeriodReopenEndpointTest.php` (setUp, permissions, team id): accountant closes an ended Open period
   (stamps asserted, `reopened_*` preserved when set); each refusal code; viewer/manager 403; other company 404;
   admin OK; and the INTEGRATION pin that matters: reopen → manual close → run
   `FiscalPeriodAutoLockService::lockExpiredPeriods()` and assert the fiscal year now closes and the period
   becomes Locked (today it stays Open forever — show that red first). Regression by path:
   `tests/Feature/Company/FiscalPeriodReopenEndpointTest.php` + the Q-10 auto-lock test file (find it:
   `grep -rl FiscalPeriodAutoLockService tests/`).
6. `php tools/feature-lane-manifest-check.php` from `apps/api`; update the manifest as instructed.

No migration. No FE page (C-24 (iii) residual stays). Deploy note for the report: seeder re-run + permission
cache reset for the new permission (extend S-20's wording).

## Deliverable
LANE-PROTOCOL §Deliverable; commit message starts `feat(sb2-c24):`. Do NOT merge.
