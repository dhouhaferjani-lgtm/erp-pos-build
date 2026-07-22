# GATE 4 — Consolidated Verdict, Round 2 — APPROVE

> Controller consolidation 2026-07-22. Tip reviewed: `62035d815` (request `fc3d012d1`). Remediation of round-1 blocking items B1–B4 (`.gates/gate-4-verdict.md`).

## Lane results
- **fiscal-pos-reviewer (Opus) — APPROVE** (`.gates/gate-4-verdict-pos-r2.md`). B1 Critical RESOLVED: all conditional guards removed, unconditional `locationScope()` helper + `LocationScopeBoundary` (ctor-injected), fail-closed proven behaviorally (zero-allowed → empty on analytics AND Z-reports), unrestricted/F&B `orWhereNull` path correct, no fiscal write-path touch, no DTO shape change. B2 RESOLVED: all 5 plan Z-report cases + restricted/zero-allowed analytics cases, real behavioral tests that genuinely regress-guard the old fail-open code. SQLite 27/100 green.
- **frontend-conventions-reviewer (Opus) — APPROVE** (`.gates/gate-4-verdict-fe-r2.md`). B3 RESOLVED: company currency from `useCompanyStore` (no hardcoded TND in changed code), in/out split per controller ruling, test now supplies EUR + nonzero both directions and would fail against old code both ways; locale keys complete en/fr/ar, no dead keys. typecheck + 8/8 vitest green.
- **treasury-reviewer (Opus) — lane stalled mid-run (stream watchdog) AFTER completing its core verification: PG 43 tests / 208 assertions PASS across the 5 affected classes, including the new restricted/zero-allowed POS tests exercising `whereIn` + `orWhereNull` + qualified joins on PostgreSQL for the first time.** Residual mechanical checks completed by controller with evidence below. The lane's substantive round-2 judgments (B3 widget contract, B4 token) were independently covered by the FE and POS lanes.

## Controller-completed residual verification (treasury lane remainder)
- **PG re-run at tip (controller):** same 5 classes (`AnalyticsTest`, `ZReportListTest`, `LocationReconciliationTest`, `MaturingInstrumentsTest`, `UpcomingPaymentsTest`) — **OK, 43 tests / 208 assertions** — matches the lane's partial result exactly. (Codex's 51/276 claim spans a wider path set; consistent.)
- **Scoped PHPStan (controller):** `PosAnalyticsService.php`, `AnalyticsController.php`, `ReportController.php` — **No errors.**
- **No treasury/Task-0 backend files changed in the r2 diff** — verified via `git diff --name-only 329a5e8f5..62035d815` (ci.yml, 3 POS backend files, 2 POS test files, widget + test, 3 reports.json — nothing else). Matches the request claim.
- **B4 verified:** ci.yml:555 carries `AnalyticsTest` as an exact token.

## Controller fix applied post-verdict (this commit)
POS lane's new Minor: the B4 edit accidentally mangled `FiscalEventQuarantineTableTest` → dead token `FiscalEventQuarantineTest` on ci.yml:555 (class still covered by the `t6-phase0b-pgsql` job at ci.yml:652, so no coverage loss). Restored `FiscalEventQuarantineTableTest` (keeping `AnalyticsTest`) — one-token change, no test-behavior impact.

## Non-blocking follow-ups (ticketed, not gate items)
- `BranchLeaderboard.tsx:28` pre-existing hardcoded `'TND'` default prop (same class as B3, outside this diff).
- FE: `finance/types.ts` hand-declared `LocationReportBucket`; `useLiveSales` scope-keyed-but-unfiltered; `ZReportListPage` raw table (baselined).
- Treasury r1 minors: `LocationReportBucketData.total` surface-dependent semantics (document contract); `UpcomingPaymentsRecurringTest`/`UpcomingPaymentsInstrumentsTest` → pgsql allowlist.

## Gate decision
All four round-1 blocking items are resolved with behavioral regression guards; both judgment lanes APPROVE; all verification (SQLite, PostgreSQL, typecheck, lint/audits, PHPStan, Pint, transformer zero-diff) is green at the tip.

**GATE 4: APPROVE — tag `multiloc-gate-4`.**
