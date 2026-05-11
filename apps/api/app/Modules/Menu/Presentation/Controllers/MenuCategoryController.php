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
use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use Illuminate\Database\Eloquent\Builder;
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
            ->whereHas('menu', fn (Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
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
            ->whereHas('menu', fn (Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
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
            ->whereHas('menu', fn (Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($id);

        /** @var array{items: array<int, array{sellable_type: string, sellable_id: string, override_price?: string|null, display_order?: int, is_available?: bool}>} $validated */
        $validated = $request->validated();

        // Bug 1 — Eloquent's mass-delete (`where()->delete()`) bypasses
        // model events, so the CatalogModelObserver-equivalent Event::listen
        // subscription in MenuServiceProvider does NOT fire here. Emit an
        // explicit CatalogChannelEvent after the rewrite so the POS picks
        // up the change immediately (per Codex r1 P2).
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

        $this->broadcastCatalogChange($category, 'MenuCategoryItem.sync');

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
            ->whereHas('menu', fn (Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
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
        $category = MenuCategory::query()
            ->whereHas('menu', fn (Builder $q) => $q->whereRaw('company_id = ?', [$companyId]))
            ->findOrFail($categoryId);

        // Bug 1 — single-row `where()->delete()` is still a mass delete in
        // Eloquent and bypasses model events. Explicit broadcast keeps the
        // POS in sync (per Codex r1 P2).
        MenuCategoryItem::where('menu_category_id', $categoryId)
            ->where('id', $itemId)
            ->delete();

        $this->broadcastCatalogChange($category, 'MenuCategoryItem.removed');

        return response()->json(null, 204);
    }

    /**
     * Emit a CatalogChannelEvent for the parent menu's tenant + company.
     * Used after mass-delete operations that bypass Eloquent model events.
     */
    private function broadcastCatalogChange(MenuCategory $category, string $reason): void
    {
        $menu = $category->menu()->first();
        if ($menu === null) {
            return;
        }
        CatalogModelObserver::broadcastFor(
            tenantId: $menu->tenant_id,
            companyId: $menu->company_id,
            reason: $reason,
            modelClass: MenuCategoryItem::class,
        );
    }
}
