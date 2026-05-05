# api.catalog — Opus round-2 adversarial review

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 91c6a9e9

Branch: feat/tenant-isolation-sweep-execution
Cluster: api.catalog (codex-owned)
Round-1 fix commit: 516c6f61
Round-2 remediation commit: 91c6a9e9

Round-1 review artefacts:
- Opus: docs/superpowers/reviews/2026-05-04-api-catalog-cluster-opus-review.md
- Codex: docs/superpowers/reviews/2026-05-04-api-catalog-cluster-codex-review.md

Round-1 verdicts going in:
- Opus: APPROVE-WITH-MINOR-EDITS-APPLIED (5 findings: 2 MEDIUM + 1 LOW + 2 NICE-TO-HAVE)
- Codex: REQUEST-CHANGES (1 HIGH new finding + confirmed Opus MEDIUM 1 + 2)

---

## 1. Scope verification

`git show 91c6a9e9 --stat` lists exactly four touched files, all in
api.catalog scope:

```
.../Catalog/Presentation/Controllers/RecipeLineController.php           | 16 +++-
.../Catalog/Presentation/Requests/StoreModifierGroupRequest.php         |  8 +-
.../Catalog/Presentation/Requests/StoreRecipeLineRequest.php            | 20 ++++-
tests/Feature/Catalog/CatalogTenantIsolationTest.php                    | 96 ++++++++++
```

Cross-checked against the contamination patterns from prior rounds:

```
git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
# (empty)
```

api.document / api.workshop / api.inventory diffs in the branch belong to
their own cluster commits (verified via `git log --oneline dev..HEAD -- apps/api/app/Modules/Document/`),
not to 91c6a9e9. No scope contamination this round.

---

## 2. Round-1 finding closure status

### Opus Finding 1 (MEDIUM) — manual-stub gap for sibling-controller blind spots

**Status: STILL OPEN — no remediation in 91c6a9e9.** The commit message says
Findings 3 and 4 are "Tracked in manual stub for follow-up" / "Tracked,"
but the manual-callsites stub still contains zero rows for api.catalog:

```
$ grep -nE "(api\.catalog|RecipeController|CompositeItemVariantController|CompositeItemController|RecipeLineController|catalog|recipe|composite)" \
    docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
(no output)
$ grep -c "cluster_id: api.catalog" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
0
```

The four sibling-controller blind spots called out in round-1 (`CompositeItemController::show:71/update:101/destroy:116/duplicate:130/checkAvailability:180`,
`CompositeItemVariantController::index:31/store:49/update:71/destroy:107`,
`RecipeController::update:97/activate:116/calculateCost:142`,
`RecipeLineController::store:34/update:69/destroy:115`) are all still
present at the same line numbers and still use `where('company_id', ...)->findOrFail($id)`
or post-load `if (... ->company_id !== $companyId) abort(403)` instead of
`(tenant_id, company_id)` bi-predicate. The implementer's choice to defer
the actual fix is consistent with round-1's "OUT-OF-SCOPE referrals"
posture, but the manual-stub claim in the commit message is not backed by
file content. **This is the same exact failure pattern that caught
api.compliance round-3 ("surface expansion not tracked in manual-stub").**

Severity: MEDIUM — not a tenant leak (company_id UUID uniqueness keeps the
practical effect at 403/no data), but a process-discipline regression and
a documentation-honesty issue.

Recommendation: before promoting the cluster to `verdict: approve`, add
explicit `manual_callsites:` rows for the four sibling-controller patterns
above plus the 8 `exists:units,id` callsites and the 2 `exists:tax_configurations,id`
callsites. Cluster need not refactor in this round, but it must be
inventoried.

### Opus Finding 2 (MEDIUM) — `StoreModifierGroupRequest` `app(CompanyContext::class)` helper

**Status: CLOSED.** Diff in 91c6a9e9 shows the constructor-injection
refactor:

```php
public function __construct(
    private readonly CompanyContext $companyContext,
) {
    parent::__construct();
}
...
$companyId = $this->companyContext->requireCompanyId();
```

Hostile-grep confirms zero residual `app(CompanyContext` calls in
api.catalog scope:

```
$ grep -rn "app(CompanyContext" apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
(no hits)
```

Bonus: switched from `getCompanyId()` (silent null on missing context) to
`requireCompanyId()`, which addresses the secondary discipline issue
called out in round-1.

### Opus Finding 3 (LOW) — bare `exists:units,id` widely used

**Status: DEFERRED, unchanged in 91c6a9e9.** The 8 callsites still bare-validate:

```
StoreCompositeItemRequest.php:53        stock_unit_id
UpdateCompositeItemRequest.php:60       stock_unit_id
StoreModifierRequest.php:40             component_unit_id
StoreRecipeRequest.php:24               yield_unit_id
UpdateRecipeRequest.php:24              yield_unit_id
StoreRecipeLineRequest.php:46           unit_id
RecipeLineController.php:96             unit_id
ModifierController.php:72               component_unit_id
```

(round-1 listed 9 hits; the 9th was the same RecipeLineController line in
the controller's own validate() vs the FormRequest — counted twice; my
reproduction yields 8 unique callsites.)

The commit-message rationale is sound: `units.tenant_id` is nullable
(system + tenant-scoped) and `ScopedExists` does not currently express a
"tenant-or-system" rule. Acceptable as a deferred item, but per
Finding 1 it should be inventoried in the manual stub.

### Opus Finding 4 (NICE-TO-HAVE) — tax_configurations annotation overstates guard

**Status: PARTIALLY CLOSED.** `UpdateCompositeItemRequest.php:52-57` now
carries a softened, accurate annotation:

```php
// tax_configurations is a country-scoped global reference table
// (no tenant_id / company_id columns) — structurally protected
// by country_code. Cross-tenant access requires assigning a
// foreign tax configuration via tax_configurations.country_code,
// which the company's own country_code rejects at the
// billing-flow tier. Tracked under api.catalog manual stub.
```

`StoreCompositeItemRequest.php:50` only carries a brief reference back to
the Update version's comment (`// tax_configurations is country-scoped (see UpdateCompositeItemRequest comment).`)
which is acceptable.

Note: the comment claims "Tracked under api.catalog manual stub" but
**the manual stub has no api.catalog rows.** Falls under Finding 1 above.

### Opus Finding 5 (NICE-TO-HAVE) — same-tenant cross-company fixture

**Status: UNCHANGED, deferred.** Acceptable per round-1 posture.

### Codex Round-1 HIGH Finding 1 — dynamic `exists:{$existsTable},id` in recipe-line surface

**Status: CLOSED in both store and update paths.**

`StoreRecipeLineRequest::rules()` now branches on `ComponentType::CompositeItem->value`
and emits `ScopedExists::tenantAndCompany('composite_items'|'products', $company->tenant_id, $company->id)`.
`RecipeLineController::update()` does the same branched scoped exists in
its inline `Request::validate()` array. ScopedExists internally constructs:

```php
Rule::exists($table, 'id')
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId);
```

so the validator predicate is now bi-scoped. Persistence is via
`$request->validated()` which now only contains a tenant+company-verified
component_id. Test suite covers both branches (product, composite_item)
and both surfaces (POST store, PATCH update).

---

## 3. Round-2 commit-internal review

### What changed (4 files)

1. `StoreRecipeLineRequest.php` — added CompanyContext constructor injection,
   replaced dynamic `exists:{table},id` with branched `ScopedExists::tenantAndCompany`.
2. `RecipeLineController::update` — switched from `requireCompanyId()` to
   `requireCompany()` so both `tenant_id` and `id` are available, applied
   the same branched ScopedExists.
3. `StoreModifierGroupRequest.php` — closed Opus Finding 2 (constructor
   injection + `requireCompanyId`).
4. `tests/Feature/Catalog/CatalogTenantIsolationTest.php` — three new
   structural tests (product cross-tenant, composite_item cross-tenant on
   store, product cross-tenant on update).

All assert `422 / VALIDATION_ERROR` shape with `component_id` key in
`error.errors`, matching the surrounding suite's invariant style.

### Required gates (all green)

```
$ vendor/bin/phpunit tests/Feature/Catalog/CatalogTenantIsolationTest.php
......................                                            22 / 22 (100%)
OK (22 tests, 61 assertions)

$ ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
    app/Modules/Catalog \
    app/Modules/Product/Presentation/Controllers/CategoryController.php \
    tests/Feature/Catalog/CatalogTenantIsolationTest.php
[OK] No errors

$ php artisan sweep:inventory:verify-history \
    --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
verified 1093 event(s) across 268 callsite(s); 0 problem(s).

$ git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
(empty)
```

---

## 4. Audit exhaustiveness — hostile-grep redux

### 4a. Dynamic exists shouldn't recur

```
$ grep -rnE '"exists:[^"]*\{.*\}' apps/api/app/Modules/Catalog/ \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
(no hits)

$ grep -rnE '"exists:.*\$' apps/api/app/Modules/Catalog/ apps/api/app/Modules/Product/
(no hits)
```

No string-interpolated `exists:{table},id` remains anywhere in catalog or
in the in-scope CategoryController. The only `Rule::exists`-style helper
is now via `ScopedExists`.

### 4b. Bare `exists:` callsites in scope

```
$ grep -rnE "exists:(products|composite_items|categories|modifier_groups|units|recipes|recipe_lines|modifiers|stock_items)" \
    apps/api/app/Modules/Catalog/ \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php \
    --include='*.php' | grep -v /tests/
```

returns 8 hits, ALL of which are `exists:units,id` (Finding 3 deferred).
Plus 2 `exists:tax_configurations,id` (Finding 4 deferred). No new
unscoped exists patterns.

### 4c. Forbidden helper

```
$ grep -rn "app(CompanyContext" apps/api/app/Modules/Catalog \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
(no hits)
```

### 4d. Validated input persisted directly without scoped re-read

```
$ grep -rnE "\$request->validated\(\)|\$validated\[" \
    apps/api/app/Modules/Catalog/Presentation/Controllers/ \
    apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php
```

All hits inspected. Notable patterns:

- `RecipeLineController::store:53` — `RecipeLine::create([...$request->validated(), 'recipe_id' => $recipe->id])`. The
  validated() set now contains a tenant+company-verified `component_id`
  (Codex Finding 1 closed). recipe_id and display_order are derived
  server-side. Safe.
- `RecipeLineController::update:103` — `$line->update($validated)`. Same
  protection on the validated array. Safe.
- `CompositeItemVariantController::store:57` — `CompositeItemVariant::create([...$request->validated(), 'composite_item_id' => $item->id])`.
  StoreVariantRequest doesn't carry tenant FK fields; composite_item_id is
  server-derived. Safe.
- `CompositeItemController::store:82` — composite_item creation pulls
  validated() and overlays `tenant_id` + `company_id` server-side. Safe.
- `CompositeItemController::update:102` — `$item->update($request->validated())`.
  UpdateCompositeItemRequest does not validate `tenant_id` / `company_id`
  fields, so they cannot be mass-assigned from request. Safe.

### 4e. New finding candidates examined

I looked specifically for:

1. **Other dynamic `exists:` patterns** — none in catalog scope.
2. **Other validators that pull data from request and use it as a table
   name** — only the recipe-line surface (now closed). Confirmed none in
   StoreModifierRequest / ModifierController / CompositeItem* / Recipe*.
3. **FK persistence without scoped re-read** — none introduced this
   round; pre-existing patterns are all going through validated arrays
   that are themselves scope-validated.

### 4f. Asymmetric component_type validation (NEW, NON-BLOCKING)

`ModifierController::update:65` validates `component_type` as
`['nullable', 'string']` — not constrained by `Enum(ComponentType::class)`
the way `StoreModifierRequest:37` does. A caller can update a modifier
with `component_type='composite_item'` but will only ever pass the
`products` ScopedExists at line 69 (no composite_items branch), so this
is a UX/data-integrity inconsistency rather than a tenant leak.

Out of scope for this cluster. Worth noting in the manual stub for the
broader Catalog modifier-component refactor track.

### 4g. CompositeItemAvailabilityService location_id (NEW, NON-BLOCKING)

`CompositeItemController::checkAvailability:175` accepts a request
`location_id` (UUID-validated only) and forwards it to
`CompositeItemAvailabilityService::checkAvailability($item, $locationId)`,
which queries `StockLevel::where('product_id', $productId)->where('location_id', $locationId)`.

Because `$productId` is enumerated from the caller-owned recipe
(tenant-scoped via composite_item), passing a foreign location UUID
returns zero matching StockLevel rows — no leak, just degenerate output.
Still, the location_id is not validated against the caller's
`(tenant_id, company_id)` Locations table, so a noisy enumerator could
distinguish "no stock" from "valid foreign location" by composing valid
foreign UUIDs (negligible practical signal).

Out of scope, non-blocking. Track in the same Catalog manual-stub note as
the sibling-controller patterns.

---

## 5. Test honesty check

Reverted `StoreRecipeLineRequest::rules()` to the pre-fix dynamic
`exists:{$existsTable},id` form. Ran the two new store-path tests:

```
$ vendor/bin/phpunit --filter "test_create_recipe_line_rejects_cross_tenant_product_component_id|test_create_recipe_line_rejects_cross_tenant_composite_item_component_id" \
    tests/Feature/Catalog/CatalogTenantIsolationTest.php
FF                                                                  2 / 2 (100%)

There were 2 failures:

1) test_create_recipe_line_rejects_cross_tenant_product_component_id
   Expected response status code [422] but received 201.
2) test_create_recipe_line_rejects_cross_tenant_composite_item_component_id
   Expected response status code [422] but received 201.
```

Both fail with the right shape (201 created where 422 was asserted),
proving the tests pin to the actual fix, not to a static fixture. Restored
the fix; all 22 tests green again.

---

## 6. New round-2 findings

### Round-2 Finding 1 — MEDIUM — manual-stub still contains zero api.catalog rows

(Carry-over of Opus round-1 Finding 1, NOT closed in 91c6a9e9 despite
commit-message language to the contrary.)

The commit message for 91c6a9e9 makes two specific claims:

> Finding 3 (LOW units bare exists): ... Tracked in manual stub for follow-up.
> Finding 4 (NICE-TO-HAVE tax_configurations annotation overstates guard):
> ... Tracked.

Neither is backed by file content:

```
$ grep -c "cluster_id: api.catalog" docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
0
```

This is a documentation-honesty issue: the cluster has been claiming
"tracked in manual stub" since round-1 and across two follow-up reviews,
but the stub has never been touched for this cluster. Same failure mode
as api.compliance round-3 ("surface expansion not tracked in manual-stub")
which Opus + Codex agreed should not be repeated.

**Required fix:** before this cluster is promoted to `verdict: approve`,
add manual_callsites entries for at least:
- 4 sibling-controller blind spots (CompositeItem*, CompositeItemVariant*,
  Recipe*, RecipeLine* — bare `where('company_id', ...)` lookups missing
  tenant_id predicate)
- 8 `exists:units,id` bare validators (deferred per Finding 3)
- 2 `exists:tax_configurations,id` bare validators (deferred per Finding 4)

No new code required for this cluster, but the inventory must reflect
reality.

### Round-2 Finding 2 — LOW — ModifierController::update component_type not enum-constrained

(NEW, non-blocking, see 4f.) Asymmetric with StoreModifierRequest.
Out of scope but worth tracking.

### Round-2 Finding 3 — LOW — checkAvailability location_id not tenant-validated

(NEW, non-blocking, see 4g.) No leak, but breaks the discipline that
"every request UUID FK should pass through ScopedExists." Out of scope.

---

## 7. Confidence

**HIGH** on:
- Codex round-1 HIGH Finding 1 (dynamic recipe-line component_id) is fully closed
  in both store and update paths, with structural tests on both branches and
  both surfaces.
- Opus round-1 Finding 2 (StoreModifierGroupRequest helper) is fully closed and
  bonus-improved (`requireCompanyId()` discipline).
- Opus round-1 Finding 4 (tax_configurations annotation) is reasonably softened.
- Opus round-1 Finding 3 (units) and Finding 5 (fixture) deferrals are technically
  defensible as written.
- 91c6a9e9 scope is exactly the 4 claimed files; no contamination.
- Tests are honest (revert-and-run check passed).
- All required gates green (22/22 tests, PHPStan clean, verify-history 0 problems,
  POS surface diff empty).

**LOW** on:
- The "Tracked in manual stub" language in the commit message corresponding to
  actual manual-stub contents. Two rounds of "tracked for follow-up" have produced
  zero rows in the stub. This must not be a third-time-as-a-charm pattern.

---

## 8. Verdict rationale

The actual round-1 blocking finding (Codex HIGH 1) is fully closed with
structural tests, the second MEDIUM is fully closed with bonus
improvement, the deferrals on units / tax_configurations / fixture are
defensible as written, and there is no scope contamination. The remaining
discipline issue (manual-stub honesty) is a documentation problem, not a
code-correctness problem, and should not block remediation that is
otherwise sound.

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 91c6a9e9

Required follow-up before this cluster is promoted to `verdict: approve`
on the inventory: populate `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml`
with the 14 deferred catalog callsites listed in Round-2 Finding 1.
