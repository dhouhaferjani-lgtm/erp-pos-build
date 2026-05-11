# Opus adversarial cluster review — api.pricing (round 2)

Review date: 2026-05-04
Branch tip reviewed: 170edc35
Reviewer: opus (round-2 post-round-1 BLOCK + round-2 remediation)
Owner: codex
Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 170edc35

## Verdict

APPROVE-WITH-MINOR-EDITS-APPLIED

All four round-1 BLOCK findings are CLOSED honestly at the production-code
tier. Two of the four new tests, however, do not honestly pin the new
behavior they claim to pin — they pass with the round-2 source change reverted.
The fixes themselves are correct (and Findings 2 and 4 are genuine
defense-in-depth hardening), but the test pins for Findings 2 and 4 are
weak. I record those as round-2 NICE-TO-HAVE findings rather than
re-BLOCKing, because (a) the production code is correct, (b) the data
configurations needed to truly pin them require constructing
inconsistent-tenant rows that cross FK boundaries (foreign-tenant
PriceList referencing same-tenant Product, foreign-tenant PartnerPriceList
referencing same-tenant Partner), and (c) the round-1 cluster-wide
controller-tier validators already prevent the actual breach paths from
being reachable through the public API.

The CRITICAL Finding 1 fix is honestly pinned by both an end-to-end leak
test AND a structural-SQL-log assertion, AND it survives the test-pin
honesty check. That is the load-bearing finding from round-1 and it is
unambiguously closed.

## Round-1 BLOCK rationale verification

### Finding 1 (CRITICAL) — `PricingController::index` unscoped paginate()

Status: **CLOSED HONESTLY**.

Test-pin honesty check: reverted `index()` in
`app/Modules/Pricing/Presentation/Controllers/PricingController.php:33-60`
to its pre-round-2 form (`PriceList::with([...])` with no tenant predicate).
Re-ran both new index tests:

```
1) test_index_returns_only_same_tenant_price_lists
   Cross-tenant price_list must NOT appear in /price-lists.
   Failed asserting that an array does not contain '019df525-...'.

2) test_index_query_includes_tenant_and_company_predicates
   PriceList listing must filter by tenant_id. Got SQL:
   select * from "price_lists" order by "created_at" desc limit 20 offset 0
   Failed asserting that '...' contains '"tenant_id"'.
```

Both diagnostics target exactly the cross-tenant pagination leak. After
restore, all 18 tests pass. This is a model test pin — the SQL-log
assertion in particular is bar-raising and resilient to rephrasing the
fix (e.g., switching to a global scope would still satisfy it).

### Finding 2 (IMPORTANT) — `PricingService::getDefaultPriceListPrice` cross-tenant

Status: **PRODUCTION FIX CLOSED; TEST PIN INSUFFICIENT** (see Round-2
Finding A below).

Test-pin honesty check: reverted only the round-2 change at
`app/Modules/Pricing/Domain/Services/PricingService.php:155-167` (removed
the tenant/company predicates on the `is_default` query). Re-ran
`test_get_price_falls_back_to_same_tenant_default_only`:

```
OK (1 test, 2 assertions)
```

The test PASSES even with the fix reverted. Trace:

1. Test marks `priceListB` as `is_default=true`.
2. Pre-fix `getDefaultPriceListPrice` selects `priceListB`.
3. Calls `getPriceFromList(priceListB->id, productA->id, '1')`.
4. `price_list_items` has zero rows where `price_list_id = priceListB->id`
   AND `product_id = productA->id` (the only fixture item links
   priceListA→productA).
5. `getPriceFromList` returns `null`.
6. `getDefaultPriceListPrice` returns `null` (the `if ($price === null)
   return null` short-circuit).
7. `getPrice` falls all the way through to `base_price` with
   `price_list_id: null`.
8. `assertNotSame(priceListB->id, null)` → null is not priceListB->id → pass.

The production code change IS correct (scoping at the source is the right
defense-in-depth move per the round-1 review's own logic — "response
wrapping leaks `price_list_id` from a foreign tenant via timing/probe
attacks"). But the test would only fail pre-fix if a `PriceListItem` row
also linked priceListB → productA, which would itself be a cross-tenant
data integrity violation that current FKs (price_list_items has no
tenant column constraint) technically permit but no normal flow creates.

### Finding 3 (IMPORTANT) — `getPartnerPrice` join cross-tenant

Status: **CLOSED, NOT DIRECTLY TESTED (acceptable)**.

The DB::table join at
`app/Modules/Pricing/Domain/Services/PricingService.php:108-109` now
chains `->where('price_lists.tenant_id', ...)` and
`->where('price_lists.company_id', ...)`. Verified by reading the diff:
both predicates are present, in the correct query, AND-combined with all
existing `partner_price_lists.*` and `price_lists.*` filters.

Not directly tested by a service-tier or SQL-log assertion. Acceptable as
defense-in-depth (the controller-tier `partner_id` validator already
rejects cross-tenant partner_id, and the `getPriceFromList` downstream
nulls the value). I flag the missing structural test as round-2
NICE-TO-HAVE Finding B below.

### Finding 4 (IMPORTANT) — `removeFromPartner` $partnerId validation

Status: **PRODUCTION FIX CLOSED; TEST PIN PASSES FOR THE WRONG REASON**
(prompt anticipated this; see Round-2 Finding C below).

Test-pin honesty check: reverted only the `Partner::where(...)->findOrFail($partnerId)`
pre-load at
`app/Modules/Pricing/Presentation/Controllers/PricingController.php:314-316`.
Re-ran `test_remove_from_partner_rejects_cross_tenant_partner_id`:

```
OK (1 test, 1 assertion)
```

The test PASSES with the fix reverted. Trace:

1. Test calls `DELETE /price-lists/{priceListA->id}/partners/{partnerB->id}`.
2. Pre-fix: PriceList lookup succeeds (priceListA is same-tenant).
3. PartnerPriceList lookup: `where('price_list_id', priceListA->id)
   ->where('partner_id', partnerB->id)->firstOrFail()`.
4. The fixture has zero PartnerPriceList rows. `firstOrFail` 404s.
5. Post-fix: same outcome (404), but now via the explicit Partner
   `findOrFail` step instead of the transitive PartnerPriceList miss.

The fix itself IS correct — it mirrors the symmetric `assignToPartner`
validator and protects against a future scenario where a same-tenant
PartnerPriceList row references a foreign-tenant partner_id (which the
schema's `foreignUuid('partner_id')->constrained('partners')` does NOT
prohibit, since `partners.id` is global UUID with no tenant constraint).
But the test does NOT pin the new behavior — it would need a fixture
PartnerPriceList row with `partner_id = partnerB->id, price_list_id =
priceListA->id` to truly exercise the new pre-load. That row would be a
cross-tenant data integrity violation, but the FK system permits it.

Round-2 NICE-TO-HAVE: strengthen the test by inserting that
inconsistent-tenant PartnerPriceList row directly via `DB::table` and
then asserting the DELETE returns 404 with a 'Partner' model
`findOrFail` exception message. Filed as Finding C below.

## New round-2 findings

### Finding A: NICE-TO-HAVE — `test_get_price_falls_back_to_same_tenant_default_only` does not pin Finding 2's leak

The current test passes both pre-fix and post-fix (see Finding 2
analysis). To truly pin the leak, the test should seed an
inconsistent-tenant `PriceListItem` row linking `priceListB` (foreign
tenant) → `productA` (same tenant). That requires a direct
`DB::table('price_list_items')->insert([...])` call (Eloquent's
`PriceListItem::create` has no scope guard, so it would also work). With
that seed in place, pre-fix `getPrice` would return
`source: 'default_price_list', price_list_id: priceListB->id, price:
'<priceListB price>'` and the assertion would fail with the
diagnostic "getPrice must NOT leak a foreign-tenant price_list_id".

Suggested replacement seed (appended to the test, before the API call):

```php
PriceListItem::create([
    'id' => Str::uuid()->toString(),
    'price_list_id' => $this->priceListB->id, // foreign tenant
    'product_id' => $this->productA->id,      // same tenant
    'price' => '99.99',
    'min_quantity' => '1',
]);
```

Severity: NICE-TO-HAVE because (a) the production fix is correct, (b)
the controller-tier `product_id` validator with `ScopedExists` blocks
the public API path, and (c) only a malicious admin or migration race
could create the inconsistent-tenant row.

### Finding B: NICE-TO-HAVE — Finding 3 join scope has no structural-SQL-log assertion

The round-1 reviewer correctly flagged this as defense-in-depth. The
fix is in place. A bar-raising structural-SQL-log assertion (similar to
`test_index_query_includes_tenant_and_company_predicates`) would lock
the predicate against future regressions. Suggested test:

```php
public function test_get_partner_price_join_includes_price_lists_tenant_predicates(): void
{
    // Seed one PartnerPriceList linking priceListA → partnerA.
    PartnerPriceList::create([...]);

    \DB::enableQueryLog();
    $this->actingAsForTenant($this->userA, $this->companyA)
        ->postJson('/api/v1/pricing/get-price', [
            'product_id' => $this->productA->id,
            'partner_id' => $this->partnerA->id,
            'quantity' => '1',
            'currency' => 'EUR',
        ])->assertStatus(200);

    $log = \DB::getQueryLog();
    $partnerJoinQuery = collect($log)
        ->first(fn ($e) =>
            str_contains((string) $e['query'], 'partner_price_lists')
            && str_contains((string) $e['query'], 'inner join "price_lists"')
        );

    $this->assertNotNull($partnerJoinQuery);
    $this->assertStringContainsString('"price_lists"."tenant_id"', $partnerJoinQuery['query']);
    $this->assertStringContainsString('"price_lists"."company_id"', $partnerJoinQuery['query']);
}
```

Severity: NICE-TO-HAVE.

### Finding C: NICE-TO-HAVE — `test_remove_from_partner_rejects_cross_tenant_partner_id` passes for the wrong reason

See Finding 4 analysis above. To pin honestly, seed an
inconsistent-tenant PartnerPriceList row first:

```php
DB::table('partner_price_lists')->insert([
    'id' => Str::uuid()->toString(),
    'price_list_id' => $this->priceListA->id, // same tenant
    'partner_id' => $this->partnerB->id,      // foreign tenant
    'is_active' => true,
    'priority' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);
```

Then the DELETE should still 404 (now via the new Partner pre-load), and
re-running with the round-2 fix reverted would surface a 200 (the join
row exists and was found, so the old code would have deleted it).

Severity: NICE-TO-HAVE. Same justification as Finding A.

### Module-walk hunt — no new substantive surfaces

Walked the entire `app/Modules/Pricing/` module:

- `PricingController.php` — all 14 routes verified tenant-scoped:
  - `index` (round-2 fixed), `show`, `store`, `update`, `destroy`,
    `addItem`, `updateItem`, `removeItem`, `assignToPartner`,
    `removeFromPartner`, `getPrice` (validator), `getQuantityBreaks`
    (validator + price_lists + products both scoped),
    `calculateLineTotal` (no model reads), `getBulkPrices` (validator),
    `checkMargin` (validator + Product findOrFail scoped). Index
    filters `is_active`/`currency` are applied AFTER the tenant/company
    `where` chain, so the predicates are AND-anchored.

- `PricingService.php`:
  - `getPrice` Product fallback is tenant-scoped (round-1).
  - `getPartnerPrice` join is tenant-scoped (round-2).
  - `getDefaultPriceListPrice` source query is tenant-scoped (round-2).
  - `getPriceFromList` is private and only ever called with priceListIds
    that are already tenant-anchored upstream (3 call sites: round-1
    fallback already scoped, round-2 partner+default queries scoped).
  - `getQuantityBreaks` (line 314) takes a raw `priceListId + productId`
    pair. Only caller in the codebase is the controller, which validates
    both via `ScopedExists::tenantAndCompany`. NO service-direct caller
    in current code. Defense-in-depth via service-tier
    `Product::where(tenant_id)->findOrFail($productId)` would be a
    future-proofing add but the AST sweep contract already covered
    inventoried callsites; not worth flipping a green review.
  - `getBulkPrices` only delegates to `getPrice` per-product, which
    already pre-validates the productId list at controller tier and
    tenant-anchors the Product fallback at service tier.
  - `calculateLineTotal` and `applyDocumentDiscount` are pure bcmath —
    no model reads.

- `PriceList.php`, `PriceListItem.php`, `PartnerPriceList.php` — Eloquent
  models, no business logic, no global scope (intentional — every
  callsite explicitly scopes).

- `routes.php` — all 14 routes verified, all behind `auth:sanctum +
  SetPermissionsTeam + can:pricing.*`. No omitted route.

- Eager loads: `index` loads `['company', 'items']`, `show` loads
  `['company', 'items.product', 'partnerPriceLists.partner']`. The
  parent `PriceList` is now tenant-scoped; eager-loaded `items` are
  tenant-anchored via `price_list_id`; eager-loaded `partnerPriceLists`
  and nested `partner` are tenant-anchored via `price_list_id` too,
  unless an inconsistent-tenant PartnerPriceList row exists (same FK
  laxness as Finding C). Same defense-in-depth class as Finding 4 — not
  worth a new BLOCK.

No new in-cluster CRITICAL or IMPORTANT surfaces found.

## Verification commands

- Test suite: `vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php`
  → 18 tests / 50 assertions OK.
- Architecture gates: Gate A 69 → 69; Gate B 77 → 77 (zero delta — all
  round-2 fixes are scanner-blind, exactly as predicted).
- PHPStan: `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
  app/Modules/Pricing tests/Feature/Pricing/PricingTenantIsolationTest.php`
  → [OK] No errors.
- Pint: `./vendor/bin/pint --test app/Modules/Pricing
  tests/Feature/Pricing/PricingTenantIsolationTest.php` → pass.
- verify-history: `php artisan sweep:inventory:verify-history` →
  867 events / 268 callsites; 0 problem(s).
- POS surface diff: `git diff --stat dev..HEAD -- apps/api/app/Modules/POS
  apps/pos apps/api/app/Modules/Voucher` → empty.
- Cross-cluster regression: `vendor/bin/phpunit tests/Feature/Pricing
  tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact
  tests/Feature/Treasury/TreasuryTenantIsolationTest.php` → 187/187 OK
  (15 pre-existing PHPUnit deprecations, no failures).

## Confidence

High that the round-2 production code closes all four round-1 findings
correctly: the CRITICAL index leak is honestly pinned by both an
end-to-end leak test and a structural-SQL-log invariant; the three
IMPORTANT defense-in-depth fixes are present and structurally correct.
Medium-high that the regression suite is durable — three of the four
new test pins are honest (Finding 1 doubled), but two (Findings 2 and 4)
pass for the wrong reason and would not catch a regression that
re-introduces the cross-tenant data path. The cluster is shippable as
APPROVE-WITH-MINOR-EDITS-APPLIED with the three NICE-TO-HAVE test
strengthenings tracked as follow-up. None of them are CRITICAL enough
to BLOCK; the production fixes themselves are correct and the
controller-tier validators already block the public-API breach paths.
