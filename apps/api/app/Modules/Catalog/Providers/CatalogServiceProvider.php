<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\Services\CompositeItemImportService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use App\Modules\Catalog\Infrastructure\Repositories\EloquentAttributeRepository;
use App\Modules\Catalog\Infrastructure\Repositories\EloquentAttributeValueRepository;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use App\Shared\Contracts\CompositeItemServiceInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompositeItemServiceInterface::class, CompositeItemImportService::class);
        $this->app->bind(AttributeRepository::class, EloquentAttributeRepository::class);
        $this->app->bind(AttributeValueRepository::class, EloquentAttributeValueRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        // Bug 1 — catalog mutations that affect the POS active-menu payload.
        //
        // CompositeItem (Codex r2 P2): direct tenant_id / company_id, so
        // the default attribute-lookup observer suffices.
        //
        // ModifierGroup + Modifier (Codex r3 P2): active-menu serializes
        // each composite item's modifier groups and modifiers. Changing a
        // modifier price/name/active flag, or attaching/detaching a group
        // from an item, must surface in the POS without waiting for the
        // 60s polling fallback. ModifierGroup carries tenant_id /
        // company_id directly; Modifier is relation-resolved via
        // ModifierGroup, so it binds through Event::listen (which keeps
        // the closure context that Model::observe would re-resolve away).
        CompositeItem::observe(CatalogModelObserver::class);
        ModifierGroup::observe(CatalogModelObserver::class);

        foreach (['saved', 'deleted'] as $action) {
            Event::listen("eloquent.{$action}: ".Modifier::class, function (Modifier $model) use ($action): void {
                $groupId = $model->modifier_group_id;
                if ($groupId === '') {
                    return;
                }
                $group = ModifierGroup::find($groupId);
                if ($group === null) {
                    return;
                }
                CatalogModelObserver::broadcastFor(
                    tenantId: $group->tenant_id,
                    companyId: $group->company_id,
                    reason: 'Modifier.'.$action,
                    modelClass: Modifier::class,
                );
            });
        }
    }
}
