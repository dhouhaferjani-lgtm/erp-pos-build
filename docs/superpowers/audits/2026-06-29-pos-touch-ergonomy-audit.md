# IziPOS POS — Touch-Screen Ergonomy & Button-Clarity Audit

**Date:** 2026-06-29
**Scope:** POS shell + cart + payment + reports + settings + returns surfaces (read-only audit, no files changed).
**Standard applied:** Design-system spec §6 — **48×48px touch floor for ANY control** (close/X, edit, increment, link-actions included). A control should read as a button: filled or clearly bordered, ≥48px, sufficient contrast. Spacing floor 12–16px between controls; destructive and confirm controls must be visibly separated.
**Excluded (owned by parapharmacy session):** ProductCard, ProductGrid, ProductDetailDrawer, ProductVariantStockView, CrossLocationStockSection, ToastSmartPrompts.

Finding categories:
1. **Sub-floor touch target** — interactive element < 48px.
2. **Ambiguous / low-contrast** — should read as a clear button but renders as a faint icon or text link.
3. **Spacing / ergonomy** — controls packed under the 12–16px gap, or destructive+confirm with no separation.

---

## Root cause — the atom that seeds most sub-floor findings

`src/components/ui/IconButton.tsx:24-28` — size map: `sm = h-9 (36px)`, **`md = h-11 (44px)`**, `lg = h-14 (56px)`. The default (`md`) is **44px — 4px under the §6 floor**, and it is the size used for nearly every icon control in the Header and cart toolbar. `Button.tsx:44-48` has the same issue: `sm = min-h-9 (36px)`, `md = min-h-11 (44px)`. **Recommendation:** raise `md` to `h-12 / min-h-12 (48px)` (and add a dedicated `lg`-touch where the CTA needs it), or stop using `md` for any touch-reachable control. Fixing the atom auto-resolves Header + QuickActions + cart-toolbar findings below.

The token map `designTokens.ts:55-78` defines `button.*` with **no built-in min-height** (call-site must add it) and `segmented.item = px-3 py-1.5` (~30px). Call sites that forget the min-height fall sub-floor.

---

## HIGH severity

### `src/components/Header.tsx`
- **L567, L611, L623, L634, L646, L657** — six `IconButton variant="ghost" size="md"` (Sync / Switch-operator / Lock / Reports / Exit-fullscreen / Settings). Two problems at once: **(1) sub-floor** — `md` = 44px; **(2) ambiguous/low-contrast** — `ghost` is `text-ink-muted` with no border/fill on the busy `bg-surface-raised` header, so they read as faint glyphs, not buttons. This is the owner's verbatim complaint ("some elements deserve to be clear buttons with a clear contrast"). Sync, Reports and Settings are frequently tapped. → bump to `size="lg"` (56px) or a 48px secondary/bordered variant; give the high-traffic ones a visible button surface.
- **L585-592** — the open-shift Badge is wrapped in a bare `<button className="rounded-full">` around `<Badge tone="success">` (~24px tall pill). It opens the **End-of-Day / close-shift** flow but reads as a passive status chip. Sub-floor **and** ambiguous. → make it an explicit ≥48px button with a button affordance (label "Clôture" / icon), not a tappable badge.

### `src/components/molecules/QuickActions/QuickActions.tsx`
- **L74-91** — the three core sale actions (Remise / En attente / Reprendre) are `Button size="md"` = `min-h-11` (44px). These are primary cart operations, tapped on every other sale. Sub-floor. → `size="lg"` (56px) or raise the `md` floor.

### `src/components/pos/TodaySalesPanel.tsx`
- **L210-217 ("Voir") and L218-226 ("Réimprimer")** — `px-3 py-1.5 text-xs` (~28–30px) with `bg-surface-sunken text-ink-muted` and a hover that returns to the **same** `surface-sunken` (no visible hover change). These are the literal "Voir / Réimprimer should be clear buttons with contrast" example. Both **sub-floor and low-contrast/ambiguous**. → make them ≥48px `IconButton`/`Button` with a real border or filled surface and a distinct hover.

### `src/components/pos/ReceiptLocatorScreen.tsx`
- **L57-63** — "Refund this" row action: `px-3 py-1.5 text-xs` (~28px). This is the **primary control that starts a refund** from a located receipt. Sub-floor. → ≥48px, treat as the row's primary button.

---

## MEDIUM severity

### `src/components/organisms/TransactionCart/TransactionCart.tsx`
- **L167-174** — Returns/Exchange `IconButton size="md"` (44px), cart toolbar. Sub-floor.
- **L177-184** — Clear-cart `IconButton variant="destructive" size="md"` (44px). Sub-floor + destructive.
- **L436-442** — `ReturnLineItem` remove button `h-7 w-7` (**28px**), destructive (keep-item-don't-refund). Notably the decrement/increment beside it are correct (`h-12`), so this is an inconsistent sub-floor on the destructive control specifically.
- **L407 / spacing** — the return-line +/−/edit cluster uses `gap-1.5` (6px), under the 12–16px floor.

### `src/components/molecules/CartLineItem/CartLineItem.tsx`
- **L151-157** — remove-line-discount `X` button: `rounded-md px-1.5 py-0.5` + `X h-3 w-3` (~20–22px). Sub-floor destructive. (Header expand target L121 `min-h-[48px]`, qty stepper, line-discount and modifier buttons L186/L196 `h-12` are all correct.)

### `src/components/pos/PaymentSummary.tsx`
- **L88-96** — remove cart-discount `IconButton variant="destructive" size="sm"` (**36px**). Sub-floor destructive on the checkout summary. (Cash/Advanced CTAs are `size="lg"` — good.)

### `src/components/pos/ReportsMenu.tsx`
- **L78-89** — menu rows `px-4 py-3 text-sm` (~44px) with leading icons `text-ink-faint`. Sub-floor, and the faint icons read as decoration. → `min-h-[48px]` rows, icon at `text-ink-muted`+.

### `src/components/pos/Modal.tsx`
- **L68-75** — close `X` button is correctly `h-12 w-12` (48px) but the glyph is `text-ink-faint` (the lowest-emphasis ink). On overlays this reads as a ghost. Category-2 contrast only (size is fine). → `text-ink-muted`.

### `src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`
- **L85-91** — Back: `px-3 py-2 text-sm` + `text-ink-muted` hover-only (~36px). Sub-floor + reads as a text link on a full-screen payment view. (Exact/denomination keys `min-h-[56px]` and Confirm `py-4` are correct.)

### `src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- **L517-523** — Back: `px-3 py-2 text-sm` text-link (~36px). Sub-floor + ambiguous.
- **L787-793 and L816-822** — tender-line remove `Trash2`: `rounded-lg p-1.5` + `h-4 w-4` (~32px). Sub-floor destructive. (Method tiles `min-h-[52px]`, Pay-Remaining / Add / Complete `py-3`+ are correct.)

### `src/components/pos/OpenShiftScreen.tsx`
- **L72-79** — "Open shift" submit: `px-4 py-2 text-sm` (~36px). This is the single button that starts the trading day; should be a large primary. Sub-floor. (CurrencyNumpad is `h-14` — good.)

### `src/components/pos/molecules/ManagerPinPanel.tsx`
- **L131-152** — manager `<select>` `p-2` (~36–40px). Sub-floor.
- **L193-203** — Verify button `py-2` (~36px). Sub-floor on a fraud-gate action. (CurrencyNumpad is correct.)

### `src/components/pos/RefundCheckoutFlow.tsx`
- **L306-325** — manager-approval Cancel and Authorize: both `py-2 text-sm` (~36px), sub-floor, side by side with only `gap-2` (8px). Adjacent cancel + refund-authorize under the spacing floor — easy mis-tap on a sensitive action.
- **L138-146** — error-banner dismiss `X`: `p-0.5` + `h-4 w-4` (~24px). Sub-floor.

### `src/components/pos/RefundConfirmModal.tsx`
- **L301-315** — Cancel / Confirm pair both `py-2` (~36px). Sub-floor on the refund-confirm step.

### `src/components/pos/VoucherTenderModal.tsx`
- **L387-401 and L469-481** — Cancel / Apply (and lookup Cancel / Apply) pairs all `py-2` (~36px). Sub-floor on a tender path.

### `src/components/pos/EndOfDayPreviewModal.tsx`
- **L332-344** — Cancel (`bg-surface-raised`) and Confirm (`bg-danger`) both `px-4 py-3` (~44px). Sub-floor on the destructive shift-close confirm (4px under floor).
- **L379-389** — success Print / New-sale `px-6 py-2.5` (~40px). Sub-floor. (L187 error-retry similar.)

### `src/components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx`
- **L170-192** — modifier selection chips `px-4 py-2 text-sm` (~36px), packed `gap-2`. Sub-floor; in a touch flow these are direct tap targets. (Add-to-cart `min-h-[48px]` — good.)

### `src/components/pos/organisms/CashCountTable.tsx`
- **L124-135** — quantity +/− adjust buttons `px-2 py-1` (~28px). Sub-floor on EOD cash-count touch entry.

### `src/components/pos/CashReconciliationSection.tsx`
- **L261-266** — commit/recount action `px-4 py-2 text-sm` (~36px). Sub-floor.

### `src/pages/SettingsPage.tsx`
- **L523-528** — "Remove printer": `px-2 py-1 text-xs text-danger` with hover-only (~24–28px). Sub-floor **and** a destructive action rendered as a faint text link.
- **L836-841** — `ManualPrinterEntry` "Add manual printer": `w-full text-center text-sm text-action`, no border/fill, no min-height — a bare text link (category 2).
- **L889-913** — `ToggleSwitch` track is `h-6 w-11` (**24px tall** hit area). Used 6× across Settings. Sub-floor for every toggle. → enlarge the tap target (wrap in a ≥44–48px hit area or grow the track).

---

## LOW severity (settings / low-traffic / minor)

### `src/pages/SettingsPage.tsx`
- **L175-180** — header Back `h-10 w-10` (40px). Sub-floor.
- **L411** — Force-Fullscreen `tokens.button.primary + 'h-9'` (36px). Sub-floor.
- **L285-299** — accent swatch buttons `h-11 w-11` (44px). Sub-floor.
- **L199-225 / L456-471** — segmented `tokens.segmented.item` (~30px) display-mode + timeout presets. Sub-floor.
- **L240** — language `<select> min-h-[44px]` (4px under).
- **L533-544** — Test-print `px-3 py-2 text-xs` (~30px). Sub-floor.
- **L783-797** — unbind-confirm modal footer Cancel / Confirm `py-2.5` (~40px). Sub-floor; destructive confirm.

### `src/components/organisms/DiscountModal/DiscountModal.tsx` & `LineDiscountModal/LineDiscountModal.tsx`
- DiscountModal **L271-292** / LineDiscount **L273-287** — %/fixed type toggles `px-3 py-2` (~36px). Sub-floor.
- DiscountModal **L355-364** / LineDiscount **L362-368** — Back `min-h-[44px]`. 4px under floor. (Authorize `min-h-[56px]` and the numpad grids that flex-fill are fine.)
- LineDiscountModal **L214-216** — Back header `px-3 py-2` text-link (~36px). Sub-floor + ambiguous.

### `src/components/pos/CashDrawerModal.tsx`
- **L106-120** — tab buttons `px-3 py-2` (~36px). Sub-floor.

### `src/components/pos/ResumeRefundDraftBanner.tsx`
- **L36-44** — Resume / Discard `px-3 py-1.5 text-sm` (~30px). Sub-floor.

### `src/components/pos/molecules/ToleranceDrillDown.tsx`
- **L42-61** — full-width disclosure toggle with no padding on the button itself (~20px tall content). Sub-floor.

### `src/components/pos/HeldTransactionsModal.tsx`
- **L54-68** — Recall (`bg-action`) and Discard (`bg-danger-surface`) are correctly `min-h-[48px]`, but sit `gap-2` (8px) apart and the destructive Discard's hover is the same `bg-danger-surface` (no hover feedback). Minor spacing + affordance.

---

## Compliant (verified — no change needed)
- `ui/Stepper.tsx` keys `h-12` (48px); `molecules/NumPad` keys `min-h-[56px]`; `pos/atoms/CurrencyNumpad` keys `h-14`; `NavRail` items `h-[72px]` + theme toggle `h-12`; `CartLineItem` expand/qty/discount/modifier controls; `Modal` close-button **size** (48px); most large CTAs (`size="lg"` / `py-3`–`py-4`).

---

## Top 12 highest-impact fixes

1. **`ui/IconButton.tsx:26` + `Button.tsx:46`** — `md` 44px → 48px (`h-12` / `min-h-12`). Single change clears most Header + cart-toolbar + QuickActions sub-floor findings.
2. **`Header.tsx:567,611,623,634,646,657`** — the six ghost `size="md"` icon actions → `size="lg"` (56px) and give Sync/Reports/Settings a visible button surface (bordered/secondary), not faint ghost glyphs.
3. **`Header.tsx:585-592`** — replace the tappable shift Badge with an explicit ≥48px labelled button (it opens End-of-Day).
4. **`molecules/QuickActions/QuickActions.tsx:74`** — Remise / En attente / Reprendre `size="md"` → `size="lg"` (56px).
5. **`pos/TodaySalesPanel.tsx:210-226`** — make "Voir" and "Réimprimer" real ≥48px bordered/filled buttons with a distinct hover (owner's exact complaint).
6. **`pos/ReceiptLocatorScreen.tsx:57-63`** — "Refund this" `py-1.5 text-xs` → ≥48px primary row button.
7. **`organisms/TransactionCart/TransactionCart.tsx:177-184` & `:436-442`** — Clear-cart `size="md"` → `lg`; ReturnLineItem remove `h-7 w-7` → `h-12 w-12`.
8. **`pos/PaymentSummary.tsx:88`** — remove-discount `IconButton size="sm"` (36px) → 48px.
9. **`pos/OpenShiftScreen.tsx:72-79`** — Open-shift submit `py-2` → large primary (`min-h-[56px]`).
10. **`pos/EndOfDayPreviewModal.tsx:332-344`** — Cancel/Confirm `py-3` → `min-h-[48px]`; widen the gap between Cancel and the danger Confirm (≥16px).
11. **`pages/SettingsPage.tsx:889-913` (`ToggleSwitch`) + `:523-528` (Remove-printer) + `:836-841` (Add-manual-printer)** — give toggles a ≥44–48px hit area; turn the two destructive/link actions into bordered buttons.
12. **`pos/Modal.tsx:74` + `pos/ReportsMenu.tsx:86`** — raise close-X / menu-row icons from `text-ink-faint` to `text-ink-muted`+; set ReportsMenu rows to `min-h-[48px]`. (Also fold in the recurring `py-2` Cancel/Confirm pairs in RefundCheckoutFlow / RefundConfirmModal / VoucherTenderModal — same one-line `min-h-[48px]` fix.)
