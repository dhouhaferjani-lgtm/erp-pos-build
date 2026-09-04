# Independent merge gate — request-hygiene Task 6 (debounce bulk pricing context, S-5)

- Reviewer: frontend-conventions (independent orchestrator check; NOT the lane's own reviewer)
- Date: 2026-09-04
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t6`, branch `lane/rh-t6-pricing-debounce`
- Base `5e1e54f69` (= `dev` at the time, ancestor of current `dev` `9c28b430a`) → HEAD `8aa378350`
- Code diff: 2 files (`DocumentLineEditor.tsx` +40, `__tests__/DocumentLineEditor.test.tsx` +364). Docs: plan +49, handback, 3 lane gate reports.
- Everything below was measured in this worktree by this reviewer. No lane-reported number was accepted.

## VERDICT: APPROVE-WITH-FIXES (code merges as-is; 2 non-blocking doc fixes owed before/with the docs commit; browser checks promotion-owed)

No BLOCKER. No MAJOR against the shipped code. The mechanism is real, the debounce is load-bearing, the anti-`placeholderData` test is genuinely falsifying, and the lane's own honesty caveats survive independent checking.

---

## 1. Blocking findings

**None.**

## 2. Non-blocking findings

**MAJOR-1 (docs, non-blocking for the code) — the plan's new `keepPreviousData` WARNING is forward-looking only; three tenant/company-scoped reads already ship the forbidden pattern and the plan does not say so.**
`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:813` and `:1078` end with "This step's snippet below predates that ruling", while both steps sit under **LANDED** banners (`:387`, `:898`) that claim to enumerate as-shipped deviations. Measured on `dev`:
- `apps/web/src/features/inventory/StockMovementsPage.tsx:190` — `placeholderData: keepPreviousData`, **no** `isPlaceholderData` guard anywhere in the file (grep on `dev`), and the page carries a row-level *Reverse* action.
- `apps/web/src/features/treasury/PaymentListPage.tsx:117` — same, no `isPlaceholderData`.
- `apps/web/src/features/pos/hooks/useDiscountPreview.ts:87` — third instance.
The lane *does* escalate all three honestly in `docs/handoff/HANDBACK-request-hygiene-T6-2026-09-04.md:437-440` + MINOR-14, which is why this is not blocking. Fix: add one sentence to each WARNING block — "as of 2026-09-04 the code shipped from this snippet is LIVE on dev with `keepPreviousData` and no `isPlaceholderData` gate (`StockMovementsPage.tsx:190`, `PaymentListPage.tsx:117`, plus `useDiscountPreview.ts:87`); registered as residual R-x, not fixed by T6" — so the plan of record cannot be read as "compliant".

**MAJOR-2 (residual, pre-existing, must be registered not fixed here) — the cross-company stale-verdict risk the B1 test names is NOT closed in production.**
`DocumentLineEditor.tsx:1120-1135` (`lineColumns` deps) omits `pricingContext`, and `LineItemsTable.tsx:133-138` renders each cell as a component type (`const Cell = column.Cell; <Cell line={line} index={index} />`). The *Use suggested* commit lives inside that memoised cell (`DocumentLineEditor.tsx:941`, `:956`). Falsifying production scenario: company A and company B share currency + locale (so `formatAmount`/`companyCurrency` do not move); operator focuses a price in company A, hint renders WAC `111`; switches company in `CompanySelector`; the editor re-renders in place (no unmount — `CompanyProvider.tsx:149` is `return <>{children}</>`, and there is no `key={currentCompanyId}` anywhere in `apps/web/src` — verified by grep), the query key moves and its data goes `undefined`, but every `lineColumns` dep is unchanged, so the memo is not recomputed and the **stale company-A cell closure keeps rendering — and can commit — company A's suggested price**. The lane discloses exactly this at `DocumentLineEditor.tsx:420-431` and in the test's own comment, and the passing test only proves the query-level guarantee because this suite's `t` mock is a fresh closure per render. Pre-existing at base (identical deps at `5e1e54f69`), *not* introduced by T6 (base carries no `placeholderData` either). Fix directive: file the `LineItemsTable` remount ticket the comment refers to and register this residual with the orchestrator; do **not** add `pricingContext` to the deps before the remount is fixed (it would drop focus mid-typing).

**MINOR-1 — citation drift, repeated three times.** `CompanyProvider.tsx:143` (plan `:813`, plan `:1078`, handback `:264`) — the bare `return <>{children}</>` is at **`apps/web/src/features/company/CompanyProvider.tsx:149`**; line 143 is inside the "no companies" branch. The claim is true, the line number is not. (`CompanySelector.tsx:39-47`, `companyStore.ts:186-198`, `LineItemEntryBar.tsx:89-96`, `LineItemsTable.tsx:133-138`, query-core `5.90.11` all verified correct.) Fix: re-cite `:149`.

**MINOR-2 — the branch inherits a RED `pnpm --filter @autoerp/web lint`.** `pnpm audit:design-system` exits 1 with **14 new / 11 stale** entries, *all* in `src/features/import/pages/ImportWizardPage.tsx` — a file byte-identical between `dev` and this lane (`git diff dev lane/… -- …ImportWizardPage.tsx` is empty). Pre-existing dev debt, zero contribution from T6, but nobody may claim "web lint green" on this branch.

## 3. What held up (item by item)

**1. Query shape.** `DocumentLineEditor.tsx:388` `const debouncedPricingLines = useDebouncedValue(pricingContextLines, 250)` — ONE debounced value feeds the key segment (`:389-394`), the `enabled` predicate (`:399`), and the request body (`:410`). `placeholderData` **absent** (`:403-432` carries a standing anti-regression comment instead). `tenantScopedKey(['line-entry-pricing-context', partnerId ?? null, pricingContextSignature])` — same root, same arity, tenant/company still the suffixes (`:404-408`); `pnpm audit:keys` → Gate C 0/0/0. `staleTime: 30000` untouched (`:432`). No `parseFloat`/`Number(...)` added anywhere in the diff; the body carries `decimalValue(...)` strings only (`:371-379`). No new user-facing literal, so no new `t()` obligation. `useDebouncedValue` seeds with the current value (`apps/web/src/lib/hooks.ts:9`), so a document mounted with lines still reads immediately on first focus — verified.
Debounce-starvation check (mine, not the lane's): the debounce restarts on every new `pricingContextLines` **identity**; both consumers hold lines in `useState` (`DocumentForm.tsx:189` + `:698`, `CreateCreditNotePage.tsx:82` + `:629`), so identity is stable across child-only re-renders and the timer always settles.

**2. Company switch mid-form — proven scope.** The B1 test is not decorative: with `placeholderData: keepPreviousData` re-added it goes RED (falsification 3 below, `expected <span></span> to be null`), i.e. it does guard the query-level carry-over and any future re-add. What it does **not** prove is stated in MAJOR-2, and the lane says so itself in the test body and in the code comment. Given base also had no `placeholderData`, T6 introduces no new exposure; the proven scope is *enough for this lane*, insufficient as a system guarantee, and correctly labelled as such.

**3. Tests — 5 added, redness re-measured independently.**
- Falsification A (debounce removed: `const debouncedPricingLines = pricingContextLines`): **2 failed / 32 passed** — the 250 ms keystroke test and the first-line-add test. The debounce line is load-bearing.
- Falsification B (round-0 shape: live body + `useDebouncedValue` on the *signature* only, feeding the key): **2 failed** — `sends exactly one bulk pricing request…` and, crucially, `files every cached pricing answer under the signature of the body it was fetched with` with `expected [ '', 'prod-1::10.000' ] to deeply equal [ 'prod-1::10.000', 'prod-1::10.000' ]`. That reproduces the M1 defect verbatim; the coherence test is a real guard, not a tautology.
- Falsification C (`placeholderData: keepPreviousData` restored): **1 failed** — the company-switch test.
- Round-0 component restored wholesale (`584fd3264`): **3 failed / 31 passed** — exactly the three the handback §7.3 claims. The remaining two are the plan's original keystroke test (red against the pre-implementation base, green against round-0 because round-0 already debounced the signature) and the explicitly-labelled behaviour pin (gate r3 MINOR-13). The lane's accounting is honest; nobody claimed 5/5 red against round-0.
- **D1 (re-query the price input before each interaction) is sound.** The cause is real and verified in code: `LineItemsTable.tsx:133-138` renders cells as component types, and this suite's `t` mock is a fresh closure per render, so the memo recomputes, React sees a new component type, and the cell subtree (MoneyInput included) remounts — a node captured before the previous render is detached. Every falsifying assertion the plan specified survives the re-query (249 ms `not.toHaveBeenCalled()`, 250 ms exact payload). Harness artifact, not a product defect — and it is the same mechanism as MAJOR-2, which the lane connects.
- Worktree restored and `git status --porcelain` empty after every falsification; no leftover vitest workers.

**4. Ruling on the "pricing hint is largely dead in production" claim.** Confirmed pre-existing at base: `lineColumns`'s dep array is byte-identical at `5e1e54f69` and HEAD (no `pricingContext`), and `LineItemsTable.tsx` is untouched by the diff. In production the hint only refreshes when something else moves a `lineColumns` dep (typically a line edit, via `DocumentForm`'s inline `onChange` → new `handleUpdateLine` identity); a fetch that resolves on a focus-only render never repaints. **T6 therefore changes request volume, not what the operator sees or can commit** — the only user-visible deltas are (a) the hint arrives up to 250 ms later, and (b) it blinks out during a settling request (no placeholder), both already true at base for the keystroke path.

**5. Plan edits.** Task 6 AS-SHIPPED banner (`:1674-1680`) matches the shipped code line for line; Step 3's rejected snippet is struck and preserved in a `<details>` block (`:1738-1785`) — correct handling, no silent rewrite of history. Task 5's snippet strike (`:1653-1665`) is accurate: T5 as merged has no `placeholderData` (`LineItemEntryBar.tsx:89-95` standing comment, verified on `dev`). Task 2/3 warnings: see MAJOR-1 — technically true, materially incomplete. No other misstatement of landed code found.

**6. Guardrails (all run by me, in this worktree).**
| Command | Result |
|---|---|
| `pnpm vitest run src/features/documents/components` | **21 files / 216 tests passed**, 4.48 s |
| `pnpm typecheck` (`tsc --noEmit`) | **exit 0**, no output |
| `pnpm audit:keys` | Gate C: 0 unscoped, **0 acknowledged / 0 new / 0 stale** |
| `pnpm audit:quantity` | 0 total, 0 new |
| `pnpm audit:design-system` | **exit 1 — 810 violations, 796 acknowledged, 14 new, 11 stale — all 14 in `ImportWizardPage.tsx`, identical to `dev` (pre-existing, MINOR-2)** |
| `npx eslint <2 changed files>` @ HEAD | **15 problems (0 errors, 15 warnings)** |
| `npx eslint <same 2 paths>` with `5e1e54f69` content restored in place, then `git checkout --` | **15 problems (0 errors, 15 warnings)** — identical count and rule set → **no new warnings**; `git status --porcelain` empty afterwards |

Falsification commands (each followed by `git checkout -- <path>`; `git status` clean after each):
- `perl -0pi -e 's/const debouncedPricingLines = useDebouncedValue\(pricingContextLines, 250\)/const debouncedPricingLines = pricingContextLines/'` → 2 failed / 32 passed.
- + key fed from a debounced signature (`laggedSignature`) → 2 failed, incl. `expected [ '', 'prod-1::10.000' ] to deeply equal [ 'prod-1::10.000', 'prod-1::10.000' ]`.
- `placeholderData: keepPreviousData` re-added → 1 failed (`expected <span></span> to be null`).
- `git show 584fd3264:…DocumentLineEditor.tsx > …` → 3 failed / 31 passed.

**Merge-tree.** From `/Users/houssamr/Projects/syneriva/apps/erp`: `git merge-tree --write-tree dev lane/rh-t6-pricing-debounce` → exit 0, single tree oid `6acd7e54972a28c82c4bcc2f9017d0a5c337be0a`, **no conflicts** — including `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` (no other lane has amended that file since `5e1e54f69`).

**7. Browser checks are PROMOTION-OWED, not merge-owed.** None were run here (no stack). The owed list is handback §7.6: purchase order + credit note pricing, a company switch mid-form, and add-product-then-click-price. Given MAJOR-2, the company-switch probe must explicitly check that the cost/margin hint and *Use suggested* do not survive the switch between two companies **with the same currency and locale** — the configuration in which the memo cannot save you.

## 4. Cross-cutting checks
- **Second-of-everything:** no catalogue entity, no schema, no unique key touched. N/A.
- **One surface per concept:** no new noun. `apps/web/src/components/documents/DocumentLineEditor.tsx` is a 2-line back-compat re-export of the `features/` component (pre-existing), not a second surface.
- **Benchmark-first / data-meaning:** the added tests assert request counts, exact payload strings and cache-key↔body coherence — behaviour, not status codes. Good.
- **Owner UI rulings:** no new colour, badge, band, dead control, brand string, gate or route. N/A.
