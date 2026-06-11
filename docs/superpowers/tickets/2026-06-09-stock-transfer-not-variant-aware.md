# Ticket — Stock transfers (T1) are not variant-aware (T2 integration gap)

**Opened:** 2026-06-09 (surfaced during the parapharmacy multi-branch e2e verification)
**Module:** Inventory / Stock transfers (T1) × Product variants (T2)
**Severity:** Medium — sized goods (a core parapharmacy product class) cannot be transferred between branches at variant grain
**Status:** Open

## Problem
T1 (stock transfers, `2026_05_28`) landed before T2 (variants, `2026_06_02`). The T2 ripple added `variant_id` to `stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `document_lines`, `pos_receipt_lines`, etc. — but **not** to the stock-transfer tables:

- `stock_transfer_lines` has **no `variant_id` column** (confirmed: no `add_variant_id_to_stock_transfer*` migration exists).
- `StoreStockTransferRequest` accepts only `lines.*.product_id` (no `variant_id`) — confirmed: 0 occurrences of "variant" in the request.

Consequently a transfer of a variant product moves stock at the **product** level. But for a variant product, on-hand stock is tracked per variant (`stock_levels.variant_id IS NOT NULL`); there is no product-level (variant_id NULL) stock row to decrement/increment. So transferring sized goods (orthopedic shoes EU 38–42, compression stockings) between branches either no-ops the wrong row, fails, or corrupts the variant-grain ledger.

## Why it matters
- Parapharmacy sells sized goods across branches; "transfer 3× EU 40 shoes from the warehouse to Lyon" is a real day-one operation.
- The variant-aware partial unique index on `stock_levels` (`stock_levels_with_variant`) means product-level and variant-level rows are distinct; a transfer that ignores `variant_id` writes to the wrong grain.

## Proposed fix
1. Add `variant_id` (nullable uuid, FK to `product_variants`) to `stock_transfer_lines` (+ `stock_transfer_line_batch_allocations` if batch-tracked variants are in scope), as a **tenant** migration (mind the DB-per-tenant placement rule — see the companion fix in this branch; tenant tables go in `database/migrations/tenant/` and `tenant_id` is a plain indexed uuid, not an FK to central `tenants`).
2. Accept `lines.*.variant_id` in `StoreStockTransferRequest` (nullable; required when the product has variants — mirror the validation T2 added on the sell/receive paths).
3. Decrement/increment the variant-grain `stock_levels` row (`tenant, product, variant, location`) on complete; thread `variant_id` through the transfer service + WAC `recordCostAdjustment` seam.
4. Tests: transfer N×(EU 40) warehouse→shop, assert the variant row moved and the other sizes are untouched.

## Smoke-test impact
The parapharmacy smoke doc marks "transfer a sized SKU between branches" as a **Known gap** (Flow 4b); testers transfer a non-variant product instead. Non-variant transfer is verified working end-to-end (create→complete moves stock; see the smoke doc Flow 4).

## References
- T1 migration docstring already lists "Per-location tax_id / branch_code" out of scope but is silent on variants (it predates T2): `database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php`.
- T2 ripple migrations: `database/migrations/tenant/2026_06_02_1000{05..15}_add_variant_id_to_*` (note the absence of a stock_transfer entry).
- Memory: `project_t2_variants_impl`, `project_inventory_transfer_wac_remediation`.
