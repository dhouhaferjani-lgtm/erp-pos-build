# FE gate r1 — Request Hygiene Phase A, Task 6 (debounce bulk pricing context, S-5)

- Date: 2026-09-04
- Gate: frontend-conventions-reviewer (adversarial)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t6`
- Branch: `lane/rh-t6-pricing-debounce`, commit `584fd3264`, base `5e1e54f69`
- Handback under test: `docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md`
- Plan: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` `## Task 6` (line 1664)

## Verdict: **CHANGES** — 1 BLOCKER, 1 MAJOR, 5 MINOR. Do not merge.

The debounce itself is correct, minimal and genuinely falsifying-tested; I reproduced every
evidence line in §3 of the handback. The two *extra* mechanisms the plan bolted on — `placeholderData:
keepPreviousData` and the render-written ref — are the problem. `keepPreviousData` re-lands the exact
defect this gate removed from `LineItemEntryBar` one day earlier, and the handback's two load-bearing
arguments for it are both false in code and falsified by probe. The ref does not deliver the
key/payload coherence it claims; the diff in fact *introduces* incoherence that outlives the 250 ms
window.

All probe evidence below was produced in this worktree with throwaway spec files that have been
deleted; `git status --short` at the end of the review shows only the untracked handback.

---

## 1. Verification I ran myself (protocol step 1 + 5)

| Check | Command | Result |
|---|---|---|
| typecheck | `pnpm typecheck` (whole project) | **clean, 0 errors** — handback claim confirmed |
| touched-dir tests | `pnpm vitest run src/features/documents/components/__tests__/` (DEFAULT pool) | **10 files / 93 tests passed** — matches handback exactly |
| eslint, 2 touched files | `npx eslint <both files>` | **0 errors**, 2 + 13 warnings, all pre-existing rules — no new errors/warnings (see MINOR-4 for a line-number nit) |
| `audit:keys` (Gate C) | inside `pnpm lint` | `Gate C … without an approved tenant scope: 0`; `0 acknowledged, 0 new, 0 stale` |
| `pnpm lint` overall | `pnpm --filter @autoerp/web lint` | **RED — inherited, not this lane** (see MINOR-5) |
| RED re-check | reverted `debouncedPricingSignature` → `pricingContextSignature` in the key, ran `-t 'waits 250 ms'` | fails at `DocumentLineEditor.test.tsx:1093` with `Number of calls: 3` — **exactly the handback's §3 RED**. Restored, `git diff HEAD` empty. |

Baseline honesty: `apps/web/tools/audit-design-system-baseline.json` is **not** in the diff
(`git show 584fd3264 --stat` = 2 files), so no baseline absorption. No alias table, no suppression
comment, no renamed literal — nothing in the diff defeats a detector. Mechanism audit clean.

Global constraints (plan line 15): the diff touches exactly
`apps/web/src/features/documents/components/DocumentLineEditor.tsx` and its `__tests__` sibling.
`DocumentForm.tsx`, `ProductController.php`, every Inventory Counting file and all of `apps/api`
are untouched. **Constraints respected.** The WAIT gate is genuinely cleared: base `5e1e54f69` is
the merge of `lane/rh-t5-product-search` into local `dev`, which already carries the wave-2 PO lane.

---

## 2. BLOCKER

### B1 — `placeholderData: keepPreviousData` re-lands the defect gate r1 removed from `LineItemEntryBar`, and BOTH handback justifications are false

`apps/web/src/features/documents/components/DocumentLineEditor.tsx:417`.

The codebase currently carries an explicit anti-regression comment, landed by the T5 fix round one
day ago, at `apps/web/src/components/molecules/line-items/LineItemEntryBar.tsx:89-96`:

> `// NO placeholderData: keepPreviousData here, deliberately (gate r1 B2).`
> `// … so it happily hands the PREVIOUS company's products back across the`
> `// tenant/company suffix of tenantScopedKey — CompanySelector only`
> `// invalidates, it never unmounts this bar.`

This lane reintroduces the same option one directory over, in a component that is likewise never
unmounted on a company switch. Handback §6.2 offers two reasons it is safe here. Both are false.

**False claim 1 — "a company switch re-mounts the document form."** It does not.
`apps/web/src/stores/companyStore.ts:186-198` `setCurrentCompany` only calls `set({ currentCompanyId })`
+ `persistCompanyId`. `apps/web/src/features/company/CompanyProvider.tsx:143` returns `<>{children}</>`
— no key, no remount. `apps/web/src/components/organisms/CompanySelector/CompanySelector.tsx:39-47`
only `queryClient.invalidateQueries()`. There is no `key={currentCompanyId}` anywhere in `src`
(grep: zero hits). `DocumentLineEditor.tsx:289` subscribes to `currentCompanyId` via the hook, so the
component **re-renders in place** with a new company suffix on the same observer.

**PROBE 1 (measured, throwaway spec, mutable company mock):**

```
PROBE1 after company switch, company-1 cost still on screen: true
  × keepPreviousData shows the PREVIOUS COMPANY pricing after a company switch
```

Company-1's `Cost 111.000000` stays rendered at `DocumentLineEditor.tsx:900-909` while the company-2
read is in flight. The payload is company-scoped server-side —
`apps/api/app/Modules/Product/Presentation/Controllers/LineEntryController.php:104-105`
(`->where('tenant_id', …)->where('company_id', $company->id)`) — and it carries **WAC cost, last
purchase cost and margin policy** (`:135-152`). This is a cross-company *cost* display, strictly
worse in kind than T5's product list.

**False claim 2 — "nothing in this component commits the placeholder."** It does.
`DocumentLineEditor.tsx:927` renders the details popover from the same `pricingItem`, and its
`Use suggested` button at `:938-950` calls
`handleUpdateLine(line.id, { price_entry_mode: 'unit', unit_price: pricingItem.suggested_price })`
at `:943-946`. The popover is reachable while `isPlaceholderData` is true, because nothing gates on it.

**PROBE 4 (measured):**

```
PROBE4 Use suggested reachable while showing company-1 placeholder: true
PROBE4 onChange payload: [{… "unit_price":"SUGGEST-10.000" …}]
```

(the mock echoes the *requesting* company's answer into `suggested_price`; `SUGGEST-10.000` is
company-1's value, written into the line while the active company is company-2). That is T5's B1
commit path and T5's B2 scope leak in one component.

**Also unaffected by the debounce but affected by the placeholder:** `partnerId`
(`DocumentLineEditor.tsx:407`) is an *un-debounced* key segment, and
`last_sale_to_partner` (`:932`, backend `LineEntryController.php:140-144`) is partner-specific. Changing
the partner while a price cell has ever been focused shows the previous partner's last-sale price.

**Severity rationale.** `focusedPriceLineId` is set at `:843`/`:860` and **never reset to null**, so
once the operator touches any price cell the query stays enabled for the rest of the form's life —
the exposure window is not "250 ms while typing", it is "every scope change for the rest of the
session".

**Fix directive (pick one, then correct handback §6.2 — it records a TanStack semantic that the next
lane will copy):**
(a) Drop `placeholderData` entirely — the six sibling pickers in `components/molecules/pickers/` and
now `LineItemEntryBar` use none (`grep -rn "keepPreviousData\|placeholderData" src` confirms); the
hint blinking during a burst is the correct, honest behaviour. **Preferred, and it is the option the
same gate already chose for T5.**
(b) Keep it and gate BOTH the hint block (`:900`) and the popover/`Use suggested` (`:927`) on
`isPlaceholderData === false`, and additionally require the debounced signature to equal the live
one, so a placeholder verdict is never displayed *or* commitable.

Also: plan Step 3 (line ~1734) prescribes `placeholderData: keepPreviousData` verbatim. The plan text
must be amended in the same round, or Tasks 7/14 will re-land it a third time.

---

## 3. MAJOR

### M1 — the diff makes the query KEY lag the request BODY; the incoherence is real, observable, and durable for `staleTime` (30 s)

`DocumentLineEditor.tsx:395-396, 404-419`.

Handback §1 asserts: *"the request body reads `pricingContextLinesRef.current` … so the payload always
matches the signature that fired the query rather than the un-debounced live one."* **False.** The key
uses the *debounced* signature while `enabled` (`:397-402`, `pricingContextLines.length > 0`) and the
body (`:412`) are both *live*. Whenever a fetch is triggered while the debounce is still lagging, the
answer for body-state N is cached under the key for signature N−1.

**PROBE 2 — the common "add a product, then click its price" path (measured, fake timers):**

```
PROBE2 requests at focus: 1  after settle: 2
PROBE2 keys after settle:
  ["line-entry-pricing-context","partner-1","","tenant-1","company-1"]
  ["line-entry-pricing-context","partner-1","prod-1::10.000","tenant-1","company-1"]
PROBE2 bodies: [{…unit_price:"10.000"}, {…unit_price:"10.000"}]
```

The signature segment `""` means "no lines", yet that cache entry holds `prod-1`'s answer. And the
path costs **2 requests where the debounce should give 1** — on the single most common entry
sequence in the editor, S-5 is only half-fixed.

**PROBE 3 — the durable form (measured):**

```
PROBE3 cache entries: [{"sig":"prod-1::10.000","cost":"ASKED-20.000"},
                       {"sig":"prod-1::20.000","cost":"ASKED-20.000"}]
PROBE3 line is at 10.000; hint on screen says: Cost ASKED-20.000 · Last buy - · Margin 30%
```

Sequence: the line's effective unit price moves 10 → 20 without the price cell being focused (in
`price_entry_mode: 'total'` a **quantity or discount** edit does exactly this — `deriveUnitPrice`
at `:341-369` feeds the signature at `:377`), then the operator focuses the price inside 250 ms. The
`prod-1::10.000` key is now permanently bound to the verdict computed at 20.000, and when the line
returns to 10.000 that wrong verdict is served from cache for up to `staleTime: 30000` (`:418`).

This is not cosmetic. `policy.allowed` / `policy.level` are **price-dependent** server-side
(`LineEntryController.php:130-132`, `getMarginLevel($product, $unitPrice)` /
`canSellAtPrice($product, $unitPrice, $user)`), and they drive `isBlocked` (`:794`), the
`Margin blocked:` message (`:796-800, :921-923`) and the red `error` border on the `MoneyInput` /
`DraftMoneyInput` (`:836`, `:856`). A false "blocked" or a false green on a price the operator is
about to submit is an owner-rule violation in its own right: **UI must not present a verdict it does
not actually hold for the current state.**

Two further triggers of the same class: `retry: 1` is the app default
(`apps/web/src/lib/queryClient.ts:7` — the test wrapper overrides it to `false`, so this is invisible
to the suite), and a retry re-invokes the queryFn, which reads `pricingContextLinesRef.current`
**at invocation time** — i.e. a *newer* body than the key. `refetchOnReconnect: true` (`:9`) plus T8's
reconnect `invalidateQueries` is the second.

**Fix directive.** Make key and body come from the *same* value. Debounce the lines, not the
signature, and derive everything from the debounced array:

```tsx
const debouncedPricingLines = useDebouncedValue(pricingContextLines, 250) // useMemo-stable identity
const debouncedPricingSignature = useMemo(() => debouncedPricingLines.map(…).join('|'), [debouncedPricingLines])
// enabled: … && debouncedPricingLines.length > 0
// queryFn body: lines: debouncedPricingLines
```

This deletes the ref (M2 below falls out), makes the `""`-key and the poisoned-key entries impossible,
and makes the add-then-focus path cost exactly one request. Ship a test for the add-then-focus
sequence and one asserting the cached key's signature segment equals the body it was fetched with.

---

## 4. MINOR

**MINOR-1 — the render-written ref is inert, untested, and its stated justification is wrong.**
`DocumentLineEditor.tsx:395-396`, handback §6.3. I falsified it: replacing `pricingContextLinesRef.current`
with `pricingContextLines` at `:412` leaves the new test **green** (`Tests 1 passed | 29 skipped`), so
nothing in the suite covers the mechanism. The §6.3 rationale ("an effect-time write would be one
render behind under React 19 StrictMode double-render") is not the operative mechanism — TanStack v5
calls `observer.setOptions` with a freshly-closed queryFn on the same render that moved the key, so a
plain closure is equally current; the only case where `.current` differs is a **retry**, where the ref
makes the body *newer* than the key (M1). The write itself is harmless under StrictMode (same value
written twice) but React documents refs as not to be written during render. Resolve by deleting it as
part of M1's fix.

**MINOR-2 — Deviation D1 is ACCEPTED; the mechanism is real.** Verified in code:
`apps/web/src/components/molecules/line-items/LineItemsTable.tsx:133-138` renders each cell as a
component type (`const Cell = column.Cell; <Cell line={line} index={index} />`); `lineColumns`
(`DocumentLineEditor.tsx:689`) is a `useMemo` whose deps include `t` (`:1122`); and this suite's `t`
is a deliberately fresh closure per render (`__tests__/DocumentLineEditor.test.tsx:40-42`, documented
at `:29-39`). New `Cell` identities on every render therefore unmount and remount the cell subtree,
detaching any captured node. The shipped `const priceInput = () => screen.getByRole(...)` is a faithful
substitute: every assertion the plan specifies survives, and the test still goes red for the right
reason (reproduced above at `:1093`, 3 calls). The reason comment at `:1057-1063` is the right
mitigation.

**MINOR-3 — follow-up ticket, out of this lane's scope (rule 4): `LineItemsTable`'s component-typed
cells are a structural remount hazard.** `LineItemsTable.tsx:134-137`. In production `t` is stable, but
`lineColumns`' other deps (`priceSourceByLineId`, `invalidLineIds`, `purchaseBonusEnabled`,
`handleUpdateLine`) do move — every such change remounts every cell of every row, dropping DOM focus
and input selection. Worth its own ticket (invoke `column.Cell({ line, index })` or memoise per
column id).

**MINOR-4 — handback §3's eslint "after" line numbers were not re-measured on the committed file.**
It reports `283:32` and `1102:6`; measured on `584fd3264` they are **`280:32`** and **`1109:6`**. The
substantive claim (0 errors; 2 and 13 warnings; no new rule triggered) is verified true.

**MINOR-5 — `pnpm --filter @autoerp/web lint` is RED on this branch, inherited, not this lane.**
`audit:design-system` reports 14 new + 11 stale baseline entries, **all** in
`src/features/import/pages/ImportWizardPage.tsx`, which this lane does not touch; neither does it touch
the baseline JSON. Introduced by the imports merge `40aed177b`. This lane contributes zero design-system
violations, but the branch cannot claim a green lint gate and whoever promotes must reconcile it.

**MINOR-6 — Rule 22 "second-of-everything" gap.** The lane ships no second-company leg, and the
second-company behaviour is precisely what is broken (B1/PROBE1). Whatever fix lands for B1 must ship
with a company-switch test on this component.

---

## 5. Rulings on the specific questions the handback asks (§6)

1. **D1 re-query loop** — ACCEPTED, mechanism verified (MINOR-2). Yes, the cell remount deserves its
   own follow-up ticket (MINOR-3).
2. **`placeholderData` / stale-verdict** — the "read-only advice, nothing commits the placeholder"
   argument **does not hold**: `Use suggested` (`:943-946`) commits it, proven by PROBE 4, and the
   company-switch leg is not protected by a remount that does not exist, proven by PROBE 1 + the
   three code sites in B1. **BLOCKER.**
3. **Render-written ref** — correct under StrictMode, but inert, untested, wrongly justified, and
   coherence-negative under `retry: 1`. Delete it with M1's fix. **MINOR.**
4. **Key shape / invalidation** — CLEAN. Root unchanged, arity unchanged, tenant/company still the last
   two segments (`tenantScopedKey.ts:29-35`), one producer in the repo
   (`grep -rn "line-entry-pricing-context" apps/web/src apps/pos/src` → `DocumentLineEditor.tsx:406`
   only), zero `invalidateQueries`/`removeQueries`/`setQueryData` on that root, `audit:keys` = 0. No
   prefix-invalidation hazard.
5. **Rule 19** — CLEAN. No `parseFloat`/`Number()` added; the signature and payload are
   `decimalValue(...)` strings; the asserted `unit_price: '125'` is a string. No `any`; no new
   user-facing copy (so no i18n exposure); no color classes touched, so no design-token drift.
   **"First read on focus is still immediate"** — TRUE for a document mounted with lines
   (`useDebouncedValue` seeds via `useState(value)`, `lib/hooks.ts:9`; pinned by the untouched
   "lazily fetches bulk pricing context" test at `__tests__/DocumentLineEditor.test.tsx:931`). It is
   also immediate, but **incoherent and duplicated**, on the add-then-focus path (M1 / PROBE 2).
6. **Browser checks** — agreed they are promotion-owed, but after B1/M1 are fixed they must be re-scoped:
   the PO and credit-note runs must include a **company switch mid-form** and an **add-product-then-click-price**
   sequence with the network panel open, not just a typing burst.
7. **Two React Doctor warnings** — agreed, inherited, out of scope under rule 4.

---

## 6. Merge conditions

1. Fix B1 (drop `placeholderData`, or gate display **and** the `Use suggested` commit on
   `isPlaceholderData === false`), and correct handback §6.2.
2. Fix M1 (debounce `pricingContextLines`, derive key/body/`enabled` from the one debounced value;
   the ref disappears), and correct handback §1.
3. Amend plan Task 6 Step 3 so `placeholderData: keepPreviousData` is not copied by later tasks.
4. New tests: company-switch on this component; add-product-then-focus costs one request; the cached
   key's signature segment matches the body it was fetched with.
5. Re-run `pnpm typecheck`, the two-file eslint, and
   `pnpm vitest run src/features/documents/components/__tests__/` + `src/features/documents/`.

_No files were committed and nothing was merged. Probe specs were deleted; the worktree is at
`584fd3264` with only the untracked handback and this report._
