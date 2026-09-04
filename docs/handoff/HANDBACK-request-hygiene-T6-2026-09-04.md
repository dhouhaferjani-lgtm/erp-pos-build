# HANDBACK — Request Hygiene Phase A, Task 6

**Debounce the bulk pricing context (S-5)**

- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t6`
- Branch: `lane/rh-t6-pricing-debounce`
- Base: `5e1e54f69` (`Merge branch 'lane/rh-t5-product-search' into dev`)
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` → `## Task 6` (rev 12), plus the rev-8/gate-r7 sibling-suites NB
- Scope: **web only.** `apps/api` untouched.
- Commits: see §5.

**WAIT gate (Step 1) is cleared.** The plan blocks Task 6 until the wave-2 PO lane merges. Verified on the base commit: `fix/l1-receipt-hardening`, `fix/l2-fe-hygiene` and `test/L-po-flow` are all merged into local `dev`, and the lane is based on `5e1e54f69` — i.e. already rebased onto post-PO-lane `dev`. No rebase was needed during the lane (nothing landed on `dev` while it ran).

---

## 1. What landed

| File | Change |
|---|---|
| `apps/web/src/features/documents/components/DocumentLineEditor.tsx` | **~~`pricingContextSignature` … passes through `useDebouncedValue`; the body reads a render-written `pricingContextLinesRef.current`; added `placeholderData: keepPreviousData`.~~ REWRITTEN in fix round 1 — see §7; the struck text is history and two of its claims were false.** As shipped: `pricingContextLines` (the array) passes through `useDebouncedValue(…, 250)`, and the query **key**, the **body** and the **`enabled`** predicate are all derived from that one debounced value — so a cached answer is always filed under the signature of the state it was computed for. Key is `tenantScopedKey(['line-entry-pricing-context', partnerId ?? null, pricingContextSignature])` (**same root, same arity, tenant/company still the suffixes**). **No `placeholderData`.** No ref. `staleTime: 30000` untouched. |
| `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` | Added the fake-timer test `waits 250 ms and sends only the final unit price to bulk pricing`, plus **four more in fix rounds 1–2** (§7.1, §7.5): a company-switch leg, the empty-document add-then-focus request count, its non-empty-document companion, and a cache key ⟺ body coherence invariant. Imports widened: `act` from `@testing-library/react`, `afterEach` from `vitest`. A suite-level `afterEach(() => { vi.useRealTimers() })` sits next to the existing `beforeEach`, so a fake-timer test can never leak into the real-timer tests around it. The `useCompanyStore` mock reads a hoisted mutable `companyMock`, reset in `beforeEach`. File total: **34** tests (29 pre-existing + 5 new). |

Nothing else changed. In particular `useDebouncedValue`, `tenantScopedKey`, `LineItemsTable`, `MoneyInput`, **`DocumentForm.tsx`**, `CreateCreditNotePage.tsx`, `ProductController.php` and every Inventory Counting file are untouched (global constraints, plan line 15 block).

### Behaviour delta

- **Before:** every keystroke in the unit-price cell produced a new `pricingContextSignature` → a new query key → one `POST /line-entry/pricing-context/bulk` per character. Typing `125` = **3** requests (measured, §3 RED). Adding a product and clicking its price = **2** requests, the first cached under a key claiming the document had no lines (measured, §7.3).
- **After:** the key only moves 250 ms after the last keystroke → typing `125` = **1** request carrying `unit_price: '125'`; adding the **first** line and focusing its price = **1** request; every cached answer is filed under the signature of the body that produced it.
- **Honest cost (gate r2 MINOR-7, corrected by gate r3 MINOR-13):** adding a line to a document that **already** has priced lines costs **2** requests (`prod-A::5.000`, then `prod-A::5.000|prod-B::10.000`) — the debounced array is non-empty, so `enabled` cannot suppress the read already in flight for the old line set. Both are internally coherent. **The base costs 2 on this sequence too**; the +1 vs base that gate r2's PROBE E measured is *timing-dependent* and only occurs when the add lands before the focus fetch dispatches. The companion test pins HEAD's 2-request signature sequence, not a delta.
- **Unchanged — the first read on focus is still immediate.** `useDebouncedValue` seeds its state with the current value, so at the moment `enabled` flips true on `onFocus` the debounced signature already equals the live one and no timer has to elapse. The pre-existing test `lazily fetches bulk pricing context on unit-price focus and renders the hint` still passes untouched, which is the regression proof for that.
- **The hint blinks while the key settles, deliberately.** With no `placeholderData` the cost/margin hint disappears for the duration of the settling request instead of showing the previous verdict. That is the honest behaviour and the same trade the FE gate imposed on `LineItemEntryBar` (T5 fix round 1): a momentary blank beats a stale margin verdict the operator could act on — or commit through `Use suggested`.
- **Money stays a string end-to-end** (rule 19). The signature and the request body are both built from the same `PricingContextLineRequest[]` of `decimalValue(...)` strings, and the asserted payload is `unit_price: '125'` — no `parseFloat`, no `Number()`, nothing numeric added.

### Hook verification (dispatch precondition)

```
apps/web/src/lib/hooks.ts:8
export function useDebouncedValue<T>(value: T, delay = 250): T
```

Same helper Task 5 used for `LineItemEntryBar` and the six picker molecules (`ProductPicker`, `PartnerPicker`, `UserPicker`, `BankPicker`, `ServicePicker`, `VehiclePicker`), all at 250 ms. Reused, not reinvented.

---

## 2. Key-shape and consumer inventory

`grep -rn "line-entry-pricing-context" apps/web/src apps/pos/src` → **one hit**, the producer itself (`DocumentLineEditor.tsx:406`). There is **no** `invalidateQueries` / `removeQueries` / `setQueryData` on this root anywhere in the repo, so no prefix invalidation could break. The key shape is unchanged — `['line-entry-pricing-context', <partnerId|null>, <signature>, <tenant>, <company>]`; only the *timing* at which the signature segment settles changed.

Component consumers (both render the real editor, both exercised by the suites in §3):

| # | Consumer | Surface | Line |
|---|---|---|---|
| 1 | `apps/web/src/features/documents/DocumentForm.tsx` | quote / sales order / invoice / **purchase order** line editor | `:697` (via the `components/documents/DocumentLineEditor.tsx` back-compat re-export) |
| 2 | `apps/web/src/features/documents/CreateCreditNotePage.tsx` | credit-note line editor | `:628` (same re-export) |

`apps/web/src/components/documents/DocumentLineEditor.tsx` is a 2-line re-export, not a second implementation.

---

## 3. Evidence

All commands from `<worktree>/apps/web`.

### Baseline (pristine `5e1e54f69`, before any edit)

`pnpm vitest run src/features/documents/components/__tests__/`

```
 Test Files  10 passed (10)
      Tests  92 passed (92)
```

### Step 2 — RED (before the implementation)

`pnpm vitest run src/features/documents/components/__tests__/DocumentLineEditor.test.tsx -t 'waits 250 ms'`

```
 × DocumentLineEditor — designation cells > waits 250 ms and sends only the final unit price to bulk pricing 176ms
   → expected "spy" not to be called at all, but actually been called 3 times

 ❯ …/DocumentLineEditor.test.tsx:1093:25
    1093|     expect(apiPost).not.toHaveBeenCalled()

 Test Files  1 failed (1)
      Tests  1 failed | 29 skipped (30)
```

`3` = one bulk-pricing POST per keystroke (`1`, `12`, `125`) — the exact S-5 defect, failing on the falsifying assertion (the 249 ms "nothing yet" line), not on a fixture line.

### Falsification re-check (after the implementation)

`debouncedPricingSignature` was swapped back to `pricingContextSignature` in the query key (component parked in the session scratchpad, restored immediately after) and only this test re-run:

```
 × … waits 250 ms and sends only the final unit price to bulk pricing 176ms
Number of calls: 3
      Tests  1 failed | 29 skipped (30)
```

The single line that carries the debounce is load-bearing for the test. Restored and re-verified green in the same run block.

### Step 4 — GREEN, the plan's named path

`pnpm vitest run src/features/documents/components/__tests__/DocumentLineEditor.test.tsx`

```
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.test.tsx (30 tests) 1592ms
 Test Files  1 passed (1)
      Tests  30 passed (30)
```

30 = 29 pre-existing + 1 new.

### Sibling suites (rev 8 / gate r7 NB)

`pnpm vitest run src/features/documents/components/__tests__/` — includes `DocumentLineEditor.purchasePriceDefault.test.tsx`, `DocumentLineEditor.quantityStep.test.tsx` and the documents tenant-scope suite `DocumentComponents.tenantScope.test.tsx`:

```
 ✓ src/features/documents/components/__tests__/DocumentComponents.tenantScope.test.tsx (3 tests) 853ms
 ✓ src/features/documents/components/__tests__/DocumentLineEditor.test.tsx (30 tests) 1658ms
 Test Files  10 passed (10)
      Tests  93 passed (93)
```

93 vs the 92 baseline — the +1 is the new test; **no pre-existing test changed status**.

### Whole documents feature (extra, beyond the plan)

Both real consumers of the editor live outside `components/__tests__/`, so the whole feature tree was run to cover `DocumentForm` and the credit-note page:

`pnpm vitest run src/features/documents/`

```
 Test Files  53 passed (53)
      Tests  449 passed (449)
```

That sweep includes `DocumentForm.test.tsx`, `DocumentForm.tenantScope.test.tsx`, `DocumentForm.blankUnitPrice.test.tsx`, `DocumentForm.payload.test.ts`, `ReturnCreditNotePages.tenantScope.test.tsx`, `CreateNotePages.quantityDisplay.test.tsx` and the four document detail-page tenant-scope suites.

### typecheck

`pnpm typecheck`

```
> @autoerp/web@0.1.0 typecheck …/.worktrees/rh-t6/apps/web
> tsc --noEmit
```

Zero output = zero errors (whole-project `tsc --noEmit`, not path-scoped).

### eslint — per file, before vs after

Baseline measured on the **pristine files at `5e1e54f69`** in this worktree before the first edit (`git status --short` was empty at that moment), which is a stricter comparison than a renamed copy: identical paths, so directory- and glob-scoped rule overrides (`__tests__/**`) applied byte-identically. No baseline copies were created, so none had to be deleted.

| File | Before (`5e1e54f69`) | After |
|---|---|---|
| `DocumentLineEditor.tsx` | 0 errors, **2** warnings — `279:32 @typescript-eslint/restrict-template-expressions`, `1090:6 react-hooks/exhaustive-deps` (`lineColumns` missing `openPricingLineId` / `pricingContext?.items`) | 0 errors, **2** warnings — the same two, at `280:32` and `1120:6` after fix round 2 (line shift only; the round-0 numbers quoted here originally were mis-transcribed — gate r1 MINOR-4, and the round-1 number moved again in round 2 — gate r3 MINOR-12) |
| `DocumentLineEditor.test.tsx` | 0 errors, **13** warnings (10 × `no-base-to-string` in the `t` mock, 1 × `unbound-method`, 2 × `require-await`) | 0 errors, **13** warnings — the same thirteen, at `74…88`, `428:15`, `895:102`, `942:101` after fix round 1 |

**No new errors, no new warnings.** In particular the new test added zero warnings, and the exhaustive-deps warning on `lineColumns` is the pre-existing one the test file's own header comment documents (m-5, deferred by an earlier FE gate) — this lane neither fixed nor worsened it.

### `pnpm audit:keys`

```
[sweep-progress] Gate C — useQuery/useQueries/queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

Zero findings overall, and no touched file is named.

### React Doctor (pre-commit hook) — 2 inherited warnings, not introduced

The repo's pre-commit hook printed "React Doctor found staged regressions". Inspected with `npx react-doctor --staged --blocking warning`:

```
⚠ Chained array iterations            react-doctor/js-combine-iterations
  apps/web/src/features/documents/components/DocumentLineEditor.tsx:372
⚠ Pure function rebuilt every render  react-doctor/prefer-module-scope-pure-function
  apps/web/src/features/documents/components/DocumentLineEditor.tsx:452
```

Both sit on **pre-existing lines** — `:372` is the `pricingContextLines` `.filter().map()` chain and `:452` is `generateId`; both are present verbatim at `5e1e54f69` (`git show 5e1e54f69:…` lines 371 and the same `generateId`). React Doctor scans whole staged files, not the diff, so these are inherited debt surfaced by touching the file. Not fixed here: rule 4 (no scope creep), and `pricingContextLines` is deliberately a filter-then-map for readability of the pricing contract. Flagged for the backlog.

### ~~Browser checks — NOT RUN (promotion-owed)~~ — SUPERSEDED, see §7.6

**Struck 2026-09-04 (gate r2 MINOR-8).** The four items that stood here were written against round 0
and described the *rejected* behaviour: item 1 asked the tester to "confirm the cost/margin hint stays
on screen (no blink) while typing" and item 4 opened "with `placeholderData: keepPreviousData`, the
hint shown during the 250 ms settle is the previous signature's answer". Both are false as shipped —
`placeholderData` is gone and **the blink is the deliberate trade** (§7.2). A tester reading this
first would have reported the blink as a defect, or "fixed" it by re-adding the option.

**The live browser-check list is §7.6.** Browser checks were not run (no stack up) and remain
**promotion preconditions, not merge preconditions**.

---

## 4. Deviations from the plan text

**D1 — the test re-queries the price input before every interaction; the plan's snippet captures it once.** The plan writes:

```tsx
const price = screen.getByRole('spinbutton', { name: 'Unit Price' })
await act(async () => { fireEvent.focus(price); … })
…
await act(async () => { fireEvent.change(price, { target: { value: '1' } }); … })
```

Shipped verbatim, **that test cannot pass and cannot go red for the right reason**: it asserted `0` calls where the un-debounced code makes 3, i.e. it silently passed the falsifying line, then failed the final `toHaveBeenCalledTimes(1)`. Diagnosed empirically — after the focus render, `price.isConnected === false` and `screen.getByRole(...) !== price`.

Root cause, verified in code: `LineItemsTable.tsx:134-137` renders each cell as a **component** (`const Cell = column.Cell; <Cell line={line} index={index} />`). `DocumentLineEditor`'s `lineColumns` memo rebuilds those `Cell` functions whenever its deps move, and in *this suite* the mocked `t` is a deliberately fresh closure on every render (documented at the top of the test file, FE gate m-4/m-5), so the memo recomputes every render, React sees a new component type, and the whole cell subtree — the `MoneyInput` included — unmounts and remounts. A node captured before the previous render is detached, so `fireEvent.change` on it reaches nothing.

Shipped instead:

```tsx
const priceInput = () => screen.getByRole('spinbutton', { name: 'Unit Price' })
```

with `priceInput()` called at each interaction. Every assertion the plan specifies is unchanged and still falsifying: `toHaveBeenCalledTimes(1)` on focus, `not.toHaveBeenCalled()` at 249 ms, `toHaveBeenCalledTimes(1)` + the exact `{ partner_id: 'partner-1', lines: [{ product_id: 'prod-1', variant_id: null, unit_price: '125' }] }` payload at 250 ms. The reason is recorded as a comment above the test so the next author does not "simplify" it back.

**This is a harness artifact, not a product defect** — in the app `t` is referentially stable, so `lineColumns` is stable and no remount happens per keystroke. Called out here because it is the one place the shipped test text differs from the gate-cleared plan.

**D2 — `staleTime: 30000` is retained.** The plan's Step 3 snippet lists only `queryKey` / `queryFn` / `placeholderData`. It is an *excerpt* of the options object, not a replacement, so the pre-existing `staleTime` and `enabled` were kept. Dropping `staleTime` would have re-fetched on every remount and worked against S-5.

**D3 — extra verification beyond the plan.** Ran the whole `src/features/documents/` tree (53 files, 449 tests) on top of the plan's named path and the rev-8 sibling directory, plus the post-implementation falsification pass and the React Doctor inspection. No production code was changed to make any of it pass.

**No other deviations.** Steps 1→4 executed in order; `useDebouncedValue` exists as specified.

### Anchor drift found

| Plan reference | Plan says | Actual on `5e1e54f69` |
|---|---|---|
| Task 6 heading | ~line 1658 | **1664** |
| Task 6 Step 4 | ~line 1760 | **1745** (rev-8 sibling-suites NB at **1749**) |
| Query block being modified | (no line cited) | `DocumentLineEditor.tsx:385-400` before, `:386-419` after |
| `pricingContextLines` memo | (no line cited) | `:370` before, `:371` after |
| Test insertion point | (no line cited) | end of the single `describe('DocumentLineEditor — designation cells')`, after `shows server-driven blocked margin policy…`; file grew 1046 → 1101 lines |

The plan's Task 6 body cites no `path:line` anchors inside the source files, so nothing else could drift.

---

## 5. Commits (path-scoped, on `lane/rh-t6-pricing-debounce`)

| Hash | Subject |
|---|---|
| `584fd3264` | `fix(web request-hygiene t6): debounce bulk pricing context (S-5)` — 2 files changed, 79 insertions(+), 5 deletions(-) |
| `4ec9db85a` | `fix(web request-hygiene t6): gate r1 — drop placeholderData, debounce the pricing lines so key and body agree` — 2 files changed, 244 insertions(+), 22 deletions(-) |
| `272247e59` | `docs(request-hygiene t6): gate r1 report, plan banner, handback fix round 1` — also carries the `## Task 6` amendment in `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` (gate r1 merge condition 3) and the r1 gate report |
| `e514f884e` | `fix(web request-hygiene t6): gate r2 — scope the company-switch claim to what is proven, pin the non-empty add cost` — 2 files changed, 96 insertions(+), 13 deletions(-) |
| `44d7bf248` | `docs(request-hygiene t6): gate r2 report, plan Task 2/3/5 hygiene, handback fix round 2` |

`git status` is clean at handback time. Housekeeping honoured: no `git stash`, no `apps/api` file touched, path-scoped `git commit -- <paths>`, vitest run by path only (never the whole web suite), no leftover scratch files inside the repo (the falsification copy lived in the session scratchpad).

---

## 6. What the reviewer should look at

Gate: **frontend-conventions-reviewer**.

1. **Deviation D1** — is the re-query loop an acceptable substitute for the plan's captured node, given the `LineItemsTable.tsx:134-137` remount mechanism? It is the only place the shipped test differs from the gate-cleared plan text. Secondary question: is the per-render cell remount worth a follow-up ticket in its own right (it is invisible in production only because `t` happens to be stable)?
2. **~~`placeholderData: keepPreviousData` — the stale-verdict question.~~ CORRECTED 2026-09-04 (gate r1 B1): both arguments this section made were FALSE in code, and the option has been REMOVED.** For the record, because the next lane will read this:
   - **"a company switch re-mounts the document form" — it does not.** `apps/web/src/stores/companyStore.ts:186-198` only `set({ currentCompanyId })`; `apps/web/src/features/company/CompanyProvider.tsx:149` returns a bare `<>{children}</>`; `apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:39-47` only calls `invalidateQueries()`; there is **no `key={currentCompanyId}` anywhere in `apps/web/src`**. The editor re-renders in place on the same observer with a new company suffix, and TanStack hands back the previous query's data regardless of key lineage. The gate measured company-1's WAC cost still on screen after the switch.
   - **"nothing in this component commits the placeholder" — it does.** `DocumentLineEditor.tsx` `Use suggested` writes `pricingItem.suggested_price` into the line, and the popover is reachable while the data is a placeholder. The gate measured company-1's suggested price committed into a line while company-2 was active.
   - **`focusedPriceLineId` is never reset to null**, so once any price cell is touched the query stays enabled for the rest of the form's life: the exposure window was never "250 ms while typing", it was every scope change for the session.
   - Fixed by **removing `placeholderData` entirely** (gate option (a), the same option the gate chose for `LineItemEntryBar`), with a standing anti-regression comment at the query site. See §7.

3. **~~The render-written ref~~ REMOVED in fix round 1 (gate r1 M1/MINOR-1).** The gate falsified its justification: TanStack calls `observer.setOptions` with a freshly-closed `queryFn` on the same render that moved the key, so a plain closure is equally current; the ref's only distinct behaviour was on a **retry** (`apps/web/src/lib/queryClient.ts:7` sets `retry: 1` in the app, though the test wrapper overrides it to `false`), where it made the body *newer* than the key — coherence-negative, not coherence-positive. Debouncing the lines array made it unnecessary and it is gone.

4. **Key shape and invalidation** — §2: root `line-entry-pricing-context`, arity unchanged, tenant/company still the suffixes, exactly one hit in the repo and zero invalidation callers. `pnpm audit:keys` = 0.
5. **Rule 19** — no `parseFloat`/`Number()` added anywhere; signature, request body and the asserted `unit_price: '125'` are all strings.
6. **Browser checks are owed at promotion, not at merge** — the live list is **§7.6** (§3's block was struck in fix round 2, gate r2 MINOR-8): purchase order and credit note, now including a company switch mid-form and an add-product-then-click-price sequence.
7. **Two React Doctor warnings are inherited**, both on pre-existing lines (§3). Confirm you agree they are out of this lane's scope under rule 4.


---

## 7. Fix round 1 — FE gate r1 (2026-09-04)

Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-t6-gate-frontend-conventions.md` — **VERDICT: CHANGES**, 1 BLOCKER (B1), 1 MAJOR (M1), 6 MINOR. The gate reproduced every §3 evidence line and accepted the debounce itself and Deviation D1; what it rejected were the two extra mechanisms plan Step 3 bolted on. Scope stayed web-only, same two files plus the plan and these docs.

### 7.1 What changed

| File | Change |
|---|---|
| `apps/web/src/features/documents/components/DocumentLineEditor.tsx` | **B1:** `placeholderData: keepPreviousData` removed, and the `keepPreviousData` import with it; a standing anti-regression comment now sits at the query site explaining why (mirroring `LineItemEntryBar.tsx:89-96`). **M1:** the debounce moved from the *signature* to the *lines array* — `const debouncedPricingLines = useDebouncedValue(pricingContextLines, 250)`, with `pricingContextSignature` (the key segment), the `enabled` predicate (`debouncedPricingLines.length > 0`) and the request body (`lines: debouncedPricingLines`) all derived from that one value. **MINOR-1:** `pricingContextLinesRef` deleted — no ref is written during render any more. |
| `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` | Three new tests (§7.3). The `useCompanyStore` mock now reads a hoisted mutable `companyMock` (reset in `beforeEach`) so a test can switch company mid-form — **this is the rule 22 second-company leg the gate's MINOR-6 asked for**. Added `createWrapperWithClient()` (a `gcTime: Infinity` client the test can inspect), `signatureOfRequestBody()` (type-guarded, no `as`) and `flushFakeTimerQueries()`. |
| `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` | Gate merge condition 3: an AS-SHIPPED banner under the `## Task 6` heading, and Step 3's snippet replaced with the shipped shape (the rejected original kept in a `<details>` block). Both spell out that **Tasks 7 and 14 must not copy `placeholderData: keepPreviousData` onto a tenant/company-scoped read**. |

### 7.2 Why option (a) — drop `placeholderData` — and not (b) — gate it

The gate allowed either. Dropping it was chosen for the same reasons the gate itself gave for `LineItemEntryBar`: a `keepPreviousData` whose rows may neither be rendered nor committed is dead configuration, and leaving it in place with two `isPlaceholderData` guards keeps the cross-company leak one careless edit away. Every sibling picker in `components/molecules/pickers/` and now `LineItemEntryBar` carry none. Cost: the cost/margin hint blinks out during each settling request. That is the correct trade — a blank hint beats a stale margin verdict that `Use suggested` can commit.

### 7.3 Evidence — RED then GREEN

All three new tests were run against the **round-0 committed component** (`584fd3264`, restored from git into the worktree, then reverted) to prove they falsify:

**B1 — `never shows the previous company pricing while the new company read is in flight`.** Company-1's answer is rendered, the mock is switched to a never-settling promise, `companyMock.currentCompanyId` becomes `company-2`, the tree re-renders in place (no unmount — that is the point).

```
 × never shows the previous company pricing while the new company read is in flight 1147ms
   → expected <span></span> to be null
```

The span is company-1's `Cost 111.000000 · Last buy 99.000000 · Margin 30%` still on screen under company-2. The test also asserts the `Pricing details` button is gone, so the `Use suggested` commit path (the gate's PROBE 4) is unreachable.

**M1a — `sends exactly one bulk pricing request when the first line is added and its price is focused inside the window`.** The gate's PROBE 2 path: **empty** document, the first line is added, its price cell is focused inside the 250 ms window. (Renamed in fix round 2 — gate r2 MINOR-7 — because it is not a general add-then-focus guarantee; see §7.5.)

```
 × sends exactly one bulk pricing request when a line is added and its price is focused inside the window 126ms
   → expected "spy" to not be called at all, but actually been called 1 times
```

That premature call is the request fired against a debounced signature that still said "no lines".

**M1b — `files every cached pricing answer under the signature of the body it was fetched with`.** Same interaction, asserted against the query cache through `createWrapperWithClient()`: the multiset of signature segments of every *successful* `line-entry-pricing-context` query must equal the multiset of signatures rebuilt from the bodies actually POSTed.

```
 × files every cached pricing answer under the signature of the body it was fetched with 130ms
AssertionError: expected [ '', 'prod-1::10.000' ] to deeply equal [ 'prod-1::10.000', 'prod-1::10.000' ]
```

The empty signature segment is the gate's PROBE 2 finding made executable: `prod-1`'s verdict cached under a key claiming the document has no lines. This is the durable half of M1 — the same mechanism that, in the gate's PROBE 3, bound `prod-1::10.000` to the verdict computed at `20.000` for a whole `staleTime`.

**GREEN after the fix:**

```
$ pnpm vitest run src/features/documents/components/__tests__/
 ✓ …/DocumentLineEditor.test.tsx (33 tests) 1763ms
 Test Files  10 passed (10)
      Tests  96 passed (96)
```

33 in the touched file (29 pre-existing + the round-0 debounce test + 3 new); 96 in the sibling directory (round 0: 93; base: 92).

```
$ pnpm vitest run src/features/documents/
 Test Files  53 passed (53)
      Tests  452 passed (452)
```

```
$ pnpm typecheck
> tsc --noEmit
(no output)          EXIT: 0
```

```
$ pnpm audit:keys
[sweep-progress] Gate C — … without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

The pre-existing `lazily fetches bulk pricing context on unit-price focus and renders the hint` test still passes untouched, which is the regression proof that **the first read on a document mounted with lines is still immediate** after moving the debounce to the array (`useDebouncedValue` seeds via `useState(value)`, `lib/hooks.ts:9`).

### 7.4 eslint — per file, round 0 vs fix round 1

| File | Round 0 (`584fd3264`) | After fix round 1 |
|---|---|---|
| `DocumentLineEditor.tsx` | 0 errors, 2 warnings (`280:32 restrict-template-expressions`, `1109:6 react-hooks/exhaustive-deps`) | 0 errors, 2 warnings — **same two rules**, `280:32` and `1111:6` (→ `1120:6` after fix round 2) |
| `DocumentLineEditor.test.tsx` | 0 errors, 13 warnings (10 × `no-base-to-string`, 1 × `unbound-method`, 2 × `require-await`) | 0 errors, 13 warnings — **same thirteen rules**, shifted |

**No new errors, no new warnings**, still matching the `5e1e54f69` baseline exactly. Two intermediate additions were caught and removed before commit: the coherence test's first draft used an `as { lines: … }` cast (`no-unsafe-type-assertion`) and then `String(unknown)` (`no-base-to-string`); both were replaced with `typeof` narrowing in `signatureOfRequestBody`.

### 7.5 The gate's MINORs — disposition

| Finding | Disposition |
|---|---|
| MINOR-1 — ref inert / wrongly justified | **Fixed** — ref deleted; §6.3 corrected above. |
| MINOR-2 — Deviation D1 accepted | Noted, no change. |
| MINOR-3 — `LineItemsTable` component-typed cells remount every cell on any `lineColumns` dep change, dropping focus and selection | **Not fixed here (rule 4, out of lane scope). Owed as a follow-up ticket.** `LineItemsTable.tsx:133-138`; fix shape is to invoke `column.Cell({ line, index })` or memoise per column id. Real production impact: `priceSourceByLineId`, `invalidLineIds`, `purchaseBonusEnabled` and `handleUpdateLine` all move in normal use. |
| **KNOWN RESIDUAL (gate r2 M2), pre-existing** — the *rendered* pricing hint can lag the query, so a company switch can still leave company-1's hint on screen with `Use suggested` reachable | **Not fixed here; same ticket as MINOR-3.** `DocumentLineEditor.tsx:1120` (the `lineColumns` dep array, which omits `pricingContext`) + `LineItemsTable.tsx:133-138` (cells are component types). Measured identical at base `5e1e54f69`, so not a T6 regression. Order matters: **remount fix first, deps second** — deps alone would drop focus mid-typing. See §8.1. |
| MINOR-4 — handback eslint line numbers | **Fixed** — §3 corrected, §7.4 re-measured. |
| MINOR-5 — `pnpm --filter @autoerp/web lint` RED | **Inherited, not this lane.** `audit:design-system` 14 new + 11 stale, all in `src/features/import/pages/ImportWizardPage.tsx`, introduced by the imports merge `40aed177b`. This lane touches neither that file nor the baseline JSON and contributes zero design-system violations. **Whoever promotes must reconcile it** — the branch cannot claim a green full-lint gate. |
| MINOR-6 — rule 22 second-company leg | **Fixed** — the company-switch test in §7.3 is that leg. |

### 7.6 Browser checks — re-scoped, still promotion-owed

Per the gate's ruling 6, the promotion browser pass on **purchase order** and **credit note** must now cover three sequences with the network panel open, not just a typing burst:

1. **Typing burst** — type a multi-digit price fast: exactly one `POST /line-entry/pricing-context/bulk` carrying the final price. Expect the hint to blank out during the settle and come back with the new verdict (this is the deliberate §7.2 trade — confirm it is not visually jarring enough to warrant option (b)).
2. **Add product, then click its price inside 250 ms** — exactly one request, and the hint that appears is for the added product.
3. **Company switch mid-form** with a price cell already focused — the hint must go blank, never show the previous company's cost/margin, and `Use suggested` must not be reachable until the new company's answer lands.

### 7.7 Commits added this round

| Hash | Subject |
|---|---|
| `4ec9db85a` | `fix(web request-hygiene t6): gate r1 — drop placeholderData, debounce the pricing lines so key and body agree` |
| (this file) | `docs(request-hygiene t6): gate r1 report, plan banner, handback fix round 1` |


---

## 8. Fix round 2 — FE gate r2 (2026-09-04)

Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-t6-gate-frontend-conventions-r2.md` — **VERDICT: CHANGES**, 1 MAJOR (M2), 3 MINOR (7, 8, 9). **B1 and M1 were confirmed genuinely fixed**, re-falsified by the gate itself against `584fd3264` rather than taken on report; the partner-switch leg r1 flagged is fixed too. Everything in this round is comment/doc-level plus one added test — **no further change to the query itself**.

### 8.1 M2 — the company-switch guarantee was overstated; the claims are now scoped to what is proven

`DocumentLineEditor.tsx:1120-1135` (`lineColumns`' dep array) omits `pricingContext`, and `LineItemsTable.tsx:133-138` renders each cell as a **component type**, so a cell body runs inside the closure captured the last time the memo computed. A pricing answer moves no `lineColumns` dep, so the **rendered** hint can lag the query. The gate measured, on HEAD with a production-faithful stable `t`:

```
PROBE D hint visible right after the answer arrives (no dep moved): false
PROBE D hint visible after one dep move:                            true
PROBE D LEAK — company-1 hint still on screen under company-2: true | Use suggested reachable: true
```

**Identical at base `5e1e54f69` — strictly pre-existing, not a T6 regression** (hence MAJOR, not BLOCKER). Two consequences worth carrying forward:

1. The B1 leak survives in production through a **second, unrelated mechanism**. `CreateCreditNotePage.tsx:630` passes a stable `onChange`, so it is the exposed surface; `DocumentForm.tsx:698-704` passes an inline arrow whose identity churns, so its leak self-heals on the next parent render — timing-dependent, not absent.
2. **The pricing hint is largely dead in production today** — it does not appear when the answer arrives, only after some later unrelated dep move. A separate pre-existing product bug this gate surfaced.

Taken here (gate option (a), the in-lane one): the production comment at the query site and the company-switch test's name and header comment now state **only what is proven** — that the *query* does not carry data across a key change — and both explicitly name the residual and where it is tracked. Handback §7.5 carries the residual row below. Option (b), the real fix, is a **separate lane**: fix MINOR-3 first (invoke `column.Cell({ line, index })` or memoise per column id), **then** add `pricingContext?.items` and `openPricingLineId` to the deps — in that order, or every pricing answer remounts the cells and drops focus mid-typing. **MINOR-3 and M2 are one ticket, not two.**

### 8.2 MINOR-7 — the request-count claim was too broad; corrected and pinned

`add-then-focus = 1 request` holds only on an **empty** document, where `enabled` (`debouncedPricingLines.length > 0`) suppresses the early read. On a document that already has priced lines it is **2** requests:

```
HEAD  (272247e59): requests at focus: 1 | after settle: 2  ["prod-A::5.000", "prod-A::5.000|prod-B::10.000"]
```

Both are internally coherent (no M1 relapse); the second is the cost of keeping the first read on focus immediate. §1's behaviour delta now says so, the empty-document test is renamed `…when the first line is added…`, and a companion test `costs one extra settling request when a line is added to a document that already has priced lines` pins the sequence.

**Corrected by gate r3 (MINOR-13):** r2's PROBE E also reported `base (5e1e54f69): … after settle: 1`, and this section originally read that as "2 where the base cost 1". Gate r3 checked the companion test out against base `5e1e54f69` and round 0 `584fd3264` and it **passes at all three**, so under the sequence the test implements the base costs 2 as well. The +1 vs base is real but **timing-dependent** — it needs the add to land before the focus fetch dispatches. The test is a behaviour pin for HEAD, **not** a delta pin; nothing about M1 or the coherence invariant changes.

### 8.3 MINOR-8 — handback §3's browser block struck

§3's four browser items were written against round 0 and told the tester to confirm the *rejected* no-blink behaviour. Struck in place with a pointer to §7.6, which is the live list. §1's test-file row refreshed to the as-shipped 34 tests.

### 8.4 MINOR-9 — plan hygiene, plus two escalations OUT of this lane

The r1 banner covers Task 6, and Tasks 7 and 14 do not prescribe the pattern at all — but the gate found it still live elsewhere. In this round, in `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md`: a plan-wide `placeholderData: keepPreviousData` WARNING block is repeated at **Task 2 Step 6** and **Task 3 Step 5** (the two steps whose snippets still prescribe it), and **Task 5 Step 3**'s snippet is struck in place to match what actually shipped after its own gate removed the option.

**Escalated to the orchestrator, NOT fixed here (different features, different lanes):** `placeholderData: keepPreviousData` has already shipped on two tenant/company-scoped reads —

- `apps/web/src/features/treasury/PaymentListPage.tsx:117`
- `apps/web/src/features/inventory/StockMovementsPage.tsx:190`
- `apps/web/src/features/pos/hooks/useDiscountPreview.ts:87` (found by gate r3, MINOR-14 — missed by both r1 and r2)

All three are `tenantScopedKey` reads, so all three are exposed to the same cross-company carry-over B1 describes (TanStack ignores key lineage; a company switch neither unmounts nor clears). Whether any is *committable* the way `Use suggested` was has not been assessed — that is each lane's first question, not an assumption. The POS one deserves the first look: a **discount preview** is closer in kind to a price verdict than a list of rows is.

### 8.5 Re-runs after fix round 2

```
$ pnpm vitest run src/features/documents/components/__tests__/
 ✓ …/DocumentLineEditor.test.tsx (34 tests)
 Test Files  10 passed (10)
      Tests  97 passed (97)

$ pnpm vitest run src/features/documents/
 Test Files  53 passed (53)
      Tests  453 passed (453)

$ pnpm typecheck
> tsc --noEmit
(no output)          EXIT: 0
```

eslint, both touched files: **0 errors, 2 + 13 warnings — same rule set as the `5e1e54f69` baseline, no new errors or warnings.** `pnpm audit:keys`: Gate C 0 / 0 new / 0 stale.

### 8.6 Commits added this round

| Hash | Subject |
|---|---|
| `e514f884e` | `fix(web request-hygiene t6): gate r2 — scope the company-switch claim to what is proven, pin the non-empty add cost` — 2 files changed, 96 insertions(+), 13 deletions(-) |
| (this file) | `docs(request-hygiene t6): gate r2 report, plan Task 3/5 hygiene, handback fix round 2` |


---

## 9. Fix round 3 — FE gate r3 (2026-09-04) — **VERDICT: MERGE**

Gate report: `docs/superpowers/reviews/2026-09-04-request-hygiene-t6-gate-frontend-conventions-r3.md` — **MERGE**, 0 BLOCKER, 0 MAJOR, 5 MINOR (all documentation-only) + 2 NIT. All five r2 merge conditions verified met; B1/M1 confirmed not regressed (`git diff 4ec9db85a..HEAD -- DocumentLineEditor.tsx` is **comment-only** — key, `queryFn`, `enabled` and `staleTime` are byte-identical). The gate re-ran everything itself: typecheck 0; two-file eslint 0 errors / 15 warnings with the rule set identical to the `5e1e54f69` baseline; `components/__tests__/` 10 files / 97 tests; `src/features/documents/` 53 / 453; `audit:keys` 0/0/0.

The five MINORs are folded in here, no re-gate:

| Finding | Disposition |
|---|---|
| **MINOR-10** — §1 and §6.5 still said "the ref carries…" / "ref payload" after the ref was deleted in round 1 | **Fixed** in place. |
| **MINOR-11** — §6.6 pointed browser checks at "§3, last block", which round 2 struck | **Fixed** — now points at §7.6. |
| **MINOR-12** — round 2's comment shifted `lineColumns`' deps to `:1120-1135` and the eslint warning to `1120:6`; §3/§7.4/§7.5/§8.1 still cited `1111` | **Fixed** — the M2/MINOR-3 ticket now points at the right line. |
| **MINOR-13** — the companion test passes at HEAD, at base `5e1e54f69` **and** at round 0 `584fd3264`, so it does not pin the "2 where base cost 1" delta §1/§8.2 attached to it; under the sequence it implements the base also costs 2, and the +1 is timing-dependent | **Fixed** — §1 and §8.2 corrected; the test is now described as a behaviour pin for HEAD, not a delta pin. This corrects gate r2's own PROBE E number, which the lane had transcribed faithfully. |
| **MINOR-14** — the escalation list was short by one: `apps/web/src/features/pos/hooks/useDiscountPreview.ts:87` is a **third** shipped `placeholderData: keepPreviousData` on a `tenantScopedKey` read (missed by r1 and r2 as well) | **Fixed** — added to §8.4. Flagged as the one to look at first: a discount *preview* is closer in kind to a price verdict than a list of rows. |
| **NIT-2** — one unattributed failure in the first of the gate's 13 `src/features/documents/` runs (452/453); 12 subsequent runs, including a cold-cache one, were green and the touched file was 10/10 in isolation | Noted, not reproduced in any of this lane's runs. |

### 9.1 Still owed at promotion (unchanged)

1. The **§7.6 browser pass** on purchase order and credit note — item 3 (company switch mid-form) is now the only check that can catch the M2 residual.
2. The **merged MINOR-3 + M2 ticket**: fix `LineItemsTable`'s component-typed cells (`LineItemsTable.tsx:133-138`) **first**, then add `pricingContext?.items` and `openPricingLineId` to `lineColumns`' deps (`DocumentLineEditor.tsx:1120`). Doing it in the other order drops focus out of the price input mid-typing. Also fixes the fact that the pricing hint is largely dead in production today.
3. The **three escalated `placeholderData` sites** (§8.4), each as its own lane.
4. The **inherited full-lint RED** — `audit:design-system` 14 new + 11 stale, all in `src/features/import/pages/ImportWizardPage.tsx`, from the imports merge `40aed177b`. Present in the main checkout too; this lane contributes zero.

### 9.2 Commits added this round

| Hash | Subject |
|---|---|
| (this file) | `docs(request-hygiene t6): gate r3 = MERGE, fold the five documentation MINORs` |
