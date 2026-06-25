<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Persistence;

use App\Modules\Media\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;

final readonly class EloquentMediaAssetRepository implements MediaAssetRepositoryInterface
{
    public function save(MediaAsset $asset): void
    {
        $asset->save();
    }

    /**
     * Scopes by BOTH id AND tenant_id — returns null when the asset is absent
     * or belongs to a different tenant.
     */
    public function find(string $id, string $tenantId): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function markProcessing(MediaAsset $asset): void
    {
        $asset->status = MediaStatus::Processing;
        $asset->save();
    }

    public function markReady(MediaAsset $asset): void
    {
        $asset->status = MediaStatus::Ready;
        $asset->save();
    }

    public function markFailed(MediaAsset $asset): void
    {
        $asset->status = MediaStatus::Failed;
        $asset->save();
    }
}
