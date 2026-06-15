<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\DTOs\ProductVariantData;
use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Presentation\Requests\CreateVariantRequest;
use App\Modules\Catalog\Presentation\Requests\GenerateMatrixRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateVariantRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\VariantStockReader;
use App\Shared\Domain\Exceptions\MatrixGenerationLimitException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        private readonly VariantStockReader $stockReader,
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
            ->with('attributeValues')
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

        $variant->loadMissing('attributeValues');

        return response()->json(['data' => ProductVariantData::fromModel($variant)], 201);
    }

    /**
     * Generate the variant matrix for a product from a value-subset selection.
     *
     * Accepts either the preferred `axes` shape (each axis = attribute + value-id
     * subset) or the legacy `attribute_ids` shape (all values of each attribute).
     * Returns the affected variants (created + restored) plus per-bucket meta counts.
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

        $axes = $this->normalizeAxes($request);

        try {
            $result = $this->variantService->generateMatrix($product->id, $axes);
        } catch (MatrixGenerationLimitException $e) {
            return response()->json(['errors' => ['combinations' => [$e->getMessage()]]], 422);
        }

        /** @var EloquentCollection<int, ProductVariant> $affected */
        $affected = new EloquentCollection(
            $result['created']->merge($result['restored'])->all(),
        );
        $affected->loadMissing('attributeValues');

        return response()->json([
            'data' => $affected
                ->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v))
                ->values(),
            'meta' => [
                'created_count' => $result['created']->count(),
                'restored_count' => $result['restored']->count(),
                'skipped_count' => $result['skipped_count'],
            ],
        ], 201);
    }

    /**
     * Normalise the validated request into the service's axes shape.
     *
     * - `axes` present  → map each {attribute_id, value_ids} → {attributeId, valueIds}.
     * - legacy `attribute_ids` → expand each attribute to ALL of its value IDs.
     *
     * @return array<int, array{attributeId: string, valueIds: string[]}>
     */
    private function normalizeAxes(GenerateMatrixRequest $request): array
    {
        if ($request->has('axes') && is_array($request->input('axes'))) {
            /** @var array<int, array{attribute_id: string, value_ids: string[]}> $rawAxes */
            $rawAxes = $request->input('axes', []);

            return array_map(
                static fn (array $axis): array => [
                    'attributeId' => $axis['attribute_id'],
                    'valueIds' => array_values($axis['value_ids']),
                ],
                $rawAxes,
            );
        }

        /** @var array<int, string> $attributeIds */
        $attributeIds = $request->input('attribute_ids', []);

        return array_map(
            static fn (string $attributeId): array => [
                'attributeId' => $attributeId,
                'valueIds' => ProductAttributeValue::query()
                    ->where('attribute_id', $attributeId)
                    ->pluck('id')
                    ->all(),
            ],
            $attributeIds,
        );
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

        $variant = $this->variantService->updateVariant($variant, $request->validated());

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

        if (bccomp($this->stockReader->variantOnHandQuantity($company->tenant_id, $company->id, $variant->id), '0', 4) === 1) {
            return response()->json([
                'message' => 'This variant has stock on hand. Deactivate it instead of deleting.',
            ], 422);
        }

        $variant->delete();

        return response()->json(null, 204);
    }
}
