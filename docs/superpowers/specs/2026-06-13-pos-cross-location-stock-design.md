# POS Cross-Location Stock Distribution — Design Spec

- **Date:** 2026-06-13
- **Branch:** `feat/pos-cross-location-stock` (forked off `feat/parapharmacy-tunisia-demo` at `b7cbc83cf`)
- **Status:** Draft (rev 2 — incorporates Codex adversarial review `docs/superpowers/reviews/2026-06-13-pos-cross-location-stock-codex-review.md`) — pending user approval
- **Author:** brainstorming session (Claude)

---

## 1. Problem & Motivation

A prospective client asked: **can a cashier check available stock across all locations, including stock that is currently in transfer?**

Today the POS desktop app (`apps/pos`, Tauri 2 + React + local SQLite, offline-first) only surfaces **the cashier's own location** stock on each product tile (on-hand + an "Arriving" incoming badge). There is no way for a cashier to answer "is this item in stock at another branch / the warehouse, and is anything in transit?"

This feature adds a **gated, server-first cross-location stock-distribution view** reachable from a product detail modal, while respecting the POS's offline-first nature ("offline by default, connected only where the function requires it").

## 2. Goals

- A cashier can open a product detail modal (via an eye icon on the product tile) and, **if permitted**, see a per-location breakdown of on-hand and in-transit-incoming stock across all company **shop and warehouse** locations.
- For **variant** products, the view is **variant-grain**: the cashier selects a variant and sees that variant's distribution. Non-variant products are product-grain.
- The cross-location data is **server-first** (fetched live when online) with a cached fallback for offline, a visible "as of" timestamp, a staleness indicator, and a manual **Refresh** button.
- Visibility is gated by **both** a super-admin/company master switch **and** a per-user permission.
- Zero impact on the offline-first shift/fiscal lifecycle — this is **read-only** and never participates in device-authority conflict resolution.

## 3. Non-Goals (explicitly out of scope)

- **Image gallery / richer media** in the modal — belongs to the media-subsystem work (`docs/media-subsystem-architecture`). This branch touches stock only.
- **Office / mobile** location types in the row set — deferred (see §4 M3 decision; "truck-behaves-like-a-shop" is a future expansion).
- **PO incoming** as a displayed figure — v1 shows in-transit **transfers** only (the literal client question). The field is named explicitly so PO incoming can be added later without a breaking rename.
- **Bulk-syncing** all cross-location stock into POS SQLite on every tick (rejected: heavy product × location payload; staleness; rarely-asked-per-product).
- **Reservation / transfer-initiation** from this view — display only.
- Any change to the cashier's **own-location** stock *data* (already shipped) — though the drawer must be upgraded to *read* that already-shipped data (see H4).

## 4. Key Decisions (locked during brainstorming + review)

| # | Decision | Choice |
|---|----------|--------|
| A | Freshness model | **Fetch-on-open + cache** (server-first; cached fallback offline) |
| B | Gating | **Company master switch AND per-user permission** (both required) |
| C | View shape | **Per-location rows: On-hand + In-transit incoming**, with a totals row |
| D | Variants | **Variant-grain, drill-down (Option A)** — a variant **selector** at the top of the section; pick a variant → see its per-location list (rows = locations). `variant_id` required for variant products (mirrors `StockTransferService` invariant); product-grain only when no active variants exist. Matches the cashier-facing industry standard (Shopify/Square/Lightspeed POS all drill-down variant→location; matrix is a back-office-only pattern — see §5a) |
| E | Scope | **Stock distribution only** (no image gallery) |
| F | Container | **Reuse `ProductDetailDrawer`** (right slide-over), but first upgrade it to read location-aware own-location stock (see H4) |
| G | What's gated | **The cross-location section only** — the eye/modal opens for everyone |
| M3 | Location set | **Active `shop` + `warehouse` locations** (regardless of `pos_enabled`); office/mobile deferred |
| M5 | Permission scope | `pos.view_cross_location_stock` is **tenant-scoped** (Spatie team = tenant); the **company flag** is the per-company control |

## 5a. Industry-standard rationale for the variant drill-down (Option A)

Research into cashier-facing POS leaders shows convergence on **drill-down (pick variant → see per-location list)**, not a variants×locations matrix:
- **Shopify POS**: *"If you have variants, then tap a variant. If you have multiple locations, then tap the current store location."* — variant-first, then per-location inventory states.
- **Lightspeed Retail**: search product → expand row → *"Show Inventory & Details"* → per-outlet location column; variants are distinct SKUs navigated to first.
- **Square for Retail**: per-item cross-location visibility surfaced at the point of sale (drill-in), used to fulfill from another location.
- **Odoo** (ERP analog): combined on-hand on the product view + a **Locations** breakdown; variant selected first.
- The **matrix** (variants × locations) appears only in **back-office** bulk editors / inventory reports, never on the cashier fast-path — it doesn't fit a narrow panel and slows the single "can I get this for this customer?" decision.

This is why decision **D** uses a variant selector defaulting to the first active variant, scoped to one variant per fetch.

## 5. Current-State Findings (reconnaissance + review-verified)

### Frontend (`apps/pos`)
- `molecules/ProductCard/ProductCard.tsx` — active tile. **The card root is `div role="button"` with `onClick={activate}` + `onKeyDown` that calls `activate()` on Enter/Space.** The existing customize button stops **click** propagation only, not keydown (⇒ see H1). Own-location stock comes from a `locationStock: LocationStockDisplay | null | undefined` prop; "Arriving" badge sums `incoming_transfer + incoming_po` via bcmath.
- `organisms/ProductGrid/ProductGrid.tsx` — active virtualized grid → `pages/HomePage.tsx`.
- `components/pos/ProductDetailDrawer.tsx` (re-exported `organisms/ProductDetailDrawer/index.ts`) — **orphaned**. Right slide-over (`w-80`). **Renders stock from legacy `product.stock_quantity`** (⇒ see H4), not the location-aware slice the grid uses.
- `atoms/SyncButton` ("Last sync 5m ago"), `atoms/StockFreshness` ("Stock as of 3m ago", amber after 15 min) — relative-time + staleness patterns to reuse.
- `lib/db/migrations.ts` — `location_stock (product_id, variant_id, quantity, reserved, available, incoming_transfer, incoming_po, updated_at)`, own-location only, scale-4 decimal **strings**, compared via bcmath (`bccomp`), never `===`.
- Auth reaches POS via login/`/auth/me` → `authStore` (`permissions: string[]`), **persisted + hydrated offline on boot** (`StorageKeys.USER`).
- **Company config** reaches POS via `/company/config` → `productStore.companyConfig`, but is held **in-memory only** — `refreshCompanyConfig`/`fetchCompanyConfig` do **not** persist it on the normal path (only a one-off C2 migration helper writes a `companyConfigCacheKey`). ⇒ see H2.
- `VariantPickerModal` exists; variants are synced (variant-grain rows in `location_stock`).
- i18n smoke pattern: `__tests__/syncIndicatorI18n.test.tsx` resolves keys in **both** `en/pos.json` and `fr/pos.json` (component tests mock i18n, so keys can ship missing without this). ⇒ see L2.

### Backend (`apps/api`, hexagonal modules under `app/Modules`)
- `Modules/Inventory/Domain/StockLevel.php` → `stock_levels (… product_id, variant_id?, location_id, quantity dec(15,4), reserved dec(15,4), …)`. Partial-unique per variant grain (`stock_levels_non_variant` / `stock_levels_with_variant`). `getAvailableQuantity() = quantity − reserved`.
- `Modules/Inventory/Domain/StockTransfer.php` / `StockTransferLine.php` (variant-aware). **In-transit is NOT stored**; derived as `SUM(stock_transfer_lines.quantity)` for `stock_transfers.status = 'in_transit'`, grouped by destination + variant.
  - `TransferStatus`: `Draft → InTransit → Completed | Cancelled`. **Review confirmed:** at `InTransit` the **source** is decremented and the **destination** is credited only at `Completed` ⇒ in-transit units belong to neither location's on-hand; attribute incoming to the **destination**.
  - `StockTransferService` **rejects** product-level lines when active variants exist (`variant_id` required) — our endpoint must honor the same invariant.
- `Modules/Inventory/Application/Services/LocationStockQueryService.php` — **single-location** reader (`read(tenantId, companyId, locationId, …)`) computing available + in-transit incoming (`incomingTransfer`) + PO incoming (`incomingPo`). Reuse its **primitives**, but do **not** call `read()` per location (⇒ M4).
- Existing `GET /api/v1/products/{product}/stock-levels` — incoming is **PO-only** and gated for inventory/admin; wrong auth + missing transfer-incoming for cashiers.
- `Modules/Company/Domain/Location.php` → `locations (id, company_id, name, code?, type [shop|warehouse|office|mobile], is_active, is_default, pos_enabled)`.
- POS routes group: `apps/api/app/Modules/POS/routes.php:34` uses `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (⇒ H3). `EnforceTokenTenantClaim` returns `401 TOKEN_TENANT_MISMATCH` on tenant-claim mismatch.
- Company toggles are boolean columns on `companies`, surfaced via `CompanyConfigController` → `/company/config`. Permissions: Spatie, team-scoped to **tenant** by `SetPermissionsTeam`; company resolved separately via `CompanyContextMiddleware` (`X-Company-Id` / default).

## 6. Architecture

### 6.1 Data flow (online happy path)

```
Cashier taps eye on ProductCard (stops click + keydown propagation — H1)
  → HomePage opens ProductDetailDrawer(product)
  → Drawer renders product info + OWN-location stock from the location-aware slice (H4)
  → IF (company flag ON, from persisted config — H2) AND (user has pos.view_cross_location_stock):
        render Cross-Location section
        → IF product has active variants: show variant selector (default: first variant)
        → useCrossLocationStock(productId, variantId):
             IF online:
                GET /api/v1/pos/products/{product}/stock-distribution[?variant_id=…]
                → render rows + totals, label "As of <now>"
                → upsert product_stock_distribution_cache[(product_id, variant_id)]
             ELSE (offline / fetch error):
                read cache[(product_id, variant_id)]
                → IF cached: render rows + "As of <fetched_at>" + staleness state + Refresh
                → ELSE: "Unavailable offline — connect to refresh" + Refresh
```

### 6.2 Backend endpoint

`GET /api/v1/pos/products/{product}/stock-distribution`

- **Module / mount:** inside the existing group in `apps/api/app/Modules/POS/routes.php` so it inherits `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (**H3**).
- **Authorization:** requires permission `pos.view_cross_location_stock` **AND** company flag `allow_cross_location_stock_view = true` (resolved via `CompanyContextMiddleware`'s company). Either failing → `403`. `{product}` must be a UUID (`Str::isUuid` guard) → `422`/`404` otherwise.
- **Variant rule (D / M1):**
  - If the product has **active variants**: `variant_id` is **required** (matching `StockTransferService`); missing → `422`. Response is that variant's distribution.
  - If the product has **no variants**: `variant_id` must be **absent**; response is product-grain.
  - The endpoint never silently ignores a supplied `variant_id`.
- **Location set (M3):** all `is_active` locations of `type ∈ {shop, warehouse}` for the company, **zero-filled** (so the cashier sees the full map), sorted current-location-first then by name.
- **Response** (`200`):

```json
{
  "data": {
    "product_id": "uuid",
    "variant_id": "uuid|null",
    "locations": [
      {
        "location_id": "uuid",
        "location_name": "Lac 2 Branch",
        "location_type": "shop",
        "is_current": true,
        "on_hand": "12.0000",
        "incoming_transfer": "5.0000"
      }
    ],
    "totals": { "on_hand": "37.0000", "incoming_transfer": "5.0000" },
    "as_of": "2026-06-13T10:22:01Z"
  }
}
```

- **Semantics:**
  - `on_hand` = `quantity − reserved` per location (available), scale-4 **string**.
  - `incoming_transfer` = `SUM(stock_transfer_lines.quantity)` for `status='in_transit'` and `destination_location_id = location` (grain-matched to the requested variant), scale-4 **string**. **Named `incoming_transfer`, not `incoming`** (**M2**) to avoid colliding with the existing POS "incoming = transfer + PO" term.
  - `is_current` flags the terminal's location.
  - Tenant + company scoped; never cross-tenant.
- **Query shape (M4):** **no `read()`-per-location loop.** One locations query (filtered to shop/warehouse/active), one stock-level query for the product (+variant) grouped by `location_id`, one transfer query for in-transit lines of the product (+variant) grouped by `destination_location_id`. Assemble in memory.
- **Shared logic:** extract a `LocationStockQueryService::stockDistributionForProduct(companyId, productId, ?variantId): StockDistributionDTO` (grouped, all-locations) so the single-location feed and this reader share the same on-hand + in-transit primitives without drift.

### 6.3 Company flag (with offline persistence — H2)

- Migration: `companies.allow_cross_location_stock_view` boolean, default `false`, not null.
- Surface in `CompanyConfigController` → `/company/config` (`allow_cross_location_stock_view: bool`); add to POS `types/companyConfig.ts`.
- **Persist + hydrate offline:** persist successful `/company/config` responses under a per-company cache key (reuse the existing `companyConfigCacheKey(companyId)` helper) on the **normal** path (`refreshCompanyConfig` and `fetchCompanyConfig` callers), and hydrate `productStore.companyConfig` from it during auth/product bootstrap. Without this, the gate fails closed after an offline restart even when the flag was enabled and stock is cached.
- **Fail-closed rule:** the section hides only when **no cached config exists** (or the flag is false). A previously-fetched flag must survive an offline restart.
- Web-admin: add a toggle in the Company settings page (same pattern as receipt-visibility / smart-prompts).

### 6.4 Permission (tenant-scoped — M5)

- Add `pos.view_cross_location_stock` to `RolesAndPermissionsSeeder` (POS group), default-granted to `admin` + `manager` (NOT base `cashier`; owner grants per role/tenant).
- It is **tenant-scoped** (Spatie team = `tenant_id`): a user holding it carries it across the companies they belong to. **Per-company control is the company flag**, not the permission. The spec does not imply company-specific permission grants.
- Delivered to POS via the existing auth payload → `authStore.permissions` (already persisted offline).

### 6.5 POS local cache (with staleness policy — L1)

- New migration in `lib/db/migrations.ts`: `product_stock_distribution_cache (product_id TEXT, variant_id TEXT NOT NULL DEFAULT '', payload TEXT NOT NULL, fetched_at TEXT NOT NULL, PRIMARY KEY (product_id, variant_id))`.
  - `variant_id = ''` for product-grain (mirrors `location_stock`).
  - `payload` = the JSON `data` object (decimal **strings** preserved; consumers format/compare via `@/lib/decimal`).
  - `fetched_at` = server-fetch time → drives the "as of" label.
- Repository: `crossLocationStockRepository.get(productId, variantId)` / `upsert(...)`.
- **Staleness UI:** fresh < 15 min; **amber "stale" warning ≥ 15 min** (reuse `StockFreshness` threshold); the Refresh button is always available. (Online open always re-fetches, so staleness is an offline-only condition.)
- Eviction: on product tombstone sync, cascade-delete cache rows for deleted products (mirror `deleteLocationStockForProducts`). Otherwise small + overwritten on each open.

### 6.6 Frontend wiring

- **`ProductCard`** — add an `Eye` icon button (overlay, mirroring the modifier-badge pattern) calling a new `onViewDetails?(product)` prop. It must **`stopPropagation()` on BOTH `onClick` and `onKeyDown`** (Enter/Space), so keyboard activation opens the drawer and never reaches the parent `activate()` (**H1**). Keyboard-accessible; regression test required.
- **`ProductGrid` → `HomePage`** — thread `onViewDetails`; `HomePage` owns `detailProduct` state + renders `ProductDetailDrawer`.
- **`ProductDetailDrawer`** (**H4**) — change it to accept the same `LocationStockDisplay | null | undefined` slice as `ProductCard`; render available via `formatAvailableQty` + own-location `incoming_transfer`/`incoming_po`, replacing the legacy `product.stock_quantity` readout. Then append the **Cross-Location** section (gated).
- **Variant selector (D)** — for variant products, a selector at the top of the cross-location section (variants already synced); default to the first variant; switching re-runs `useCrossLocationStock`. Non-variant products skip it.
- New API client fn `stockApi.fetchStockDistribution(productId, variantId?)`.
- `useCrossLocationStock(productId, variantId)` — encapsulates fetch-on-open + cache + refresh + online/offline/loading/error/staleness states.
- All strings via `t()` (POS namespace); design tokens for colors (no hardcoded Tailwind color classes); no `parseFloat`/`Number()` on quantities.

### 6.7 Gate evaluation is offline-safe **iff config is persisted**

The permission input is already persisted/hydrated offline. The company-flag input becomes offline-safe **only after** the §6.3 persistence change. With both in place, the section's visibility is decided without a network call; only the **data fetch** needs connectivity. (Corrects rev 1's claim that both inputs were already cached.)

## 7. Device-authority / conflict analysis

No writes to shift, fiscal chain, receipts, or stock. Reads a server snapshot into a display-only, per-device, advisory cache overwritten on each fetch. It does not participate in device-authority reconciliation and cannot create split-brain or fiscal conflicts with the offline-first shift work.

## 8. Testing (TDD — write tests first)

### Backend (PHPUnit, RefreshDatabase + real models + seeded permissions; scope with `--filter`)
1. Per-location `on_hand` = available (`quantity − reserved`) for all active shop+warehouse locations; zero-filled rows render `"0.0000"`.
2. `incoming_transfer` = sum of `in_transit` transfer-line quantities to each **destination**; `draft`/`completed`/`cancelled` excluded; not attributed to source.
3. Totals = column-wise sums.
4. **Location set:** inactive locations excluded; `office`/`mobile` excluded; `pos_enabled=false` shops **included**; warehouses included.
5. **Variant rule:** variant product without `variant_id` → `422`; with `variant_id` → that variant's grain; non-variant product with `variant_id` → `422`; non-variant returns product-grain.
6. `403` when permission missing; `403` when company flag off (even with permission).
7. **`401 TOKEN_TENANT_MISMATCH`** when token tenant claim ≠ user tenant (**H3**).
8. Tenant/company isolation: never another tenant's/company's locations or stock.
9. Decimal strings throughout (no float drift). Non-UUID `{product}` rejected (no 500).
10. **No N+1 (M4):** assert the all-locations path issues a bounded, constant number of queries (not per-location).

### Frontend (Vitest)
1. Eye icon renders + opens the drawer; **keyboard (Enter/Space) on the eye opens the drawer and does NOT call `onAddToCart`** (H1).
2. Drawer own-location stock renders from the `LocationStockDisplay` slice (present / null-exempt / undefined-legacy fallback) — not legacy `stock_quantity` (H4).
3. Cross-location section hidden when the flag is off OR the permission is missing (test each independently); shown only when both pass.
4. **Offline restart:** with persisted config flag ON + cached payload, the section still renders offline (H2 regression).
5. Online open: fetch called, rows + totals render, "As of \<now\>", cache upserted (keyed by product+variant).
6. Offline open with cache: cached rows + "As of \<fetched_at\>" + Refresh; **amber stale state ≥ 15 min** (L1).
7. Offline open without cache: "Unavailable offline" + Refresh.
8. Variant product: selector renders, switching variant re-fetches that variant's distribution; non-variant skips selector.
9. Loading/error states; quantities via `formatAvailableQty` (no `parseFloat`).
10. **i18n smoke (L2):** every new cross-location key resolves in **both** `en/pos.json` and `fr/pos.json` (direct `i18n.t()`, following `syncIndicatorI18n.test.tsx`).

### Quality gates
- Backend: `phpstan` (level 8) clean on new code, `pint`. Never run the full PHPUnit suite (laptop-crash risk) — scope with `--filter`.
- Frontend: `pnpm typecheck`, `pnpm lint` (token + no-parsefloat rules), scoped Vitest.

## 9. Open questions / to confirm during implementation

- Final **role grants** for `pos.view_cross_location_stock` beyond admin+manager (e.g. senior cashiers) — owner to confirm.
- Exact **stale threshold** value (default 15 min, matching `StockFreshness`) and whether a hard "too old, must refresh" ceiling is wanted.
- Variant selector **default** when opened via the eye with no prior variant context (default: first active variant) — confirm acceptable vs. an "all variants" summary option.
- Future expansion: `mobile`/"truck-as-shop" locations and `incoming_po` column.

## 10. Rollout

Ships dark: company flag defaults `false` and the permission is off for base cashier, so no tenant sees it until a super-admin enables it + grants the role. Safe to merge ahead of go-live.
