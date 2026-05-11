# web.tanstack-keys Batch 86 — Opus Review

Commit reviewed: 67e8dc12
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `67e8dc12` — fix(tenant-isolation): wrap web.tanstack-keys batch 86 (return credit notes)
Scope: 9 callsites across 3 production files.
- `CreateCreditNotePage.tsx` (.141 read invoice, .142 invalidate credit-notes predicate, .143 invalidate documents predicate)
- `CreateReturnNotePage.tsx` (.144 read invoice, .145 read delivery-note, .146 invalidate return-notes predicate)
- `ReturnNoteDetailPage.tsx` (.155 read return-note, .156 invalidate return-note exact, .157 invalidate return-notes predicate)

Test: `apps/web/src/features/__tests__/ReturnCreditNotePages.tenantScope.test.tsx` (new, 325 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 9 sites use `tenantScopedKey([...])` for exact keys or `scopedNamespacePredicate(namespace, tenantId, companyId)` for namespace sweeps.
2. **State-value selectors**: All three pages select `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 3 reads AND-combine `tenantId !== null && companyId !== null` with existing predicates.
4. **Async invalidate cascade**: All three mutations (`CreateCreditNotePage.create`, `CreateReturnNotePage.create`, `ReturnNoteDetailPage.confirm`) converted to `async` with `Promise.all([…])` for parallel invalidates.
5. **Predicate mixed with exact-key**: `ReturnNoteDetailPage.confirm` uses an exact `tenantScopedKey(['return-note', id])` AND a predicate `scopedNamespacePredicate('return-notes', ...)` — covers both the detail-view cache and the list-view caches.

## Non-blocking findings

1. **`scopedNamespacePredicate` inlined again** in all three files. Now 5 known copies across the codebase (B90 ×2, B87 ×1, B86 ×3). Strongly suggests promoting to `apps/web/src/lib/tenantScopedKey.ts` — out of scope but a clear refactor candidate.
2. The test file is large (325 lines) — pattern matches the cluster convention.

## Locks applied

9 callsites locked at fix commit `67e8dc12`:
web.tanstack-keys.141, .142, .143, .144, .145, .146, .155, .156, .157.
