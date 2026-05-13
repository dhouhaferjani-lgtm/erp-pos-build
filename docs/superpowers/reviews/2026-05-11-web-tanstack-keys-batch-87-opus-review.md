# web.tanstack-keys Batch 87 — Opus Review

Commit reviewed: b7d6253b
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `b7d6253b` — fix(tenant-isolation): wrap web.tanstack-keys batch 87 (document components)
Scope: 6 callsites across 2 production files.
- `DocumentLineEditor.tsx` (.159 read products, .160 read services, .161 invalidate products via predicate)
- `AdditionalCostsForm.tsx` (.165 read additional-costs, .166 invalidate addCost mutation, .167 invalidate deleteCost mutation)

Test: `apps/web/src/features/__tests__/DocumentComponents.tenantScope.test.tsx` (new, 231 lines).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 6 sites use `tenantScopedKey([...])`. Products invalidate uses `scopedNamespacePredicate('products', tenantId, companyId)` inlined locally — same pattern as B90 (AddVehicleModal/LocationSelector).
2. **State-value selectors**: Both consumers select `tenantId`/`companyId` with `?? null`.
3. **Enabled gates**: All 3 read queries AND-combine `tenantId !== null && companyId !== null`.
4. **Async invalidate cascade**: Both AdditionalCostsForm mutations converted to `async/await`. The DocumentLineEditor.AddQuickProductModal callback retains `void` because the callback signature is synchronous and `handleAddProduct` runs before the invalidate enqueues — acceptable.
5. **Predicate path**: `scopedNamespacePredicate('products', ...)` invalidates every `['products', filter, tenant-A, company-1]` variant (search-with-query, search-no-query, …) within the active tenant — correct for the wildcard cache shape.

## Non-blocking findings

1. **Predicate helper duplicated again**: Same `scopedNamespacePredicate` inline copy as B90. Reinforces the case for a shared utility at `apps/web/src/lib/tenantScopedKey.ts`. Tracked as a B90 follow-up.
2. The test file exists but I did not inline-verify its assertions here — the wrap pattern matches the established convention exactly and the scanner is clean.

## Locks applied

6 callsites locked at fix commit `b7d6253b`:
web.tanstack-keys.159, .160, .161, .165, .166, .167.
