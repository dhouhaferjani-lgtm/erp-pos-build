# GATE 1 — Location Placement Phase 2 (Wave 1: Tree management UI)

**Scope:** `git diff origin/dev..HEAD` (`0ea41e1ad`, `023e04fe3`) vs spec §5–§7, §10.
**Evidence caveat:** static review + `grep`; vitest / `audit:design-system` / `audit:keys` runners were blocked by sandbox approval, so test-PASS marks are by inspection, not a green run.

## Gate criteria — all 10 PASS

1. **Hardcoded colors (0-new):** grep of `src/features/placement/` → 0 hits; baseline JSON only *removed* the deleted `BulkAssignDialog` entry. ✅
2. **`tenantScopedKey` on queries:** `PlacementPage.tsx:53,70`, `NodeProductsPanel.tsx:42`, `CreateCountingPage.tsx:487`. ✅
3. **Invalidation direction:** raw prefixes at `PlacementPage.tsx:79` / `NodeProductsPanel.tsx:48-49` — correctly *not* wrapped, so they prefix-match tenant-suffixed keys. ✅
4. **Stale `zones.*` keys:** namespace removed all 3 locales; guarded by new `staleZoneKeys.test.ts`; live grep of counting/settings = 0; no dangling imports. ✅
5. **en/fr/ar parity:** `placement.*` key-set identical across locales; `navigation.placement` added to all `common.json`; AR/FR fully translated. ✅
6. **parseFloat/Number on qty:** 0 hits (labels-only surface). ✅
7. **Subtree-query drift:** FE `tree.ts:39-41 isDescendant` uses `/`-delimited form. ✅
8. **Prefix-collision A1/A10:** `tree.ts:40` appends `/` → `A10/`.startsWith(`A1/`) false. ✅
9. **view vs adjust gating:** route `permission="inventory.view"` (`routes/index.tsx:1033` + `routes.test.tsx`); writes `inventory.adjust` (`PlacementPage.tsx:44`); view-only test at `PlacementPage.test.tsx:157-166`. ✅
10. **types transformed:** `generated.d.ts:886-912` has `product_count` + `ProductPlacementDto`; `CountingScopeType` keeps `'zone'` (D7). ✅

## Findings (all Low, non-blocking)

- **F1 [i18n]** `en/fr inventory.json:1051-1052` toasts still say "Zone created/updated" while the rest of the namespace (and AR) says "Storage node". Not caught by the key-only stale test.
- **F2 [UX]** `PlacementPage.tsx:218` reorder copies `target.sort_order` → sibling collision, tie-broken by name; imprecise placement.
- **F3 [caching]** `NodeProductsPanel.tsx:47-50` bulk-move doesn't invalidate `['placement','node-products', targetId]` — target list stays stale until remount.
- **F4 [cross-wave]** `CreateCountingPage.tsx:486-490` repoint surfaces all node types as flat "zones"; subtree picker/expansion is Wave 4 — confirm interim state OK.
- **F5 [convention]** `LocationsPage.tsx:339` uses raw `<a href>` (full reload) instead of react-router nav.
- **F6 [verify]** `NodeFormDialog.tsx:10` regex bans `_` (matches D11 prose; spec's literal regex contradicts by allowing it) — confirm backend `CreateNodeRequest` also bans `_`.

## VERDICT: APPROVE
