<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Modules\Product\Application\Services\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @cross-tenant-by-design Pure storage-transformation queue job — generates WebP variants from a tenant-anchored storage path; handle() has ZERO DB queries, only reads the original via Storage::disk($this->storageDisk) and writes derived variants to ImageVariantService::variantPath()-derived paths. Tenant isolation is anchored upstream by the storage path's tenant-scoped structure (the dispatcher is responsible for handing in a tenant-anchored path; the job has no DB to leak through). Dispatched both from request-scoped ProductImageService.php:82 and from the cat-(b) GenerateProductImageVariants console command (api.console-commands cluster); both dispatchers anchor on the ProductImage row's storage_path which carries the tenant prefix.
 */
final class GenerateImageVariants implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $imageId,
        public readonly string $storagePath,
        public readonly string $storageDisk,
    ) {
        $this->onQueue('images');
    }

    public function handle(ImageVariantService $variantService): void
    {
        $disk = Storage::disk($this->storageDisk);

        if (! $disk->exists($this->storagePath)) {
            Log::warning('GenerateImageVariants: original not found', [
                'image_id' => $this->imageId,
                'path' => $this->storagePath,
            ]);

            return;
        }

        /** @var string $originalData */
        $originalData = $disk->get($this->storagePath);

        $generated = 0;
        foreach (ImageVariantService::VARIANTS as $variant => $maxWidth) {
            $variantPath = ImageVariantService::variantPath($this->storagePath, $variant);

            try {
                $webpData = $variantService->resizeAndEncode($originalData, $maxWidth);
                $disk->put($variantPath, $webpData);
                $generated++;
            } catch (\Throwable $e) {
                Log::error('GenerateImageVariants: variant failed', [
                    'image_id' => $this->imageId,
                    'variant' => $variant,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('GenerateImageVariants: completed', [
            'image_id' => $this->imageId,
            'generated' => $generated,
            'total' => count(ImageVariantService::VARIANTS),
        ]);
    }
}
