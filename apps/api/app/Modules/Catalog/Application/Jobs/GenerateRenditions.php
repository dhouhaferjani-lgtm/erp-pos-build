<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Catalog\Application\Services\RenditionService;
use App\Modules\Catalog\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Catalog\Domain\Enums\MediaSource;
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

            $assets->markProcessing($asset);

            try {
                $renditions->generate($asset);
                $assets->markReady($asset);
            } catch (\Throwable $e) {
                $assets->markFailed($asset);
                Log::error('GenerateRenditions failed', [
                    'asset' => $this->mediaAssetId,
                    'tenant' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }
}
