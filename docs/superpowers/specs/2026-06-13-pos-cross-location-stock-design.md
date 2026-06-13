# POS Cross-Location Stock Distribution — Design Spec

- **Date:** 2026-06-13
- **Branch:** `feat/pos-cross-location-stock` (forked off `feat/parapharmacy-tunisia-demo` at `b7cbc83cf`)
- **Status:** Draft — pending adversarial review + user approval
- **Author:** brainstorming session (Claude)

---

## 1. Problem & Motivation

A prospective client asked: **can a cashier check available stock across all locations, including stock that is currently in transfer?**

Today the POS desktop app (`apps/pos`, Tauri 2 + React + local SQLite) only surfaces **the cashier's own location** stock on each product tile (on-hand + an "Arriving" incoming badge). There is no way for a cashier to answer "is this item in stock at another branch, and is anything in transit?"

This feature adds a **gated, server-first cross-location stock-distribution view** reachable from a product detail modal, while respecting the POS's offline-first nature.

## 2. Goals

- Cashier can open a product detail modal (via an eye icon on the product tile) and, **if permitted**, see a per-location breakdown of on-hand and in-transit-incoming stock across **all** company locations.
- The cross-location data is **server-first** (fetched live when online) with a cached fallback for offline, a visible "as of" timestamp, and a manual **Refresh** button.
- Visibility is gated by **both** a super-admin/company master switch **and** a per-user permission.
- Zero impact on the offline-first shift/fiscal lifecycle — this is **read-only** and never participates in device-authority conflict resolution.

## 3. Non-Goals (explicitly out of scope)

- **Image gallery / richer media** in the modal — belongs to the media-subsystem work (`docs/media-subsystem-architecture`). This branch touches stock only.
- **Per-variant** cross-location breakdown — product-grain aggregate only for now. The endpoint accepts an optional `variant_id` for a future iteration.
- **Bulk-syncing** all cross-location stock into POS SQLite on every tick (rejected: heavy payload of product × location; staleness; the question is rarely asked per product).
- **Reservation / transfer-initiation** from this view — display only.
- Any change to the cashier's **own-location** stock display (already shipped).

## 4. Key Decisions (locked during brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| A | Freshness model | **Fetch-on-open + cache** (server-first; cached fallback offline) |
| B | Gating | **Company master switch AND per-user permission** (both required) |
| C | View shape | **Per-location rows: On-hand + Incoming**, with a totals row |
| D | Variants | **Product-grain aggregate** (no variant breakdown yet) |
| E | Scope | **Stock distribution only** (no image gallery) |
| F | Container | **Reuse the existing orphaned `ProductDetailDrawer`** (right slide-over), wire up the eye icon |
| G | What's gated | **The cross-location section only** — the eye/modal opens for everyone (general product info + own-location stock stays visible) |
| H | Backend | **New POS-scoped endpoint**, with the per-location assembly extracted into a shared `LocationStockQueryService` method so admin + POS read stock identically (no logic drift) |

## 5. Current-State Findings (reconnaissance)

### Frontend (`apps/pos`)
- `molecules/ProductCard/ProductCard.tsx` — active product tile. Shows own-location stock from a `locationStock` prop + an "Arriving: X" incoming badge (`ArrowUpRight`). Has an `onCustomize` callback + a `SlidersHorizontal` modifier badge pattern we can mirror for the eye icon. **No eye icon today.**
- `organisms/ProductGrid/ProductGrid.tsx` — active, virtualized grid; renders `ProductCard`s; used by `pages/HomePage.tsx`.
- `components/pos/ProductDetailDrawer.tsx` (re-exported at `organisms/ProductDetailDrawer/index.ts`) — **exists, fully built, ORPHANED** (never instantiated). Right-side slide-over (`w-80`, backdrop) rendering image, name, price, SKU, barcode, category, **stock**, tax rate. This is our container.
- Sync UI patterns to reuse: `atoms/SyncButton` ("Last sync 5m ago" + manual trigger), `atoms/StockFreshness` ("Stock as of 3m ago", amber after 15 min) — relative-time formatting we reuse for the "as of" label.
- Local DB migrations: `lib/db/migrations.ts`. Stock lives in `location_stock (product_id, variant_id, quantity, reserved, available, incoming_transfer, incoming_po, updated_at)` — own-location only. Decimal **strings** (scale-4), compared via bcmath helpers (`bccomp`), never `===`.
- Auth/permissions reach POS via the login/`/auth/me` payload → `authStore` (`permissions: string[]`) and `operatorStore`.
- Company config reaches POS via `/company/config` → `types/companyConfig.ts` (`ReceiptVisibility`, `smart_prompts_enabled`, …). This is the precedent for a new company flag.

### Backend (`apps/api`, hexagonal modules under `app/Modules`)
- `Modules/Inventory/Domain/StockLevel.php` → table `stock_levels (id, tenant_id, company_id, product_id, variant_id?, location_id, quantity dec(15,4), reserved dec(15,4), min/max, …)`. `getAvailableQuantity() = quantity − reserved` (bcmath).
- `Modules/Inventory/Domain/StockTransfer.php` + `StockTransferLine.php` → tables `stock_transfers` / `stock_transfer_lines`. **In-transit is NOT a stored column**; it is derived: `SUM(stock_transfer_lines.quantity)` where `stock_transfers.status = 'in_transit'` grouped by destination.
  - `TransferStatus`: `Draft → InTransit → Completed | Cancelled`. At `InTransit` the **source** on-hand is already decremented; the **destination** is credited only at `Completed`. ⇒ in-transit units belong to neither location's on-hand.
- `Modules/Inventory/Application/Services/LocationStockQueryService.php` — already computes per-location available + in-transit incoming (transfers) + PO incoming for a single location (POS feed). **This is the logic to extract/reuse.**
- Existing `GET /api/v1/products/{product}/stock-levels` (`Modules/Product/.../ProductController.php`) returns per-location available + totals, but its `incoming` is **PO-only** and it is gated for inventory/admin use — wrong auth + missing transfer-incoming for cashiers.
- `Modules/Company/Domain/Location.php` → `locations (id uuid, company_id, name, code?, type [shop|warehouse|office|mobile], is_active, is_default)`.
- Company-level toggles are individual boolean columns on `companies` (e.g. `receipt_show_*`, `smart_prompts_enabled`) surfaced through `CompanyConfigController` → `/company/config`. Super-admin module gating is `tenants.enabled_extras`; per-tenant toggles managed in web-admin Company settings.
- Permissions: Spatie, team-scoped by `SetPermissionsTeam` (tenant). Seeded in `database/seeders/RolesAndPermissionsSeeder.php`. POS-specific permissions already exist (`pos.operate_terminal`, `pos.void_receipts`, …).

## 6. Architecture

### 6.1 Data flow (happy path, online)

```
Cashier taps eye on ProductCard
  → HomePage opens ProductDetailDrawer(product)
  → Drawer renders product info + OWN-location stock (already local, instant)
  → IF (company flag ON) AND (user has pos.view_cross_location_stock):
        render Cross-Location section
        → useCrossLocationStock(productId):
             IF online:
                GET /api/v1/pos/products/{product}/stock-distribution
                → render rows + totals, label "As of <now>"
                → upsert into product_stock_distribution_cache
             ELSE (offline / fetch error):
                read product_stock_distribution_cache[productId]
                → IF cached: render rows + "As of <fetched_at>" + Refresh
                → ELSE: "Unavailable offline — connect to refresh" + Refresh
```

### 6.2 Backend endpoint

`GET /api/v1/pos/products/{product}/stock-distribution`

- **Middleware:** `['api', 'auth:sanctum', SetPermissionsTeam::class]` (per route convention).
- **Authorization:** requires permission `pos.view_cross_location_stock` **AND** the company flag `allow_cross_location_stock_view = true`. If either fails → `403` (consistent body). Validate `{product}` is a UUID before querying (Str::isUuid guard) → `404`/`422` otherwise.
- **Input:** path `{product}` (UUID); optional `?variant_id` (UUID) reserved for future — for v1 the controller ignores variant breakdown and returns product-grain aggregate.
- **Response** (`200`):

```json
{
  "data": {
    "product_id": "uuid",
    "locations": [
      {
        "location_id": "uuid",
        "location_name": "Lac 2 Branch",
        "location_type": "shop",
        "is_current": true,
        "on_hand": "12.0000",
        "incoming": "5.0000"
      }
    ],
    "totals": { "on_hand": "37.0000", "incoming": "5.0000" },
    "as_of": "2026-06-13T10:22:01Z"
  }
}
```

- **Semantics:**
  - `on_hand` = `stock_levels.quantity − reserved` per location (available), scale-4 **string**.
  - `incoming` = `SUM(stock_transfer_lines.quantity)` for `stock_transfers.status = 'in_transit'` and `destination_location_id = location`, product-grain, scale-4 **string**. (PO incoming intentionally excluded in v1 — the client question is about transfers; can be added as a separate field later without breaking the shape.)
  - `is_current` flags the cashier's terminal location.
  - **Include all active company locations**, zero-filled, so the cashier sees the full map (not just locations that happen to have stock). Sorted: current location first, then by name.
  - All money/qty as decimal strings; never floats (precision contract).
  - Tenant + company scoped (never cross-tenant; `LocationStockQueryService` already scopes by tenant/company).

- **Shared logic:** extract a `LocationStockQueryService::stockDistributionForProduct(productId): StockDistributionDTO` (or equivalent) reading `stock_levels` + in-transit transfer aggregation across **all** company locations. The existing single-location POS feed and this all-location reader share the same on-hand + in-transit primitives.

### 6.3 Company flag

- Migration: add `companies.allow_cross_location_stock_view` boolean, default `false`, not null.
- Surface in `CompanyConfigController` → `/company/config` payload (e.g. `allow_cross_location_stock_view: bool`).
- Add to POS `types/companyConfig.ts`.
- Web-admin: add a toggle in the Company settings page (same pattern as receipt-visibility / smart-prompts) so a super-admin/owner enables it per tenant.

### 6.4 Permission

- Add `pos.view_cross_location_stock` to `RolesAndPermissionsSeeder` (POS permission group).
- Grant by default to `admin`, `manager` (NOT base `cashier` — owner grants per role/tenant). Final role grants confirmed during implementation.
- Delivered to POS via the existing auth payload (`getAllPermissions()`), already in `authStore.permissions`.

### 6.5 POS local cache

- New migration in `lib/db/migrations.ts`: `product_stock_distribution_cache (product_id TEXT PRIMARY KEY, payload TEXT NOT NULL, fetched_at TEXT NOT NULL)`.
  - `payload` = the JSON `data` object from the endpoint (locations + totals).
  - `fetched_at` = server-fetch time (drives the "as of" label).
- Repository: `crossLocationStockRepository` with `get(productId)` / `upsert(productId, payload, fetchedAt)`.
- Eviction: on product tombstone sync, cascade-delete cache rows for deleted products (mirror `deleteLocationStockForProducts`). Otherwise cache is small (only viewed products) and overwritten on each open.

### 6.6 Frontend wiring

- `ProductCard`: add an `Eye` icon button (top-left overlay, mirroring the modifier-badge pattern) calling a new `onViewDetails?(product)` prop. Keyboard-accessible; does not trigger add-to-cart (stop propagation).
- `ProductGrid` → `HomePage`: thread `onViewDetails`. `HomePage` owns `detailProduct` state + renders `ProductDetailDrawer`.
- `ProductDetailDrawer`: keep existing content; append a **Cross-Location Stock** section rendered only when gated-in. New hook `useCrossLocationStock(productId)` encapsulates fetch-on-open + cache + refresh + online/offline/loading/error states.
- New API client fn in `apps/pos/src/api` (e.g. `stockApi.fetchStockDistribution(productId)`).
- All user-facing strings via `t()` (new keys; respect i18n 3-place setup if a new namespace is needed — prefer an existing POS namespace).
- Design tokens for any colors (no hardcoded Tailwind color classes in new code).

### 6.7 Gate evaluation is offline-safe

Both gate inputs (`/company/config` flag, auth `permissions`) are already cached locally on the POS, so the section's visibility is decided without a network call. Only the **data fetch** requires connectivity — matching the "offline by default, connected only where required" principle.

## 7. Device-authority / conflict analysis

This feature performs **no writes** to shift, fiscal chain, receipts, or stock. It reads a server snapshot into a display-only cache. It therefore:
- Does not participate in device-authority reconciliation.
- Cannot create split-brain or fiscal-chain conflicts with the offline-first shift work.
- The cache is per-device, advisory, and overwritten on each fetch; no merge semantics needed.

## 8. Testing (TDD — write tests first)

### Backend (PHPUnit, RefreshDatabase + real models + seeded permissions)
1. Endpoint returns per-location `on_hand` (available = quantity − reserved) for all active company locations.
2. `incoming` equals the sum of `in_transit` transfer-line quantities destined to each location; `draft`/`completed`/`cancelled` transfers excluded; in-transit attributed to **destination**, not source.
3. Totals equal the column-wise sums of the rows.
4. `403` when the user lacks `pos.view_cross_location_stock`.
5. `403` when the company flag is off, even with the permission.
6. Tenant isolation: never returns another tenant's/company's locations or stock.
7. Quantities are scale-4 decimal strings (no float drift); zero-filled locations render `"0.0000"`.
8. Non-UUID `{product}` is rejected (guard) — no 500.

### Frontend (Vitest)
1. Eye icon renders on the tile and opens the drawer; does not add to cart.
2. Cross-location section is **hidden** when the company flag is off OR the permission is missing (test both independently) and shown only when both pass.
3. Online open: fetch is called, rows + totals render, "As of \<now\>" shown, cache upserted.
4. Offline open with cache: renders cached rows + "As of \<fetched_at\>" + Refresh; no fetch attempted (or fetch fails gracefully to cache).
5. Offline open without cache: shows "Unavailable offline" + Refresh.
6. Refresh button re-fetches when online and updates timestamp.
7. Loading and error states render; decimal strings formatted via the existing qty formatter (no `parseFloat`).

### Quality gates
- Backend: `phpstan` (level 8) clean on new code, `pint`. Scope PHPUnit with `--filter` (never full suite without permission).
- Frontend: `pnpm typecheck`, `pnpm lint` (incl. token + no-parsefloat rules), scoped Vitest.

## 9. Open questions / to confirm in review

- **Role grants** for `pos.view_cross_location_stock` — default to admin+manager; owner to confirm whether senior cashiers get it.
- **Forbidden vs empty** when the company flag is off — spec chooses **403**; confirm that's the desired client behavior (vs silently hiding with a 200 empty).
- Whether to also expose **PO incoming** as a separate column later (out of scope v1).
- Whether to **include all active locations zero-filled** vs only locations with stock/incoming — spec chooses include-all for a predictable full map.

## 10. Rollout

- Ships dark: company flag defaults `false`, so no tenant sees it until a super-admin enables it. Permission also off for base cashier role. Safe to merge ahead of go-live.
