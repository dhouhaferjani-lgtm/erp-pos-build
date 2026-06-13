<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Application\Jobs\GenerateRenditions;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use Illuminate\Console\Command;

/**
 * @cross-tenant-by-design Maintenance batch that walks MediaAsset rows fleet-wide
 * (source=UPLOAD, type=IMAGE) to dispatch GenerateRenditions per asset.
 * The job carries an explicit tenantId so the worker rebinds tenant context
 * before any DB access.
 */
class GenerateProductImageVariants extends Command
{
    protected $signature = 'products:generate-image-variants
        {--force : Regenerate even if renditions already exist}
        {--product= : Process only images for a specific product ID}';

    protected $description = 'Generate WebP thumbnail variants for existing product images';

    public function handle(): int
    {
        $query = MediaAsset::query()
            ->where('source', MediaSource::Upload)
            ->where('type', MediaAssetType::Image);

        if ($this->option('force') !== true) {
            // Without --force, skip assets that already have renditions (READY status).
            $query->where('status', '!=', MediaStatus::Ready);
        }

        $total = $query->count();
        $dispatched = 0;

        $this->info("Processing {$total} media assets...");

        $query->chunkById(100, function ($assets) use (&$dispatched): void {
            /** @var MediaAsset $asset */
            foreach ($assets as $asset) {
                GenerateRenditions::dispatch($asset->tenant_id, $asset->id);
                $dispatched++;
            }
        });

        $this->info("Dispatched: {$dispatched} jobs.");

        return self::SUCCESS;
    }
}
