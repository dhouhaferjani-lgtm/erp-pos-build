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
