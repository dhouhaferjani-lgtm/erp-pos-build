# Ticket — POS stock-decrement dual-write (ReceiptCreationService vs PosCoreReceiptProjection)

**Opened:** 2026-06-01 (flagged during the WAC Serialization Foundation; deliberately out of scope there)
**Module:** POS
**Severity:** Medium — potential double-decrement / unclear single-writer ownership of `stock_level.quantity`
**Status:** Open — needs investigation to confirm whether both fire for the same receipt

## Problem
Two POS code paths each implement and call a private `decrementStock(...)` that writes the same `stock_level` row:

- `apps/api/app/Modules/Pos/Application/Services/ReceiptCreationService.php:849` (`decrementStock`), called at `:646` and `:1169`.
- `apps/api/app/Modules/Pos/Application/Projections/PosCoreReceiptProjection.php:838` (`decrementStock`), called via `decrementStockForLines` at `:807`/`:823`.

If both the receipt-creation service AND the core projection run for the same sale (e.g. authoring path + projection replay of the same fiscal event), the stock could be decremented twice, or the ownership of "who is the authoritative writer of `stock_level` for a POS sale" is ambiguous. This was flagged as a smell, not yet proven to double-decrement.

## What to investigate
1. Trace the live POS sale flow end-to-end: does a single completed sale invoke **both** `ReceiptCreationService::decrementStock` and `PosCoreReceiptProjection::decrementStock` for the same line, or are they mutually exclusive (e.g. one is legacy / one is the new fiscal-event projector path)?
2. Check idempotency: is the projection guarded by a `fiscal_event_id` / already-projected check that prevents a second decrement on replay? Does the authoring path skip the decrement when the projector owns it?
3. Confirm with a test: create one POS sale through the production path and assert the product's `stock_level.quantity` dropped by exactly the sold qty (not 2×).

## Proposed fix (pending investigation)
- Establish a **single authoritative writer** of `stock_level` for POS sales (most likely the `PosCoreReceiptProjection`, consistent with the fiscal-event-sourced architecture) and have the other path delegate to / not duplicate it.
- Note for the WAC foundation: both decrement paths are **pure decrements** (operate on an existing row, never recompute cost / create rows), so they correctly stay lock-free under `ProductCostLock`. This ticket is about decrement *duplication*, independent of the serialization seam.

## References
- WAC foundation plan: `docs/superpowers/plans/2026-05-29-wac-serialization-foundation.md` ("Out of scope" §).
- Memory: `project_pos_fiscal_event_engine` (device-authority / projection model), `project_archive_vs_recompute_data_flow`.
