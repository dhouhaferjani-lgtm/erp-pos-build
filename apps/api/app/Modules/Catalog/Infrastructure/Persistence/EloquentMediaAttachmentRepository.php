<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Contracts\MediaAttachmentRepositoryInterface;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class EloquentMediaAttachmentRepository implements MediaAttachmentRepositoryInterface
{
    /**
     * @param  array<string>  $ownerIds
     * @return array<string, array<int, MediaAttachment>>
     */
    public function forOwners(MediaOwnerType $type, array $ownerIds, string $tenantId): array
    {
        if ($ownerIds === []) {
            return [];
        }

        // Defense-in-depth: tenant-scope EVERY tenant-scoped table in the query
        // (the link row, the asset via whereHas, and both eager-loaded relations).
        $rows = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $type)
            ->whereIn('owner_id', $ownerIds)
            ->whereHas('mediaAsset', static function (Builder $q) use ($tenantId): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $tenantId)
                    ->where('status', MediaStatus::Ready);
            })
            ->with([
                'mediaAsset' => static function (Relation $relation) use ($tenantId): void {
                    /** @var Relation<MediaAsset, MediaAttachment, *> $relation */
                    $relation->where('tenant_id', $tenantId);
                },
            ])
            ->orderBy('sort_order')
            ->get();

        /** @var array<string, array<int, MediaAttachment>> */
        return $rows
            ->groupBy('owner_id')
            ->map(static fn ($group) => $group->values()->all())
            ->all();
    }
}
