# Codex round-3 second-layer review - api.pos-stabilization cluster

Review date: 2026-05-05
Branch tip reviewed: a30290ae
Reviewer: codex (round-3 second-layer review)

Verdict: REQUEST-CHANGES
Commit reviewed: 4e5edc2c

Round-3 commits inspected: c2f37aea, a7860ef4, 6ca27b7a, 4e5edc2c, c0d42f56.

## Round-2 finding closure
- Finding 1 (SyncReceiptsRequest sellable FKs): CLOSED. c2f37aea scopes `receipts.*.lines.*.product_id` and `composite_item_id` with `ScopedExists::tenantAndCompany(...)` in `SyncReceiptsRequest` and rewrites persisted receipt-line FK columns from scoped-resolved IDs in `ReceiptSyncService`, so unresolved cross-tenant product/composite IDs persist as null while the existing Unknown Product snapshot fallback remains. Regression coverage added validator denial tests for both foreign product and foreign composite IDs.
- Finding 2 (idempotency_key echo-leak): CLOSED. a7860ef4 scopes the duplicate lookup by CompanyContext `tenant_id` and `company_id` before `idempotency_key`, and also scopes the duplicate echo terminal lookup to the existing receipt's tenant/company. The regression test proves cross-tenant key collisions are not duplicate responses, do not echo the foreign receipt ID/hash, leave the foreign receipt unchanged, and captures SQL with tenant/company predicates. Minor test note: it asserts predicate columns in the SQL string, not exact binding values, but the production code supplies CompanyContext values directly.
- Finding 3 (TableManagementService): CLOSED. 6ca27b7a scopes both `assignOrderToTable` and `releaseTable` locked table lookups by authenticated tenant/company before `id`, then `lockForUpdate()->firstOrFail()`. Regression coverage includes cross-tenant release denial/no-mutation and a structural SQL predicate check.
- Finding 4 (CreateOrderRequest + OrderManagementService): CLOSED. 4e5edc2c scopes `CreateOrderRequest::table_id` with `ScopedExists::tenantAndCompany('pos_tables', ...)` and scopes `OrderManagementService::createOrder` locked table lookup by the resolved terminal's tenant/company. Regression coverage rejects a foreign table ID through the HTTP validator.

## New findings (round 3, second-layer)
- REQUEST-CHANGES - Order and kitchen mutation routes still lock and mutate route-supplied foreign order IDs without tenant/company predicates. `OrderController` passes path IDs directly into `OrderManagementService` for `POST /pos/orders/{id}/lines`, `PATCH/DELETE /pos/orders/{id}/lines/{lineId}`, `POST /pos/orders/{id}/send-to-kitchen`, `POST /pos/orders/{id}/close`, and `POST /pos/orders/{id}/cancel` (`apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:157`, `207`, `245`, `283`, `308`, `342`). The service then performs bare locked lookups such as `Order::lockForUpdate()->findOrFail($orderId)` or `Order::lockForUpdate()->with('lines')->findOrFail($orderId)` with no CompanyContext predicate (`apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:190`, `253`, `304`, `331`, `372`, `414`). Several controller response reloads are also bare `Order::with(...)->findOrFail($id)` (`OrderController.php:170`, `217`, `251`). A tenant-A POS operator who knows a tenant-B open/kitchen order UUID can mutate that foreign order (send to kitchen, close to receipt, cancel, modify/remove known line IDs) and receive the foreign order resource back. `closeOrder` and `cancelOrder` also release `Table::where('id', $order->table_id)` without tenant/company predicates (`OrderManagementService.php:388`, `435`), compounding the foreign mutation. The same pattern is route-reachable through `KitchenDisplayController`: `PATCH /pos/kitchen/orders/{orderId}/lines/{lineId}/status`, `POST /pos/kitchen/orders/{orderId}/bump`, and `POST /pos/orders/{orderId}/served` call unscoped service methods (`KitchenDisplayController.php:57`, `84`, `104`; service lookups at `OrderManagementService.php:460`, `502`, `537`) and then reload bare foreign orders (`KitchenDisplayController.php:60`, `87`, `107`). Fix by anchoring every route-order locked lookup and post-mutation reload on authenticated CompanyContext tenant/company before `id`, and add cross-tenant no-mutation/no-leak tests for at least cancel/send-to-kitchen plus one kitchen route.

## Audit exhaustiveness
- Ran `git show` for c2f37aea, a7860ef4, 6ca27b7a, 4e5edc2c, and c0d42f56.
- Confirmed c0d42f56 added manual stubs `api.pos-stabilization.042` through `.045` with `cluster_id: api.pos-stabilization`, `status: under_review`, and fix commits pinned respectively to c2f37aea, a7860ef4, 6ca27b7a, and 4e5edc2c.
- Searched POS for `idempotency_key`, client dedupe/correlation keys, sync requests, bare `exists:`, `find`, `findOrFail`, and `lockForUpdate` callsites. The new order/kitchen route-order issue above is the material unremediated production mutation surface found in this pass.
- Gate results:
  - `vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS, 46 tests, 200 assertions.
  - `vendor/bin/phpunit tests/Feature/POS`: FAIL, 565 tests, 1931 assertions, 1 failure, 17 skipped, 2 incomplete. Failing test: `Tests\Feature\POS\ReceiptSyncServiceVoucherRedemptionTest::test_offline_sync_with_store_voucher_payment_invokes_redemption_service`, expected `SyncStatus::Synced` but got `SyncStatus::Failed`.
  - Reran the failing test alone with `--filter test_offline_sync_with_store_voucher_payment_invokes_redemption_service`: PASS, 1 test, 14 assertions. This suggests order-dependent state leakage or flake; the required full POS gate still did not stay green in this review run.
  - `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS.
  - `./vendor/bin/pint --test app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS.
  - `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: PASS, verified 1437 events across 303 callsites, 0 problems.

## Confidence
High on the four round-2 closures: the prescribed validator and service-tier patterns are present and covered by targeted regressions. High on the new order/kitchen finding: route handlers pass attacker-controlled path IDs directly into bare locked `Order` lookups that mutate and return the loaded row. Medium on broader exhaustiveness because the POS module still has many legacy route-level post-load guards and scanner-blind matches; the review focused on material unscoped pre-mutation/readback paths in the requested cluster.
