# FE merge gate — `fix/l5-discount-amount-toggle`, commit `07a60fa05` (W-3 Option A, FE half)

- **Scope gated:** FE commit `07a60fa05` only. Diff base `695f6814d`. Backend `47b4114e1` gated separately.
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l5-discount`
- **Files:** `apps/web/src/features/documents/components/DocumentLineEditor.tsx`,
  `.../components/__tests__/DocumentLineEditor.test.tsx`, `apps/web/src/locales/{en,fr}/sales.json`,
  `apps/web/e2e/money-campaign/documents-discounts.spec.ts`
- **Verdict: REJECT** (1 CRITICAL, 3 IMPORTANT, 6 minor). Re-gate after C-1 + I-1.

---

## Guardrails re-run (not taken on report)

| Gate | Command | Result |
|---|---|---|
| typecheck | `npx tsc --noEmit -p tsconfig.json` | clean, no output |
| eslint (repo) | `npx eslint .` | **0 errors**, 6518 warnings (pre-existing warn-level baseline) |
| eslint (touched) | `npx eslint <3 touched files>` | 0 errors, 17 warnings — all pre-existing categories |
| tanstack keys | `node tools/audit-tanstack-keys.mjs` | Gate C: 0 unscoped, 0 new, 0 stale |
| design system | `node tools/audit-design-system.mjs` | 743 acknowledged, **0 new**, 0 stale |
| quantity display | `node tools/audit-quantity-display.mjs` | 0 total, 0 new |
| eslint-rules RuleTester | 3 rule test files | all pass (5/5, 6/3, 6/3) |
| vitest | `npx vitest run src/features/documents` | **38 files / 287 tests passed** |

`apps/web/tools/audit-design-system-baseline.json` is **NOT** in the diff (`git show --stat 07a60fa05` = 5 files).
No `--write-baseline`, no alias/suppression indirection found in the diff. Baseline honesty: **clean**.

---

## CRITICAL

### C-1 — Toggling the mode does not clear the abandoned field: the visible discount input contradicts the discount actually applied and saved

`DocumentLineEditor.tsx:812-824` — the toggle only writes `discountModeOverrides`; it never touches
`discount_percent` / `discount_amount`. `DocumentLineEditor.tsx:801` then renders
`decimalValue(line.discount_amount)`, which returns the literal string `'0'` for a null amount
(`DocumentLineEditor.tsx:43-46`).

**Empirically reproduced** (throw-away probe file, rendered with the suite's own mocks, deleted afterwards —
worktree verified clean):

```
[A] line starts { discount_percent: '10', discount_amount: null }, click "Amt"
    amount input displays: "0"
    line state after toggle: {"p":"10","a":null,"t":90}
    row text: ... Discount [0] Tax EUR 90.00 | Subtotal EUR 90.00 Total EUR 90.00

[B] line starts { discount_percent: null, discount_amount: '25.000' }, click "%"
    percent input displays: ""
    line state after toggle: {"p":null,"a":"25.000","t":75}
```

**Failure scenario (money error on a fiscal document, one click, zero typing):** an operator opens an
invoice line carrying 10% off, clicks `Amt` to switch to a flat discount, sees a discount cell reading
`0`, changes their mind and saves. `buildLinePayload` (`DocumentForm.tsx:184-186`) sends
`discount_percent: "10"`, `discount_amount: null`; the backend's percent-wins precedence applies the 10%.
The invoice is issued at 90.00 on a 100.00 line while the discount cell read `0`. The mirror case (B)
issues a 25.000 flat discount while the percent cell reads empty. The only counter-signal is the Total
column. This state is **newly reachable** — at `695f6814d` the single percent cell always displayed the
effective discount (`git show 695f6814d:...DocumentLineEditor.tsx`, discount column).

The commit message asserts "Writing either field nulls the other, matching the backend's either/or
precedence". True for typing (`:789-792`, `:803-806`); **false for toggling**, which is the new
interaction this commit adds.

*(For the record on the parent's explicit question: the FE cannot emit BOTH fields non-null — every
write path nulls its counterpart. The defect is mode/value desync, not dual-population.)*

**Fix directive:** make the toggle a write, not just a view switch — either clear the abandoned field in
the same click (`handleUpdateLine(line.id, mode === 'percent' ? { discount_percent: null } : { discount_amount: null })`)
or convert the value into the new unit (`gross × pct/100` and back, via `bcmul`/`bcdiv`, never `parseFloat`).
Add a test asserting the **line payload** (not just which input is rendered) immediately after a toggle in
both directions — none of the 4 new tests assert post-toggle payload state.

---

## IMPORTANT

### I-1 — `MoneyInput` renders `"0"` for an unset discount, where percent mode renders `""`

`DocumentLineEditor.tsx:801` `value={decimalValue(line.discount_amount)}` → `'0'` when the amount is
null/undefined/empty (`:43-46`), vs `:787` `value={line.discount_percent ?? ''}`. Every line with no
discount displays a hard `0` in amount mode, which is what makes C-1 read as a deliberate "no discount"
rather than an empty field. Independently: it also means the user must clear the `0` before typing.
**Fix:** render `line.discount_amount ?? ''` (MoneyInput accepts `''` — it passes the raw string through,
`MoneyInput.tsx:82-91`).

### I-2 — Clearing the amount input emits `''`, not `null`, asymmetrically with the percent branch

`DocumentLineEditor.tsx:802-806` passes `value` straight through, so clearing the field sets
`discount_amount: ''` (probe C: `{"p":null,"a":"","t":"100.000"}`), while the percent branch explicitly
maps `'' → null` (`:790`). `buildLinePayload` (`DocumentForm.tsx:185`) uses `?? null`, which does not
catch `''`, so the wire payload carries `"discount_amount": ""`. This does not 422 today only because
Laravel's default global `ConvertEmptyStringsToNull`
(`vendor/laravel/framework/.../Configuration/Middleware.php:462`) rewrites it server-side — an accident,
not a contract. It also introduces a third value (`''`) into a field typed `string | null`
(`DocumentLineEditor.tsx:122`), which every downstream `JSON.stringify` comparison
(`computeLinesDirty`, `DocumentForm.tsx:141-143`) treats as different from `null`.
**Fix:** `discount_amount: value === '' ? null : value`, mirroring `:790`.

### I-3 — No client-side ceiling on the amount input: the new backend 422 is only discovered at save, behind a plausible-looking 0.00 total

`DocumentLineEditor.tsx:798-810` sets `min="0"` but no `max`. Probe D: typing `500` on a 100.000-gross,
20%-VAT line yields `line_total: "0.000"` and a totals footer of `EUR 0.00` — the FE floors silently
(`calculateDiscountedSubtotal`, `:59`) and shows a self-consistent zero document, while the payload still
carries `discount_amount: "500"` and the backend's new `LineDiscountAmountWithinGross`
(`CreateDocumentRequest.php:133-142`) rejects it. The only feedback is a generic
`toast.error(message)` (`DocumentForm.tsx:457-463`); with autosave armed this fires repeatedly. The
percent branch has the analogous guard inline (`max="100"`, `:786`).
**Fix:** pass `max={calculateNetExtendedAmount(line)}`-equivalent gross (`bcmul(qty, unit_price)`) to the
MoneyInput so the ceiling is expressed where the percent ceiling already is.

---

## minor

### m-1 — `className="px-2 py-1"` on the toggle is dead CSS
`DocumentLineEditor.tsx:821`. `Button` composes with raw template-string concatenation and no
`tailwind-merge` (`Button/Button.tsx:50-60`), so `sizeStyles.xs = 'px-3 py-1.5 text-xs'`
(`Button.tsx:29`) and `px-2 py-1` both land in the class attribute at equal specificity. Verified with the
project's own Tailwind CLI: `.px-2` is emitted at line 2537 and `.px-3` at 2543; `.py-1` at 2570 and
`.py-1.5` at 2573 — the later rule wins, so **`px-3 py-1.5` applies and the override is inert**. The button
renders ~16px wider than intended inside a `w-40` column that also holds a `w-24` MoneyInput plus `gap-1`.
**Fix:** drop the dead class, or size the button via the atom's API.

### m-2 — "mirrors the existing price-entry-mode toggle's pattern" is inaccurate; the two adjacent toggles will not match
The existing price-entry-mode toggle is a **raw `<button>`** with
`${colors.neutral[100]} px-2 py-1 text-xs font-medium ${textColors.secondary} ${colors.hover.gray50}`
(`DocumentLineEditor.tsx:687-700`) — no border, gray-100 fill. The new one is the `Button` atom,
`variant="secondary"` = white surface + border + `rounded-[var(--radius-button)]`
(`Button.tsx:16-17`). Using the atom is the correct call under the design system; the **claim** is wrong,
and with m-1 the two toggles sit two columns apart in the same row at different sizes and treatments.
Either accept the visual delta knowingly or migrate the price-entry toggle in the same pass.

### m-3 — `headerClassName` widened from `w-28` to `w-40` for readonly views too
`DocumentLineEditor.tsx:764`. In readonly mode the cell renders only a `<span>` (`:769-777`); the column
is now 48px wider on every read-only document view, squeezing the neighbouring columns. **Fix:** widen
conditionally (`readonly ? 'w-28 text-end' : 'w-40 text-end'`).

### m-4 — The deps fix is real and correct, but nothing gates a revert of it
`getDiscountMode` added at `:886`; it is `useCallback`-wrapped on `[discountModeOverrides]` (`:524-532`),
so it is referentially stable except when a toggle fires — inclusion does **not** make the memo recompute
every render. Confirmed against base: `git show 695f6814d:...` deps array had no `getDiscountMode`.
However the suite's `react-i18next` mock returns a **fresh `t` closure on every render**
(`DocumentLineEditor.test.tsx:27-100`), so the memo recomputes unconditionally under test and **no test
would go red if `getDiscountMode` were removed from the deps array again**; `react-hooks/exhaustive-deps`
is `warn` (`eslint.config.js:98`), so lint would not gate it either. **Fix:** memoize `t` in the mock
(`const t = useMemo(...)` or a module-level constant) so the deps array is actually exercised.

### m-5 — The two pre-existing missing deps in the same memo are confirmed pre-existing, and remain
`react-hooks/exhaustive-deps` reports `'openPricingLineId' and 'pricingContext?.items'` missing at
`:881`. Both are referenced at base (`git show 695f6814d:...DocumentLineEditor.tsx:615,624`) and absent
from base's deps array — the implementer's "pre-existing" claim is **verified true**, this commit does not
introduce them. Noting only that the commit's own rationale ("real staleness bug") applies identically to
them and they were left in place; acceptable as scope discipline, but they are live staleness bugs in the
pricing popover.

### m-6 — `ar/sales.json` did not get the new keys, though the sibling toggle has them there
`src/locales/ar/sales.json` `lineItems` contains `priceEntryMode` but neither `discountAmountPerLine` nor
`discountMode`. The `ar` `sales` namespace is a **shallow** spread (`{...enSales, ...arSales}` with only
`documents`/`partners` deep-merged, `lib/i18n.ts:280-313`), so the keys resolve via `fallbackLng: 'en'`
(`lib/i18n.ts:427`) — Arabic users see English `Amt` / `Discount amount (per line)`, not a raw key. The
spec only required en+fr, so this is flagged for parity with `priceEntryMode`, not as a spec violation.

---

## Verified-clean claims (no finding)

- **Money precision (spec point 4):** no `parseFloat` / `Number(` anywhere in the diff; `MoneyInput` passes
  the raw string through (`MoneyInput.tsx:82-91`) and derives `step` from the currency
  (`MoneyInput.tsx:78`) — no hardcoded step, `no-hardcoded-step` RuleTester green. `handleUpdateLine`
  recomputes `line_total` through `bcmul`/`bcdiv`/`bcsub`/`bcadd` only (`:441-475`). Probe B confirms the
  string `'25.000'` survives the round trip verbatim.
- **Atoms / design system (spec point 1):** `Button` atom (not a raw `<button>`), `Input` atom, canonical
  `MoneyInput`, `tokens.input.base` — no hardcoded Tailwind colors on any touched line; design-system audit
  reports 0 new violations with the baseline untouched.
- **i18n (spec point 5):** all three keys present in en (`en/sales.json:305-309`) and fr
  (`fr/sales.json:305-309`); `Montant de remise (par ligne)` / `Mt` is genuine French, not copied English.
  All new user-facing strings go through `t()`.
- **TanStack (spec point 8):** the only query key in the file is untouched and already
  `tenantScopedKey([...])` (`:288`); Gate C reports 0.
- **RHF/zod (spec point 7):** line items are **not** part of the RHF/zod schema — `DocumentForm.tsx:233`
  owns them in `useState` and passes `setLines` as `onChange` (`:730`). `discount_amount` is therefore no
  less validated than the pre-existing `discount_percent`; nothing regressed, but note that I-3's ceiling
  cannot be delegated to zod here.
- **Tests are non-vacuous for what they cover:** all 4 new cases
  (`DocumentLineEditor.test.tsx:495,514,546,564`) assert rendered roles/labels/values and emitted payload,
  never CSS classes; test 1 and 4 would fail outright if the toggle wiring were reverted. Their gap is
  coverage (C-1's post-toggle payload) and the masked deps array (m-4), not vacuity.
- **e2e MTP-DSC-04** (`e2e/money-campaign/documents-discounts.spec.ts:163-210`): tripwire comment retained
  and rewritten rather than deleted, asserts `422` and `lines.0.discount_amount`; the now-unused
  `addMoney` import was correctly removed. **Merge-order constraint:** this assertion is green only with
  backend `47b4114e1` — `07a60fa05` cannot ship ahead of it.

---

## Verdict

**REJECT.** Fix C-1 (toggle must reconcile the value, plus a payload-level test in both directions) and
I-1; I-2 and I-3 should land in the same pass. m-1..m-6 may be deferred with a ticket, but m-1 is a
one-line deletion and m-4 is the only thing standing between the deps fix and a silent future regression.

---

# Fix-round re-verify — commit `1f1d85a34` (2026-08-07)

Scope of this pass: **only** the findings raised above. Backend `c3df4fafc` re-gated separately.
Fix commit touches 6 files (editor, its test, `ar`/`en`/`fr` `sales.json`, and this record — the
implementer committed the previously-untracked gate record; noted, not a finding).

## Gates re-run

| Gate | Result |
|---|---|
| `npx vitest run DocumentLineEditor.test.tsx DocumentLineEditor.quantityStep.test.tsx` | **2 files / 32 tests passed** (29 + 3; was 27 + 3 — the 2 new C-1 cases) |
| `npx tsc --noEmit -p tsconfig.json` | clean (`textColors.tertiary` exists, `designTokens.ts:89`) |
| `node tools/audit-design-system.mjs` | 743 acknowledged, **0 new**, 0 stale — baseline file again **not** in the diff |
| `npx eslint` (both touched files) | **0 errors**, 17 warnings (same pre-existing categories; `getDiscountMode` no longer in the missing-deps list) |
| `grep '^+' … -E 'parseFloat\|Number(\|toFixed\|step='` on the diff | **no matches** — no float math, no hardcoded step added |

## Finding-by-finding (empirically re-probed, throw-away file, deleted; worktree clean)

**C-1 — RESOLVED.** `DocumentLineEditor.tsx:823-841`: the toggle's `onClick` now also calls
`handleUpdateLine(line.id, nextMode === 'amount' ? { discount_percent: null } : { discount_amount: null })`.
Original probe [A] re-run against the fix:

```
[A] percent '10' -> click "Amt"
    amount input value: ""   (was "0")
    payload:            {"p":null,"a":null,"t":"100.000"}   (was {"p":"10","a":null,"t":90})
    row text:           ... Max EUR 100.00 ... Total EUR 100.00
```

The stale `discount_percent:"10"` is gone, `line_total` recomputes to the full gross in the same click, and
the rendered field equals what saves. **Double-toggle round trip** (`10 → Amt → %`):
`percent input value: ""`, `payload {"p":null,"a":null,"t":"100.000"}` — view and payload agree; the
original `10` is destroyed. That is the accepted cost of the "clear the abandoned field" option from the
fix directive: an accidental double-toggle discards the discount, but it does so **visibly** (empty field
*and* the total snapping back to EUR 100.00), so there is no silent divergence left. Not a finding.

The 2 new tests (`DocumentLineEditor.test.tsx:509,551` region) assert the **emitted payload**
(`discount_percent: null`, `discount_amount: null`, `line_total: '100.000'`) plus the emptied sibling
input, in both directions. Non-vacuous by construction: pre-fix the toggle never invoked `onChange` at
all, so `expect(onChange).toHaveBeenLastCalledWith(...)` could only fail — the implementer's red-proof
claim is consistent with the code it replaced.

**I-1 — RESOLVED.** `:800-806` `value={line.discount_amount ?? ''}`. Probe confirms the amount input
renders `""` for an unset amount, symmetric with the percent branch.

**I-2 — RESOLVED.** `:807-816` `discount_amount: value === '' ? null : value`. Probe: clearing the field
now emits `{"p":null,"a":null,"t":"100.000"}` (was `a:""`). No further reliance on Laravel's
`ConvertEmptyStringsToNull`.

**I-3 — RESOLVED to parity with the percent branch.** `:779-783` computes
`lineGross = bcmul(decimalValue(line.quantity), decimalValue(line.unit_price))` — string math, no
`parseFloat`/`Number` — fed to `max={lineGross}` and to a `Max {{amount}}` hint rendered under the field in
amount mode (`:846-850`, `formatAmount(lineGross)`). Probe: `max="100.000"` on a 1×100 line and
`max="37.500"` on a 3×12.500 line; hint renders `Max EUR 100.00`. Typing `500` still reaches state
(`a:"500"`, total floored `0.000`) but the input now reports `validity.rangeOverflow === true` /
`checkValidity() === false`. Worth stating plainly: `max` on a number input is **advisory** — it does not
block typing and this form does not gate submit on native validity, so the backend 422 remains the hard
boundary. That is exactly the same semantics the pre-existing `max="100"` percent ceiling has, which is
what the finding asked for. Accepted.

**m-1 — RESOLVED.** Probe dump of the rendered toggle class list ends
`… px-3 py-1.5 text-xs` with no `px-2`/`py-1` — the inert override is gone.

**m-3 — RESOLVED.** Probe: readonly `<th>` carries `w-28 text-end`, editable carries `w-40 text-end`.

**m-6 — RESOLVED.** `ar/sales.json` gains `discountAmountPerLine: "مبلغ الخصم (لكل بند)"`,
`discountMaxHint: "الحد الأقصى {{amount}}"`, `discountMode.amount: "مبلغ"` — genuine Arabic, correctly
placed inside `lineItems` (which the `ar` `sales` namespace replaces wholesale, `lib/i18n.ts:280-282`).
`en`/`fr` both gain `discountMaxHint`; `Max` is a legitimate French abbreviation, not an untranslated copy.

**m-2 — ACCEPTED as documentation-only.** The inaccurate "mirrors the existing price-entry-mode toggle"
claim is corrected in `1f1d85a34`'s body; migrating the pre-existing raw-`<button>` toggle
(`DocumentLineEditor.tsx:687-700`) stays out of scope. The visual delta between the two adjacent toggles
persists by decision, not by oversight.

**m-4 — ACCEPTED, DEFERRED WITH TICKET.** The reasoning is present inline at the mock definition site
(`DocumentLineEditor.test.tsx:27-39`), not just in the commit body, and it names the two tests that break
("lazily fetches bulk pricing context…", "shows server-driven blocked margin policy…"). The entanglement
argument is sound and self-consistent: a stable `t` makes every missing dep in `lineColumns` load-bearing,
and this gate's own m-5 explicitly ruled the pre-existing `openPricingLineId`/`pricingContext?.items`
omissions out of scope. m-4 cannot be closed without m-5. Both belong on one ticket.

## Open (non-blocking)

- **m-4 + m-5 ticket:** stabilize the suite's `t` mock **and** add `openPricingLineId` /
  `pricingContext?.items` to the `lineColumns` deps array in the same pass. Until then the
  `getDiscountMode` deps fix has no regression gate, and the pricing popover carries a live staleness bug.
- **Merge-order constraint (unchanged):** `e2e/money-campaign/documents-discounts.spec.ts:163-210`
  asserts the 422 contract, so the FE commits cannot ship ahead of backend `47b4114e1` + `c3df4fafc`.

## Fix-round verdict

**CLEAR TO MERGE** (FE half: `07a60fa05` + `1f1d85a34`). The CRITICAL and all three IMPORTANTs are fixed
and empirically re-verified; m-1/m-3/m-6 fixed; m-2 accepted as documentation-only; m-4 deferred with
m-5 on a ticket. No new findings introduced by the fix round.
