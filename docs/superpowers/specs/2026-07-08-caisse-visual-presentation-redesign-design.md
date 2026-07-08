# Design Spec — Caisse Visual & Presentation Redesign (Track B, Spec 1)

> **Status:** DRAFT for owner review · 2026-07-08
> **Scope:** Frontend only — Tauri POS app `apps/pos`. No backend, no sync, no SQLite changes.
> **Execution gate:** ⛔ **DO NOT EXECUTE** until the other in-flight POS session finishes its visual revamp. This session produces **spec + implementation plan only**. Whoever picks this up rebases onto the latest `origin/dev` (which will include that session's work + Track A enrichment images) *before* starting.
> **Companion doc:** Spec 2 (batch expiry, offline-first, cross-layer) — *to be written in a later session* (`docs/superpowers/specs/2026-…-caisse-batch-expiry-offline-design.md`). This spec only *reserves visual space* for its surfaces; it does not depend on Spec 2 to ship.
> **Supersedes/extends:** [`2026-06-27-pos-caisse-redesign-design.md`](./2026-06-27-pos-caisse-redesign-design.md) (theme foundation now shipped to `origin/dev`). This spec **reverses one locked decision** from that track — see §3.1.
> **Brainstorm record:** `.superpowers/brainstorm/` mockups (research → display model → data layer → batch tiers → decomposition → color strategy → design summary).

---

## 1. Problem & intent

The owner audited the live caisse (screenshots + code) and found it *"rough, cramped, not contrast-friendly for a 15" touchscreen."* Root causes are code-grounded, not cosmetic:

- **Green overload (the crux).** This parapharmacy tenant's *accent* is green (`#1f8a5b`). But `--success` and `--stock-ok` are the **same hex** (`index.css:77/94/219`), and **prices are drawn in `text-accent-strong`** (`ProductCard.tsx:409`) — which directly **violates the codebase's own rule** *"prices use `text-ink`, never accent"* (`designTokens.ts:14`). So four different meanings — selected, price, confirmed-money, in-stock — all render as one green. Everything blends; contrast suffers (green ≈ 4:1 vs ink ≈ 15:1).
- **Overflow / clipping bugs.** Customer chip collides when a customer has points + balance (`CartCustomerControl.tsx:33`); "Rappeler" clips to "Rapp" (`QuickActions.tsx` + base `Button.tsx` lacks `whitespace-nowrap`); cart icon clipped at the screen edge.
- **Inconsistency.** Stock badge shows "Stock : 12" (with number) vs "Stock faible" (no number) (`StockBadge.tsx`); duplicate "Caisse" nav labels (`NavRail.tsx`); touch targets range from 72px nav to sub-30px header/eye buttons; 10px washed-out brand labels; a hardcoded lavender placeholder bypassing tokens (`ProductThumb.tsx:60`).
- **Density.** Unnecessary whitespace in places; sections don't read as clearly-separated blocks.
- **Scale reality.** Tenants carry **5,000–10,000 SKUs**. Tile-only browsing does not scale; the workflow is **search + barcode scan**, and the checkout must present a **touch-usable dense table** for the long tail plus a **desktop-dense** variant, alongside the visual tiles for the curated top sellers.

**Intent:** a calm, high-contrast, readable caisse where **one color = one job**, sections are visually distinct, spacing is purposeful, and the operator can switch product-presentation density to fit the moment — all while preserving 100% of existing behavior (offline-first, fiscal-at-edge, shifts, sync).

## 2. Goals / non-goals

**Goals**
1. Fix the color grammar: prices → ink; green means only "good/available/money"; blue means all interaction.
2. Clear **section separation** (owner's stated top priority) and a **navy top bar** matching the logo.
3. **Purposeful spacing** + a consolidated **button size/style system** (fixes label clipping at the root).
4. Three switchable product-presentation densities: **Vitrine** (tiles) · **Liste** (touch table) · **Tableau** (desktop dense).
5. Surface **equivalents/complements** inline in the search/scan result flow (data already exists).
6. Keep and enrich the **detail drawer** ("i"/eye) — Routine · Équivalents · Compléments (exist) + Stock & lots + Autres officines.
7. Resolve every audit bug listed in §1.
8. AAA 7:1 body contrast (retail glare); 48px touch floor / 64px primary.

**Non-goals (this spec)**
- No overall layout/composition change — owner is happy with the two-pane structure (header · nav rail · product area · cart · payment footer).
- No backend / sync / SQLite change. Batch-expiry data pipeline and the near-expiry chip **plumbing** are Spec 2. This spec only reserves the chip's visual slot + defines its look.
- No new business logic; restyle organisms in place, preserve data flow (parity guardrail from the prior track).
- RTL/Arabic out (French launch).

## 3. Color system — Strategy A ("one interactive color") · SETTLED with owner

| Role | Color | Token | Where |
|---|---|---|---|
| **Interaction + selection** | Ocean blue `#1a6fb5` | `--action` / `--color-action*` | Primary CTA, selected/active card, active nav item, in-cart chrome, focus ring |
| **Goodness (stock / money)** | Green `#1f8a5b` | `--success*`, `--stock-ok*` | In-stock badge, confirmed-money toast, Encaisser button |
| **Prices / data** | Ink `#14283f`/`--text` | `--color-ink` | All monetary display (per `designTokens.ts:14`) |
| Warning / low stock | Amber `#c77e00` | `--warning*`, `--stock-low*` | Low-stock badge, warnings |
| Danger / rupture | Red `#d64545` | `--danger*`, `--stock-out*` | Rupture badge, destructive actions |

Mental model for staff: **"blue = you can touch this; green = this is good."**

### 3.1 Reversal of a prior locked decision (must be explicit)
The 2026-06-28 track locked: *"CTA stays blue; **accent is a SEPARATE swappable highlight dimension** (selected pills, active nav, in-cart)."* Strategy A **retires that for the caisse**: selection/active/in-cart move to **`--action` (blue)**. Consequence: the `data-accent` swap (`index.css:206-249`) becomes **cosmetic** in the caisse (no selection chrome reads from it). We must **not** leave a half-wired accent system:
- Repoint caisse selection/active/in-cart styles from `accent*` → `action*` (real changes in `ProductCard.tsx:215,244,253,346`, nav active state, in-cart tint).
- `*:focus-visible` outline (`index.css:389`) → `--action` (was `--accent`).
- Keep the `data-accent` machinery in place (other tenants/verticals may still use it) but document that the parapharmacy caisse does not drive selection from it. Decide per §11-Q1 whether to also point `--accent` at `--action` for this tenant so any stray `accent` usage degrades gracefully.

### 3.2 De-collision at the token layer
`--success` and `--stock-ok` remaining the same hex is acceptable **because they never carry different meanings on the same element anymore** (price is off green; selection is off green). Keep the distinct `--stock-*` token *family* (documented exception) so a future tenant can retune stock hues independently of money-green. No hex change required by this spec; the fix is **usage**, not palette.

## 4. Composition, section separation & top bar

Structure unchanged; **legibility of the blocks** is the deliverable.

- **Top bar → navy.** Header (`Header.tsx`) background = `--pay-navy #14283f` (the logo dark blue), text `--pay-navy-fg`. This is the strongest section anchor and instantly separates chrome from the light work canvas. Re-verify every header child on navy: StatusPill variants, sync/online dot, StockFreshness, operator Avatar, manager-PIN entry, divider atoms — each must clear AAA on navy or switch to an inverse token. (Do **not** hardcode; add inverse-on-navy token variants where needed.)
- **Section blocks.** Each region reads as a distinct block via **elevation + border + the navy anchors**, not via color noise:
  - Header: navy.
  - Nav rail: `surface-raised` with a `border-strong` seam against the canvas.
  - Product area: `surface-canvas` (recessed) — the "work surface."
  - Cart panel: `surface-raised` + `border-strong` + subtle shadow — a clearly-lifted column.
  - Payment footer (`PaymentSummary.tsx`): navy or a strong top border, echoing the header to "bookend" the cart.
- **Cart icon clip:** ensure the cart column and its header have inner padding so the icon never sits under the screen edge (`TransactionCart.tsx:137` wrapper).

## 5. Spacing & density system

- **One spacing scale**, 4px-based (4·8·12·16·20·24). Audit the caisse for whitespace that doesn't aid grouping and tighten it; *keep* spacing that separates sections (§4).
- Primary offenders to tighten: toolbar, cart line rows, card padding, header right-zone clusters. Target: fit more product rows/lines per screen without crowding.
- Document the scale in `design-language.md` and (where reusable) as spacing helpers; enforce via review, not new hardcoded values.

## 6. Button system

Consolidate on the existing `components/ui/Button` voices (primary/confirm/secondary/ghost/destructive; `designTokens.ts`). Two concrete changes:

1. **Fix the base `Button` so labels never clip** (root cause of "Rappeler"→"Rapp"): add `whitespace-nowrap` + `min-w-0` support + `truncate` opt-in, and make horizontal padding size-driven rather than a fixed `px-4`. This fixes `QuickActions.tsx` (`flex-1` buttons) without per-call hacks and removes the reliance on `overflow-x-auto` clipping.
2. **Size scale keyed to ergonomics** (revises the old sm36/md44/lg56): **sm 40 · md 48 (touch floor) · lg 64 (primary/Encaisser/numpad)**. Every tappable control ≥ 48px; the one strong per-view action is lg. Icon-only header/eye buttons currently sub-30px → raise to ≥ 40px hit area (visual glyph can stay small; expand the tap target).

## 7. Unified stock badge · PROPOSED default (owner may retune wording)

One pill format everywhere (tile · row · desktop), color = severity, **number always shown when known**:

- `Stock 12` — green (`stock-ok`)
- `Stock 3` — amber (`stock-low`)
- `Rupture` — red (`stock-out`, the only wordless one, qty = 0)

Fixes the "12 vs wordless faible" inconsistency (`StockBadge.tsx`). Threshold for low remains the current ≤10 (`ProductCard.tsx:121,129`). Location-aware path (`available`) and legacy path (`stock_quantity`) both render the same format.

## 8. Product presentation — three densities

Product data is served identically to all three modes (grounded in `POSProduct`, `types/product.ts:50`). Modes differ only in layout/density.

### 8.1 Modes & switcher
- **Vitrine** — visual tiles, image + brand forward (real images now exist via Track A). Curated top sellers / a category / promos.
- **Liste** — touch table: rows ≥ 48px, thumbnail · brand+name · price (ink) · stock badge · large `+`. The **search/scan workhorse** for the 5–10k catalog. **Default on touchscreen.**
- **Tableau** — desktop dense: more rows, more columns (code · product · category · stock (+incoming) · price), keyboard navigation (↑↓/⏎). **Default on mouse/desktop.**

Switcher = a segmented control (`components/ui/SegmentedControl`) in the product toolbar. Selection **persists per device** in `settingsStore` (extend the existing `displayMode`; today `'grid'|'visual'` → `'vitrine'|'liste'|'tableau'`, with a migration mapping `grid→liste`, `visual→vitrine`). Auto-pick initial mode by input type/viewport (coarse-pointer/touch → Liste; fine-pointer/desktop → Tableau), user-overridable. **Retire** the old image-less "compact grid" (folds into Liste). Reconcile the known dual-source-of-truth for display mode (`pos-display-mode` localStorage vs `settingsStore.displayMode`) into the store as the single source.

### 8.2 Field → mode matrix (grounded; defaults)
| Field (source) | Vitrine | Liste | Tableau |
|---|:--:|:--:|:--:|
| Image (`image_url`) | ✓ | ✓ | optional |
| Brand (`brand_name`) | ✓ | ✓ | ✓ |
| Name (`name`) | ✓ | ✓ | ✓ |
| Price (`sale_price`, ink) | ✓ | ✓ | ✓ |
| Stock (`available`) | ✓ | ✓ | ✓ |
| Incoming (`incoming_*`) | opt | opt | ✓ |
| SKU/code (`sku`) | — | opt | ✓ |
| Barcode | — | opt | opt |
| Category | opt | opt | ✓ |
| Skin type (`suitable_skin_types`) | opt | opt | opt |
| VAT (`tax_rate`) | — | — | opt |
| Variants/modifiers (`has_variants`) | ✓ | ✓ | opt |
| Equivalents count (`equivalent_product_ids`) | opt | opt | opt |
| Near-expiry chip | **slot reserved (Spec 2)** | slot reserved | slot reserved |

Owner-selected optional toggles from brainstorm to include as **settings** (default off unless noted): SKU on Liste rows; skin-type dots on Vitrine tiles; an "N équivalents" badge on tile+row; regulatory-code search (deferred — needs backend projection, flagged §11-Q2).

### 8.3 Search / scan result flow (equivalents surfacing)
Data exists: `equivalent_product_ids`, `complement_product_ids` on `ParapharmacyMeta` (`product.ts:11-12`), resolved via `productStore.getByIds` (`productStore.ts:49`); backing `product_equivalents` table with `equivalence_type`. Currently only shown in the detail drawer (`ProductDetailDrawer.tsx:80-93`). **Surface it in the main flow:**
- On a resolved search/scan (matches on name·sku·barcode — `ProductGrid.tsx:276`, `resolveScannedCode.ts`), render **matched product first**, then a labelled **Équivalents** group (in-stock alternatives), then **Compléments / routine**.
- If the exact match is out of stock, it still shows (with price + "Rupture") but its `+` is **disabled** (respecting the existing `hardBlockOutOfStock` policy) — steering the eye to in-stock equivalents. This is the parapharmacy value moment.
- Gate the equivalents/complements groups on `hasModule(companyConfig,'Merchandising')` + non-null metadata (same gate the drawer uses).

## 9. Detail drawer ("i"/eye) — kept + enriched
Preserve the eye affordance and `ProductDetailDrawer` (`components/pos/ProductDetailDrawer.tsx`). Tabs:
- **Routine · Équivalents · Compléments** — already exist; restyle to the new system.
- **Stock & lots** — NEW. Batch/expiry list. **Data + wiring land in Spec 2** (reuses existing online `GET /pos/products/{id}/batches`). This spec defines the tab shell + styling and an empty/offline state; it renders no batch data until Spec 2.
- **Autres officines** — NEW. Branch stock. Needs a branch-stock endpoint (Spec 2 optional). This spec defines the tab shell only.

Raise the eye/`ViewDetailsButton` hit target to ≥ 40px (§6).

## 10. Audit bug fixes (folded into this spec)
1. **Customer chip overflow** (`CartCustomerControl.tsx:33`): allow wrap/priority-truncation; loyalty badge + wallet + detach must not all be `shrink-0` on a non-wrapping row. Give the whole control a coherent min-width strategy so points+balance never collide.
2. **"Rappeler" clip** — fixed at the `Button` root (§6) + `min-w-0` on `QuickActions` buttons.
3. **Touch-target consistency** (§6): ≥48px tappable, ≥40px icon hit area.
4. **Duplicate "Caisse" nav labels** (`NavRail.tsx`): rename one (e.g. top nav "Caisse" vs bottom shift/wallet item → "Fond de caisse" / "Session").
5. **Low-contrast 10px brand labels & lavender placeholder** (`ProductThumb.tsx:60` hardcodes `bg-[#eef3f8] text-[#5e6670]`): move to tokens, raise contrast/size.
6. **Cart icon clipped at screen edge** (§4).

## 11. Resolved decisions (owner-approved 2026-07-08)
- **Q1 → RESOLVED:** point `--accent` → `--action` (blue) for this tenant so any stray `accent` usage degrades safely to blue; keep an explicit brand-green only where deliberately intended (e.g. wordmark).
- **Q2 → RESOLVED (deferred):** regulatory-code (CIP/PPN) search/display is **out of Spec 1** — it needs a backend projection into the POS product payload. Log as a backend ticket.
- **Q3 → RESOLVED:** stock-badge wording is `Stock 12` / `Stock 3` / `Rupture` (as in §7).
- **Q4 → RESOLVED:** payment footer is **full navy**, bookending the navy header for section clarity (as in §4).

## 12. Ground-truth component map (real files to touch)
- Tokens: `src/index.css`, `src/lib/designTokens.ts`, `docs/.../design-language.md`
- Buttons: `src/components/ui/Button.tsx`
- Header / nav / footer: `src/components/Header.tsx`, `src/components/NavRail.tsx`, `src/components/pos/PaymentSummary.tsx`
- Customer chip: `src/components/customers/CartCustomerControl.tsx`, `CustomerLoyaltyBadge.tsx`
- Quick actions: `src/components/molecules/QuickActions/QuickActions.tsx`
- Stock badge / thumb: `src/components/ui/StockBadge.tsx`, `src/components/ui/ProductThumb.tsx`
- Product card / grid: `src/components/molecules/ProductCard/ProductCard.tsx`, `src/components/organisms/ProductGrid/ProductGrid.tsx` (+ `cardSizing.ts`)
- New density: a `Liste` (touch table) + `Tableau` (desktop dense) view under ProductGrid (shared data, new layout components)
- Cart: `src/components/pos/TransactionCart.tsx`, `CartLineItem`
- Detail drawer: `src/components/pos/ProductDetailDrawer.tsx`
- Search/scan: `src/components/organisms/ProductGrid/ProductGrid.tsx:276`, `src/lib/scan/resolveScannedCode.ts`, `src/pages/HomePage.tsx:433-573`
- Settings: `src/stores/settingsStore.ts`, `src/pages/SettingsPage.tsx`
- Screen assembly: `src/pages/HomePage.tsx`
- Visual harness: `src/pages/ThemePreviewPage.tsx` (`/theme-preview`)

## 13. Testing & verification
- **Parity is law.** Restyle in place; preserve Zustand stores, hooks, Tauri IPC, offline/sync/shift/receipt. No business-logic rewrites.
- **Vitest** (jsdom) for logic/format/state: badge format, mode switch + persistence + migration, search-result grouping (matched→equiv→compl), button label non-clip, out-of-stock `+` disabled.
- **Visual verification is mandatory** (jsdom misses layout — the P3 card-clip bug shipped because it was only jsdom-tested). Extend `/theme-preview` with seeded Vitrine/Liste/Tableau + a search-result-with-equivalents fixture. Drive with `playwright-core` + system Chrome (`chromium.launch({channel:'chrome'})`) per the repo recipe; verify both themes, 15" viewport, real Track-A images, AAA contrast spot-checks. Authed shell needs online+login (offline gate blocks headless) → `/theme-preview` is the auth-free harness.
- **Token/i18n guards:** ESLint color guard clean (tokens only, no hardcoded hex/Tailwind palette); all copy via `t()` (`locales/fr/pos.json`).
- **Watch for vitest zombie worker pools** after any hung run (kill leftover `node (vitest` workers).

## 14. Risks & coordination
- **Concurrent POS session** — do not execute until it lands; rebase onto latest `origin/dev` (incl. Track A images + that session's revamp) first. If that session also restyled these files, reconcile before starting; treat its output as the new baseline.
- **Display-mode dual source of truth** — must be reconciled into `settingsStore` or the switcher will desync (§8.1).
- **Navy header contrast** — every header child must be re-checked on navy; easy to ship an AAA regression.
- **Virtualizer + new views** — ProductGrid uses `measureElement` dynamic rows (the P3 fix); Liste/Tableau must keep content-sized measurement, not reintroduce fixed row heights.
- **Scope creep** — batch-expiry data, near-expiry chip plumbing, branch-stock endpoint, regulatory-code search are **out** (Spec 2 / tickets). This spec ships visuals + the equivalents surfacing that needs no backend.

## 15. Deferred to Spec 2 (batch expiry, offline-first)
Near-expiry chip data pipeline (backend earliest-expiry aggregate → sync projection → SQLite v60 → `POSProduct` type → chip), the drawer Stock & lots batch data, and the branch-stock "Autres officines" endpoint. This spec reserves the chip's visual slot (§8.2) and the two drawer tab shells (§9).
