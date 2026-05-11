<?php

declare(strict_types=1);

namespace App\Modules\Menu\Providers;

use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // Bug 1 — coarse POS catalog refresh signal.
        //
        // Menu carries tenant_id / company_id directly, so the observer's
        // default attribute-lookup path is sufficient. Note that
        // `Model::observe()` strips constructor state and re-resolves the
        // observer from the container — pass the class name so DI works.
        Menu::observe(CatalogModelObserver::class);

        // MenuCategory and MenuCategoryItem are relation-resolved (no direct
        // tenant_id / company_id columns). `Model::observe(new
        // CatalogModelObserver($closure))` would lose the closure during DI
        // re-resolution, so subscribe to the Eloquent events directly and
        // delegate to the shared broadcast helper after resolving the
        // tenant context via foreign-key lookup.
        foreach (['saved', 'deleted'] as $action) {
            Event::listen("eloquent.{$action}: ".MenuCategory::class, function (MenuCategory $model) use ($action): void {
                $menuId = $model->menu_id;
                if ($menuId === '') {
                    return;
                }
                $menu = Menu::find($menuId);
                if ($menu === null) {
                    return;
                }
                CatalogModelObserver::broadcastFor(
                    tenantId: $menu->tenant_id,
                    companyId: $menu->company_id,
                    reason: 'MenuCategory.'.$action,
                    modelClass: MenuCategory::class,
                );
            });

            Event::listen("eloquent.{$action}: ".MenuCategoryItem::class, function (MenuCategoryItem $model) use ($action): void {
                $categoryId = $model->menu_category_id;
                if ($categoryId === '') {
                    return;
                }
                $menuCategory = MenuCategory::find($categoryId);
                if ($menuCategory === null) {
                    return;
                }
                $menu = Menu::find($menuCategory->menu_id);
                if ($menu === null) {
                    return;
                }
                CatalogModelObserver::broadcastFor(
                    tenantId: $menu->tenant_id,
                    companyId: $menu->company_id,
                    reason: 'MenuCategoryItem.'.$action,
                    modelClass: MenuCategoryItem::class,
                );
            });
        }
    }
}
