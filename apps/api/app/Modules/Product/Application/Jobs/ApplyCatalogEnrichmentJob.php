<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Services\CatalogEnrichmentService;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CatalogLookupInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * @cross-tenant-by-design Catalog apply queue job runs inside the tenant database context restored by QueueTenancyBootstrapper; it carries tenant-scoped product identifiers and never enumerates tenants.
 */
final class ApplyCatalogEnrichmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Transient platform outages rethrow PlatformCatalogUnavailableException;
     * the queue retries with backoff and the backlink is left untouched.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $productId,
        public readonly string $expectedPlatformProductId,
        public readonly string $barcode,
        public readonly string $vertical,
    ) {}

    public function handle(CompanyContext $companyContext, CatalogLookupInterface $lookup, CatalogEnrichmentService $enricher): void
    {
        $product = Product::query()->find($this->productId);

        if ($product === null) {
            return;
        }

        // Queue workers bind no CompanyContext, but the lookup's outbound
        // platform call requires one (PlatformHttpClient::tenantHeaders()).
        // Bind it from the product being enriched and clear it so nothing
        // leaks into the next job on this worker.
        $companyContext->setCompanyId($product->company_id);

        try {
            $catalog = $lookup->lookupCatalogProduct($this->barcode, $this->vertical);

            if ($catalog === null) {
                $product->update(['platform_product_id' => null]);

                Log::info('Cleared platform backlink: catalog lookup returned a genuine miss', [
                    'product_id' => $this->productId,
                    'expected_platform_product_id' => $this->expectedPlatformProductId,
                ]);

                return;
            }

            if ($catalog->platformProductId !== $this->expectedPlatformProductId) {
                $product->update(['platform_product_id' => null]);

                Log::warning('Skipping catalog enrichment apply due to platform product mismatch', [
                    'product_id' => $this->productId,
                    'expected_platform_product_id' => $this->expectedPlatformProductId,
                    'actual_platform_product_id' => $catalog->platformProductId,
                ]);

                return;
            }

            $enricher->applyCatalogHit($product, $catalog);
        } finally {
            $companyContext->clear();
        }
    }
}
