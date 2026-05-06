Codex round-4 second-layer review — api.pos-stabilization cluster

Review date: 2026-05-06
Branch tip reviewed: 0d15e4be6a0d806118e0b75775c9ade13252f6d7
Reviewer: codex (round-4 second-layer review)

Verdict: REQUEST-CHANGES
Commit reviewed: de7078d0cd544908ac310b596c4e8daa9cc8f7e3, 0d15e4be6a0d806118e0b75775c9ade13252f6d7

Round-3 finding closure

- Order/kitchen mutation routes: PARTIAL. The live mutation risk is closed at the main service locks: `OrderManagementService::{addLine,modifyLine,removeLine,sendToKitchen,closeOrder,cancelOrder,updateLineStatus,bumpOrder,markOrderServed}` now resolve CompanyContext and apply `tenant_id`, `company_id`, and `id` before `lockForUpdate()->firstOrFail()`. `closeOrder` and `cancelOrder` table releases now scope `pos_tables` by the already-scoped order tenant/company before `id`. Regression tests were added for cross-tenant cancel, send-to-kitchen, close, remove-line, and kitchen bump denial/no-mutation, plus one SQL-log invariant for the cancel locked SELECT. However, the controller readback portion of the round-3 finding is not fully closed against the stated cluster invariant. `OrderController::addLine`, `modifyLine`, and `removeLine` reload with `Order::where('company_id', $companyId)->...->findOrFail($id)` only (`apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:180`, `237`, `281`). `KitchenDisplayController::updateLineStatus`, `bump`, and `served` do the same company-only reload (`apps/api/app/Modules/POS/Presentation/Controllers/KitchenDisplayController.php:64`, `100`, `129`). The invariant for this review explicitly requires both authenticated `tenant_id` and `company_id` predicates on every route-param anchored read.

New findings (round 4, second-layer)

- REQUEST-CHANGES: Route-param anchored POS order readbacks remain structurally under-scoped. In addition to the controller reloads above, service response readbacks use `$order->fresh(['lines'])` after route-param anchored mutations in `sendToKitchen`, `closeOrder`, `cancelOrder`, `markOrderServed`, and `bumpOrder` (`OrderManagementService.php:404`, `460`, `516`, `611`, `663`). Those `fresh()` reads issue primary-key reloads without explicit authenticated tenant/company predicates. `OrderController::show` also reads route `{id}` with company-only scope (`OrderController.php:98`). This may not reproduce the original live foreign mutation after the new service guard, but it still violates the cluster invariant and leaves the SQL shape inconsistent with the required tenant-and-company chain pattern.

Audit exhaustiveness

- Inspected `git show` for `de7078d0` and `0d15e4be`.
- Verified manual stubs `.046` through `.049` exist, are under_review, and are pinned to `de7078d0`. Note: stubs `.048` and `.049` record `expected_scope: company_only`, which conflicts with this review's stated cluster invariant of `tenant_and_company`.
- Ran the hostile grep. The material round-3 locked order mutation lookups are now scoped; remaining relevant hits include company-only controller reads/reloads and service `fresh()` readbacks noted above.
- Gates:
  - `vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS, 52 tests, 222 assertions.
  - `vendor/bin/phpunit tests/Feature/POS`: PASS, 571 tests, 1961 assertions; PHPUnit reported 16 deprecations, 17 skipped, 2 incomplete.
  - `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS.
  - `./vendor/bin/pint --test app/Modules/POS app/Modules/Loyalty tests/Feature/POS/PosStabilizationTenantIsolationTest.php`: PASS.
  - `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: PASS, verified 1489 events across 314 callsites, 0 problems.

Confidence

High that the original cross-tenant mutation route bug is closed at the service lock/update layer. High that the round-3 controller reload/readback closure is incomplete under the explicit both-predicate invariant. Medium on broader POS exhaustiveness: the requested grep was reviewed, but this pass focused on the api.pos-stabilization order/kitchen surfaces and sibling route-param order reads.
