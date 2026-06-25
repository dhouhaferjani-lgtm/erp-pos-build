<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Media\MediaAsset;

interface MediaAssetRepositoryInterface
{
    /**
     * Persist (insert or update) a MediaAsset.
     */
    public function save(MediaAsset $asset): void;

    /**
     * Find a MediaAsset by its id, scoped to the given tenant.
     * Returns null when the asset does not exist OR belongs to a different tenant.
     */
    public function find(string $id, string $tenantId): ?MediaAsset;

    /**
     * Set status to PROCESSING and persist.
     */
    public function markProcessing(MediaAsset $asset): void;

    /**
     * Set status to READY and persist.
     */
    public function markReady(MediaAsset $asset): void;

    /**
     * Set status to FAILED and persist.
     */
    public function markFailed(MediaAsset $asset): void;
}
