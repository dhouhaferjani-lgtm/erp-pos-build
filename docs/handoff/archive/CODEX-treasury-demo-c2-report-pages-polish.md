# C2 — Finance Report Pages Polish (P&L headline)

> Chunk C2 of the treasury demo plan (`docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md`). Frontend only (`apps/web`). TDD: Vitest failing test first per task. TS strict, no `any`.

## Problem

The full accounting report suite EXISTS (`/finance/profit-loss`, balance-sheet, aged-receivables, aged-payables, trial-balance — `apps/web/src/features/finance/pages/*.tsx`) but is demo-hostile:

1. **Precision/currency violation (visible on screen):** pages use `parseFloat` + `new Intl.NumberFormat('en-US')` (e.g. `ProfitLossPage.tsx:30-36`) → USD-style formatting, wrong currency on a TND/EUR tenant, violates precision rule 19 (never parseFloat on money).
2. **Zero visual hierarchy:** plain black-and-white tables. No KPI summary, no chart.
3. `components/ui/StatCard.tsx` uses hardcoded Tailwind colors (`text-gray-*`, `green-600`, `red-600`) instead of design tokens.

## Tasks (each = failing Vitest test first → implement → green → commit)

### T1 — Kill `parseFloat`/`en-US` across all five report pages
`ProfitLossPage.tsx`, `BalanceSheetPage.tsx`, `AgedReceivablesPage.tsx`, `AgedPayablesPage.tsx`, `TrialBalancePage.tsx`: replace with the project money formatter (`formatCurrency` — check existing usages, e.g. `features/owner-dashboard/components/SalesSummaryCards.tsx`, for the exact import — `@/lib/decimal` or `@/lib/format`) using the tenant/company currency. Amounts from the API are STRINGS — never convert through `Number`/`parseFloat` (ESLint `no-parsefloat-on-money` guards this). Tests assert rendered output (e.g. TND-formatted string appears), NOT class names.

### T2 — Tokenize `StatCard`
Migrate `components/ui/StatCard.tsx` hardcoded colors to design tokens (`tokens`, `textColors`, `borderColors` from `@/lib/designTokens`). Don't change its API. Update/extend its test.

### T3 — P&L page upgrade
Add to `ProfitLossPage`:
- KPI row: 3 `StatCard`s — Total Revenue / Total Expenses / Net Income (trend optional; use the API's `total_revenue`/`total_expenses`/`net_income`).
- A revenue-vs-expenses bar chart via the existing echarts wrapper `features/owner-dashboard/components/OwnerChart.tsx` (echarts is the bundled chart lib — do NOT add recharts). Follow an existing usage (e.g. `SalesTrendChart.tsx`) for option shape + loading/empty states.
- Keep the detail table below, restyled with table tokens (`tokens.table.header`, `borderColors.divideDefault`) and a `PageHeader` (`components/molecules/PageHeader`) consistent with other polished pages.

### T4 — Consistent date-range filter UX
Keep the existing `<Input type="date">` in `FormField` pattern (ProfitLossPage:114-137) but ensure sensible defaults (current month) and that all five pages share the same filter presentation. No new date-picker dependency.

## Constraints

- ALL new user-facing text via `t()` — add keys to the EXISTING `finance` namespace JSON for **en, fr, ar** (`apps/web/src/locales/{en,fr,ar}/finance.json`); namespace is already registered in `lib/i18n.ts` — do NOT register a new one.
- Design tokens ONLY for any color you touch (rule 18); do not refactor untouched lines.
- `apiGet` already unwraps `response.data.data` — do not double-unwrap (rule 14).
- Component tests may `vi.mock` hooks/providers; test rendered HTML output.
- Commit per task: `fix(finance): ...` / `feat(finance): ...`.

## Verify

`cd apps/web && pnpm typecheck && pnpm lint` (scoped is fine) + run YOUR test files (`pnpm test <path>`). Visual check if a dev server is available, else rely on tests + a screenshot task note for the merger.
