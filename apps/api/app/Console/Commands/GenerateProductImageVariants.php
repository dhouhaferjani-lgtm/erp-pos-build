<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Media\Application\Jobs\GenerateRenditions;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The annotation said the batch "walks MediaAsset rows fleet-wide".
 * `media_assets` is a TENANT table, so after the 2026-05-28
 * database-per-tenant flip the walk raised 42P01 on the console's CENTRAL
 * connection and dispatched nothing.
 *
 * The scope must be named: `--tenant=<uuid>` or `--all-tenants`. The dispatched
 * `GenerateRenditions` job still carries the asset's own `tenant_id` and
 * rebinds tenant context in the worker — that was already correct and is
 * unchanged.
 */
final class GenerateProductImageVariants extends TenantScopedCommand
{
    protected $signature = 'products:generate-image-variants
        {--tenant= : Tenant UUID to process (required unless --all-tenants)}
        {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
        {--force : Regenerate even if renditions already exist}
        {--product= : Process only images for a specific product ID}';

    protected $description = 'Generate WebP thumbnail variants for existing product images (per tenant)';

    public function __construct(CompanyContext $companyContext)
    {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $force = $this->option('force') === true;
        $dispatched = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use ($force, &$dispatched): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode.
                $query = MediaAsset::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('source', MediaSource::Upload)
                    ->where('type', MediaAssetType::Image);

                if (! $force) {
                    // Without --force, skip assets that already have renditions (READY status).
                    $query->where('status', '!=', MediaStatus::Ready);
                }

                $total = $query->count();
                $this->info(sprintf(
                    'TENANT %s (%s): processing %d media asset(s)...',
                    $tenant->id,
                    $tenant->slug,
                    $total,
                ));

                $query->chunkById(100, function ($assets) use (&$dispatched): void {
                    /** @var MediaAsset $asset */
                    foreach ($assets as $asset) {
                        GenerateRenditions::dispatch($asset->tenant_id, $asset->id);
                        $dispatched++;
                    }
                });

                return self::SUCCESS;
            },
        );

        $this->info("Dispatched: {$dispatched} jobs.");

        return $exit;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
