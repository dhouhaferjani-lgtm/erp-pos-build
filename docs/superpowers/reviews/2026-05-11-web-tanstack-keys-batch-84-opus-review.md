# web.tanstack-keys Batch 84 — Opus Review

Commit reviewed: e9c1e2c7
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `e9c1e2c7` — fix(tenant-isolation): wrap web.tanstack-keys batch 84 (receipt search)
Scope: 4 callsites in `ReceiptSearchPage.tsx`.
- .532 read pos.terminals, .533 read pos.receipts (filtered)
- .534 invalidate pos.receipts on void (predicate path)
- .535 invalidate pos.receipts on return success (predicate path, fire-and-forget)

Test: `ReceiptSearchPage.tenantScope.test.tsx` (new, 188 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: Both reads use `tenantScopedKey([...])`. Both invalidates use a focused `scopedPosReceiptsPredicate(tenantId, companyId)` that requires `['pos', 'receipts', ..., tenant, company]`.
2. **State-value selectors**: Selects `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: Both reads AND-combine `tenantId !== null && companyId !== null`.
4. **Async invalidate**: `voidMutation.onSuccess` converted to `async/await`. The return-modal callback retains `void` (synchronous callback).
5. **Predicate refinement**: `scopedPosReceiptsPredicate` is more specific than the generic `scopedNamespacePredicate` — it pins the first TWO key segments (`pos`, `receipts`) to avoid accidentally matching other `pos.*` keys.

## Non-blocking findings

1. The specialized predicate suggests a `scopedPathPredicate(prefixSegments, tenantId, companyId)` shared helper would cover the generic and the multi-prefix cases.

## Locks applied

4 callsites locked at fix commit `e9c1e2c7`:
web.tanstack-keys.532, .533, .534, .535.
