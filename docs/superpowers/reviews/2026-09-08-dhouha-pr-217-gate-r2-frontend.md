# Gate r2 — PR #217 fix round 1 (gate r1 MAJOR M-1)

- Reviewer: frontend-conventions-reviewer (adversarial re-gate, r2)
- Date: 2026-09-08
- Checkout: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-217` — branch `gate/pr-217`, HEAD `fcd728229`
- Delta under review: `git diff 64fabaadd..fcd728229` (2 files, +36/-1)
- Base of PR: `origin/dev` `cdedc2830`
- r1 report: `docs/superpowers/reviews/2026-09-08-dhouha-pr-217-gate-r1-frontend.md`
- Scope of THIS gate (as briefed): only (a) M-1 closure, (b) test honesty, (c) re-run gates, (d) no collateral damage to the other scopes. r1's m-1…m-5 were re-checked only for regression, not re-litigated.

**VERDICT: MERGE** — M-1 is closed and empirically proven closed. 0 Blocker, 0 Major, 1 new Minor (a UX regression the fix itself introduces on an idempotent re-click), 1 pre-existing Minor newly observed. Neither blocks.

---

## 1. The delta is exactly two files and nothing else

`git diff 64fabaadd..fcd728229 --stat`:

```
.../__tests__/CreateCountingProductLocationScope.test.tsx | 33 +++++++++
.../pages/CreateCountingPage.tsx                          |  4 ++-
```

No baseline file, no `queries.ts`, no e2e spec, no locale file, no config. Working tree `git status --porcelain` empty at gate start and at gate end (I reverted/restored source twice and wrote/deleted a scratch spec — verified clean both times).

**(a) The source change is the one line r1 asked for, plus a comment.**
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:223-225`
```tsx
// Reset the filters on scope change so a stale location_id / zone /
// category selection from a previous scope never leaks into the payload.
onChange={(scope_type) => { setFormData({ ...formData, scope_type, scope_filters: {} }); }}
```
That is the entire production diff. No other line of `CreateCountingPage.tsx` moved (the file's other 8 `scope_filters` writers at `:51,347,368,381,497,535,550,559` are byte-identical to `64fabaadd`).

Delta contains **zero** `className`, `queryKey`, `tokens.*` or `colorClasses` additions (grepped the `+` lines) — so no design-system, TanStack-key, interpolation or conservation surface is touched by this round. Confirmed by the audits in §3 returning numbers identical to r1.

---

## 2. (b) The new test exercises the REAL switch path — verified red-then-green by me

`apps/web/src/features/inventory-counting/__tests__/CreateCountingProductLocationScope.test.tsx:232-264`
drives the component through the actual transition, not a fresh render:
`product_location` tile (`:237`) → next → Add Product (`:239`) → Select Location (`:240`) → **`previous`** (`:244`) → `product` tile (`:245`) → next (`:246`) → … → submit (`:257`).

Assertions are data-meaning, not status/no-throw:
- `:262` `expect(payload.scope_filters).toEqual({ product_ids: ['p-1'] })`
- `:263` `expect(payload.scope_filters).not.toHaveProperty('location_id')`

`:263` is the load-bearing one: `toEqual` ignores `undefined`-valued keys, so on its own `:262` would pass a payload carrying `location_id: undefined`. `not.toHaveProperty` fails on a present-but-undefined key, so the pair actually pins key **absence**. Good assertion design.

**Red-first proven, not accepted as reported.** I reverted ONLY `CreateCountingPage.tsx` to `64fabaadd` (`git checkout 64fabaadd -- …`) and re-ran the file:

```
✓ … renders a location selector for product_location scope
✓ … blocks the selection step when products are selected but no location is chosen
✓ … carries product_ids and location_id in the create payload once both are selected
✓ … clears location_id and blocks again when the location is deselected
✓ … does NOT render a location selector under plain product scope and submits product_ids only
× … drops the stale location_id when the scope is switched from product_location to product before submit
  → expected { product_ids: [ 'p-1' ], …(1) } to deeply equal { product_ids: [ 'p-1' ] }
Tests  1 failed | 5 passed (6)
```

Two things this proves at once: the new test **fails** on the pre-fix source (so it is a genuine guard, not a tautology), and the pre-existing fresh-render `product` test at `:206-231` **still passes** on that same pre-fix source — i.e. the new test covers a path the old one provably did not. That was exactly r1 M-1's complaint. Source restored to `fcd728229` and tree re-verified clean.

---

## 3. (c) Guardrails re-run by the reviewer

| Gate | Command | Result |
|---|---|---|
| Feature unit tests | `pnpm vitest run src/features/inventory-counting --reporter=dot` | **11 files / 75 tests PASS** (r1 was 74; +1 = the new switch-path test) |
| Typecheck | `pnpm typecheck` (`tsc --noEmit`) | **clean**, exit 0 |
| ESLint (2 touched files) | `pnpm eslint <page> <test>` | **0 errors**, 10 warnings — all pre-existing on untouched lines (`:150,156,222,231,640,655,774,862,873,894`; note `:222` is the untouched `scope_type \|\| 'full_inventory'` prop, not the changed line) |
| TanStack keys | `pnpm audit:keys` | Gate C **0 violations, 0 new, 0 stale** — identical to r1 |
| Design system | `pnpm audit:design-system` | **802 acknowledged, 0 new, 0 stale** — byte-identical to r1's number |

Baseline honesty (protocol §2): **PASS** — no baseline file in the delta, and both audits report `0 stale baseline entries`, so nothing was absorbed or padded. Mechanism audit (protocol §3): **PASS** — the delta adds no alias, no suppression comment, no renamed literal, no class string at all.

Vitest workers reaped after every run (`pkill -f 'node \(vitest'`); `ps` clean.

---

## 4. (d) Collateral damage check — I drove all six scopes, empirically

Static reading first: every `scope_filters` writer lives in `ProductSelectionStep` / `NodeScopeSelection` (`CreateCountingPage.tsx:345-385`, `:494-501`, `:532-564`), and the selection step is `STEPS[1]` — strictly **after** the scope step (`:29`). `needsSelectionStep()` (`:63-70`) and `canProceed()` (`:72-111`) read filters only from that later step. So no scope reads a filter written before its own scope was chosen. Resetting on scope change is therefore sound by construction.

I did not stop at reading. I wrote a throwaway spec (copy of the file's mocks + 6 probes), ran it, deleted it, re-verified `git status --porcelain` empty:

| Probe | Result |
|---|---|
| B — `zone`: select scope → next → pick location | location selector renders, flow intact |
| C — `category`: full wizard → submit | payload `{"category_ids":["7"]}` ✅ |
| D — `location`: full wizard → submit | payload `{"location_ids":["loc-1"]}` ✅ |
| E — `full_inventory`: full wizard → submit | `scope_filters: {}`, all other fields intact ✅ |
| A — re-click the **already selected** tile | **selection wiped**, Next re-disabled → see m-6 |
| F — back/forward within the same scope, no tile click | filters kept ✅, but chips list empty → see m-7 |

`product` and `product_location` are covered by the shipped suite (75/75). So: five of six scopes are unaffected; the sixth issue is not a scope, it is the re-click path below.

---

## 5. Findings

### r1 MAJOR M-1 — **CLOSED**
`CreateCountingPage.tsx:225` now resets `scope_filters`, and `CreateCountingProductLocationScope.test.tsx:232-264` guards the switch path with an absence assertion. Proven red on `64fabaadd`, green on `fcd728229`. The PR body's `product`-scope regression claim is now true as written.

### MINOR

**m-6 (NEW, introduced by this fix) — re-clicking the ALREADY SELECTED scope tile silently wipes the operator's selection.**
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:225` — `ScopeStep` calls `onChange(type)` on **every** tile click (`:314`), including the tile that is already active. Before `fcd728229` that was a harmless no-op; now it clears `scope_filters`.

Empirically proven (probe A, since deleted): `product` scope → Add Product → `previous` → click the same highlighted `product` tile → `next` is **disabled**, the product list is gone, no warning. On `64fabaadd` the same sequence kept the selection.

Not a payload/data-meaning bug (what is submitted is always consistent with the chosen scope) and fully recoverable in-wizard, hence Minor — but it is silent work loss on a plausible "yes, this one" click, and it is new debt from this round.

*Fix directive:* make the reset conditional at `CreateCountingPage.tsx:225` —
`onChange={(scope_type) => { setFormData(scope_type === formData.scope_type ? { ...formData, scope_type } : { ...formData, scope_type, scope_filters: {} }); }}`
— plus one test asserting a same-tile re-click preserves `product_ids`. One line; if the owner wants it in this PR I can re-gate the delta in minutes.

**m-7 (pre-existing, newly observed) — the selected-products panel shows a count with zero rows after any back-and-forth.**
`CreateCountingPage.tsx:341` — `selectedProducts` is `ProductSelectionStep`-local state, but `product_ids` lives in the parent's `formData`. The step is conditionally mounted (`:229`), so leaving and returning remounts it with `selectedProducts = []` while `productIds` survives. Probe F: header `products:selectedProducts {count: 1}` (`:439`) renders with **0** `ProductCell` rows (`:442`). Present on `cdedc2830`, unchanged by this PR — record as a ticket, do not charge it to #217. (Ironically m-6's wipe hides this on the re-click path.)

**r1 m-1…m-5 — carried forward unchanged, all still non-blocking.** The delta does not touch `api/queries.ts` (m-1 lint exclusion), the e2e spec (m-2 raw-key locators, m-3 unratcheted spec), `LocationSelectorMulti` (m-4 `maxSelection={1}` no-op), and m-5's `undefined`-vs-deleted note is now *strengthened*, not weakened, by the `not.toHaveProperty` assertion at `:263`.

### Cross-cutting (protocol §"Cross-cutting checks")
No catalogue entity, no unique key, no new noun or FE type, no route/nav change, no module gate, no refund/blind-count/brand surface, no quantity display, no money path. Second-of-everything / one-surface-per-concept / benchmark-first: **N/A** for this delta, same as r1.

---

## 6. Claim-vs-verified table for this round

| Claim | Verdict |
|---|---|
| M-1 fixed with the exact one-liner r1 directed | **TRUE** (`CreateCountingPage.tsx:225`) |
| Nothing else changed in the page | **TRUE** (diff is 1 hunk, 3 added lines) |
| New test covers the switch path, not a fresh render | **TRUE** (re-ran against reverted source: only the new test goes red) |
| Feature suite green | **TRUE** — 11 files / 75 tests, re-run by me |
| Typecheck green | **TRUE** — re-run by me |
| Other scopes unaffected | **TRUE for zone/category/location/full_inventory/product/product_location** (probes B–F + shipped suite); **one behavioural change found on the same-tile re-click path** → m-6 |

---

## VERDICT: MERGE

M-1 closed, no Blocker, no Major, no new debt in any ratchet (0 new / 0 stale on both audits). m-6 is a genuine new Minor introduced by the fix — recommend either the one-line guard now (I will re-gate immediately) or a ticket; m-7 and r1's m-1…m-5 are tickets against pre-existing debt. I do not merge or push.

---

# r3 delta — fix of r2 m-6 (`fcd728229..309cfd686`)

- Re-gate date: 2026-09-08 · HEAD `309cfd686` on `gate/pr-217`
- Scope of THIS section, as briefed: only `git diff fcd728229..309cfd686` (2 files, +39/-1). r1/r2 findings are not re-litigated; §1-§5 above stand.

## (a) The guard is exactly what m-6 directed
`apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:225-232`
```tsx
onChange={(scope_type) => {
  if (scope_type === formData.scope_type) {
    return
  }
  setFormData({ ...formData, scope_type, scope_filters: {} })
}}
```
Early-return on the already-active tile (a true no-op — it does not even re-`setFormData`, so no needless re-render), and the **M-1 reset is unchanged on every real scope change**. That is the whole production diff: 1 hunk, 7 added lines + 2 comment lines, nothing else in the file moved.

Edge cases checked, not assumed: `scope_type` is initialised to `'full_inventory'` (`:50`) and only ever assigned a defined member of `SCOPE_TYPES` (`:34-41`, `:314`), so the comparison is never `undefined === 'full_inventory'` on the first click of the default tile; and were it ever `undefined`, the guard falls through to the reset branch — fail-safe direction, no stale-filter path re-opens.

## (b) The new test drives the real re-click path
`__tests__/CreateCountingProductLocationScope.test.tsx:266-295` — `product_location` tile → next → Add Product → Select Location → **`previous`** (`:279`) → **re-click the same `product_location` tile** (`:280`) → next → … → submit. It asserts both the guard (`:284` Next still enabled after the re-click) and the payload (`:293` `toEqual({ product_ids: ['p-1'], location_id: 'loc-1' })`) — data meaning, not a status code.

**Red-first proven by me**, not accepted as reported: reverting ONLY the page to `fcd728229` gives
```
✓ … drops the stale location_id when the scope is switched from product_location to product before submit
× … keeps product_ids and location_id when the already-active scope tile is re-clicked
  → expect(element).toBeEnabled()
Tests  1 failed | 6 passed (7)
```
Two things at once: the new test genuinely fails on the pre-fix source, and the **r2 M-1 switch-path test still passes there** — so the new test isolates the m-6 path and does not overlap or dilute the M-1 guard. Source restored to `309cfd686`; `git status --porcelain` empty.

## (c) Gates re-run at `309cfd686`

| Gate | Result |
|---|---|
| `pnpm vitest run src/features/inventory-counting --reporter=dot` | **11 files / 76 tests PASS** (r2 was 75; +1 = the re-click test) |
| `pnpm typecheck` | **clean**, exit 0 |
| `pnpm eslint` on the 2 touched files | **0 errors**, same 10 pre-existing warnings as r2 (unchanged lines) |

Delta adds zero `className` / `queryKey` / `tokens.*` / `parseFloat` / `decimalPlaces` (grepped the `+` lines), and touches no baseline file — the r2 audit numbers (`audit:keys` 0/0/0, `audit:design-system` 802/0 new/0 stale) are unaffected by construction. Vitest workers reaped after each run.

## r3 findings
**m-6 — CLOSED.** No new finding. m-7 (pre-existing `selectedProducts` local-state/`product_ids` divergence, `CreateCountingPage.tsx:341`, `:439`, `:442`) and r1's m-1…m-5 remain open as tickets against pre-existing debt, none chargeable to #217.

## FINAL VERDICT (r3): MERGE
M-1 and m-6 both closed with independently re-proven red-then-green guards; 0 Blocker, 0 Major, 0 new Minor at this HEAD; every ratchet flat. I do not merge or push.
