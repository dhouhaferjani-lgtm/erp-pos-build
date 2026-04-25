<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Assigns placeholder images to all products that don't have a primary image.
 *
 * Uses picsum.photos for realistic placeholder images — each product gets a
 * deterministic image seeded by its UUID, so re-running produces the same images.
 *
 * Usage: php artisan db:seed --class=ProductImagePlaceholderSeeder
 */
class ProductImagePlaceholderSeeder extends Seeder
{
    public function run(): void
    {
        // Get all products without a primary image
        $products = Product::query()
            ->whereDoesntHave('primaryImage')
            ->select(['id', 'tenant_id', 'name'])
            ->get();

        if ($products->isEmpty()) {
            $this->command->info('All products already have primary images.');

            return;
        }

        $this->command->info("Assigning placeholder images to {$products->count()} products...");

        $bar = $this->command->getOutput()->createProgressBar($products->count());

        $batch = [];
        $now = now()->toDateTimeString();

        foreach ($products as $product) {
            // Use a short seed derived from the product UUID for deterministic images
            $seed = substr(md5($product->id), 0, 8);

            $batch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'filename' => "placeholder-{$seed}.jpg",
                'original_filename' => "placeholder-{$seed}.jpg",
                'storage_path' => "https://picsum.photos/seed/{$seed}/400/400",
                'storage_disk' => 'url',
                'mime_type' => 'image/jpeg',
                'file_size' => 0,
                'width' => 400,
                'height' => 400,
                'sort_order' => 0,
                'is_primary' => true,
                'thumbnail_path' => "https://picsum.photos/seed/{$seed}/100/100",
                'uploaded_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 100) {
                ProductImage::insert($batch);
                $batch = [];
            }

            $bar->advance();
        }

        // Insert remaining
        if (! empty($batch)) {
            ProductImage::insert($batch);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Done! {$products->count()} products now have placeholder images.");
    }
}
