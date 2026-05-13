# web.tanstack-keys Batch 92 — Opus Review

Commit reviewed: 0147e158
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `0147e158` — fix(tenant-isolation): wrap web.tanstack-keys batch 92
Scope: 9 callsites across 8 production files (documents + customer-history-audit cluster).
- `apps/web/src/features/customer-history-audit/hooks/useCustomerHistorySearches.ts` (.136 read)
- `apps/web/src/features/documents/DocumentListPage.tsx` (.154 read)
- `apps/web/src/features/documents/ReturnNoteListPage.tsx` (.158 read)
- `apps/web/src/features/documents/components/DocumentTotals.tsx` (.162 read tax-breakdown)
- `apps/web/src/features/documents/components/PaymentHistorySection.tsx` (.163 read payment-history)
- `apps/web/src/features/documents/components/RelatedDocumentsPanel.tsx` (.164 read related-documents)
- `apps/web/src/features/documents/hooks/useRelatedDocuments.ts` (.197 read documents/related)
- `apps/web/src/features/documents/return-notes/ReturnNoteDetailPage.tsx` (.236 read document, .237 invalidate document on confirm)

Test: `apps/web/src/features/documents/__tests__/DocumentTenantScope.test.tsx` (new, 223 lines, 2 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 8 read queries and the single invalidate (`ReturnNoteDetailPage.confirmMutation.onSuccess` for `['document', id]`) use `tenantScopedKey([...])`. No raw arrays remain.
2. **State-value selectors**: Every consumer that owns a read selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. `ReturnNoteDetailPage` selects both because its read also needs gating.
3. **Enabled gates**: All 8 read queries AND-combine `tenantId !== null && companyId !== null` with their existing predicates (`!!documentId`, `apiEndpoint !== '/documents'`, etc.).
4. **Async invalidate cascade**: `ReturnNoteDetailPage.confirmMutation.onSuccess` converted to `async` and `await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', id]) })`. The toast and `setConfirmAction(null)` run after the refetch enqueues.
5. **Cross-tenant isolation test**: Renders 5 components + 2 hooks. `CacheProbe` captures the live `queryClient` (since the components wrap their own provider internally). Asserts 7 distinct query keys land at `[..., 'tenant-A', 'company-1']`. Second `it` block: tenantless gating on DocumentTotals + PaymentHistorySection + RelatedDocumentsPanel produces zero API calls.

## Non-blocking findings

1. **ReturnNoteDetailPage coverage gap**: The test imports and renders 5 of the 8 files in this batch but NOT `ReturnNoteDetailPage`. Callsites .236 (read) and .237 (invalidate) inherit the pattern verified on the other files but are not directly asserted here. The page is exercised by feature-level browser tests; the wrap pattern is mechanically identical to `DocumentListPage`. Acceptable for this batch — the cluster-level `tenantSwitchCacheInvalidation.test.tsx` covers the global behavior.
2. The `DocumentListPage` query key contains both `effectiveType` and `apiEndpoint`-derived state; the suffix assertion uses literal `'invoice'`, matching `effectiveType` derived from prop `documentType="invoice"`. Correct.
3. `CacheProbe` is a one-off helper to escape a render context where `queryClient` is not visible to the test body. Pattern is fine but slightly heavier than tests that pass the client through the wrapper directly.

## Locks applied

9 callsites locked at fix commit `0147e158`:
web.tanstack-keys.136, .154, .158, .162, .163, .164, .197, .236, .237.
