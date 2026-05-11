# Codex second-layer review — api.pricing cluster (round 2)

Review date: 2026-05-04
Branch tip reviewed: f7f87acb
Reviewer: codex (round-2 second-layer review post-Opus round-2 APPROVE-WITH-MINOR-EDITS-APPLIED)

Verdict: APPROVE
Commit reviewed: 12ec1df9

## Round-1 BLOCK rationale verification

Finding 1, index unscoped: CLOSED honestly. I temporarily removed only the `PricingController::index()` `where('tenant_id')->where('company_id')` chain and reran the two index pins. Both failed: `test_index_returns_only_same_tenant_price_lists` saw `priceListB.id` in the `/api/v1/price-lists` response, and `test_index_query_includes_tenant_and_company_predicates` captured `select * from "price_lists" order by "created_at" desc limit 20 offset 0` with no tenant predicate.

Finding 2, `getDefaultPriceListPrice` cross-tenant default: CLOSED honestly, including the `f7f87acb` test-honesty strengthening. I temporarily reverted only the default-list tenant/company scope. `test_get_price_falls_back_to_same_tenant_default_only` failed pre-fix because the response carried the foreign default `priceListB.id`; the strengthened cross-tenant `PriceListItem(priceListB, productA, 99.00)` is now exercising the leak path instead of letting the response fall through to `null`.

Finding 3, `getPartnerPrice` join unfiltered: CLOSED honestly. I temporarily removed only the `price_lists.tenant_id` and `price_lists.company_id` predicates from the join. `test_get_partner_price_join_filters_price_lists_by_tenant` failed with captured SQL for `partner_price_lists inner join price_lists` that had no joined-table tenant/company predicates.

Finding 4, `removeFromPartner` partner_id unvalidated: CLOSED honestly, including the `f7f87acb` test-honesty strengthening. I temporarily removed only the explicit tenant-scoped `Partner` pre-load. With the seeded cross-tenant `PartnerPriceList(priceListA, partnerB)` row, `test_remove_from_partner_rejects_cross_tenant_partner_id` failed because DELETE returned 200 instead of 404.

## Module-walk hunt

No new substantive Pricing-module blind spot found.

- Listing/page reads: `PricingController::index()` is the only `paginate()` listing in `app/Modules/Pricing`; it is tenant/company scoped before eager-loading `company` and `items`.
- `Model::all()` / unscoped `with(...)->get()`: none found in the Pricing module.
- Routes: `Presentation/routes.php` exposes the expected 15 Pricing routes. `PricingServiceProvider` loads only that routes file; `bootstrap/providers.php` registers the provider. No hidden Pricing route file surfaced from `bootstrap/app.php` or route-list checks.
- `PriceListItem` reads: controller item update/delete paths first load a tenant/company-scoped `PriceList`. Service `getPriceFromList()` is fed by the now-scoped default-list or partner-list paths. Public `getQuantityBreaks()` reads by caller-provided `price_list_id`/`product_id`, but the only in-repo caller is the controller route with tenant/company-scoped validators for both IDs.
- `PartnerPriceList` reads: only `removeFromPartner()` and `getPartnerPrice()` read it; both are now anchored by scoped `PriceList`/`Partner` or joined `price_lists` predicates.
- Domain/application services: DB reads are limited to scoped Product fallback, scoped partner/default price-list lookups, `getPriceFromList()`, and controller-validated `getQuantityBreaks()`.
- Commands/jobs/listeners: none under `app/Modules/Pricing`.

## Audit exhaustiveness

- Hostile-grep delta: `12ec1df9..HEAD` touches `PricingService`, `PricingController`, `PricingTenantIsolationTest`, and the sweep inventory only; hostile grep found no additional in-module `paginate`, `all`, unscoped `with()->get`, commands, jobs, or listeners.
- Tests: `vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php` -> OK, 19 tests / 56 assertions. `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress` -> OK, Gate A 69 and Gate B 77. Cross-cluster regression -> OK, 188 tests / 653 assertions, with 15 PHPUnit deprecations.
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Pricing tests/Feature/Pricing/PricingTenantIsolationTest.php` -> OK, no errors.
- Pint: `./vendor/bin/pint --test app/Modules/Pricing tests/Feature/Pricing/PricingTenantIsolationTest.php` -> pass.
- verify-history: `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` -> verified 867 events across 268 callsites; 0 problem(s).
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` -> empty.

## Confidence

High. The four round-1 production fixes were independently reverted one at a time and each strengthened test failed on the intended pre-fix path. The second module walk did not find an exposed Pricing route or in-module service caller that bypasses the newly scoped controller/service boundaries.
