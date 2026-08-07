<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Media\Application\Services\RenditionService;
use App\Modules\Media\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Generates WebP renditions for an uploaded MediaAsset.
 *
 * Tenant-isolation (api.scheduled-jobs): the constructor carries `tenantId`
 * so the queue worker can rebind tenant context via
 * {@see BindsTenantContext::withTenantContext()} BEFORE any DB access.
 * The asset lookup additionally re-asserts `where('tenant_id', $this->tenantId)`
 * as defense-in-depth (via MediaAssetRepositoryInterface::find).
 *
 * External-URL assets are skipped: they reference a third-party URL and do
 * not have original bytes in our storage to derive renditions from.
 */
final class GenerateRenditions implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Maximum seconds the job may run (2 minutes — WebP encoding is fast).
     */
    public int $timeout = 120;

    /**
     * Seconds to wait before each retry: 10 s, then 60 s.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     *
     * `$tenantId` is REQUIRED by {@see BindsTenantContext} — it must survive
     * queue serialisation so the worker can rebind the tenant context.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $mediaAssetId,
    ) {
        $this->onQueue('images');
    }

    /**
     * Execute the job.
     */
    public function handle(RenditionService $renditions, MediaAssetRepositoryInterface $assets): void
    {
        $this->withTenantContext(function () use ($renditions, $assets): void {
            $asset = $assets->find($this->mediaAssetId, $this->tenantId);

            if ($asset === null || $asset->source === MediaSource::ExternalUrl) {
                return;
            }

            // Non-image assets have no rendition pipeline.  If this job was somehow
            // dispatched for a Document/Video/etc., mark it Ready (so it is visible in
            // read queries that filter on Ready) and return without generating renditions.
            if ($asset->type !== MediaAssetType::Image) {
                $assets->markReady($asset);

                return;
            }

            // BUG-005 / RCA A2 — this job ADDS renditions; it never gates
            // visibility. Assets are READY from upload (MediaUploadService) and
            // MediaStorageAdapter falls back to the original object whenever a
            // variant's rendition row is absent, so the bytes are serveable the
            // whole time this job runs.
            //
            // It therefore must NOT demote the asset: marking it PROCESSING would
            // 404 every read for the duration of encoding, and marking it FAILED
            // would hide a perfectly good original forever because a derived
            // thumbnail failed — the exact "worker trouble = no images ever"
            // failure mode this fix removes. Retries ($tries = 3) and the Horizon
            // failed-jobs record remain the observability channel.
            try {
                $renditions->generate($asset);
                // Promote any legacy row still sitting at UPLOADED/PROCESSING from
                // before assets were created READY. A no-op for new uploads.
                $assets->markReady($asset);
            } catch (\Throwable $e) {
                Log::error('GenerateRenditions failed — asset keeps serving its original bytes', [
                    'asset' => $this->mediaAssetId,
                    'tenant' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }
}
