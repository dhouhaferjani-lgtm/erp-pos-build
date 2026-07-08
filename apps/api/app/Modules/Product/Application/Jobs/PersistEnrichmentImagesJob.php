<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Media\Application\Jobs\GenerateRenditions;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper both enrichment paths (barcode-lookup apply + manual review
 * accept) dispatch to persist enrichment images for a product.
 *
 * Tenant-isolation (api.scheduled-jobs): the constructor carries `tenantId` so
 * the queue worker can rebind tenant context via
 * {@see BindsTenantContext::withTenantContext()} BEFORE any DB access —
 * mirrors {@see GenerateRenditions}.
 * {@see EnrichmentImagePersister} runs with NO CompanyContext bound (per its
 * own doc block); this job is the only caller responsible for establishing
 * the tenant schema the persister's queries run against.
 */
final class PersistEnrichmentImagesJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<int, array{url: string|null, thumbnail: string|null, type: string|null}>  $images
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly array $images,
        public readonly ?string $userId = null,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(EnrichmentImagePersister $persister): void
    {
        $this->withTenantContext(function () use ($persister): void {
            $persister->persist($this->productId, $this->tenantId, $this->images, $this->userId);
        });
    }
}
