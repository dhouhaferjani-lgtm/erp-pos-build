<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\DTOs\CatalogProductDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class BarcodeLookupService implements CatalogLookupInterface
{
    private const CACHE_PREFIX = 'platform:lookup:';

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
        private readonly CompanyContext $companyContext,
    ) {}

    public function lookup(string $barcode, ?string $vertical = null): BarcodeLookupResultData
    {
        $normalizedBarcode = $this->normalizeBarcode($barcode);

        if ($normalizedBarcode === null) {
            return BarcodeLookupResultData::error($barcode, 'invalid_barcode');
        }

        // Resolve vertical from company context if not provided
        if ($vertical === null) {
            $vertical = $this->companyContext->requireCompany()->tenant->vertical->platformVertical();
        }

        if ($vertical === null) {
            return BarcodeLookupResultData::error($normalizedBarcode, 'vertical_not_supported');
        }

        $cacheKey = self::CACHE_PREFIX.$vertical.':'.$normalizedBarcode;

        // Check cache — stores BarcodeLookupResultData objects directly
        $cached = Cache::get($cacheKey);
        if ($cached instanceof BarcodeLookupResultData) {
            return $cached;
        }

        // Check circuit breaker
        if ($this->platformClient->isCircuitOpen()) {
            return BarcodeLookupResultData::error($normalizedBarcode, 'platform_unavailable');
        }

        try {
            $response = $this->platformClient->postRaw('/api/v1/products/lookup', [
                'barcode' => $normalizedBarcode,
                'vertical' => $vertical,
            ]);

            if ($response === null) {
                return BarcodeLookupResultData::notFound($normalizedBarcode);
            }

            $status = $response['status'] ?? 'not_found';

            if ($status === 'found' && isset($response['product'])) {
                /** @var array<string, mixed> $productData */
                $productData = $response['product'];
                $product = PlatformProductData::fromApiResponse($productData);
                $result = BarcodeLookupResultData::found($normalizedBarcode, $product);

                Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

                return $result;
            }

            $trackingId = $response['tracking_id'] ?? null;
            $result = BarcodeLookupResultData::notFound($normalizedBarcode, $trackingId);

            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Platform product lookup failed', [
                'barcode' => $normalizedBarcode,
                'vertical' => $vertical,
                'error' => $e->getMessage(),
            ]);

            return BarcodeLookupResultData::error($normalizedBarcode, 'platform_error');
        }
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        $result = $this->lookup($barcode, $vertical);

        if ($result->status !== 'found' || $result->product === null) {
            return null;
        }

        $product = $result->product;

        return new CatalogProductDTO(
            platformProductId: $product->id,
            barcode: $product->barcode,
            name: $product->name,
            brand: $product->brand,
            description: $product->description,
            classification: $product->classification,
            ingredients: $product->ingredients,
            images: $product->images,
            confidenceScore: $product->confidenceScore,
            enrichmentTier: $product->enrichmentTier,
        );
    }

    /**
     * Normalize barcode: trim, strip non-alphanumeric, UPC-12 to EAN-13, validate EAN-13 check digit.
     */
    private function normalizeBarcode(string $barcode): ?string
    {
        $barcode = trim($barcode);

        // Strip non-alphanumeric characters
        $barcode = (string) preg_replace('/[^a-zA-Z0-9]/', '', $barcode);

        if ($barcode === '') {
            return null;
        }

        // UPC-12 to EAN-13 conversion (12 digits -> prepend 0)
        if (preg_match('/^\d{12}$/', $barcode) === 1) {
            $barcode = '0'.$barcode;
        }

        // EAN-13 check digit validation
        if (preg_match('/^\d{13}$/', $barcode) === 1) {
            if (! $this->isValidEan13($barcode)) {
                return null;
            }
        }

        return $barcode;
    }

    /**
     * Validate EAN-13 check digit.
     * Algorithm: sum digits with alternating weights 1 and 3, check digit = (10 - sum%10) % 10
     */
    private function isValidEan13(string $ean): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean[$i];
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $expectedCheckDigit = (10 - ($sum % 10)) % 10;

        return $expectedCheckDigit === (int) $ean[12];
    }
}
