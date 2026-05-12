# api.inventory Cluster — Claude Review

Cluster: `api.inventory`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Stock levels, batch create/transfer/write-off, counting requests, reservations, weighted-average-cost service. Pre-sweep, batch and counting validators were under-scoped, and the WeightedAverageCostService had a defense-in-depth gap around StockLevel locking.

Inventory callsite total: **39 / 39 fixed**.

## Implementation summary

Top fix commits: `1eada1cb` (26 callsites), `1585720e` (7), `39718854` (6).

Top files: `CreateBatchRequest`, `WriteOffBatchRequest`, `TransferBatchStockRequest`, `BatchController`, `CreateCountingRequest`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`.

## Per-round review trail

Four implementation rounds + three Opus rounds:

- `2026-05-04-api-inventory-cluster-codex-{review,round2,round3,round4}-review.md`
- `2026-05-04-api-inventory-cluster-opus-{review,round2,round3}-review.md`
- `2026-05-04-api-inventory-reassigned-cluster-codex-round2-review.md`
- `2026-05-09-api-inventory-cluster-codex-review.md` (final adversarial pass)

## Gates evaluated

1. **Batch write-off / transfer / create**: every Rule::exists for batches, stock-levels, locations carries `ScopedExists::tenantAndCompany`.
2. **WAC service StockLevel lock**: defense-in-depth predicates re-validated (api.inventory.032).
3. **Reservation release scope**: `StockReservationService::releaseBySource` caller-scope verified (api.inventory.033).
4. **Fraud-triggered counting**: user fallback path now scoped (api.inventory.030).

## Non-blocking follow-ups

None at cluster level. Workshop-related WorkOrder repository defect surfaced during inventory round-4 was reassigned to `api.workshop` (see Workshop cluster review).

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`
