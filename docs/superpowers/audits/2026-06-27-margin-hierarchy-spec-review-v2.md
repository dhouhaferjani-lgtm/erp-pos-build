# Anti-confabulation proof

I opened and read `docs/superpowers/specs/2026-06-27-margin-hierarchy-design.md` in full, and opened and read `docs/superpowers/audits/2026-06-27-margin-hierarchy-approach-review.md` in full. The exact spec headers present are:

- `# Spec — Editable, hierarchy-aware product margin (company → category → product)`
- `## 1. Goal & owner requirements`
- `## 2. Locked decisions (owner)`
- `## 3. Non-goals / boundaries (explicit)`
- `## 4. Backend design`
- `### 4.1 Data model changes`
- `### 4.2 Enums (no magic strings — rule 9)`
- ``### 4.3 `MarginResolver` (new, Product module) — owns the hierarchy``
- `### 4.4 minimum ≤ target invariant (BLOCKER fix)`
- `### 4.5 Pricing mode — state machine (SHOULD-FIX fix)`
- `### 4.6 Validation (rule 19 — percent = 2dp, NOT currency-scaled)`
- `### 4.7 N+1 / performance (SHOULD-FIX fix)`
- `### 4.8 Company default exposure`
- `### 4.9 Category margin persistence`
- `### 4.10 Module boundaries (SHOULD-FIX fix)`
- `### 4.11 Queue-safe scale resolution (rule 20 — verified gap)`
- `### 4.12 Serialization to the frontend`
- `## 5. Frontend design`
- `### 5.1 Fix the formula + go float-free (BLOCKER fix)`
- `### 5.2 Editable bidirectional field`
- `### 5.3 Category margin-defaults UI`
- `### 5.4 i18n`
- `## 6. Branching & environment`
- `## 7. Data migration / backfill (BLOCKER fix)`
- `## 8. Testing (TDD — write tests first)`
- `## 9. Acceptance bar`

Three verbatim lines from three different spec sections:

```text
**Resolution chain (the owner requirement):**
```

```text
**API contract — mode is driven by explicit client intent, never inferred from "did `sale_price` change".** This is critical because the margin-edit path *also* sends a recomputed `sale_price`. Rules, in priority order, applied on product create/update:
```

```text
1. Schema migration adds `products.pricing_mode` with **DB default `'manual'`** → zero existing prices can be auto-clobbered immediately.
```

If any recalled section conflicts with the verbatim text above or with the opened files, the verbatim text wins.

# PART A — finding-by-finding resolution table

| Audit finding | Status against current spec | Evidence |
|---|---:|---|
| Existing products defaulting to `auto` will silently overwrite hand-set prices on next WAC/cost event | RESOLVED | §4.1 and §7 now default to `manual` and define a proof-based, idempotent backfill. |
| Percent scale design contradicts precision contract and existing product storage | RESOLVED | §4.1 explicitly keeps existing product storage at `decimal:3` while enforcing 2dp percent values at validation. |
| Independent target/minimum inheritance can produce `minimum > target` and invert margin bands | RESOLVED | §4.4 adds write-time validation and resolve-time clamp. |
| FE rewrite must eliminate float money/percent arithmetic, not just change formula | RESOLVED | §5.1 requires string props, decimal helpers, and bans float/native arithmetic in touched pricing components. |
| Category ancestor resolution will create N+1 queries unless the spec defines a batch resolver | RESOLVED | §4.7 defines `resolveMany()` and bounded category loading. |
| A single `source` / `source_category_id` cannot describe split target/minimum provenance | RESOLVED | §4.3 and §4.12 require per-field provenance and `minimum_clamped`. |
| Pricing mode lacks a complete state machine and explicit reprice flows | RESOLVED | §4.5 includes priority rules, trigger table, recalc seam, and re-arm rule. |
| Module-boundary risk: Inventory already depends on Product internals, and hierarchy would deepen coupling | PARTIAL | §4.10 prevents Inventory from learning hierarchy/mode internals, but does not move the existing Inventory → Product model/service dependency behind a `Shared/Contracts` interface. |
| New mode/source strings need enum coverage | RESOLVED | §4.2 requires Product-domain `PricingMode` and `MarginSource` enums. |
| Fiscal path appears safe, but spec should explicitly preserve historical line immutability | RESOLVED | §3 explicitly forbids fiscal mutation. |

Resolution evidence, quoted exactly from the current spec:

F1:

```text
**Products** — new migration (tenant): `products.pricing_mode` — non-null, **DB default `'manual'`** (the safe default; see §4.5 backfill), cast to the new `PricingMode` enum, added to `$fillable`.
```

```text
2. Idempotent artisan command `products:backfill-pricing-mode` (runnable per tenant) flips a row to `auto` **only when** `cost_price > 0` AND `sale_price` equals `priceFromMargin(cost_price, effective_target_margin)` at the currency money scale (i.e. provably auto-priced). It resolves each product's effective target via `MarginResolver::resolveMany` (batch, to avoid per-row ancestor queries). All other rows stay `manual`. Logs counts + sampled affected ids; safe to re-run. Note: flipping a currently-matching row to `auto` is safe by construction — its price already equals the effective-target computation, so the next cost-driven reprice keeps it consistent; a non-matching row stays `manual` and is preserved. Scale resolution uses the entity currency (§4.11), not a no-arg `getScale()`.
```

F2:

```text
**Product margin override columns stay `decimal:3`** (existing, post widen-to-scale-3 migration). Deliberate: the precision contract's requirement is that percent **values** are 2dp and not currency-scaled — enforced at **validation** (§4.6). Storage width ≥ value precision is compliant; a narrowing migration to `decimal(5,2)` is **rejected** as unnecessary risk (would require per-tenant pre-checks / truncation handling per `precision-contract.md:20`). Category columns are new, so they are created at the correct `decimal(5,2)` directly.
```

F3:

```text
- **Resolve-time clamp:** because split-level resolution can still yield `minimum > target` (e.g. target from product, minimum from a category), the resolver **clamps** `minimum = min(minimum, target)` and sets `minimum_clamped = true`. This guarantees the band logic in `MarginService::getMarginLevel` (checks minimum before target) can never invert. The FE surfaces a subtle "minimum adjusted to target" hint when `minimum_clamped`.
```

F4:

```text
- **Float-free**: string props + `lib/decimal.ts` (`bcadd/bcsub/bcmul/bcdiv/bccomp`, half-up). **No** `parseFloat`, `Number(...)`, native `+ - * /` on money/percent, or `Math.round` in any touched pricing component.
```

F5:

```text
`ProductController::index` paginates up to 2000 rows. List/report code paths must use `MarginResolver::resolveMany()`, which:
```

```text
2. loads all needed categories in **one** `whereIn('id', …)` query,
```

F6:

```text
  target_source: MarginSource
```

```text
  minimum_source: MarginSource
```

```text
The resolved `EffectiveMargins` (target, minimum, **per-field** `target_source`/`target_source_category_id`, `minimum_source`/`minimum_source_category_id`, `minimum_clamped`) and the product's `pricing_mode` must be **serialized to the FE** via the product show/detail payload (extend `ProductData` DTO) and the company defaults via `formatCompany`. Run `CACHE_STORE=array php artisan typescript:transform` so the generated types carry these fields — otherwise the editor's seeding, provenance display, and `minimum_clamped` hint cannot render. `pricing_mode` is an explicit, validated field on `Store/UpdateProductRequest` (§4.6), sent explicitly by the editor (§4.5), never inferred from a changed price.
```

F7:

```text
1. If the payload contains `pricing_mode` → trust it (authoritative). The editor always sends it: margin-edit sends `pricing_mode: auto` (+ `target_margin_override` when changed); price-edit sends `pricing_mode: manual` (+ `sale_price`).
```

```text
**Explicit recalc seam:** a Product-module application command (`RecalculateSalePriceCommand` / public service method) that recomputes from the effective target and sets mode `auto`. This is the *only* sanctioned way to reprice a `manual` product. (Wiring it to a bulk UI is out of scope; the command + a covering test are in scope.)
```

F8:

```text
- Inventory's `WeightedAverageCostService` continues to call the **public** `MarginService` (already constructor-injected) — it gains **no** knowledge of categories or pricing-mode internals; the `manual` skip is internal to `updateSalePrice`.
```

```text
- The pre-existing coupling (WAC importing the `Product` model) is **not deepened** and is out of scope to refactor here.
```

F9:

```text
- `App\Modules\Product\Domain\Enums\PricingMode`: `Auto = 'auto'`, `Manual = 'manual'`. **Distinct** from `Catalog\…\PricingMode` and `Workshop\…\BundlePricingMode` (different domains; do not reuse).
```

```text
- `App\Modules\Product\Domain\Enums\MarginSource`: `Product = 'product'`, `Category = 'category'`, `Company = 'company'`, `Default = 'default'`. Used for per-field provenance and serialized to the FE.
```

F10:

```text
- **No fiscal mutation.** Changing product-master `sale_price`, margins, or modes affects **future** price selection/defaults only. It must **never** rewrite SALE_RECEIPT/document line `unit_price`, canonical fiscal bytes, or hash-chain rows. (Verified: receipt lines persist request `unit_price`; finalization hashes are immutable.)
```

# PART B — ranked residual findings

## BLOCKER — §4.11 names a non-existent product currency source

Opened files:

- `apps/api/app/Modules/Product/Application/Services/MarginService.php`
- `apps/api/app/Modules/Product/Domain/Product.php`
- `apps/api/app/Modules/Company/Domain/Company.php`
- `apps/api/app/Shared/Contracts/CurrencyScaleResolverInterface.php`
- `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php`
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`

Spec text:

```text
Requirement: all scale resolution inside `MarginService`/`MarginResolver` that can run on the WAC/repricing path must be **currency-aware and context-safe** — resolve via the product's currency with `getScaleSafe($product->currency, 3)` (or `getScale($currency)` when the currency is known), never a bare no-arg `getScale()`. The `MARGIN_SCALE = 2` percent scale is unaffected (percent is not currency-scaled). Covered by the §8 test that clears `CompanyContext` before calling `updateSalePrice`/`resolve`.
```

Actual `MarginService` no-arg scale call:

```php
    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }
```

Actual resolver contract and implementation:

```php
    public function getScale(?string $currencyCode = null): int;
```

```php
        if ($company === null) {
            throw new UnboundCompanyContextException(
                'CurrencyScaleResolver::getScale() called with no currency code and no CompanyContext bound. '
                .'Ensure CompanyContextMiddleware is applied or pass an explicit $currencyCode. '
                .'For callers that intentionally run outside request context (queued jobs, console commands), '
                .'use getScaleSafe($currencyCode, $fallback) instead. See audit finding F-RES-1.',
            );
        }
```

`Product` has a company relation, not a currency property:

```php
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
```

`Company` owns currency:

```php
 * @property string $currency ISO 4217 currency code
```

```php
        'currency',
```

WAC calls `updateSalePrice()` after product cost writes:

```php
                $priceUpdated = $this->marginService->updateSalePrice($product);
```

Concrete failure scenario: an implementation following the spec literally calls `getScaleSafe($product->currency, 3)`. In the opened `Product` model, no product-level `currency` field or relation exists; currency is on `Company`. For an EUR company, `$product->currency` resolves as absent/null, so `getScaleSafe(null, 3)` can silently use fallback scale 3 in contextless WAC/queue code. That writes/compares `sale_price` at 3dp where EUR should use 2dp. The existing no-arg call is already a queue/console failure; the proposed literal fix can become a silent wrong-scale failure.

Recommended spec change: replace every "product currency" instruction with "the product's company currency". On the WAC path, load or already carry `$product->company->currency` before calling margin repricing, and call `getScale($companyCurrency)` or `getScaleSafe($companyCurrency, 3)`. For backfill and resolver code, "entity currency" should mean the owning company's `companies.currency`, not a `products.currency` column.

## SHOULD-FIX — §4.5 contract is sound, but the implementation surfaces are named incompletely

Opened files:

- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php`
- `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php`
- `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php`
- `apps/api/app/Modules/Product/Application/Services/ProductService.php`
- `apps/api/app/Modules/Product/Domain/Product.php`

Actual create request path is `CreateProductRequest`, not `StoreProductRequest`; `rg --files` found no `StoreProductRequest.php` under Product requests. The controller imports:

```php
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\PostOpeningBalanceRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
```

Actual create/update validation currently has `sale_price` but no `pricing_mode`:

```php
            'sale_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
```

```php
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
```

Actual update path mass-assigns the validated payload:

```php
        // Update product core fields
        $productModel->update($validated);
```

Actual import/upsert service path writes `sale_price` directly and is not imported by `ProductController`:

```php
namespace App\Modules\Product\Application\Services;
```

```php
use App\Shared\Contracts\ProductServiceInterface;
```

```php
            'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
```

Spec text that is correct but must be implemented across all these surfaces:

```text
1. If the payload contains `pricing_mode` → trust it (authoritative). The editor always sends it: margin-edit sends `pricing_mode: auto` (+ `target_margin_override` when changed); price-edit sends `pricing_mode: manual` (+ `sale_price`).
```

```text
2. Else if `sale_price` is present **without** `pricing_mode` (generic API / import / upsert) → treat as a direct price intent → `manual`.
```

Answer to the requested scenario: yes, under §4.5 a margin-edit that sends a recomputed `sale_price` stays in `auto` only if it also sends explicit `pricing_mode: auto`, because rule 1 beats rule 2. If it sends recomputed `sale_price` without `pricing_mode`, rule 2 deliberately turns it into `manual`.

Residual risk: §4.12 says `Store/UpdateProductRequest`, but the actual product create class is `CreateProductRequest`, and import/upsert goes through `ProductService::upsert()` rather than a FormRequest. An implementer who only edits controller requests can miss the service import/upsert path. Concrete failure: an import sends `sale_price` without `pricing_mode`; if `ProductService::upsert()` does not explicitly set `pricing_mode = manual`, an auto product can remain auto and be repriced on the next WAC event, clobbering the imported price.

Recommended spec change: replace `Store/UpdateProductRequest` with `CreateProductRequest` / `UpdateProductRequest`, and add `ProductService::upsert()` to the §4.5 implementation checklist. Keep the current rule ordering, but make the frontend §5.2 persistence line explicit: "editing margin sends `pricing_mode: auto` plus recomputed `sale_price`; editing price sends `pricing_mode: manual` plus `sale_price`."

## SHOULD-FIX — Legacy `MarginService` methods currently consume unclamped minimums

Opened files:

- `apps/api/app/Modules/Product/Application/Services/MarginService.php`
- no `MarginLevel.php` file was found under `apps/api/app`

Current `getEffectiveMargins()` has no clamp and returns `minimum_margin` directly:

```php
    public function getEffectiveMargins(Product $product): array
    {
        $company = $product->company;

        // For now, skip category since it doesn't exist
        // Will implement: product → category → company when categories are added
        $targetMargin = $this->toNumericString(
            $product->target_margin_override
            ?? $company->default_target_margin
            ?? '30',
        );

        $minimumMargin = $this->toNumericString(
            $product->minimum_margin_override
            ?? $company->default_minimum_margin
            ?? '15',
        );

        return [
            'target_margin' => CurrencyScale::bcformat($targetMargin, self::MARGIN_SCALE),
            'minimum_margin' => CurrencyScale::bcformat($minimumMargin, self::MARGIN_SCALE),
            'source' => $this->getMarginSource($product),
        ];
    }
```

Current `getMarginLevel()` reads `minimum_margin` from that array:

```php
    public function getMarginLevel(Product $product, string|int|float $sellPrice): array
    {
        $cost = $this->toNumericString($product->cost_price ?? '0');
        $sell = $this->toNumericString($sellPrice);
        $margins = $this->getEffectiveMargins($product);
        $actualMargin = $this->calculateMargin($cost, $sell);
        $inter = $this->intermediateScale();
        $scale = $this->scale();

        if (bccomp($cost, '0', $inter) <= 0) {
            return [
                'level' => self::LEVEL_GREEN,
                'message' => 'No cost data',
                'actual_margin' => null,
            ];
        }

        // Below cost (sell < cost) → loss
        if (bccomp($sell, $cost, $scale) < 0) {
            return [
                'level' => self::LEVEL_RED,
                'message' => 'Below cost - LOSS',
                'actual_margin' => $actualMargin,
                'loss_amount' => (float) $this->bcRoundHalfUp(
                    bcsub($cost, $sell, $inter),
                    $scale,
                ),
            ];
        }

        $actualMarginStr = CurrencyScale::bcformat((string) $actualMargin, self::MARGIN_SCALE);

        // Below minimum margin (actual < minimum)
        if (bccomp($actualMarginStr, $margins['minimum_margin'], self::MARGIN_SCALE) < 0) {
            return [
                'level' => self::LEVEL_ORANGE,
                'message' => 'Below minimum margin',
                'actual_margin' => $actualMargin,
                'minimum_margin' => (float) $margins['minimum_margin'],
            ];
        }

        // Below target margin (actual < target)
        if (bccomp($actualMarginStr, $margins['target_margin'], self::MARGIN_SCALE) < 0) {
            return [
                'level' => self::LEVEL_YELLOW,
                'message' => 'Below target margin',
                'actual_margin' => $actualMargin,
                'target_margin' => (float) $margins['target_margin'],
            ];
        }

        return [
            'level' => self::LEVEL_GREEN,
            'message' => 'Above target margin',
            'actual_margin' => $actualMargin,
        ];
    }
```

Current `canSellAtPrice()` depends on that level:

```php
    public function canSellAtPrice(
        Product $product,
        string|int|float $sellPrice,
        User $user
    ): array {
        $marginLevel = $this->getMarginLevel($product, $sellPrice);
        $company = $product->company;

        // Below cost check
        if ($marginLevel['level'] === self::LEVEL_RED) {
            if (! $company->allow_below_cost_sales) {
                return [
                    'allowed' => false,
                    'reason' => 'Sales below cost are not allowed',
                    'requires_permission' => 'sell_below_cost',
                ];
            }

            if (! $user->can('pricing.sell_below_cost')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below cost',
                    'requires_permission' => 'pricing.sell_below_cost',
                ];
            }
        }

        // Below minimum margin check
        if ($marginLevel['level'] === self::LEVEL_ORANGE) {
            if (! $user->can('pricing.sell_below_minimum_margin')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below minimum margin',
                    'requires_permission' => 'pricing.sell_below_minimum_margin',
                ];
            }
        }

        // Below target margin check (warning only, generally allowed)
        if ($marginLevel['level'] === self::LEVEL_YELLOW) {
            if (! $user->can('pricing.sell_below_target_margin')) {
                return [
                    'allowed' => false,
                    'reason' => 'You do not have permission to sell below target margin',
                    'requires_permission' => 'pricing.sell_below_target_margin',
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'margin_level' => $marginLevel,
        ];
    }
```

Spec soundness: §4.4 is conceptually sound because it requires resolver clamp and says `MarginService::getMarginLevel` can never invert. §4.12 is also sound because it serializes `minimum_clamped`.

Residual implementation risk: the existing method names are still public and currently return the old unclamped array shape. Concrete failure: if a developer adds `MarginResolver` for product show payloads but leaves `getMarginLevel()` wired to the current `getEffectiveMargins()` body, a product/category split where resolved minimum exceeds target still returns ORANGE before checking target.

Recommended spec change: add an explicit implementation bullet under §4.4: `MarginService::getEffectiveMargins()`, `getSuggestedPrice()`, `updateSalePrice()`, `getMarginLevel()`, and `canSellAtPrice()` must all consume the post-clamp `EffectiveMargins` DTO from `MarginResolver`; no method may read raw `minimum_margin_override` / company minimum for sellability decisions.

## NICE-TO-HAVE — §4.7 batch resolver is viable; specify not to call `getAncestors()` per row

Opened files:

- `apps/api/app/Modules/Product/Domain/Category.php`
- `apps/api/database/migrations/tenant/2025_12_26_194624_create_categories_table.php`

The migration does have a materialized path column:

```php
            // Materialized path for efficient tree queries
            // e.g., "1/5/12" means: Root(1) > Child(5) > Grandchild(12)
            $table->string('path')->default('')->index();
```

Actual `Category::getAncestors()` method body:

```php
    public function getAncestors(): Collection
    {
        if (empty($this->path)) {
            return new Collection;
        }

        $ancestorIds = explode('/', $this->path);
        array_pop($ancestorIds); // Remove self

        if (empty($ancestorIds)) {
            return new Collection;
        }

        // Get categories and sort in memory to maintain order
        $categories = static::whereIn('id', $ancestorIds)->get();

        // Sort by position in path
        return $categories->sortBy(function ($category) use ($ancestorIds) {
            return array_search($category->id, $ancestorIds);
        })->values();
    }
```

Conclusion: `getAncestors()` uses `path` and performs one bounded `whereIn` query per call; it does not loop over parents. The spec's §4.7 `resolveMany()` design is sound because page-level code can parse category paths and issue one page-level `whereIn`. The residual risk is only implementation drift: calling this method once per product still causes one query per product/category.

Recommended spec change: add "do not implement `resolveMany()` by calling `Category::getAncestors()` inside the product loop" to §4.7. Severity is NICE-TO-HAVE because §4.7 already says collect paths and one `whereIn`.

## NICE-TO-HAVE — 2dp validation over `decimal:3` storage does not create a real boundary-check break

Opened files:

- `docs/architecture/precision-contract.md`
- `apps/api/database/migrations/tenant/2025_12_02_064541_add_cost_and_margin_fields_to_products_table.php`
- `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php`
- `apps/api/app/Modules/Product/Domain/Product.php`
- `apps/api/app/Modules/Product/Application/DTOs/ProductData.php`
- `apps/api/app/Modules/Product/Application/Services/MarginService.php`

Precision contract:

```text
- Tax rate / percentage: `regex:/^\d+(\.\d{1,2})?$/`. **Percentages are NOT currency-scaled** — they keep a fixed 2-dp ceiling, independent of currency.
```

Product margin columns were originally 2dp, later widened to 3dp:

```php
            $table->decimal('target_margin_override', 5, 2)->nullable();
            $table->decimal('minimum_margin_override', 5, 2)->nullable();
```

```php
            ['target_margin_override', 5, 3],
            ['minimum_margin_override', 5, 3],
```

Current Product casts return 3dp strings:

```php
            'target_margin_override' => 'decimal:3',
            'minimum_margin_override' => 'decimal:3',
```

Current `ProductData` passes override strings as-is:

```php
            target_margin_override: $product->target_margin_override !== null ? (string) $product->target_margin_override : null,
            minimum_margin_override: $product->minimum_margin_override !== null ? (string) $product->minimum_margin_override : null,
```

Current effective margin formatting is 2dp:

```php
            'target_margin' => CurrencyScale::bcformat($targetMargin, self::MARGIN_SCALE),
            'minimum_margin' => CurrencyScale::bcformat($minimumMargin, self::MARGIN_SCALE),
```

Conclusion: validating to 2dp while storing in `decimal(5,3)` does not create a real computation or boundary-check break as long as all writes reject more than two decimal places and effective margins are formatted to 2dp before comparison/serialization. A submitted `12.34` may be stored/cast as `12.340`, but `bccomp(..., 2)` and `CurrencyScale::bcformat(..., 2)` treat it as `12.34`.

Residual risk: product override DTO fields can still display as `12.340` if exposed directly rather than through `EffectiveMargins`. That is an API polish/round-trip consistency issue, not a pricing correctness issue.

Recommended spec change: add that raw product override fields, if serialized for editing, should be formatted to 2dp or superseded by the new effective margin payload. Severity is NICE-TO-HAVE because §4.1 and §4.6 already protect the real precision contract.

## UNVERIFIED — Backfill command exists only in the spec, not current code

Opened/search evidence:

- `rg` found `products:backfill-pricing-mode` only in `docs/superpowers/specs/2026-06-27-margin-hierarchy-design.md`.
- No `RecalculateSalePriceCommand`, `MarginResolver`, `EffectiveMargins`, or product pricing-mode backfill command exists in the opened current code tree.

Spec text:

```text
2. Idempotent artisan command `products:backfill-pricing-mode` (runnable per tenant) flips a row to `auto` **only when** `cost_price > 0` AND `sale_price` equals `priceFromMargin(cost_price, effective_target_margin)` at the currency money scale (i.e. provably auto-priced). It resolves each product's effective target via `MarginResolver::resolveMany` (batch, to avoid per-row ancestor queries). All other rows stay `manual`. Logs counts + sampled affected ids; safe to re-run. Note: flipping a currently-matching row to `auto` is safe by construction — its price already equals the effective-target computation, so the next cost-driven reprice keeps it consistent; a non-matching row stays `manual` and is preserved. Scale resolution uses the entity currency (§4.11), not a no-arg `getScale()`.
```

Spec-level conclusion: the backfill design is idempotent. Re-running it does not "double-set" numeric values; it only sets qualifying rows to `auto`, and setting an already-`auto` row to `auto` again is harmless. Current-code conclusion is UNVERIFIED because no command/job implementation exists yet.

Recommended spec change: no severity assigned under the grounding rule because code implementation is absent. When implemented, tests should assert first run and second run produce the same counts or separately report "already auto" rows without modifying `sale_price`.

# Summary verdict

The revised spec resolves the original approach-review findings on hierarchy, provenance, pricing-mode intent, backfill safety, N+1 risk, frontend float removal, enums, and fiscal immutability.

The only BLOCKER residual is §4.11's incorrect currency source: current code has `Company::currency`, not `Product::currency`, so the safe scale fix must pass the product's owning company currency. The main SHOULD-FIX items are implementation-surface clarity for `CreateProductRequest`/`UpdateProductRequest`/`ProductService::upsert()`, and an explicit requirement that all legacy `MarginService` sellability methods consume the new post-clamp resolver result.

Overall verdict: SPEC MOSTLY SOUND AFTER ONE BLOCKER WORDING FIX. Fix the currency source before planning implementation; then tighten the checklist so import/upsert and legacy margin methods cannot be missed.
