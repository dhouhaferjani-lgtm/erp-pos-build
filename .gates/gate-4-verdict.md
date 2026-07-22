# GATE 4 — Consolidated Verdict, Round 1 — REJECT

> Controller consolidation 2026-07-22. Tip reviewed: `0945d39e0`. Three parallel Opus lanes:
> - `.gates/gate-4-verdict-pos.md` — fiscal-pos-reviewer — **REJECT** (1 Critical, 1 Important, 2 Minor)
> - `.gates/gate-4-verdict-fe.md` — frontend-conventions-reviewer — **REJECT** (1 Major, 4 Minor)
> - `.gates/gate-4-verdict-treasury.md` — treasury-reviewer — **REJECT** (2 Important, 3 Minor; Task 0a/0b substance VERIFIED CORRECT)

## Blocking items (must fix, in priority order)

**B1 [CRITICAL — scope leak] POS analytics + Z-report list fail OPEN on an empty effective location set.**
`PosAnalyticsService` (8 methods using `->when($locationIds !== [], …)`) and `ReportController::listZReports` (:271 `if ($locationIds !== [])`). A user with `allowed_location_ids = []` (reachable — Create/UpdateUserRequest have no `min:1`) resolves to `[]` and the guard skips the predicate → full-company fiscal takings/Z-reports. Every sibling surface fails CLOSED (unconditional `whereIn` + `LocationScopeBoundary::isUnrestricted` gating `orWhereNull`). Fix: inject `LocationScopeBoundary` into `AnalyticsController`/`ReportController` paths, apply the location predicate UNCONDITIONALLY, add `orWhereNull` only when unrestricted. Fold in the pos-lane minor: `pos_orders.location_id` is nullable, so the `getFnbMetrics` queries need the `orWhereNull`-when-unrestricted branch to keep Unattributed orders visible to owners.

**B2 [IMPORTANT — missing guard tests] Plan-mandated scope-enforcement tests absent; B1 was invisible to the suite.**
`ZReportListTest` has 1 of the 5 plan-required tests (plan Task 5, line 344); `AnalyticsTest` has no restricted-membership case (all seeded users unrestricted). Add: restricted user (`allowed_location_ids = [L1]`) bare request → only L1 data; out-of-scope in-company id → 403; `terminal_id` + `location_ids[]` narrowing on Z-reports (incl. one `location_ids[]` case that exercises the `pos_terminals.location_id` branch on PG); and a zero-allowed (`allowed_location_ids = []`) case asserting an **empty** result — write it first; it must FAIL before B1 and pass after.

**B3 [MAJOR — wrong currency] `DueThisWeekWidget.tsx:32` hardcodes `currency: 'TND'`.**
Wrong symbol AND wrong decimal scale (TND=3 vs EUR=2) for every non-TND tenant. Source the company currency (as `CashAcrossStoresWidget` and `InstrumentListPage` already do) and make the widget test supply/assert a non-TND currency so the mock can't mask it again.

**B4 [IMPORTANT — CI coverage] Add POS `AnalyticsTest` to the pgsql `--filter` allowlist (ci.yml:555).**
It is only substring-matched inside `ExpenseAnalyticsTest`; the new location-scoped aggregates have zero PG coverage in CI. Also add the B2 test classes if any are new.

## Controller ruling on the direction-conflation minor (FE minor 2 / treasury minor 3)
`DueThisWeekWidget` sums `total_in + total_out` into one figure. While fixing B3, split the display into in/out (the DTO already carries `total_in`/`total_out` per bucket) — netting opposite directions into a magnitude misleads; the per-direction DTO fields exist precisely for this. Owner may override at device pass.

## Non-blocking (ticket or fold in if trivial)
- FE: `finance/types.ts:326` hand-declared `LocationReportBucket` → consume generated type; `useLiveSales` scope-keyed but unfiltered fetch; `ZReportListPage` raw `<table>` → DataTable (baselined).
- Treasury: `LocationReportBucketData.total` surface-dependent semantics — document the contract on the DTO docblock; `UpcomingPaymentsRecurringTest`/`UpcomingPaymentsInstrumentsTest` → pgsql allowlist.

## Verified sound this round (do not redo)
Task 0a DTO emission + transformer zero-diff; Task 0b GR-IR accrual case is a real accrual scenario (raw totals would fail it); upcoming-payments refactor preserves math and fixes the silently-ignored location filter; widget scope wiring + `locationScopedKey` coverage; en/fr/ar complete on all new keys; no fiscal write-path or hash-chain touch; frozen-origin vs custody grain respected; PG runs green (24/176 + 19/82); typecheck/lint/vitest green (38/38).

VERDICT: REJECT — fix B1–B4, re-request as `.gates/gate-4-request-r2.md`
