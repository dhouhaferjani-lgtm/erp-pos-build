# POS "Caisse Parapharmacie" (Pulse) — Design-vs-Implementation Gap Analysis

> Reference companion to `HANDOVER-pos-caisse-pulse-fidelity.md`. Grounded 2026-07-02
> against the synced design files and the current `apps/pos` code. Cite file:line when acting.

## Authoritative design source
All under `docs/design_handoff_pos_caisse/` (synced from claude-design project
"EZ POS Design System Setup" on 2026-07-02):

| File | What it is | In POS scope? |
|---|---|---|
| `IZI POS - Caisse Parapharmacie.dc.html` | **The touch-POS design** (sell screen, fiche modal, filtres, rapports, shift) | ✅ YES — the SoT for this handover |
| `IZI POS Design System.dc.html` | Tokens/atoms/typography/dark mode | ✅ reference (already ~implemented in `index.css @theme`) |
| `IZI POS - Add Product.dc.html` | Back-office product editor (full ERP sidebar) | ❌ `apps/web`, NOT POS |
| `IZI POS Product Page.dc.html` | Back-office product editor A/B layout | ❌ `apps/web`, NOT POS |
| `IZI POS - Add Product (Modern Directions).dc.html` | Exploratory style studies | ❌ not final |
| `Easy Pulse Dashboard.dc.html` | Back-office owner dashboard | ❌ `apps/web`, NOT POS |

**Taxonomy:** only the Caisse file is a Tauri-POS screen. The POS app is read-only for
products; product create/edit lives in `apps/web`. Do NOT build the back-office designs in `apps/pos`.

## ★ #1 divergence — Product detail: drawer → centered two-column MODAL (STRUCTURAL)
**Design** (`IZI POS - Caisse Parapharmacie.dc.html` ~lines 783–922, "Fiche produit"): a
**centered modal**, not a drawer. Overlay `inset:0; flex; align/justify:center; z-index:52`.
Panel **~1080×680** (`max-width:96%`, `max-height:92%`), `border-radius:20px`, big shadow,
`ezSheetIn` scale-in; **two columns**:
- **LEFT (344px):** hero tile (brand-tinted bg + product initials/image), "Rupture" badge if out,
  brand eyebrow, product name (Montserrat 21px), stock pill + category, divider, **Prix TTC**
  (large mono + "DT"), ref/barcode block, and a **full-width 54px "Ajouter au panier" primary button**.
- **RIGHT (flex-1):** tab bar **Détails / Routine / Équivalents / Compléments** (with counts) over a
  scrollable panel. *Détails* (default) = description + "Indications · bon pour" benefit chips +
  "Composition · ingrédients clés" chips + "Disponibilité · stock par agence" (cross-branch rows,
  "ICI" badge). *Routine* = numbered steps w/ add. *Équivalents*/*Compléments* = 2-col related-product
  grids with `+` add-to-cart.
- Opened from cart-line "Fiche produit" eye + each product card's eye (sets `detailId`, `detailTab:'details'`).

**Current** (`apps/pos/src/components/pos/ProductDetailDrawer.tsx`): a **320px right slide-over**.
Backdrop `fixed inset-0 z-40` (139–142); container `fixed inset-y-0 right-0 z-50 w-80 …translate-x` (146–151).
Renders centered image, name `text-xl`, price `text-2xl text-blue-600`, info rows (SKU/barcode/category/stock/tax),
`CrossLocationStockSection`, tabs Equivalents/Compléments/Routine. **No add-to-cart button; no Détails tab.**
Opened from `ProductCard.onViewDetails`.

**Gap:** wrong archetype (320px right drawer vs 1080×680 centered 2-col modal); missing left hero column;
missing prominent add-to-cart; missing Détails-default tab with benefit/ingredient chips + per-branch availability;
price color `text-blue-600` vs ink-strong mono + "DT". **This is the owner-flagged item.**

## Sell screen "Caisse" — close, mostly cosmetic
Current (`HomePage.tsx:1436` flex row w/ `cartPosition`; cart `min-w-[340px] flex-[4]` 1452; grid `flex-[7]` 1493;
`ProductGrid`, `TransactionCart`, `ProductCard`) is faithful. Gaps:
- **Cart width:** design **460px fixed**; code `min-w-[340px] flex-[4]`. Grid cols should be `--grid-cols:6` visual / `--list-cols:4` list.
- **Skin-advice bar:** current renders a 5-skin pill bar INSIDE `ProductGrid.tsx:591–619` (Task 27). **Design has none on the sell screen** — skin filtering is in the Filtres drawer ("Type de peau" + "Routine conseil", Caisse ~595–614). **OWNER DECISION: move into Filtres** (+ a settings toggle to enable/disable the skin feature).
- Cart-line steppers visible vs design's expand-only (intentional prior deviation — reconfirm).
- Product-card in-cart accent bar + qty-check badge + top-left fiche eye — verify vs design ~307–328.

## Reports / Shift — BUILT but mis-gated + Rapports routes to /sales
Owner reports "reports were 100% built but I (owner) only see sales." Grounded cause:
- `AppShell.tsx:46` — **`rapports: '/sales'`** — the Rapports nav routes to a plain **sales list**, not the designed KPI/analytics Rapports.
- Manager-only surfaces gate on **`isManagerRole(operator?.roles)`** (`AppShell.tsx:74`, `ReportsMenu.tsx:32`) — the **PIN operator's** roles, not the logged-in user. `isManagerRole` accepts `['manager','admin','owner']` (`lib/auth/roles.ts:7`). The active operator in the demo is **"Caissier Tunis Lac" (cashier)** → `isManagerRole=false` → reports hidden.
- POS pages: `ZReportListPage.tsx` (fiscal Z list, `/reports/z`, manager-gated at `AppShell.tsx:214`) + `ReportsMenu.tsx` overlay. **No KPI/analytics Rapports screen and no in-app cash-count Z-closure UI in the designed form.**
Design (Caisse ~447–564): full **Rapports** (KPI cards Ventes/Transactions/Panier moyen + payment-breakdown bars +
period/method filters + searchable ticket table) and **Shift** (X-report reading, cash-count Z-closure w/ expected/écart,
"Clôturer le service").
**Fix + build:** (1) ensure the owner/manager actually sees reports — resolve operator-role vs user-role (the owner should
operate as a manager/owner operator, or reconsider gating source); (2) point Rapports at the designed KPI screen (build it);
(3) build the Shift/Z-closure screen.

## Touchscreen optimization (cross-cutting, owner-flagged)
Several screens were built web-style, not touch-first. **`CustomersPage.tsx`** especially (list/detail with small
controls). Everything must meet touch floors (48px min, 64px primary), large tap targets, no hover-only affordances,
touch-friendly spacing/scrolling. Audit every POS screen against the design's density + the design-system touch rules.

## Design System — already implemented in POS tokens
`index.css @theme inline` already has the fonts, accent `#EA661A`, navy `#14283F`, status + distinct stock colors,
7 category tints (`--cat-*`), radius tokens (+ sharp variant), ergonomic type/touch scale, light/dark + accent/corner/density
via `data-*` (`lib/theme.ts`, `stores/settingsStore.ts`), `/theme-preview` gallery. The design-system's Forms/Tables
patterns are back-office-only — not needed in POS. No missing color/radius/font tokens for the sell screen.
