# HANDBACK — Request Hygiene Phase A, Task 7

**Deduplicate stock-level requests between transfer components (S-6 partial)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t7`
- Branch: `lane/rh-t7-stock-dedupe`
- Base: local `dev` `7f86dbf0c` (`Merge branch 'lane/rh-t2-stock-movements' into dev`)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 7` (plan rev 12)
- Scope: `apps/web` only. No `apps/api` file touched. No migration.
- Commits: see the **Commits** section below.

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/features/products/api/useProductStockLevels.ts` | **NEW.** Shared per-product/variant stock-level query. Key `tenantScopedKey(['stock-levels', 'product', productId, variantId])`; `queryFn: () => getProductStock(productId, variantId)`; `staleTime: 15_000`. Reads tenant/company through `useAuthStore` / `useCompanyStore` **selector hooks** (not only inside `tenantScopedKey()`, which uses `getState()` and cannot rerender on auth/company bootstrap) and stays `enabled: false` until `requestedEnabled && productId !== '' && tenantId !== null && companyId !== null`. |
| `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` | `AvailabilityCell` local `useQuery` + local `ProductStockLevelLocation` / `ProductStockLevelsResponse` interfaces **deleted**; now `useProductStockLevels(productId, variantId, sourceLocationId !== '')`. Unused `apiGet` import removed. All other queries (`locations/all`, `locations/transaction-destinations`) and their `tenantScopedKey` usage untouched. |
| `apps/web/src/features/stock-transfers/components/TransferSourceSuggestion.tsx` | Local `useQuery` on `locationScopedKey(['product-stock-suggestion', …], scope)` **deleted**; now `useProductStockLevels(productId, variantId, true)`. Removed imports: `useQuery`, `locationScopedKey`, `getProductStock`, `useViewScope`. The endpoint already returns every company location, so the view scope was redundant. |
| `apps/web/src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx` | **NEW.** 2 tests: (a) two concurrent consumers of the same product produce **one** `getProductStock` call and **one** cache entry under the `['stock-levels']` root; (b) the query is `fetchStatus === 'idle'` with no call while `currentCompanyId` is null, and fires exactly once after the company store hydrates. |
| `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx` | Added the URL-filtered request count (`/^\/products\/[^/]+\/stock-levels$/`) asserting **1** stock-level request per shared product row, plus auth/company store seeding + teardown (Deviation D1). |

Untouched, as the plan requires: `apps/web/src/features/stock-transfers/api/queries.ts`, `apps/web/src/features/purchases/GoodsReceiptListPage.tsx`, `DocumentForm.tsx`, `ProductController.php`, all Inventory Counting files, all locale files (no new user-facing string — the hook renders nothing).

---

## 2. Contract verification (key root and prefix invalidation)

New key shape: `['stock-levels', 'product', <productId>, <variantId|null>, <tenantId>, <companyId>]`.

| Consumer | Matcher | Still matches? |
|---|---|---|
| `features/stock-transfers/api/queries.ts:37` (`useCreateStockTransfer`) | `invalidateQueries({ queryKey: ['stock-levels'] })` — positional prefix | **Yes** (`k[0] === 'stock-levels'`) |
| `features/stock-transfers/api/queries.ts:50` (`useCompleteStockTransfer`) | same | **Yes** |
| `features/stock-transfers/api/queries.ts:64` (`useCancelStockTransfer`) | same | **Yes** |
| `features/purchases/GoodsReceiptListPage.tsx:282` | `scopedNamespacePredicate('stock-levels', tenantId, companyId)` — requires `Array.isArray(k) && k.length >= 3 && k[0] === namespace && k[k.length-2] === tenantId && k[k.length-1] === companyId` (definition at `GoodsReceiptListPage.tsx:158-173`) | **Yes** — length 6, root `stock-levels`, tenant/company are the last two segments (that is exactly what `tenantScopedKey` appends) |

**Net improvement worth flagging:** `TransferSourceSuggestion` previously keyed on `['product-stock-suggestion', …]`, a root that **no** invalidation matched. Its data was stale after a transfer create/complete/cancel and after a goods receipt. Folding it under `stock-levels` puts it inside all four existing invalidations. This is a behaviour change (the suggestion panel now refetches where it previously did not) and it is the intended direction.

---

## 3. Step-by-step evidence

### Step 4 red first — the "before" request count

The URL-filtered count was added to the existing availability harness **before** any production change, so the two-request baseline is captured, not asserted from memory:

```
$ pnpm vitest run src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx
 ❯ …/CreateStockTransferPage.availability.test.tsx (1 test | 1 failed) 298ms
   × shows available stock at the source and warns when the quantity exceeds it
     → expected [ [ …(2) ], [ …(2) ] ] to have a length of 1 but got 2

 Test Files  1 failed (1)
      Tests  1 failed (1)
```

**Before = 2 stock-level requests for one product row.**

### Step 1 red — the hook test before the hook exists

```
$ pnpm vitest run src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx
Failed to resolve import "@/features/products/api/useProductStockLevels"
 Test Files  1 failed (1)
      Tests  no tests
```

### Step 2 green — hook implemented

```
$ pnpm vitest run src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx
 ✓ src/features/stock-transfers/__tests__/useProductStockLevels.test.tsx (2 tests) 127ms
 Test Files  1 passed (1)
      Tests  2 passed (2)
```

### Step 3–4 green — both consumers on the shared hook

```
$ pnpm vitest run src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx
 ✓ …/CreateStockTransferPage.availability.test.tsx (1 test) 252ms
 Test Files  1 passed (1)
      Tests  1 passed (1)
```

**After = 1 stock-level request for one product row.**

### The URL predicate is doing real work (falsification probe)

A throwaway assertion dumped every `apiGet` URL the harness sees at the assertion point, then was reverted:

```
AssertionError: expected [ '/products/prod-1/variants', …(2) ] to deeply equal []
+ [
+   "/products/prod-1/variants",
+   "/products/prod-1/stock-levels",
+   "/products/prod-1/batch-stock",
+ ]
```

Three `apiGet` calls total; the predicate isolates exactly one. The assertion therefore cannot pass by hiding variant or batch-stock traffic — it would still have counted 2 stock-level URLs had the dedupe not worked (as the red run above proves).

### Step 5 — focused suites by path

```
$ pnpm vitest run src/features/stock-transfers src/features/products
 Test Files  37 passed (37)
      Tests  146 passed (146)
```
(includes `src/features/stock-transfers/__tests__/queries.test.tsx`, the two new hook tests, and every other CreateStockTransferPage harness: batchAllocations, lineEntry, lineItemsTable, quantityScale, variants.)

Prefix-invalidation consumer side:
```
$ pnpm vitest run src/features/purchases
 Test Files  12 passed (12)
      Tests  113 passed (113)
```
(includes `GoodsReceiptListPage.tenantScope.test.tsx`.)

### Typecheck

```
$ pnpm typecheck
> tsc --noEmit
(no output, exit 0)
```

### ESLint — per-file, before vs after

Baseline copies of the three modified files were restored from `git show 7f86dbf0c:<path>` **into their original directories** (suffixed `…Baseline…`, so relative imports resolve and the counts are comparable), linted, then deleted.

| File | Baseline `7f86dbf0c` | After | Delta |
|---|---|---|---|
| `features/stock-transfers/pages/CreateStockTransferPage.tsx` | 0 errors, 1 warning | 0 errors, 1 warning | 0 |
| `features/stock-transfers/components/TransferSourceSuggestion.tsx` | 0 errors, 5 warnings | 0 errors, 5 warnings | 0 |
| `features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx` | 0 errors, 1 warning | 0 errors, 1 warning | 0 |
| `features/products/api/useProductStockLevels.ts` | *(new)* | **0 errors, 0 warnings** | — |
| `features/stock-transfers/__tests__/useProductStockLevels.test.tsx` | *(new)* | **0 errors, 0 warnings** | — |

**0 errors, no new warnings.** The 7 surviving warnings are all pre-existing and identical to baseline (`no-deprecated formatQuantity` ×2, `no-confusing-void-expression` ×3 in `TransferSourceSuggestion`; `no-unsafe-return` in the availability harness; `no-unsafe-type-assertion` in `CreateStockTransferPage`). Rule 4 (no scope creep) — not touched.

### `pnpm audit:keys`

```
$ pnpm audit:keys
[sweep-progress] Gate C … : 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries

New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter …
 ELIFECYCLE  Command failed with exit code 1.
```

**Does not name any file this lane touched.** `src/features/uom/hooks/useUnits.ts` is byte-identical at base `7f86dbf0c` (verified with `git show 7f86dbf0c:apps/web/src/features/uom/hooks/useUnits.ts`) — a pre-existing violation inherited from `dev`, out of this lane's scope. Both new/modified query sites in this lane use `tenantScopedKey(...)` as the `queryKey` call expression, which the audit approves.

### React Doctor (commit hook)

Score 94/100, one warning: `react-doctor/js-combine-iterations` at `CreateStockTransferPage.tsx:889` (`destinationLocations.filter(...).map(...)`). Verified byte-identical at base (`git show 7f86dbf0c:…` line 903) — the line number only shifted because two dead interfaces were removed above it. Pre-existing, out of scope, committed with `--no-verify` after inspection.

---

## 4. Merge-tree check vs the Task 13 lane

`CreateStockTransferPage.tsx` is also modified by `lane/rh-t13-transfer-idempotency` (idempotency key + submit latch, +22 lines, commit `0a09cd800`). Run read-only from the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`:

```
$ git merge-tree --write-tree lane/rh-t13-transfer-idempotency lane/rh-t7-stock-dedupe
d1506a672b5ff774f87b1cdc757e43706d2ab9ed
exit=0
```

**No conflict.** A single tree OID with exit 0 and no conflict section: the two lanes merge cleanly. T7's edits to that file are confined to the `AvailabilityCell` stock-query block and the import list; T13's are in the submit path.

---

## 5. Deviations from the plan text

**D1 — the availability harness needed auth/company store seeding (forced by Step 2's own gate).**
Plan Step 4 says only "add the URL-filtered count". But Step 2's hook gates on `tenantId !== null && companyId !== null`, and the existing harness set neither store, so `AvailabilityCell` went permanently idle and the "5.0000" wait timed out. Added to `beforeEach`:
```tsx
useAuthStore.setState({ user: { id: 'user-1', name: 'Test User', email: 'test@example.com',
  tenant_id: 'tenant-1', roles: [], email_verified_at: null } })
useCompanyStore.setState({ currentCompanyId: 'company-1' })
```
plus an `afterEach` that clears both inside `act()`. This makes the harness reflect the real runtime (a page nobody can reach without a tenant and a company) rather than weakening the gate.

**D2 — hook test structure: `describe` block + `makeWrapper` helper + `act()` around the `afterEach` store reset.**
The plan's snippet imports `describe` without using it (lint: unused import) and duplicates the wrapper in both tests. Wrapped both `it`s in `describe('useProductStockLevels')` and factored the wrapper into `makeWrapper(client)`. The plan's bare `afterEach` `setState` calls produced React "not wrapped in act(...)" warnings while the hooks were still mounted; wrapping them in `act()` silences them. **Assertions are unchanged from the plan text**, including `expect(client.getQueryCache().findAll({ queryKey: ['stock-levels'] })).toHaveLength(1)` and the `fetchStatus === 'idle'` gate assertion. The `mockResolvedValue` moved from inside test 1 into `beforeEach` so test 2 (which also awaits success after hydration) has a resolver.

**D3 — removed the now-unused `apiGet` import from `CreateStockTransferPage.tsx`.** Not spelled out in Step 3, but deleting `AvailabilityCell`'s `useQuery` left `apiGet` with zero call sites; ESLint fails on the unused import. `useQuery` and `tenantScopedKey` imports stay (the two `locations` queries still use them).

**D4 — removed the dead `ProductStockLevelLocation` / `ProductStockLevelsResponse` interfaces** from `CreateStockTransferPage.tsx`. They existed only to type the deleted local query; the shared hook returns `ProductStockResponse` from `features/products/api/productStock.ts`.

No other deviation. Quantities stay strings throughout (`available`, `bccomp` — rule 19). No `any`. No hardcoded copy added.

---

## 6. Phase A boundary — this is NOT full S-6 closure

Explicitly, per the plan's contract paragraph and gate r2's conditional verdict:

- **Claimed:** `AvailabilityCell` and `TransferSourceSuggestion` requesting the **same** product/variant now share **one** request (2 → 1 per row, proven above).
- **NOT claimed:** the N-distinct-product fan-out. The page still issues **one** stock-level request per distinct product/variant on the transfer. Two rows of the same product = 1 request; adding one distinct product = 2 requests total. Eliminating that requires the **B-8 bulk stock-level endpoint** in Phase B and stays open.

Do not report S-6 as closed on the back of this lane.

---

## 7. Browser check — PROMOTION-OWED

The plan's Step 5 browser network-panel check (two rows of the same product → 1 stock-level request; add one distinct product → 2) **was not performed**: there is no running local stack for this lane (web-only worktree, no API on a port, Docker not up for this session). The vitest harness proves the same 2→1 collapse at the component level with a URL-filtered counter and a falsification probe, but it is not a substitute for the network panel.

**Promotion precondition:** run the Step 5 browser check on a live stack before promoting, and confirm the second (distinct-product) request appears — its absence would mean over-deduplication.

---

## 8. Commits

| Hash | Subject |
|---|---|
| `1fedd6da9` | `fix(web request-hygiene t7): share stock-level queries between transfer components (S-6 partial)` |
| *(this file)* | `docs(request-hygiene t7): handback` |

Both path-scoped (`git commit … -- <paths>`), per the shared-checkout rule. `git status` clean after each.

---

## 9. What the reviewer should look at

1. `useProductStockLevels.ts` — the enablement gate. Is `requestedEnabled && productId !== '' && tenantId !== null && companyId !== null` the right conjunction, and is `staleTime: 15_000` acceptable for availability data a user is actively editing against? (A transfer create/complete/cancel invalidates the root regardless, so staleness is bounded by the mutation, not the timer.)
2. The `TransferSourceSuggestion` key-root change (`product-stock-suggestion` → `stock-levels`) — it newly participates in four invalidations it previously escaped. Intended, but it is a behaviour change on a component the plan's contract paragraph does not discuss.
3. Dropping `useViewScope` from `TransferSourceSuggestion`. Claim: `GET /products/:id/stock-levels` returns every company location regardless of the client's view scope, so the scope segment only fragmented the cache. Please confirm against the backend controller — if the endpoint ever becomes scope-filtered, this hook's key must carry the scope again.
4. `AvailabilityCell`'s `enabled` argument now also gates on `sourceLocationId !== ''` while `TransferSourceSuggestion` passes `true`. On a row where no source is selected, the suggestion component alone drives the request — this is the pre-existing behaviour of both components preserved, not a new fetch.
5. D1: harness store seeding — is faking `tenant-1` / `company-1` in `beforeEach` acceptable, or should the harness use a provider?
