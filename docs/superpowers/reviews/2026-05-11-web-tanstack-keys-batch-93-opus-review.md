# web.tanstack-keys Batch 93 — Opus Review

Commit reviewed: f6cb4b65
Verdict: APPROVE

Reviewer: opus
Implementer: codex
Branch: feat/tenant-isolation-sweep-execution
Cluster: web.tanstack-keys
Date: 2026-05-11

## Commit reviewed

Fix commit: `f6cb4b65` — fix(tenant-isolation): wrap web.tanstack-keys batch 93
Scope: 11 callsites across 8 production files (POS cluster).
- `apps/web/src/features/pos/components/CashOperationModal.tsx` (.458 invalidate shift-balance, .459 invalidate shift)
- `apps/web/src/features/pos/components/EarnPointsPreview.tsx` (.460 read preview-earning)
- `apps/web/src/features/pos/components/LoyaltyRewardSelector.tsx` (.461 read rewards)
- `apps/web/src/features/pos/components/ReturnItemsModal.tsx` (.462 read receipt-detail)
- `apps/web/src/features/pos/components/TerminalSelector.tsx` (.463 read terminals)
- `apps/web/src/features/pos/hooks/useActiveMenu.ts` (.464 read active-menu)
- `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx` (.528 read payment-methods, .529 read payment-repositories)
- `apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.tsx` (.530 read product, .531 read product-stock)

Test: `apps/web/src/features/pos/__tests__/PosTenantScope.test.tsx` (new, 238 lines, 2 it-blocks).

Scanner: 0 violations. Verify-history: clean.

## Verdict

Verdict: APPROVE

## Gates evaluated

1. **Factory wrap**: All 9 read queries (`loyalty.preview-earning`, `loyalty.rewards`, `pos.receipt-detail`, `pos.terminals`, `pos.active-menu`, `payment-methods`, `payment-repositories`, `product`, `product-stock`) and both invalidate calls in `CashOperationModal.onSuccess` (`pos.shift-balance`, `pos.shift`) use `tenantScopedKey([...])`. No raw arrays remain.
2. **State-value selectors**: Every consumer selects `useAuthStore((s) => s.user?.tenant_id ?? null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`. `CashOperationModal` does not need them (mutation only — invalidate keys use the helper which reads state at call time).
3. **Enabled gates**: All 9 read queries AND-combine `tenantId !== null && companyId !== null` with their existing predicates (`!!enrollmentId && parseFloat(cartTotal) > 0`, `isOpen && !!receiptId`, `isOpen`, `isOpen && activeTab === 'stock'`, etc.).
4. **Async invalidate cascade**: `CashOperationModal.onSuccess` converted to `async` and uses `await Promise.all([…invalidateQueries…, …invalidateQueries…])` so the close-and-reset path runs only after both refetches are enqueued.
5. **Cross-tenant isolation test**: Asserts 8 distinct query keys land at `[..., 'tenant-A', 'company-1']`. Pre-seeds both `['pos', 'shift-balance', 'shift-1', 'tenant-A', 'company-1']` AND `['pos', 'shift-balance', 'shift-1', 'tenant-B', 'company-2']` cache entries, drives the deposit flow, asserts the tenant-A entries flip to `isInvalidated=true` while tenant-B remains `isInvalidated=false`. This is the strongest cross-tenant isolation assertion in the cluster so far — it proves the invalidation predicate is anchored to the active tenant suffix, not a global key. Second `it` block: tenantless gating on EarnPointsPreview + TerminalSelector.

## Non-blocking findings

1. The describe title says ".609-.619" but the actual inventory IDs are .458-.464, .528-.531 (inventory was renumbered later). Cosmetic.
2. `useActiveMenu` defines `activeMenuKeys.all = ['pos', 'active-menu']` as a const tuple; passing it through `tenantScopedKey(activeMenuKeys.all)` is correct, but consumers who reference `activeMenuKeys.all` for invalidations downstream must also wrap. No such consumer exists today (grepped — only this hook reads, no invalidate sites).
3. `EarnPointsPreview` query key includes `cartItems.length` rather than a hashed cart payload; this is the established convention but technically two carts with the same count and total reuse cache. Out of scope for tenant-isolation review.

## Locks applied

11 callsites locked at fix commit `f6cb4b65`:
web.tanstack-keys.458, .459, .460, .461, .462, .463, .464, .528, .529, .530, .531.
