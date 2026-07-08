<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\DTOs\EnrichmentImagePersistOutcome;
use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates persistence of enrichment images for a product:
 * normalize → per-descriptor get-or-create asset by `source_ref` → SSRF-safe
 * fetch → upload to object storage → checksum backstop → policy → attach.
 *
 * Concurrency & idempotency guarantees:
 *  - Get-or-create is keyed on the DB partial-unique `(tenant_id, source_ref)`
 *    index; a concurrent loser catches the precise unique violation, reloads the
 *    winner and attaches idempotently — it never re-downloads.
 *  - The critical section (checksum backstop → attach) is serialized per product
 *    with a {@see Cache::lock()} (DB-agnostic: array/redis), eliminating the
 *    same-bytes/different-URL double-attach and the double-PRIMARY race.
 *  - Every asset<->owner link is protected by the `owner_asset` unique index;
 *    the persister no-ops only on THAT specific violation.
 *  - Each descriptor is isolated: one bad URL is logged, counted and skipped;
 *    the run continues. A freshly-created asset that fails to attach (or is a
 *    checksum duplicate) is hard-deleted as an orphan.
 *
 * Runs with NO CompanyContext (invoked from a queued job): every dependency is
 * constructor-injected and the tenant/currency-free media path is used.
 */
final class EnrichmentImagePersister
{
    /**
     * Max images processed per run — mirrors the normalizer cap so the two stay
     * consistent even if a caller passes a larger raw payload.
     */
    private const CAP = 6;

    /** Seconds a per-product lock may be held before it auto-expires. */
    private const LOCK_TTL = 30;

    /** Seconds to wait to acquire a contended per-product lock. */
    private const LOCK_WAIT = 10;

    /** DB index name for the `(tenant_id, source_ref)` partial-unique constraint. */
    private const SOURCE_REF_INDEX = 'media_assets_tenant_source_ref_unique';

    /** DB index name for the `(tenant, owner, asset)` attachment-unique constraint. */
    private const ATTACH_INDEX = 'media_attachments_owner_asset_unique';

    public function __construct(
        private readonly RemoteImageFetcher $fetcher,
        private readonly MediaUploadService $uploads,
        private readonly MediaAttachmentService $attachments,
        private readonly EnrichmentImagePolicy $policy,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Persist the given image descriptors for a product.
     *
     * @param  mixed  $images  Untrusted `images[]` payload (normalized internally).
     */
    public function persist(string $productId, string $tenantId, mixed $images, ?string $userId): EnrichmentImagePersistOutcome
    {
        $outcome = new EnrichmentImagePersistOutcome;
        $descriptors = ImageDescriptorNormalizer::normalize($images, self::CAP);
        $runAssignedPrimary = false;
        $sort = 0;

        foreach ($descriptors as $descriptor) {
            $url = $descriptor['url'] ?? $descriptor['thumbnail'];
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            try {
                // 1. Get-or-create by source_ref: reuse an existing asset verbatim.
                $existing = $this->findBySourceRef($tenantId, $url);
                if ($existing !== null) {
                    $this->attachExistingUnderLock($existing->id, $productId, $tenantId, $runAssignedPrimary, $sort);
                    $outcome->reused++;
                    $sort++;

                    continue;
                }

                // 2. Download (SSRF-safe) and materialize an UploadedFile.
                $fetched = $this->fetcher->fetch($url);

                try {
                    $asset = null;

                    try {
                        $file = new UploadedFile($fetched->tempPath, $fetched->filename, $fetched->mime, null, true);
                        $asset = $this->uploads->uploadForProduct($tenantId, $productId, $file, $userId, $url);
                    } catch (QueryException $e) {
                        // Only a source_ref unique violation means "another worker won the race".
                        if (! $this->isConstraintViolation($e, self::SOURCE_REF_INDEX, ['source_ref'])) {
                            throw $e;
                        }

                        $winner = $this->findBySourceRef($tenantId, $url);
                        if ($winner === null) {
                            throw $e; // constraint fired but the row is gone — do not swallow.
                        }

                        $this->attachExistingUnderLock($winner->id, $productId, $tenantId, $runAssignedPrimary, $sort);
                        $outcome->reused++;
                        $sort++;

                        continue;
                    }

                    // 3-4. Checksum backstop + attach, serialized per product.
                    $createdAssetId = $asset->id;

                    try {
                        $attached = $this->finalizeCreatedAssetUnderLock(
                            $asset,
                            $productId,
                            $tenantId,
                            $runAssignedPrimary,
                            $sort,
                        );
                    } catch (Throwable $e) {
                        // Attach/backstop failed for a just-created asset → clean up the orphan.
                        $this->safeCleanupOrphan($createdAssetId, $tenantId);

                        throw $e;
                    }

                    if ($attached) {
                        $outcome->attached++;
                        $sort++;
                    } else {
                        $outcome->skipped++;
                    }
                } finally {
                    // 6. Always drop the temp file, on every path.
                    @unlink($fetched->tempPath);
                }
            } catch (Throwable $e) {
                // 5. One bad descriptor never aborts the rest of the run.
                $this->logger->warning('Enrichment image persist failed for descriptor', [
                    'product_id' => $productId,
                    'tenant_id' => $tenantId,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $outcome->failed++;
            }
        }

        return $outcome;
    }

    /**
     * Attach an already-existing asset under the per-product lock, updating the
     * run's "assigned primary" flag so at most one PRIMARY is claimed per run.
     */
    private function attachExistingUnderLock(
        string $assetId,
        string $productId,
        string $tenantId,
        bool &$runAssignedPrimary,
        int $sort,
    ): void {
        $localRunAssigned = $runAssignedPrimary;

        $assignedPrimary = $this->lockProduct($tenantId, $productId, function () use ($assetId, $productId, $tenantId, $localRunAssigned, $sort): bool {
            $role = $this->policy->roleFor($productId, $tenantId, $localRunAssigned);
            $this->attachIdempotent($assetId, $productId, $tenantId, $role, $sort);

            return $role === MediaRole::Primary;
        });

        if ($assignedPrimary) {
            $runAssignedPrimary = true;
        }
    }

    /**
     * Under the per-product lock: run the checksum backstop for a freshly-created
     * asset (hard-deleting it as an orphan when a duplicate is found), otherwise
     * attach it with the policy-decided role.
     *
     * @return bool True if the asset was attached; false if skipped as a duplicate.
     */
    private function finalizeCreatedAssetUnderLock(
        MediaAsset $asset,
        string $productId,
        string $tenantId,
        bool &$runAssignedPrimary,
        int $sort,
    ): bool {
        $localRunAssigned = $runAssignedPrimary;

        /** @var array{attached: bool, primary: bool} $result */
        $result = $this->lockProduct($tenantId, $productId, function () use ($asset, $productId, $tenantId, $localRunAssigned, $sort): array {
            if ($this->isChecksumDuplicate($asset, $productId, $tenantId)) {
                $this->attachments->hardDeleteOrphan($asset->id, $tenantId);

                return ['attached' => false, 'primary' => false];
            }

            $role = $this->policy->roleFor($productId, $tenantId, $localRunAssigned);
            $this->attachIdempotent($asset->id, $productId, $tenantId, $role, $sort);

            return ['attached' => true, 'primary' => $role === MediaRole::Primary];
        });

        if ($result['primary']) {
            $runAssignedPrimary = true;
        }

        return $result['attached'];
    }

    /**
     * Serialize a closure per (tenant, product) via a DB-agnostic cache lock.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function lockProduct(string $tenantId, string $productId, \Closure $callback): mixed
    {
        return Cache::lock("enrich-img:{$tenantId}:{$productId}", self::LOCK_TTL)
            ->block(self::LOCK_WAIT, $callback);
    }

    private function findBySourceRef(string $tenantId, string $url): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('source_ref', $url)
            ->first();
    }

    /**
     * Attach an asset to the product, treating ONLY the owner_asset unique
     * violation as an idempotent no-op. Any other QueryException is rethrown.
     */
    private function attachIdempotent(string $assetId, string $productId, string $tenantId, MediaRole $role, int $sort): void
    {
        try {
            $this->attachments->attach($assetId, MediaOwnerType::Product, $productId, $role, $sort, $tenantId);
        } catch (QueryException $e) {
            if (! $this->isConstraintViolation($e, self::ATTACH_INDEX, ['owner_id', 'media_asset_id'])) {
                throw $e;
            }
            // Already attached to this owner — idempotent no-op.
        }
    }

    /**
     * True if the freshly-created asset's bytes (checksum) already belong to a
     * non-deleted, non-failed asset attached to THIS product under another row.
     */
    private function isChecksumDuplicate(MediaAsset $asset, string $productId, string $tenantId): bool
    {
        if ($asset->checksum === null || $asset->checksum === '') {
            return false;
        }

        return MediaAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('checksum', $asset->checksum)
            ->where('id', '!=', $asset->id)
            ->whereIn('status', [
                MediaStatus::Uploaded->value,
                MediaStatus::Processing->value,
                MediaStatus::Ready->value,
            ])
            ->whereHas('attachments', static function (Builder $query) use ($productId): void {
                /** @var Builder<MediaAttachment> $query */
                $query->where('owner_id', $productId);
            })
            ->exists();
    }

    /**
     * Best-effort orphan cleanup — never let a cleanup failure mask the original error.
     */
    private function safeCleanupOrphan(string $assetId, string $tenantId): void
    {
        try {
            $this->attachments->hardDeleteOrphan($assetId, $tenantId);
        } catch (Throwable $e) {
            $this->logger->warning('Enrichment orphan cleanup failed', [
                'asset_id' => $assetId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decide whether a QueryException is the specific unique violation we expect.
     *
     * Pgsql: SQLSTATE 23505. SQLite: "UNIQUE constraint failed". In both cases the
     * message must also name the target index OR every one of the relevant columns,
     * so an unrelated unique violation is never mistaken for our idempotency race.
     *
     * @param  array<int, string>  $columns
     */
    private function isConstraintViolation(QueryException $e, string $indexName, array $columns): bool
    {
        $message = $e->getMessage();

        $isPgUnique = $e->getCode() === '23505';
        $isSqliteUnique = str_contains($message, 'UNIQUE constraint failed');

        if (! $isPgUnique && ! $isSqliteUnique) {
            return false;
        }

        if (str_contains($message, $indexName)) {
            return true;
        }

        foreach ($columns as $column) {
            if (! str_contains($message, $column)) {
                return false;
            }
        }

        return true;
    }
}
