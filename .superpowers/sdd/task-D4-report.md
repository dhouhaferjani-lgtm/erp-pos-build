# Task D4 Report — Cash Movements Report Frontend

## Scope

- Binding plan: Global Constraints and Task D4 in docs/superpowers/plans/2026-07-12-treasury-phase3-cash-visibility.md.
- Binding spec: §7.2, §10, and §15 L2-1/L2-6.
- D4 base and pinned React Doctor base: 9ccbb45c7b252409335f6f852673e1dcfa74d1a8.
- Intentional gating axis: the page is on the Accounting/reports axis (reports.view route and reports navigation permission), while the later widget remains on the Treasury axis.

## Implementation

- Added useCashMovementsReport with the locked filter/row/meta contract. It uses raw api.get, returns response.data unchanged so {data,meta} survives, gates on tenant+company context, and uses a tenant-scoped cash-movements-report key.
- Added a current-month report page with DateRangeFilter, repository and direction Select atoms, loading/error/empty states, and page-reset behavior on every filter change.
- Rendered full-range totals above the table as one independent row per currency, formatting each in/out/net decimal string with formatCurrency from @/lib/format.
- Added the typed canonical DataTable with date, direction badge, signed per-row currency amount, source type, counterparty, GL account, and source reference columns, paired with external pagination.
- Payment-backed references link only to the established /treasury/payments/:id detail route; other source types render a copy affordance instead of inventing unsafe routes.
- Added OffsetPagination with the server's fixed per-page value and preserved filters across page changes.
- Added the lazy /finance/cash-movements route under reports.view and an adjacent Accounting/reports sidebar entry using ArrowLeftRight and the reports permission gate.
- Added complete cashMovements.* keys to en/fr/ar finance bundles.

## TDD Evidence

### RED

Command: pnpm --filter @autoerp/web exec vitest run src/features/finance/hooks/useCashMovementsReport.test.tsx src/features/finance/pages/CashMovementsReportPage.test.tsx src/routes/CashMovementsRoute.test.tsx src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx

- Exit 1.
- Hook and page suites failed import resolution because their production modules did not exist.
- Sidebar failed to find finance:cashMovements.navTitle.
- /finance/cash-movements fell through to the existing Dashboard catch-all, so the report route/page assertion failed.
- Existing Sidebar coverage remained green (36 passing tests).

### GREEN

Command: pnpm --filter @autoerp/web exec vitest run src/features/finance src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx src/routes/CashMovementsRoute.test.tsx

- Exit 0.
- 27 files passed, 172 tests passed.
- D4 pins raw paginated response preservation, tenant-scoped key/gating, current-month defaults, repository/direction filters, mixed TND/EUR totals, manual columns, safe payment link/copy fallback, pagination filter preservation, route permission, sidebar order, and sidebar permission denial.
- The first clipboard GREEN attempt exposed a test-harness issue: userEvent.setup() replaces the clipboard shim. The test now spies on the installed clipboard and passes without changing production behavior.

## Verification

- pnpm --filter @autoerp/web typecheck — exit 0.
- pnpm --filter @autoerp/web lint — exit 0.
- node apps/web/tools/audit-tanstack-keys.mjs — exit 0, 0 new.
- node apps/web/tools/audit-design-system.mjs — exit 0, 0 new.
- git diff --check — exit 0.
- Pinned React Doctor v0.7.6 changed-scope scan against 9ccbb45c7b252409335f6f852673e1dcfa74d1a8: baseline.newCount 0, diagnostics empty, complete scan. Line-scope also reports No issues found.
- React Doctor's score API displays 93/100 because it re-counts four pre-existing whole-file warnings in the two required host files (Sidebar.tsx/routes/index.tsx): two giant-component warnings, one existing Sidebar chained-iteration warning, and one existing unversioned-localStorage warning. File-scope locates all four outside D4 changed lines. Per task scope, none were refactored or suppressed.

## Deviations

No functional deviation. The literal React Doctor score display is an upstream changed-file scoring artifact; its baseline/new-diagnostics and line-scope results confirm D4 introduced zero findings.

## Spec Cross-link Follow-up

- Added the §7.2 TreasuryOverviewPage header action linking to /finance/cash-movements.
- Reused the existing finance:cashMovements.navTitle translations in all three locales and the established PageHeader action plus Link-wrapped secondary Button convention.
- RED: the focused TreasuryOverviewPage suite failed exactly one new accessible-link assertion while its four existing tests passed.
- GREEN: TreasuryOverviewPage passed 5/5; the complete D4 focused set passed 5 files and 49 tests.
- Typecheck, full web lint, explicit TanStack/design audits, and diff check exited 0.
- React Doctor v0.7.6 pinned to the cross-link task base 632b4eacf47ddae606dedb29b435f639eb289386 reported No issues found, baseline.newCount 0, empty diagnostics, and 100/100.

## Design-system Audit Follow-up — 2026-07-12

- Root cause: the original plan's hand-rolled-table rationale was stale. The current canonical DataTable supports strictly typed presentational columns/data/key extraction while OffsetPagination remains externally owned.
- Replaced the raw table with DataTableColumn<CashMovementRow> descriptors and DataTable data/keyExtractor. All seven columns, translated headers, badges, signed currency formatting, payment links, source copying, empty behavior, and external pagination remain unchanged.
- Replaced the raw source-copy button with the canonical ghost/xs Button atom.
- Existing page regression stayed green at 4/4 before and after the refactor; typecheck passed.
- Design-system audit moved from 753 acknowledged + 2 new (C3/C5) to 753 acknowledged, 0 new, 0 stale.
- Final D4 focused coverage passed 5 files and 49 tests; full web lint, TanStack audit, and diff check exited 0.
- React Doctor pinned to the original D4 base 9ccbb45c7b252409335f6f852673e1dcfa74d1a8 reported baseline.newCount 0 and empty diagnostics. A concurrent D5 commit moved HEAD during verification; the isolated follow-up scan pinned to that current task base 2a265a2a68f159f8501a62221c6800b7f5375d68 reported No issues found, 0 new diagnostics, and 100/100.
- Dated plan deviation: the D4 hand-table instruction is superseded by the enforced canonical DataTable audit requirement. This is presentational only and does not change the server pagination or report contract.
