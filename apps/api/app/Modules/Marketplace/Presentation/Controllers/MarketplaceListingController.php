<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Presentation\Controllers;

use App\Modules\Marketplace\Application\DTOs\MarketplaceListingData;
use App\Modules\Marketplace\Application\Services\MarketplaceSearchService;
use App\Modules\Marketplace\Application\Services\PriceComparisonService;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MarketplaceListingController extends Controller
{
    public function __construct(
        private readonly MarketplaceSearchService $searchService,
        private readonly PriceComparisonService $priceComparisonService,
    ) {}

    /**
     * GET /api/v1/marketplace/listings
     *
     * Search marketplace listings by article identifiers.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'article_number' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string'],
            'platform_article_id' => ['nullable', 'string', 'uuid'],
        ]);

        $listings = $this->searchService->findListings(
            countryCode: $request->string('country')->toString(),
            platformArticleId: $request->string('platform_article_id')->toString() ?: null,
            articleNumber: $request->string('article_number')->toString() ?: null,
            barcode: $request->string('barcode')->toString() ?: null,
        );

        return response()->json(['data' => $listings]);
    }

    /**
     * GET /api/v1/marketplace/listings/{id}
     */
    public function show(string $id): JsonResponse
    {
        $listing = MarketplaceListing::findOrFail($id);

        return response()->json([
            'data' => MarketplaceListingData::fromModel($listing),
        ]);
    }

    /**
     * GET /api/v1/marketplace/price-comparison
     */
    public function priceComparison(Request $request): JsonResponse
    {
        $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'current_price' => ['required', 'numeric', 'min:0'],
            'article_number' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string'],
        ]);

        $result = $this->priceComparisonService->findBetterPrices(
            countryCode: $request->string('country')->toString(),
            articleNumber: $request->string('article_number')->toString() ?: null,
            barcode: $request->string('barcode')->toString() ?: null,
            currentPrice: $request->string('current_price')->toString(),
        );

        if ($result === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $result]);
    }
}
