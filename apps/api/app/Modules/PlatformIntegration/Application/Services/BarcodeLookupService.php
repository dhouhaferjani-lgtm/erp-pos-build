<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Product\Application\Services\BrandResolutionService;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Exceptions\PlatformCatalogUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class BarcodeLookupService implements CatalogLookupInterface
{
    private const CACHE_PREFIX = 'platform:lookup:v2:';

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
        private readonly CompanyContext $companyContext,
        private readonly BrandResolutionService $brandResolution,
        private readonly BarcodeNormalizer $barcodeNormalizer,
    ) {}

    public function lookup(string $barcode, ?string $vertical = null): BarcodeLookupResultData
    {
        $normalizedBarcode = $this->barcodeNormalizer->normalize($barcode);

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
            return $this->withLocalBrand($cached);
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

                return $this->withLocalBrand($result);
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

    private function withLocalBrand(BarcodeLookupResultData $result): BarcodeLookupResultData
    {
        $product = $result->product;

        if ($result->status !== 'found' || $product === null) {
            return $result;
        }

        if ($product->canonicalBrandId === null && $product->externalBrandId === null) {
            return $result;
        }

        if (! filled($product->brand)) {
            return $result;
        }

        try {
            $company = $this->companyContext->requireCompany();
            $resolution = $this->brandResolution->resolve(
                $company->tenant_id,
                (string) $product->brand,
                $product->canonicalBrandId,
                $product->externalBrandId,
            );
            $this->brandResolution->dispatchPushIfNeeded($resolution, $company->id);

            return BarcodeLookupResultData::found((string) $result->barcode, $product, $resolution->brand->id);
        } catch (\Throwable $e) {
            Log::warning('Brand resolution during lookup failed', [
                'barcode' => $result->barcode,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        $result = $this->lookup($barcode, $vertical);

        // A transient outage must never read as "not in the catalog" — callers
        // (e.g. ApplyCatalogEnrichmentJob) clear state on a genuine miss.
        if ($result->status === 'error'
            && in_array($result->errorReason, ['platform_unavailable', 'platform_error'], true)) {
            throw new PlatformCatalogUnavailableException($result->errorReason);
        }

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
}
