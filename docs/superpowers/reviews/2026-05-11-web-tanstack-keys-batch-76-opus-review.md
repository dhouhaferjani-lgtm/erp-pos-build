# web.tanstack-keys Batch 76 — Opus Review

Commit reviewed: d7002075
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `d7002075` — fix(tenant-isolation): wrap web.tanstack-keys batch 76 (document form)
Scope: 7 callsites in `DocumentForm.tsx`.
- .147 read document, .148/.149 invalidate documents-predicate + effectiveType-exact on create
- .150/.151/.152 invalidate documents-predicate + effectiveType-exact + document-exact on update
- .153 invalidate partners-predicate inside AddPartnerModal onSuccess callback

Test: `DocumentForm.tenantScope.test.tsx` (new, 290 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

APPROVE

## Gates evaluated

1. **Factory wrap**: All 7 sites use `tenantScopedKey([...])` or `scopedNamespacePredicate(...)`.
2. **State-value selectors**: `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: Read AND-combines `isEditing && apiEndpoint !== '/documents' && tenantId !== null && companyId !== null`.
4. **Async invalidate**: Both create and update onSuccess handlers `async/await` with `Promise.all([…])` for parallel invalidates (2-3 calls each).
5. **Mixed patterns**: Predicate for `documents` (multi-shape), exact for `[effectiveType]` (single namespace key), exact for `['document', effectiveType, id]` (specific entry). Correct partitioning.

## Non-blocking findings

1. `scopedNamespacePredicate` inlined. Tracked.
2. The AddPartnerModal-onSuccess callback at .153 retains `void` — synchronous callback, partner ID already pushed to form via `setValue` before the invalidate enqueues. Pattern is consistent.

## Locks applied

7 callsites locked at fix commit `d7002075`:
web.tanstack-keys.147, .148, .149, .150, .151, .152, .153.
