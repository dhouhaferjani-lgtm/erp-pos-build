# api.inventory cluster — Opus round-3 adversarial review

Verdict: APPROVE
Commit reviewed: 44207410

Reviewer: Opus (Anthropic)
Cluster owner: Codex
Branch: feat/tenant-isolation-sweep-execution
Round-1 fix commit: 1eada1cb (28 callsites closed)
Round-2 remediation: c521b051 (StockLevel/StockMovement company_id leak + WAC regression-coverage pin)
Round-3 remediation: 44207410 (StockReservationService::reserveForWorkOrder cross-company reservation closure)
Predecessors:
- Opus round-2 (APPROVE): docs/superpowers/reviews/2026-05-04-api-inventory-cluster-opus-round2-review.md
- Codex round-2 (REQUEST-CHANGES): docs/superpowers/reviews/2026-05-04-api-inventory-cluster-codex-round2-review.md (HIGH 1, LOW 2)

---

## 1. Scope confirmation

`git show 44207410 --name-only` returns exactly 5 files, all on the api.inventory critical path:

1. `apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php`
2. `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
3. `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php`
4. `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`
5. `apps/api/tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php`

No Document, Service, Catalog, Accounting, POS, Voucher, Treasury, or Loyalty module files touched. The Workshop file is the InventoryReservationAdapter only — the legitimate cross-module bridge that owns the reserveForWorkOrder call. No scope creep.

`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` returns empty: POS/Voucher surface is untouched across the entire branch.

## 2. Codex round-2 HIGH 1 closure verification

Codex round-2 HIGH Finding 1 was: "StockReservationService::reserveForWorkOrder derives Company from `StockLevel::where('product_id', $productId)->first()->company_id`, allowing a forged cross-company productId on Workshop approval to anchor a reservation against a foreign company's stock."

I verified each of the four required changes:

### 2.1 Interface signature requires tenantId + companyId

`apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php` lines 31–39:

```php
public function reserveForWorkOrder(
    string $tenantId,
    string $companyId,
    string $productId,
    string $quantity,
    string $workOrderLineId,
    string $workOrderId,
    ?\DateTimeImmutable $expiresAt,
): StockReservation;
```

The doc-comment (lines 22–28) explicitly states the rationale and references the round-2 finding. A future implementer cannot accidentally drop tenantId/companyId without re-reading why they're required. Defense-by-design.

### 2.2 StockReservationService::reserveForWorkOrder scopes the StockLevel lookup

`apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php` lines 437–488:

- Signature now takes `string $tenantId, string $companyId` as the first two parameters (matches the interface).
- StockLevel lookup at lines 454–458 filters by `tenant_id = $tenantId AND company_id = $companyId AND product_id = $productId`. A forged cross-company productId returns no row → `RuntimeException` at line 461 → no reservation is created.
- Company lookup at lines 467–469 is also tenant-scoped: `Company::query()->where('tenant_id', $tenantId)->findOrFail($companyId)`. Even if companyId leaked across tenants in the request payload, it now requires the matching tenant_id to resolve.
- Inline comment at lines 446–452 explains why the scope is required and references the round-2 finding.

### 2.3 InventoryReservationAdapter::reserveFor passes wo.tenant_id + wo.company_id

`apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php` lines 53–61:

```php
$reservation = $this->reservations->reserveForWorkOrder(
    tenantId: $wo->tenant_id,
    companyId: $wo->company_id,
    productId: $line->product_id,
    quantity: $quantity,
    workOrderLineId: $line->id,
    workOrderId: $wo->id,
    expiresAt: null,
);
```

The WorkOrder model carries both columns (workshop schema confirmed via existing repo queries — see EloquentWorkOrderRepository::paginate filtering by tenant_id+company_id). The adapter is the only caller of reserveForWorkOrder in the codebase (verified — see §3).

### 2.4 New regression test asserts cross-company productId is rejected

`apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php` lines 633–671:

- Seeds a foreign-tenant StockLevel for productB+locationB belonging to tenantB+companyB.
- Calls `$service->reserveForWorkOrder(tenantId: tenantA, companyId: companyA, productId: productB, ...)` — i.e. caller scope is tenantA but the productId belongs to tenantB.
- Asserts `RuntimeException` with message matching `/no StockLevel row found/`.
- Pre-fix code path would have matched productB's StockLevel under tenantB+companyB and proceeded toward `reserve()` with company=companyA (which would then hit a separate firstOrFail at line 101–105, but with a different exception message and only because companyA happens not to have a StockLevel for that product+location combination — which is a defense-in-depth happy accident, not a contract guarantee).

### 2.5 Test honesty proof

I independently reverted the tenant_id+company_id predicates in StockReservationService::reserveForWorkOrder (lines 454–456) to the pre-fix `StockLevel::where('product_id', $productId)->orderByRaw(...)->first()` form and ran the new test:

```
1) Tests\Feature\Inventory\InventoryTenantIsolationTest::test_reserve_for_work_order_rejects_cross_company_product
Failed asserting that exception message 'No query results for model [App\Modules\Inventory\Domain\StockLevel].' matches '/no StockLevel row found/'.
```

The test fails as expected — the assertion `expectExceptionMessageMatches('/no StockLevel row found/')` does not match the alternative pre-fix exception path. Restoring the predicates returns the test to green. The assertion is precise enough to detect both the behavioral regression (no scope on the lookup) AND the message regression (wrong exception type). Test honesty is confirmed.

I noted during the test-honesty exercise that the pre-fix code would have failed at the secondary lock query in `reserve()` at lines 101–105 (`where('product_id', $productId)->where('location_id', $locationId)->where('company_id', $company->id)->firstOrFail()`) for the specific test seed (companyA has no StockLevel for productB+locationB). This narrows the practical exploit window described in the Codex round-2 finding — the leak required BOTH a forged productId AND a colluding StockLevel row in the attacker's company. The fix is still correct: it closes the gap at the contract layer, prevents future callers from misusing the API, and makes the failure mode an unambiguous "reservation rejected" RuntimeException rather than a downstream firstOrFail. Defense-in-depth justified.

## 3. Cross-module reachability check

`grep -rn "reserveForWorkOrder" apps/api/app --include='*.php' | grep -v /tests/`:

- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php:53` — call site (updated).
- `apps/api/app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php:31` — interface declaration (updated).
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:437` — implementation (updated).
- `apps/api/app/Modules/Inventory/Domain/Enums/ReservationSource.php:33` — comment reference only.

Only ONE production caller. Signature change is fully covered.

`grep -rnE "InventoryReservation|StockReservationService|StockLevel::|StockMovement::" apps/api/app/Modules/Workshop --include='*.php'` finds:

- `WorkOrderTransitionService.php` injects `InventoryReservationAdapter` and calls `reserveFor($wo)` / `releaseFor($wo, $reason)` — both go through the adapter.
- `InventoryReservationAdapter.php` (already audited).
- Documentation comments in events.

No bypass path. Workshop never reaches Inventory's StockLevel/StockMovement directly — the hexagonal boundary holds.

## 4. Hostile grep — other service-tier scope leaks in Inventory

Two patterns checked:

### 4.1 `find()|first()` on StockLevel/StockReservation/StockMovement in Application + Domain

`grep -rnE "StockLevel::|StockReservation::|StockMovement::" apps/api/app/Modules/Inventory/Application apps/api/app/Modules/Inventory/Domain --include='*.php' | grep -v /tests/ | grep -E "first\(|find\("` returns nothing — no other unscoped find/first lookups remain in the Inventory service tier. The audit-grep is now clean.

### 4.2 `->company_id` property reads in Inventory services

I traced every read:
- `StockReservationService.php:218,304` — `companyId: $reservation->company_id` (event payload — reservation is tenant-coherent because creation requires the explicit tenantId/companyId now).
- `InventoryCountingService.php:638` — `companyId: $counting->company_id` (counting model is tenant-scoped via repo).
- `WeightedAverageCostService.php:74,85,104,156,221,241,321,331,350,401,472` — round-2 audit closed these (caller-scope inputs validated).
- `InventoryOpeningService.php:77,220` — `$batch->company_id` (batch is tenant-scoped).
- `GoodsReceiptService.php:90,115` — `$purchaseOrder->company_id` (PO is tenant-scoped).
- `FraudTriggeredCountingService.php:76` — `$alert->company_id` (alert is tenant-scoped).

Every Service-tier `->company_id` read derives from a model row that was itself loaded via a tenant-scoped repository or controller-scoped flow. No new leaks.

### 4.3 StockAdjustmentService internal lock query

`StockAdjustmentService::lockStockLevel` (lines 494–514) does `StockLevel::where('product_id')->where('location_id')->lockForUpdate()->first()` without tenant scope. This was reviewed and accepted in earlier rounds:
- It is a private helper, not part of the public surface.
- It is preceded by `getOrCreateStockLevel` which scopes Product by Location's company (line 473).
- The pair is internal to StockAdjustmentService; external callers go through the Service's public methods which require tenant-coherent inputs.

Pre-existing accepted pattern. Not a round-3 regression.

## 5. Pre-existing Inventory gap noted (not in scope for round-3)

`StockReservationService::releaseBySource` (lines 237–255) filters reservations by `source_type` + `source_id` only, with no tenant_id or company_id predicate. Callers:
- `MarketplaceOrderService.php:194`
- `SalesOrderService.php:223`
- `DeliveryNoteService.php:181`
- `StockReservationService::releaseForWorkOrder:501` (internal)

In practice, `source_id` is a UUID v4 (122-bit random) supplied by the caller module, so cross-tenant collision is statistically negligible. However, this is a defense-in-depth gap: a deliberately-forged source_id collision could release a foreign tenant's reservations. Codex round-2 did not flag this. It's pre-existing and out of scope for round-3 (the round-3 fix is constrained to reserveForWorkOrder per the brief).

**Recommendation:** Add to the api.inventory manual-stub follow-up tracker so the cluster lead can decide whether to fold a tenantId/companyId predicate into releaseBySource in a separate commit. This is an OBSERVATION, not a blocker for round-3 approval.

## 6. Workshop-side observation (api.workshop responsibility)

While verifying the round-3 chain, I noted `EloquentWorkOrderRepository::findById` and `findForUpdate` (lines 15–23) load by primary key without tenant_id+company_id predicates. The `WorkOrderTransitionController::requireWorkOrder` private helper (lines 166–176) does check `$wo->company_id !== $companyId` AFTER loading, so cross-company access is rejected at the controller layer — but `tenant_id` is not checked at all. In a schema-per-tenant architecture this is bounded by the database connection, but defense-in-depth would add the tenant predicate.

This is api.workshop cluster's responsibility, not api.inventory's. Crucially, even if the controller-layer check were bypassed and a foreign WorkOrder were passed to InventoryReservationAdapter::reserveFor, the round-3 fix would still anchor the reservation to that WorkOrder's own tenant_id+company_id (which carry the foreign tenant's identifiers, not the attacker's session) — so Inventory would correctly reserve against the WorkOrder's own company. The Inventory-side contract is sound regardless of upstream Workshop hardening.

I will flag this in api.workshop notes if I review that cluster; it does not affect the round-3 verdict here.

## 7. LOW 2 — manual-stub follow-ups tracker

Codex round-2 LOW Finding 2 asked for the manual-stub follow-ups to be tracked. Since this round-3 commit is scoped to HIGH 1 only (per the brief), the LOW 2 tracking is left to the cluster owner (Codex) to update in `tenant-isolation-sweep-inventory.yml` or a manual-stub tracker. Not a blocker — already an accepted "pending" state.

## 8. Required gates

All four gates green:

| Gate | Result |
|---|---|
| `vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php` | OK (20 tests, 54 assertions) |
| `phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory/Application/Services/StockReservationService.php app/Modules/Inventory/Application/Contracts/InventoryReservationServiceInterface.php app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php` | OK — No errors |
| `phpstan` over the broader Inventory module | 59 errors, all pre-existing baseline in test files (GoodsReceiptTest, InventoryEventsTest etc.); dev baseline is 61 errors — round-3 reduces total error count |
| `php artisan sweep:inventory:verify-history --inventory-path=...` | verified 1093 event(s) across 268 callsite(s); 0 problem(s) |
| `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | empty |

## 9. Verdict rationale

- Codex round-2 HIGH 1 is closed at the correct architectural layer: defensive tenantId+companyId parameters at the cross-module interface, scoped lookup in the implementation, explicit pass-through in the adapter, regression test pinned with a precise message-matching assertion.
- Test honesty is verified by independent mutation: reverting the predicates fails the new test.
- Scope is exactly 5 files. No spillover into Document, Service, Catalog, Accounting, POS, Voucher, Treasury, or Loyalty.
- Hostile grep finds no other unscoped service-tier model lookups in the Inventory module.
- Cross-module reachability check confirms only one caller of reserveForWorkOrder (the adapter) — signature change is fully covered.
- All required gates green: PHPUnit, PHPStan (changed files), verify-history, POS surface diff.
- Pre-existing observations (releaseBySource defense-in-depth; Workshop findById tenant predicate) are flagged as observations, not blockers — both are out of scope for the round-3 brief and neither is a regression introduced by this commit.

## 10. Confidence

High. The fix correctly addresses Codex round-2 HIGH 1 with a contract-layer guarantee that cannot be re-introduced without a deliberate signature change. The regression test pins both the behavioral and the exception-message contract. No new findings warrant a REQUEST-CHANGES verdict on this round.

Verdict: APPROVE
Commit reviewed: 44207410
