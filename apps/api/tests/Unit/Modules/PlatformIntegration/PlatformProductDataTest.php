<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use PHPUnit\Framework\TestCase;

class PlatformProductDataTest extends TestCase
{
    public function test_from_api_response_maps_all_fields(): void
    {
        $apiResponse = [
            'id' => 'prod-uuid-123',
            'barcode' => '5901234123457',
            'name' => 'Avène Cleanance Gel',
            'brand' => 'Avène',
            'description' => 'Purifying cleansing gel',
            'classification' => ['category' => 'facial_cleanser', 'subcategory' => 'gel'],
            'ingredients' => [
                ['name' => 'Aqua', 'position' => 1],
                ['name' => 'Zinc Gluconate', 'position' => 2],
            ],
            'images' => [
                ['url' => 'https://cdn.test/img.jpg', 'thumbnail' => 'https://cdn.test/thumb.jpg', 'type' => 'front'],
            ],
            'confidence_score' => 85,
            'enrichment_tier' => 'high',
        ];

        $product = PlatformProductData::fromApiResponse($apiResponse);

        $this->assertSame('prod-uuid-123', $product->id);
        $this->assertSame('5901234123457', $product->barcode);
        $this->assertSame('Avène Cleanance Gel', $product->name);
        $this->assertSame('Avène', $product->brand);
        $this->assertSame('Purifying cleansing gel', $product->description);
        $this->assertSame('facial_cleanser', $product->classification['category']);
        $this->assertCount(2, $product->ingredients);
        $this->assertCount(1, $product->images);
        $this->assertSame(85, $product->confidenceScore);
        $this->assertSame('high', $product->enrichmentTier);
    }

    public function test_from_api_response_handles_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 'prod-uuid-456',
            'barcode' => '1234567890123',
            'name' => 'Unknown Product',
        ];

        $product = PlatformProductData::fromApiResponse($apiResponse);

        $this->assertSame('prod-uuid-456', $product->id);
        $this->assertNull($product->brand);
        $this->assertNull($product->description);
        $this->assertSame([], $product->classification);
        $this->assertSame([], $product->ingredients);
        $this->assertSame([], $product->images);
        $this->assertSame(0, $product->confidenceScore);
        $this->assertNull($product->enrichmentTier);
    }
}
