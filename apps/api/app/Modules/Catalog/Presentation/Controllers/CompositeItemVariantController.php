<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\CompositeItemVariantData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Presentation\Requests\StoreVariantRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
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
        // api.catalog.021 round-2: ::query() + tenant_id predicate on CompositeItem lookup.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);
        $variants = CompositeItemVariant::where('composite_item_id', $item->id)
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'data' => $variants->map(fn (CompositeItemVariant $v) => CompositeItemVariantData::fromModel($v)),
        ]);
    }

    public function store(StoreVariantRequest $request, string $compositeItemId): JsonResponse
    {
        // api.catalog.021 round-2: ::query() + tenant_id predicate on CompositeItem lookup.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);

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
        // api.catalog.021 round-2: whereHas scopes CompositeItemVariant via compositeItem
        // (composite_item_variants has no tenant_id/company_id — ownership via FK).
        // whereRaw required because the generic Builder<Model> closure does not narrow
        // to CompositeItem at PHPStan level 8.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $variant = CompositeItemVariant::with('compositeItem')
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

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
        // api.catalog.021 round-2: whereHas scopes CompositeItemVariant via compositeItem.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $variant = CompositeItemVariant::with('compositeItem')
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

        $variant->delete();

        return response()->json(null, 204);
    }
}
