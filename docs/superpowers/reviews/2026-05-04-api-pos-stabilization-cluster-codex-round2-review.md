# Codex round-2 second-layer review — api.pos-stabilization cluster

Review date: 2026-05-05
Branch tip reviewed: 5a74481c
Reviewer: codex (round-2 second-layer review post-Opus APPROVE)

Verdict: REQUEST-CHANGES
Commit reviewed: 5a74481c

## Round-1 finding closure (verified independently)
- F1 (VoucherLedgerPushService): closed. `VoucherLedgerPushService` now constructor-injects `CompanyContext`; the `Terminal` lookup leads with authenticated `tenant_id` + `company_id` before applying the request terminal id, and `VoucherLedgerSyncRequest` now scopes `entries.*.terminal_id` with `ScopedExists::tenantAndCompany('pos_terminals', ...)`. I verified the HTTP path is `POST /api/v1/pos/voucher-ledger/sync` behind `auth:sanctum` + `SetPermissionsTeam`, and the service-tier bypass test exercises the company-context guard.
- F2 (composite_items): closed for `StoreReceiptRequest`. `lines.*.composite_item_id` is now scoped T+C with the same authenticated company context as `product_id`, and `ReceiptSyncService` scopes the downstream `CompositeItem` lookup by the already company-scoped terminal.
- F3 (pos_floors): closed for create/update table FormRequests. Both `CreateTableRequest` and `UpdateTableRequest` inject `CompanyContext` and use `ScopedExists::tenantAndCompany('pos_floors', ...)`.
- F4 (ReceiptSyncService:132): annotation exists, but Opus's own round-2 observation correctly identifies that the annotation is incomplete: the real duplicate-echo root is the unscoped `Receipt::where('idempotency_key', ...)` at line 128, before any company context is applied.

## Opus's 3 new round-2 observations — disposition
- Observation 1 (SyncReceiptsRequest): found in-scope leak. `POST /api/v1/pos/receipts/sync` is production HTTP-reachable. `SyncReceiptsRequest` validates `receipts.*.lines.*.product_id` and `receipts.*.lines.*.composite_item_id` only as nullable UUIDs. `ReceiptSyncService` correctly refuses to load a cross-tenant Product/CompositeItem snapshot, but then persists the raw attacker-supplied UUIDs into `pos_receipt_lines.product_id` / `composite_item_id`. A tenant-A user can therefore create a tenant-A receipt line that references tenant-B sellable rows, provided the malicious client supplies a matching offline fiscal hash for the server's fallback "Unknown Product" snapshot.
- Observation 2 (idempotency_key echo-leak): found in-scope leak. `ReceiptSyncService::syncSingleReceipt` checks `Receipt::where('idempotency_key', $payload->idempotencyKey)->first()` before resolving or scoping the submitted terminal. A tenant-A user who submits a tenant-B idempotency key receives the foreign receipt id, fiscal hash, and terminal hash state through the duplicate response branch.
- Observation 3 (TableManagementService): found in-scope leak. `POST /api/v1/pos/tables/{id}/release` reaches `TableManagementService::releaseTable`, which does `Table::lockForUpdate()->findOrFail($tableId)` and updates the row without company/tenant predicates. A tenant-A operator can release a tenant-B occupied table and receive the foreign table resource. `assignOrderToTable` has the same unscoped pattern, even though I did not find a direct controller route to that service method.

## New findings (round 2, second-layer)
1. REQUEST-CHANGES — Offline receipt sync persists cross-tenant sellable IDs. Evidence: `SyncReceiptsRequest.php:58-59` lacks scoped exists rules; `ReceiptSyncService.php:230-253` scopes lookup but `:286-289` persists the original IDs. Fix by scoping both `product_id` and `composite_item_id` in `SyncReceiptsRequest` and clearing/rejecting any service-tier sellable id that fails scoped resolution.
2. REQUEST-CHANGES — Cross-tenant duplicate receipt echo leaks fiscal data. Evidence: `ReceiptSyncService.php:128-149` returns duplicate data from a receipt selected only by client-controlled `idempotency_key`. Scope this lookup by authenticated company/tenant before returning duplicate metadata.
3. REQUEST-CHANGES — Table release mutates route-param foreign table. Evidence: `routes_tables.php:28` -> `TableController.php:145-148` -> `TableManagementService.php:184-192`. Scope the locked table lookup by authenticated company/tenant before update.
4. REQUEST-CHANGES — Order creation can assign a foreign tenant's table. This is the same table bug through a different production HTTP path: `CreateOrderRequest.php:50` validates `table_id` only as UUID, `OrderController.php:122-125` passes it through, and `OrderManagementService.php:115-153` locks and updates the table without company/tenant predicates while persisting the foreign `table_id` onto the tenant-A order.

## Audit exhaustiveness
- Read Opus round-1 and round-2 verdict files end-to-end.
- Read `git show 2b314480 af79d7c3 d91c0d3a 5a74481c` diffs.
- Verified no POS file changes exist between `5a74481c` and the current checkout (`eccc5b2a` only changes pricing), so POS code under test matches the reviewed commit.
- `vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php` -> OK, 40 tests / 170 assertions.
- `vendor/bin/phpunit tests/Feature/POS` -> OK, 559 tests / 1909 assertions; existing 17 skipped, 2 incomplete, 16 PHPUnit deprecations.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php` -> no errors.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php` -> pass.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> verified 1409 events across 299 callsites, 0 problems.
- Hostile grep produced 265 POS matches. The pass is not clean: the findings above are reachable HTTP leaks/mutations. Manual stubs `.039`, `.040`, `.041` have the expected generate -> claim -> start -> submit history, `fix_commit` pinned to `2b314480`, `af79d7c3`, `d91c0d3a` respectively, and regression tests recorded.

## Confidence
High on the three round-1 closures: the code now uses the canonical authenticated-context scoping pattern on the originally reported surfaces, and the regression suite pins them.

High on the `REQUEST-CHANGES` findings: each is reachable from production HTTP routes guarded only by ordinary POS permissions, each uses a route/body anchor before tenant/company scoping, and each either leaks foreign fiscal metadata or writes/mutates cross-tenant POS state. Opus already confirmed the first three mechanics; the only disagreement is severity. Under the user's threshold, exploitable reachable observations are closure-blocking.
