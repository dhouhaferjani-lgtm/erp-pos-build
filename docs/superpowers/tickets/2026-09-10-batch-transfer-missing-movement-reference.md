# Per-lot transfer fails before response serialization

Status: open; disclosed pre-existing residual, not an A-1a blocker. Owner: inventory movement seam.
Schedule: after T-2 S1 merges.
Discovered while adding W-LOT-A-1a fix-round-2 MAJOR 1 real HTTP coverage.

`POST /api/v1/batches/{uuid}/transfer` with valid same-company source/destination, sufficient stock and permitted locations returns 500 on PostgreSQL. `BatchStockService::transferBatchStock` calls `recordBatchMovement` without `movementId` at `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:523` and `:531`. The insert at `:563` therefore omits `movement_id`, which is a non-null foreign UUID at `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:22`. PostgreSQL raises SQLSTATE 23502; the service transaction rolls back before a successful response can be serialized.

Both the service and migration have an empty diff against pre-lane `4373ba2f6`. Retained real HTTP failure: `docs/sessions/wlota1a/r2-green-batch-m1.txt` (local ignored evidence; its name records the attempted green run, not its outcome). The real success regression remains intact behind the explicit `WLOTA1A_RUN_KNOWN_REDS=1` opt-in; the default CI run skips it with this ticket named. No mock or nullable-column alteration is used.

A repair needs the owning inventory movement workflow to provide real movement references while conserving aggregate and batch stock, including reserved quantities, company/tenant scope and rollback. Do not invent movement IDs or relax the foreign key. The BatchStockService explicitly owns batch-level stock only; confirm the aggregate writer and transfer audit trail before changing it. This exceeds response serialization. The orchestrator’s 2026-09-10 round-2b ruling (owner may overturn) declares this **out of scope for A-1a**, owned by the inventory movement seam and scheduled **after T-2 S1 merges**. T-2 S1 is changing `StockAdjustmentService::receive()/issue()` transfer-linkage parameters; repairing it here would collide at merge. A-1a leaves the batch service, aggregate writer and migration unchanged.

Acceptance: the retained real HTTP regression succeeds with membership-scoped totals/stock rows; verify linked stock movements, source/destination stock conservation and rollback on denial/failure. No deployment or activation is authorized by this ticket.

## Acceptance harness and current exposure

The two methods in `apps/api/tests/Feature/BatchExpiry/BatchReadLocationScopeTest.php` are the acceptance harness:

- `test_transfer_controller_response_uses_all_membership_locations` retains the real HTTP success contract and runs only with `WLOTA1A_RUN_KNOWN_REDS=1`.
- `test_transfer_missing_movement_reference_ticket_pins_failure_and_rollback` uses the same lot/location fixture, with aggregate stock seeded as well. It pins PostgreSQL SQLSTATE 23502 for `inventory_batch_movements.movement_id` and compares the original seven tables plus `stock_levels`, `stock_movements` and `inventory_batch_movements` before/after the HTTP request. All ten tables, including `inventory_batch_stock`, are unchanged.

When the writer is repaired, delete the failure pin and remove the success test’s skip together. Keep the success test and extend movement-linkage/conservation coverage in the owning inventory work.

Verified caller census on 2026-09-10:

```sh
rg -n -i 'batches.{0,160}transfer|transfer.{0,160}batches|transferbatch|batchtransfer' apps/web/src apps/pos/src
```

The only three matches are the local `isBatchTransferableAtSource` helper definition/use in `CreateStockTransferPage.tsx` (lines 83, 130, 268). Inspection of `features/batches/api/batches.ts` finds no transfer operation; `features/stock-transfers/api/stockTransferApi.ts:9` uses the separate `/stock-transfers` endpoint. No first-party web or POS client calls the per-lot transfer route today. Priority consequence: API/mobile exposure only; no current first-party web/POS flow is blocked. This census does not establish whether external API/mobile callers exist.

Round-2b evidence is retained locally under `docs/sessions/wlota1a/`: `r2b-pg-default.txt`, `r2b-pg-known-red.txt`, and `r2b-transfer-caller-census.txt`.
