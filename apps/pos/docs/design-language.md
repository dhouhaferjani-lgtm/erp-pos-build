# IziPOS Design Language

> The locked design system for `apps/pos`. Every new screen and component must follow this. Enforced by the ESLint hardcoded-color guard (`eslint.config.js`) and `tsc`. Iterable — propose changes via PR to this file + `src/index.css` `@theme` + `src/lib/designTokens.ts` together.

Established 2026-06-13 from the Hallmark UI audit. Source of truth for tokens: `src/index.css` (`@theme`) and `src/lib/designTokens.ts`.

---

## 1. Color grammar (non-negotiable)

Color carries meaning. Do not reuse a status color for decoration.

| Role | Token family | Use for | Never use for |
|---|---|---|---|
| **action** (ocean blue) | `action`, `action-hover`, `action-subtle`, `action-strong` | Interactive/primary actions, selected state | Prices, decoration |
| **success** (green) | `success`, `success-surface`, `success-strong`, `success-subtle` | Confirmed money & sync events ONLY (completed sale, "synced", change due) | Generic "good" states, in-stock counts |
| **warning** (amber) | `warning`, `warning-surface`, `warning-strong`, `warning-subtle` | Warnings, low stock, "caution" actions (e.g. change terminal) | Errors |
| **danger** (red) | `danger`, `danger-surface`, `danger-strong`, `danger-subtle` | Errors + destructive/irreversible actions ONLY | Out-of-stock, backspace keys, generic emphasis |
| **brand → accent** (Caisse) | `accent`, `accent-strong`, `accent-tint`, `accent-ring`, `accent-text` | UX accent / primary CTA (Encaisser). Swappable via `data-accent`. In the Caisse theme `action` is re-pointed to `accent`. | Body text |
| **ink** | `ink`, `ink-strong`, `ink-muted`, `ink-faint`, `ink-inverse` | All text; **prices use `ink`** (+ mono) | — |
| **stock** (Caisse) | `stock-ok`, `stock-low`, `stock-out` (+ `-surface`) | Product stock state ONLY (En stock / Stock faible / Rupture) | Money, sync, errors, decoration |

### Stock-badge exception (Caisse Parapharmacie)

The earlier rule — *"out-of-stock = neutral, NOT red; never green for in-stock"* — kept the `success`/`danger` money-and-error semantics uncontaminated. The parapharmacy Caisse redesign needs **glanceable green/amber/red stock status** (a near-universal retail convention, and a core merchandising signal at a fast till). Resolution: a **dedicated stock token family** (`stock-ok`/`stock-low`/`stock-out`), visually green/amber/red but **semantically separate** from `success`/`warning`/`danger`. This satisfies the original intent — money/error colours are still never overloaded — while giving the cashier the conventional traffic-light read. Out-of-stock cards are still **dimmed** (desaturated) in addition to the `stock-out` badge. Use the stock tokens ONLY for stock state; never for money, sync, or errors, and never the reverse.

## 2. Surface scale (elevation = separation)

The fix for "weak separation": elevation comes from the surface scale, not from borders alone.

- `surface-canvas` — app background (slightly tinted, neutral-100). The page sits here.
- `surface-raised` — panels & cards (white + `shadow-sm`). The cart (money zone) is raised against the canvas.
- `surface-overlay` — modals (white + `shadow-2xl` + `bg-black/50` scrim).
- `surface-sunken` — inset boxes, disabled controls, neutral keypad keys.

### Section-surface helpers

`tokens.section` in `designTokens.ts` packages the recurring chrome/canvas/panel combos so restyle tasks don't hand-roll them: `header`/`footer` = navy chrome anchor (`bg-pay-navy text-pay-navy-fg`); `rail`/`cartPanel` = raised + bordered (`bg-surface-raised border-* border-border-strong`, `cartPanel` adds `shadow-sm`); `canvas` = the recessed page background (`bg-surface-canvas`).

### Navy — the chrome anchor

`--pay-navy` / `--pay-navy-fg` are theme-constant (not `[data-theme]`- or `[data-accent]`-dependent — same navy in light and dark, same navy across every accent swap). Header and footer bars are the one fixed structural color in the app: they anchor the chrome regardless of tenant accent or theme, and never carry interaction or status meaning (that's `action`/`success`/`warning`/`danger`, per §1).

### Spacing scale

4px base scale: **4 · 8 · 12 · 16 · 20 · 24**. Rule: tighten intra-group spacing (icon-to-label, badge-to-price, stacked form fields — 4-8px) and keep inter-section spacing generous (panel-to-panel, cart-to-canvas, header-to-content — 16-24px), so the eye reads groups first and the overall section structure second.

## 3. Components — atomic layer (`src/components/ui/`)

**This is the rule the whole system hinges on: every element of a given type is the same atom.** Never hand-roll a button/badge/pill/segmented control with raw classes — import the atom from `@/components/ui`. A change to an atom or a token then propagates to every instance automatically. Atoms are ESLint-guarded (no raw palette classes).

- **`<Button variant size>`** — the single source for text buttons. Variants encode role (the color grammar): `primary` (main action), `confirm` (money completion — Charge/Pay, green), `secondary` (neutral actions — Client, Discount, Hold…), `ghost` (low-emphasis), `destructive` (irreversible, red). Sizes are touch targets: `sm`=36px (desktop-dense), `md`=44px (default), `lg`=56px (primary CTA). All 8 states; disabled communicated by surface+ink (never opacity); `loading` shows a spinner.
- **`<IconButton aria-label icon variant size>`** — square icon-only button, same variants; accessible name enforced.
- **`<Badge tone>`** — one pill shape for terminal/shift/stock/counts. Tones: neutral/success/warning/danger/action.
- **`<StatusPill tone label pulse>`** — the session/connectivity indicator (one consistent shape, dot + label). It's an indicator, NOT a control — keep the manual sync trigger as a separate `<IconButton>` beside it.
- **`<SegmentedControl options value onChange>`** — the ONLY choose-one control voice.

> Legacy `tokens.*` recipes in `designTokens.ts` (button/badge/segmented/statusPill/money/surface) still back the color grammar and a few non-atom spots, but **new UI must use the atoms in `ui/`**. The atoms supersede the className-string recipes for buttons.

- **Disabled controls:** communicate disabled by surface + ink + cursor, **never opacity alone**; show the reason near the control where space allows.

## 4. Typography & numbers

- Font: Inter (system stack fallback). Roles: display amount > section heading > body > caption.
- **All monetary & quantity values use `tabular-nums`** (utility class `tabular-nums` or `tokens.money`). Columns of money must align. TND is 3-decimal — keypads include a `000` key.

## 5. Touch targets

- Tactile mode: ≥ 48px. Desktop: ≥ 40px. Clickable text never wraps to two lines (`whitespace-nowrap` + shorten the label).

## 6. Status & alarm discipline

- Healthy/normal states are quiet (a header pill), not full-width banners. Surface a banner ONLY when something needs attention (elevated/escalated). This matches the POS fail-closed philosophy and prevents alarm fatigue.

## 7. PR checklist (design)

- [ ] No raw Tailwind palette classes (`bg-blue-600`, `text-gray-500`, …). Use semantic tokens. (ESLint errors on migrated dirs.)
- [ ] Color grammar respected (success = money/sync, danger = error/destructive only).
- [ ] Surfaces use the elevation scale; money zones read as raised.
- [ ] Text contrast ≥ 4.5:1; UI/border contrast ≥ 3:1.
- [ ] Monetary values use `tabular-nums`.
- [ ] Interactive elements: visible disabled treatment (not opacity-only) + focus-visible ring; touch targets meet the minimum.
- [ ] Choose-one controls use `tokens.segmented`; buttons use `tokens.button.*`.
- [ ] All user-facing strings via `t()` (no hardcoded English/French).
- [ ] New full-screen surface? Declare its overlay class (a/b/c) — see §9. Class (a) never covers the cart.

## 8. Adding a new screen/dir

When a new directory is fully tokenized, add its glob to `tokenMigratedGlobs` in `eslint.config.js` so the color guard enforces it at `error`.

## 9. Overlay policy — the cart is always in the foreground

The cart is the source of truth. Any surface whose outcome is "something lands
in the cart that the operator must visually confirm" must NOT cover the cart.
(Spec: `docs/superpowers/specs/2026-07-10-pos-cart-always-foreground-design.md`.)

Every new POS surface declares one of three classes in review:

- **(a) Browse / add-to-cart-adjacent** → hosted in the product pane
  (`ProductPaneHost`, `components/pos/ProductPaneHost.tsx`) or a sub-second
  centered disambiguation dialog. **Never `fixed inset-0`.**
  Current: ProductDetailSheet pane, ModifierComposerSheet pane;
  VariantPickerModal + BarcodeChooserModal (transient dialogs, allowed);
  ToastSmartPrompts / SmartPromptCard (inline, compliant).
- **(b) Cart-action takeover** — payment, discounts, quantity, held/recall,
  refunds, customer attach → full-screen allowed; it IS the cart action.
  Current: CashPaymentScreen, AdvancedPaymentsModal, CheckoutSuccessModal,
  DiscountModal, LineDiscountModal, QuantityNumpad, HeldTransactionsModal,
  RefundCheckoutFlow, ReceiptScanConfirmationSheet, ReceiptLocatorScreen,
  CustomerSearchModal, CardPaymentModal, VoucherTenderModal, CashDrawerModal,
  RefundConfirmModal, EndOfDayPreviewModal, SaleDetailModal.
  Note: CustomerSearchModal stays class (b) in v1, but its attach outcome is
  cart-visible — a legitimate class-(a) candidate for v2.
- **(c) System/admin** — shift, PIN, fiscal durability, reports → full-screen
  allowed. Current: OpenShiftScreen, CloseShiftModal, ReportsMenu,
  DurabilityGateModal, LoyaltyEnrollDialog, XReportModal, ZReportModal.

No lint tooling in v1 — the pane host being the easiest path is the
enforcement; reviewers reject an undeclared class-(a) `fixed inset-0` surface.
