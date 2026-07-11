# Opus adversarial review — pricing section

Model: `claude-opus-4-8`

Scope: the uncommitted pricing milestone, reviewed against the governing design specification and its pricing invariants.

## Invariant verification (passed)

- **One controller hook owns every margin/HT/TTC write** — verified: all pricing writes live in `useProductPricingEditAdapter.ts`; the old handlers are deleted from `ProductForm.tsx`; the hero strip renderers are gone.
- **`sale_price` canonical HT** — view derives TTC from HT and edit back-solves HT from margin/TTC. No TTC-authoritative path remains.
- **Cost/WAC/margin/floor gated by `pricing.view_cost_prices`** — view wraps WAC, last-purchase, margin, and floor in the permission gate; edit gates purchase price, WAC, and margin. The public product type omits cost fields. Holder/non-holder behavior is tested in both modes.
- **Pricing renders once** — pricing was removed from `PRODUCT_DETAIL_SECTIONS` and its renderer registry; one explicit `ProductPricingSection` renders per mode.
- **Hero identity/enrichment/image only** — cost, margin, HT, TTC, and stock were removed from both hero variants. Opening quantity moved into inventory.
- **Opening-cost coupling** — `commitCost` updates `opening_unit_cost` only when opening entry is eligible and quantity is non-empty/non-zero, matching prior semantics.

## MAJOR — unchanged blur silently mutates canonical HT and dirties the form

Location: `apps/web/src/features/products/sections/useProductPricingEditAdapter.ts`, original lines 70–74.

The new blur handler committed every time, with no change guard:

```ts
onBlur: () => {
  const value = drafts[field]
  setFocusedField(null)
  commitField(field, value)
}
```

The replaced draft inputs committed only when the draft differed from its initial value. Without that guard, focusing and blurring margin or TTC without editing could perform a lossy derived-value round trip. For example, cost `100.000` and HT `137.777` can derive a two-decimal margin and then reconstruct a different HT. Even an exact round trip called `setValue(..., { shouldDirty: true })`, spuriously dirtying the form.

Required fix: snapshot the derived value on focus and commit on blur only when the draft differs from that focus-time baseline. Add a regression test asserting unchanged focus/blur neither mutates nor dirties `sale_price`.

## MINOR — view TTC hardcodes scale 3

Location: `apps/web/src/features/products/sections/ProductPricingSection.tsx`, original line 59.

View mode called `priceTtcFromHt(..., 3)` while edit mode used the company/currency money scale. Thread the resolved scale through the view adapter so the canonical pricing surface is consistent between modes.

## NOTE — literal-draft preservation lacks a DOM-level assertion

The hook test proves it returns the literal focused draft, but a rendered-section test should also prove the controlled numeric input retains a trailing-zero draft while focused.

## Verdict

**CHANGES REQUESTED.** The unchanged-blur MAJOR must be fixed before the milestone proceeds. The scale issue and DOM assertion should also be addressed while the pricing surface is being established.

## Reconciliation

- Added focus-time baseline and ref-backed draft tracking; unchanged blur now performs no write.
- Added a regression test using an HT value that would drift through the rounded margin, and asserted the field remains clean.
- Added `moneyScale` to the shared adapter and use it for view TTC derivation.
- Added a rendered-section literal trailing-zero draft assertion.
