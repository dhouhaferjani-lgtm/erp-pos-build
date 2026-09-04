# Gate — Request Hygiene Phase A, Task 2 (WEB half) — frontend-conventions-reviewer

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2`, branch `lane/rh-t2-stock-movements`
- Reviewed range: `b133caf21..ae0921a2c -- apps/web` (web commit `cf6c11ea6`)
- Handback: `docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md`
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` lines 385–892 (Steps 5–8 = web)
- Scope: web only. Backend (controller/FormRequest/PHPUnit) is the inventory-costing reviewer's half; PHP is cited here only where it establishes a web-side fact.

## VERDICT: **CHANGES**

Two blocking findings. Both are cheap in-lane fixes (one is a single `?`, one is a three-token guard). Everything else the lane claims held up under re-execution: the pagination wiring, the query-key shape, the canonical components, the precision contract, the design-token discipline and the token audits are all clean, and the new vitest test is genuinely falsifying.

---

## Blocking findings

### B1 (BLOCKER) — An existing test file for the touched page is RED on this branch and was never run

`apps/web/src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx` — **15 of 15 tests fail** on `ae0921a2c`. Root cause is one character in the diff:

`apps/web/src/features/inventory/StockMovementsPage.tsx:365`
```tsx
subtitle={t('movements.subtitle', { count: data?.meta.total ?? 0 })}
```
The optional chain guards `data`, not `meta`. That file's harness returns a page payload with no `meta` (`StockMovementsPage.reverseWriteOff.test.tsx:187-192`), so every render throws.

Falsifying evidence (re-run by the reviewer, exact output in "Commands" below):
```
TypeError: Cannot read properties of undefined (reading 'total')
 ❯ StockMovementsPage src/features/inventory/StockMovementsPage.tsx:365:58
 Test Files  1 failed (1)
      Tests  15 failed (15)
```
Pre-diff the same expression read `subtitle={t('movements.subtitle', { count: movements.length })}` (`git show b133caf21:apps/web/src/features/inventory/StockMovementsPage.tsx` line 351), so the file was green at the lane base — this is a regression introduced by `cf6c11ea6`, not inherited debt.

Two independent process failures compound it: plan Step 10 and the handback's frontend evidence (§3 "Step 5–6, 8") both enumerate only three vitest paths and omit the fourth test file that renders the very page being rewritten. `pnpm vitest run src/features/inventory` would have caught it.

**Fix directive:** guard the read (`data?.meta?.total ?? 0`, matching the already-defensive `data?.meta ?` at `StockMovementsPage.tsx:422`) **and** add the six-field `meta` to `StockMovementsPage.reverseWriteOff.test.tsx:187-192` so the fixture matches the new contract; then re-run the whole `src/features/inventory` directory, not a hand-picked list of three files.

### B2 (MAJOR, blocking) — The Write-Offs tab now surfaces write-off REVERSALS with a live "Reverse" button the backend always refuses

`apps/web/src/features/inventory/StockMovementsPage.tsx:155-156`
```tsx
} else if (movementFilter === 'write_off') {
  params.append('reason', 'write_off')
}
```
Pre-diff the same tab sent `movement_type=issue` and then narrowed client-side by reason, so only ISSUE rows could appear. The new request constrains **reason only**; the backend alias (`StockMovementController` `reason=write_off` → `whereIn reason [write_off, expiry, damage]`) applies no type predicate.

A write-off reversal is a RECEIPT that inherits the original's reason — `ReverseWriteOffService::reverse()` calls `StockAdjustmentService::receive(... reason: $original->reason ...)` with `referenceType` left null. So on this branch reversal receipts land in the Write-Offs tab, and the FE gate lets them through:

`apps/web/src/features/inventory/StockMovementsPage.tsx:93-98`
```tsx
function isReversibleMovement(movement: StockMovement): boolean {
  if (!isReversibleWriteOff(movement.reason)) return false
  return movement.reference_type === null
    || !(NON_REVERSIBLE_REFERENCE_TYPES as readonly string[]).includes(movement.reference_type)
}
```
`reason` ∈ {write_off, expiry, damage} ✓, `reference_type === null` ✓, `is_reversed` false (nothing reverses a reversal) ✓ → the Reverse button renders. Clicking it hits two backend guards that throw unconditionally: `ReverseWriteOffService.php:91` ("is itself a reversal and cannot be reversed") and `:100` ("Only write-off issue movements can be reversed"). The user gets `movements.actions.reverseFailed` every time.

This is the owner rule OQ-11 class (a control whose backend refuses it must not be shown), and it is a *widening* of a latent bug: the same button already renders on reversal rows in the "All" tab, but the Write-Offs tab is precisely where an operator hunts for reversible rows, so the diff moves the defect onto the surface that matters.

**Falsifying scenario:** write off a lot, reverse it, open Inventory → Movements → Write-Offs. Pre-diff: one row (the issue). On this branch: two rows, and the reversal receipt offers "Reverse" → error toast.

**Fix directive:** add `movement.reverses_movement_id === null` to `isReversibleMovement` (the field is already on the interface at `StockMovementsPage.tsx:57` and is emitted by the API at `StockMovementController.php:193`), and either send `movement_type=issue` alongside `reason=write_off` or state explicitly in the handback that the Write-Offs tab is now type-agnostic by design. A vitest case asserting no Reverse button on a `reverses_movement_id !== null` row belongs with the fix.

---

## Non-blocking findings

### N1 (MINOR) — dead duplicate mock branch
`apps/web/src/features/inventory/__tests__/tenantScope.test.tsx:178-180` still holds the old `/stock-movements` branch returning `{ data: { data: [] } }`. It is now unreachable — the new branch at `:129-138` shadows it. Delete it; leaving two branches for one URL is exactly how the next fixture drifts from the contract.

### N2 (MINOR) — `filterSignature` normalises `scope` differently from the query key
`StockMovementsPage.tsx:132` does `JSON.stringify([searchQuery, movementFilter, scope])` on the raw scope, while `locationScopedKey` (`src/lib/locationScopedKey.ts:16`) sorts it. A permuted-but-equal scope array therefore resets the page without changing the query key. Low likelihood today (the only mutator, `useViewScope`'s clamp effect, preserves order) but the two normalisations should be one: reuse the sorted form in the signature.

### N3 (MINOR) — the diff's *core* behaviour (server-side filter mapping) has no executed evidence
The new test (`StockMovementsPage.test.tsx:174-191`) pins only `page`/`per_page`. Swapping `params.append('reason', 'write_off')` back to `movement_type=issue`, or dropping the `transfer` alias, leaves all 18 tests green. With Step 11's browser probe **not run** (handback §3, correctly disclosed), the transfer/write-off/receipt→param mapping is currently verified by inspection only. I verified by reading `ListStockMovementsRequest.php:29-49` that every value the page can emit (`receipt`, `issue`, `adjustment`, `transfer`, `reason=write_off`) is accepted, so the mapping is *correct* — it is just unpinned. Add two assertions (click Transfers tab → URL contains `movement_type=transfer`; click Write-Offs → `reason=write_off`) to the existing test.

### N4 (MINOR) — `page > last_page` after a shrink
`onPageChange={setPage}` is driven by `data.meta.current_page`; if the result set shrinks under a stale offset (e.g. after the reversal invalidation cascade) the pager renders "Page 3 of 1" over an empty table until the user clicks Previous. Shared with every other `OffsetPagination` consumer; noted, not charged to this lane.

### N5 (informational) — `ar` has no `inventory.movements.*` namespace at all
`src/locales/ar/inventory.json` has no `movements` key, so `movements.subtitle` / `movements.filters.*` fall back to EN in Arabic. Pre-existing (the lane adds no keys and touches no locale file); `en`/`fr` carry `subtitle` + `subtitle_plural` and all eight `common:pagination` keys in all three locales.

### N6 (owed, already disclosed) — Step 11 browser probe and the four W4 Playwright specs are unrun; `w4-support.ts`'s new `expect(meta.last_page).toBe(1)` bound is unexercised. Promotion must not happen on this evidence set.

---

## What held up

**Canonical components.** `OffsetPagination` (`src/components/ui/OffsetPagination.tsx`) is the design-system pager, imported at `StockMovementsPage.tsx:24` and rendered at `:422-436` with the full six-prop contract — no hand-rolled pager, no `hidePerPage` misuse. `DataTable` (`:396`), `SearchInput` (`:381`), `FilterTabs` (`:380`), `PageHeader` (`:363`), `EmptyState`, `ConfirmDialog`, `StatusBadge` all unchanged and canonical. No raw `<table>`, no raw form control added. The D4 wrapper `<div>` at `:395` is a structural necessity of the error ternary, not a styling change.

**Colours / rule 18.** Zero hardcoded Tailwind colour classes in the added lines; the only new class strings are layout (`'rounded-lg border bg-white'` is pre-existing at `:401`, paired with `borderColors.light`). No interpolated variant prefixes or opacity modifiers onto tokens anywhere in the diff. `pnpm audit:design-system`'s 15 "new" entries are all in `src/features/import/pages/ImportWizardPage.tsx` and `src/features/uom/components/UnmappedUnitTextsPanel.tsx` — none in this lane's files. The baseline file `tools/audit-design-system-baseline.json` is **not** in the diff (`git diff --stat b133caf21..ae0921a2c -- apps/web/tools/` is empty): no `--write-baseline` absorption, no alias-table or suppression-comment evasion. Mechanism audit clean.

**i18n / rule 11.** No new literal reaches the DOM. Every string added or moved goes through `t()`; `movements.subtitle`, `movements.filters.*`, `common:filters.all` and `common:pagination.*` all pre-exist in `en`/`fr` (`ar` gap is N5, pre-existing). The subtitle's semantics *improve*: `{{count}} movements recorded` now receives the global `meta.total` instead of the page length.

**TanStack.** Key is `locationScopedKey(['stock-movements', searchQuery, movementFilter, page, perPage], scope)` (`:145`) → `['stock-movements', search, filter, page, perPage, {locScope}, tenant, company]`: page/perPage are inside the key, tenant/company stay suffixes. `placeholderData: keepPreviousData` at `:166`. Every invalidation call site still matches the longer key:
- `src/features/inventory/_invalidation.ts:44-56` — prefix `[0]` + suffix tenant/company + `length >= 3` → matches at length 8. ✓
- `src/features/stock-transfers/api/queries.ts:38,51,65` — bare `queryKey: ['stock-movements']`, positional-prefix matching → still hits. ✓
- `src/features/inventory/components/ProductMovementsTab.tsx:116` uses its own `product-movements` namespace, untouched. ✓
`pnpm audit:keys` reports exactly one violation, `src/features/uom/hooks/useUnits.ts:53`, which is not in this diff and is present verbatim at `b133caf21` (verified with `git show`). No touched file is reported.

**Precision (rule 19).** Quantities still render through `formatQuantity(value, getQuantityDecimals(movement))` at `:283`, `:293`, `:300` — unit-precision, unchanged. Sign comparison uses `bccomp`. No `parseFloat`/`Number()` on any money or quantity in the diff. (`OffsetPagination`'s `Number(e.target.value)` is a page-size integer in an untouched shared component.)

**Falsifying power of the new test.** `StockMovementsPage.test.tsx:181` asserts the exact URL `'/stock-movements?page=1&per_page=25'`; deleting the two `params.append` lines at `StockMovementsPage.tsx:160-161` makes the queryFn build `'/stock-movements?'`, which fails string equality — the handback's claim is structurally sound on reading, and the page-2 half (`:191`) additionally pins that the pager actually re-keys the query. It is a real test.

**D2 (tenantScope fixture).** The added branch (`tenantScope.test.tsx:129-138`) returns the six-field meta the endpoint now always emits. It does not weaken tenant isolation: the file's isolation assertions are `expectScoped(...)` on key suffixes (`:283`) and the predicate cases at `:214-217`, none of which read the fixture body. The fixture was made to match the contract rather than the page made to tolerate a shape the server can no longer emit — the right direction. (Its shadowed twin is N1.)

**Baseline honesty & conservation.** No baseline file, no token file, no locale file, no shared component changed. Nothing in the diff can move a detector's denominator.

---

## D3 ruling — render-phase reset is canonical; T3 should converge on it

**Ruling: the render-phase derived-state reset (T2) is the canonical pattern for this repo; `useEffect(() => setPage(1), [deps])` (T3, `.worktrees/rh-t3/apps/web/src/features/treasury/PaymentListPage.tsx:60-64`) is the one that should change.**

Correctness under React 19 + StrictMode: the block at `StockMovementsPage.tsx:132-137` is React's documented "adjusting state during render" pattern — conditional, self-terminating (`setAppliedFilterSignature` makes the predicate false on the immediate re-invocation), and it updates only this component's own state. It sits before the `useQuery` call but after all preceding hooks and does not early-return, so hook order is invariant; `react-hooks/rules-of-hooks` (a hard error in `eslint.config.js:107`) is clean and ESLint reports **zero** problems on the file — notably `react-hooks/set-state-in-render` (`eslint.config.js:109`) does not fire on the guarded form. StrictMode's double render-invocation is idempotent here because the signature is a pure function of the three inputs.

No double request. Verified against the installed TanStack v5.90.11 source (`@tanstack/react-query/build/modern/useBaseQuery.js`): during render only `observer.getOptimisticResult(defaultedOptions)` runs, which builds a cache entry but never fetches; fetching starts in `observer.subscribe(...)` inside the `useSyncExternalStore` subscription and in `observer.setOptions(...)` inside a `useEffect` — both commit-phase. The stale-page render is discarded before commit, so the observer only ever subscribes to the `page = 1` key. The `useEffect` variant does not have that property: the stale-page render commits, the subscription effect fires in the same commit and issues a request for a page the new filter may not have, and only then does the queued `setPage(1)` produce a second request. On a lane whose entire purpose is request hygiene, shipping the variant that emits a guaranteed wasted round trip per filter change would be self-defeating.

Coverage of `scope`: yes, and this is the decisive argument. `scope` comes from `useViewScopeStore` via `useViewScope()`, so it can change without any handler on this page firing. The repo's prevailing "reset inside the change handler" idiom (45 `setPage(1)` sites) structurally cannot cover it; only a render-derived or effect-derived reset can.

Two conditions on the ruling: (1) normalise the signature the same way the query key does (N2); (2) extract it once — `useResetOnChange(signature, setPage)` or a `usePagedFilters` hook in `src/hooks/` — and have T2, T3 and future paginated pages import it, so "canonical" is a module rather than a pattern re-typed per lane. Two lanes in the same programme currently ship two different answers to the same question; that is the thing to fix before either merges.

## Ruling on removed FilterTabs counts (plan question 4)

**Acceptable — do NOT add a server aggregate for tenant #1.** Rationale: (a) a page-scoped count is not merely unhelpful, it is *wrong* — it would report "Receipts 4" while 900 receipts exist, and the number would change as the user pages, i.e. a badge that lies; (b) the global figure the user actually needs is still on screen exactly once, in the `PageHeader` subtitle fed by `meta.total` (`:365`), which satisfies the owner's "one main element per screen" ruling better than six competing count pills did; (c) restoring counts requires a new aggregate contract on `GET /stock-movements` (six `COUNT(*)` branches per request) — a real cost on the endpoint this lane exists to bound, for a signal nobody has asked for. `FilterTabs`'s `count` prop is optional (`src/components/molecules/FilterTabs/FilterTabs.tsx:4`), so the tabs render correctly without it. If counts are ever wanted back, the binding rule is: they come from a server aggregate in `meta`, never from `data.data`.

---

## Commands and outputs

All run from `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2/apps/web`, default vitest pool.

**1. The three files the lane nominates**
```
$ pnpm vitest run src/features/inventory/StockMovementsPage.test.tsx \
    src/features/inventory/__tests__/tenantScope.test.tsx \
    src/features/stock-adjustments/__tests__/queries.test.tsx
 ✓ src/features/inventory/__tests__/tenantScope.test.tsx (8 tests) 181ms
 Test Files  3 passed (3)
      Tests  18 passed (18)
   Duration  2.22s
```
(act() warnings from `ProductListPage`/`CompanyConfigProvider` in `tenantScope.test.tsx` are pre-existing noise, not failures.)

**2. The file the lane did NOT nominate — B1**
```
$ pnpm vitest run src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx
TypeError: Cannot read properties of undefined (reading 'total')
 ❯ StockMovementsPage src/features/inventory/StockMovementsPage.tsx:365:58
 Test Files  1 failed (1)
      Tests  15 failed (15)
   Duration  2.27s
```

**3. typecheck**
```
$ pnpm typecheck
> tsc --noEmit
(no output — clean)
```

**4. eslint, the four touched src files**
```
$ pnpm exec eslint src/features/inventory/StockMovementsPage.tsx \
    src/features/inventory/StockMovementsPage.test.tsx \
    src/features/stock-adjustments/__tests__/queries.test.tsx \
    src/features/inventory/__tests__/tenantScope.test.tsx
✖ 15 problems (0 errors, 15 warnings)
```
All 15 are in `tenantScope.test.tsx` at pre-existing lines (357/367/369/374/381/433/456/459×2/467 — `react-hooks/globals`, `require-await`, `restrict-template-expressions`, `no-unsafe-type-assertion`, `array-type`). Re-running without that file:
```
$ pnpm exec eslint src/features/inventory/StockMovementsPage.tsx \
    src/features/inventory/StockMovementsPage.test.tsx \
    src/features/stock-adjustments/__tests__/queries.test.tsx
EXIT=0   (no output)
```

**5. audit:keys**
```
$ pnpm audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter …
 ELIFECYCLE  Command failed with exit code 1.
```
Lines mentioning a touched file: **none**. `useUnits.ts` is not in this diff and the offending code is present verbatim at `b133caf21`.

**6. audit:design-system (mechanism audit)**
```
$ pnpm audit:design-system
[gate-summary] Design-system baseline: 796 acknowledged, 15 new, 11 stale baseline entries
```
All 15 new entries are `src/features/import/pages/ImportWizardPage.tsx` (14) and `src/features/uom/components/UnmappedUnitTextsPanel.tsx` (1). Grep for `StockMovements|OffsetPagination`: no match.

**7. Baseline diff check**
```
$ git diff --stat b133caf21..ae0921a2c -- apps/web/tools/
(empty)
```

No vitest worker processes left behind (`ps aux | grep 'node (vitest'` → empty).

---

## Merge conditions

1. Fix B1 (guard `data?.meta?.total` + add `meta` to the `reverseWriteOff` fixture) and re-run `pnpm vitest run src/features/inventory` — the whole directory, green.
2. Fix B2 (`reverses_movement_id === null` in `isReversibleMovement`, plus a test) or produce an explicit owner-level justification for showing a refused action.
3. Land N1 and N2 in the same round (both one-liners), and N3's two assertions.
4. Reconcile D3 with T3 before either lane merges — one shared hook, one pattern.
5. Step 11 browser probe + the four W4 specs remain promotion preconditions, per the handback's own §6.8.
