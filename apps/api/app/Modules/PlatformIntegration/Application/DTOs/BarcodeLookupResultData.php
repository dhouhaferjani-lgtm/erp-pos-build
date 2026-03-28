<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class BarcodeLookupResultData extends Data
{
    /**
     * @param  array<string, mixed>|null  $suggestedProduct
     */
    public function __construct(
        public string $status,
        public ?string $barcode,
        public ?PlatformProductData $product,
        public ?string $trackingId,
        public ?array $suggestedProduct,
        public ?string $errorReason,
    ) {}

    public static function found(string $barcode, PlatformProductData $product): self
    {
        return new self(
            status: 'found',
            barcode: $barcode,
            product: $product,
            trackingId: null,
            suggestedProduct: self::buildSuggestedProduct($product),
            errorReason: null,
        );
    }

    public static function notFound(string $barcode, ?string $trackingId = null): self
    {
        return new self(
            status: 'not_found',
            barcode: $barcode,
            product: null,
            trackingId: $trackingId,
            suggestedProduct: null,
            errorReason: null,
        );
    }

    public static function error(string $barcode, string $reason): self
    {
        return new self(
            status: 'error',
            barcode: $barcode,
            product: null,
            trackingId: null,
            suggestedProduct: null,
            errorReason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildSuggestedProduct(PlatformProductData $product): array
    {
        return [
            'name' => $product->name,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'description' => $product->description,
            'platform_product_id' => $product->id,
            'classification' => $product->classification,
            'ingredients' => $product->ingredients,
            'images' => $product->images,
        ];
    }
}
