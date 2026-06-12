# POS Location-Stock Tauri Smoke Checklist (Manual)

> **Browser cannot run this checklist** — the offline layer (SQLite, `pullLocationStock`, availability selector, `resolveSellerIdentity` in paymentStore) is Tauri-only. Run with `pnpm tauri dev` or a packaged build.

**Setup:** `ParapharmacySeeder` (Tier-A multi-branch) + `pnpm tauri dev`; claim a terminal at Branch B (a branch with a complete fiscal identity: `tax_id` + full address). Have a second location (Branch A or company-only) configured without a complete location address for the fallback test.

**Notes on implementation vs. plan:**
- Stock policy is received via the terminal payload (`pos_stock_policy` field from `TerminalResource`) — not from a `/company/config` endpoint.
- The staleness hint (`StockFreshness`) appears in the Header next to the `SyncButton`, not in a separate location.
- Arriving badge is rendered as `+N ↗` on the product tile; the tooltip (on hover or long-press) splits by source (transfer / PO) when both are present.
- Seller identity selection is atomic: location identity is used wholesale or company identity is used wholesale — never mixed per-field.

---

## 1. Terminal claim + initial pull

- [ ] After claiming the terminal at Branch B: `location_stock` table in SQLite is populated for Branch B only (inspect via Tauri SQLite viewer or `SELECT * FROM location_stock LIMIT 10` in the dev console).
- [ ] Products from other branches do not appear in Branch B's `location_stock` rows.
- [ ] `sync_metadata.stock_last_sync` key is written after the pull completes.

## 2. Policy enforcement — block policy

- [ ] A product with zero available stock at Branch B displays the out-of-stock visual (low-stock styling, not clickable or shows blocked toast).
- [ ] Attempting to add a zero-stock product via **tile** → blocked toast (`pos:stock.blocked`).
- [ ] Attempting to add a zero-stock product via **barcode scan** → blocked toast.
- [ ] Attempting to add a zero-stock product via **numpad / manual entry** → blocked toast.
- [ ] Attempting to add a zero-stock product via **variant picker** → blocked toast (variant-level stock checked).
- [ ] A product with available stock (`> 0`) adds to cart normally.

## 3. Policy enforcement — warn policy

- [ ] Switch Branch B's `pos_stock_policy` to `warn` (admin UI or direct DB update + terminal refresh).
- [ ] Adding a product beyond available stock → warning toast (`pos:stock.warned`), line is added to cart.
- [ ] Cart is not blocked; sale can complete.

## 4. Offline sell + drain + re-pull (no double-count)

- [ ] Disable network. Sell available stock down across several queued sales (offline receipts accumulate in `offline_receipts`). `effectiveAvailable` in the UI decreases with each queued sale.
- [ ] Re-enable network. Observe sync drain: `offline_receipts` are uploaded; server-side stock reflects the sales.
- [ ] After drain, next delta pull re-baselines `location_stock.available_qty` from the server.
- [ ] No double-count: `effectiveAvailable` matches the server quantity after re-pull.

## 5. Staleness hint in the Header

- [ ] Header shows the `StockFreshness` hint ("Stock as of …") beside the `SyncButton` after the first pull.
- [ ] Hint updates after each successful sync tick (60s cadence).
- [ ] No hint appears on a Menu tenant (never pulls stock — key absent from `sync_metadata`).

## 6. Incoming stock badges on product tiles

- [ ] Initiate an **in-transit transfer** toward Branch B from the web admin.
- [ ] After the next stock pull: product tile shows the arriving badge (`+N ↗`).
- [ ] Hover/long-press badge → tooltip splits: "N in transit (transfer)" vs. "N on order (PO)" when both are present.
- [ ] Complete the transfer (mark received) in the web admin.
- [ ] After next pull: badge clears; `available_qty` rises by the transferred quantity.

## 7. Confirmed PO incoming badge

- [ ] Create a confirmed Purchase Order toward Branch B for a product.
- [ ] After next pull: product tile shows the on-order label (`+N ↗`) with the PO source in the tooltip.
- [ ] Receive the PO in the web admin → badge clears; `available_qty` rises.

## 8. Seller identity — fiscally complete branch

- [ ] Complete a sale at Branch B (which has a complete fiscal identity).
- [ ] In the signed `SALE_RECEIPT` canonical payload: `seller.tax_number` = Branch B's `tax_id`; `seller.street/city/postal_code/country_code` = Branch B's address fields. Inspect via the sync batch upload in network inspector or the server `pos_receipts` record.
- [ ] For an `ACCOUNT_PAYMENT` (customer account charge): same seller block.
- [ ] Printed receipt header (thermal) shows Branch B's tax ID and address.
- [ ] Z report printed header shows Branch B's fiscal identity.

## 9. Seller identity — incomplete address fallback (atomic)

- [ ] Configure a test terminal at a branch that has `tax_id` but is missing at least one address field (e.g. no `address_street`).
- [ ] Complete a sale at that terminal.
- [ ] In the signed `SALE_RECEIPT` payload: `seller` block sources entirely from the company — company `tax_id`, company address. No mixing of branch tax number with company address.
- [ ] Printed receipt header shows the company identity, not the branch partial identity.

## 10. Coffee-shop (Menu) tenant — stock chrome absent

- [ ] Switch to the CoffeeShopSeeder tenant; claim a terminal.
- [ ] No stock chrome on product tiles (no availability count, no low-stock badge, no block/warn behavior).
- [ ] No stock pull happens (no `stock_last_sync` key in `sync_metadata`, no `location_stock` rows populated).
- [ ] Cart works normally with no stock enforcement.

---

## Pass criteria

All items above checked with no regressions on:
- Existing sale, refund, and account-charge flows
- Offline sync drain (no double-count)
- Seller identity never mixing fields from two sources

Sign off: _________________________ Date: _________________
