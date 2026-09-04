# Gate — request-hygiene Phase A Task 5 (debounce `LineItemEntryBar` product search, S-4)

- Gate: **frontend-conventions-reviewer** (adversarial merge gate)
- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t5`
- Branch: `lane/rh-t5-product-search` @ `368119f40` — base `7f86dbf0c`
- Diff reviewed: `git diff 7f86dbf0c..368119f40` (3 files: component, its test, handback)
- Handback: `docs/handoff/HANDBACK-request-hygiene-T5-2026-09-04.md`
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 5` (lines 1594–1657)

## VERDICT: **CHANGES**

The debounce itself is implemented exactly as the plan asks, reuses the sibling hook, keeps the
tenant-scoped key shape, and the new test is genuinely falsifying (independently re-verified below).
But the change **regresses the barcode / fast-typed Enter path into a silent wrong-product add**
across all four consumers, and `placeholderData: keepPreviousData` **does** cross a company-scope
change (the handback's §6.2 reasoning about this is factually wrong). Both are proven empirically
below with throwaway probe tests (created, run, deleted; `git status` clean after).

---

## 1. BLOCKING findings

### B1 — BLOCKER: a fast-typed barcode + Enter now adds the FIRST-PAGE suggestion instead of resolving the scan

`apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx:75` (debounce) +
`:89` (`placeholderData: keepPreviousData`) +
`:209` (`if (isOpen && products.length > 0 && (event.key === 'Enter' || trimmedQuery !== ''))`) +
`:224` (`resolveScan(trimmedQuery)` — now unreachable in the common case).

**Mechanism.** `products` is derived from the query that is keyed on `debouncedQuery`
(`:77`, `:92`). `resolveScan` reads `trimmedQuery` (`:224`). During the 250 ms settle window the two
disagree: the input holds the full barcode, `products` still holds the list for the *previous*
settled term. Focus (or the post-add auto-refocus at `:113-118` → `onFocus` at `:247-249`) loads a
non-empty first page of 20 products, so `products.length > 0` is TRUE at the moment Enter arrives
and the suggestion branch at `:209` wins. `keepPreviousData` extends the window past the debounce
for the whole request RTT, because `isLoading` is false and the previous rows stay mounted.

**Before this diff** the key changed on every keystroke and there was no `placeholderData`, so
`data` was `undefined` while the request was in flight → `products = []` → the fork fell through to
`resolveScan`. That is why the pre-existing test at `LineItemEntryBar.test.tsx:268`
("resolves scanner-like Enter through the code resolver…") still passes: its fixture is
`apiClientGetMock.mockResolvedValue({ data: { data: [] } })` (`:274`), i.e. an always-empty product
list. It cannot see this regression.

**Falsifying scenario (measured, not argued).** Probe test rendering the base component and the lane
component side by side; the empty-query focus read resolves, any `search` read stays in flight
(models the operator pressing Enter before the server answers):

```
   ✓ BASE (7f86dbf0c): fast-typed barcode + Enter resolves through the code resolver 91ms
   × LANE:             fast-typed barcode + Enter resolves through the code resolver 30ms
     → expected "spy" to be called with arguments: [ '/line-entry/resolve-code', …(1) ]
       Number of calls: 0
   ✓ LANE: what it actually did instead (documents the wrong-product add)
LANE onAddProduct calls: [["product-FIRST-PAGE","Adhesif carrosserie",{"source":"search","incrementBy":1,"variantId":null}]]
LANE resolve-code calls: 0
```

A second probe isolates the two mechanisms — Enter sent 300 ms after the last keystroke (debounce
already elapsed, the `search` request in flight):

```
   ✓ BASE: 300 ms after the last keystroke, search in flight, Enter -> scan resolver  324ms
   × LANE: 300 ms after the last keystroke, search in flight, Enter -> scan resolver  326ms
PROBE2 LANE calls: [["product-FIRST-PAGE",{"source":"search","incrementBy":1,"variantId":null}]]
```

So **both** the debounce window *and* `keepPreviousData` independently keep the stale list alive
across the Enter fork. The same stale list is also mouse-clickable (`:302-304` →
`commitSearchAdd`), so this is not Enter-only.

**Blast radius.** Wrong product silently added to: sales/purchase document lines
(`features/documents/components/DocumentLineEditor.tsx:1168`), stock transfers
(`features/stock-transfers/pages/CreateStockTransferPage.tsx:935`), replenishment capture
(`features/replenishment/pages/ReplenishmentCapturePage.tsx:97`) and — worst — counting sheets
(`features/inventory-counting/pages/CreateCountingPage.tsx:407`), where blind counting (owner rule
A-9) means the operator has no expected-quantity cue that would expose the wrong line. The
scan-add-scan-add loop is the *designed* workflow here (`resetAfterAdd` refocuses and re-opens the
dropdown), so every scan after the first hits a populated stale list.

**Fix directive.** Derive a settled flag and use it on BOTH the commit fork and the render branch,
e.g. `const suggestionsSettled = debouncedQuery === trimmedQuery && !isPlaceholderData` (take
`isPlaceholderData` from `useQuery`); require `suggestionsSettled` in the `:209` condition (so an
unsettled Enter falls through to `resolveScan`), and render the loading branch at `:285` when it is
false so stale rows are never clickable. Ship the probe above as a permanent test with a NON-empty
`/products` fixture — the existing scanner test's empty fixture must not be reused.

### B2 — MAJOR (blocking, same fix): `keepPreviousData` shows the previous COMPANY's products after a company switch

`apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx:89`.

The handback (§6.2, and §1 "Behaviour delta") asserts: *"a tenant switch produces a different key
with no placeholder ancestor"*. That is not how TanStack v5 resolves `placeholderData`. In
`@tanstack/query-core@5.90.11`, `build/modern/queryObserver.js:265-281` calls
`options.placeholderData(this.#lastQueryWithDefinedData?.state.data, …)` — the **observer's** last
query that had data, with no key-lineage check; `utils.js:198 keepPreviousData(previousData)` just
returns it. Any key change on the same mounted observer — including the tenant/company suffix
appended by `tenantScopedKey` — yields the old scope's rows as placeholder.

Measured (probe 3, lane component, company-2 read left in flight):

```
PROBE3 dropdown after switch: STILL SHOWS COMPANY-1 ROWS
   × LANE: after switching company the dropdown still lists company-1 products while company-2 loads
```

This is *not* a cross-tenant leak: a tenant change goes through `features/auth/useLogout.ts` /
`AuthProvider.tsx` → `lib/clearAppState.ts:47 queryClient.clear()` + unmount. It **is** a
cross-company one: products are company-scoped server-side
(`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:127`
`->where('company_id', $companyId)`), and `CompanySelector.tsx:41-48` only calls
`invalidateQueries()` — no clear, no remount — so the component stays mounted and the operator can
see and (per B1) commit a company-A product into a company-B document.

**Fix directive.** Either drop `placeholderData` (the six sibling pickers in
`components/molecules/pickers/` use `useDebouncedValue` and NO `keepPreviousData` — grep confirms
zero hits — and simply show the loading branch), or keep it and gate both display and commit on
`isPlaceholderData === false` plus a tenant/company identity check. Correct handback §6.2, which
records a false TanStack semantic that will be copied by the next lane.

---

## 2. Non-blocking findings

- **MINOR — handback §6.3 under-states the fork risk.** It says the disagreement is benign because
  "the suggestion branch requires `isOpen && products.length > 0`; with an empty/absent match list
  it falls through to `resolveScan`". The list is precisely *not* empty after focus/auto-refocus —
  that is B1. `docs/handoff/HANDBACK-request-hygiene-T5-2026-09-04.md` §6.3 must be rewritten.
- **MINOR — no staleness affordance.** With `keepPreviousData`, `isLoading` (`:76`, `:285`) is false
  during every refetch after the first, so the dropdown gives the operator no cue that the rows do
  not match what they typed. If `placeholderData` is kept, surface `isFetching`/`isPlaceholderData`.
- **MINOR — 2 new `act(...)` warnings** in the touched suite (base 22 → lane 24, counted below);
  trailing debounce timers fire after the assertions in real-timer tests. Noise, not a failure.
- **MINOR — the lane's base is one merge behind `dev`** (`dev` = `5edb7e810`, base = `7f86dbf0c`).
  Two repo-wide audits are red in the worktree from inherited debt, NOT from this lane (§3):
  `audit:keys` flags `src/features/uom/hooks/useUnits.ts:53`, already fixed on `dev` by
  `lane/rh-web-lint-debt`; `audit:design-system` reports 15 new violations, all in
  `features/import/pages/ImportWizardPage.tsx` and friends. Re-run the full `pnpm lint` after the
  rebase/merge, not before.

## 3. What held up (verified, not taken on trust)

- **Plan conformance.** `useDebouncedValue(trimmedQuery, PRODUCT_SEARCH_DEBOUNCE_MS /* 250 */)` from
  `@/lib/hooks` at `LineItemEntryBar.tsx:75` (hook at `apps/web/src/lib/hooks.ts:8`, the same one
  the six pickers use); key `tenantScopedKey(['line-entry-products', debouncedQuery])` at `:77`
  (tenant/company remain suffixes); `params.search` uses the debounced value at `:82`; `enabled:
  !disabled && isOpen && tenantId !== null && companyId !== null` untouched at `:87`.
- **Immediate empty-query focus read still fires** — the debounced value initialises to `''`, so no
  timer must elapse; asserted in the new test before any `advanceTimersByTime`.
- **Deviation D1 (type guard instead of the plan's `as` cast) is sound.** `hasSearchParam` narrows
  `unknown` with `typeof`/`null`/`in` checks and no assertion; semantics identical to the plan's
  cast, and it avoids the `@typescript-eslint/no-unsafe-type-assertion` warning the literal snippet
  produced. D2 (named constant) and D3 (merged `afterEach`) are cosmetic and correct — `afterEach`
  at `LineItemEntryBar.test.tsx:106-109` calls `vi.useRealTimers()` before `resetTenant()`, so no
  sibling test can inherit fake timers or tenant state.
- **The new test is genuinely falsifying** — re-verified independently of the handback by running
  the SHIPPED test text against a copy of the base component (probe files deleted afterwards):
  ```
  × LineItemEntryBar > waits 250 ms and sends exactly the final product search 85ms
    → expected [ [ '/products', …(1) ], …(2) ] to have a length of +0 but got 3
  ```
- **Consumers.** `grep -rn "LineItemEntryBar" apps/web/src` → four production consumers
  (`DocumentLineEditor.tsx:1168`, `CreateStockTransferPage.tsx:935`,
  `ReplenishmentCapturePage.tsx:97`, `CreateCountingPage.tsx:407`) plus the barrel
  `components/molecules/line-items/index.ts:19,22`. None reads the query, the key or the cadence.
  `grep -rn "line-entry-products" apps/web/src apps/pos/src` → 4 hits, all producer or test
  fixtures; **zero** `invalidateQueries` / `removeQueries` / `setQueryData` on that root. Key shape
  unchanged, so no prefix invalidation broke. Confirmed.
- **Baseline honesty.** `git diff --stat 7f86dbf0c..368119f40 -- apps/web/tools/*baseline*` is empty
  — no baseline was rewritten to absorb this diff. No detector-evading indirection in the diff (no
  alias table, no suppression comment, no renamed literal).
- **Cross-cutting.** No catalogue entity, no new unique key, no new noun/surface, no new user-facing
  string, no color/token change, no money/quantity surface. Second-of-everything and
  one-surface-per-concept do not bite. Owner UI rulings: no new competing accent, no dead control,
  no brand string, no blended refunds.

## 4. Commands and outputs

All from `<worktree>/apps/web` unless noted. Probe files were created inside
`src/components/molecules/line-items/`, run, then deleted; `git status --short` printed nothing
afterwards (verified twice).

```
$ pnpm vitest run src/components/molecules/line-items
 ✓ src/components/molecules/line-items/LineItemEntryBar.test.tsx (12 tests) 1048ms
 Test Files  5 passed (5)
      Tests  24 passed (24)
   Duration  2.59s

$ pnpm typecheck
> tsc --noEmit
(no output)          EXIT: 0

$ npx eslint src/components/molecules/line-items/ProbeBaseEntryBar.tsx \
             src/components/molecules/line-items/ProbeBaseEntryBar.test.tsx   # base copies of both files
  89:75  warning  Unsafe type assertion: type 'Node' is more narrow than the original type  @typescript-eslint/no-unsafe-type-assertion
✖ 1 problem (0 errors, 1 warning)

$ npx eslint src/components/molecules/line-items/LineItemEntryBar.tsx \
             src/components/molecules/line-items/LineItemEntryBar.test.tsx    # lane
  96:75  warning  Unsafe type assertion: type 'Node' is more narrow than the original type  @typescript-eslint/no-unsafe-type-assertion
✖ 1 problem (0 errors, 1 warning)
```

Per-file lint delta: **0 errors before, 0 errors after; identical single pre-existing warning**
(line 89 → 96 from the 7 added lines); the test file is clean (0 problems) before and after.
Confirms the handback's D1 claim.

```
$ pnpm audit:keys
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter …
 ELIFECYCLE  Command failed with exit code 1.
```
NOT this lane: `git diff --name-only 7f86dbf0c..368119f40` lists only the two `line-items` files and
the handback; `git show dev:apps/web/src/features/uom/hooks/useUnits.ts` already uses
`predicate: uomUnmappedUnitTextsInvalidationPredicate(...)`, i.e. `dev` has fixed it. Stale-base
artifact. No `audit:keys` line names a touched file.

```
$ pnpm audit:design-system
[sweep-progress] Design-system audit C1-C6 violations: 811
[gate-summary] Design-system baseline: 796 acknowledged, 15 new, 11 stale baseline entries
$ pnpm audit:design-system | grep -i "line-items\|LineItemEntryBar"
(empty)

$ pnpm audit:quantity
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```

act-warning delta (base test+component copies vs lane): `22` → `24`.

TanStack semantics cited in B2, read from the installed package:
`node_modules/.pnpm/@tanstack+query-core@5.90.11/.../build/modern/queryObserver.js:265-281` and
`.../utils.js:198`.

### merge-tree (read-only, from the main checkout `/Users/houssamr/Projects/syneriva/apps/erp`)

```
$ git rev-parse --short dev                      -> 5edb7e810
$ git rev-parse --short lane/rh-t5-product-search -> 368119f40
$ git merge-tree --write-tree dev lane/rh-t5-product-search
6ec9bb907ecab1af35eb42329e3e70d00810eaf9
EXIT: 0
```
**No conflicts.** `dev` has moved since the lane's base (`apps/web/eslint.config.js`,
`apps/web/package.json`, `features/uom/*`, two locale files, two docs) — none overlap this lane.

### Browser checks — NOT RUN, promotion-owed

No stack was up for this gate. The plan's Step 4 browser checks of Sales / document line editor,
stock transfer, replenishment capture and counting remain **promotion preconditions** and were not
performed here. They must be re-scoped after the B1/B2 fix to include, on every one of the four
surfaces: (a) a real wedge scan into the focused bar → the *scanned* product is added, and (b) type
3 characters and press Enter within 250 ms → nothing wrong is added.
