# Today Sales blind-count derivation across navigation

## Finding

During an open shift, a cashier can open Reports → Today's Sales or navigate to `/sales`. `TodaySalesPanel` renders the exact sale total and receipt amounts. On an ordinary cash-only shift with no drawer movements, the whole-drawer expected cash is exactly the opening float plus gross sales. The end-of-day instruction intentionally discloses the opening float, so a cashier can leave the uncommitted blind-count modal, read Today's Sales, and reconstruct the expected count.

M4 did not change this route. Hiding a cashier's sales history, carrying blind-count state across navigation, or redefining the blind-count threat model is a product and authorization decision larger than SV-10's permission for narrow fixes inside the existing close flow.

## Required decision and acceptance

Choose and document one policy:

1. hide monetary Today's Sales values from cashiers while a blind count is open and uncommitted;
2. prevent cashier navigation to sales-history money during that interval;
3. change who may access `/sales`; or
4. explicitly exclude cross-navigation reconstruction from the blind-count threat model and record the accepted residual risk.

For outcomes 1–3, add a rendered route-level test using a cashier with an open cash-only/no-movement shift. It must prove that opening float plus Today's Sales cannot reveal exact expected cash before Commit Counts, and that the authorized sales view or values return after the chosen boundary. Reuse the production fraud-policy source; do not create a second local money calculation.

## References

- `apps/pos/src/components/pos/TodaySalesPanel.tsx:85-93,115-138`
- `apps/pos/src/components/pos/ReportsMenu.tsx:59-65`
- `apps/pos/src/components/AppShell.tsx:218`
- `apps/pos/src/components/Header.tsx:677-685,732-740`
- `docs/handoff/reviews/sv-stage1/M4-sv10-leak-audit.md`
