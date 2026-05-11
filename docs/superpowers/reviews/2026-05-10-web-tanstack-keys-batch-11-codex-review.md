Commit reviewed: c10a8a97

1. Scanner delta: APPROVE. The allowed brief records callsites web.tanstack-keys.376-.387 and the expected 760 -> 748 reduction at docs/superpowers/audits/2026-05-10-web-tanstack-keys-batch-11-codex-prompt.md:10-11, with live confirmation of 748 at line 24.

2. State-value selectors: APPROVE. The two menu queries select `tenantId` and `companyId` before enabling tenant-scoped queries in `useMenus` and `useMenu` at apps/web/src/features/menu/hooks/useMenus.ts:59-75. All 9 mutation hooks select the same state values before invalidation at apps/web/src/features/menu/hooks/useMenus.ts:79-204. `MenuCategoryItemManager` imports and uses both stores at apps/web/src/features/menu/components/MenuCategoryItemManager.tsx:8-10 and apps/web/src/features/menu/components/MenuCategoryItemManager.tsx:36-37.

3. Predicate gates: APPROVE. `menusInvalidationPredicate` requires `k[0] === 'menus'` plus matching tenant/company suffixes at apps/web/src/features/menu/hooks/useMenus.ts:43-55. Tests assert menu list/detail positives and sibling/wrong-scope negatives, including `composite_item-search`, `product-search`, wrong tenant, and wrong company at apps/web/src/features/menu/__tests__/tenantScope.test.tsx:127-142.

4. Cascade tests: APPROVE. The cascade probe installs per-render list/detail counters at apps/web/src/features/menu/__tests__/tenantScope.test.tsx:185-201, registers all 9 mutation hooks at lines 203-222, and uses `it.each` for the 9 callsites at lines 245-255. Each case waits for counters at 1, runs the mutation, then requires both counters to reach 2 at lines 260-268, so an always-false predicate would fail.

5. Cross-tenant isolation: APPROVE. The isolation test seeds a tenant-B detail query at apps/web/src/features/menu/__tests__/tenantScope.test.tsx:271-282, runs a tenant-A mutation at line 284, then verifies the tenant-B data remains unchanged and `state.isInvalidated` is false at lines 288-290.

6. MenuCategoryItemManager: APPROVE. The component imports `tenantScopedKey`, `useAuthStore`, and `useCompanyStore` at apps/web/src/features/menu/components/MenuCategoryItemManager.tsx:8-10. Its search query wraps the runtime-computed namespace in `tenantScopedKey([searchTab + '-search', searchQuery])` at line 45 and gates execution on `showAddForm`, tenant, and company at line 52.

Novel-shape analysis: APPROVE. The runtime-computed first element is present in `MenuCategoryItemManager` at apps/web/src/features/menu/components/MenuCategoryItemManager.tsx:44-45. The brief states this is scanner-accepted because the scanner requires the outer call expression to be a bare-Identifier `tenantScopedKey()` and does not restrict argument shape at docs/superpowers/audits/2026-05-10-web-tanstack-keys-batch-11-codex-prompt.md:17-18. The menu cascade remains isolated because the predicate only admits `menus` keys at apps/web/src/features/menu/hooks/useMenus.ts:49-55, and the negative assertions for `composite_item-search` and `product-search` confirm the search namespaces are excluded at apps/web/src/features/menu/__tests__/tenantScope.test.tsx:134-140.

Verdict: APPROVE
