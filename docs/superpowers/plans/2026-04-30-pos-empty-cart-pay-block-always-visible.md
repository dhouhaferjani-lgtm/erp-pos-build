# POS — Always-Visible Pay Block (Empty-Cart UX)

**Status:** Planned, not started
**Owner:** TBD (small enough for any session)
**Scope:** `apps/pos` only
**Estimated effort:** 1–2 hours including tests
**Coordination risk:** Touches `TransactionCart.tsx`. Refund-flow branch will also touch this file. **Merge this before refund, or plan a tight rebase.**

---

## Problem

When the cart is empty, the entire `PaymentSummary` block (Subtotal / Tax / Total bar / Cash Payment button / Advanced Payments button) is conditionally hidden. Cashiers see an empty cart panel with only the "Add products to cart" placeholder.

Industry standard (Square, Shopify POS, Lightspeed, Toast, Loyverse): the Pay block is **always present** at the bottom of the cart. Buttons are visually disabled (greyed out, `cursor-not-allowed`) when the cart is empty. Totals show €0.00. This:

- Telegraphs the workflow to a new cashier (you build a cart up here, then you pay down there).
- Avoids the layout flash where the bottom of the cart "snaps in" the moment the first item is added — disorienting on a touchscreen.
- Stops the empty-cart screen from being visually identical to a misconfigured / broken state.

## Current state (file:line)

`apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx:126`

```tsx
{/* Payment summary + actions */}
<div className="shrink-0 px-3 pb-2">
  {items.length > 0 && (
    <PaymentSummary
      subtotal={subtotal}
      taxAmount={taxAmount}
      discountAmount={discountAmount}
      total={total}
      hasDiscount={hasDiscount}
      onPayCash={onPayCash}
      onAdvancedPayments={onAdvancedPayments}
      onRemoveDiscount={onRemoveDiscount}
      paymentMethods={paymentMethods}
      disabled={checkoutDisabled}
    />
  )}
</div>
```

`PaymentSummary` already accepts and honors a `disabled` prop (`apps/pos/src/components/pos/PaymentSummary.tsx:16,29,82,92`) using Tailwind `disabled:cursor-not-allowed disabled:opacity-50`. So the disabled-button styling is already there — we just need to always render the block and feed it the right `disabled` flag.

## Change

1. Remove the `{items.length > 0 && (...)}` guard around `<PaymentSummary>` in `TransactionCart.tsx:126`.
2. Keep the wrapper `<div className="shrink-0 px-3 pb-2">` so layout stays identical.
3. Pass `disabled={checkoutDisabled || items.length === 0}` to `PaymentSummary`.
4. When cart is empty, totals come through as `0` from the cart store selectors — they already format correctly via `useCurrency().format()`. No additional code needed for that.
5. Optional polish: when `items.length === 0`, hide the "Discount removed / discount applied" row inside `PaymentSummary` (only relevant when there's a basket). The existing `hasDiscount` flag already gates this — verify no regression.

## Tests to add / update

**Unit tests** (`apps/pos/src/components/organisms/TransactionCart/__tests__/TransactionCart.test.tsx` — create if missing):

- Renders `PaymentSummary` even when `items=[]`.
- Cash button has `disabled` attribute when cart empty.
- Cash button is enabled when cart has at least one item AND `checkoutDisabled=false`.
- Cash button is disabled when `checkoutDisabled=true` regardless of cart contents.
- Totals display €0,00 when cart empty.

**Visual / manual** (run `pnpm tauri dev`):

- Open a fresh shift. Confirm the Pay block sits pinned at the bottom with €0,00 total and a greyed Cash button.
- Add an item — button activates, total updates without layout shift.
- Remove the only item — button greys out, total returns to €0,00, no layout jump.
- With multiple payment methods configured, confirm the "Advanced payments" button matches the same disabled state.

## Coordination with other sessions

- **Refund-flow branch** (`feat/refund-flow`) will modify `TransactionCart.tsx` to add return-mode UI. Conflict surface here is small — this plan removes one conditional and adds one prop. If refund hasn't merged yet when this PR ships, refund will rebase cleanly (~1 minute). If refund has merged first, apply this on top of refund's changes.
- **POS performance session** does not touch `TransactionCart.tsx` (focuses on `productStore` / `ProductGrid` / image cache). No conflict.

## Out of scope

- Redesign of the Pay block (colors, copy, button order). Pure behavior change.
- "No payment methods configured" empty-state messaging — separate concern, covered by the activation-hardening plan.
- Animations on enable/disable transition. Tailwind already animates the opacity change.

## Acceptance

- [ ] `PaymentSummary` is always rendered when `items` array is defined (regardless of length).
- [ ] Pay button is disabled (greyed, not clickable) when cart is empty OR when `checkoutDisabled=true`.
- [ ] Layout does not shift when first item is added or last item removed.
- [ ] Tests cover the four states: empty + ready, empty + checkoutDisabled, has-items + ready, has-items + checkoutDisabled.
- [ ] Manual verification on `pnpm tauri dev` matches the screenshots in the next test report.
