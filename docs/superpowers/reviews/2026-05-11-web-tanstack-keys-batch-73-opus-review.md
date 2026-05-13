# web.tanstack-keys Batch 73 — Opus Review

Commit reviewed: 66e3d17e
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `66e3d17e` — fix(tenant-isolation): wrap web.tanstack-keys batch 73 (sales order detail)
Scope: 12 callsites in `SalesOrderDetailPage.tsx`.
- .238 read order
- .239/.240 invalidate on confirm (document-exact + documents-predicate)
- .241/.242 invalidate on convert-to-invoice success, .243 on convert-to-invoice error
- .244/.245 invalidate on convert-to-delivery success, .246 on convert-to-delivery error
- .247/.248/.249 invalidate on payment success (document-exact, documents-predicate, payments-predicate)

Test: `SalesOrderDetailPage.tenantScope.test.tsx` (new, 351 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 12 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gate**: Read AND-combines `id.length > 0 && tenantId !== null && companyId !== null`.
4. **Async invalidate**: All 4 mutation handlers (confirm, convert-to-invoice, convert-to-delivery, handlePaymentSuccess) `async/await` with `Promise.all`. Both convert flows also have `async` onError rollback paths.

## Locks applied

12 callsites locked at fix commit `66e3d17e`:
web.tanstack-keys.238-.249.
