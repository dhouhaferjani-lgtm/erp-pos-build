<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Console;

use App\Modules\Company\Presentation\Console\BackfillPosStockPolicyCommand;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;

/**
 * Dispatches fresh catalog enrichment (Path-A, including image persistence)
 * across a tenant's eligible products.
 *
 * Eligible = `platform_product_id` AND `barcode` both non-null. The vertical
 * passed to every job is the tenant's own vertical unless `--vertical`
 * overrides it (`vertical` is a tenant column, not a product column).
 *
 * Follows the same conditional tenancy-init pattern as
 * {@see BackfillPosStockPolicyCommand}:
 * `tenancy()->initialize()` only runs under db-per-tenant mode
 * (`config('tenancy_resolver.db_per_tenant')`). In the current single-database
 * reality (and in the test suite, which forces `TENANCY_DB_PER_TENANT=false`),
 * the query is scoped by `tenant_id` on the shared connection instead.
 */
final class RunEnrichmentCommand extends Command
{
    protected $signature = 'enrichment:run
                            {tenant : UUID of the tenant to run enrichment for}
                            {--vertical= : Override the tenant vertical passed to the enrichment job}
                            {--limit= : Maximum number of products to dispatch}
                            {--only-missing-images : Skip products that already have a Primary media attachment}';

    protected $description = 'Dispatch fresh catalog enrichment (incl. image persistence) for a tenant\'s eligible products.';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $tenant = Tenant::find($tenantId);

        if (! $tenant instanceof Tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        $vertical = $this->stringOption('vertical') ?? $tenant->vertical->value;
        $limit = $this->stringOption('limit');
        $onlyMissingImages = (bool) $this->option('only-missing-images');
        $dbPerTenant = (bool) config('tenancy_resolver.db_per_tenant', false);

        $dispatched = 0;
        $skipped = 0;
        $ineligible = 0;
        $initialized = false;

        try {
            if ($dbPerTenant) {
                tenancy()->initialize($tenant);
                $initialized = true;
            }

            $query = Product::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('platform_product_id')
                ->whereNotNull('barcode');

            if ($limit !== null) {
                $query->limit((int) $limit);
            }

            foreach ($query->cursor() as $product) {
                /** @var Product $product */
                if ($product->platform_product_id === null || $product->barcode === null) {
                    // Defense-in-depth: the whereNotNull() filters above already
                    // exclude these, but the columns remain nullable on the model,
                    // so narrow explicitly before handing strings to the job.
                    $ineligible++;

                    continue;
                }

                if ($onlyMissingImages && $this->hasPrimaryImage($product->id, $tenant->id)) {
                    $skipped++;

                    continue;
                }

                ApplyCatalogEnrichmentJob::dispatch(
                    $product->id,
                    $product->platform_product_id,
                    $product->barcode,
                    $vertical,
                    $product->tenant_id,
                )->onQueue('enrichment');

                $dispatched++;
            }
        } finally {
            if ($initialized && tenancy()->initialized) {
                tenancy()->end();
            }
        }

        $this->info("Dispatched {$dispatched} enrichment job(s); {$skipped} skipped (already have images); {$ineligible} ineligible.");

        return self::SUCCESS;
    }

    private function hasPrimaryImage(string $productId, string $tenantId): bool
    {
        return MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productId)
            ->where('role', MediaRole::Primary)
            ->exists();
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
