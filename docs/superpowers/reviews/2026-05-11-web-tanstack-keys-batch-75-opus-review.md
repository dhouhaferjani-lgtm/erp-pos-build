# web.tanstack-keys Batch 75 — Opus Review

Commit reviewed: 013d75b4
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `013d75b4` — fix(tenant-isolation): wrap web.tanstack-keys batch 75 (purchase order detail)
Scope: 9 callsites in `PurchaseOrderDetailPage.tsx`.
- .221 read document, .222/.223 invalidate on confirm
- .224/.225/.226 invalidate on receive goods (documents + document-exact + stock-levels)
- .227/.228/.229 invalidate on payment success (document + documents + payments)

Test: `PurchaseOrderDetailPage.tenantScope.test.tsx` (new, 296 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 9 sites use exact `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gate**: Read AND-combines `id.length > 0 && tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 3 invalidate flows (confirm onSuccess, receive onSuccess, handlePaymentSuccess) `async/await` with `Promise.all([…])`.

## Locks applied

9 callsites locked at fix commit `013d75b4`:
web.tanstack-keys.221-.229.
