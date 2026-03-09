<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Product\Domain\AutomotiveProductMetadata;
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
    public function getManufacturers(): ?array
    {
        return $this->cachedGet('/api/v1/automotive/manufacturers', 'manufacturers', 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getModelSeries(string $manufacturerId): ?array
    {
        return $this->cachedGet(
            "/api/v1/automotive/manufacturers/{$manufacturerId}/model-series",
            "model-series:{$manufacturerId}",
            86400
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicles(string $modelSeriesId): ?array
    {
        return $this->cachedGet(
            "/api/v1/automotive/model-series/{$modelSeriesId}/vehicles",
            "vehicles:{$modelSeriesId}",
            86400
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVehicleArticles(string $vehicleType, string $vehicleId): ?array
    {
        return $this->platformClient->get("/api/v1/automotive/vehicles/{$vehicleType}/{$vehicleId}/articles");
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    public function searchArticles(array $params): ?array
    {
        return $this->platformClient->get('/api/v1/automotive/articles', $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeRoots(): ?array
    {
        return $this->cachedGet('/api/v1/automotive/search-tree/roots', 'search-tree:roots', 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeChildren(string $nodeId): ?array
    {
        return $this->cachedGet(
            "/api/v1/automotive/search-tree/{$nodeId}/children",
            "search-tree:children:{$nodeId}",
            86400
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSearchTreeArticles(string $nodeId): ?array
    {
        return $this->platformClient->get("/api/v1/automotive/search-tree/{$nodeId}/articles");
    }

    /**
     * Enrich articles with local inventory status.
     *
     * @param array<int, array<string, mixed>> $articles
     * @param string $companyId
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
            ->keyBy(fn (Product $p) => $p->automotiveMetadata?->platform_article_id);

        foreach ($articles as &$article) {
            $articleId = $article['id'] ?? null;
            $localProduct = $articleId !== null ? $localProducts->get($articleId) : null;

            if ($localProduct !== null) {
                $totalStock = $localProduct->stockLevels->sum('quantity');
                $totalReserved = $localProduct->stockLevels->sum('reserved');
                $article['local_inventory'] = [
                    'in_stock' => true,
                    'product_id' => $localProduct->id,
                    'quantity' => (string) $totalStock,
                    'available' => (string) bcsub((string) $totalStock, (string) $totalReserved, 2),
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
     * @return array<string, mixed>|null
     */
    private function cachedGet(string $path, string $cacheKey, int $ttl): ?array
    {
        $fullKey = self::CACHE_PREFIX . $cacheKey;

        $cached = Cache::get($fullKey);
        if ($cached !== null) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $response = $this->platformClient->get($path);
        if ($response !== null) {
            Cache::put($fullKey, $response, $ttl);
        }

        return $response;
    }
}
