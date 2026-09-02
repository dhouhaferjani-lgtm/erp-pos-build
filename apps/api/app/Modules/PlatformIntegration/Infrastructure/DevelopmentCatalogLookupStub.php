<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure;

use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\Contracts\CatalogLookupResultInterface;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\CatalogLookupOutcome;

/**
 * Opt-in local fixture for the import-enrichment e2e flow. Never bound outside
 * the local environment, and disabled there unless its dedicated flag is set.
 */
final class DevelopmentCatalogLookupStub implements CatalogLookupInterface
{
    private const FOUND_BARCODE = '5903407024073';

    private const PLATFORM_PRODUCT_ID = '11111111-1111-4111-8111-111111111111';

    public function __construct(private readonly BarcodeNormalizer $normalizer) {}

    public function normalizeBarcode(string $barcode): ?string
    {
        return $this->normalizer->normalize($barcode);
    }

    public function lookup(string $barcode, ?string $vertical = null): CatalogLookupResultInterface
    {
        $normalized = $this->normalizeBarcode($barcode);
        if ($normalized === null) {
            return new DevelopmentCatalogLookupResult(CatalogLookupOutcome::InvalidBarcode, null);
        }

        if ($normalized === self::FOUND_BARCODE) {
            return new DevelopmentCatalogLookupResult(CatalogLookupOutcome::Found, self::PLATFORM_PRODUCT_ID);
        }

        return new DevelopmentCatalogLookupResult(CatalogLookupOutcome::NotFound, null);
    }

    public function isCircuitOpen(): bool
    {
        return false;
    }

    public function lookupCatalogProduct(string $barcode, string $vertical): ?CatalogProductDTO
    {
        if ($this->normalizeBarcode($barcode) !== self::FOUND_BARCODE) {
            return null;
        }

        return new CatalogProductDTO(
            platformProductId: self::PLATFORM_PRODUCT_ID,
            barcode: self::FOUND_BARCODE,
            name: 'Local catalogue fixture',
            brand: 'Syneriva Local',
            description: 'Deterministic development-only catalogue match.',
            classification: [],
            ingredients: [],
            images: [],
            confidenceScore: 100,
            enrichmentTier: 'verified',
        );
    }
}
