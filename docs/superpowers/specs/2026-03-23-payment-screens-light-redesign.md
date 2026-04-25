# Payment Screens Light Theme Redesign

## Goal

Redesign both CashPaymentScreen and AdvancedPaymentsModal as light-themed full-screen experiences, consistent with each other and the rest of the POS app. Use only semantic Tailwind classes so future dark mode requires zero rework.

## Context

The CashPaymentScreen was previously redesigned as a dark full-screen (`bg-gray-900 text-white`). The AdvancedPaymentsModal remained as a constrained modal overlay (`<Modal size="full">` = `max-w-4xl h-[85vh]`, centered with backdrop — not actual full screen). This creates two problems:
1. **Visual inconsistency** — jarring theme switch when entering cash payment
2. **Advanced payments UX** — constrained modal with cramped 2-column layout requires scrolling, poor contrast

The app is light-themed. A dark mode toggle will be added later. Both payment screens must follow the current light theme and use semantic classes that invert naturally with `dark:` variants.

## Design Principles

1. **No hardcoded colors** — use Tailwind semantic classes (`bg-white`, `text-gray-900`, `bg-gray-50`) not hex values or dark-specific colors
2. **Use theme variables for accents** — `bg-primary-600`, `text-primary-700` etc. from the existing CSS variable system
3. **Full-screen, not modal** — both screens replace the view entirely (CashPaymentScreen already does this; AdvancedPaymentsModal switches from Modal overlay to full-screen div)
4. **Touch-first sizing** — minimum 48px touch targets, large numpad buttons, readable at arm's length
5. **Minimal scrolling** — all primary content visible without scrolling on a 15" 1080p POS display; payment lines list scrolls independently if > 3 lines

## Screen 1: CashPaymentScreen (Light Retheme)

**Layout:** Keep existing 2-panel structure. Change colors only.

*Note: ASCII diagrams show a simplified 3-column numpad. The actual NumPad component is a 4-column grid (digits in cols 1-3, actions in col 4). Use the existing NumPad component as-is.*

```
┌──────────────────────────────────────────────────────┐
│  ← Back to cart          Cash Payment                │  Header (white, border-b)
├────────────────────┬─────────────────────────────────┤
│                    │  [Exact] [10€] [20€] [50€]      │
│   AMOUNT DUE       │                                 │
│   € 24.50          │   ┌─────┬─────┬─────┐          │
│                    │   │  7  │  8  │  9  │          │
│   TENDERED         │   ├─────┼─────┼─────┤          │
│   € 30.00          │   │  4  │  5  │  6  │          │
│   (primary-600)    │   ├─────┼─────┼─────┤          │
│                    │   │  1  │  2  │  3  │          │
│  ┌──────────────┐  │   ├─────┼─────┼─────┤          │
│  │ CHANGE DUE   │  │   │  0  │  .  │  ⌫  │          │
│  │ € 5.50       │  │   └─────┴─────┴─────┘          │
│  │ (green card)  │  │                                 │
│  └──────────────┘  │   [✓ Complete Payment]           │
│   (white bg)       │   (bg-green-600, full width)     │
└────────────────────┴─────────────────────────────────┘
```

### Color mapping (dark → light)

| Element | Current (dark) | New (light) |
|---------|---------------|-------------|
| Background | `bg-gray-900` | `bg-gray-50` (full screen div) |
| Header | `border-gray-700` | `bg-white border-gray-200` |
| Back button | `text-gray-400 hover:bg-gray-800` | `text-gray-500 hover:bg-gray-100` |
| Title | `text-white` | `text-gray-900` |
| Left panel | `border-gray-700` (implicit) | `bg-white border-gray-200` |
| Amount labels | `text-gray-400` | `text-gray-500` |
| Amount values | `text-white` | `text-gray-900` |
| Tendered value | `text-blue-400` | `text-primary-600` |
| Change due card | `bg-green-950 border-green-800` | `bg-green-50 border-green-200` |
| Change due text | `text-green-400/500` | `text-green-700` |
| Denomination buttons | `bg-gray-800 border-gray-600` | `bg-white border-gray-200` |
| Exact button | `bg-blue-950 border-blue-600 text-blue-300` | `bg-primary-600 text-white` |
| Numpad buttons | (no change — NumPad component uses `bg-gray-100`, already light) | (no change) |
| Error icon | `text-red-400` | `text-red-500` |
| Confirm button | `bg-green-600` | `bg-green-600 text-white` (same) |
| Error banner | `bg-red-900/50 border-red-700` | `bg-red-50 border-red-200 text-red-700` |

### Structural changes
- None. Layout stays identical (flex row, left flex-2 amounts, right flex-3 numpad).
- Just color class replacements.

## Screen 2: AdvancedPaymentsModal → AdvancedPaymentScreen (Full Redesign)

**Major change:** Convert from `<Modal size="full">` (currently a `max-w-4xl h-[85vh]` centered modal with backdrop) to a `fixed inset-0 z-50` full-screen div matching CashPaymentScreen's pattern. Do not rename the file/directory — keep as `AdvancedPaymentsModal` to avoid churn across imports; the name change is cosmetic and can be done later if desired.

**Layout:** 3-column instead of current 2-column.

```
┌──────────────────────────────────────────────────────────────┐
│  ← Back to cart              Split Payment                   │  Header
├──────────────┬─────────────────────┬─────────────────────────┤
│              │                     │                         │
│  METHODS     │   AMOUNT            │  TOTAL DUE              │
│  ┌────────┐  │   € 15.00           │  € 24.50               │
│  │💳 Card ◄│  │                     │  (primary-600 card)     │
│  ├────────┤  │  [Pay Remaining]     │                         │
│  │💵 Cash  │  │                     │  PAYMENTS               │
│  ├────────┤  │  ┌─────┬─────┬─────┐│  ┌───────────────────┐  │
│  │📄 Check │  │  │  7  │  8  │  9  ││  │ 💵 Cash    € 9.50│  │
│  ├────────┤  │  ├─────┼─────┼─────┤│  │ Caisse Prin.   ✕ │  │
│  │🏦 Xfer  │  │  │  4  │  5  │  6  ││  └───────────────────┘  │
│  └────────┘  │  ├─────┼─────┼─────┤│                         │
│              │  │  1  │  2  │  3  ││  ─────────────────────  │
│  REPOSITORY  │  ├─────┼─────┼─────┤│  Paid         € 9.50   │
│  [TPE Princ.]│  │  0  │  .  │  ⌫  ││  Remaining    € 15.00  │
│  CARD LAST 4 │  └─────┴─────┴─────┘│                         │
│  [4821]      │                     │  [Complete Transaction]  │
│              │                     │  (green-600, disabled    │
│ [+ Add Pay]  │                     │   until fully paid)      │
└──────────────┴─────────────────────┴─────────────────────────┘
```

### Column breakdown

**Left column (30%) — Method selection + config:**
- Payment method list as vertical buttons (not grid) — larger touch targets, clearer selection state
- Selected method: `border-primary-500 bg-primary-50 text-primary-700`
- Unselected: `border-gray-200 bg-white text-gray-700 hover:border-gray-300`
- Config fields appear below the method list (repository dropdown, reference, card last 4) — only fields relevant to the selected method
- "Add Payment" button pinned to bottom of column: `bg-primary-600 text-white`
- White background (`bg-white`)

**Center column (35%) — Amount + numpad:**
- Large amount display at top: label `text-gray-500` + value `text-gray-900` in large font
- "Pay Remaining" pill button: `bg-primary-600 text-white` — prominent, not a tiny gray afterthought
- NumPad fills remaining space
- Gray background (`bg-gray-50`)

**Right column (35%) — Balance + payment lines:**
- Total due card at top: `bg-primary-600 text-white` with large amount
- Scrollable payment lines list (each line: method name, repository, amount, delete button)
- Balance footer pinned to bottom: paid, remaining (warning color if > 0), change due (green if overpaid)
- "Complete Transaction" button: `bg-green-600 text-white`, disabled state `opacity-50` until fully paid
- White background (`bg-white`)

### Behavioral changes
- Remove `<Modal>` wrapper — render as `fixed inset-0 z-50` div (same as CashPaymentScreen)
- Remove `touchMode` dependency — numpad always visible, amount input always `readOnly` (numpad is the sole input method on a POS terminal)
- Remove collapsible order summary toggle and `showSummary` state — total due card is always visible and compact
- Auto-fill amount with remaining balance when selecting a method (already works)
- Reset state on close (already works)

## Shared Components

### NumPad
The existing NumPad component uses hardcoded `bg-gray-100` buttons. These are already semantic Tailwind classes that will work with dark mode. No changes needed to NumPad itself — its current light styling matches the new design.

### Header pattern
Both screens share the same header: `bg-white border-b border-gray-200`, back button left, title center. Duplicate the header markup in both components for now — extracting a shared `PaymentHeader` component is deferred to avoid scope creep.

## Accessibility

- All interactive elements ≥ 48px touch target
- Color contrast ratio ≥ 4.5:1 for text (gray-900 on white = 17.4:1, primary-600 on white = ~5:1)
- Focus-visible rings on all buttons for keyboard navigation
- Semantic HTML: `<button>` elements, not clickable divs
- ARIA labels on icon-only buttons (back, delete payment line)

## Dark Mode Readiness

All classes used are semantic Tailwind that pair naturally with `dark:` variants:
- `bg-white` → `dark:bg-gray-900`
- `bg-gray-50` → `dark:bg-gray-950`
- `text-gray-900` → `dark:text-gray-100`
- `border-gray-200` → `dark:border-gray-700`
- `bg-primary-600` → stays the same (primary works on both themes)
- `bg-green-50` → `dark:bg-green-950`

No hex colors, no `bg-gray-900` used for "dark look", no `text-white` paired with dark backgrounds (except on primary/green buttons which stay colored in both themes).

## Files to Modify

1. `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx` — retheme from dark to light
2. `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx` — full redesign: Modal → full-screen, 2-col → 3-col, light theme (keep filename unchanged)
3. `apps/pos/src/pages/HomePage.tsx` — update AdvancedPaymentsModal usage if props change
4. `apps/pos/src/locales/en/common.json` + `fr/common.json` — any new translation keys

## Out of Scope

- Dark mode toggle implementation (future task)
- NumPad component refactoring (already light-compatible)
- Other POS screens/components
- Backend changes (none needed)
