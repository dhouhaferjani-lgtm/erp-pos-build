<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\Exceptions\MissingVariantException;
use App\Modules\Catalog\Application\Exceptions\VariantRequiredException;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use App\Modules\Inventory\Application\Services\StockLevelMigrationService;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $variantRepo,
        private readonly AttributeRepository $attributeRepo,
        private readonly AttributeValueRepository $attributeValueRepo,
        private readonly ProductVariantMatrixGenerator $matrixGenerator,
        private readonly StockLevelMigrationService $stockMigrator,
    ) {}

    /**
     * Persist a new product variant together with its attribute-value junction rows,
     * then emit ProductVariantCreated.
     *
     * When this is the first variant for the product (and isDefault is true), a
     * stock-level migration from product-level stock to this default variant should
     * occur — that is deferred to Task 14.
     */
    public function createVariant(CreateVariantCommand $command): ProductVariant
    {
        return DB::transaction(function () use ($command): ProductVariant {
            $variant = new ProductVariant;
            $variant->tenant_id = $command->tenantId;
            $variant->company_id = $command->companyId;
            $variant->product_id = $command->productId;
            $variant->variant_code = $command->variantCode;
            $variant->sku = $command->sku;
            $variant->name_suffix = $command->nameSuffix;
            $variant->is_default = $command->isDefault;
            $variant->barcode = $command->barcode;
            $variant->price_override = $command->priceOverride;
            $variant->cost_override = $command->costOverride;
            $variant->image_url = $command->imageUrl;

            $this->variantRepo->save($variant);

            foreach ($command->attributeValues as $pair) {
                ProductVariantAttributeValue::create([
                    'variant_id' => $variant->id,
                    'attribute_id' => $pair['attributeId'],
                    'attribute_value_id' => $pair['attributeValueId'],
                ]);
            }

            $isFirstVariant = $this->variantRepo->listForProduct($command->productId, onlyActive: false)->count() === 1;

            if ($isFirstVariant && $command->isDefault) {
                $this->stockMigrator->migrateToDefaultVariant(
                    productId: $command->productId,
                    defaultVariantId: $variant->id,
                );
            }

            event(new ProductVariantCreated(
                variantId: $variant->id,
                tenantId: $variant->tenant_id,
                companyId: $variant->company_id,
                productId: $variant->product_id,
                variantCode: $variant->variant_code,
                sku: $variant->sku,
                isDefault: $variant->is_default,
                createdAt: now()->toIso8601String(),
            ));

            return $variant;
        });
    }

    /**
     * Make the given variant the default for its product, clearing any previous default
     * in the same atomic transaction.
     *
     * @throws MissingVariantException when the variant does not exist.
     * @throws VariantRequiredException when there are no variants at all for the product.
     */
    public function setDefault(string $variantId): ProductVariant
    {
        return DB::transaction(function () use ($variantId): ProductVariant {
            $variant = $this->variantRepo->findById($variantId);

            if ($variant === null) {
                throw MissingVariantException::withId($variantId);
            }

            $existing = $this->variantRepo->listForProduct($variant->product_id, onlyActive: false);

            if ($existing->isEmpty()) {
                throw VariantRequiredException::forProduct($variant->product_id);
            }

            // Clear the previous default for this product (the DB partial-unique constraint
            // prevents two concurrent defaults, but we must clear the flag explicitly before
            // setting the new one).
            ProductVariant::where('product_id', $variant->product_id)
                ->where('is_default', true)
                ->where('id', '!=', $variantId)
                ->update(['is_default' => false]);

            $variant->is_default = true;
            $this->variantRepo->save($variant);

            return $variant;
        });
    }

    /**
     * Resolve a variant by barcode within a company scope, or return null.
     */
    public function resolveBarcode(string $barcode, string $companyId): ?ProductVariant
    {
        return $this->variantRepo->findByBarcode($barcode, $companyId);
    }

    /**
     * Resolve a variant by SKU within a company scope, or return null.
     */
    public function resolveSku(string $sku, string $companyId): ?ProductVariant
    {
        return $this->variantRepo->findBySku($sku, $companyId);
    }

    /**
     * Generate and persist the full cartesian-product variant matrix for a product.
     *
     * For each attribute in $attributeIds, all its values are loaded and used as
     * one axis. The resulting N₁ × N₂ × … combinations are each persisted as a
     * ProductVariant (via createVariant) with junction rows.
     *
     * The entire creation loop runs inside a single outer DB transaction so that
     * a failure on any combo rolls back all previously-created variants atomically.
     * Under PostgreSQL, the nested DB::transaction calls inside createVariant become
     * savepoints, so nesting is safe.
     *
     * SKU / variant_code uniqueness guarantee:
     *   "{product->sku}-{valueCode1}-{valueCode2}-…" uppercased and stripped of
     *   spaces. The cartesian product guarantees each combination of value codes is
     *   distinct within this call, so no two generated variants can produce the same
     *   code. Tenant-global SKU uniqueness additionally relies on product SKUs being
     *   unique per tenant — this method does not enforce cross-product uniqueness.
     *
     * name_suffix uses human-readable value LABELs (e.g. "39 / Noir"), while
     * variant_code and SKU use the machine-readable value CODEs (e.g. "39-NOIR").
     *
     * is_default rule: the first generated variant is set as default ONLY when
     * the product currently has zero variants. Subsequent calls therefore never
     * add a second default.
     *
     * @param  string[]  $attributeIds
     * @return Collection<int, ProductVariant>
     *
     * @throws RuntimeException when the product does not exist.
     */
    public function generateMatrix(string $productId, array $attributeIds): Collection
    {
        /** @var Product|null $product */
        $product = Product::find($productId);

        if ($product === null) {
            throw new RuntimeException("Product [{$productId}] not found.");
        }

        // Build axes (string codes) AND a parallel ID-lookup map at the same time.
        // axes:   ['taille' => ['36', '37', …], 'couleur' => ['noir', …]]
        // lookup: ['taille' => ['36' => {attributeId, attributeValueId, label}, …], …]

        /** @var array<string, string[]> $axes */
        $axes = [];

        /**
         * @var array<string, array<string, array{attributeId: string, attributeValueId: string, label: string}>> $lookup
         */
        $lookup = [];

        foreach ($attributeIds as $attributeId) {
            $attribute = $this->attributeRepo->findById((string) $attributeId);

            if ($attribute === null) {
                throw new RuntimeException("Attribute [{$attributeId}] not found.");
            }

            $values = $this->attributeValueRepo->listForAttribute($attribute->id);

            /** @var array<string, array{attributeId: string, attributeValueId: string, label: string}> $axisLookup */
            $axisLookup = [];

            /** @var array<int, string> $axisCodes */
            $axisCodes = [];

            /** @var ProductAttributeValue $value */
            foreach ($values as $value) {
                $axisCodes[] = $value->code;
                $axisLookup[$value->code] = [
                    'attributeId' => $attribute->id,
                    'attributeValueId' => $value->id,
                    'label' => $value->label,
                ];
            }

            $axes[$attribute->code] = $axisCodes;
            $lookup[$attribute->code] = $axisLookup;
        }

        $combos = $this->matrixGenerator->cartesian($axes);

        // Determine if this is the first matrix generation (no existing variants).
        $hasExistingDefault = $this->variantRepo
            ->listForProduct($productId, onlyActive: false)
            ->contains('is_default', true);

        // Wrap the entire creation loop in an outer transaction so that any
        // mid-loop failure (e.g. unique constraint violation) rolls back ALL
        // previously-created variants atomically.
        return DB::transaction(function () use ($combos, $product, $productId, $lookup, $hasExistingDefault): Collection {
            $created = collect();

            foreach ($combos as $index => $combo) {
                // Build suffix parts in axis order for deterministic, unique codes.
                // Uses value CODEs (machine-readable) for variant_code / SKU.
                $codeParts = array_values($combo);
                $suffix = implode('-', array_map(
                    fn (string $part): string => strtoupper(str_replace(' ', '_', $part)),
                    $codeParts
                ));

                $variantCode = strtoupper($product->sku).'-'.$suffix;
                $sku = $variantCode;

                // Build name suffix from human-readable value LABELs (e.g. "39 / Noir").
                $labelParts = [];
                foreach ($combo as $axisCode => $valueCode) {
                    $labelParts[] = $lookup[$axisCode][$valueCode]['label'];
                }
                $nameSuffix = implode(' / ', $labelParts);

                // Build junction pairs from the lookup.
                /** @var array<int, array{attributeId: string, attributeValueId: string}> $attributeValues */
                $attributeValues = [];
                foreach ($combo as $axisCode => $valueCode) {
                    $attributeValues[] = [
                        'attributeId' => $lookup[$axisCode][$valueCode]['attributeId'],
                        'attributeValueId' => $lookup[$axisCode][$valueCode]['attributeValueId'],
                    ];
                }

                // First combo becomes default only when the product has no existing default.
                $isDefault = $index === 0 && ! $hasExistingDefault;

                $command = new CreateVariantCommand(
                    tenantId: $product->tenant_id,
                    companyId: $product->company_id,
                    productId: $productId,
                    variantCode: $variantCode,
                    sku: $sku,
                    nameSuffix: $nameSuffix,
                    isDefault: $isDefault,
                    attributeValues: $attributeValues,
                );

                $created->push($this->createVariant($command));
            }

            return $created;
        });
    }
}
