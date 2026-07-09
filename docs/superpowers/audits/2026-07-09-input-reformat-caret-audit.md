# Input "reformat-on-keystroke + caret-reset" bug — full inventory (2026-07-09)

**Trigger:** on the product edit form, typing in Margin % / Sale price (excl/incl tax): the first digit is accepted, then the value reformats mid-edit (`3`→`3.00`) and the caret jumps to the end ("kicked out after one character").
**Method:** 1 diagnostic pass (root cause) + 3 classification auditors (Sonnet ×2, Haiku ×1) over all **51** files that use `MoneyInput`/`QuantityInput`. Read-only, code-verified `file:line`.

## Root cause (confirmed)
A controlled numeric input whose displayed `value` is a **derived, rounded read-back** of shared form state — recomputed on **every keystroke** via bc-helpers that always `.toFixed(scale)` (`lib/decimal.ts:50,65,80,95` → `components/pricing/pricingMath.ts`), and committed via `setValue`/`setState` on `onChange` (not `onBlur`). No local "draft" state holds the literal keystrokes, so the field only ever shows `f(g(typed))`. Setting `.value` on a `type="number"` input (which cannot preserve the caret) then resets the caret to the end. `MoneyInput`/`QuantityInput` themselves are innocent — they pass the raw keystroke through; the disease is always in the caller's wiring. Amplifier: `ProductForm` uses many top-level `watch()` calls → whole-form re-render per keystroke (not itself a caret-reset source per the audit, but a perf smell).

## The fix already exists in the repo — canonicalize + adopt it
`RecipeLineEditor.tsx:8-35`, `VariantEditor.tsx:11-61`, `ModifierGroupFormPage.tsx:15-65` each define a local **`BlurMoneyInput`/`BlurQuantityInput`**: local `draft` state, render the draft while focused, call the mutation `onCommit` only on **blur**. That is exactly the correct pattern — it's just duplicated 3× and not adopted by the 3 buggy files. **Root fix = extract one shared `DraftMoneyInput`/`DraftQuantityInput` (or a `useDraftValue` hook) into `components/atoms`, replace the 3 local copies, and adopt it in the buggy fields.**

## The inventory (7 buggy fields / 3 files; 48 files FINE)

| file:line | field | kind | cause | fix |
|---|---|---|---|---|
| `features/inventory/ProductForm.tsx:943` (value) / `:766-771` (onChange) | **Margin %** | derived-recompute | `value={marginFromCost(cost, priceHtValue)}` (`.toFixed(2)`); `onChange`→`handleMarginChange` writes `sale_price` every keystroke | draft state; commit derived `sale_price` on blur; the edited field shows the literal draft while focused |
| `features/inventory/ProductForm.tsx:980` / `:773-775` | **Sale price (incl tax / TTC)** | derived-recompute | `value={priceTtcFromHt(priceHtValue, taxRate, scale)}`; `type="number"` MoneyInput (caret can't be preserved); `onChange`→`handlePriceTtcChange` writes `sale_price` every keystroke | same — draft + blur-commit; only the NON-edited sibling fields re-derive live |
| `features/inventory/ProductForm.tsx:958` (Sale price excl/HT) | (sibling) | — | not value-corrupted (pass-through) but shares the re-render cascade; adopt the same draft component for consistency | |
| `features/settings/components/InventorySettings.tsx:292` | reservation-expiry `sales_order_expiry_days` | self-fallback | `type="number"`, `onChange` = `parseInt(v) || 30` → clearing → `NaN||30` snaps to 30 mid-edit; also blocks `0` (`0||30`) | draft string state; parse/clamp on blur; allow empty-while-typing and `0` |
| `features/settings/components/InventorySettings.tsx:307` | `ecommerce_cart_expiry_minutes` (`||30`) | self-fallback | same | same |
| `features/settings/components/InventorySettings.tsx:322` | `marketplace_order_expiry_hours` (`||24`) | self-fallback | same | same |
| `features/settings/components/InventorySettings.tsx:337` | `customer_return_expiry_days` (`||14`) | self-fallback | same | same |
| `features/documents/components/DocumentLineEditor.tsx:601` / `:605-611` | line unit-price **in `price_entry_mode:'total'`** (PurchaseBonus PO lines) | derived-recompute | `value={calculateNetExtendedAmount(line)}`; typing a total re-derives `unit_price`/`line_total` via `bcmul/bcdiv/bcsub` (`.toFixed(3)`) and feeds the rounded result back into the same input every keystroke | draft for the total input; commit the derivation on blur |

**FINE (48 files)** — already use either the `Blur*Input` draft pattern or a plain literal-passthrough (`value={state}` + raw `onChange`), incl. all POS/loyalty/promotions/coupons/vouchers/treasury/finance/expense/income forms and most catalog/purchases forms.

## Recommended root-level fix (Track C in the Codex handover)
1. **Extract `DraftMoneyInput` + `DraftQuantityInput`** (or `useDraftValue(commit)`) into `components/atoms`, matching the existing `Blur*Input` semantics (draft while focused, commit+normalize on blur, no reformat on keystroke). Replace the 3 duplicated local copies.
2. **ProductForm pricing strip:** adopt it for Margin %, Price HT, Price TTC. While a field is focused, show the literal draft; on blur, run the derivation once and commit `sale_price`; the *other* (non-focused) fields keep updating live. This breaks the feedback loop.
3. **InventorySettings:** draft string state + parse/clamp on blur for the 4 integer fields (allow empty + `0`).
4. **DocumentLineEditor total-mode:** draft the total input; commit the unit-price/line-total derivation on blur.
5. **(Broader, optional)** money inputs use `type="number"`, which can never preserve a caret when the value is set programmatically — consider `type="text" inputMode="decimal"` on `MoneyInput`/`QuantityInput` so the caret survives any legitimate value update. This is a bigger, cross-cutting change — do it as its own reviewed step.
6. Note overlap with the precision audit: `PriceInputWithMargin.tsx:85-89` uses `parseFloat` (precision-contract violation, tracked separately) — fix together while touching pricing inputs.
