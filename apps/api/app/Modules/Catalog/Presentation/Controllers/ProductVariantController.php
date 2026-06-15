<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\DTOs\ProductVariantData;
use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Presentation\Requests\CreateVariantRequest;
use App\Modules\Catalog\Presentation\Requests\GenerateMatrixRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateVariantRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * REST surface for product variants.
 *
 * cost_override accepted on create/update is ADVISORY ONLY (spec §6.7): it is
 * persisted for display, never wired into the inventory WAC pipeline. This
 * controller never touches WeightedAverageCostService / StockAdjustmentService.
 */
class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductVariantService $variantService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List variants for a product (scoped to the current company).
     */
    public function index(string $productId): JsonResponse
    {
        if (! Str::isUuid($productId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $variants = ProductVariant::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('product_id', $productId)
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'data' => $variants->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v))->values(),
        ]);
    }

    /**
     * Create a single variant for a product.
     */
    public function store(CreateVariantRequest $request, string $productId): JsonResponse
    {
        if (! Str::isUuid($productId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->find($productId);

        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        /** @var array<int, array{attribute_id: string, attribute_value_id: string}> $pairs */
        $pairs = $request->input('attribute_values', []);

        $attributeValues = array_map(
            static fn (array $pair): array => [
                'attributeId' => $pair['attribute_id'],
                'attributeValueId' => $pair['attribute_value_id'],
            ],
            $pairs,
        );

        $variant = $this->variantService->createVariant(new CreateVariantCommand(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            productId: $product->id,
            variantCode: $request->string('variant_code')->toString(),
            sku: $request->string('sku')->toString(),
            nameSuffix: $request->string('name_suffix')->toString(),
            isDefault: $request->boolean('is_default', false),
            barcode: $request->input('barcode') !== null ? $request->string('barcode')->toString() : null,
            priceOverride: $request->input('price_override') !== null ? $request->string('price_override')->toString() : null,
            costOverride: $request->input('cost_override') !== null ? $request->string('cost_override')->toString() : null,
            imageUrl: $request->input('image_url') !== null ? $request->string('image_url')->toString() : null,
            attributeValues: $attributeValues,
        ));

        return response()->json(['data' => ProductVariantData::fromModel($variant)], 201);
    }

    /**
     * Generate the full cartesian-product variant matrix for a product.
     */
    public function generateMatrix(GenerateMatrixRequest $request, string $productId): JsonResponse
    {
        if (! Str::isUuid($productId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->find($productId);

        if ($product === null) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        /** @var array<int, string> $attributeIds */
        $attributeIds = $request->input('attribute_ids', []);

        $variants = $this->variantService->generateMatrix($product->id, $attributeIds);

        return response()->json([
            'data' => $variants->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v))->values(),
        ], 201);
    }

    /**
     * Partially update a variant.
     */
    public function update(UpdateVariantRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $variant = ProductVariant::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->find($id);

        if ($variant === null) {
            return response()->json(['message' => 'Variant not found'], 404);
        }

        $variant->fill($request->validated());
        $variant->save();

        return response()->json(['data' => ProductVariantData::fromModel($variant)]);
    }

    /**
     * Soft-delete a variant.
     */
    public function destroy(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $variant = ProductVariant::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->find($id);

        if ($variant === null) {
            return response()->json(['message' => 'Variant not found'], 404);
        }

        $variant->delete();

        return response()->json(null, 204);
    }
}
