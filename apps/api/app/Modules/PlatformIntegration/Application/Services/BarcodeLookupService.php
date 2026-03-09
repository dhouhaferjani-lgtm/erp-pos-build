<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformArticle;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class BarcodeLookupService
{
    private const CACHE_PREFIX = 'platform:barcode:';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
    ) {}

    public function lookup(string $barcode): BarcodeLookupResultData
    {
        $normalizedBarcode = $this->normalizeBarcode($barcode);

        // Check cache
        $cached = Cache::get(self::CACHE_PREFIX . $normalizedBarcode);
        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return BarcodeLookupResultData::found(
                $normalizedBarcode,
                $cached['article'],
                $cached['suggested_product'],
            );
        }

        // Check circuit breaker
        if ($this->platformClient->isCircuitOpen()) {
            return BarcodeLookupResultData::error($normalizedBarcode, 'platform_unavailable');
        }

        try {
            $response = $this->platformClient->get("/api/v1/automotive/articles/barcode/{$normalizedBarcode}");

            if ($response === null) {
                return BarcodeLookupResultData::notFound($normalizedBarcode);
            }

            $article = PlatformArticle::fromApiResponse($response);
            $articleArray = $this->articleToArray($article);
            $suggestedProduct = $this->buildSuggestedProduct($article);

            // Cache the result
            Cache::put(self::CACHE_PREFIX . $normalizedBarcode, [
                'article' => $articleArray,
                'suggested_product' => $suggestedProduct,
            ], self::CACHE_TTL_SECONDS);

            return BarcodeLookupResultData::found($normalizedBarcode, $articleArray, $suggestedProduct);
        } catch (\Throwable $e) {
            Log::warning('Platform barcode lookup failed', [
                'barcode' => $normalizedBarcode,
                'error' => $e->getMessage(),
            ]);

            return BarcodeLookupResultData::error($normalizedBarcode, 'platform_error');
        }
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);

        // UPC-A to EAN-13 conversion (12 digits → prepend 0)
        if (preg_match('/^\d{12}$/', $barcode) === 1) {
            $barcode = '0' . $barcode;
        }

        return $barcode;
    }

    /**
     * @return array<string, mixed>
     */
    private function articleToArray(PlatformArticle $article): array
    {
        return [
            'id' => $article->id,
            'article_number' => $article->articleNumber,
            'name' => $article->articleName,
            'supplier_brand' => $article->supplierBrand,
            'product_group_name' => $article->productGroupName,
            'barcode' => $article->barcode,
            'brand_quality_tier' => $article->brandQualityTier,
            'weight_kg' => $article->weightKg,
            'dimensions' => $article->dimensions,
            'cross_references' => $article->crossReferences,
            'vehicle_linkages' => $article->vehicleLinkages,
            'criteria' => $article->criteria,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSuggestedProduct(PlatformArticle $article): array
    {
        return [
            'name' => $article->articleName,
            'sku' => $article->supplierBrand . '-' . $article->articleNumber,
            'barcode' => $article->barcode,
            'automotive_metadata' => [
                'platform_article_id' => $article->id,
                'platform_link_status' => 'linked',
                'article_number' => $article->articleNumber,
                'supplier_brand' => $article->supplierBrand,
                'product_group_name' => $article->productGroupName,
                'brand_quality_tier' => $article->brandQualityTier,
                'weight_kg' => $article->weightKg,
                'dimensions' => $article->dimensions,
                'data_source' => 'platform',
                'cross_references' => $article->crossReferences,
                'vehicles' => $article->vehicleLinkages,
                'criteria' => $article->criteria,
            ],
        ];
    }
}
