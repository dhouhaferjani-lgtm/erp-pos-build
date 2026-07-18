# Treasury Phase 4 — GATE 4 frontend-conventions lane (rc1)

**Scope:** tokens/atoms, i18n EN/FR/AR, tenantScopedKey, invalidation, authenticated blob export, RTL/accessibility, route order, and FE permissions over `origin/dev..HEAD`.

## Verified

- New expense UI uses canonical atoms/design tokens, has no new hardcoded color classes or `any`, and keeps money as strings with MoneyInput/QuantityInput, formatCurrency, decimal helpers, and Big.js.
- New useQuery keys are tenant/company scoped; invalidation separates list/detail/analytics and preserves cross-tenant/company isolation.
- Phase 4 locale keys match across EN/FR/AR, including nested recurring notification keys and preserved placeholders.
- CSV blob export is authenticated, permission-gated on list and analytics pages, cleans up object URLs, and surfaces errors.
- RTL logical properties and accessibility roles/labels are present; route order and FE permission/sidebar gates are correct.

## Findings (LOW/INFO only)

1. AR `expenses.json` lacks pre-existing `pay.*` keys and falls back through the EN merge; retain in the existing locale follow-up.
2. A few cross-cutting invalidations are not tenant-scoped and may over-refetch; they do not leak data and match the existing pattern.
3. Automated locale-completeness coverage is narrower than the full manual parity audit.

The lane could not independently run the node audits due to harness command restrictions; recorded Gate 4 audit output and source inspection were green.

**VERDICT: APPROVE**
