# ShiftClosurePage blind-count parity and route ownership

## Finding

`apps/pos/src/pages/ShiftClosurePage.tsx` is reachable at the manager-only `/shift` route and is also embedded in the theme preview. It is a static/demo screen: opening cash, cash sales, and expected cash are hard-coded, variance is derived locally, and exact expected/variance amounts render before the screen's inert **Close Shift** button. The page does not read `require_blind_cash_count` and does not use the production `EndOfDayPreviewModal` / `CashReconciliationSection` commit flow.

The SV-10 Stage-1 audit did not rewrite this page. That would require a product/architecture choice among removing the production route, explicitly declaring the screen a manager report, or replacing its right-hand panel with the real close workflow. Such a redesign exceeds SV-10's permission to fix only narrow pre-Commit magnitude leaks in the existing blind-count flow.

## Required decision and acceptance

Assign ownership for `/shift` and choose one outcome:

1. remove the route if the page is only a theme specimen;
2. define it as a manager reporting surface and document why blind-count concealment does not apply; or
3. wire it to the production close flow and apply the same `require_blind_cash_count` / Commit Counts gates.

If outcome 3 is chosen, add a rendered route-level test proving that under blind mode neither expected cash nor variance magnitude appears before Commit Counts, while both appear after commit. Do not create a second cash-close state machine alongside `EndOfDayPreviewModal`.

## References

- `apps/pos/src/pages/ShiftClosurePage.tsx:18-25,93-128`
- `apps/pos/src/components/AppShell.tsx:223-225`
- `apps/pos/src/pages/ThemePreviewPage.tsx:864`
- `docs/handoff/reviews/sv-stage1/M4-sv10-leak-audit.md`
