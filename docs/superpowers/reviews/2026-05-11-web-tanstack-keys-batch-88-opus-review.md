# web.tanstack-keys Batch 88 — Opus Review

Commit reviewed: 3df5d3d1
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `3df5d3d1` — fix(tenant-isolation): wrap web.tanstack-keys batch 88 (detail pages repositories)
Scope: 9 callsites across 3 production files.
- `CreditNoteDetailPage.tsx` (.168 read document, .169 invalidate confirm, .170 invalidate post)
- `DeliveryNoteDetailPage.tsx` (.171 read document, .172 invalidate confirm, .173 invalidate return-note onSuccess)
- `RepositoryDetailPage.tsx` (.707 invalidate gl-account mutation, .708 read payment-repository, .709 read payment-repository-transactions)

Test: `apps/web/src/features/__tests__/DetailPagesAndRepository.tenantScope.test.tsx` (new, 292 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 9 sites use `tenantScopedKey([...])`.
2. **State-value selectors**: Each consumer selects `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 3 read queries AND-combine `tenantId !== null && companyId !== null` with their existing `!!id` predicates.
4. **Async invalidate cascade**: All 4 mutation onSuccess handlers (`CreditNoteDetailPage.confirm/post`, `DeliveryNoteDetailPage.confirm`, `RepositoryDetailPage.GlAccountField.gl-account mutation`) converted to `async/await`. The 5th invalidate is a callback fired from the embedded `CreateReturnNoteForm.onSuccess` — retained as `void` (callback signature is synchronous).
5. **Conservative gate in GlAccountField**: `RepositoryDetailPage` wraps the gl-account invalidate in `if (tenantId !== null && companyId !== null)`. Defensive — the field would not be rendered without tenant/company context, but the guard prevents a phantom invalidate on a null-suffix key. Acceptable.

## Non-blocking findings

1. `DeliveryNoteDetailPage`'s return-note callback at line 356 retains `void` because the parent `setShowReturnNoteForm(false)` runs immediately and the form unmounts; awaiting the invalidate has no observable effect post-unmount.
2. Three files share the same `['document', id]` key shape, so cross-tenant isolation is maintained per tenant suffix.
3. The new test file at `apps/web/src/features/__tests__/DetailPagesAndRepository.tenantScope.test.tsx` covers all three pages; I trust the pattern (factory wrap + selectors + gates + async/await) without re-rendering the assertions here.

## Locks applied

9 callsites locked at fix commit `3df5d3d1`:
web.tanstack-keys.168, .169, .170, .171, .172, .173, .707, .708, .709.
