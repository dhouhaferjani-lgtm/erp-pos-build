<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\DTOs\ProductIdentityInputData;
use App\Shared\DTOs\ProductIdentityResolutionData;
use App\Shared\Enums\ProductIdentityFailure;
use App\Shared\Enums\ProductIdentityMatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProductResolver implements ProductResolverInterface
{
    public function resolve(
        string $tenantId,
        string $companyId,
        ?string $sku,
        ?string $barcode,
        string $name,
    ): ProductIdentityResolutionData {
        return $this->resolveMany($tenantId, $companyId, [
            new ProductIdentityInputData('single', $sku, $barcode, $name),
        ])['single'];
    }

    public function resolveMany(string $tenantId, string $companyId, array $inputs): array
    {
        $skus = self::uniqueValues($inputs, static fn (ProductIdentityInputData $input): string => self::createSku($input));
        $barcodes = self::uniqueValues($inputs, static fn (ProductIdentityInputData $input): ?string => self::blankToNull($input->barcode));
        $names = self::uniqueValues(
            array_values(array_filter(
                $inputs,
                static fn (ProductIdentityInputData $input): bool => self::blankToNull($input->sku) === null
                    && self::blankToNull($input->barcode) === null,
            )),
            static fn (ProductIdentityInputData $input): ?string => self::normalizedName($input->name),
        );

        $skuRows = $this->rowsBy('sku', $skus, $tenantId, $companyId);
        $barcodeRows = $this->rowsBy('barcode', $barcodes, $tenantId, $companyId);
        $nameRows = $this->rowsByNormalizedName($names, $tenantId, $companyId);
        $resolved = [];

        foreach ($inputs as $input) {
            $resolved[$input->key] = self::resolveInput($input, $skuRows, $barcodeRows, $nameRows);
        }

        return $resolved;
    }

    /**
     * The one identity ladder used by both single-row execution and batched census reads.
     *
     * @param  array<string, list<Product>>  $skuRows
     * @param  array<string, list<Product>>  $barcodeRows
     * @param  array<string, list<Product>>  $nameRows
     */
    private static function resolveInput(
        ProductIdentityInputData $input,
        array $skuRows,
        array $barcodeRows,
        array $nameRows,
    ): ProductIdentityResolutionData {
        $sku = self::blankToNull($input->sku);
        $barcode = self::blankToNull($input->barcode);

        if ($sku !== null && isset($skuRows[$sku][0])) {
            $product = $skuRows[$sku][0];
            if ($product->trashed()) {
                return new ProductIdentityResolutionData(
                    null,
                    null,
                    null,
                    failure: ProductIdentityFailure::SkuHeldByDeletedProduct,
                    failureSku: $sku,
                );
            }

            return new ProductIdentityResolutionData($product->id, $product->sku, ProductIdentityMatch::Sku);
        }

        if ($barcode !== null) {
            $matches = array_values(array_filter(
                $barcodeRows[$barcode] ?? [],
                static fn (Product $product): bool => ! $product->trashed(),
            ));
            if (count($matches) > 1) {
                return new ProductIdentityResolutionData(
                    null,
                    null,
                    null,
                    array_map(static fn (Product $product): string => $product->sku, $matches),
                    ProductIdentityFailure::BarcodeAmbiguous,
                );
            }
            if (isset($matches[0])) {
                return new ProductIdentityResolutionData(
                    $matches[0]->id,
                    $matches[0]->sku,
                    ProductIdentityMatch::Barcode,
                );
            }
        }

        $normalizedName = $sku === null && $barcode === null
            ? self::normalizedName($input->name)
            : null;
        $nameMatches = $normalizedName === null ? [] : ($nameRows[$normalizedName] ?? []);
        $nameMatch = array_find(
            $nameMatches,
            static fn (Product $product): bool => ! $product->trashed(),
        );
        if ($nameMatch instanceof Product) {
            return new ProductIdentityResolutionData($nameMatch->id, $nameMatch->sku, ProductIdentityMatch::Name);
        }

        $createSku = self::createSku($input);
        $deletedHolder = array_find(
            $skuRows[$createSku] ?? [],
            static fn (Product $product): bool => $product->trashed(),
        );
        if ($deletedHolder instanceof Product) {
            return new ProductIdentityResolutionData(
                null,
                null,
                null,
                failure: ProductIdentityFailure::SkuHeldByDeletedProduct,
                failureSku: $createSku,
            );
        }

        return new ProductIdentityResolutionData(null, null, null);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, list<Product>>
     */
    private function rowsBy(string $column, array $values, string $tenantId, string $companyId): array
    {
        if ($values === []) {
            return [];
        }

        /** @var Collection<int, Product> $rows */
        $rows = Product::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn($column, $values)
            ->orderBy('sku')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->getAttribute($column)][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<Product>>
     */
    private function rowsByNormalizedName(array $names, string $tenantId, string $companyId): array
    {
        if ($names === []) {
            return [];
        }

        /** @var Collection<int, Product> $rows */
        $rows = Product::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn(DB::raw('LOWER(TRIM(name))'), $names)
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::normalizedName($row->name) ?? ''][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<ProductIdentityInputData>  $inputs
     * @param  callable(ProductIdentityInputData): ?string  $value
     * @return list<string>
     */
    private static function uniqueValues(array $inputs, callable $value): array
    {
        $values = [];
        foreach ($inputs as $input) {
            $resolved = $value($input);
            if ($resolved !== null) {
                $values[$resolved] = true;
            }
        }

        return array_keys($values);
    }

    private static function normalizedName(string $name): ?string
    {
        $name = mb_strtolower(trim($name));

        return $name === '' ? null : $name;
    }

    private static function createSku(ProductIdentityInputData $input): string
    {
        $sku = self::blankToNull($input->sku);
        if ($sku !== null) {
            return $sku;
        }

        $barcode = self::blankToNull($input->barcode);
        if ($barcode !== null) {
            return $barcode;
        }

        $nameSku = strtoupper(substr(Str::slug($input->name, '-'), 0, 100));

        return $nameSku !== '' ? $nameSku : 'PRODUCT';
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
