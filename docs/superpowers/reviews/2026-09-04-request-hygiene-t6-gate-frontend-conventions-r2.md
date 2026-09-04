# FE gate r2 — Request Hygiene Phase A, Task 6 (debounce bulk pricing context, S-5)

- Date: 2026-09-04
- Gate: frontend-conventions-reviewer (adversarial re-gate after fix round 1)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t6`
- Branch: `lane/rh-t6-pricing-debounce`, HEAD `272247e59`, base `5e1e54f69`
- Round 0 `584fd3264` · fix round 1 `4ec9db85a` · docs `272247e59`
- r1 report (committed, unmodified): `docs/superpowers/reviews/2026-09-04-request-hygiene-t6-gate-frontend-conventions.md`
- Handback: `docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md` (§7 = fix round 1)

## Verdict: **CHANGES** — 1 MAJOR, 3 MINOR. B1 and M1 are genuinely fixed; the remaining work is comment/doc-level plus one follow-up ticket.

The code changes in `4ec9db85a` are correct, minimal, and I reproduced every RED/GREEN line
in §7.3 myself against `584fd3264` rather than trusting the handback. `placeholderData` is gone,
the ref is gone, and key/body/`enabled` now derive from one debounced value — verified in code and
by four fresh probes.

What blocks a clean MERGE is **M2**: the guarantee the lane now asserts in a production code comment
(`DocumentLineEditor.tsx:413-419`), in a test name (`:1165`) and in the handback (§7.1, §7.2) —
*"a company switch can never leave the previous company's pricing on screen or commit it"* — **is not
true in production**. A pre-existing missing-dependency bug on the `lineColumns` memo reproduces the
identical leak, and the suite cannot see it because the file's `t` mock is a fresh closure per render.
I measured the leak on **HEAD** with a production-faithful stable `t` (PROBE D). It is strictly
pre-existing (identical at base `5e1e54f69`), so it is not a regression and not a BLOCKER against this
lane — but a gate cannot certify a guarantee it has just falsified, and the false-green test is exactly
the class of evidence this gate exists to catch.

All probes below were run in this worktree with throwaway spec files that have been deleted;
`git status --short` is empty and `git diff HEAD` is empty at the end of the review. The component was
temporarily checked out at `584fd3264` / `5e1e54f69` for falsification and restored each time.

---

## 1. Merge-condition verification (r1 §6)

| r1 condition | Ruling | Evidence |
|---|---|---|
| **1. Fix B1** (`placeholderData` gone, no display/commit path for a previous scope's answer) | **MET in code, NOT MET as a production guarantee** — see M2 | §2 |
| **2. Fix M1** (key, body, `enabled` from one debounced value) | **MET** | §3 |
| **3. Plan amendment** (T7/T14 cannot copy the pattern) | **MET literally**; the pattern survives elsewhere in the plan and in two already-landed lanes — MINOR-9 | §4 |
| **4. New tests** (company switch, add-then-focus = 1 request, cache coherence) | **MET and genuinely falsifying**; the company-switch leg is mock-dependent (M2) and the add-then-focus leg is narrower than claimed (MINOR-7) | §5 |
| **5. Re-runs** | **MET, all re-run by me** | §6 |

---

## 2. Condition 1 — B1

**`placeholderData` is genuinely gone.** `git show 4ec9db85a` removes both the option and the
`keepPreviousData` import; `grep -rn "keepPreviousData\|placeholderData" apps/web/src` returns no
occurrence in `DocumentLineEditor.tsx` except the anti-regression comment at `:413-419`. The ref is
gone too (`grep -c pricingContextLinesRef` → **0**).

**Every display and commit path is gated on the live query data.** The hint (`:902`), the
`Pricing details` button (`:913-925`) and the popover with `Use suggested` (`:929-953`, the commit at
`:944-948`) are all inside `pricingItem !== undefined`, and `pricingItem` is read from
`pricingContext?.items[...]` at `:794` — the `data` of the one `useQuery` at `:402`. There is no local
mirror of the answer (no `useState`/`useEffect` capture of `pricingContext` anywhere in the file), so
with the placeholder gone a key change yields `data === undefined` and all three surfaces unrender.

**Partner-switch leg (r1 asked specifically): FIXED.** `partnerId` is segment 1 of the key (`:405`)
*and* `partner_id` in the body (`:409`), both un-debounced, so they always agree; with no placeholder,
a partner change drops the previous partner's answer. Measured — **PROBE A**, HEAD:

```
PROBE A partner-1 pricing still on screen after partner switch: false     (PASS)
```

and against round 0 the same probe fails (`expected <span></span> to be null`), i.e. `last_sale_to_partner`
/ cost / suggested price from partner-1 *were* being shown under partner-2 before this fix. The
partner leg was real and is now closed.

### MAJOR — M2: the company-switch guarantee does not hold in production, and the new test cannot see it

`apps/web/src/features/documents/components/DocumentLineEditor.tsx:1111-1126` (the `lineColumns`
`useMemo` dep array) — **pre-existing, untouched by this lane, identical at base `5e1e54f69`.**

The dep array omits `pricingContext` (eslint already says so, and it is the pre-existing warning this
lane reports at `1111:6`: *"missing dependencies: 'openPricingLineId' and 'pricingContext?.items'"*).
`LineItemsTable.tsx:133-138` renders each column's `Cell` as a **component type**, so the cell bodies
run inside the closure captured the last time the memo computed. When the pricing answer changes,
**no dep moves** (`companyCurrency`, `formatAmount`, `handleUpdateLine [deriveUnitPrice, onChange]`,
`handleRemoveLine [onChange]`, `getDiscountMode`, `priceSourceByLineId`, `readonly`, `t`, … are all
stable across a query resolution, which re-renders only this component) — so the rendered hint keeps
showing the **stale** `pricingContext`.

In this suite that is invisible, because the `t` mock is deliberately a fresh closure per render
(`__tests__/DocumentLineEditor.test.tsx:29-42, :44-46`), which forces the memo to recompute on every
render. The test file's own header comment already records this (m-4/m-5, deferred).

**PROBE D (measured on HEAD, same spec run against base for the pre-existence check), production-faithful
stable `t`, stable `onChange` (the `CreateCreditNotePage.tsx:630` shape — `onChange={setLines}`):**

```
PROBE D apiPost calls after focus: 1
PROBE D hint visible right after the answer arrives (no dep moved): false
PROBE D hint visible after one dep move:                            true
PROBE D LEAK — company-1 hint still on screen under company-2: true | Pricing details (Use suggested) reachable: true
```

Identical output at base `5e1e54f69`. Two consequences:

1. **The B1 leak survives in production through a second mechanism.** After a company switch in which
   no `lineColumns` dep happens to move, company-1's `cost_wac`, `last_purchase_cost`, margin verdict
   and `suggested_price` stay rendered under company-2, and `Use suggested` (`:944-948`) commits
   company-1's price into the line. `CreateCreditNotePage` is the exposed surface (stable `onChange`);
   `DocumentForm.tsx:698-704` passes an inline arrow, so its `handleUpdateLine` identity churns on every
   parent render and the leak there self-heals on the next parent render — i.e. it is timing-dependent,
   not absent.
2. **The pricing hint is largely dead in production today** — it does not appear when the answer
   arrives, only after some later unrelated dep move. That is a separate pre-existing product bug this
   gate is surfacing, not a T6 regression.

**Fix directive (choose, do not skip):**
(a) *Preferred, cheapest, in-lane:* downgrade the overstated claims — reword the comment at
`:413-419` and the test name at `:1165` to state what is actually proven ("no `placeholderData`, so
the query's own data cannot cross a key change"), add a one-paragraph "known residual" to handback §7.5
citing `DocumentLineEditor.tsx:1111` + `LineItemsTable.tsx:133-138`, and file the follow-up ticket.
(b) *Real fix — a separate lane, not this one:* fix MINOR-3 **first** (invoke `column.Cell({ line, index })`
instead of `<Cell …/>`, or memoise per column id), **then** add `pricingContext?.items` and
`openPricingLineId` to the dep array. The order matters: adding the deps alone makes every pricing
answer remount every cell and drop focus out of the price input mid-typing. MINOR-3 and M2 are one
ticket, not two.

Keep §7.6 item 3 (company switch mid-form) in the promotion browser pass — with M2 open it is now the
only check that can catch this.

---

## 3. Condition 2 — M1: key / body / `enabled` coherence

**MET.** `DocumentLineEditor.tsx:388` `const debouncedPricingLines = useDebouncedValue(pricingContextLines, 250)`;
`:389-394` the signature memo reads `debouncedPricingLines`; `:398` `enabled` reads
`debouncedPricingLines.length > 0`; `:409` the body sends `lines: debouncedPricingLines`. One value,
three consumers. The signature is a total encoding of the body (`product_id`,`variant_id`,`unit_price`
are the whole of `PricingContextLineRequest`), plus `partner_id` which is live in both key and body —
so key ⟺ body content is now a bijection.

**PROBE 2 re-run (add-then-focus), HEAD** — via the lane's own new test, which I re-ran and also
falsified against round 0:

```
round 0: × expected "spy" to not be called at all, but actually been called 1 times
HEAD   : ✓ 1 request, body {partner_id:'partner-1', lines:[{product_id:'prod-1', variant_id:null, unit_price:'10.000'}]}
```

The `""`-signature cache entry is impossible now: while the debounced array is empty, `enabled` is
false, so no query can be filed under the empty signature.

**PROBE 3 re-run (the durable poisoning), HEAD** — my own probe, price moved 10 → 20 before the first
focus, then focused inside 250 ms:

```
HEAD    PROBE B asked: ["prod-1::10.000","prod-1::20.000"] cached: ["prod-1::10.000","prod-1::20.000"]   (PASS)
round 0 PROBE B asked: ["prod-1::20.000","prod-1::20.000"] cached: ["prod-1::10.000","prod-1::20.000"]   (FAIL — r1 PROBE 3 exactly)
```

The poisoned key is gone.

**Retry path (`lib/queryClient.ts:7` `retry: 1`) is coherent.** With the ref deleted, the queryFn is a
plain closure over `debouncedPricingLines` from the render whose key hashes to that query object. A
query's `options.queryFn` is only ever refreshed by an observer whose key hashes to it, and the key is
now a function of the same array, so a retry can only re-send the body the key already claims. The
`refetchOnReconnect: true` (`:9`) + T8 reconnect-invalidation path inherits the same property.

**Starvation check (new risk introduced by debouncing an array instead of a string):** `useDebouncedValue`'s
effect deps are `[value, delay]`, so an *identity* change now restarts the timer where a string could not.
Both consumers hold `lines` in `useState` (`DocumentForm.tsx:189`, `CreateCreditNotePage.tsx:82`), so the
identity only moves on a real edit — no render-churn starvation. Confirmed no re-render loop:
`setDebounced` does not feed back into `pricingContextLines`' deps.

---

## 4. Condition 3 — plan amendment

**MET literally.** The AS-SHIPPED banner (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:1666-1672`)
and the Step 3 rewrite (`:1730-1755`, original demoted into a `<details>`) are clear, cite the mechanism
and name Tasks 7 and 14. Grep confirms **neither Task 7 (`:1786`) nor Task 14 (`:3387`) prescribes
`placeholderData`**, so those two tasks cannot copy it.

### MINOR-9 — the pattern is still live elsewhere in the same plan, and has already shipped twice

Out of T6's scope (rule 4); raised for the programme orchestrator, not as a T6 blocker.

- `…phase-a.md:1095` — **Task 3** still prescribes `placeholderData: keepPreviousData` on
  `tenantScopedKey(['payments', search, page, perPage])`, un-annotated. T3 has a fix round + FE gate owed.
- `…phase-a.md:1657` — **Task 5** still prescribes it for `LineItemEntryBar`, which is now factually
  contradicted by shipped code (`LineItemEntryBar.tsx:89-96` removed it).
- Already landed on this branch's base, same defect class (company suffix in the key, placeholder
  crosses it): `apps/web/src/features/treasury/PaymentListPage.tsx:105 + :117` (`522bd92bc`, T3) and
  `apps/web/src/features/inventory/StockMovementsPage.tsx:169 + :190` (`cf6c11ea6`, T2). I did not probe
  these two; the key shape and the absence of any remount make the leak structurally identical to B1/B2.

Fix directive: move the banner's rule to a plan-global note (or repeat it at Task 3 and strike Task 5's
snippet), and route the two shipped sites to their own lanes.

---

## 5. Condition 4 — the three new tests

**All three are genuinely falsifying.** I re-checked them against `584fd3264` myself (component checked
out from git, tests at HEAD, restored after):

| Test | file:line | Round-0 failure I measured |
|---|---|---|
| `never shows the previous company pricing while the new company read is in flight` | `__tests__/DocumentLineEditor.test.tsx:1165` | `expected <span></span> to be null` |
| `sends exactly one bulk pricing request when a line is added and its price is focused inside the window` | `:1217` | `expected "spy" to not be called at all, but actually been called 1 times` |
| `files every cached pricing answer under the signature of the body it was fetched with` | `:1266` | `expected [ '', 'prod-1::10.000' ] to deeply equal [ 'prod-1::10.000', 'prod-1::10.000' ]` |

These match §7.3 exactly — the handback's evidence is honest. The coherence test is the strongest of the
three: it asserts a *data-meaning* invariant (multiset of cached signatures = multiset of asked bodies),
not a call count, which is what this class of defect needs.

**Coverage vs what r1 asked:** company switch ✅ (with the M2 caveat — it passes only because the `t`
mock forces the memo to recompute), add-then-focus ✅ (narrowed, MINOR-7), cache coherence ✅.

**The mutable `companyMock` change is safe.** `__tests__/DocumentLineEditor.test.tsx:21-23` hoists
`{ currentCompanyId: 'company-1' }`; the store mock (`:118-136`) reads it in both the selector and
`getState()`; `beforeEach` (`:263-269`) resets it to `'company-1'` before **every** test in the file's
single `describe`. All 29 pre-existing tests therefore observe the byte-identical value they did before,
and the suite is green (33/33 in the file). The `vi.useFakeTimers()` in the two new timer tests is
covered by the pre-existing `afterEach(() => { vi.useRealTimers() })` at `:271-273`. **No masking, no
destabilisation.**

### MINOR-7 — "add-then-focus costs one request" is only true on an *empty* document; on a document that already has a line the lane costs **two** requests where the base cost one

The new test at `:1217` starts from `[]`. When the debounced array is empty, `enabled` is false, so the
early request cannot fire — that is what makes it 1. Start from a document that already has a line and
the guard disappears. **PROBE E (measured):**

```
HEAD  (272247e59): requests at focus: 1 | after settle: 2  ["prod-A::5.000", "prod-A::5.000|prod-B::10.000"]
base  (5e1e54f69): requests at focus: 1 | after settle: 1  ["prod-A::5.000|prod-B::10.000"]
```

Both HEAD requests are internally coherent (no M1 relapse), but the first is a wasted round-trip whose
answer omits the product the operator just focused, and on this path S-5's request count goes **up**.
This is the price of the "seeded debounce keeps the first read immediate" property and I am not asking
for a redesign. Fix directive: correct the claim in handback §1 (Behaviour delta) and §7.3 to
"add-then-focus on an empty document = 1 request; adding a line to a non-empty document costs one extra
settling request", and add the two-line variant to the test so the next author does not read the current
test as a general guarantee.

### MINOR-8 — handback §3's browser-check block still describes the *rejected* behaviour and contradicts §7.6

`docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md` §3, "Browser checks — NOT RUN": item 1 still says
*"Confirm the cost/margin hint stays on screen (no blink) while typing"* and item 4 still opens
*"with `placeholderData: keepPreviousData`, the hint shown during the 250 ms settle is the previous
signature's answer"*. Both are false as shipped, and §7.2/§7.6 say the opposite (the blink is the
deliberate trade). §1's test-file row likewise still describes only the one round-0 test and "29
real-timer tests". A promotion tester reading §3 first would report the deliberate blink as a defect —
or "fix" it by re-adding `placeholderData`. Fix directive: strike §3's browser block with a pointer to
§7.6 (the same in-place strike treatment §1 and §6.2/§6.3 already got), and refresh §1's test row.

---

## 6. Condition 5 — re-runs (all executed by me in this worktree, nothing taken on report)

| Check | Command | Result |
|---|---|---|
| typecheck | `pnpm typecheck` (whole project) | **0 errors**, no output |
| eslint, 2 touched files @ HEAD | `npx eslint <both>` | **0 errors, 15 warnings** (2 + 13) |
| eslint, same 2 files @ `5e1e54f69` | files checked out from base, linted, restored | **0 errors, 15 warnings** — *same rules, same counts*: `restrict-template-expressions`, `react-hooks/exhaustive-deps`, 10 × `no-base-to-string`, `unbound-method`, 2 × `require-await`. **No new errors, no new warnings.** Handback §7.4's `280:32` / `1111:6` re-measured and correct (r1 MINOR-4 resolved) |
| touched dir | `npx vitest run src/features/documents/components/__tests__/` (DEFAULT pool) | **10 files / 96 tests passed** — matches §7.3 |
| feature tree | `npx vitest run src/features/documents/` | **53 files / 452 tests passed** — matches §7.3 |
| `audit:keys` | `pnpm audit:keys` | `Gate C … without an approved tenant scope: 0`; `0 acknowledged, 0 new, 0 stale` |
| full lint | `pnpm --filter @autoerp/web lint` **inside this worktree** | **RED, inherited** — `lint:eslint` 0 errors / 6452 warnings; `audit:design-system` 810 violations, 796 acknowledged, **14 new + 11 stale, every one in `src/features/import/pages/ImportWizardPage.tsx`**. Identical numbers in the main `apps/erp` checkout, so it is `dev`-wide, not this branch. r1 MINOR-5 stands unchanged |

**Baseline honesty:** `apps/web/tools/audit-design-system-baseline.json` is **not** in the lane diff
(`git diff --name-only 5e1e54f69..HEAD` = 5 files, 2 code + 3 docs). No `--write-baseline` absorption.

**Mechanism audit:** `git diff 5e1e54f69..HEAD -- apps/web | grep '^+' | grep -iE 'eslint-disable|@ts-|skip\(|only\(|xit\('` → **nothing**. No alias table, no suppression comment, no renamed-but-equivalent literal. The improvement is real code, not detector evasion.

---

## 7. Still-standing r1 findings — regression check

| r1 finding | Status |
|---|---|
| **B1** `placeholderData` | Removed; verified in code + PROBE A/round-0 falsification. Residual **M2** (pre-existing, different mechanism) |
| **M1** key/body incoherence | Fixed; PROBE B + the lane's coherence test both confirm |
| **MINOR-1** render-written ref | Fixed — `grep -c pricingContextLinesRef` = 0 |
| **MINOR-2** Deviation D1 accepted | Unchanged; `priceInput()` re-query helper and its reason comment still in place |
| **MINOR-3** `LineItemsTable` cell remount | **Correctly deferred, not dropped** — handback §7.5 records it with `LineItemsTable.tsx:133-138`, the fix shape (`column.Cell({line,index})` or per-column memo) and the real production deps that move. **Add to that ticket:** it is a prerequisite for M2's real fix (deps-first would drop input focus on every pricing answer) |
| **MINOR-4** eslint line numbers | Fixed and re-measured by me |
| **MINOR-5** full lint RED | Unchanged, inherited, re-measured in this worktree; promotion still owes reconciliation |
| **MINOR-6** rule 22 second-company leg | Shipped (`:1165`), subject to M2 |

**Baseline claims re-verified:** `git diff --name-only 5e1e54f69..HEAD` touches **zero** `apps/api/` files;
`DocumentForm.tsx`, `CreateCreditNotePage.tsx`, `ProductController.php`, `LineItemsTable.tsx`,
`useDebouncedValue`, `tenantScopedKey` and every Inventory Counting file are untouched. **Rule 19:** no
`parseFloat` / `Number(` added anywhere in the diff; the signature, body and asserted `unit_price: '125'`
are all strings. **No `any`** (`signatureOfRequestBody` uses `typeof` narrowing, no `as`). **Key shape
unchanged:** `tenantScopedKey(['line-entry-pricing-context', partnerId ?? null, <signature>])` — same
root, same arity, tenant/company still the suffixes; only the line wrapping changed. **Rule 22
one-surface / catalogue-entity checks:** the lane introduces no noun and no write path — N/A.

---

## 8. Merge conditions for fix round 2 (all small; no further change to the query itself)

1. **M2** — reword the guarantee at `DocumentLineEditor.tsx:413-419` and the test name at
   `__tests__/DocumentLineEditor.test.tsx:1165` to what is proven, add a "known residual" note to
   handback §7.5 citing `DocumentLineEditor.tsx:1111` + `LineItemsTable.tsx:133-138` + PROBE D, and fold
   the dep fix into the MINOR-3 ticket (remount fix first, deps second).
2. **MINOR-7** — correct the "add-then-focus = 1 request" claim (handback §1 Behaviour delta, §7.3) and
   add the non-empty-document variant to the test.
3. **MINOR-8** — strike handback §3's browser-check block in favour of §7.6; refresh §1's test-file row.
4. **MINOR-9** — plan hygiene: repeat the banner rule at Task 3 (`:1095`) and strike Task 5's snippet
   (`:1657`); escalate `PaymentListPage.tsx:117` and `StockMovementsPage.tsx:190` to the orchestrator as
   their own lanes.
5. Re-run only: two-file eslint, `pnpm vitest run src/features/documents/components/__tests__/`,
   `pnpm typecheck` (unchanged if only comments/docs move, but confirm).

_Nothing was committed, nothing was merged, nothing was pushed. All probe specs were deleted and the
component was restored after every falsification checkout; `git status --short` is empty._
