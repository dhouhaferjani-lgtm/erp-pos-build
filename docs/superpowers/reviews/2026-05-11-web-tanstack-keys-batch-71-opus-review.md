# web.tanstack-keys Batch 71 — Opus Review

Commit reviewed: 30d29426
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `30d29426` — fix(tenant-isolation): wrap web.tanstack-keys batch 71 (payment detail)
Scope: 12 callsites in `PaymentDetailPage.tsx`.
- .677/.678/.679 reads: payment, payment.can-refund, payment.refund-history
- .680 invalidate payments-predicate on delete
- .681/.682/.683 invalidate on full refund
- .684/.685/.686 invalidate on partial refund
- .687/.688 invalidate on reverse

Test: `PaymentDetailPage.tenantScope.test.tsx` (new, 282 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 12 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 3 reads AND-combine `tenantId !== null && companyId !== null` with their existing `Boolean(id)` / `data?.data?.status === 'completed'` predicates.
4. **Async invalidate**: All 4 mutation handlers `async/await` with `Promise.all`.

## Locks applied

12 callsites locked at fix commit `30d29426`:
web.tanstack-keys.677-.688.
