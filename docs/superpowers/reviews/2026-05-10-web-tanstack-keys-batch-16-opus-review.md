Commit reviewed: c209d6e5

# Opus review — web.tanstack-keys batch 16 (inventory)

Independent second-pair-of-eyes review of the Codex implementation
at `c209d6e5`. All 9 review axes pass. Verdict APPROVE.

## Axis-by-axis findings

**Axis 1 — Scanner delta = 19 (695 → 676): PASS.** Live audit count
at HEAD is 641 (post-B20). Cumulative deltas B16+B17+B18+B19+B20 =
19 + 17 + 2 + 6 + 10 = 54; 695 − 54 = 641 matches live. Per-batch
delta is logically consistent with the file diff scope.

**Axis 2 — All 19 callsites tenant-scoped: PASS.** Helper module at
`_invalidation.ts:8-22, 24-38` exports two predicates with the
standard k[0]+tail-t/c shape. Each touched file imports the relevant
helper:
- ProductDetailPage.tsx:25 (predicate), :70 (exact-match wrap)
- ProductForm.tsx:24 (predicate), :177 (exact-match wrap)
- ProductListPage.tsx:8 (tenantScopedKey), :78 (wrap)
- StockLevelsPage.tsx:20 (predicate), :60+ (wraps)
- StockMovementsPage.tsx:7 (tenantScopedKey), :55 (wrap)
- platformQueries.ts:5-6 (tenantScopedKey), :17 (wrap)
- ProductDocumentsTab.tsx, ProductMovementsTab.tsx,
  ProductStockLevels.tsx, PriceInputWithMargin.tsx — all wrap with
  tenantScopedKey, state-value selectors added.

**Axis 3 — State-value selectors used everywhere: PASS.** Every
touched component reads `useAuthStore((s) => s.user?.tenant_id ??
null)` and `useCompanyStore((s) => s.currentCompanyId ?? null)`.
Where the old code used `getCurrentCompany()` action selector
(ProductDetailPage, ProductListPage, ProductDocumentsTab), the new
code uses `useCompanyStore((s) => s.companies.find(c => c.id ===
s.currentCompanyId) ?? null)` — subscribes to state values directly
(B2 round-1 F1 lesson applied correctly).

**Axis 4 — `enabled` gates preserve + extend: PASS.** Every wrapped
useQuery's enabled gate AND-combines the pre-existing condition
(Boolean(id) / isEditing / barcode validity / debouncedValue > 0)
with `!!tenantId && !!companyId`. PriceInputWithMargin.tsx:65 is
the cleanest example.

**Axis 5 — Async + await mutation cascades: PASS.** All mutations
in ProductDetailPage (deleteMutation), ProductForm (create + update),
and StockLevelsPage (adjust + receive + issue + transfer) use
`onSuccess: async () => { await queryClient.invalidateQueries(...) }`.
ProductForm's updateMutation correctly uses `Promise.all` to cascade
the plural-list predicate AND the singular detail exact-match wrap.

**Axis 6 — Predicates gate by namespace + tenant/company suffix:
PASS.** Predicate unit tests at tenantScope.test.tsx:533-547 cover
the positive case (list keys), negative case (singular product +
sibling stock-movements + sibling product-stock + wrong-tenant) for
both predicates. The `inventoryProductsInvalidationPredicate`
correctly rejects `['product', id, ...]` singular keys — pairs
cleanly with the exact-match singular wrap to avoid double-invalidate.

**Axis 7 — L7 per-call counter cascade present: PASS.** Test at
tenantScope.test.tsx:602-642 ('predicate-based mutation cascades
refetch active products and stock-levels lists') drives both
`productMutate?.()` and `stockMutate?.()` via real useMutation hooks,
asserts productListCalls and stockLevelCalls go from 1 to 2. Vacuous
predicate would leave them at 1.

**Axis 8 — L18 cross-tenant DATA isolation present: PASS.** Test at
tenantScope.test.tsx:644-697 ('tenant-A product list data does not
contain tenant-B entries') pre-seeds tenant-B `['products', {page:1},
'tenant-B', 'company-1']` with `[{id: 'leaked-tenant-b-product'}]`,
uses a custom QueryClient with `gcTime: Infinity` (B2 lesson
applied), renders ProductListPage under tenant-A, asserts:
- tenant-A data.data === [] (empty mock response, not tenant-B)
- tenant-A IDs do NOT contain 'leaked-tenant-b-product'
- tenant-B cache entry SURVIVES unchanged

This is the strict L18 shape applied upfront.

**Axis 9 — Existing tests still pass: PASS.** Pre-existing
ProductDocumentsTab.test.tsx and ProductMovementsTab.test.tsx
updated with the new store mock shape (object-with-getState +
selector dispatch) so the new state-value selectors work in mocked
contexts. All 24 component tests pass.

## Quality gates (re-run by Opus at HEAD)

- `pnpm vitest run src/features/inventory/`: **45/45 pass** across
  6 files (7 tenantScope + 24 component + 14 other).
- `pnpm vitest run src/features/inventory/__tests__/tenantScope.test.tsx`:
  **7/7 pass** with React act() warnings (expected for
  renderWithProviders + useQuery).
- `audit-tanstack-keys`: live count 641 (cumulative post-B20);
  per-batch delta 19 verified logically.
- `php artisan sweep:inventory:verify-history`: 3652 events / 1205
  callsites / 0 problems (pre-lock).

Verdict: APPROVE
