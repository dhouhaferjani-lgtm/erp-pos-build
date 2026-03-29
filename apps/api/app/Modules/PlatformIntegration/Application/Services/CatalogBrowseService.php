<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Shared\Contracts\ProductInventoryQueryInterface;
use Illuminate\Support\Facades\Cache;

final class CatalogBrowseService
{
    private const CACHE_PREFIX = 'platform:catalog:';

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
        private readonly ProductInventoryQueryInterface $productInventoryQuery,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManufacturers(?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        $scopeKey = $this->scopeKey('manufacturers', $verticalScope, $countryCode);

        return $this->cachedGet('/api/v1/automotive/manufacturers', $scopeKey, 86400, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getModelSeries(string $manufacturerId, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->cachedGet(
            "/api/v1/automotive/manufacturers/{$manufacturerId}/model-series",
            $this->scopeKey("model-series:{$manufacturerId}", $verticalScope, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicles(string $modelSeriesId, string $vehicleType = 'passenger-cars', ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $typeSegment = match ($vehicleType) {
            'pc' => 'passenger-cars',
            'cv' => 'commercial-vehicles',
            'mtb' => 'motorbikes',
            default => $vehicleType,
        };

        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->cachedGet(
            "/api/v1/automotive/model-series/{$modelSeriesId}/{$typeSegment}",
            $this->scopeKey("vehicles:{$modelSeriesId}:{$typeSegment}", $verticalScope, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicle(string $vehicleType, string $vehicleId, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams(null, $countryCode);

        return $this->cachedGet(
            "/api/v1/automotive/vehicles/{$vehicleType}/{$vehicleId}",
            $this->scopeKey("vehicle:{$vehicleType}:{$vehicleId}", null, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicleArticles(string $vehicleType, string $vehicleId, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->platformClient->get("/api/v1/automotive/vehicles/{$vehicleType}/{$vehicleId}/articles", $params);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>|null
     */
    public function searchArticles(array $params, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = array_merge($params, $this->buildBaseParams($verticalScope, $countryCode));

        return $this->platformClient->get('/api/v1/automotive/articles', $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArticle(string $articleId, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams(null, $countryCode);

        return $this->platformClient->get("/api/v1/automotive/articles/{$articleId}", $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArticleLinkages(string $articleId, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams(null, $countryCode);

        $data = $this->platformClient->get("/api/v1/automotive/articles/{$articleId}/linkages", $params);

        if ($data === null) {
            return null;
        }

        // Platform returns ArticleLink[] with nested vehicles
        // Frontend expects { vehicles: CompatibleVehicle[] }
        $links = $data['data'] ?? $data;
        $vehicles = [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            foreach ($link['vehicles'] ?? [] as $v) {
                $vehicles[] = [
                    'vehicle_type' => $v['vehicle_type'] ?? 'pc',
                    'vehicle_id' => $v['vehicle_id'] ?? '',
                    'display' => $v['display_string'] ?? '',
                    'fitment_confidence' => (float) ($v['confidence'] ?? 0),
                    'data_source' => $v['data_source'] ?? 'tecdoc',
                ];
            }
        }

        return ['vehicles' => $vehicles];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>|null
     */
    public function searchByCriteria(array $criteria, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $baseParams = $this->buildBaseParams($verticalScope, $countryCode);
        $payload = array_merge($criteria, $baseParams);

        return $this->platformClient->post('/api/v1/automotive/articles/search-by-criteria', $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCriteria(?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams(null, $countryCode);

        return $this->cachedGet(
            '/api/v1/automotive/criteria',
            $this->scopeKey('criteria', null, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSuppliers(?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams(null, $countryCode);

        return $this->cachedGet(
            '/api/v1/automotive/suppliers',
            $this->scopeKey('suppliers', null, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeRoots(?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->cachedGet(
            '/api/v1/automotive/search-tree/roots',
            $this->scopeKey('search-tree:roots', $verticalScope, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeChildren(string $nodeId, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->cachedGet(
            "/api/v1/automotive/search-tree/{$nodeId}/children",
            $this->scopeKey("search-tree:children:{$nodeId}", $verticalScope, $countryCode),
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeArticles(string $nodeId, ?string $verticalScope = null, ?string $countryCode = null): ?array
    {
        $params = $this->buildBaseParams($verticalScope, $countryCode);

        return $this->platformClient->get("/api/v1/automotive/search-tree/{$nodeId}/articles", $params);
    }

    /**
     * Build query parameters for vertical scoping and country code.
     *
     * @return array<string, string>
     */
    private function buildBaseParams(?string $verticalScope, ?string $countryCode = null): array
    {
        $params = [];

        if ($verticalScope !== null) {
            $params['vertical'] = $verticalScope;
        }

        if ($countryCode !== null) {
            $params['country'] = $countryCode;
        }

        return $params;
    }

    /**
     * Build a scoped cache key incorporating vertical and country.
     */
    private function scopeKey(string $base, ?string $verticalScope, ?string $countryCode): string
    {
        $key = $base;

        if ($verticalScope !== null) {
            $key .= ":{$verticalScope}";
        }

        if ($countryCode !== null) {
            $key .= ":c:{$countryCode}";
        }

        return $key;
    }

    /**
     * Enrich articles with local inventory status.
     *
     * @param  array<int, array<string, mixed>>  $articles
     * @return array<int, array<string, mixed>>
     */
    public function enrichWithInventoryStatus(array $articles, string $companyId): array
    {
        $platformArticleIds = array_filter(array_column($articles, 'id'));

        if (empty($platformArticleIds)) {
            return $articles;
        }

        $localProducts = $this->productInventoryQuery->findByPlatformArticleIds($companyId, $platformArticleIds);

        foreach ($articles as &$article) {
            $articleId = $article['id'] ?? null;
            $inventoryDTO = $articleId !== null ? $localProducts->get($articleId) : null;

            if ($inventoryDTO !== null) {
                $article['local_inventory'] = [
                    'in_stock' => true,
                    'product_id' => $inventoryDTO->productId,
                    'quantity' => $inventoryDTO->totalStock,
                    'available' => $inventoryDTO->available,
                    'sale_price' => $inventoryDTO->salePrice,
                ];
            } else {
                $article['local_inventory'] = [
                    'in_stock' => false,
                ];
            }
        }

        return $articles;
    }

    /**
     * @param  array<string, string>  $queryParams
     * @return array<string, mixed>|null
     */
    private function cachedGet(string $path, string $cacheKey, int $ttl, array $queryParams = []): ?array
    {
        $fullKey = self::CACHE_PREFIX.$cacheKey;

        $cached = Cache::get($fullKey);
        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $response = $this->platformClient->get($path, $queryParams);
        if ($response !== null) {
            Cache::put($fullKey, $response, $ttl);
        }

        return $response;
    }
}
