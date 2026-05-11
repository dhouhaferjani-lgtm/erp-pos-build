Commit reviewed: a9bd4aa2

# Opus review — web.tanstack-keys batch 35 (POS report pages)

Independent second-pair-of-eyes review of the Codex implementation
at `a9bd4aa2`. All 5 review gates pass. Verdict APPROVE.

## Axis-by-axis findings

**Gate 1 — Scanner delta = 5 (486 → 481): PASS.** ShiftHistoryPage
(2 queries: terminals lookup + shift-history) + ZReportDetailPage (1
useQuery z-report) + ZReportListPage (2 queries: terminals + z-reports)
= 5 callsites.

**Gate 2 — Report page query keys wrapped: PASS.**
- terminals lookup: `tenantScopedKey(['pos', 'terminals'])`
- shift-history: `tenantScopedKey(['pos', 'shift-history', filters])`
- z-report detail: `tenantScopedKey(['pos', 'z-report', zNumber,
  terminalId])`
- z-reports list: `tenantScopedKey(['pos', 'z-reports', filters])`

**Gate 3 — Pages subscribe via `usePosTenantScope()`: PASS.** Helper
reused from B30.

**Gate 4 — Existing route/filter enabled gates preserved + extended:
PASS.** Each page preserves pre-existing conditions (`!!zNumber &&
!!terminalId`, `!!filters.terminal_id`) and AND-combines with
`hasTenantScope`.

**Gate 5 — Tests cover scoped key shapes + no-fetch behavior without
tenant/company: PASS.** 4 tests passing.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/pos/pages/reportPages.tenantScope.test.tsx`:
  **4/4 pass**.
- `pnpm typecheck`: clean.
- `php artisan sweep:inventory:verify-history`: 4231 events / 1205
  callsites / 0 problems.

Verdict: APPROVE
