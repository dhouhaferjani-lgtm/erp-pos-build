# Counting error codes — inventory/costing review round 1

- **Reviewer:** `inventory-costing-reviewer`
- **Model:** Opus
- **Mode:** read-only

## Verified

- `InventoryCountingController.php:1164-1168`: malformed product IDs return `PRODUCT_NOT_FOUND` alongside the unchanged `Invalid product ID; expected a UUID` message.
- `InventoryCountingController.php:1176-1180`: unknown or other-company product IDs return `PRODUCT_NOT_FOUND` alongside the unchanged `Product not found for current company` message.
- `InventoryCountingController.php:1207-1211`: barcode lookup misses return `PRODUCT_NOT_FOUND` alongside the unchanged barcode-specific message.
- `InventoryCountingController.php:1221-1225`: duplicate products return `PRODUCT_ALREADY_IN_COUNT` alongside the unchanged duplicate message.
- Successful and mixed-batch persistence paths are unchanged. The tests exercise real HTTP/database behavior and discriminate the prior lowercase/missing-code implementation.
- The diff touches no inventory quantity, costing, movement, batch/lot, fiscal, projection, or precision path.
- PHPStan level 8 was independently verified by the reviewer. The implementation lane separately verified Pint.

## Findings

- **Important, non-blocking:** confirm the mobile client does not depend on the provisional lowercase `invalid_product_id` value. A read-only follow-up grep of `/Users/houssamr/Projects/syneriva/erp-mobile` found no consumer of `invalid_product_id` or lowercase `product_not_found`; the counting sync service already prefers `PRODUCT_ALREADY_IN_COUNT`.
- **Minor:** the unrelated malformed-barcode branch still emits lowercase `invalid_barcode`, and the catch-all exception branch remains codeless. The task supplied no replacement codes for those branches, so changing them would widen this contract change.
- **Minor:** prior A6 review records describe the provisional lowercase codes. They remain immutable historical review records; this review records the superseding contract.
- **Minor:** barcode-driven duplication is not separately tested, although it reaches the same strict duplicate check with a UUID string.

Nothing blocking remains for the requested typed-code contract.

**VERDICT: APPROVED**
