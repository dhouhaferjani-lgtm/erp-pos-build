<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\DTOs\CategoryResolutionDTO;
use Illuminate\Support\Str;

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
        $product = Product::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        return $product?->id;
    }

    /**
     * Create or update a product.
     *
     * @param  array<string, mixed>  $data  Product data
     * @return string The product ID
     */
    public function upsert(
        string $tenantId,
        string $companyId,
        array $data
    ): string {
        $fileSku = $this->emptyToNull($data['sku'] ?? null);
        $barcode = $this->emptyToNull($data['barcode'] ?? null);
        $nameSku = $this->skuFromName((string) $data['name']);
        $existing = $this->findExistingProduct($tenantId, $companyId, $fileSku, $barcode, $nameSku);
        $createSku = $fileSku ?? ($barcode ?? $nameSku);

        $attributes = [
            'name' => $data['name'],
            'type' => ProductType::from((string) ($this->emptyToNull($data['type'] ?? null) ?? ProductType::Part->value)),
            'description' => $this->emptyToNull($data['description'] ?? null),
            'sale_price' => $this->emptyToNull($data['sale_price'] ?? null),
            'purchase_price' => $this->emptyToNull($data['purchase_price'] ?? null),
            'barcode' => $barcode,
            'tax_rate' => $this->emptyToNull($data['tax_rate'] ?? null),
            'unit' => $this->emptyToNull($data['unit'] ?? null),
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
            $attributes['brand_source'] = BrandSource::User;
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
        // (CompanyTaxProvisioningService.php:63). Inherit it — category first, then
        // company — but only for a product that does not already carry one, so a
        // re-import never clobbers an operator's explicit choice.
        if ($existing === null || $existing->default_tax_configuration_id === null) {
            $inherited = $this->resolveDefaultTaxConfigurationId(
                $tenantId,
                $companyId,
                $attributes['category_id'] ?? null,
                $this->emptyToNull($attributes['tax_rate']),
            );

            if ($inherited !== null) {
                $attributes['default_tax_configuration_id'] = $inherited;
            }
        }

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing->id;
        }

        $product = Product::create(
            array_merge([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'sku' => $createSku,
            ], $attributes)
        );

        return $product->id;
    }

    /**
     * The tax configuration an imported product should inherit — category first,
     * then company — subject to ONE hard condition: the configuration's own
     * percentage must equal the rate the product is being written with.
     *
     * That condition is the whole point. `products.tax_rate` is the number
     * ReceiptCreationService seals into the POS hash chain, and
     * `default_tax_configuration_id` is what the product screen shows and what
     * DocumentLineTaxResolver prefers. Storing a configuration next to a rate it
     * disagrees with is exactly defect N-1 — a product priced at one VAT rate and
     * sold at another. When the two levels disagree, leaving the id null is the
     * honest answer; a blank selector is a question, a wrong one is a wrong tax.
     *
     * @return string|null A `tax_configurations.id`, or null when none agrees.
     */
    private function resolveDefaultTaxConfigurationId(
        string $tenantId,
        string $companyId,
        int|string|null $categoryId,
        ?string $taxRate,
    ): ?string {
        if ($taxRate === null || ! is_numeric($taxRate)) {
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

    private function findExistingProduct(
        string $tenantId,
        string $companyId,
        ?string $fileSku,
        ?string $barcode,
        string $nameSku
    ): ?Product {
        $query = Product::where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        if ($fileSku !== null) {
            return $query->where('sku', $fileSku)->first();
        }

        if ($barcode !== null) {
            return $query->where('barcode', $barcode)->first();
        }

        return $query->where('sku', $nameSku)->first();
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
