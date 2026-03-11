<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Menu\Application\DTOs\MenuCategoryData;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\Menu\Presentation\Requests\AddMenuCategoryItemRequest;
use App\Modules\Menu\Presentation\Requests\StoreMenuCategoryRequest;
use App\Modules\Menu\Presentation\Requests\SyncMenuCategoryItemsRequest;
use App\Modules\Menu\Presentation\Requests\UpdateMenuCategoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class MenuCategoryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function store(StoreMenuCategoryRequest $request, string $menuId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($menuId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $menu = Menu::query()->forCompany($companyId)->findOrFail($menuId);

        $category = $menu->categories()->create($request->validated());
        $category->load(['compositeItems', 'products']);

        return response()->json(['data' => MenuCategoryData::fromModel($category)], 201);
    }

    public function update(UpdateMenuCategoryRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $category = MenuCategory::query()
            ->whereHas('menu', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($id);

        $category->update($request->validated());
        $category->load(['compositeItems', 'products']);

        return response()->json(['data' => MenuCategoryData::fromModel($category)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $category = MenuCategory::query()
            ->whereHas('menu', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($id);

        $category->delete();

        return response()->json(null, 204);
    }

    public function syncItems(SyncMenuCategoryItemsRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $category = MenuCategory::query()
            ->whereHas('menu', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($id);

        /** @var array{items: array<int, array{sellable_type: string, sellable_id: string, override_price?: string|null, display_order?: int, is_available?: bool}>} $validated */
        $validated = $request->validated();

        MenuCategoryItem::where('menu_category_id', $category->id)->delete();

        foreach ($validated['items'] as $index => $item) {
            MenuCategoryItem::create([
                'menu_category_id' => $category->id,
                'composite_item_id' => $item['sellable_type'] === 'composite_item' ? $item['sellable_id'] : null,
                'product_id' => $item['sellable_type'] === 'product' ? $item['sellable_id'] : null,
                'override_price' => $item['override_price'] ?? null,
                'display_order' => $item['display_order'] ?? $index,
                'is_available' => $item['is_available'] ?? true,
            ]);
        }

        $category->load(['compositeItems', 'products']);

        return response()->json(['data' => MenuCategoryData::fromModel($category)]);
    }

    public function addItem(AddMenuCategoryItemRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $category = MenuCategory::query()
            ->whereHas('menu', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($id);

        /** @var array{sellable_type: string, sellable_id: string, override_price?: string|null, display_order?: int, is_available?: bool} $validated */
        $validated = $request->validated();

        MenuCategoryItem::create([
            'menu_category_id' => $category->id,
            'composite_item_id' => $validated['sellable_type'] === 'composite_item' ? $validated['sellable_id'] : null,
            'product_id' => $validated['sellable_type'] === 'product' ? $validated['sellable_id'] : null,
            'override_price' => $validated['override_price'] ?? null,
            'display_order' => $validated['display_order'] ?? 0,
            'is_available' => $validated['is_available'] ?? true,
        ]);

        $category->load(['compositeItems', 'products']);

        return response()->json(['data' => MenuCategoryData::fromModel($category)], 201);
    }

    public function removeItem(string $categoryId, string $itemId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($categoryId) || ! Str::isUuid($itemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        // Verify category belongs to company
        MenuCategory::query()
            ->whereHas('menu', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($categoryId);

        MenuCategoryItem::where('menu_category_id', $categoryId)
            ->where('id', $itemId)
            ->delete();

        return response()->json(null, 204);
    }
}
