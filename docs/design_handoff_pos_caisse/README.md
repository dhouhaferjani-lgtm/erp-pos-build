# Handoff: IZI POS — "Caisse Parapharmacie" redesign

## 1. Overview

A visual + UX redesign of the **POS sell flow** (`apps/pos`) for the **parapharmacy
vertical**, first market **Tunisia** (French UI, cash-first). It restyles the existing
two-pane sell screen and all its modals, adds a left **nav rail** tying together four
destinations (Caisse / Clients / Rapports / Caisse-Shift), introduces a full **Light +
Dark** theme, parapharmacy merchandising (brand/skin/routine filters, advice bar,
equivalents & complementary upsell), and tactile touch micro-interactions.

The single source of truth is the interactive reference:
**`IZI POS - Caisse Parapharmacie.dc.html`** — open it in any browser. The brand
foundation is **`IZI POS Design System.dc.html`** (also included).

## 2. About the design files

The HTML files in this bundle are **design references**, not production code. They are
built as self-contained prototypes to demonstrate the intended look, layout, states, and
behavior at full fidelity. **Do not ship the HTML or copy its demo logic.** Your task is
to **recreate these designs inside the existing `apps/pos` React + TypeScript codebase**,
using its established **atomic-design** structure, state stores, hooks, i18n, routing,
and Tauri IPC. Where the codebase already has a component for a screen (it does for
almost all — see §4), **restyle/re-lay-out that component in place** and keep its real
data flow.

## 3. Fidelity

**High-fidelity.** Final colors, typography, spacing, radii, and interaction states are
all specified below and demonstrated in the reference. Recreate pixel-accurately using
the codebase's libraries, mapping the mock's placeholder icons/fonts to the project's
icon system and configured typefaces.

## 4. Component map → atomic design

The repo (`apps/pos/src/components`) already uses atoms / molecules / organisms / pos /
settings. The mock's surfaces map to existing folders — restyle these, don't recreate:

| Mock surface | Existing component (restyle in place) |
|---|---|
| Sell-screen cart panel | `organisms/TransactionCart` |
| Payment summary / totals | `organisms/PaymentSummary` |
| Product grid (visual + compact) | `organisms/ProductGrid` + `molecules/ProductCard` |
| Product detail (tabs) | `organisms/ProductDetailDrawer` |
| Full-screen cash payment | `organisms/CashPaymentScreen` + `organisms/QuantityNumpad` |
| Advanced / mixed payment | `organisms/AdvancedPaymentsModal` |
| Card terminal sub-flow | `organisms/CardPaymentModal` |
| Checkout success | `organisms/CheckoutSuccessModal` |
| Cart-level discount | `organisms/DiscountModal` |
| Per-line discount | `organisms/LineDiscountModal` |
| Hold / recall parked sales | `organisms/HeldTransactionsModal` |
| Returns / void | `organisms/VoidReturnModal` |
| Reports menu | `organisms/ReportsMenu` |
| X report (mid-shift read) | `organisms/XReportModal` |
| Z report / shift close | `organisms/ZReportModal` + `organisms/CloseShiftModal` |
| Cash in/out / drop-to-safe | `organisms/CashDrawerModal` |
| Modifier selection | `organisms/ModifierSelectionModal` |
| Header (terminal, shift, operator, sync) | `components/Header.tsx` |
| App shell | `components/AppShell.tsx` |
| Numeric/PIN entry | `components/PinPad.tsx`, `organisms/QuantityNumpad` |
| Smart prompts toast | `organisms/ToastSmartPrompts.tsx` |

New shared **atoms/molecules** to factor out while restyling (used everywhere in the
mock): `Button` (primary/secondary/ghost/icon), `IconButton`, `Pill`/`Chip` (incl.
removable filter chip), `SegmentedControl` (period toggle, view toggle), `StockBadge`
(En stock / Stock faible / Rupture), `Avatar` (customer/operator initials),
`ProductThumb` (tinted initials tile), `Stepper` (qty −/＋), `Toggle` (consent), `Tab`
(detail tabs), `KpiCard`, `BreakdownBar`, `Numpad`, `ModalShell` / `FullScreenSheet`,
`Toast`, `Drawer`.

## 5. Screens & layout

Canvas is **1920×1080** (15"+ touchscreen). Touch targets ≥ 44px. Two main zones with a
**12px gutter** on an app-bg base; the cart and product area are elevated panels
(`--r-panel` radius, 1px `--border`, subtle shadow). The **nav rail (88px)** sits on the
**opposite side from the cart** (cart left ⇒ rail right, and vice-versa).

### 5.0 App shell + nav rail
- Rail: brand mark, 4 destinations (Caisse, Clients, Rapports, Caisse[Shift]) as
  stacked icon+label buttons; active item uses `--accent-tint` bg + accent fg. Theme
  sun/moon toggle pinned bottom.
- Header (62px, `--surface`, bottom border + 2px elevation): left = brand + `Caisse 1` +
  online dot; center = shop name + date (mono); right = grouped, divider-separated
  clusters — shift chip (`Service #42 | Fond 200,000`), sync icon; operator avatar +
  name + switch; lock / reports / settings ghost icon buttons. **Use vertical dividers
  between groups, ghost icon buttons (no boxed chrome).**

### 5.1 Sell screen — Caisse
- **Cart panel (≈460px):** title + item-count badge + "Vider"; customer/loyalty card
  (tap → picker); quick actions row (Remise, En attente, Reprendre+count); scrollable
  **compact cart lines**; summary (Sous-total, Remises produits, Remise panier, dont
  TVA 19%, Total big mono) + checkout buttons.
  - **Cart line (compact, expandable):** avatar, brand (caps), name, `×qty` chip, line
    total (struck original if discounted), remove ✕, chevron. **Tapping the row body
    expands** it (does NOT increment) to reveal: qty stepper (−/＋), per-line discount
    button, fiche shortcut. Collapsed by default. Rationale: tap-to-increment surprises
    cashiers; visible stepper on expand is the researched pattern.
- **Checkout buttons (cash-first):** big **Encaisser** (accent) + **Mixte** (secondary,
  opens advanced payments). No standalone card button — card lives inside Mixte (Tunisia
  is ~99% cash).
- **Product toolbar:** search (left), **Filtres** button (badge w/ active count), **Top
  ventes** toggle, view toggle (Vignettes / Liste compacte). Below: horizontal
  **category pills** (multi-select; "Tous" clears). Active **filter chips** row appears
  when filters set (removable ✕, "Tout effacer", live result count). **Skin-type advice
  bar** (parapharmacy): "Conseil · Type de peau" + skin pills + result count.
- **Product grid:** 6 cols dense / 5 comfortable (density tweak); compact list = 5/4.
  - **Visual card:** tinted initials thumb (88px), info-eye (top-left, opens fiche —
    works even out-of-stock), price (mono), `StockBadge`. **In-cart treatment** (see
    §5.7).
  - **Compact card:** no thumb — brand, name, price, stock, info-eye, in-cart badge.
  - Out-of-stock: dimmed + "Rupture" overlay, not addable (but fiche still openable).

### 5.2 Payment flows
- **Cash (full-screen):** left navy summary (Total à payer big, Montant reçu, Monnaie à
  rendre panel that greens when covered); right large numpad + quick amounts
  (Exact/50/100/200) + Valider. Full-screen for touch.
- **Advanced / mixed (full-screen, 3 columns):**
  - **Left — method + config:** step ① method list (Espèces, Carte bancaire, Chèque,
    Virement, Bon/Avoir). On select, show **repository** picker (caisse / coffre /
    compte bancaire / wallet) — **auto-selected when only one is compatible, required
    when several** (real `getCompatibleRepositoryTypes` rule). Reference field for
    chèque/virement/carte; card last-4 for card/transfer. "Ajouter le paiement".
  - **Center — amount + numpad:** step ② amount shown large; selecting a method
    **pre-fills the remaining as an editable suggestion** (accent color + "tap to
    replace" hint) — first digit replaces it. "Payer le restant" pill.
  - **Right — total + lines + complete:** step ③ navy total card, accumulating list of
    payment **lines** (method · repository · ref/last-4 · amount, each removable), Total
    perçu / Restant / Monnaie à rendre, **Finaliser** (enabled only when fully covered).
  - **Card sub-flow:** adding a Carte line opens the **terminal** (idle → "Transaction
    en cours…" spinner → "Paiement approuvé" ✓) then drops the line back into the
    advanced list. Standalone cash/mixed completion routes to success.
- **Checkout success:** confirmation with ticket number, total, règlement label, change
  to return; actions: print ticket, new sale.

### 5.3 Cart-adjacent modals
- **Line discount:** product name + grid of % options (Aucune/5/10/15/20/25).
- **Cart discount:** toggles a cart-wide % (shown as its own summary line).
- **Hold:** parks current sale (customer + items + discounts), clears till.
- **Recall:** list of parked sales (customer, item count, total, time-ago) → restore or
  delete each.
- **Customer picker:** searchable (name/phone) list, "Client de passage" option, active
  highlighted, "+ Nouveau client".
- **Add customer:** name, phone, email, skin type, marketing-consent toggle; save +
  auto-select.

### 5.4 Returns (`VoidReturnModal`)
Select a past transaction → choose which lines/quantities to return → optionally add
replacement items → settle the net (refund or balance due) through the same payment
flow. Mirror the existing return logic exactly.

### 5.5 Reports / Shift
- **Rapports page:** KPI cards (Ventes, Transactions, Panier moyen) + **payment-method
  breakdown** bars (Espèces/Carte/Chèque/Mixte) + filterable **ticket list** (period
  segmented control Today/7j/30j, payment filter, search by ticket/client).
- **Caisse & Shift page:** left = **Rapport X** (service header, KPIs, breakdown,
  "Imprimer le rapport X"); right = **Clôture Z** — count cash, reconcile vs expected
  (opening float + cash sales), live écart (surplus/manque, color-coded), "Clôturer le
  service".
- **Cash movements** (`CashDrawerModal`): deposit / payout / drop-to-safe with amount +
  reason; feeds the Z reconciliation.

### 5.6 Customers page
Browse/search customer list → detail (contact, loyalty tier + points, total spend +
visits, store credit, skin type, advice note, **purchase history** of past tickets) →
edit. See §8 — may be a new surface.

### 5.7 In-cart indicator & micro-interactions
- **In-cart product card** (visual signal beyond shadow): full **accent outline** +
  **accent-tinted background** + a **3px top accent bar** + a **✓ check inside the qty
  badge**. Reads at a glance in both themes.
- **Tap pulse:** adding an item plays a ~420ms `ezTap` micro-animation on the card
  (scale 1 → .95 → 1.015 → 1 with an accent ring flash) so the tap is unambiguous.
  Active-press also scales the card to .96.
- Modal transitions: overlay fade 160ms; sheet rise+scale 200–220ms
  `cubic-bezier(.2,.8,.3,1)`; drawer slide-in 240ms; toast rise 220ms; spinner 0.8s
  linear.

## 6. Design tokens

**Typography** — Montserrat (700/800, headings & brand), Public Sans (400–700, UI/body),
IBM Plex Mono (numbers, prices, IDs, totals). Min text 24px equivalents not applicable
(screen app) but body ≥ 12.5px; prices 15–60px mono.

**Currency** — TND: `1 234,500 DT` (3 decimals = millimes, space thousands, comma
decimal).

**Accent options** (tweakable; UX-led, not brand-locked):
| Name | accent | strong (hover) | tint | ring |
|---|---|---|---|---|
| Orange (default) | `#EA661A` | `#D45915` | `#FEF3EC` | `rgba(234,102,26,.16)` |
| Green | `#1F8A5B` | `#19744C` | `#E6F4EE` | `rgba(31,138,91,.16)` |
| Blue | `#2B6CC4` | `#22569E` | `#E7F0FD` | `rgba(43,108,196,.16)` |
| Teal | `#0E8E80` | `#0B7468` | `#E0F2EF` | `rgba(14,142,128,.16)` |

**Surface tokens**
| Token | Light | Dark |
|---|---|---|
| `--app-bg` | `#EBEEF1` | `#080B11` |
| `--surface` | `#FFFFFF` | `#161D29` |
| `--surface-2` | `#F7F9FB` | `#1F2735` |
| `--bg-subtle` | `#F2F4F6` | `#0E1019` |
| `--hover` | `#F0F2F4` | `#28313F` |
| `--border` | `#DCE1E7` | `#2E3845` |
| `--border-soft` | `#EDF0F3` | `#232C39` |
| `--text-strong` | `#14283F` | `#F6F8FB` |
| `--text` | `#1C1E21` | `#D7DEE8` |
| `--text-muted` | `#5A5F66` | `#8C97A8` |
| `--text-faint` | `#9AA0A8` | `#5E6A7B` |
| `--pill-on` / `--pill-on-fg` | `#14283F` / `#FFFFFF` | `#EAF0F6` / `#080B11` |

**Semantic colors** (both themes): success `#1F8A5B` on `#E6F4EE`; warning `#8A5A00`/
`#C77E00` on `#FCF3DC`; danger `#D64545` on `#FBE9E9`; the navy payment panels use
`#14283F`. Category thumb tints: Visage `#EAF1F8`/`#3A5C88`, Solaire `#FEF1E6`/`#C85F18`,
Corps & Bain `#E6F4EE`/`#1F8A5B`, Cheveux `#F1ECF7`/`#8A4196`, Bébé `#FCEEF3`/`#C2557A`,
Compléments `#FAF2DC`/`#A9791A`, Hygiène `#E7F0FD`/`#2B6CC4`.

**Corner radius scale** (tweak: Rounded ↔ Sharp):
| Token | Rounded | Sharp |
|---|---|---|
| `--r-panel` | 16px | 4px |
| `--r-card` | 12px | 3px |
| `--r-tile` | 9px | 2px |
| `--r-ctl` | 11px | 4px |
| `--r-sm` | 8px | 3px |
| `--r-pill` | 99px | 4px |

**Density tweak:** grid 6 cols (dense) / 5 (comfortable); compact list 5 / 4.

**Spacing:** 12px panel gutter; 10–14px card padding; 8–12px control gaps. **Shadows:**
panel `0 1px 3px rgba(20,40,63,.05)`; card hover `0 4px 14px rgba(20,40,63,.10)`; modal
`0 28px 70px rgba(8,14,22,.5)`.

These are exposed as root **props/tweaks** in the reference: `theme` (Light/Dark),
`accent` (Orange/Green/Blue/Teal), `cornerStyle` (Rounded/Sharp), `cartSide`
(Left/Right), `denseGrid` (bool). Implement as real theme settings.

## 7. Functional-parity matrix (must stay 100% green)

Every existing POS capability must still work after the redesign. Verify each against
the current organism:

- [ ] Add product to cart (tap), increment/decrement, remove line, clear cart
- [ ] Per-line discount; cart-level discount; combined totals + TVA breakdown
- [ ] Hold (park) sale; recall/restore parked sale; delete parked sale
- [ ] Customer: select, search, "client de passage", create new, attach to sale
- [ ] Cash payment: tendered, change due, validate
- [ ] Advanced/mixed payment: multiple lines, **repository selection rules**, ref &
      card last-4, pay-remaining, finalize only when covered
- [ ] Card terminal flow (idle → processing → approved) inside advanced payments
- [ ] Checkout success: ticket #, print, new sale
- [ ] Returns/void: pick ticket, select return lines, add items, settle net
- [ ] Product detail: description, ingredients, indications, branch stock; (new tabs §8)
- [ ] Search; category filter; top-sellers; view toggle (visual/compact)
- [ ] Header: terminal, online/sync, shift chip, operator switch, lock, reports, settings
- [ ] Reports: X report read; sales history; payment-method breakdown
- [ ] Shift: open (float), close = Z report, cash reconciliation/écart
- [ ] Cash movements: in / out / drop-to-safe with reason
- [ ] Modifier selection (where products have modifiers)
- [ ] Offline-first behavior, sync, session/shift state, receipt printing — unchanged
- [ ] i18n (French strings via existing layer); TND money formatting

## 8. New surfaces / to confirm & implement (flag if missing)

- **Customer management page** (browse + detail + history + edit). Today the app has
  customer *selection*; a full management page may be new — implement or ticket.
- **Parapharmacy data + filters:** advanced **Filtres** drawer (brand / category /
  routine / skin type), **skin-type advice bar**, and product-detail **Équivalents /
  Compléments / Routine** tabs. Requires product data to carry: brand, skin-type,
  routine membership, and equivalent/complementary relations. Add fields/endpoints if
  absent.
- **Dark theme** — if the current build is light-only.
- **Cosmetic upsell** affordances in product detail (one-tap add of equivalents &
  complements) for revenue maximization.
- **Cart-line expand-to-reveal** interaction pattern (if the current cart shows all
  controls inline).
- **Nav-rail-opposite-cart** layout rule + per-terminal cart-side setting.

## 9. Assets

- `assets/pos-mark.svg`, `assets/pos-mark-dark.svg`, `assets/logo-mark.png`,
  `assets/logo-lockup-light.png`, `assets/logo-lockup-dark.png` — brand marks (in
  bundle). Map to the app's existing brand assets.
- All other icons in the reference are inline SVG **placeholders** — replace with the
  project's icon set.
- Product imagery is represented by **tinted initials tiles** (no real photos in the
  mock); wire to real product images where available, falling back to the tinted tile.

## 10. Files in this bundle

- `KICKOFF.md` — the prompt to start the Claude Code session.
- `README.md` — this document (self-sufficient spec).
- `IZI POS - Caisse Parapharmacie.dc.html` — the full interactive design reference.
- `IZI POS Design System.dc.html` — brand/design-system reference.
- `support.js` — runtime needed to open the `.dc.html` files in a browser.

> The `.dc.html` files are **design references**, not deliverables. Recreate them in
> `apps/pos` using the existing atomic components, stores, and IPC — preserving full
> functional parity (§7) and implementing/ticketing the new surfaces (§8).
