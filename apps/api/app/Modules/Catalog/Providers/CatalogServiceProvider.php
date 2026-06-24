<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\Queries\CatalogMediaQuery;
use App\Modules\Catalog\Application\Services\CompositeItemImportService;
use App\Modules\Catalog\Application\Services\MediaService;
use App\Modules\Catalog\Application\Services\PosVariantFeedService;
use App\Modules\Catalog\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Catalog\Domain\Contracts\MediaAttachmentRepositoryInterface;
use App\Modules\Catalog\Domain\Contracts\MediaStorageInterface;
use App\Modules\Catalog\Domain\Contracts\RenditionGeneratorInterface;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use App\Modules\Catalog\Infrastructure\Adapters\EloquentProductVariantLookup;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentMediaAssetRepository;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentMediaAttachmentRepository;
use App\Modules\Catalog\Infrastructure\Rendition\ImageRenditionGenerator;
use App\Modules\Catalog\Infrastructure\Repositories\EloquentAttributeRepository;
use App\Modules\Catalog\Infrastructure\Repositories\EloquentAttributeValueRepository;
use App\Modules\Catalog\Infrastructure\Repositories\EloquentProductVariantRepository;
use App\Modules\Catalog\Infrastructure\Storage\MediaStorageAdapter;
use App\Modules\POS\Infrastructure\Broadcasting\CatalogModelObserver;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\MediaServiceInterface;
use App\Shared\Contracts\PosVariantFeedReader;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompositeItemServiceInterface::class, CompositeItemImportService::class);
        $this->app->bind(AttributeRepository::class, EloquentAttributeRepository::class);
        $this->app->bind(AttributeValueRepository::class, EloquentAttributeValueRepository::class);
        $this->app->bind(ProductVariantRepository::class, EloquentProductVariantRepository::class);
        $this->app->bind(ProductVariantLookup::class, EloquentProductVariantLookup::class);
        $this->app->bind(PosVariantFeedReader::class, PosVariantFeedService::class);
        $this->app->bind(MediaAttachmentRepositoryInterface::class, EloquentMediaAttachmentRepository::class);
        $this->app->bind(MediaAssetRepositoryInterface::class, EloquentMediaAssetRepository::class);
        $this->app->bind(CatalogMediaQueryInterface::class, CatalogMediaQuery::class);
        $this->app->bind(MediaStorageInterface::class, MediaStorageAdapter::class);
        $this->app->bind(RenditionGeneratorInterface::class, ImageRenditionGenerator::class);
        $this->app->bind(MediaServiceInterface::class, MediaService::class);
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
