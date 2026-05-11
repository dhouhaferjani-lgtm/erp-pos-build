# api.inventory cluster — triage 2026-05-09

**Branch:** `feat/tenant-isolation-sweep-execution`  
**Tip on entry:** `c501344f`  
**Owner reassigned:** codex → claude (per recalibration §16.1; --force at claim time)

## Cluster scope

39 callsites total. Pre-session state:
- 32 fixed (locked under prior codex flow)
- 5 pending (manual entries added 2026-05-04; not yet touched)
- 2 needs_recheck (017, 018; previously fixed + APPROVE'd by claude review, then `stale_mark`'d on stable_key change)

This triage closes the remaining 7.

## The 7 callsites + intended fix

| # | callsite_id | file:line | symbol | pattern | fix |
|---|---|---|---|---|---|
| 1 | api.inventory.017 | StockAdjustmentService.php:472 | getOrCreateStockLevel | bare `Location::findOrFail($locationId)` | Add caller-supplied `$expectedCompanyId` param + `Location::query()->where('company_id', $expectedCompanyId)->findOrFail($locationId)` |
| 2 | api.inventory.018 | StockAdjustmentService.php:536 | recordMovement | bare `Location::findOrFail($locationId)` | Same — scope by caller-supplied $expectedCompanyId (already takes $tenantId) |
| 3 | api.inventory.029 | CountingItemController.php:242 | triggerThirdCount validator | `'item_ids.*' => 'string\|exists:inventory_counting_items,id'` | Replace with closure validator scoped to parent counting_id (counting is forCompany-scoped on line 245) |
| 4 | api.inventory.030 | FraudTriggeredCountingService.php:56 | createCountingFromAlert | `User::where('email', 'system@autoerp.local')->first() ?? User::first()` | Scope by alert.tenant_id; fail loud if no system user for tenant |
| 5 | api.inventory.031 | InventoryCountingController.php:422 | createDraft | tenant_id not set on `new InventoryCounting` | Set `$counting->tenant_id = $user->tenant_id` |
| 6 | api.inventory.032 | WeightedAverageCostService.php:62, 211, 309 | recordPurchase/Sale/Return | StockLevel lock tuples lack company_id | Add `->where('company_id', $product->company_id ?? $location->company_id)` to the 3 lockForUpdate chains |
| 7 | api.inventory.033 | StockReservationService.php:237 | releaseBySource | no tenant/company scope | Take `$expectedCompanyId` from caller; filter `where('company_id', ...)`. Three callers: SalesOrderService, DeliveryNoteService, MarketplaceOrderService — pass company_id from upstream entity |

## Hostile-grep before scope-commit

Confirmed existing scoping in:
- StockReservationService:372 (Product scoped by tenant+company before findOrFail)
- StockReservationService:469 (Company scoped by tenant before findOrFail)
- WeightedAverageCostService:87/223/333 (Product locks scoped, callsites 020-028 fixed)
- InventoryCountingController:151/235/258/280/491/575/614/684/835/957 + CountingItemController:35/86/124/180/245 (forCompany scope on parent counting; child items via whereHas — already correct)
- LocationController:46 (company_id scoped)

Possible additional exposure surfaced (NOT in cluster scope):
- `InventoryOpeningService.php:220 — Company::findOrFail($batch->company_id)` defense-in-depth gap. Current path: $batch is loaded via repository upstream (assumed scoped); Company lookup itself is unscoped. Risk: if batch's company_id was forged upstream, Company::findOrFail returns cross-tenant. Logged to cross-cluster-observations; not added to this cluster (would push scope >7→8 mid-cluster; defer).

## Test-first targets

Single regression test file: `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php` (already exists, locked under prior cluster; ADD test methods, do not rewrite).

New test methods (one per callsite group):
- `it_scopes_get_or_create_stock_level_by_company` (017)
- `it_scopes_record_movement_location_lookup_by_company` (018)
- `it_rejects_cross_company_item_id_in_trigger_third_count_validator` (029)
- `it_scopes_fraud_system_user_to_alert_tenant` (030)
- `it_persists_tenant_id_on_inventory_counting_draft_create` (031)
- `it_locks_stock_level_tuples_with_company_id` (032)
- `it_scopes_release_by_source_to_caller_tenant_and_company` (033)

## State machine

- 017, 018 (needs_recheck): atomic-mutation script transitions to `in_progress` with `edit_applied` event recording the fix application context (no claim/start since those require pending).
- 029-033 (pending): `claim --force --reason "ownership reassigned 2026-05-09" --approved-by admin@otospex.com` per callsite, then `start`, then fix, then `submit`, then Codex review, then `review --verdict=fixed`.
