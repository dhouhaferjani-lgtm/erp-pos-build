<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Application\Services\ImageVariantService;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateProductImageVariants extends Command
{
    protected $signature = 'products:generate-image-variants
        {--force : Regenerate even if variants already exist}
        {--product= : Process only images for a specific product ID}';

    protected $description = 'Generate WebP thumbnail variants for existing product images';

    public function handle(): int
    {
        $query = ProductImage::query();

        if ($this->option('product')) {
            $query->where('product_id', $this->option('product'));
        }

        $total = $query->count();
        $dispatched = 0;
        $skipped = 0;

        $this->info("Processing {$total} product images...");

        $query->chunkById(100, function ($images) use (&$dispatched, &$skipped): void {
            /** @var ProductImage $image */
            foreach ($images as $image) {
                // Skip external URL images (seeded placeholders) — only process S3 uploads
                if ($image->storage_disk === 'url') {
                    $skipped++;

                    continue;
                }

                if (! $this->option('force')) {
                    $smPath = ImageVariantService::variantPath($image->storage_path, 'sm');
                    if (Storage::disk($image->storage_disk)->exists($smPath)) {
                        $skipped++;

                        continue;
                    }
                }

                GenerateImageVariants::dispatch(
                    $image->id,
                    $image->storage_path,
                    $image->storage_disk,
                );
                $dispatched++;
            }
        });

        $this->info("Dispatched: {$dispatched} jobs. Skipped: {$skipped} (variants exist).");

        return self::SUCCESS;
    }
}
