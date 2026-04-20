<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Application\Services;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class PurchaseHubService
{
    private const CACHE_PREFIX = 'purchase_hub:';

    private const OFFERS_CACHE_TTL = 300;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getOffers(): ?array
    {
        /** @var array<int, array<string, mixed>>|null $cached */
        $cached = Cache::get(self::CACHE_PREFIX.'offers');
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->platformClient->get('/api/v1/purchase-hub/tenant/offers');
            if ($data !== null) {
                Cache::put(self::CACHE_PREFIX.'offers', $data, self::OFFERS_CACHE_TTL);
            }

            return $data;
        } catch (\Throwable $e) {
            Log::warning('PurchaseHub: failed to fetch offers', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOffer(string $campaignId): ?array
    {
        try {
            return $this->platformClient->get("/api/v1/purchase-hub/tenant/offers/{$campaignId}");
        } catch (\Throwable $e) {
            Log::warning('PurchaseHub: failed to fetch offer', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function placeOrder(array $data): ?array
    {
        try {
            Cache::forget(self::CACHE_PREFIX.'offers');

            return $this->platformClient->post('/api/v1/purchase-hub/tenant/orders', $data);
        } catch (\Throwable $e) {
            Log::warning('PurchaseHub: failed to place order', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrders(): ?array
    {
        try {
            return $this->platformClient->get('/api/v1/purchase-hub/tenant/orders');
        } catch (\Throwable $e) {
            Log::warning('PurchaseHub: failed to fetch orders', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrder(string $orderId): ?array
    {
        try {
            return $this->platformClient->get("/api/v1/purchase-hub/tenant/orders/{$orderId}");
        } catch (\Throwable $e) {
            Log::warning('PurchaseHub: failed to fetch order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
