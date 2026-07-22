# Gate 4 FE Verdict — Round 2 (B3 remediation) — `feat/multi-location` @ 62035d815

> Controller-run 2026-07-22. Reviewer: frontend-conventions-reviewer (Opus). Request: `.gates/gate-4-request-r2.md`. Round-1: `.gates/gate-4-verdict-fe.md` (REJECT).

Re-reviewed the remediation diff `git diff 329a5e8f5..62035d815` (FE scope: `DueThisWeekWidget.tsx` + its test + `{en,fr,ar}/reports.json`).

## Re-run evidence
- `pnpm typecheck` — **GREEN** (`tsc --noEmit`, no output).
- `pnpm vitest run DueThisWeekWidget.test.tsx + OwnerDashboardPage.test.tsx` — **8 passed / 8** (1 + 7). Hung workers killed → 0 remaining.

## 1. B3 (round-1 MAJOR) — FIXED
- Company currency now sourced from the store: `useCompanyStore((state) => state.getCurrentCompany()?.currency ?? 'EUR')` (DueThisWeekWidget.tsx:22); `getCurrentCompany` is a real store selector (companyStore.ts:99,157). Both `formatCurrency` calls pass `{ currency: companyCurrency }` (DueThisWeekWidget.tsx:36-37).
- No hardcoded `'TND'` remains in the widget. Grep of `src/features/owner-dashboard/` for `'TND'` returns only: `BranchLeaderboard.tsx:28` default prop (pre-existing debt, untouched, outside this diff) and two test-mock literals (`LiveSalesFeed.test`, `CashAcrossStoresWidget.test`) — none in production widget code changed here.
- Controller ruling applied: the in+out magnitude sum is gone. Inbound and outbound are computed and rendered as **separate labeled amounts** — `inbound = bcadd(overdue.total_in, d0_7.total_in)`, `outbound = bcadd(overdue.total_out, d0_7.total_out)` (DueThisWeekWidget.tsx:25-26), shown on two labeled `<span>` lines (:36-37).
- Money handling stays canonical: decimal-string `bcadd` from `@/lib/decimal`, `formatCurrency` only at the render boundary; no `parseFloat`/`Number()` introduced.

## 2. Test can no longer mask a hardcoded currency — CONFIRMED
- The mock now injects a company currency of **EUR** (DueThisWeekWidget.test.tsx:9-11) and supplies **nonzero in AND out** (`total_out: '5.00'`/`'7.00'` alongside `total_in: '10.000'`/`'20.000'`). Assertions require `dueThisWeek.inbound` → `'30,00 EUR'` and `dueThisWeek.outbound` → `'12,00 EUR'` (DueThisWeekWidget.test.tsx:17-18).
- Against the old code this test **would fail** two ways: (a) the old single-line render had no `inbound`/`outbound` elements, so `getByText(/dueThisWeek.inbound/)` throws; (b) the old hardcoded `currency: 'TND'` would render `TND`, not `EUR`, failing `toHaveTextContent('… EUR')`. The mask is closed.

## 3. Locale changes — CLEAN, no dead keys
- All keys the widget consumes exist in en, fr, AND ar: `title`, `summary` (now `{{count}}`-only), `inbound`, `outbound`, `view`, `empty` (verified by parsing all three `reports.json`). en: Incoming/Outgoing, fr: Entrants/Sortants, ar: واردة/صادرة.
- The old `{{amount}}` interpolation was removed from `summary` in all three locales; nothing else references it, so no dead/orphaned key remains from the single-amount rendering.

## 4. Deferred minors — unchanged, not worsened
This diff touches only `DueThisWeekWidget.tsx`, its test, and the three `reports.json` files. The three round-1 minors (finance/types.ts `LocationReportBucket` duplicate, `useLiveSales` scope-in-key-without-param, ZReportListPage raw `<table>`) live in untouched files and are unaffected.

## Findings
None blocking. B3 is resolved; typecheck, the widget test, and the OwnerDashboardPage tests are green; i18n coverage is complete across en/fr/ar with no dead keys. The three pre-existing minors remain as non-blocking follow-ups.

VERDICT: APPROVE
