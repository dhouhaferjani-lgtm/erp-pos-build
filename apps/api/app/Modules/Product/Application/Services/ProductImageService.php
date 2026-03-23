<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Jobs\GenerateImageVariants;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageService
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    public const MAX_IMAGES_PER_PRODUCT = 10;

    /**
     * @var array<int, string>
     */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Upload a new image for a product.
     */
    public function upload(Product $product, UploadedFile $file, ?int $sortOrder = null): ProductImage
    {
        $this->validateFile($file);
        $this->enforceImageLimit($product);

        return DB::transaction(function () use ($product, $file, $sortOrder) {
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid().'.'.$extension;

            // Storage path: products/{tenant_id}/{product_id}/{filename}
            $storagePath = sprintf(
                'products/%s/%s/%s',
                $product->tenant_id,
                $product->id,
                $filename
            );

            // Upload to S3 (MinIO)
            $disk = Storage::disk('s3');
            $disk->putFileAs(
                dirname($storagePath),
                $file,
                basename($filename),
                'private' // Default private
            );

            // Get image dimensions
            [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];

            // Create record
            $image = ProductImage::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $storagePath,
                'storage_disk' => 's3',
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'sort_order' => $sortOrder ?? $this->getNextSortOrder($product),
                'is_primary' => $product->images()->count() === 0, // First image is primary
                'uploaded_by' => auth()->id(),
            ]);

            // Dispatch async WebP variant generation
            GenerateImageVariants::dispatch(
                $image->id,
                $image->storage_path,
                $image->storage_disk,
            );

            return $image;
        });
    }

    /**
     * Set an image as the primary image for its product.
     */
    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            // Remove primary flag from other images
            ProductImage::where('product_id', $image->product_id)
                ->where('id', '!=', $image->id)
                ->update(['is_primary' => false]);

            // Set this image as primary
            $image->update(['is_primary' => true]);
        });
    }

    /**
     * Reorder images for a product.
     *
     * @param  array<int, string>  $imageIds
     */
    public function reorder(Product $product, array $imageIds): void
    {
        DB::transaction(function () use ($product, $imageIds) {
            foreach ($imageIds as $index => $imageId) {
                ProductImage::where('product_id', $product->id)
                    ->where('id', $imageId)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * Delete an image.
     */
    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            // Delete from storage
            $disk = Storage::disk($image->storage_disk);
            $disk->delete($image->storage_path);

            // Delete WebP variants
            $variantPaths = ImageVariantService::allVariantPaths($image->storage_path);
            foreach ($variantPaths as $variantPath) {
                $disk->delete($variantPath);
            }

            // If this was the primary image, set the next one as primary
            if ($image->is_primary) {
                $nextImage = ProductImage::where('product_id', $image->product_id)
                    ->where('id', '!=', $image->id)
                    ->ordered()
                    ->first();

                if ($nextImage) {
                    $nextImage->update(['is_primary' => true]);
                }
            }

            // Soft delete
            $image->delete();
        });
    }

    /**
     * Get a public temporary URL for an image (if product is active for e-commerce).
     */
    public function getPublicUrl(ProductImage $image, int $expirationMinutes = 15): ?string
    {
        if (! $image->canBeAccessedPublicly()) {
            return null;
        }

        return Storage::disk($image->storage_disk)
            ->temporaryUrl($image->storage_path, now()->addMinutes($expirationMinutes));
    }

    /**
     * Download an image file (attachment, triggers browser download).
     */
    public function download(ProductImage $image): StreamedResponse
    {
        $disk = Storage::disk($image->storage_disk);

        return $disk->download($image->storage_path, $image->original_filename);
    }

    /**
     * Serve an image inline for browser rendering (e.g., <img src>).
     * Sets Content-Disposition: inline and Cache-Control headers so browsers
     * render the image rather than triggering a file download.
     */
    public function serve(ProductImage $image, ?string $variant = null): StreamedResponse
    {
        $disk = Storage::disk($image->storage_disk);
        $path = $image->storage_path;
        $mimeType = $image->mime_type;

        if ($variant !== null) {
            $variantPath = ImageVariantService::variantPath($image->storage_path, $variant);
            if ($disk->exists($variantPath)) {
                $path = $variantPath;
                $mimeType = 'image/webp';
            }
        }

        $response = $disk->response($path, $image->original_filename, [
            'Content-Type' => $mimeType,
        ]);

        $response->headers->set('Content-Disposition', 'inline; filename="'.$image->original_filename.'"');
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    /**
     * Validate uploaded file.
     */
    private function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File size exceeds maximum of 5MB');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, WebP, GIF');
        }
    }

    /**
     * Enforce image limit per product.
     */
    private function enforceImageLimit(Product $product): void
    {
        if ($product->images()->count() >= self::MAX_IMAGES_PER_PRODUCT) {
            throw new InvalidArgumentException('Maximum images per product exceeded (limit: 10)');
        }
    }

    /**
     * Get the next sort_order value for a product's images.
     */
    private function getNextSortOrder(Product $product): int
    {
        $maxOrder = $product->images()->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }
}
