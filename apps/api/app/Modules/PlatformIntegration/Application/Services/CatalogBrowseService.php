<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\Cache;

final class CatalogBrowseService
{
    private const CACHE_PREFIX = 'platform:catalog:';

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getManufacturers(?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);

        if ($params === []) {
            return $this->cachedGet('/api/v1/automotive/manufacturers', 'manufacturers', 86400);
        }

        $scopeKey = "manufacturers:{$verticalScope}";

        return $this->cachedGet('/api/v1/automotive/manufacturers', $scopeKey, 86400, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getModelSeries(string $manufacturerId, ?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);
        $scopeSuffix = $verticalScope !== null ? ":{$verticalScope}" : '';

        return $this->cachedGet(
            "/api/v1/automotive/manufacturers/{$manufacturerId}/model-series",
            "model-series:{$manufacturerId}{$scopeSuffix}",
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicles(string $modelSeriesId, ?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);
        $scopeSuffix = $verticalScope !== null ? ":{$verticalScope}" : '';

        return $this->cachedGet(
            "/api/v1/automotive/model-series/{$modelSeriesId}/vehicles",
            "vehicles:{$modelSeriesId}{$scopeSuffix}",
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicleArticles(string $vehicleType, string $vehicleId, ?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);

        return $this->platformClient->get("/api/v1/automotive/vehicles/{$vehicleType}/{$vehicleId}/articles", $params);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>|null
     */
    public function searchArticles(array $params, ?string $verticalScope = null): ?array
    {
        $params = array_merge($params, $this->buildVerticalParams($verticalScope));

        return $this->platformClient->get('/api/v1/automotive/articles', $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeRoots(?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);

        if ($params === []) {
            return $this->cachedGet('/api/v1/automotive/search-tree/roots', 'search-tree:roots', 86400);
        }

        $scopeKey = "search-tree:roots:{$verticalScope}";

        return $this->cachedGet('/api/v1/automotive/search-tree/roots', $scopeKey, 86400, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeChildren(string $nodeId, ?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);
        $scopeSuffix = $verticalScope !== null ? ":{$verticalScope}" : '';

        return $this->cachedGet(
            "/api/v1/automotive/search-tree/{$nodeId}/children",
            "search-tree:children:{$nodeId}{$scopeSuffix}",
            86400,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeArticles(string $nodeId, ?string $verticalScope = null): ?array
    {
        $params = $this->buildVerticalParams($verticalScope);

        return $this->platformClient->get("/api/v1/automotive/search-tree/{$nodeId}/articles", $params);
    }

    /**
     * Build query parameters for vertical scoping.
     *
     * @return array<string, string>
     */
    private function buildVerticalParams(?string $verticalScope): array
    {
        if ($verticalScope === null) {
            return [];
        }

        return ['vertical' => $verticalScope];
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

        // Find local products linked to these platform articles
        $localProducts = Product::query()
            ->where('company_id', $companyId)
            ->whereHas('automotiveMetadata', function ($query) use ($platformArticleIds) {
                $query->whereIn('platform_article_id', $platformArticleIds);
            })
            ->with(['automotiveMetadata', 'stockLevels'])
            ->get()
            ->keyBy(fn (Product $p): string => (string) ($p->automotiveMetadata->platform_article_id ?? ''));

        foreach ($articles as &$article) {
            $articleId = $article['id'] ?? null;
            $localProduct = $articleId !== null ? $localProducts->get($articleId) : null;

            if ($localProduct !== null) {
                /** @var numeric-string $totalStock */
                $totalStock = (string) $localProduct->stockLevels->sum('quantity');
                /** @var numeric-string $totalReserved */
                $totalReserved = (string) $localProduct->stockLevels->sum('reserved');
                $article['local_inventory'] = [
                    'in_stock' => true,
                    'product_id' => $localProduct->id,
                    'quantity' => $totalStock,
                    'available' => bcsub($totalStock, $totalReserved, 2),
                    'sale_price' => $localProduct->sale_price,
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
