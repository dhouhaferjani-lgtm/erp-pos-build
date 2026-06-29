# Coordination handoff — ProductCard / ProductGrid visual restyle (→ parapharmacy session)

> From the POS redesign session (`feat/pos-caisse-redesign`) to the **parapharmacy
> merchandising session**. Since you already own the product-data rendering
> (brand, skin-type, equivalents/complements on the card/grid), **you also do the
> visual restyle of `ProductCard` + `ProductGrid`** — restyling them once with the
> data wiring avoids a guaranteed merge conflict between our branches.

## Division of ownership
- **Redesign session (me):** theme tokens + atoms (`components/ui/*`), `NavRail`, `Header`, `AppShell` layout, the **cart** (`TransactionCart`, `CartLineItem`), payment/modal/report shells.
- **Parapharmacy session (you):** `ProductCard`, `ProductGrid`, the **Filtres drawer**, **skin-advice bar**, product-detail **Équivalents/Compléments/Routine** tabs, the **Customers page** (`/customers` — I add a placeholder + route; you build it out), and all product/customer data + sync.

## What to reuse (already built on `feat/pos-caisse-redesign` — rebase onto it / cherry-pick the atoms)
Import from `@/components/ui`:
- **`ProductThumb`** — tinted-initials tile + category tint + image fallback (`name`, `category`, `imageUrl`, `size`). Use for the visual card thumb (88px) and small avatars.
- **`StockBadge`** — `status="ok"|"low"|"out"` with the dedicated `stock-*` tokens (NOT success/warning/danger — see `apps/pos/docs/design-language.md` stock exception). Map the existing `isOutOfStock`/`isLowStock` logic to these.
- **`Pill`** — category pills (multi-select toggle, `selected`) + removable filter chips (`onRemove`).
- **`Tabs`** — product-detail Équivalents/Compléments/Routine (with `count`).
- `Badge`, `SegmentedControl` (view toggle Vignettes/Liste), `Button`, `IconButton`.
- Tokens: `bg-surface-raised/-sunken/-canvas`, `text-ink/-strong/-muted/-faint`, `border-border-subtle`, **`accent`** (selected/highlight — e.g. in-cart, active pill), **`action`** (blue, primary CTA only). Prices use `font-mono` + `tabular-nums`.

## ProductCard restyle spec (mock §5.1 / §5.7) — restyle IN PLACE, preserve all logic
Keep every existing prop, the `data-testid`s (`in-cart-badge`, `view-details-button`, `price-row`, `stock-row`, `incoming-badge`), the `locationStock` three-path stock logic, modifiers, activation-block, keyboard, memo.
- **Visual card:** `ProductThumb` (88px) top; **info-eye top-left** (opens fiche, works even out-of-stock); brand in caps (your new `brand` field) above name; price in `font-mono`; `StockBadge`.
- **In-cart treatment (§5.7):** full **accent** outline + **accent-tint** bg + a **3px top accent bar** + a ✓ inside the qty badge. **Use `accent` (orange), not `action`** — per owner decision, accent = "selected/highlight", action(blue) = CTA only.
- **Tap pulse:** a ~420ms `ezTap` micro-animation on add. Add the keyframe to `index.css` (I haven't yet — coordinate so we don't both add it; if you add it, name it `ezTap` and use `var(--accent-ring)`), trigger via a brief state on the card.
- **Compact card:** no thumb — brand, name, price, stock, info-eye, in-cart badge.
- **Out-of-stock:** dimmed (`surface-sunken` + `ink-faint`) + `StockBadge status="out"`; fiche still openable.

## ProductGrid restyle + the dual-source-of-truth reconcile (REQUIRED, flagged in the redesign spec)
- **Reconcile first:** `ProductGrid` keeps its OWN `useState` seeded from `localStorage['pos-display-mode']` and **ignores `settingsStore.displayMode`**. Make the grid read `displayMode` + the new `density` from `settingsStore` (migrate the legacy key) so the Appearance settings (already shipped: theme/accent/corner/**density**) actually drive the grid.
- **Density is JS-driven:** thread `density` into `getColumns(displayMode, density, width)` + the `CARD_MIN_H_*` constants (a CSS var won't drive the virtualizer). Map: visual+comfortable=5, visual+dense=6, compact+comfortable=4, compact+dense=5.
- Toolbar: search · **Filtres** (badge count) · Top ventes toggle · view toggle (`SegmentedControl`). Category **`Pill`** row (multi-select; "Tous" clears). Active **filter-chip** row (`Pill onRemove`) + live count. **Skin-advice bar** (`Pill` skin-type pills filtering by your `suitable_skin_types`).

## Notes
- `index.css` token system is on the redesign branch — rebase onto it (or wait for it to merge to dev) so `bg-stock-*`, `bg-accent`, `rounded-tile/card`, `font-mono` etc. resolve.
- Both themes; ESLint color guard ERROR on `ProductCard`/`ProductGrid` (they're in `tokenMigratedGlobs`) — tokens only.
- Ping me (redesign session) before touching `CartLineItem`/`TransactionCart`/`Header`/`AppShell`/`NavRail`/`components/ui/*` — those are mine; everything product/customer/grid is yours.
