<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\DTOs\ProductVariantData;
use App\Modules\Catalog\Application\Services\ProductVariantMatrixGenerator;
use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Presentation\Requests\CreateVariantRequest;
use App\Modules\Catalog\Presentation\Requests\GenerateMatrixRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateVariantRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly AttributeRepository $attributeRepo,
        private readonly ProductVariantMatrixGenerator $matrixGenerator,
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
     * Generate the cartesian-product variant matrix for a product.
     *
     * Accepts two payload shapes:
     *   - New (preferred): { axes: [{ attribute_id, value_ids: [...] }] }
     *     Generates the matrix from the explicit value subset supplied per axis.
     *   - Legacy:          { attribute_ids: [...] }
     *     Generates the full matrix from all values of each attribute.
     *
     * The service signature will be updated in task A3 to accept axes natively;
     * until then the axes shape is handled here at the controller layer.
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

        // --- New axes shape ---
        if ($request->has('axes') && is_array($request->input('axes'))) {
            /** @var array<int, array{attribute_id: string, value_ids: array<int, string>}> $axes */
            $axes = $request->input('axes', []);

            $variants = $this->generateMatrixFromAxes($product, $axes);

            return response()->json([
                'data' => $variants->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v))->values(),
            ], 201);
        }

        // --- Legacy attribute_ids shape ---
        /** @var array<int, string> $attributeIds */
        $attributeIds = $request->input('attribute_ids', []);

        $variants = $this->variantService->generateMatrix($product->id, $attributeIds);

        return response()->json([
            'data' => $variants->map(fn (ProductVariant $v): ProductVariantData => ProductVariantData::fromModel($v))->values(),
        ], 201);
    }

    /**
     * Build a variant matrix from an explicit axes+value_ids payload.
     *
     * This is a controller-layer bridge until task A3 rewrites the service
     * method to accept axes natively.
     *
     * @param  array<int, array{attribute_id: string, value_ids: array<int, string>}>  $axesInput
     * @return Collection<int, ProductVariant>
     */
    private function generateMatrixFromAxes(Product $product, array $axesInput): Collection
    {
        /** @var array<string, string[]> $axes  attribute_code => [value_codes] */
        $axes = [];

        /**
         * @var array<string, array<string, array{attributeId: string, attributeValueId: string, label: string}>> $lookup
         */
        $lookup = [];

        foreach ($axesInput as $axisInput) {
            $attributeId = $axisInput['attribute_id'];
            $valueIds    = array_values(array_unique($axisInput['value_ids']));

            $attribute = $this->attributeRepo->findById($attributeId);

            if ($attribute === null) {
                continue;
            }

            $values = ProductAttributeValue::query()
                ->where('attribute_id', $attributeId)
                ->whereIn('id', $valueIds)
                ->orderBy('display_order')
                ->get();

            /** @var array<string, array{attributeId: string, attributeValueId: string, label: string}> $axisLookup */
            $axisLookup = [];

            /** @var array<int, string> $axisCodes */
            $axisCodes = [];

            foreach ($values as $value) {
                $axisCodes[] = $value->code;
                $axisLookup[$value->code] = [
                    'attributeId'      => $attribute->id,
                    'attributeValueId' => $value->id,
                    'label'            => $value->label,
                ];
            }

            if ($axisCodes === []) {
                continue;
            }

            $axes[$attribute->code]   = $axisCodes;
            $lookup[$attribute->code] = $axisLookup;
        }

        $combos = $this->matrixGenerator->cartesian($axes);

        $hasExistingDefault = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_default', true)
            ->exists();

        return DB::transaction(function () use ($combos, $product, $lookup, $hasExistingDefault): Collection {
            $created = collect();

            foreach ($combos as $index => $combo) {
                $codeParts = array_values($combo);
                $suffix    = implode('-', array_map(
                    fn (string $part): string => strtoupper(str_replace(' ', '_', $part)),
                    $codeParts,
                ));

                $variantCode = strtoupper($product->sku).'-'.$suffix;

                $labelParts = [];
                foreach ($combo as $axisCode => $valueCode) {
                    $labelParts[] = $lookup[$axisCode][$valueCode]['label'];
                }
                $nameSuffix = implode(' / ', $labelParts);

                /** @var array<int, array{attributeId: string, attributeValueId: string}> $attributeValues */
                $attributeValues = [];
                foreach ($combo as $axisCode => $valueCode) {
                    $attributeValues[] = [
                        'attributeId'      => $lookup[$axisCode][$valueCode]['attributeId'],
                        'attributeValueId' => $lookup[$axisCode][$valueCode]['attributeValueId'],
                    ];
                }

                $isDefault = $index === 0 && ! $hasExistingDefault;

                $created->push($this->variantService->createVariant(new CreateVariantCommand(
                    tenantId: $product->tenant_id,
                    companyId: $product->company_id,
                    productId: $product->id,
                    variantCode: $variantCode,
                    sku: $variantCode,
                    nameSuffix: $nameSuffix,
                    isDefault: $isDefault,
                    attributeValues: $attributeValues,
                )));
            }

            return $created;
        });
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
