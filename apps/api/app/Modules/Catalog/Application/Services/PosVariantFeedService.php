<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Shared\Contracts\PosVariantFeedReader;
use App\Shared\DTOs\PosVariantData;
use App\Shared\DTOs\PosVariantFeedPageDTO;
use Carbon\CarbonImmutable;

final class PosVariantFeedService implements PosVariantFeedReader
{
    public function read(
        string $tenantId,
        string $companyId,
        ?CarbonImmutable $updatedSince,
        ?CarbonImmutable $updatedUntil,
        int $page,
        int $perPage,
    ): PosVariantFeedPageDTO {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 500);

        $paginator = ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($updatedSince !== null, fn ($q) => $q->where('updated_at', '>', $updatedSince))
            ->when($updatedUntil !== null, fn ($q) => $q->where('updated_at', '<=', $updatedUntil))
            ->orderBy('product_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        $variants = [];
        foreach ($paginator->items() as $v) {
            /** @var ProductVariant $v */
            $variants[] = new PosVariantData(
                id: $v->id,
                productId: $v->product_id,
                sku: $v->sku,
                barcode: $v->barcode,
                nameSuffix: $v->name_suffix,
                isDefault: $v->is_default,
                displayOrder: $v->display_order,
                priceOverride: $v->price_override,
                imageUrl: $v->image_url,
                updatedAt: $v->updated_at?->toIso8601String(),
            );
        }

        $deletedIds = [];
        if ($updatedSince !== null && $page === 1) {
            /** @var list<string> $softDeleted */
            $softDeleted = ProductVariant::onlyTrashed()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('deleted_at', '>', $updatedSince)
                ->when($updatedUntil !== null, fn ($q) => $q->where('deleted_at', '<=', $updatedUntil))
                ->pluck('id')
                ->all();
            /** @var list<string> $deactivated */
            $deactivated = ProductVariant::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('is_active', false)
                ->where('updated_at', '>', $updatedSince)
                ->when($updatedUntil !== null, fn ($q) => $q->where('updated_at', '<=', $updatedUntil))
                ->pluck('id')
                ->all();
            $deletedIds = array_values(array_unique([...$softDeleted, ...$deactivated]));
        }

        return new PosVariantFeedPageDTO(
            variants: $variants,
            deletedIds: $deletedIds,
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }
}
