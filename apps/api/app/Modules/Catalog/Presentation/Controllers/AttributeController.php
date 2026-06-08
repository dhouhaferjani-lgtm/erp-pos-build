<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\Commands\AddAttributeValueCommand;
use App\Modules\Catalog\Application\Commands\CreateAttributeCommand;
use App\Modules\Catalog\Application\DTOs\ProductAttributeData;
use App\Modules\Catalog\Application\DTOs\ProductAttributeValueData;
use App\Modules\Catalog\Application\Services\AttributeService;
use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use App\Modules\Catalog\Presentation\Requests\AddAttributeValueRequest;
use App\Modules\Catalog\Presentation\Requests\CreateAttributeRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class AttributeController extends Controller
{
    public function __construct(
        private readonly AttributeService $attributeService,
        private readonly AttributeRepository $attributeRepo,
        private readonly AttributeValueRepository $attributeValueRepo,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all attributes for the current tenant.
     */
    public function index(): JsonResponse
    {
        $attributes = $this->attributeService->listForTenant();

        return response()->json([
            'data' => $attributes->map(fn (ProductAttribute $a): ProductAttributeData => ProductAttributeData::fromModel($a))->values(),
        ]);
    }

    /**
     * Create a new product attribute.
     */
    public function store(CreateAttributeRequest $request): JsonResponse
    {
        $tenantId = $this->companyContext->requireTenantId();

        $attribute = $this->attributeService->create(new CreateAttributeCommand(
            tenantId: $tenantId,
            code: $request->string('code')->toString(),
            name: $request->string('name')->toString(),
            dataType: AttributeDataType::from($request->string('data_type')->toString()),
            isVariantAxis: $request->boolean('is_variant_axis', true),
            displayOrder: $request->integer('display_order', 0),
        ));

        return response()->json(['data' => ProductAttributeData::fromModel($attribute)], 201);
    }

    /**
     * Add a value to an existing attribute.
     */
    public function storeValue(AddAttributeValueRequest $request, string $attributeId): JsonResponse
    {
        if (! Str::isUuid($attributeId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $tenantId = $this->companyContext->requireTenantId();

        $attribute = $this->attributeRepo->findById($attributeId);

        if ($attribute === null || $attribute->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Attribute not found'], 404);
        }

        $value = $this->attributeService->addValue(new AddAttributeValueCommand(
            tenantId: $tenantId,
            attributeId: $attribute->id,
            code: $request->string('code')->toString(),
            label: $request->string('label')->toString(),
            hexColor: $request->input('hex_color') !== null ? $request->string('hex_color')->toString() : null,
            imageUrl: $request->input('image_url') !== null ? $request->string('image_url')->toString() : null,
            displayOrder: $request->integer('display_order', 0),
        ));

        return response()->json(['data' => ProductAttributeValueData::fromModel($value)], 201);
    }

    /**
     * List values for an attribute.
     */
    public function indexValues(string $attributeId): JsonResponse
    {
        if (! Str::isUuid($attributeId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $tenantId = $this->companyContext->requireTenantId();
        $attribute = $this->attributeRepo->findById($attributeId);

        if ($attribute === null || $attribute->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Attribute not found'], 404);
        }

        $values = $this->attributeValueRepo->listForAttribute($attribute->id);

        return response()->json([
            'data' => $values->map(fn ($v): ProductAttributeValueData => ProductAttributeValueData::fromModel($v))->values(),
        ]);
    }

    /**
     * Soft-delete an attribute.
     */
    public function destroy(string $attributeId): JsonResponse
    {
        if (! Str::isUuid($attributeId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $tenantId = $this->companyContext->requireTenantId();
        $attribute = $this->attributeRepo->findById($attributeId);

        if ($attribute === null || $attribute->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Attribute not found'], 404);
        }

        $this->attributeRepo->softDelete($attribute->id);

        return response()->json(null, 204);
    }
}
