# web.tanstack-keys Batch 37 — Opus Review

Commit reviewed: 2f8e5b0b
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `2f8e5b0b` — fix(tenant-isolation): wrap web.tanstack-keys batch 37 (goods receipts)
Scope: 5 callsites in `GoodsReceiptListPage.tsx`.
- .581/.582/.583 reads: pending-receipt, received, confirmed-fully-received POs
- .584/.585 invalidate purchase-orders-predicate + stock-levels-predicate on receive

Test: `GoodsReceiptListPage.tenantScope.test.tsx` (new, 251 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 3 reads wrapped; both invalidates use predicate path.
2. **State-value selectors + hasTenantScope**: Uses the `hasTenantScope` composition pattern (see B38).
3. **Enabled gates**: All 3 reads use `enabled: hasTenantScope`.
4. **Async invalidate**: onSuccess `async/await` with `Promise.all([…])`.
5. **Predicate defensive guard**: This batch's `scopedNamespacePredicate` adds an extra `Array.isArray(k)` check — slightly more defensive than later copies, harmless.

## Locks applied

5 callsites locked at fix commit `2f8e5b0b`:
web.tanstack-keys.581-.585.
