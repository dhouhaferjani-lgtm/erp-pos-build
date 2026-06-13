<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Application\Services\MediaAttachmentService;
use App\Modules\Catalog\Application\Services\MediaUploadService;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Seeder;

/**
 * Assigns placeholder images to all products that don't have a primary media attachment.
 *
 * Uses picsum.photos for realistic placeholder images — each product gets a
 * deterministic image seeded by its UUID, so re-running produces the same images.
 *
 * Usage: php artisan db:seed --class=ProductImagePlaceholderSeeder
 */
class ProductImagePlaceholderSeeder extends Seeder
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
        private readonly MediaAttachmentService $attachmentService,
    ) {}

    public function run(): void
    {
        // Get products that already have a PRIMARY MediaAttachment
        $productsWithPrimary = MediaAttachment::where('owner_type', MediaOwnerType::Product)
            ->where('role', MediaRole::Primary)
            ->pluck('owner_id')
            ->all();

        // Get all products without a primary media attachment
        $products = Product::query()
            ->whereNotIn('id', $productsWithPrimary)
            ->select(['id', 'tenant_id', 'name'])
            ->get();

        if ($products->isEmpty()) {
            $this->command->info('All products already have primary images.');

            return;
        }

        $this->command->info("Assigning placeholder images to {$products->count()} products...");

        $bar = $this->command->getOutput()->createProgressBar($products->count());

        foreach ($products as $product) {
            // Use a short seed derived from the product UUID for deterministic images
            $seed = substr(md5($product->id), 0, 8);
            $url = "https://picsum.photos/seed/{$seed}/400/400";

            $asset = $this->uploadService->registerExternalUrl(
                $product->tenant_id,
                $product->id,
                $url,
            );

            $this->attachmentService->attach(
                $asset->id,
                MediaOwnerType::Product,
                $product->id,
                MediaRole::Primary,
                0,
                $product->tenant_id,
            );

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Done! {$products->count()} products now have placeholder images.");
    }
}
