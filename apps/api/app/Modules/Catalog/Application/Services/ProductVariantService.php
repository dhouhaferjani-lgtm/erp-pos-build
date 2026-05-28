<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\Exceptions\MissingVariantException;
use App\Modules\Catalog\Application\Exceptions\VariantRequiredException;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use Illuminate\Support\Facades\DB;

final class ProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $variantRepo,
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
                // TODO(Task 14): migrate pre-existing product-level stock to this default variant on first-variant creation
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
}
