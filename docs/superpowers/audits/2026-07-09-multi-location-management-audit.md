# Multi-Location Management — Audit & Session Seed (2026-07-09)

> Synthesis of 3 codebase audits (backend location scoping, web UX, pricing location-dimension) + 1 industry research pass (per-location pricing standards). Purpose: seed a dedicated design session for multi-location management tooling. Trigger: owner observation that stock-transfer creation cannot show per-location stock at a glance, plus first-customer (parapharmacy chain) and future F&B (central kitchen / franchise) needs.

## Verdict

The **data model is largely multi-location-ready** (per-location stock, min/max, transfers, terminals, per-branch tax identity, membership-level location restrictions). What's missing is almost entirely **wiring and surfaces**: request-time location context is dormant, per-location authorization is un-wired, pricing has zero location dimension, and the web has no cross-location comparison surface for inventory. Most gaps are composition work over existing primitives, not new infrastructure.

---

## 1. Foundation status (what exists)

- **Location model**: `locations` rich fields (type, receipt header/footer, per-branch `tax_id`/`vat_number`/`legal_identifiers`, `onboarding_mode`, `pos_stock_policy_override`, `pos_enabled`) — `app/Modules/Company/Domain/Location.php`.
- **Per-location stock**: `stock_levels` keyed (product, [variant], location) with per-location `min_quantity`/`max_quantity`; `stock_movements.location_id`; mature stock transfers.
- **Cross-location read model**: `LocationStockReader` contract → `LocationStockQueryService::stockDistributionForProduct()` (on-hand + in-transit across locations); company flag `allow_cross_location_stock_view`. Consumed by **POS only** (`CrossLocationStockSection`); **no web consumer**.
- **Web endpoint already returns the full vector**: `GET /products/{id}/stock-levels` → `{totals, locations[]}` (qty/reserved/available/incoming/projected per location).
- **Authorization data model**: `user_company_memberships.allowed_location_ids` (NULL = all) + `LocationContext::canAccessLocation()` (fail-closed) + `ValidateLocationAccess` middleware — all present.
- **Reports**: `Accounting\ReportsController` accepts `locationIds` via `OwnerReportScope`; `SalesReportService::salesByLocation()` is a true side-by-side comparison; `StockAlertReportService::lowStockAcrossLocations()`.
- **POS terminals location-bound**: `pos_terminals.location_id` + `Terminal::scopeForLocation`.
- **"Location override → company default" resolver precedents**: `LocationStockPolicyResolver` (canonical), per-branch tax fields (`TaxIdentityResolver`), `variant.price_override ?? product.sale_price`.

## 2. Dormant / un-wired machinery (highest-leverage fixes)

1. **`LocationContext::setLocationId()` is never called** — the singleton is always empty; `resolveLocationId()` always falls back to the company default location. Web back-office ops silently attribute to the default location. No middleware, no `X-Location` header (web axios client sends only `X-Company-Id`, `apps/web/src/lib/api.ts:145-148`; a `locationStore`/`LocationSwitcher` exists client-side but is per-page query-param only).
2. **`ValidateLocationAccess` middleware applied to zero routes.**
3. **No write path ever sets `allowed_location_ids`** — all membership creation sites create owner/all-location memberships (`TenantProvisioningService.php:178`, `AuthController.php:444`, `CompanyController.php:129`); `UserController::store()` creates staff with a Spatie role and **no membership row at all** (`UserController.php:166-238`). Net effect: every company web user sees all locations; the restriction machinery is unreachable.
4. **`PricingService::getPrice` (variant → partner list → default list → base) is dormant for sales** — only consumers are barcode labels + `/pricing/*` diagnostics. Actual sale-time prices come from two choke points: `DocumentLineEditor.tsx:350` (web) and `cartStore.ts:287` (POS `variant.price_override ?? product.sale_price`). Price lists are a built-but-unused subsystem.

## 3. Prioritized gap register (merged, cross-audit)

### P0 — foundations (block everything else)
- **G1. Request-time location context**: middleware + header (or explicit param convention) populating `LocationContext`; align web `locationStore` with it. *(backend §1)*
- **G2. Per-location authorization wiring**: write paths + UI for `allowed_location_ids`; attach `validate.location.access`; staff-creation must create memberships. *(backend §3)*

### P1 — the owner's pain: cross-location visibility surfaces (web)
- **G3. Transfer line entry discards the fetched cross-location vector** (`CreateStockTransferPage.tsx` `AvailabilityCell` filters `/products/{id}/stock-levels` down to source only). Show per-location availability during line entry; add destination-stock context + "suggest source". **The motivating example.**
- **G4. No product × location matrix anywhere in web** (POS has one; web never calls stock-distribution). Compose existing endpoint + `LocationSelectorMulti` into a comparison grid (rows = products, cols = locations).
- **G5. Product detail cross-location breakdown is a collapsed footnote** (`ProductStockLevels.tsx:150-181`, drops `reserved`, hidden unless >1 location). Promote to a decision surface.
- **G6. Purchasing is location-blind**: goods-receipt/supplier-invoice destination is a bare `<select>` with no stock/reorder context; no per-location need signal while ordering.
- **G7. Owner reporting compares locations for sales only** (`SalesByLocationChart`, `BranchLeaderboard`); nothing for inventory: no consolidated stock valuation, no rebalancing view ("out at A / overstocked at B"); `LowStockAlertsList` is a flat list.

### P2 — attribution & analytics
- **G8. `PosAnalyticsService` is company-scoped only** (no location filter/grouping) — store managers can't see their own store; no store comparison in POS analytics.
- **G9. Expenses & payments have no `location_id`** — per-location P&L / cash position impossible (treasury `payment_repositories.location_id` exists, spend attribution doesn't).
- **G10. `goods_receipts` have no `location_id`** (implicit from PO lines); documents header has no `location_id` (line-level only, defaults via G1's broken fallback).
- **G11. Reorder policy split-brain**: `products.reorder_point/reorder_quantity` (company-grain) vs `stock_levels.min/max_quantity` (location-grain), unreconciled. Central-kitchen replenishment needs location grain. *(Interacts with the Replenishment Request feature spec'd 2026-07-09.)*

### P3 — per-location pricing (capability, off by default)
- **G12. Pricing has zero location dimension** (no location column in `price_lists`/`price_list_items`/`partner_price_lists`; `variant.price_override` global).
  - **Industry**: per-location pricing capability is standard everywhere (Lightspeed/Square/Shopify/D365/SAP/Erply/Toast); dominant model = **price lists assigned to stores, most-specific wins, central price always fallback** — NOT per-store price columns. Practice is discretionary (NBER: most chains price near-uniformly). **F&B franchise makes it mandatory**: French law forbids imposing resale prices on franchisees (art. L442-6) — central price can only be *recommended*; Toast MLM two-key model (central flags item as locally priceable + scoped local permission) is the reference. Tunisia: pharma prices/margins state-controlled (uniform by law); parapharmacy free.
  - **Smallest-viable sketch** (from feasibility audit): `product_location_prices` nullable-override table (or location→price-list binding) + `LocationPriceResolver` cloning `LocationStockPolicyResolver`; overlay server-side in POS `SyncController::pull` (~L81, terminal's location) + `PosVariantFeedService` — **device code unchanged**; web `DocumentLineEditor.tsx:350` resolves via document location; feed resolved price into `DiscountPolicySubjectProvider.salePriceNet` so discount-cap floors track location price. Ship OFF by default (no overrides = uniform pricing → owner's preferred policy).
  - **Hidden constraint**: WAC is product-grain (`WeightedAverageCostService` → `product.cost_price`) — per-location *prices* are clean; per-location *margin/floors* need a per-location cost grain (separate, larger project).
- **G13. Per-location operational config**: no opening hours anywhere; per-location tax-*rate* override, receipt logo/VAT-breakdown/auto-print, price-display are company-global.

### UX coherence
- **G14. Two inconsistent scope paradigms**: global one-at-a-time `LocationSwitcher` (switch = invalidate ALL queries) vs per-page `LocationSelectorMulti`; no shared "compare these N locations" scope.
- **G15. Fragile precedent to kill**: `ProductMovementsTab.tsx:113-133` fakes multi-location by sending only the first location to the API and filtering client-side.

## 4. Reusable assets for the design session

- `/products/{id}/stock-levels` (full per-location vector, already called where needed)
- `LocationStockReader` / `LocationStockQueryService` + POS `CrossLocationStockSection` (portable pattern)
- `LocationSelectorMulti`, owner-dashboard `location_ids` filter + `SalesByLocationChart` grouped-bar approach
- `LocationStockPolicyResolver` (the "location override → company default" resolver to clone)
- `user_company_memberships.allowed_location_ids` + `LocationContext` + `ValidateLocationAccess` (dormant authz stack)
- Dormant `PriceList`/`PriceListItem`/`PartnerPriceList` subsystem (maps onto the industry price-list-assignment model if/when activated)

## 5. Suggested session decomposition

1. **Foundations**: G1 + G2 (+G10 stamps) — location context & authorization wiring.
2. **Visibility**: G3–G7 — the cross-location matrix component + its embeddings (transfer page, product page, purchasing, owner inventory comparison). Biggest owner-visible win.
3. **Attribution/analytics**: G8, G9, G11.
4. **Per-location pricing capability** (G12/G13) — gated on a real tenant need (capability standard, practice optional; F&B franchise forces it).

Full agent reports (file-level citations) live in the session transcript of 2026-07-09; this doc is the durable summary.
