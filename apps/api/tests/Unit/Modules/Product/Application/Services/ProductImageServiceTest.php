<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product\Application\Services;

use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\ProductImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageServiceTest extends TestCase
{
    private ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProductImageService;
    }

    public function test_serve_returns_inline_content_disposition(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('products/tenant/product/test.jpg', 'fake-image-content');

        /** @var ProductImage $image */
        $image = new ProductImage;
        $image->storage_disk = 's3';
        $image->storage_path = 'products/tenant/product/test.jpg';
        $image->original_filename = 'test.jpg';
        $image->mime_type = 'image/jpeg';

        $response = $this->service->serve($image);

        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($contentDisposition);
        $this->assertStringStartsWith('inline', $contentDisposition);
    }

    public function test_serve_includes_cache_headers(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('products/tenant/product/photo.png', 'fake-image-content');

        /** @var ProductImage $image */
        $image = new ProductImage;
        $image->storage_disk = 's3';
        $image->storage_path = 'products/tenant/product/photo.png';
        $image->original_filename = 'photo.png';
        $image->mime_type = 'image/png';

        $response = $this->service->serve($image);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
    }
}
