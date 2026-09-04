# HANDBACK — Request Hygiene Phase A, Task 5

**Debounce the shared LineItemEntryBar product search (S-4)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t5`
- Branch: `lane/rh-t5-product-search`
- Base: `7f86dbf0c` (`Merge branch 'lane/rh-t2-stock-movements' into dev`)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 5` (rev 12)
- Scope: **web only.** `apps/api` untouched.
- Commits: see §5.

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx` | The trimmed query now passes through the existing `useDebouncedValue(value, 250)` helper before it reaches the read. `queryKey` is `tenantScopedKey(['line-entry-products', debouncedQuery])` — same root, tenant/company still the suffixes. `params.search` uses `debouncedQuery`. Added `placeholderData: keepPreviousData` so the suggestion list keeps the previous page of results instead of blanking to the "Loading" branch on every settled term. Debounce interval is the named constant `PRODUCT_SEARCH_DEBOUNCE_MS = 250` (no magic number). |
| `apps/web/src/components/molecules/line-items/LineItemEntryBar.test.tsx` | Added the fake-timer test `waits 250 ms and sends exactly the final product search`; imports widened to `act, fireEvent, render, screen, waitFor`; the suite `afterEach` now calls `vi.useRealTimers()` before `resetTenant()` so a fake-timer test can never leak into the rest of the file. |

Nothing else changed. In particular: `useDebouncedValue` itself, `tenantScopedKey`, the four consumer pages, `DocumentForm.tsx`, `ProductController.php` and every Inventory Counting file are untouched (global constraints, plan line 15 block).

### Behaviour delta

- **Before:** every committed keystroke produced a new `queryKey` → one `GET /products?per_page=20&search=…` per character. Typing `abc` = 3 requests.
- **After:** the key only changes 250 ms after the last keystroke → typing `abc` = 1 request, carrying `search: 'abc'`.
- **Unchanged:** the empty-query focus read still fires immediately (the debounced value starts at `''`, which equals the initial trimmed query, so no timer has to elapse). `enabled: !disabled && isOpen && tenantId !== null && companyId !== null` is untouched, so the tenant/company gate and the "no read without tenant state" behaviour still hold.

### Hook verification (dispatch precondition)

`useDebouncedValue` exists and matches the plan's call shape:

```
apps/web/src/lib/hooks.ts:8
export function useDebouncedValue<T>(value: T, delay = 250): T
```

Generic, `setTimeout`/`clearTimeout` on `[value, delay]`. It is already the debounce used by six sibling molecules (`ProductPicker`, `PartnerPicker`, `UserPicker`, `BankPicker`, `ServicePicker`, `VehiclePicker` — all at `250`), so this lane makes the entry bar consistent with the picker family rather than introducing a new pattern.

---

## 2. Consumer inventory (`grep -rn "LineItemEntryBar" apps/web/src`)

Four production consumers render the real component:

| # | Consumer | Surface | Line |
|---|---|---|---|
| 1 | `apps/web/src/features/documents/components/DocumentLineEditor.tsx` | Sales / documents line editor (quote, order, invoice, credit note, PO) | `:1168` |
| 2 | `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` | Stock transfer line entry (uses `onBeforeAdd` + `onNotFound`) | `:935` |
| 3 | `apps/web/src/features/replenishment/pages/ReplenishmentCapturePage.tsx` | Replenishment capture (uses `onBeforeAdd`) | `:97` |
| 4 | `apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx` | Inventory counting sheet build | `:407` |

Plus the barrel re-export `apps/web/src/components/molecules/line-items/index.ts:19,22`.

**None of the four relies on the immediate (undebounced) query.** All four are pure `onAddProduct`-style callback consumers: they receive an already-selected `ProductLineProduct` and never read the query, the query key, or the request cadence. Consumers 2 and 3 additionally pass `onBeforeAdd`, which runs on commit, not on search.

**Nobody invalidates or reads the old key shape.** `grep -rn "line-entry-products" apps/web/src apps/pos/src` returns exactly four hits and there is **no `invalidateQueries` / `removeQueries` / `setQueryData` on this root anywhere**:

| Hit | Kind |
|---|---|
| `apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx:71` | the (only) producer |
| `apps/web/src/components/molecules/line-items/LineItemEntryBar.test.tsx:150` | test fixture `['line-entry-products', 'creme', 'tenant-A', 'company-1']` |
| `apps/web/src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx:193,198` | test fixture `['line-entry-products', 'Product', 'tenant-A', 'company-1']` |

The key **shape** is unchanged (`['line-entry-products', <query>, tenant, company]`); only the *timing* at which the query segment settles changed. Both fixtures use real timers with `waitFor`, so a 250 ms settle is inside the default 1 s timeout — and both still pass (§3).

Test files that `vi.mock` the bar (unaffected by construction, run anyway in §3): `ReplenishmentCapturePage.test.tsx:35`, `DocumentLineEditor.purchasePriceDefault.test.tsx:97`, `CreateCountingZoneScope.test.tsx:47`, `CreateCountingPage.test.tsx:56`.

---

## 3. Evidence

All commands from `<worktree>/apps/web` unless noted.

### Step 2 — RED (before the implementation, exactly as required)

`pnpm vitest run src/components/molecules/line-items`

```
 ❯ src/components/molecules/line-items/LineItemEntryBar.test.tsx (12 tests | 1 failed) 796ms
   ✓ LineItemEntryBar > adds the highlighted search result with Enter and keeps the input focused 257ms
   ✓ LineItemEntryBar > scopes the product search read key with the active tenant + company 65ms
   ✓ LineItemEntryBar > shows first-page product suggestions when focused with an empty query 45ms
   ✓ LineItemEntryBar > commits the highlighted focus suggestion with Enter when the query is empty 42ms
   ✓ LineItemEntryBar > does not commit a focus suggestion with Tab when the query is empty 48ms
   ✓ LineItemEntryBar > commits a focus suggestion on mouse click 51ms
   ✓ LineItemEntryBar > closes product suggestions on pointerdown outside 33ms
   ✓ LineItemEntryBar > does not fire the product search without tenant/company state 30ms
   ✓ LineItemEntryBar > resolves scanner-like Enter through the code resolver and never submits the parent form 65ms
   ✓ LineItemEntryBar > highlights the first search result and emits it on Enter with an explicit accessible name 110ms
   ✓ LineItemEntryBar > resolves a variant scanner code and emits a direct variant add 11ms
   × LineItemEntryBar > waits 250 ms and sends exactly the final product search 38ms
     → expected [ [ '/products', …(1) ], …(2) ] to have a length of +0 but got 3

 FAIL  … > waits 250 ms and sends exactly the final product search
AssertionError: expected [ [ '/products', …(1) ], …(2) ] to have a length of +0 but got 3
 ❯ src/components/molecules/line-items/LineItemEntryBar.test.tsx:428:27
    428|     expect(searchCalls()).toHaveLength(0)

 Test Files  1 failed | 4 passed (5)
      Tests  1 failed | 23 passed (24)
```

`3` = one search request per committed keystroke (`a`, `ab`, `abc`) — the exact S-4 defect.

### Falsification re-check — RED again with the FINAL test text

The red run above used the plan's literal `as { params?: … }` cast; that cast was replaced by a type guard (Deviation D1). To prove the shipped assertion is still falsifying, the component was reverted to `7f86dbf0c` (copy parked in the session scratchpad, restored immediately after) and only this test re-run:

`pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx -t "waits 250 ms"`

```
   × LineItemEntryBar > waits 250 ms and sends exactly the final product search 210ms
     → expected [ [ '/products', …(1) ], …(2) ] to have a length of +0 but got 3
AssertionError: expected [ [ '/products', …(1) ], …(2) ] to have a length of +0 but got 3
      Tests  1 failed | 11 skipped (12)
```

### Step 4 — GREEN, the named path

`pnpm vitest run src/components/molecules/line-items`

```
 ✓ src/components/molecules/line-items/useProductLineLookup.test.tsx (3 tests) 23ms
 ✓ src/components/molecules/line-items/ProductCell.test.tsx (3 tests) 87ms
 ✓ src/components/molecules/line-items/LineItemsTable.test.tsx (4 tests) 194ms
 ✓ src/components/molecules/line-items/ProductLineSelect.test.tsx (2 tests) 203ms
 ✓ src/components/molecules/line-items/LineItemEntryBar.test.tsx (12 tests) 749ms

 Test Files  5 passed (5)
      Tests  24 passed (24)
```

12 tests in the touched file (11 pre-existing + 1 new), 24 in the directory.

### Consumer suites that render the REAL bar

`pnpm vitest run src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx`

```
 ✓ src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx (8 tests) 2576ms
 Test Files  2 passed (2)
      Tests  11 passed (11)
```

(`DocumentComponents.tenantScope.test.tsx` is the file that asserts the live `['line-entry-products', 'Product', 'tenant-A', 'company-1']` key — it passes unchanged, confirming the key shape survived.)

### Consumer suites that mock the bar

`pnpm vitest run src/features/replenishment/__tests__/ReplenishmentCapturePage.test.tsx src/features/inventory-counting/__tests__/CreateCountingZoneScope.test.tsx src/features/inventory-counting/pages/__tests__/CreateCountingPage.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx`

```
 ✓ src/features/replenishment/__tests__/ReplenishmentCapturePage.test.tsx (3 tests) 235ms
 ✓ src/features/inventory-counting/pages/__tests__/CreateCountingPage.test.tsx (8 tests) 391ms
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.purchasePriceDefault.test.tsx (17 tests) 636ms
 ✓ src/features/inventory-counting/__tests__/CreateCountingZoneScope.test.tsx (10 tests) 1164ms

 Test Files  4 passed (4)
      Tests  38 passed (38)
```

Grand total run for this lane: **73 tests, 0 failures.**

### typecheck

`pnpm typecheck`

```
> @autoerp/web@0.1.0 typecheck /Users/houssamr/…/.worktrees/rh-t5/apps/web
> tsc --noEmit
```

Zero output = zero errors (whole-project `tsc --noEmit`, not path-scoped).

### eslint — per-file, before vs after

Baseline captured by materialising the `7f86dbf0c` versions **inside the same directory** (`BaselineT5EntryBar.tsx` / `.test.tsx`, the test's import rewritten to the copy) so directory-scoped rule overrides applied identically, then deleted (`git status` clean, §5).

| File | Before (`7f86dbf0c`) | After |
|---|---|---|
| `LineItemEntryBar.tsx` | 0 errors, 1 warning — `89:75 @typescript-eslint/no-unsafe-type-assertion` ("type 'Node' is more narrow…") | 0 errors, 1 warning — **same** warning, now at `96:75` (line shift from the 7 added lines) |
| `LineItemEntryBar.test.tsx` | 0 errors, 0 warnings (no output) | 0 errors, 0 warnings (no output) |

**No new errors, no new warnings.** The single component warning is pre-existing (`containerRef.current.contains(event.target as Node)` in the pointerdown handler, untouched by this lane).

Interim data point for the reviewer: the plan's literal test snippet **did** add a warning (`421:24 @typescript-eslint/no-unsafe-type-assertion`, "Unsafe assertion from `any`"). That is what Deviation D1 removes.

### Browser checks — NOT RUN (promotion-owed)

The plan's Step 4 asks for browser checks of Sales, transfer, replenishment and counting product entry, and rev 12's onboarding-safety table marks Task 5 **Conditional** on exactly that. **No stack was up tonight, so none of the four were browser-checked.** They remain **promotion preconditions**, not merge preconditions:

1. Sales / document line editor — type fast into the entry bar, confirm one `/products` request per settled term in the network panel and that the dropdown does not flicker empty (that is what `keepPreviousData` is for).
2. Create stock transfer — same, plus confirm the `onBeforeAdd` veto (no source location) still blocks the add.
3. Replenishment capture — same, plus the `onBeforeAdd` veto.
4. Create counting — same; confirm the sheet still accepts scanned codes (scan path bypasses the debounce entirely and must be unaffected).

Also worth an eye during the browser pass: barcode-wedge scans typed *into the input* still resolve on Enter through `resolveScan(trimmedQuery)` — that path reads `trimmedQuery`, **not** the debounced value, so a fast wedge scan followed by Enter resolves with zero added latency. This is deliberate (see §6).

---

## 4. Deviations from the plan text

**D1 — the `searchCalls` filter uses a type guard, not the plan's `as` cast.** Plan Step 1 prescribes:

```tsx
const searchCalls = () => apiClientGetMock.mock.calls.filter(
  ([, config]) => (config as { params?: { search?: string } } | undefined)?.params?.search !== undefined,
)
```

Shipped verbatim, that cast produces a **new** ESLint warning (`421:24 @typescript-eslint/no-unsafe-type-assertion` — "Unsafe assertion from `any`"), which violates the lane's "no new warnings vs base" bar and the repo's no-`any` rule. Replaced with an assertion-free narrowing guard:

```tsx
function hasSearchParam(config: unknown): boolean {
  if (typeof config !== 'object' || config === null || !('params' in config)) return false
  const params: unknown = config.params
  return typeof params === 'object' && params !== null && 'search' in params
}

const searchCalls = () => apiClientGetMock.mock.calls.filter(([, config]) => hasSearchParam(config))
```

Semantics are identical (select calls whose config carries a `params.search`), the two assertions the plan cares about are unchanged (`toHaveLength(0)` at 249 ms, `toHaveLength(1)` + exact `{ params: { per_page: 20, search: 'abc' } }` at 250 ms), and the test is still falsifying against the base component (proved above).

**D2 — the debounce interval is a named constant.** The plan writes `useDebouncedValue(trimmedQuery, 250)` inline. Shipped as `PRODUCT_SEARCH_DEBOUNCE_MS = 250` at module scope, matching how the sibling `ToBillPage` names its `PARTNER_SEARCH_DEBOUNCE_MS`. Value identical; the test's 249/250 boundary is unaffected.

**D3 — the plan's `afterEach` block is merged into the existing one.** The plan shows a standalone `afterEach(() => { vi.useRealTimers(); resetTenant() })`. The file already had `afterEach(() => { resetTenant() })` inside the `describe`; a second top-level block would have double-reset the stores. `vi.useRealTimers()` was added as the first statement of the existing block instead.

**D4 — extra verification beyond the plan.** Ran the six consumer test files (§3) and the second RED falsification pass; neither is in the plan's Step 4. No production code was changed to make them pass.

**No other deviations.** Steps 1→4 were executed in order; `useDebouncedValue` exists as specified, so the "say so and stop" branch of the dispatch brief did not trigger.

---

## 5. Commits (path-scoped, on `lane/rh-t5-product-search`)

| Hash | Subject |
|---|---|
| `301352c62` | `fix(web request-hygiene t5): debounce LineItemEntryBar product search (S-4)` — 2 files changed, 65 insertions(+), 4 deletions(-) |
| (this file) | `docs(request-hygiene t5): handback` |

`git status` is clean at handback time; the two ESLint baseline copies and the scratchpad component copy were deleted before the first commit.

Housekeeping honoured: no `git stash`, no `apps/api` file touched, path-scoped `git commit -- <paths>`, vitest run by path only (never the whole web suite).

---

## 6. What the reviewer should look at

Gate: **frontend-conventions-reviewer**.

1. **Deviation D1** — is the type guard an acceptable substitute for the plan's literal cast? It is the only place the shipped test text differs from the gate-cleared plan.
2. **`placeholderData: keepPreviousData`** — with `staleTime: 30000` and `gcTime: 0` in the test wrapper, confirm the intended UX (keep the last result set visible while the next settled term loads) is what lands, and that it cannot surface a *stale tenant's* rows: the tenant/company suffix is part of the key, so a tenant switch produces a different key with no placeholder ancestor. Worth a second pair of eyes.
3. **The scan path deliberately still reads `trimmedQuery`, not `debouncedQuery`** (`handleKeyDown` → `resolveScan(trimmedQuery)`, `LineItemEntryBar.tsx`). A barcode wedge that types a code and presses Enter must resolve instantly; debouncing that path would add 250 ms to every scan. Confirm this is the wanted split. The consequence is that `handleKeyDown`'s Enter branch can, for one frame, choose between "commit the highlighted suggestion" (driven by `products`, i.e. the *debounced* list) and "resolve as a code" (driven by `trimmedQuery`) using two values that disagree during the 250 ms window. In practice the suggestion branch requires `isOpen && products.length > 0`; with an empty/absent match list it falls through to `resolveScan`, which is the pre-existing behaviour. Pre-existing tests `resolves scanner-like Enter through the code resolver…` and `adds the highlighted search result with Enter…` both still pass, and both exercise that fork.
4. **Key shape and invalidation** — §2's grep table: the root `line-entry-products` is unchanged and has zero invalidation callers, so no prefix invalidation broke.
5. **Browser checks are owed at promotion, not at merge** (§3, last block). Task 5 is "Conditional" in the plan's rev 7/12 safety table specifically on those four surfaces.
6. **`useDebouncedValue` was reused, not reinvented** — `apps/web/src/lib/hooks.ts:8`, already the debounce of six sibling picker molecules at the same 250 ms.
