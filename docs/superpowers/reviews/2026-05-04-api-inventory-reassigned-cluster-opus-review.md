# Opus adversarial review — api.inventory reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: d54151d0
Reviewer: opus

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 39718854

## Summary

The 6 reassigned callsites (api.unmapped.006–010, .014) are correctly closed.
All 6 dedicated tests fail on pre-fix code and pass on post-fix; the full
`InventoryTenantIsolationTest` runs 24 tests / 71 assertions green. PHPStan
(level 8) and Pint pass on changed files. POS surface diff `dev..HEAD` is
empty (no UI/feature paths touched; only an unrelated audit-tool fixture).

The `locations` single-predicate `ScopedExists::company` is correct:
`2025_11_30_105000_create_locations_table.php` declares only `company_id`
(no `tenant_id`), and `CompanyContextMiddleware::handle` enforces
`userHasAccessToCompany()` BEFORE setting context — so a tenant-A user
cannot coerce CompanyContext to a tenant-B company. The `products`
double-predicate is correct: `products` carries both `tenant_id` +
`company_id`.

`BatchWriteOffService::calculateWriteOffAmount` is `private`; the only
caller (`writeOff` line 86) was updated to pass the source batch's own
`tenant_id` + `company_id`. No external callsites missed.

The verdict is APPROVE-WITH-MINOR-EDITS-APPLIED rather than APPROVE because
adversarial sweep surfaced four pre-existing BatchExpiry tenant-iso gaps
that the scanner did not inventory and the agent's scoped-cluster work did
not address. None of these block the cluster's stated scope (the agent
correctly stayed inside the 6-callsite remit), but they must be tracked as
followups before BatchExpiry can be considered fully closed.

## Findings

1. (NEW, MEDIUM) `BatchController::productBatchStock` (route
   `GET /api/v1/products/{productId}/batch-stock`) is fully unscoped.
   The handler calls `batchRepository->getByProduct($productId,
   activeOnly: true)`, which is bare `Batch::where('product_id', …)`
   (BatchRepository.php:35). No `companyContext` filter, no validator on
   the route param. A tenant-A user passing tenant-B's productId
   receives tenant-B's batches. Out-of-scope for this cluster but a
   functional cross-tenant read leak.

2. (NEW, LOW) `BatchController::posAvailableBatches` validates
   `location_id` post-fix but the `productId` route param is still
   unvalidated. `FEFOInventoryService::suggestBatchesForSale` joins on
   `product_batches.product_id = ?` with no tenant/company predicate.
   Cross-tenant exfiltration is structurally blocked because
   `location_id` is now company-scoped (cross-product/own-location
   yields empty rows), but defense-in-depth is incomplete.

3. (NEW, LOW) `BatchRepository` has 7 bare reads/updates with no
   `tenant_id` + `company_id` predicates: `findById` line 16,
   `findByUuid` line 21, `findByBatchNumber` line 26 (company-only),
   `getByProduct` line 35, `getByCompany` line 51 (company-only),
   `markAsExpired` line 103, `recall` line 108. The public surfaces are
   structurally protected by `BatchController::findBatchOrFail`'s
   post-load `company_id` check, but the repository methods are
   reusable and deserve same-tier scoping.

4. (TEST HONESTY, OBSERVATION) The reflection-based `.014` test asserts
   `assertSame('0.00', $crossTenantAmount)` which would also pass on
   pre-fix code (productA has no `weighted_average_cost`/`cost_price`,
   so the WAC fallback is `0.00` regardless of whether the lookup
   succeeded). The test is salvaged by the structural-SQL-log assertion
   (`tenant_id` + `company_id` predicates in the SELECT) which DOES
   fail on pre-fix code (verified by stash-revert + re-run). The agent
   should consider seeding `productA.weighted_average_cost = 10.00` so
   the value-level assertion also discriminates pre-fix from post-fix.

## Audit exhaustiveness

- Stash-revert + re-run: all 6 BatchExpiry tests fail on pre-fix code.
- Hostile grep over `app/Modules/BatchExpiry/`: 4 distinct unscoped read
  patterns surfaced beyond the cluster's 6 fixed callsites (Findings
  1–3 above). Not in cluster scope; recommend tracking as followup
  rows in `tenant-isolation-sweep-manual-callsites.yml` or a new
  `api.batch-expiry` cluster.
- `locations` schema: confirmed company-only (no tenant_id) via
  migration read; no later migration adds tenant_id.
- `CompanyContextMiddleware`: confirmed it validates user-company
  membership before setting context (justifies single-predicate
  `ScopedExists::company`).
- `calculateWriteOffAmount` callers: only one (in-class, updated).

## Confidence

High that the 6 stated callsites are correctly fixed and tests are
honest. Medium-high that the reflection-based `.014` test pin holds
discriminating power (structural-SQL pin works; value pin is a
tautology on this seed but harmless). High that the surfaced followups
(Findings 1–3) are out of cluster scope yet real.
