# Treasury Phase 3 — Gate 3 rc1 Adversarial Review

## Scope

Wave D, reviewed as `phase3-gate-2..HEAD` against the Rev 2 spec, plan interfaces, reconciled findings, and documented deviations.

## Findings

1. **LOW — French window label.** `treasury.cashWidget.window` hardcodes “7 derniers jours” rather than retaining the `{{days}}` interpolation used by English and Arabic. This is cosmetic while the widget window is fixed at seven days; record for final cleanup.
2. **INFO — report row identity.** `CashMovementsReportPage` assumes the chosen source-based key extractor is unique within a page. No observed collision in the endpoint contract or tests.

## Verified contracts

- Mandatory transfer-port diff is empty. Fiscal and named interlock files are untouched.
- Added frontend money paths use decimal strings/big.js and contain no float coercion or `any`.
- D1 permission fallback roles exactly match backend transfer grants.
- D2 posts the approved contract, filters same-currency repositories, keeps a stable per-open UUID, validates decimal strings, and invalidates all eight cache families.
- D3 uses user-aware tenant-scoped query keys, raw-prefix invalidations, hidden zero badge, legacy FQCN/data.message fallback, native dialog semantics, and complete namespace registration.
- D4 renders totals per currency and gates route/nav on the accounting/reports axis.
- D5 self-gates on both treasury permission and company module, uses one shared component, and mounts on both dashboards.
- English, French, and Arabic key parity is present across the new namespaces/features.
- The all-raw invalidation deviation is semantically correct because current tenant-scoped query keys append tenant/company and raw leading prefixes match them safely; it is required by the enforced TanStack audit.
- The typed DataTable deviation is presentational and preserves external pagination; it is required by the enforced design-system audit.
- No money-path uncertainty exists in Wave D; Fable escalation was not triggered.

VERDICT: APPROVE
