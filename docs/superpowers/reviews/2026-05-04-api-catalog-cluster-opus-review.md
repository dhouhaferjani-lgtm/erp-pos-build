# api.catalog Cluster — Opus Adversarial Review

```
Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 516c6f61
```

Reviewer: Opus first-layer adversarial reviewer
Branch tip: feat/tenant-isolation-sweep-execution
Submit-state YAML commit: 77185828
Date: 2026-05-04

---

## Summary

The 17-callsite api.catalog fix is structurally sound on the inventoried surface:

- All `bare_exists_validator` callsites are now backed by `ScopedExists::tenantAndCompany`
  (modifier_groups, products) or `ScopedExists::company` (categories, the latter table
  having no `tenant_id` column per its 2025_12_26 migration).
- All ModifierGroup route-anchored lookups (show / update / destroy / assignToItem /
  removeFromItem) now chain `where('tenant_id')->where('company_id')`.
- Modifier route-anchored lookups (update / destroy) — which cannot use direct
  `where('tenant_id')` since the `modifiers` table has no scope columns — are scoped
  via `whereHas('group', ...)` using `whereRaw('tenant_id = ?', ...)` /
  `whereRaw('company_id = ?', ...)`. The `whereRaw` choice is justified (PHPStan
  level 8 cannot narrow `Builder<TRelatedModel>` in the closure), and the precedent
  (BatchTraceabilityController) is well-known.
- Three `FormRequest` classes that were previously violating CLAUDE.md Rule #13 by
  using the forbidden `app(CompanyContext::class)` helper were refactored to
  constructor injection.
- `tax_configurations` (api.catalog.002 / .005) is correctly identified as a
  country-scoped global reference table (no tenant/company columns). The annotation
  is reasonable, though see Finding 4 for a residual data-integrity caveat.

The 19-test regression suite passes (52 assertions), PHPStan is clean on the changed
surface, `php artisan sweep:inventory:verify-history` reports `0 problem(s)` over
1089 events / 268 callsites, and the POS surface diff `dev..HEAD` is empty.

Test honesty was independently verified by reverting three representative files and
re-running the suite (see "Test Honesty Results" below) — every reverted file
produced at least one failing test tied to the reverted fix, including the
structural-SQL-log invariant tests that pin the actual `tenant_id` / `company_id`
predicates rather than only end-to-end behavior.

The reason this verdict is APPROVE-WITH-MINOR-EDITS-APPLIED rather than APPROVE
is a documentation gap (Finding 1) and three out-of-scope but discovered
follow-ups (Findings 2-4). None blocks the cluster fix shipping.

---

## Findings

### Finding 1 — MEDIUM — manual-stub does not yet track Catalog sibling-controller blind spots

The commit message states (verbatim):

> Hostile-grep blind spots in sibling controllers (CompositeItemController,
> RecipeController, RecipeLineController, CompositeItemVariantController) — bare
> where('company_id', ...)->findOrFail chains missing tenant_id — are NOT in the
> scanner inventory and remain untouched per the api.cart cluster's OUT-OF-SCOPE
> referrals pattern. **Tracked for follow-up via the manual-callsites stub.**

But:

```
$ grep -nE "(api\.catalog|RecipeController|CompositeItemVariantController|CompositeItemController|RecipeLineController)" \
    docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
(no output)
```

The manual stub currently contains only `api.compliance` and `api.document` rows.
This is the same pattern that caught api.compliance round-3 ("surface expansion not
tracked in manual-stub").

**Recommendation:** Before promoting the cluster to `verdict: approve`, add stub
entries for at least the four sibling-controller blind spots referenced in the
commit message. Concrete callsites (verified via grep, see "Audit Exhaustiveness"
below):

- `RecipeController::show:50-57`, `::update:97-102`, `::activate:116-121`,
  `::calculateCost:142-147` — `Recipe::with('compositeItem')->findOrFail($id)`
  (UNSCOPED) followed by post-load `if ($recipe->compositeItem->company_id !== $companyId) abort(403);`
  → returns 403 on cross-tenant ID (existence leak) and the row is loaded from
  any tenant before the check. RecipeLineController:33/68/107 has the same pattern.
- `CompositeItemVariantController::update:71-77` and `::destroy:107-113` — same
  pattern.
- `CompositeItemController::show:71-73`, `::update:101`, `::destroy:116`,
  `::duplicate:130-132`, `::checkAvailability:180-182` — uses
  `where('company_id', $companyId)->findOrFail($id)` with single-predicate
  scoping. composite_items HAS a tenant_id column, so the discipline (per the
  pattern the implementer just applied to ModifierGroupController) requires
  BOTH. Cross-tenant exposure is gated by company_id UUID uniqueness in
  practice, but the discipline is inconsistent with the rest of the cluster.

### Finding 2 — MEDIUM — `StoreModifierGroupRequest` still uses forbidden `app()` helper

```
apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierGroupRequest.php:25:
        $companyId = app(CompanyContext::class)->getCompanyId();
```

This file is NOT inventoried and was therefore not refactored. However, it sits
in the same Catalog `Presentation/Requests/` directory the cluster touched, and
the implementer explicitly described the fix as having "Refactored three
FormRequests away from the forbidden app(CompanyContext::class) helper." The
fourth FormRequest in the same directory was missed.

This violates CLAUDE.md Rule #13 (Constructor Injection Only). Note that this
also calls `getCompanyId()` rather than `requireCompanyId()` — the unique-rule
on `modifier_groups.code` would silently scope by `null` if the user has no
company context, which is a secondary discipline issue.

**Recommendation:** Apply the same refactor pattern (constructor injection +
`requireCompanyId()`) to `StoreModifierGroupRequest`. Optionally bundle into
this cluster as an out-of-scope edit before the verdict promotion, or open a
separate manual-stub follow-up.

### Finding 3 — LOW — `units` reference table is tenant-scoped but bare `exists:units,id` is used widely

`units` has a NULLABLE `tenant_id` column (`2026_01_09_095045_create_units_table.php`):

```
$table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
...
$table->unique(['tenant_id', 'code']);
$table->index(['tenant_id', 'is_active']);
```

That is, units are either system-wide (`tenant_id IS NULL`) or tenant-specific.
The cluster's hostile grep flagged 9 bare `exists:units,id` rules across:

- `UpdateRecipeRequest.php:24` (yield_unit_id)
- `StoreRecipeLineRequest.php:30` (unit_id)
- `UpdateCompositeItemRequest.php:60` (stock_unit_id)
- `StoreCompositeItemRequest.php:53` (stock_unit_id)
- `StoreModifierRequest.php:40` (component_unit_id)
- `StoreRecipeRequest.php:24` (yield_unit_id)
- `RecipeLineController.php:88` (unit_id)
- `ModifierController.php:72` (component_unit_id)

These let user A reference user B's tenant-specific unit. The leak severity is
limited (units mostly contain a code/symbol/conversion factor, not customer or
financial data), but it still permits a structurally invalid foreign-key write.
The scanner did not flag these because `units` was not enumerated in its
table-allowlist.

**Recommendation:** Add a manual-stub row per file/field with
`expected_scope: tenant_or_system_global` (a new scope label, since system
units carry `tenant_id = NULL`) and propagate via `sweep:inventory:generate`.

### Finding 4 — NICE-TO-HAVE — `tax_configurations` country-scoped guard is documented in source but not enforced anywhere I could find

The implementer's annotation reads:

> tax_configurations is country-scoped — Cross-tenant assignment is structurally
> impossible because the company's country_code blocks foreign rows at the
> tax-application tier.

A search of the consumer surface (`Tax`, `Document`, `Billing`, `Catalog`) does
not surface a "block foreign tax_configuration when its country_code !=
company.country_code" check. `OnboardingChecklistService::68` only reads the id;
`InvoiceService::327-329` reads `CountryTaxRate` (not `tax_configurations`)
filtered by tenant.country_code. So a FR company writing a TN
`default_tax_configuration_id` onto its `composite_items` row will succeed at
the validator AND will be stored. Whether anything later reconciles is unclear.

This is a NICE-TO-HAVE because: (a) the resource is a global reference table
with no PII / financial data of its own, (b) the field is `default_*` (a hint,
not a transactional binding), and (c) the scenario requires deliberate
cross-country submission. But the annotation as written overstates the actual
guard; it should be softened to "structurally low-risk because tax_configurations
contains no tenant data, and downstream tax-rate resolution generally re-derives
from company.country_code" — without claiming an actual tier-blocking guard
exists.

**Recommendation:** Soften the annotation in the inventory note OR add the
explicit downstream guard if the team agrees one is owed.

### Finding 5 — NICE-TO-HAVE — no same-tenant-cross-company test scenario

The test fixture pairs (tenantA → companyA) and (tenantB → companyB). All
"cross-tenant" assertions are therefore also "cross-company" assertions, and
the structural-SQL-log invariants are what actually pin the `tenant_id`
predicate (without them, a single `where('company_id')` would still pass the
behavior tests, since UUIDs do not collide between companies in the fixture).

A future hardening would add a (tenantA → companyA1) + (tenantA → companyA2)
fixture so a user from companyA1 attacking companyA2 is exercised — this is
the api.document round-1 Codex Finding 1+2 pattern. The structural tests
already protect against single-predicate regression, so I am not blocking on
this, but it would be a strict tightening.

---

## Audit Exhaustiveness — Commands Run

### 1. Test suite (full)

```
$ cd apps/api && vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php
...................                                               19 / 19 (100%)
OK (19 tests, 52 assertions)
```

### 2. PHPStan on the changed surface

```
$ cd apps/api && ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
    app/Modules/Catalog \
    app/Modules/Product/Presentation/Controllers/CategoryController.php \
    tests/Feature/Catalog/CatalogTenantIsolationTest.php
[OK] No errors
```

### 3. `sweep:inventory:verify-history`

```
$ cd apps/api && php artisan sweep:inventory:verify-history \
    --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
verified 1089 event(s) across 268 callsite(s); 0 problem(s).
```

### 4. POS-surface diff guard

```
$ git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
(empty)
```

### 5. Inventory sanity — all 17 entries point at fix_commit `516c6f61` and the
   regression test:

```
$ grep -nE "fix_commit: 516c6f61" .../tenant-isolation-sweep-inventory.yml | wc -l
17
```

(Confirmed by inspection that lines 1834, 1928, 2022, 2116, 2210, 2304, 2398,
10052, 10146, 10240, 10334, 10428, 10522, 22068, 22162, 22256, 22350 all carry
`fix_commit: 516c6f61` followed by `regression_test:
apps/api/tests/Feature/Catalog/CatalogTenantIsolationTest.php`.)

### 6. Hostile grep #1 — bare where chains on route-anchored columns

```
$ grep -rnE "where\([\"\'](id|partner_id|composite_item_id|modifier_group_id|category_id|component_id|component_unit_id)[\"\']" \
    apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
```

Results (excluding tests):

- `RecipeController.php:34, 73, 124, 125` — Recipe lookups by composite_item_id;
  scoping inherited from upstream `CompositeItem::where('company_id', $companyId)->findOrFail($compositeItemId)`. The composite item lookup itself is single-predicate (no tenant_id) — see Finding 1.
- `CompositeItemVariantController.php:32, 53, 91, 92` — same upstream-scoping
  pattern, same caveat.
- `CategoryController.php:228` — inside `reorder()`, `Category::where('id', $item['id'])->where('company_id', $companyId)->update(...)` — double-predicate, fine.

Conclusion: no NEW unscoped bare-where chains were introduced; the residual
chains are in OUT-OF-SCOPE controllers and are all upstream-protected by the
single-predicate company_id chain — which is the exact gap Finding 1 wants
tracked.

### 7. Hostile grep #2 — bare exists rules

```
$ grep -rnE "exists:(partners|products|users|categories|modifier_groups|composite_items|tax_configurations|units|locations)" \
    apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
```

Hits:

- `tax_configurations` (2 hits in StoreCompositeItemRequest:51 / UpdateCompositeItemRequest:58) — annotated as country-scoped, see Finding 4.
- `units` (9 hits across 8 files) — see Finding 3.

No `partners`, `products`, `categories`, `modifier_groups`, `composite_items`
bare-exists references remain.

### 8. Hostile grep #3 — body-trusted company_id / tenant_id

```
$ grep -rnE "request->(input|header|query)\(.{0,20}(company_id|tenant_id|X-Company-Id)" \
    apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
(no output)
```

Clean — no body-trusted scope-id reads.

### 9. Hostile grep #4 — forbidden `app(CompanyContext...)` helper

```
$ grep -rn "app(CompanyContext" apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierGroupRequest.php:25:
        $companyId = app(CompanyContext::class)->getCompanyId();
```

ONE residual hit — this is Finding 2.

### 10. Hostile grep #5 — sibling Presentation/Controllers blind spots

```
$ grep -rnE "findOrFail|::find\(|::first\(" \
    apps/api/app/Modules/Catalog/Presentation/ \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
```

Catalogued in Finding 1; the relevant unscoped-then-post-check pattern lives
in RecipeController, CompositeItemVariantController, RecipeLineController.

### 11. Service-tier reachability

Routes confirmed in `apps/api/app/Modules/Catalog/Presentation/routes.php:48-61`:

- `POST /api/v1/composite-items/{id}/modifier-groups` → `assignToItem`
- `DELETE /api/v1/composite-items/{id}/modifier-groups/{mgid}` → `removeFromItem`
- `GET|PATCH|DELETE /api/v1/modifier-groups/{id}` → `show|update|destroy`
- `POST /api/v1/modifier-groups/{groupId}/modifiers` → `store`
- `PATCH|DELETE /api/v1/modifiers/{id}` → `update|destroy`

And in `apps/api/app/Modules/Product/routes.php:30-36`:

- `GET|POST|PUT|DELETE /api/v1/categories[/{id}]` → all CategoryController methods
- `POST /api/v1/categories/reorder` → `reorder`

All inventoried surface is HTTP-reachable.

---

## Test Honesty Results

I performed three independent revert-and-rerun honesty checks. Each used
`git checkout 516c6f61^ -- <file>`, ran the relevant subset of the test suite,
then `git checkout HEAD -- <file>` to restore.

### Honesty check 1 — `StoreCompositeItemRequest.php`

After reverting:

```
1) Tests\Feature\Catalog\CatalogTenantIsolationTest::test_create_composite_item_rejects_cross_tenant_category_id
... (500 from CompositeItemData null vertical_type)
FAILURES! Tests: 1, Assertions: 1, Failures: 1.
```

Test FAILS — and importantly, the failure is post-validation: the bare
`exists:categories,id` accepted the cross-tenant category_id and the request
proceeded into the controller, where the DTO crashed because the row was
written without all the required fields. Confirms the test's 422 expectation
is what the `ScopedExists::company` rule provides. NOT vacuous.

### Honesty check 2 — `ModifierGroupController.php`

After reverting:

```
1) Tests\Feature\Catalog\CatalogTenantIsolationTest::test_show_modifier_group_query_includes_tenant_and_company_predicates
ModifierGroup route-anchored lookup must filter by tenant_id. Got SQL:
select * from "modifier_groups" where "company_id" = ? and "modifier_groups"."id" = ?
  and "modifier_groups"."deleted_at" is null limit 1
FAILURES! Tests: 2, Assertions: 5, Failures: 1.
```

The structural-SQL-log invariant catches the regression. The
`test_show_modifier_group_rejects_cross_tenant_id` 404 test PASSES with the fix
reverted (because pre-fix code's `where('company_id', ...)` already blocks
cross-COMPANY lookups, and the fixture has distinct UUIDs across companies).
**This proves Finding 5 is real**: the structural-SQL-log invariant test is the
ONLY thing pinning the tenant_id predicate. Without it, this would be a
silent-pass scenario. The implementer's choice to include it was correct.

### Honesty check 3 — `ModifierController.php`

After reverting:

```
4 failures:
- test_update_modifier_rejects_cross_tenant_id (403, expected 404)
- test_destroy_modifier_rejects_cross_tenant_id (403, expected 404)
- test_update_modifier_rejects_cross_tenant_component_id (200, expected 422)
- test_update_modifier_query_scopes_via_group_tenant_and_company
  (Modifier whereHas subquery not captured)
FAILURES! Tests: 7, Assertions: 10, Failures: 4.
```

All four tied directly to the reverted whereHas / ScopedExists combination.
Strong honesty.

All three reverts caused at least one tied failure → none of the spot-checked
tests are vacuous.

---

## Confidence Statement

**Confidence: HIGH** that the 17 inventoried callsites are correctly scoped,
the regression suite is non-vacuous, and PHPStan / sweep:inventory:verify
gates are clean.

**Confidence: MEDIUM** that nothing on the cluster surface bypasses the fix,
because four sibling controllers (RecipeController, RecipeLineController,
CompositeItemController, CompositeItemVariantController) and one FormRequest
(StoreModifierGroupRequest) carry residual discipline gaps that the implementer
acknowledged but did not record in the manual-callsites stub. These are
out-of-cluster per the inventory, but the manual-stub commitment in the commit
message has not been honored on disk.

**Confidence: HIGH** that the core architectural choices (whereRaw to bypass
PHPStan generic narrowing, ScopedExists::company for the tenant_id-less
`categories` table, structural-SQL-log invariant tests) are correct.

**Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED.** The cluster fix itself is solid
and the regression coverage is genuine. The minor edits required before
promoting `under_review` → `approve` are:

1. Track the four sibling-controller blind spots in
   `tenant-isolation-sweep-manual-callsites.yml` (Finding 1).
2. Refactor `StoreModifierGroupRequest::rules()` away from `app()` helper
   (Finding 2) — either inline in this cluster or as a separate manual-stub
   row.
3. Optionally: add manual-stub rows for the 9 bare `exists:units,id`
   validators (Finding 3).

I am marking this APPROVE-WITH-MINOR-EDITS-APPLIED rather than REQUEST-CHANGES
because none of the findings are leaks WITHIN the inventoried 17 callsites —
they are documentation gaps and out-of-cluster discoveries. The core sweep is
green; the housekeeping is what's owed.
