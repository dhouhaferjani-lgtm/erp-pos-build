# Opus adversarial rereview — pricing section

Model: `claude-opus-4-8`

Scope: the reconciliation for the pricing review's unchanged-blur MAJOR, currency-scale MINOR, and DOM-test NOTE.

## Required invariant verification

1. **Unchanged focus/blur cannot mutate or dirty `sale_price` — PASS.** Focus snapshots the derived value into an independent baseline. Blur reads the ref-backed draft and commits only when it differs. The HT `137.777` regression case is load-bearing because its rounded margin would reconstruct `137.780`; the test asserts both unchanged value and clean field state.
2. **Changed literal drafts still commit on blur — PASS.** Change updates the draft ref. The hook test proves `50.` remains literal while focused, leaves HT untouched before blur, and commits `150.000` on blur. TTC back-solving is also covered.
3. **Refs cannot alias — PASS.** Draft and baseline refs are initialized from separate object spreads. The draft change path cannot mutate the baseline.
4. **`sale_price` remains canonical HT — PASS.** Margin and TTC both back-solve into HT; the HT controller writes it directly; view TTC is derived from it.
5. **`moneyScale` is correct in both modes — PASS.** Edit receives the scale resolved by `useCurrency`; view resolves the same currency metadata with `getDecimals`.
6. **Cost permission gates remain intact — PASS.** Purchase price, WAC, margin, last purchase, and floor remain guarded. The public view product strips cost fields and non-holders receive `costPrices: null`.

## Findings

- **NOTE:** `useState(derivedValues)` initially shared the first derived-values reference. It was harmless because state updates spread and derived values are never mutated, but using `{ ...derivedValues }` is clearer and symmetric with the refs. Reconciled after rereview.
- **NOTE:** edit-mode `moneyScale` is carried on the shared adapter even though edit calculations already receive it inside the controller. This is benign shared-contract symmetry.
- **NOTE:** the requested DOM-level literal-draft assertion is present and verifies the trailing-zero string `50.00` at the rendered input boundary.

No BLOCKER, MAJOR, or MINOR findings remain.

## Verdict

**APPROVED.** Every original finding is resolved with load-bearing regression coverage. Only non-blocking notes remain.
