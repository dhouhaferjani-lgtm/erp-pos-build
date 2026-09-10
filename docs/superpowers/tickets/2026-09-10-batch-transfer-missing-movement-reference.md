# Per-lot transfer fails before response serialization

Status: review blocker; scope ruling requested. Owner: BatchExpiry / Inventory.
Discovered while adding W-LOT-A-1a fix-round-2 MAJOR 1 real HTTP coverage.

`POST /api/v1/batches/{uuid}/transfer` with valid same-company source/destination, sufficient stock and permitted locations returns 500 on PostgreSQL. `BatchStockService::transferBatchStock` calls `recordBatchMovement` without `movementId` at `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:523` and `:531`. The insert at `:563` therefore omits `movement_id`, which is a non-null foreign UUID at `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php:22`. PostgreSQL raises SQLSTATE 23502; the service transaction rolls back before a successful response can be serialized.

Both the service and migration have an empty diff against pre-lane `4373ba2f6`. Retained real HTTP failure: `docs/sessions/wlota1a/r2-green-batch-m1.txt` (local ignored evidence; its name records the attempted green run, not its outcome). The new `BatchReadLocationScopeTest::test_transfer_controller_response_uses_all_membership_locations` remains a failing regression test; no mock, nullable-column alteration or skip conceals this path.

A repair needs the owning inventory movement workflow to provide real movement references while conserving aggregate and batch stock, including reserved quantities, company/tenant scope and rollback. Do not invent movement IDs or relax the foreign key. The BatchStockService explicitly owns batch-level stock only; confirm the aggregate writer and transfer audit trail before changing it. This exceeds response serialization and requires the requested scope ruling.

Acceptance: the retained real HTTP regression succeeds with membership-scoped totals/stock rows; verify linked stock movements, source/destination stock conservation and rollback on denial/failure. No deployment or activation is authorized by this ticket.
