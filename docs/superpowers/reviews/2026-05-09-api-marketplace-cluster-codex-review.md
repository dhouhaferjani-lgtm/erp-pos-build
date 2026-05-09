# api.marketplace cluster — Codex round-1 review 2026-05-09

Verdict: APPROVE

Commit reviewed: 3d0378cb

## Findings

### BLOCKER

None

### MINOR

None

## Recommendation

Approve. The fixed listeners now require `productId` plus `companyId`, scope the `Product` lookup by `company_id` before `find()` (`apps/api/app/Modules/Marketplace/Application/Listeners/SyncListingOnPriceChange.php:31`, `apps/api/app/Modules/Marketplace/Application/Listeners/SyncListingOnStockChange.php:30`), and scope the `MarketplaceSeller` lookup by both `tenant_id` and `company_id` (`apps/api/app/Modules/Marketplace/Application/Listeners/SyncListingOnPriceChange.php:43`, `apps/api/app/Modules/Marketplace/Application/Listeners/SyncListingOnStockChange.php:42`). `companyId` is a single-table UUID primary key, so a same-database cross-tenant company-id collision is not a grounded attack path (`apps/api/database/migrations/2025_11_30_104000_create_companies_table.php:23`); the seller-side `tenant_id` predicate is real defense-in-depth because `marketplace_sellers` stores nullable `tenant_id` and `company_id` independently and only enforces a partial uniqueness constraint on their pair (`apps/api/database/migrations/2026_03_10_400000_create_marketplace_sellers_table.php:16`, `apps/api/database/migrations/2026_03_10_400000_create_marketplace_sellers_table.php:35`). Exact grep found no app dispatch or provider registration for `SyncListingOnPriceChange`/`SyncListingOnStockChange`; only the new test directly instantiates them (`apps/api/tests/Feature/Marketplace/MarketplaceTenantIsolationTest.php:134`, `apps/api/tests/Feature/Marketplace/MarketplaceTenantIsolationTest.php:180`), while the plausible product price dispatchers that do exist include `companyId` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:434`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:157`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:405`) and neither `EventServiceProvider` nor `MarketplaceServiceProvider` wires these marketplace listeners (`apps/api/app/Providers/EventServiceProvider.php:51`, `apps/api/app/Modules/Marketplace/Providers/MarketplaceServiceProvider.php:23`). Despite the red-first deviation, the direct regression cases cover the forged cross-tenant paths and missing-price-`companyId` contract (`apps/api/tests/Feature/Marketplace/MarketplaceTenantIsolationTest.php:131`, `apps/api/tests/Feature/Marketplace/MarketplaceTenantIsolationTest.php:177`), and a targeted phpstan level 8 run on the two listeners plus the new test completed cleanly.
