# POS Bugs & UX Overhaul — Design Spec

> **Date:** 2026-03-22
> **Target:** Deploy tomorrow at client coffee shop
> **Scope:** Desktop POS app (apps/pos), Web POS (apps/web/src/features/pos), Backend (apps/api)

---

## Phasing

**Phase 1 — Deploy-critical (today):** Bug fixes (1.1–1.4) + spacing/touch optimization (2.4)
**Phase 2 — Fast-follow:** Layout restructure (2.1), payment screen redesign (2.2, 2.3), onboarding (3)

---

## 1. Bug Fixes

### 1.1 Transaction Discount Makes Total Zero

**Root Cause:** `DiscountModal.tsx` `onApplyTransactionDiscount` callback signature only accepts `{ amount: string; reason: string }` (line 11-14) — no `type` field. The modal tracks `discountType` internally ('percentage' | 'fixed') but passes the raw numeric string as `amount` without any type indicator. `cartStore.ts` `total()` (line 228-234) then treats it as an absolute amount: `Math.max(0, subtotal + tax - parseFloat(amount))`, which over-subtracts for percentage discounts on small orders, clamping to 0.

**Fix:** In `DiscountModal.tsx`, expand the callback to pass `{ type, value, reason }`. In `HomePage.tsx` `handleApplyTransactionDiscount`, convert percentage to absolute amount before storing — same pattern as the working line-level discount handler at line 316.

**Files:**
- `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx` — expand callback to pass `type` + `value`
- `apps/pos/src/pages/HomePage.tsx` lines 292-300 — add percentage→absolute conversion
- `apps/pos/src/stores/cartStore.ts` — no changes needed (already correct)

**Tests:** Unit test for percentage-to-absolute conversion. Cart store test for percentage discount scenario.

**Web POS:** NOT affected. `TransactionDiscountInput.tsx` already converts percentage to absolute via `resolveAmount()` (line 72-77).

### 1.2 Line Item Discount Doesn't Update Total

**Root Cause:** `HomePage.tsx` `handleApplyLineDiscount` (lines 306-335) updates `line_total` after discount but does NOT recalculate `tax_amount`. Since `total()` = `subtotal + tax`, stale tax keeps the total wrong.

**Fix:** Add `tax_amount` recalculation to the returned item, matching the pattern in `cartStore.ts` `recalcLineTotal` (lines 45-67). Note: `computeTaxAmount` (line 37-43) is not currently exported from `cartStore.ts` — either export it or inline the calculation: `lineTotal * (taxRate / (100 + taxRate))` for tax-inclusive, or `lineTotal * (taxRate / 100)` for tax-exclusive.

**Tests:** Unit test verifying total updates correctly after line discount.

**Files:**
- `apps/pos/src/pages/HomePage.tsx` lines 320-328 — add `tax_amount` recalculation
- `apps/pos/src/stores/cartStore.ts` — export `computeTaxAmount` if needed

### 1.3 Quick Cash Payment — "No POS Station Configured"

**User-reported error:** "No point of sale station is configured" when attempting quick cash payment.

**Root Cause:** The actual errors are i18n-keyed: `i18n.t('errors.noCashMethod', { ns: 'pos' })` and `i18n.t('errors.noCashRegister', { ns: 'pos' })` in `paymentStore.ts` lines 165-178. These fire when the tenant lacks an active CASH payment method or active `cash_register` repository. The "station" phrasing may come from the translation file — verify exact key text.

**Fix (two-part):**
1. **Better error messaging:** Replace generic error with specific guidance: "No cash payment method found. Go to Settings → Payment Methods to create one." Same for cash register. Include a direct link/action to navigate to the relevant settings page.
2. **Onboarding checklist (web):** Surface missing configuration as a notification/action list in the web dashboard so users complete setup before using POS. (See Section 3.)

**Files:**
- `apps/pos/src/stores/paymentStore.ts` lines 151-213 — improve error messages with specific guidance
- `apps/pos/src/locales/*/pos.json` — update error message translations
- `apps/web/src/features/pos/organisms/PaymentPanel/PaymentPanel.tsx` — improve error toast in `handleQuickCheckout` (web POS equivalent)

### 1.4 Windows Fullscreen — Remove Taskbar and Title Bar

**Root Cause:** `tauri.conf.json` has `fullscreen: false` and no `decorations: false`. Runtime `setFullscreen(true)` doesn't remove window decorations on Windows.

**Fix:**
- Set `"decorations": false` in `tauri.conf.json`
- Enhance `App.tsx` fullscreen handler to also call `setDecorations(false)` via Tauri API
- Default to fullscreen on first launch (can be toggled in settings)
- **Important:** `decorations: false` removes the title bar on ALL platforms (including macOS during dev). Add a custom close/minimize control in the POS header bar, or make decorations conditional on fullscreen mode only (set at runtime via `setDecorations(false)` when fullscreen is enabled, not in static config).

**Files:**
- `apps/pos/src-tauri/tauri.conf.json` — keep `"decorations": true` (for dev), control at runtime
- `apps/pos/src/App.tsx` lines 151-164 — call `setDecorations(false)` + `setFullscreen(true)` when fullscreen setting is enabled

---

## 2. POS UX Overhaul

### 2.1 Main Layout Restructure

**Current:** Products left (flex-3), Cart right (flex-2). Controls stacked vertically (toggle, search, categories = 160-180px). Cart items squeezed. Payment section = 43% of cart panel.

**Target:** Cart left (35%), Products right (65%). Configurable via user setting. RTL locales auto-mirror (cart goes right, products go left). CSS logical properties throughout.

#### Cart Panel (35% width)
- **Header:** Compact — cart title + item count badge. Single line.
- **Items list:** `flex-1 overflow-y-auto`. Compact rows (48px height). Inline quantity badge. Item name + line total on one line. Tap to expand for modifiers/discount.
- **Totals:** Fixed at bottom. 3 lines max: Subtotal, Tax, Total (bold). Discount line only when active.
- **Buttons:** Two buttons side-by-side:
  - **PAY CASH** (flex-3): Large green button, shows total. Opens quick cash flow.
  - **More** (flex-1): Small dark button with split icon. Opens advanced payment.

#### Product Panel (65% width)
- **Top bar:** Single line containing:
  - Consumption mode toggle (Dine-in / Takeaway) — compact pill toggle, left-aligned
  - Search input — fills remaining width, right-aligned
- **Category tabs:** Horizontally scrollable pill buttons. Single row, never wraps.
- **Product grid:** 3-4 columns depending on screen width. Compact cards.

#### Configurable Cart Position
- User setting stored in `settingsStore` (desktop POS, persisted to localStorage): `cartPosition: 'start' | 'end'` (default: `'start'` = left in LTR)
- RTL locales (`ar`, `ar-TN`) automatically flip via CSS `dir="rtl"` + logical properties
- Manual override available for bilingual environments (Tunisia) — "layout lock" toggle in POS settings
- Use `flex-direction: row` / `row-reverse` based on setting, combined with `dir` attribute
- Use CSS logical properties throughout (`margin-inline-start`, `padding-inline-end`, etc.) instead of physical (`margin-left`, `padding-right`)

### 2.2 Quick Cash Payment Screen (Full-Screen Overlay)

Replaces the current `CashTenderedModal`. Note: two implementations exist in the desktop POS — `components/organisms/CashTenderedModal/CashTenderedModal.tsx` (uses `NumPad` component) and `components/pos/CashTenderedModal.tsx` (standalone). Verify which is actively imported before replacing.

**Layout:** Full-screen dark overlay, split into two panels.

**Left panel (40%):**
- Amount Due — large text (36px), white
- Cash Tendered — large text (28px), blue, updates as user types
- Change Due — prominent green box with large text (28px)

**Right panel (60%):**
- Smart denomination buttons: "Exact", and 2-3 common bills computed from total. Denominations must be currency-aware (EUR: 5, 10, 20, 50, 100; TND: 5, 10, 20, 50; GBP: 5, 10, 20, 50). Store denomination sets in a config map keyed by currency code. Show bills >= total, up to 3.
- Numpad: 3×4 grid + backspace. Keys minimum 64px height for touch.
- "Complete & Print Receipt" button — full-width green, 56px height.

**Behavior:**
- Cash payment method auto-selected (first method where `code === 'CASH'` or `is_physical && !has_maturity`)
- Default cash register auto-selected (first active `cash_register` repository)
- If no cash method/register configured, show inline error with link to settings (not a toast)
- "Exact" button pre-fills exact amount and immediately shows "Complete" as ready
- After completion: success screen with change amount. Tap anywhere or "New Sale" button to return to empty cart. Auto-returns after 5s as fallback (cashier may need time to count change).
- "Reprint" button available on success screen

### 2.3 Advanced Payment Screen (Full-Screen Overlay)

Refactor existing `AdvancedPaymentsModal` for better space use. Keep fullscreen.

**Left panel (40%):**
- Order summary (item list)
- Applied payments list with remove button
- Remaining balance — prominent display

**Right panel (60%):**
- Payment method grid — large touch buttons with distinct colors per method (future: configurable colors)
- Amount input for selected method
- Repository selector (if applicable)
- "Add Payment" button
- "Complete Sale" button (enabled when remaining = 0)

### 2.4 Spacing & Touch Optimization

| Element | Current | Target |
|---------|---------|--------|
| Cart row height | 80-100px (p-3/4 + space-y-3) | 48-52px |
| Cart row spacing | space-y-3 (12px) | space-y-1 (4px) with border dividers |
| Payment section | ~210px (43% of cart) | ~120px (totals + 2 buttons) |
| Product card image | h-32 (128px) | h-20 (80px) |
| Product grid gap | gap-4/6 (16-24px) | gap-2 (8px) |
| Search + toggle | 2 lines (100px) | 1 line (~48px) |
| Category buttons | flex-wrap multi-line | horizontal scroll, single line |
| Main padding | p-4/6 (16-24px) | p-2 (8px) |

### 2.5 Icons

Use Lucide React icons exclusively (already in the design system). No emoji. Examples:
- Cash: `Banknote`
- Card: `CreditCard`
- Split: `Split` or `ArrowLeftRight`
- Back: `ArrowLeft`
- Backspace: `Delete`
- Success: `CheckCircle2`
- Print: `Printer`

---

## 3. Onboarding Setup Checklist (Web Only)

### 3.1 Overview

New tenants need a guided setup before POS works. Surface this as a notification/alert in the web dashboard, not a separate wizard page.

### 3.2 Checklist Items

| Step | Required? | Check Condition |
|------|-----------|-----------------|
| Company Information | Yes | Company name, address, tax ID filled |
| Tax Configuration | Yes | At least one active tax rate exists |
| Payment Methods | Yes | At least one active payment method exists |
| Payment Repositories | Yes | At least one active cash_register repository exists |
| POS Terminal | No | At least one terminal configured |
| First Product | No | At least one active product exists |

### 3.3 Implementation

- **Backend:** New endpoint `GET /api/onboarding/status` returns checklist with completion status per item. Use Application-layer service with constructor-injected repositories (hexagonal architecture). Query other modules via `Shared/Contracts/` interfaces only — do not import models directly from Tax, Treasury, or Catalog modules.
- **Frontend (web):** Dismissible alert banner on dashboard + dedicated `/settings/setup` page
- **Notification:** Use existing notification system to create a persistent "Complete your setup" notification
- **POS blocking:** If required steps incomplete, POS page shows setup prompt instead of empty/broken state
- Each checklist item links directly to the relevant settings page
- Use a PHP Enum for checklist step keys (per CLAUDE.md Rule #9)

### 3.4 Scope Limitation

Web dashboard only. Desktop and mobile apps show a message: "Complete setup in the web dashboard at [URL]" if configuration is missing. No native onboarding wizard for tomorrow's deployment.

---

## 4. Out of Scope (Future)

- Dark mode theme (research done, implement later)
- Color-coded payment method buttons (per-method configurable colors)
- Enhanced contrast/visual hierarchy audit
- Desktop/mobile native onboarding wizard
- Receipt RTL layout for Arabic
