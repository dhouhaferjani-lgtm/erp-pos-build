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

---

# Re-gate r2 (2026-09-04)

- Reviewed: web commit `18fbcbe01` (`fix(rh-t2 web): guard missing meta, hide Reverse on reversal receipts`) on `lane/rh-t2-stock-movements`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t2`, branch head `47e988267`.
- Handback section replayed: `docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md` `## Fix round 1 (2026-09-04)` items 3 and 4.
- Read-only review. No file in the worktree was modified (`git status --porcelain` empty at start and at end).

## VERDICT: **CHANGES**

r1-B1 is fully fixed and honestly evidenced. r1-B2 is **half** fixed: the lane closed the `reverses_movement_id` door and left a second, equally reachable one open — the backend's `movement_type === Issue` guard has no FE mirror, and this lane's reason-only Write-Offs filter is exactly what newly surfaces the rows it refuses. That plus a lint-ratchet regression and the still-unpinned filter mapping are the three blockers.

---

## Blocking findings

### F1 (BLOCKER) — the OQ-11 defect r1-B2 named is only half closed: adjustment-sourced write-offs still offer a Reverse the backend always refuses

`apps/web/src/features/inventory/StockMovementsPage.tsx:93-105` now mirrors three of the backend's four pre-flight guards, and misses the third one:

| `ReverseWriteOffService.php` guard | FE mirror |
|---|---|
| 1a reason ∈ {expiry, damage, write_off} — `:73-80` | `StockMovementsPage.tsx:94` ✓ |
| 1a-bis `reverses_movement_id !== null` — `:89-93` | `StockMovementsPage.tsx:101` ✓ (added this round) |
| **1a-ter `movement_type !== MovementType::Issue`** — `apps/api/app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:98-104` | **MISSING** |
| 1a-quater `reference_type === pos_receipt_return_scrap` — `:144-149` | `StockMovementsPage.tsx:91,103-104` ✓ |

The uncovered row class is real, shipped and operator-reachable, not hypothetical:

- A stock-adjustment line may carry `reason_code` `damage` or `write_off` — offered in the UI at `apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:128` and `apps/web/src/features/stock-adjustments/components/QuickStockAdjustmentModal.tsx:138`, and refused **only** on batch-tracked products (`apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:526-530`, re-checked at post `:692-695`). On any non-batch-tracked product it posts.
- Posting routes through `adjustByDelta(... reasonCode: $line->reason_code, referenceType: StockMovementReferenceType::StockAdjustment ...)` (`StockAdjustmentDocumentService.php:252-267`), which records `type: MovementType::Adjustment` with that reason (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:950,956`).
- Result row: `movement_type = adjustment`, `reason = damage|write_off`, `reverses_movement_id = null`, `reference_type = stock_adjustment`, `is_reversed = false`.
- The lane's Write-Offs tab now sends **reason only** (`StockMovementsPage.tsx:162-163`) and the backend alias is `whereIn reason [write_off, expiry, damage]` with no type predicate (`StockMovementController.php:106-110`), so the row lands in the tab. Pre-diff it could not: the tab sent `movement_type=issue`.
- `isReversibleMovement` returns **true** for it (reason ✓, `reverses_movement_id === null` ✓, `stock_adjustment` ∉ `NON_REVERSIBLE_REFERENCE_TYPES` ✓) → the Reverse button renders → the request dies on `ReverseWriteOffService.php:98-104` *"Only write-off issue movements can be reversed; movement X is of type adjustment."* → `movements.actions.reverseFailed` toast, every time.

This is the same owner rule (OQ-11: a control whose backend refuses it is hidden, not shown-and-failing) and the same widening mechanism r1-B2 described. The fix commit's own message quotes the sibling guard from the very same service and stops one guard short.

**Falsifying scenario:** on a non-batch-tracked product, post a stock adjustment with reason `damage`, then open Inventory → Movements → Write-Offs. The adjustment row appears with a live Reverse button; clicking it errors.

**Fix directive:** add `if (movement.movement_type !== 'issue') return false` to `isReversibleMovement` (`StockMovementsPage.tsx:93-105`) so the FE mirrors all four backend guards, and add a red-first vitest case with a `movement_type: 'adjustment'`, `reason: 'damage'`, `reverses_movement_id: null` fixture asserting no Reverse button.

### F2 (MAJOR, blocking) — the new ESLint warning is lint-ratchet drift; the lane picked the one option that fails a CI gate

`apps/web/src/features/inventory/StockMovementsPage.tsx:372` — `@typescript-eslint/no-unnecessary-condition`, 0 errors / **1 warning**, measured below. r1 measured this file at **0 problems** on `ae0921a2c`, so the delta is exactly **+1 web warning**.

`scripts/lint-ratchet.mjs:113-116` fails the run when a package's warning count *grows* (`FAIL — warnings rose N → N+1`); `scripts/lint-warning-baseline.json` pins `@autoerp/web` at 6448. The ratchet is a discrete CI step in `frontend-lint`. Re-baselining upward to absorb the lane's own new warning is the absorption pattern this gate rejects, so option (a) is not available. See the ruling below for the disposition.

### F3 (MAJOR, blocking) — r1-N3 unfixed: the diff's core behaviour still has zero executed evidence, and it is precisely the behaviour that produced both B2 and F1

`apps/web/src/features/inventory/StockMovementsPage.test.tsx:181,191` still pin only `page`/`per_page`. Nothing in the suite executes the transfer or write-off branches at `StockMovementsPage.tsx:160-166`. r1 dispositioned this as MINOR on the reasoning that the mapping was *correct*, just unpinned; r2 revises that: the reason-only write-off branch is the exact line that widened the tab and produced two blockers, and it is still asserted by nobody. With Step 11's browser probe unrun, an unasserted `params.append('reason', 'write_off')` is the single riskiest untested token in the diff.

**Fix directive:** two assertions in `StockMovementsPage.test.tsx` — Transfers tab → last `api.get` URL contains `movement_type=transfer`; Write-Offs tab → contains `reason=write_off` and does **not** contain `movement_type=`.

---

## Item-by-item verification of the fix round

### 1. r1-B1 — FIXED, evidence replayed

- `apps/web/src/features/inventory/StockMovementsPage.tsx:372` → `subtitle={t('movements.subtitle', { count: data?.meta?.total ?? 0 })}`. Correct guard, consistent with the pager's `data?.meta ?` at `:429`.
- `apps/web/src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx:200-216` — the fixture now carries the full six-field meta (`current_page`, `last_page`, `per_page`, `total`, `from`, `to`), matching the contract rather than tolerating a shape the server cannot emit. Right direction, same as D2.
- Whole directory green, re-run by the reviewer (not accepted from the handback):
```
$ pnpm vitest run src/features/inventory src/features/stock-adjustments/__tests__/queries.test.tsx
 Test Files  47 passed (47)
      Tests  328 passed (328)
   Duration  9.97s
$ pnpm vitest run src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx \
    src/features/inventory/StockMovementsPage.test.tsx
 ✓ src/features/inventory/StockMovementsPage.test.tsx (5 tests) 162ms
 ✓ src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx (17 tests) 473ms
 Test Files  2 passed (2)
      Tests  22 passed (22)
```
The handback's 47/328 claim reproduces exactly. The previously-red file is now 15 → 17 tests, all green.

### 2. r1-B2 — the guard is right and the two tests are meaningful, but the fix is incomplete (F1)

`StockMovementsPage.tsx:101` `if (movement.reverses_movement_id !== null) return false` mirrors `ReverseWriteOffService.php:89-93` exactly.

**Are the two new tests meaningful? Yes — they assert DOM, not implementation.**
- `StockMovementsPage.reverseWriteOff.test.tsx:418-424` renders the page with a lone reversal receipt and asserts `screen.queryByRole('button', { name: 'movements.actions.reverse' })` is absent. Role+accessible-name query against the rendered tree; the button's accessible name comes from `aria-label={t('movements.actions.reverse')}` (`StockMovementsPage.tsx:355`). No spying on `isReversibleMovement`, no class-name assertion.
- `:426-433` is the stronger of the two and is what makes the pair non-vacuous: with `[writeOffMovement, reversalReceiptMovement]` it asserts **exactly one** button, so the test cannot pass by the page rendering nothing (the classic failure mode of a lone `not.toBeInTheDocument()`).
- Falsifying by construction: `reversalReceiptMovement` (`:183-190`) satisfies every *other* branch of the gate — `reason: 'write_off'` passes `:94`, `reference_type` is absent from the fixture so `:103-104` returns true, `is_reversed: false` passes the render guard at `StockMovementsPage.tsx:343`, and `mockHasPermission` grants `batches.write-off` at `:366-369`. Delete line `:101` and both tests go red. The handback's red-first output (`expected [...] to have a length of 1 but got 2`) is consistent with that structure.

The residual is F1: the same fixture family with `movement_type: 'adjustment'` is untested and unguarded.

Note also that the handback does state the tab is now type-agnostic by design (fix round item 4), which discharges the second half of r1-B2's fix directive. Leaving reversal receipts *visible* in the Write-Offs tab (button suppressed) is acceptable and arguably informative; the objection was only ever to the live control.

### 3. Ruling on the `no-unnecessary-condition` warning at `StockMovementsPage.tsx:372`

**Ruling: none of (a)–(c). Take (d) in its cheapest form — hoist the read once (`const meta = data?.meta`) and use `meta?.total ?? 0` and `meta ? … : null`. Zero warnings, no type widening, no suppression, the runtime guard kept.**

Empirically verified, not reasoned: a throwaway probe file (created, linted, deleted in one command; working tree clean afterwards) with the identical types showed the rule fires on the inline chain and is silent on the hoisted form —
```
$ pnpm exec eslint src/features/inventory/__eslintprobe_meta.ts     # probe, since deleted
  9:20  warning  Unnecessary optional chain on a non-nullish value  @typescript-eslint/no-unnecessary-condition
✖ 1 problem (0 errors, 1 warning)
# line 9  = `return data?.meta?.total ?? 0`        → FLAGGED
# line 13 = `const meta = data?.meta`
# line 14 = `return meta?.total ?? 0`              → NOT flagged
```
The rule discounts the `undefined` contributed by an earlier link *inside the same chain*, so `?.total` reads as redundant there; once `data?.meta` is bound to a variable its type is genuinely `OffsetPaginationMeta | undefined` and the optional chain is necessary. Concretely:
```tsx
const meta = data?.meta
…
subtitle={t('movements.subtitle', { count: meta?.total ?? 0 })}
…
{meta ? (
  <OffsetPagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total}
                    perPage={meta.per_page} from={meta.from} to={meta.to} … />
) : null}
```
This also collapses the seven `data.meta.*` reads at `:428-435` to one binding.

Why the other three are rejected:
- **(a) accept the warning — NO.** It is a growth on a shrink-only ratchet (`scripts/lint-ratchet.mjs:113-116`, baseline `@autoerp/web: 6448`). Accepting it means either a red `frontend-lint` for this lane or a baseline bumped to absorb the lane's own new warning — the exact `--write-baseline` absorption class this gate rejects on the design-system baseline.
- **(b) widen `meta?:` — NO.** The endpoint is unconditionally paginated; `meta` is always emitted. Typing it optional makes the declared contract tolerate a shape the server cannot produce and would silently license `?.` everywhere downstream. It is the precise inverse of the D2 direction r1 endorsed (fixture made to match the contract, not the code made to tolerate a lie).
- **(c) strict type + drop `?.` + fixtures all carry meta — NO,** although the prerequisite now genuinely holds: the only remaining meta-less `/stock-movements` fixture in the touched tree is the **dead** duplicate branch at `__tests__/tenantScope.test.tsx:178-180` (r1-N1); the live branch `:129-138`, `StockMovementsPage.test.tsx:113` and `StockMovementsPage.reverseWriteOff.test.tsx:205-212` all carry it. Rejected anyway because `api.get<StockMovementsResponse>` is an **unchecked cast**, not a validated parse: a rolling deploy against an older API, an error envelope or a proxy-rewritten body all produce a `meta`-less object at runtime, and the cost of the guard is one `?`. The pager at `:428` already takes exactly this defensive position; the page should not be defensive in one place and fatal in the other.

The lane's stated reasoning for (c-as-shipped) was sound — it correctly refused both the type widening and the suppression comment. It simply stopped at the first option that had no downside *in the file* and did not check the ratchet. The hoist gives it everything it wanted for free.

### 4. Cross-lane convergence with T3 — CONVERGED

T3 has no worktree; read from branch `lane/rh-t3-payments-list` (head `ba87012a2`, commit `c008547fe` *"render-phase page reset and PaymentStatus-aligned status union"*).

`apps/web/src/features/treasury/PaymentListPage.tsx:83-93` vs `apps/web/src/features/inventory/StockMovementsPage.tsx:134-144` — **the same shape, token for token**: same comment rationale, `const filterSignature = JSON.stringify([...])`, `const [appliedFilterSignature, setAppliedFilterSignature] = useState(filterSignature)`, then the guarded `if (appliedFilterSignature !== filterSignature) { setAppliedFilterSignature(...); setPage(1) }` before the `useQuery`. The only difference is the signature payload (T3 `[search]`, T2 `[searchQuery, movementFilter, scope]`). r1's D3 ruling is discharged: T3 adopted the canonical pattern; the two lanes no longer ship two answers.

**Shared-hook extraction (`useResetOnChange` / `usePagedFilters` in `src/hooks/`) is recorded as a POST-MERGE follow-up, not a blocker for either lane.** r1's merge condition 4 is hereby downgraded: convergence-on-the-pattern was the substance of the condition and it is met; a cross-lane hook extraction touching two in-flight worktrees is worse merge hygiene than one ticket after both land.

Two cross-lane notes for T3's own reviewer (not charged to T2):
- `PaymentListPage.tsx:121` — `const total = data?.meta.total ?? payments.length` is the **identical unguarded read** that was T2's r1-B1. Same hoist applies, and it will produce the same `no-unnecessary-condition` warning if guarded inline.
- T3 **exported** its row interface so the page's tests bind to it instead of re-declaring it (`PaymentListPage.tsx:36-40`, "a second hand-rolled copy is how the `status` union drifted"). T2 has not — see the type-shadowing note below, which is the structural cause of r1-B1.

### 5. Commands and outputs (all from `<worktree>/apps/web`, default vitest pool)

**typecheck**
```
$ pnpm typecheck
> tsc --noEmit
(no output — clean)   TYPECHECK_EXIT=0
```

**eslint, the touched files**
```
$ pnpm exec eslint src/features/inventory/StockMovementsPage.tsx \
    src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx \
    src/features/inventory/StockMovementsPage.test.tsx \
    src/features/stock-adjustments/__tests__/queries.test.tsx
src/features/inventory/StockMovementsPage.tsx
  372:62  warning  Unnecessary optional chain on a non-nullish value  @typescript-eslint/no-unnecessary-condition

✖ 1 problem (0 errors, 1 warning)
```
0 errors. The single warning is F2. (`tenantScope.test.tsx` deliberately excluded here — its 15 warnings are pre-existing and were itemised in r1.)

**audit:keys**
```
$ pnpm audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter …
 ELIFECYCLE  Command failed with exit code 1.
```
Lines naming a touched file: **none**. Unchanged from r1; `useUnits.ts` is not in this diff.

**Baseline / mechanism audit for the fix round**
```
$ git diff --stat b133caf21..47e988267 -- apps/web/tools scripts/lint-warning-baseline.json
(empty)
```
No detector baseline, no token file, no locale file touched by the fix round. `18fbcbe01` is 2 files / +48 −1. No alias table, no suppression comment, no renamed-equivalent literal. Mechanism audit clean.

**Worker hygiene**
```
$ ps aux | grep '[v]itest'
(empty)
```

---

## Disposition of the r1 non-blocking items (explicit, as requested)

| Item | r2 status | Classification |
|---|---|---|
| **N1** — dead duplicate `/stock-movements` mock branch, `__tests__/tenantScope.test.tsx:178-180` | still present (verified) | **NOT merge-blocking** — fold the 3-line delete into the F1/F2 commit. Two branches for one URL, one of them meta-less, is how the next fixture drifts from the contract. Acceptable as a follow-up if the lane prefers. |
| **N2** — `filterSignature` normalises `scope` raw (`StockMovementsPage.tsx:139`) while `locationScopedKey` sorts it (`src/lib/locationScopedKey.ts:16`) | still present (verified) | **NOT merge-blocking → follow-up**, bundled with the shared-hook extraction (item 4). No current mutator permutes the array. |
| **N3** — transfer/write-off param mapping unasserted | still present | **MERGE-BLOCKING — promoted to F3.** Rationale in F3: this is the line that produced both B2 and F1. |
| **N4** — `page > last_page` after a shrink | unchanged | follow-up, shared with every `OffsetPagination` consumer, not this lane's debt. |
| **N5** — `ar` has no `inventory.movements.*` namespace | unchanged | follow-up (pre-existing; the lane touches no locale file). |
| **N6** — Step 11 browser probe + four W4 Playwright specs unrun | unchanged, correctly disclosed in the handback | **Not merge-blocking; PROMOTION-blocking.** Unchanged from r1: this lane must not reach staging on the current evidence set. |
| **`docs/api/README.md:447`** — "`GET /api/v1/stock-movements` is unchanged." | still says it; now false (unconditional pagination + `meta` envelope + `movement_type`/`reason`/`search`/`page`/`per_page` contract) | **NOT merge-blocking by itself, but fold it into the same fix commit** — a one-line edit, and it is a published-contract statement that is actively false about this lane's own change (other clients, incl. mobile, read this doc). If not fixed here it must be a named follow-up ticket, not a silent carry. |

## New non-blocking finding (r2)

### N7 (MAJOR, not merge-blocking) — three hand-rolled `StockMovement` shapes; the page's interface is not exported. This is the structural cause of r1-B1.
`StockMovementsPage.tsx:36-61` declares the row type and does not export it; `StockMovementsPage.test.tsx:65-93` and `StockMovementsPage.reverseWriteOff.test.tsx:96-114` each re-declare a *narrower* copy (both omit `reference_type`, `source_document_id/type`; the reverseWriteOff copy also omits `quantity_decimals`, so `getQuantityDecimals(movement)` runs on `undefined` there). Nothing type-checks a fixture against what the page actually reads — which is exactly why a `meta`-less, `reference_type`-less fixture could sit green for a whole lane and then explode. One-surface-per-concept: export the page's `StockMovement`/`StockMovementsResponse` and have both test files import them, as **T3 already did** for `Payment` (`PaymentListPage.tsx:36-40`). Cheap, and it is the only change in this round that prevents r1-B1 from recurring.

---

## Merge conditions (r2)

1. **F1** — mirror the backend's `movement_type === Issue` guard in `isReversibleMovement`, with a red-first `movement_type: 'adjustment'` / `reason: 'damage'` test.
2. **F2** — hoist `const meta = data?.meta` and read through it in both places; confirm `pnpm exec eslint` on the file is 0 problems.
3. **F3** — two assertions pinning the Transfers and Write-Offs URL params.
4. Fold in N1 and the `docs/api/README.md:447` line; open a ticket for N2 + the shared `useResetOnChange` hook (post-merge, with T3) and for N7 if not taken now.
5. Unchanged from r1: N6 (browser probe + four W4 specs) remains a **promotion** precondition; merging into local `dev` does not discharge it.

Re-run for r3: `pnpm vitest run src/features/inventory src/features/stock-adjustments/__tests__/queries.test.tsx`, `pnpm typecheck`, `pnpm exec eslint <touched>`, `pnpm audit:keys`.

---

# Re-gate r3 (2026-09-04)

- Reviewed: fix round 2 `0c476ff7f` + docs `16e599285`; fix round 3 `8c2ea02f4` + docs `a54b4fc67`. Branch `lane/rh-t2-stock-movements`, worktree head `a54b4fc67`.
- Handback sections replayed: `docs/handoff/HANDBACK-request-hygiene-T2-2026-09-03.md` `## Fix round 2` and `## Fix round 3`.
- Backend re-gate r2 = MERGE (inventory-costing reviewer). This section is the web half only.
- Read-only. `git status --porcelain` empty at start, after every measurement, and at end.

## VERDICT: **MERGE**

All three r2 blockers (F1, F2, F3) are closed, and all four carried non-blockers (N1, N2, N7, the `docs/api/README.md` line) are folded in honestly. Every claim in the two handback sections reproduced under re-execution; nothing was absorbed into a baseline, no detector was evaded, no suppression comment was introduced, and the merge is conflict-free against a `dev` that already carries T3 and T4. Zero blocking findings.

---

## Item-by-item verification

### 1. F1 — issue-only reversibility guard: **VERIFIED**

`apps/web/src/features/inventory/StockMovementsPage.tsx:100-119` now mirrors all four backend pre-flight guards:

| `ReverseWriteOffService.php` guard | FE mirror |
|---|---|
| reason ∈ {expiry, damage, write_off} | `StockMovementsPage.tsx:101` |
| `reverses_movement_id !== null` | `StockMovementsPage.tsx:108` |
| `movement_type !== Issue` | `StockMovementsPage.tsx:115` **← added this round** |
| `reference_type === pos_receipt_return_scrap` | `StockMovementsPage.tsx:98,117-118` |

`:115` is exactly `if (movement.movement_type !== 'issue') return false`, with the backend guard quoted in the comment at `:110-114`. The r2-F1 row class (`movement_type: 'adjustment'`, `reason: 'damage'`, `reverses_movement_id: null`) is now gated.

**The red-first tests assert DOM by role/name, not implementation.** `StockMovementsPage.reverseWriteOff.test.tsx:439-444` renders the lone `adjustmentDamageMovement` and asserts `screen.queryByRole('button', { name: 'movements.actions.reverse' })` absent; `:446-453` renders `[writeOffMovement, adjustmentDamageMovement]` and asserts **exactly one** button — the pairing is what makes it non-vacuous (it cannot pass by the page rendering nothing). The fixture at `:185-192` satisfies every *other* branch of the gate (reason `damage` passes `:101`; `reverses_movement_id: null` passes `:108`; `reference_type: null` passes `:117`; `is_reversed: false` passes the render guard at `:366`; `mockHasPermission` grants `batches.write-off`), so deleting `:115` sends both red. The same structure holds for the r1-B2 pair at `:419-436` with `reversalReceiptMovement` (`:170-177`). The existing positive case is unharmed: `damageWriteOffMovement` (`:158-164`) is `movement_type: 'issue'`.

### 2. F2 — hoisted meta: **VERIFIED**

`apps/web/src/features/inventory/StockMovementsPage.tsx:224` — `const meta = data?.meta`, bound once beside `const movements = data?.data ?? []` at `:219`, with the rationale in the comment at `:220-223`.
- Subtitle: `StockMovementsPage.tsx:394` → `t('movements.subtitle', { count: meta?.total ?? 0 })`.
- Pager: `StockMovementsPage.tsx:451-459` → `{meta ? (<OffsetPagination currentPage={meta.current_page} … />) : null}`; the seven `data.meta.*` reads collapsed to one binding.
- Declared type still strict: `StockMovementsResponse.meta: OffsetPaginationMeta` at `StockMovementsPage.tsx:70-73` — **non-optional**, no widening, no `eslint-disable`.
- Page file measured at **0 errors / 0 warnings** (table in §7).

The gate's r2 ruling was applied verbatim in its cheapest form; the runtime guard is retained because `api.get<StockMovementsResponse>` at `:186` is an unchecked cast.

### 3. F3 — tab param-mapping assertions execute the real `queryFn`: **VERIFIED**

`apps/web/src/features/inventory/StockMovementsPage.test.tsx:191-203` (`urlForTab`) renders the page, clicks the real `FilterTabs` button by accessible name, waits for the re-registered query, then **executes** `tabQuery.queryFn()` (`:199`) and reads the URL `api.get` was actually called with (`:200`) through an `unknown` + `typeof` guard (no unsafe cast — confirmed by the 0-warning measurement on this file).

- `:205-209` Transfers → `toContain('movement_type=transfer')` **and** `not.toContain('reason=')`.
- `:211-215` Write-Offs → `toContain('reason=write_off')` **and** `not.toContain('movement_type=')`.

Falsifying by construction: swapping the two branches at `StockMovementsPage.tsx:177-183` produces `reason=transfer` / `movement_type=write_off`, which fails both the positive `toContain` **and** the negative `not.toContain` in each test — four assertion failures across two tests. The presence-plus-absence pairing is what makes a swap detectable; a positive-only assertion would not have been. The handback's recorded red output matches that shape.

### 4. N2 — one scope normalisation: **VERIFIED**

`apps/web/src/lib/locationScopedKey.ts:13-15` exports `normalizeViewScope`, and `locationScopedKey` calls **that same function** at `:28`. The diff is a pure extraction — `git diff 16e599285 8c2ea02f4 -- apps/web/src/lib/locationScopedKey.ts` replaces the inline `const locScope = scope === 'all' ? 'all' : [...scope].sort()` with `const locScope = normalizeViewScope(scope)`. **No duplicated sort anywhere in the app**: `grep -rn '\.sort()' apps/web/src/lib apps/web/src/features/locations` returns exactly one production hit, `locationScopedKey.ts:14`.

`StockMovementsPage.tsx:156` consumes it in the reset signature: `JSON.stringify([searchQuery, movementFilter, normalizeViewScope(scope)])`.

**No key-output change**, proven two ways rather than asserted:
- `apps/web/src/lib/locationScopedKey.test.ts:12-15` pins `{ locScope: ['loc-a','loc-b'] }` from an unsorted input, and `:17-20` pins `'all'` — both green in the `src/lib` run below.
- `apps/web/src/features/stock-adjustments/__tests__/queries.test.tsx:74` pins the whole key literal `['stock-movements', '', 'all', 1, 25, { locScope: 'all' }, tenant, company]` — green.

**The permutation test is meaningful.** `StockMovementsPage.test.tsx:222-251`: scope `['loc-b','loc-a']` → page to 2 → asserts `page=2` (`:233`); permute to `['loc-a','loc-b']` + `rerender` → asserts **still** `page=2` (`:241`); switch to a genuinely different set `['loc-c']` → asserts `page=1` (`:250`). The third leg is what keeps it honest — it fails if the reset were disabled rather than normalised. Reverting `:156` to the raw `scope` makes `:241` fail by construction (the signature would differ, the render-phase guard at `:158-161` would fire, `page` would be 1). `scopeRef` is a hoisted mutable mock (`:40-48`) because `scope` is a store value no handler on this page can observe — which is precisely the argument that made the render-derived reset canonical in r1's D3 ruling.

### 5. N7 — exported row types: **VERIFIED**

`StockMovementsPage.tsx:41` `export interface StockMovement` and `:70` `export interface StockMovementsResponse`, both with a docblock naming the reason (`:35-40`, `:69`). Consumed by:
- `StockMovementsPage.test.tsx:3` — `import { StockMovementsPage, type StockMovement, type StockMovementsResponse }`; its two local re-declarations are deleted (diff `16e599285..8c2ea02f4` removes the 15-line `interface StockMovement` and the 11-line `interface StockMovementsResponse`).
- `StockMovementsPage.reverseWriteOff.test.tsx:14` — `import { StockMovementsPage, type StockMovement }`; its 19-line local copy is deleted.
- `__tests__/tenantScope.test.tsx` has no local copy (grep confirms).

`makeMovement` in both files now supplies every field the page reads (`StockMovementsPage.test.tsx:69-94`, `reverseWriteOff.test.tsx:100-121`), so a fixture can no longer omit a field the page dereferences without a type error — the structural cause of r1-B1 is closed. This is also the T3 pattern (`PaymentListPage.tsx` exports `Payment`), so the two lanes converge here too.

**The `quantity_decimals: 3` fixture fix does not mask a rendering regression.** `StockMovementsPage.reverseWriteOff.test.tsx` contains **no formatted-quantity assertion at all** — grep for text assertions in that file returns only Reverse-button `queryByRole`/`getAllByRole` checks (`:288, :300, :396, :425, :442`). Previously `getQuantityDecimals(undefined)` returned the `DEFAULT_QUANTITY_DECIMALS = 4` fallback (`src/lib/quantityScale.ts:129-135`) and rendered `-5.0000`; now it renders `-5.000`. Nothing asserted either value, so no assertion was loosened and none was silently satisfied. Unit-precision display remains pinned where it always was — `StockMovementsPage.test.tsx:152-165` (`+5.000`, `0.000`, `5.000` from `quantity_decimals: 3`), unchanged by this round. Recorded as N9 below: the fix is a latent-correctness improvement, not new coverage.

### 6. N1 + `docs/api/README.md`: **VERIFIED**

**N1** — the shadowed branch is gone: `git diff 16e599285 8c2ea02f4 -- .../__tests__/tenantScope.test.tsx` removes exactly the three lines `if (url.startsWith('/stock-movements')) { return { data: { data: [] } } }`. The single surviving handler is the live one at `tenantScope.test.tsx:129-138`, returning the six-field meta. Tenant-isolation assertions untouched (file's warning count is byte-identical at 15, §7).

**README contract block accurate** — `docs/api/README.md:457-467`, spot-checked against `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` (note: `Presentation/Controllers/`, not the `Presentation/Http/Controllers/` path the handback table implies — cosmetic, the claims themselves check out):

| README claim | Verified at |
|---|---|
| six-field `meta` envelope `{current_page,last_page,per_page,total,from,to}` | `StockMovementController.php:141-148` |
| `movement_type=transfer` matches `transfer_in` + `transfer_out` | `StockMovementController.php:96-102` (`whereIn` on both `MovementType` cases); alias allowed at `ListStockMovementsRequest.php:37-45` |
| `reason=write_off` matches `write_off`, `expiry`, `damage` | `StockMovementController.php:105-111` |
| ordering `created_at DESC, id DESC` | `StockMovementController.php:118-123` |
| `per_page` default 25 / max 100, `page` min 1 | `StockMovementController.php:43` (`DEFAULT_PER_PAGE = 25`), `ListStockMovementsRequest.php:55-56` |
| `search` server-side over reference / product name / SKU | `StockMovementController.php:78-95` |

The false "`GET /api/v1/stock-movements` is unchanged." sentence is deleted from the DPA V7 removal note (`docs/api/README.md:445-448`).

### 7. Lint delta — **0 errors, 0 new warnings**

Measured, not accepted: baseline copies of each touched file were materialised via `git show <rev>:<path>` into same-directory temp paths inside `src/` (ESLint overrides in `eslint.config.js` are directory-glob based — `:248`, `:384`, `:212` — so a same-directory temp file resolves an identical rule set), linted with `-f json`, then deleted. `git status --porcelain` empty afterwards.

```
=== BASELINE per-file (errors/warnings) ===
0E  0W  src/features/inventory/__gb_r2_page.tsx          (16e599285 StockMovementsPage.tsx)
0E  0W  src/features/inventory/__gb_r2_pagetest.tsx      (16e599285 StockMovementsPage.test.tsx)
0E  0W  src/features/inventory/__gb_r2_rwo.tsx           (16e599285 …reverseWriteOff.test.tsx)
0E 15W  src/features/inventory/__tests__/__gb_base_tenantscope.tsx  (b133caf21 lane base)
0E 15W  src/features/inventory/__tests__/__gb_r2_tenantscope.tsx    (16e599285)
0E  1W  src/lib/__gb_base_lsk.ts                         (b133caf21 locationScopedKey.ts)
0E  1W  src/lib/__gb_r2_lsk.ts                           (16e599285 locationScopedKey.ts)

=== CURRENT per-file (errors/warnings) ===
0E  0W  src/features/inventory/StockMovementsPage.tsx
0E  0W  src/features/inventory/StockMovementsPage.test.tsx
0E  0W  src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx
0E 15W  src/features/inventory/__tests__/tenantScope.test.tsx
0E  1W  src/lib/locationScopedKey.ts
0E  0W  src/features/stock-adjustments/__tests__/queries.test.tsx
```

Both non-zero counts are **pre-existing at the lane base `b133caf21`**, not inherited from an intermediate: `tenantScope.test.tsx` 15 → 15 (`react-hooks/globals`, `require-await`, `restrict-template-expressions`, `no-unsafe-type-assertion`, `array-type`), `locationScopedKey.ts` 1 → 1 (`no-unsafe-type-assertion` on the `QueryKey` cast at `:29`). r2-F2's +1 ratchet drift is gone: the page file is back to 0. **Net web warning delta for the lane: 0.** No `scripts/lint-warning-baseline.json` change (§8).

**audit:keys** — no touched file named:
```
$ pnpm audit:keys
[sweep-progress] Gate C — …queryKeys without an approved tenant scope: 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 invalidateQueries({ queryKey: tenantScopedKey([...]) }) is a no-op filter …
 ELIFECYCLE  Command failed with exit code 1.
```
`useUnits.ts` is not in this diff and is verbatim at `b133caf21` (verified in r1). Unchanged across all three rounds.

**audit:design-system** — no touched file named:
```
$ pnpm audit:design-system
[gate-summary] Design-system baseline: 796 acknowledged, 15 new, 11 stale baseline entries
```
All 15 enumerated entries are `src/features/import/pages/ImportWizardPage.tsx` (14: C2 raw checkbox/radio, C3 raw buttons) and `src/features/uom/components/UnmappedUnitTextsPanel.tsx:138` (1: C2 raw select). Zero matches for `StockMovements`, `locationScopedKey`, `tenantScope`, `OffsetPagination`. Identical to r1 and r2.

### 8. Baseline honesty & mechanism audit — **CLEAN**

```
$ git diff --stat b133caf21..a54b4fc67 -- apps/web/tools scripts/lint-warning-baseline.json \
    apps/web/src/lib/designTokens.ts apps/web/src/locales
(empty)
```
No detector baseline, no ratchet baseline, no token file, no locale file touched by ANY of the three fix rounds. The whole-lane diff is 14 files: 3 backend, 7 web src/e2e, 4 docs. No alias table re-exporting `tokens.*`, no suppression comment containing a detector keyword, no renamed-but-equivalent literal. Every metric that improved this round improved by deleting the offending construct (`:115` guard added, inline chain hoisted, dead mock branch deleted, local types deleted), not by moving a denominator.

### 9. Tests and typecheck — re-run, exact output

```
$ pnpm vitest run src/features/inventory src/lib src/features/stock-adjustments/__tests__/queries.test.tsx
 Test Files  69 passed (69)
      Tests  484 passed (484)
   Duration  16.41s
```
Reconciles exactly with the handback's split claim (inventory 46/328 + lib 22/151 = 68/479, plus `queries.test.tsx` 1/5 = **69/484**). Default pool.

```
$ pnpm typecheck
> tsc --noEmit
(no output)   TYPECHECK_EXIT=0
```

Worker hygiene: `ps aux | grep -c '[n]ode (vitest'` → `0`.

### 10. Merge readiness

```
$ cd /Users/houssamr/Projects/syneriva/apps/erp
$ git merge-tree --write-tree dev lane/rh-t2-stock-movements
451cf1783d5758ebb718184a74d6d7aaf235ad80
MERGE_TREE_EXIT=0
```
Exit 0 with a bare tree OID and no `CONFLICT` section → **clean merge, zero conflicting files**. `dev` at `c872427cb` already contains the T3 merge (`451f8b62e`) and the T4 merge (`fbae84cb3`), so the two lanes this one shares files-of-concept with are already folded in without collision. The only shared file across T2/T3 is `apps/web/src/lib/locationScopedKey.ts`, which T3 does not touch.

---

## Non-blocking findings (r3)

### N8 (MINOR, new this round) — `StockMovementsPage.test.tsx` now mocks `useViewScope`, so the real hook is no longer exercised in this file
`StockMovementsPage.test.tsx:41-48`. The mock is *necessary* for the N2 permutation test (the scope is a store value no handler can drive), and it changes nothing observable — the real hook produced `scope: 'all'` / `effectiveLocationIds: []`, and the pre-existing URL pin at `:174` (`?page=1&per_page=25`, no `location_ids[]`) was identical before. Consequence, not regression: the `effectiveLocationIds.forEach((id) => params.append('location_ids[]', id))` branch at `StockMovementsPage.tsx:173` is now permanently unexercised by this file. Worth one assertion with a non-empty `effectiveLocationIds` in the shared-hook follow-up.

### N9 (MINOR, new this round) — the `quantity_decimals` fixture fix is a latent-correctness improvement, not new coverage
`StockMovementsPage.reverseWriteOff.test.tsx:109`. That file asserts no rendered quantity text, so the pre-r3 `getQuantityDecimals(undefined) → 4` fallback was invisible to it and the fix is invisible too. Unit-precision display for this page is pinned by exactly one test, `StockMovementsPage.test.tsx:152-165`. Adequate, but single-threaded.

### N10 (MINOR) — `normalizeViewScope` has no direct unit test
`src/lib/locationScopedKey.test.ts` (3 tests) exercises it only through `locationScopedKey`. That is sufficient to prove the extraction is behaviour-preserving (which is what mattered this round), but the newly-public helper now has a second consumer and deserves its own case in the follow-up.

### N11 (MINOR) — `StockMovement` is a second FE surface with no glossary row
`docs/glossary.md:42` carries **Stock level** but no **Stock movement** row, while two hand-rolled `StockMovement`/`StockMovementsResponse` pairs exist: `StockMovementsPage.tsx:41,70` (now exported, `GET /stock-movements`) and `components/ProductMovementsTab.tsx:30,50` (`GET /products/{id}/movements`). Correctly left alone by this lane — different endpoint, different shape, and there is no generated DTO to shadow (`packages/shared/types/generated.d.ts` carries only the `StockMovementReferenceType` enum at `:3192`, no movement row type). Per convention 11 this is a declare-it-or-unify-it follow-up, not this lane's debt: the second surface pre-dates the lane and the lane strictly *reduced* the count from four to two.

### Carried, unchanged

| Item | Status |
|---|---|
| **N4** — `page > last_page` after a shrink | follow-up, shared with every `OffsetPagination` consumer |
| **N5** — `ar` has no `inventory.movements.*` namespace | follow-up, pre-existing, lane touches no locale file |
| **N6 / inventory-costing B3** — Step 11 browser probe + four W4 Playwright specs unrun; `w4-support.ts`'s `expect(meta.last_page).toBe(1)` unexercised | **PROMOTION-blocking, not merge-blocking.** Correctly disclosed in the handback's "Still owed after round 3". Merging into local `dev` does not discharge it. |
| Shared `useResetOnChange` / `usePagedFilters` extraction with T3 | post-merge follow-up (r2 ruling), now due — T3 is already on `dev` at `451f8b62e` with the identical render-phase block |

## Owner-rule check (r3 delta only)

- **OQ-11 (dead controls hidden, not disabled)** — the r1/r2 defect class is fully closed: all four backend refusal conditions are mirrored in `isReversibleMovement`, and the control is *hidden* (`return null` at `StockMovementsPage.tsx:364`), never rendered-and-failing. No `disabled={true}` + toast anywhere in the diff.
- **One main element per screen** — unchanged; no colour, badge or accent added this round. The single global figure remains the `PageHeader` subtitle.
- Brand strings, refunds/sales separation, blind counting, module gates, orphaned routes, overstated guarantees: not touched by this diff.

## Merge conditions (r3)

None. The lane is merge-ready into local `dev`.

Standing precondition, unchanged and NOT discharged by merging: **N6** — Step 11 browser probe + the four W4 Playwright specs must run green before this lane is promoted to `origin/dev`/staging.
