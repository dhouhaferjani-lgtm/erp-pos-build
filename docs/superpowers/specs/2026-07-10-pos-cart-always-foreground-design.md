# POS — Cart Always in the Foreground (design)

**Date:** 2026-07-10
**Branch:** `feat/pos-cart-foreground` (worktree `apps/erp.cart-foreground`, based on local dev `4b53a774e`)
**Status:** owner-approved design (brainstorm 2026-07-10); baseline includes the caisse polish round 2 merge `81c03d963`
**Related:** memory `project_pos_cart_always_foreground`; `ProductDetailSheet` extraction (commit `3bb0a1706`)

## Rev 2 (2026-07-10) — adversarial-review reconciliation

Folds in the accepted findings of the pre-dispatch adversarial review (`docs/superpowers/specs/reviews/2026-07-10-pos-cart-always-foreground-spec-plan-adversarial-review.md` — 2 reviewers, 0 BLOCKER / 5 MAJOR / 12 MINOR). **Rev 2 items override any conflicting text below**; the body has also been edited in place so the doc reads consistently.

- **Close pane on settle/new-sale** — new owner decision (U4); the within-sale "post-add stays open" decision is untouched.
- **Esc stacking guard (U1):** the detail pane's Esc handler bails while any `[aria-modal="true"]` element is mounted (every Modal binds its own window Esc listener).
- **Pulse lands on-screen (U2):** the cart scrolls the affected line into view on `lastAddedNonce` change; the customize-EDIT confirm path also pulses (U9).
- **Stale customize-EDIT protection (U3):** customize state proactively cleared on recall and on settle/new-sale; for clear-cart, removal of the edited line, and refund draft-resume, the confirm-time validation (line missing → `modifiers.lineGone` toast + close, no mutation) covers the outcome. §4 "held/recall: pane unaffected" now correctly holds for the DETAIL pane only.
- Deliberate-decision notes made explicit: Esc inert on the composer is a behavior CHANGE (U5); no focus management (U7). Grid-hiding fallback named (U6); pane wrapper gets `overflow-x-auto` (U8); §3 inventory completed (U10).

## Owner principle

The cart is the source of truth. Any action that ends with something added to the cart, and that the operator must visually confirm, must NOT block the cart. The cart is always visible and always interactive.

Concrete trigger: the product detail drawer overlays the whole screen (`fixed inset-0 z-[52]`, `ProductDetailDrawer.tsx:66`). The operator opens Équivalents, taps one, it IS added — but the drawer hides the cart, so they cannot see it land ("did it work?").

## Owner decisions (brainstorm 2026-07-10)

| Decision | Choice |
|---|---|
| Grid fate when detail opens | **Detail replaces the grid** in the product pane; cart untouched beside it |
| V1 scope | **Detail drawer + Customize (ModifierSelectionModal)** — the two dwell surfaces. Variant picker + barcode chooser stay as quick centered dialogs (sub-second occlusion) |
| Post-add behavior (detail pane) | **Stays open** — operator watches the cart line appear, keeps adding (routine/equivalents upsell flow) |
| Post-settle behavior | **Close pane on settle/new-sale** (owner 2026-07-10, review U4) — within-sale "post-add stays open" untouched |
| Hosting mechanism | **Approach A: pane replacement** — normal flex children, no overlay, no z-index; occlusion becomes structurally impossible |
| Added-line pulse | **Included in v1** |
| Full-screen exception | Payment/checkout takeover MAY cover the screen — it IS the cart action |

## §1 Interaction model

**Pane state.** The product area (`HomePage.tsx:1502`, `flex flex-[7]`) becomes a switched pane with three views: `grid | detail | customize`, derived from the existing `detailProduct` (`HomePage.tsx:241`) and `modifierProduct` state — no new duplicated state. The two are mutually exclusive: opening one clears the other.

**Grid preservation.** The grid stays **mounted but `hidden`** while a pane view is active, so scroll position and TanStack Virtual state survive; closing a pane returns to the grid exactly where the operator left it.

**Cart column untouched.** Fixed 460px (`HomePage.tsx:1463`), always visible, always interactive: quantities, customer attach, and Pay all work while a pane is open. Both `cartPosition` values work for free — the pane is just the other flex child of the existing `flex-row`/`flex-row-reverse` split (`HomePage.tsx:1444`).

**Detail pane.** `ProductDetailSheet` fills the pane fluidly. Adds (main button, Équivalents, Routine, Compléments — all `addItemGated`, non-closing today) keep the pane open. Close = X or Esc (the backdrop-click close path dies with the backdrop). Esc closes the detail pane ONLY when no modal dialog is above it: the pane's Esc handler bails if any `[aria-modal="true"]` element is mounted (review U1). Every Modal binds its own window Esc listener (`Modal.tsx:31-39`), so without the guard one Esc press aimed at a dialog stacked above the pane (variant picker, held, customer search, cash screen) would close both surfaces. Opening detail for a different product swaps content in place (existing `product.id` reset keeps tab semantics).

**Customize pane.** The modifier composition UI renders in the same pane. **Confirm-add closes back to grid** (composing is a task); cancel closes too. This differs deliberately from the detail pane (inspecting is a context). Esc doing nothing on the composer is a DELIBERATE BEHAVIOR CHANGE vs today — the old `<Modal>` shell closed on Esc (`Modal.tsx:31-39`); the owner verifies this knowingly on device (U5).

**Scan unaffected.** Scan capture/routing stays in HomePage above the pane (`useBarcodeScanner`, `HomePage.tsx:581-584`; `routeScanResult` fan-out `HomePage.tsx:477-509`). Scanning while a pane is open adds to the visible cart with the toast; disambiguation (variant picker / barcode chooser) appears as today's quick centered dialog — allowed per scope.

**Exceptions (unchanged surfaces).** Payment/checkout takeovers (`CashPaymentScreen`, `AdvancedPaymentsModal`), cart-action modals (transaction/line discount, `QuantityNumpad`, `HeldTransactionsModal`, refund flow), and system surfaces (shift, PIN, fiscal gate, reports) keep full-screen behavior.

## §2 Components

1. **`ProductDetailSheet` gains `variant: 'overlay' | 'pane'`** (`components/pos/ProductDetailDrawer.tsx:94-318`). Pane mode: `w-full h-full` (drops fixed `w-[1080px] h-[680px]`, caps, and `ez-sheet-rise`); `role="region"` + `aria-label` instead of `role="dialog" aria-modal` (it is genuinely not a modal — a11y correction). During the transition the overlay variant remains for `/theme-preview`'s existing overlay demo until the host is deleted, then the preview moves to the pane variant.
2. **The overlay host `ProductDetailDrawer` is deleted** once HomePage hosts the pane (`HomePage.tsx:1638-1644` mount replaced). The `organisms/ProductDetailDrawer/index.ts` barrel re-exports move accordingly.
3. **`ModifierComposerSheet` extracted from `ModifierSelectionModal`** (`components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx:126`, currently `<Modal size="full">`) — same extraction pattern as `ProductDetailSheet`: pure content component, controlled props, behavior (gating, pricing, quantity) byte-identical; the Modal shell wrapper leaves the HomePage flow. HomePage is its only consumer.
4. **`ProductPaneHost`** — small new component owning the `grid|detail|customize` switch and the hidden-grid mechanics (grid + TableSelector hidden together; ToastSmartPrompts stays). Keeps HomePage from growing.
5. **Added-line pulse** — when an add lands, the affected cart line briefly highlights (single token-based CSS animation on the existing `TransactionCart` line item; keyed by last-touched line id from the cart store mutation). Draws the operator's eye from the pane to the confirmation. No timers held in React state beyond an animation class toggle. On `lastAddedNonce` change the cart also **scrolls the affected line into view** (U2): the cart list is `overflow-y-auto` and new lines append at the bottom — on 8+ line tickets they land below the fold, and a pulse that fires off-screen is the exact "did it work?" failure this story exists to fix. The customize-EDIT confirm path (`updateLineModifiers`) also stamps the pulse — it is a pane-originated cart mutation the operator must confirm (U9).

## §3 Overlay policy (codified)

Documented in the POS design-language doc as a rule for new surfaces:

- **(a) Browse / add-to-cart-adjacent** → pane-hosted, or a sub-second centered disambiguation dialog. Never `fixed inset-0`.
- **(b) Cart-action takeover** (payment, discounts, quantity, held/recall, refunds) → full-screen allowed; it is the cart action.
- **(c) System/admin** (shift, PIN, fiscal durability, reports) → full-screen allowed.

New surfaces declare their class in review. No lint tooling in v1; the pane host becomes the easiest path, which is the real enforcement.

Current inventory classification (from the 2026-07-10 exploration; completed in Rev 2, U10): (a) ProductDetailDrawer, ModifierSelectionModal (both move in v1); VariantPickerModal, BarcodeChooserModal (transient, stay as dialogs); ToastSmartPrompts/SmartPromptCard (already inline, compliant). (b) CashPaymentScreen, AdvancedPaymentsModal, CheckoutSuccessModal, DiscountModal, LineDiscountModal, QuantityNumpad, HeldTransactionsModal, RefundCheckoutFlow, ReceiptScanConfirmationSheet, ReceiptLocatorScreen, CustomerSearchModal, CardPaymentModal, VoucherTenderModal, CashDrawerModal, RefundConfirmModal, EndOfDayPreviewModal, SaleDetailModal. (c) OpenShiftScreen, CloseShiftModal, ReportsMenu, DurabilityGateModal, LoyaltyEnrollDialog, XReportModal, ZReportModal. `CashTenderedModal` has no consumers (legacy; candidate for removal, out of scope). Note: CustomerSearchModal stays class (b) in v1, but its attach outcome is cart-visible — a legitimate class-(a) candidate for v2 (U10).

## §4 Edge cases

- **Pay while pane open:** allowed; the payment takeover covers everything including the pane. On settle/new-sale the pane CLOSES (owner decision, Rev 2 U4): `handleNewSale` clears `detailProduct`/`modifierProduct` (and `editingLineId`) so the next sale starts on the grid — including through lock-after-sale; the cart clears normally.
- **Held/recall while pane open:** cart content swaps; detail pane unaffected; CUSTOMIZE pane state is proactively cleared on recall and on settle/new-sale; clear-cart, removal of the edited line, and refund draft-resume are covered by the confirm-time validation instead — a dangling `editingLineId` would otherwise make Confirm a silent no-op (`updateLineModifiers` no-ops on missing ids, `cartStore.ts:371-393`). Confirm additionally validates the line still exists and toasts if not (U3).
- **TableSelector (F&B)** hides with the grid; **ToastSmartPrompts** remain visible (inline, non-occluding, cart-relevant).
- **OOS gating unchanged:** `hardBlockOutOfStock` is already a sheet prop threaded from `posStockPolicy` (`HomePage.tsx:1648`).
- **Width floor:** at 1366px the pane is ≈840px (sheet aside 344px + ≈490px tab column — comfortable). Set a sane internal `min-w` rather than responsive breakpoints; the POS is a fixed-terminal layout (no breakpoints exist on the split today). The pane wrapper gets `overflow-x-auto` so at degenerate widths content scrolls instead of clipping the tab strip and close X (U8).
- **Esc:** closes the detail pane (new; guarded — inert while a modal dialog is above it, see §1/U1); customize pane closes only via its explicit confirm/cancel (Esc inert — a deliberate behavior change, see §1/U5).
- **Grid hiding fallback:** if `display:none` breaks TanStack Virtual scroll restoration on device, fall back to capture/restore `scrollTop` or `visibility`-based hiding (U6).
- **Focus management:** none on pane open/close — deliberate for a touch/scan terminal (the scanner listens on window) (U7).
- **Refund carts:** detail/customize panes behave identically; refund checkout remains a class-(b) takeover.

## §5 Testing & verification

- **Structural invariant test (the story's regression guard):** with any pane view active, assert no `fixed inset-0` surface is mounted from the pane path, and `TransactionCart` remains present in the accessibility tree and receives pointer events.
- Component tests: pane switching + `detailProduct`/`modifierProduct` mutual exclusion; grid hidden-not-unmounted (state/scroll preservation); pane-variant sheet (no `aria-modal`, fluid fill, `role="region"`); `ModifierComposerSheet` behavior parity with the old modal; post-add keeps detail open / confirm closes customize; Esc handling; pulse class applied on add.
- **/theme-preview:** pane-variant panel at realistic pane width (~840px), replacing the overlay demo; playwright matrix pass (light/dark × rounded/sharp) as in polish round 2.
- **On-device:** owner verifies the equivalents flow end-to-end on real Tauri — open detail, add equivalent, watch the cart line pulse.
- **Execution model:** orchestrated waves with adversarial review at every milestone; merge to LOCAL dev only (promotion = owner call).

## Out of scope

- Variant picker / barcode chooser re-hosting (transient dialogs — revisit only if owner reports pain).
- Equivalents-on-scan (deferred feature; seam documented: `routeScanResult` hit branch, `HomePage.tsx:477-509`).
- Overlay-policy lint tooling.
- `CashTenderedModal` removal (unused legacy).
- Near-expiry / batch-expiry content (Spec 2, unwritten).
