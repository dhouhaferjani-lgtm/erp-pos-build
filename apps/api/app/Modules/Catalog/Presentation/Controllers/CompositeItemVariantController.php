<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\CompositeItemVariantData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Presentation\Requests\StoreVariantRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CompositeItemVariantController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(string $compositeItemId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($compositeItemId);
        $variants = CompositeItemVariant::where('composite_item_id', $item->id)
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'data' => $variants->map(fn (CompositeItemVariant $v) => CompositeItemVariantData::fromModel($v)),
        ]);
    }

    public function store(StoreVariantRequest $request, string $compositeItemId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($compositeItemId);

        // If setting as default, unset other defaults
        if ($request->boolean('is_default')) {
            CompositeItemVariant::where('composite_item_id', $item->id)
                ->update(['is_default' => false]);
        }

        $variant = CompositeItemVariant::create([
            ...$request->validated(),
            'composite_item_id' => $item->id,
        ]);

        return response()->json(['data' => CompositeItemVariantData::fromModel($variant)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $variant = CompositeItemVariant::with('compositeItem')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($variant->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'price_adjustment_type' => ['sometimes', 'string'],
            'price_adjustment' => ['sometimes', 'numeric'],
            'recipe_multiplier' => ['sometimes', 'numeric', 'min:0.0001'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        // If setting as default, unset other defaults
        if (($validated['is_default'] ?? false) === true) {
            CompositeItemVariant::where('composite_item_id', $variant->composite_item_id)
                ->where('id', '!=', $variant->id)
                ->update(['is_default' => false]);
        }

        $variant->update($validated);

        return response()->json(['data' => CompositeItemVariantData::fromModel($variant)]);
    }

    public function destroy(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $variant = CompositeItemVariant::with('compositeItem')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($variant->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $variant->delete();

        return response()->json(null, 204);
    }
}
