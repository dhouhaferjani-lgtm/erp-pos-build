<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Shared\Contracts\CoalescingAttributeMergerInterface;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\DTOs\CategoryResolutionDTO;
use App\Shared\DTOs\ProductIdentityResolutionData;
use App\Shared\DTOs\ProductUpsertResultData;
use App\Shared\Enums\ProductIdentityFailure;
use BackedEnum;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Application service for product operations.
 *
 * Exposes product functionality to other modules through the ProductServiceInterface.
 */
final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly TaxResolutionService $taxResolution,
        private readonly BrandResolutionService $brandResolution,
        private readonly CategoryResolutionService $categoryResolution,
        private readonly CoalescingAttributeMergerInterface $attributeMerger,
        private readonly ProductResolverInterface $productResolver,
    ) {}

    /**
     * Find a product by SKU.
     *
     * @return string|null Product ID or null if not found
     */
    public function findIdBySku(
        string $tenantId,
        string $companyId,
        string $sku
    ): ?string {
        $product = Product::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        // The lookup key IS the SKU here, so the matched row and the SKU in
        // question are the same value — refusing on the matched row is correct
        // on this path (unlike the upsert key ladder, gate r2 G3A-R2-1).
        if ($product !== null && $product->trashed()) {
            $this->refuseSkuHeldByDeletedProduct($sku);
        }

        return $product?->id;
    }

    /**
     * Create or update a product.
     *
     * @param  array<string, mixed>  $data  Product data
     */
    public function upsert(
        string $tenantId,
        string $companyId,
        array $data
    ): string {
        return $this->upsertWithResult($tenantId, $companyId, $data)->productId;
    }

    public function upsertWithResult(
        string $tenantId,
        string $companyId,
        array $data
    ): ProductUpsertResultData {
        $fileSku = $this->emptyToNull($data['sku'] ?? null);
        $barcode = $this->emptyToNull($data['barcode'] ?? null);
        $nameSku = $this->skuFromName((string) $data['name']);
        $resolution = $this->resolveIdentity(
            $tenantId,
            $companyId,
            $fileSku,
            $barcode,
            (string) $data['name'],
        );
        if ($resolution->isBarcodeAmbiguous()) {
            throw new RuntimeException(
                'barcode_ambiguous: barcode matches multiple products: '.implode(', ', $resolution->candidateSkus)
            );
        }
        $existing = $resolution->productId === null
            ? null
            : Product::query()->where('company_id', $companyId)->find($resolution->productId);
        $createSku = $fileSku ?? ($barcode ?? $nameSku);

        // Gate r2 G3A-R2-1: the deleted-holder guard keys on the SKU that will be
        // WRITTEN, never on whichever row the key ladder happened to match.
        // `products.barcode` has no unique index at any scope, so a trashed
        // barcode twin forbids nothing and must not produce a row error naming a
        // SKU the operator never supplied; conversely a trashed row holding
        // exactly `$createSku` WILL break the lifetime `unique(company_id, sku)`
        // on INSERT, and must surface as this coded token rather than a raw
        // 23505 in `import_rows.import_error`.
        //
        // Only the create branch needs it: `$attributes` never carries `sku`, so
        // an update cannot move a product onto a held SKU from here (the API
        // update path is fenced by UpdateProductRequest's lifetime unique rule).
        if ($existing === null) {
            $this->refuseIfSkuHeldByDeletedProduct($tenantId, $companyId, (string) $createSku);
        }

        $attributes = [
            'name' => (string) $data['name'],
            'type' => ProductType::from((string) ($this->emptyToNull($data['type'] ?? null) ?? ProductType::Part->value))->value,
            'description' => $this->emptyToNull($data['description'] ?? null),
            'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
            'purchase_price' => $this->emptyToNull($data['purchase_price'] ?? null),
            'barcode' => $barcode,
            'tax_rate' => $this->emptyToNull($data['tax_rate'] ?? null),
            'unit' => $this->emptyToNull($data['unit'] ?? null),
            'unit_id' => $this->emptyToNull($data['unit_id'] ?? null),
        ];

        if (isset($data['is_active']) && $data['is_active'] !== '') {
            $attributes['is_active'] = in_array(
                strtolower((string) $data['is_active']),
                ['true', '1', 'yes'],
                true
            );
        }

        // W2-3: create on miss, exactly as the brand block below does. Lookup-only
        // meant a day-one tenant (empty `categories`, and no categories import
        // exists) lost every category_name with no warning anywhere.
        if (isset($data['category_name']) && trim((string) $data['category_name']) !== '') {
            $attributes['category_id'] = $this->categoryResolution
                ->resolve($companyId, (string) $data['category_name'])
                ->categoryId;
        }

        if (isset($data['brand']) && trim((string) $data['brand']) !== '') {
            $brand = $this->brandResolution->resolve($tenantId, trim((string) $data['brand']), null, null)->brand;
            $attributes['brand_id'] = $brand->id;
            $attributes['brand_source'] = BrandSource::User->value;
        }

        if (($attributes['tax_rate'] ?? null) === null) {
            if ($existing !== null && $existing->tax_rate !== null) {
                // Re-import without a rate column must not clobber an existing explicit rate.
                $attributes['tax_rate'] = $existing->tax_rate;
            } else {
                $company = Company::where('tenant_id', $tenantId)
                    ->where('id', $companyId)
                    ->firstOrFail();

                $attributes['tax_rate'] = $this->taxResolution->getDefaultTaxForNewProduct(
                    $company,
                    $attributes['category_id'] ?? null,
                );
            }
        }

        // W2-5 / C-23(iii): nothing on the import path ever wrote
        // `default_tax_configuration_id`, so every imported product landed on the
        // product screen with a BLANK tax selector even though the company has had
        // a default configuration since provisioning
        // (CompanyTaxProvisioningService.php:63).
        //
        // Gate r1 F-1: this runs on EVERY write, not only when the column is
        // still null. `tax_rate` above is rewritten unconditionally, so a guard
        // that fired only on the first write let a re-import move the rate and
        // leave the previously inherited configuration standing beside it —
        // 7 % configuration, 19 % rate. That pair is executable N-1:
        // DocumentLineTaxResolver prefers the configuration while the POS seals
        // `products.tax_rate` into the receipt hash chain, so one product taxes
        // at two different rates depending on which surface sells it.
        $attributes['default_tax_configuration_id'] = $this->resolveCoherentTaxConfigurationId(
            $tenantId,
            $companyId,
            $attributes['category_id'] ?? null,
            $this->emptyToNull($attributes['tax_rate']),
            $existing?->default_tax_configuration_id,
        );

        if ($existing !== null) {
            $provided = is_array($data['_provided'] ?? null)
                ? array_values(array_filter($data['_provided'], static fn (mixed $key): bool => is_string($key)))
                : array_keys($attributes);
            $existingAttributes = [];
            foreach (array_keys($attributes) as $field) {
                $value = $existing->getAttribute($field);
                // Preserve the useful decimal string casts while reducing
                // enum-backed attributes to their persisted scalar value.
                if ($value instanceof BackedEnum) {
                    $value = $value->value;
                }
                if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
                    $existingAttributes[$field] = $value;
                }
            }
            $attributes = $this->attributeMerger->merge($existingAttributes, $attributes, $provided);
            $existing->fill($attributes);
            $existing->save();

            return new ProductUpsertResultData(
                productId: $existing->id,
                sku: $existing->sku,
                skuWasGenerated: false,
            );
        }

        $product = Product::create(
            array_merge([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'sku' => $createSku,
            ], $attributes)
        );

        return new ProductUpsertResultData(
            productId: $product->id,
            sku: $product->sku,
            skuWasGenerated: false,
        );
    }

    /**
     * A rate that reaches bcmath must be a plain, exponent-free decimal string.
     * `is_numeric` is NOT that test (gate r1 F-6): it admits forms like `1e2`,
     * on which `bccomp` throws a ValueError — and this path takes its value from
     * a spreadsheet cell on the queued import worker.
     *
     * DELIBERATELY UNBOUNDED ON FRACTIONAL DIGITS (gate r2 NEW-1). An earlier
     * revision capped it at two, claiming to mirror the products FormRequests
     * (`CreateProductRequest`: `regex:/^\d+(\.\d{1,2})?$/`). That ceiling is real
     * on the API path and absent on THIS one — `ImportType::Products` gives
     * `tax_rate` only `numeric|min:0|max:100`, with no percent regex (unlike its
     * `margin` sibling), and `NumericFieldNormalizer` keys "is this a percent
     * field" off that same regex being present, so `19.000` arrives untouched.
     *
     * Capping here therefore did not enforce a contract, it discarded agreement:
     * a TND sheet formats its whole numeric block to three decimals, so `19.000`
     * is the ORDINARY shape of the cell, and it was answering null before the
     * candidate ladder ran — a blank selector on exactly the files this lane
     * exists to fix, and worse, a re-import that CLEARED a still-agreeing
     * configuration when the only thing that changed was cell formatting.
     *
     * Textual scale is not the question anywhere else either: `19.000` and
     * `19.00` are the same rate, they land as the same `decimal(5,2)` value, and
     * `bccomp(…, 2)` below is what decides agreement. This pattern's only job is
     * keeping exponent forms away from bcmath, which it still does.
     *
     * (Adding the missing percent ceiling to `ImportType::Products` is a separate,
     * larger call — it turns a file accepted today into a row error — and is
     * recorded in the round-2 report rather than taken here.)
     */
    private const PERCENT_DECIMAL_STRING = '/^-?\d+(\.\d+)?$/';

    /**
     * The tax configuration this product may carry ALONGSIDE the rate it is being
     * written with — evaluated on every write, never assumed from the last one.
     *
     * ONE hard condition governs the whole method: the stored configuration's own
     * percentage must equal `$taxRate`. `products.tax_rate` is the number
     * ReceiptCreationService seals into the POS hash chain, and
     * `default_tax_configuration_id` is what the product screen shows and what
     * `DocumentLineTaxResolver` PREFERS over the rate. A pair that disagrees is
     * defect N-1 in storage: the same product taxes at one rate on a document
     * line and another at the till.
     *
     * Order of preference:
     *  1. the configuration the product ALREADY carries, if it still agrees —
     *     so a re-import at an unchanged rate never clobbers an operator's
     *     explicit choice;
     *  2. otherwise re-resolve for the NEW rate: category configuration, then
     *     company configuration;
     *  3. otherwise NULL.
     *
     * Returning null is a real answer, not a failure to decide: it CLEARS a
     * configuration that has gone stale. A blank selector is a question; a wrong
     * one is a wrong tax, and leaving the old pair in place is the one option
     * that is not available.
     *
     * @param  string|null  $existingConfigurationId  what the product carries today, if anything
     * @return string|null A `tax_configurations.id`, or null when none agrees.
     */
    private function resolveCoherentTaxConfigurationId(
        string $tenantId,
        string $companyId,
        int|string|null $categoryId,
        ?string $taxRate,
        ?string $existingConfigurationId,
    ): ?string {
        // The regex is the real gate (gate r1 F-6); `is_numeric` is kept AFTER it
        // purely as the narrowing PHPStan needs to see a `numeric-string` reach
        // `bccomp` below. Both must hold — every string the regex accepts is a
        // valid numeric string, so the pair rejects exactly what the regex does.
        if ($taxRate === null
            || preg_match(self::PERCENT_DECIMAL_STRING, $taxRate) !== 1
            || ! is_numeric($taxRate)
        ) {
            return null;
        }

        $company = Company::where('tenant_id', $tenantId)
            ->where('id', $companyId)
            ->first();

        if (! $company instanceof Company) {
            return null;
        }

        /** @var list<string> $candidates */
        $candidates = [];

        // The product's own configuration is tried FIRST so an operator's choice
        // survives every re-import that does not move the rate out from under it.
        if ($existingConfigurationId !== null && $existingConfigurationId !== '') {
            $candidates[] = $existingConfigurationId;
        }

        if ($categoryId !== null) {
            $categoryConfigurationId = Category::query()
                ->where('id', $categoryId)
                ->where('company_id', $companyId)
                ->value('default_tax_configuration_id');

            if (is_string($categoryConfigurationId) && $categoryConfigurationId !== '') {
                $candidates[] = $categoryConfigurationId;
            }
        }

        if (is_string($company->default_tax_configuration_id) && $company->default_tax_configuration_id !== '') {
            $candidates[] = $company->default_tax_configuration_id;
        }

        foreach ($candidates as $candidate) {
            $configuredRate = $this->taxResolution->resolveRateFromTaxConfiguration($company, $candidate);

            // precision-ok: tax percentage columns are decimal(5,2) throughout.
            if ($configuredRate !== null && bccomp($configuredRate, $taxRate, 2) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    public function resolveCategoryByName(string $companyId, string $name): CategoryResolutionDTO
    {
        return $this->categoryResolution->resolve($companyId, $name);
    }

    private function skuFromName(string $name): string
    {
        $sku = strtoupper(substr(Str::slug($name, '-'), 0, 100));

        return $sku !== '' ? $sku : 'PRODUCT';
    }

    /**
     * The key ladder matches LIVE products only (gate r2 G3A-R2-1).
     *
     * A soft-deleted row is not an updatable target — filling and saving it
     * would silently mutate a deleted product — and it is not a refusal reason
     * on its own either. The one thing a trashed row can do is hold the SKU the
     * caller is about to write, which `refuseIfSkuHeldByDeletedProduct()` checks
     * against `$createSku` in `upsert()`, independently of which column matched.
     */
    public function resolveIdentity(
        string $tenantId,
        string $companyId,
        ?string $fileSku,
        ?string $barcode,
        string $name,
    ): ProductIdentityResolutionData {
        $resolution = $this->productResolver->resolve($tenantId, $companyId, $fileSku, $barcode, $name);
        if ($resolution->failure === ProductIdentityFailure::SkuHeldByDeletedProduct) {
            $this->refuseSkuHeldByDeletedProduct($resolution->failureSku ?? (string) $fileSku);
        }
        if ($resolution->failure === ProductIdentityFailure::BarcodeAmbiguous) {
            throw new RuntimeException(
                'barcode_ambiguous: barcode matches multiple products: '.implode(', ', $resolution->candidateSkus)
            );
        }

        return $resolution;
    }

    /**
     * Refuse when a soft-deleted product in the same company already holds
     * `$sku`. The lifetime `unique(company_id, sku)` counts trashed rows, so the
     * INSERT would otherwise fail as a raw driver error.
     */
    private function refuseIfSkuHeldByDeletedProduct(string $tenantId, string $companyId, string $sku): void
    {
        $held = Product::onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->exists();

        if ($held) {
            $this->refuseSkuHeldByDeletedProduct($sku);
        }
    }

    private function refuseSkuHeldByDeletedProduct(string $sku): never
    {
        throw new RuntimeException(
            "sku_held_by_deleted_product: SKU {$sku} is held by a soft-deleted product; "
            .'purge the deleted record or choose a different SKU.'
        );
    }

    /**
     * Convert empty strings to null.
     */
    private function emptyToNull(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
