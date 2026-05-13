<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PurchaseHub integration service.
 *
 * dev-remediation/B.M2.5 — the offers cache key now includes the
 * active CompanyContext tenant_id + company_id suffix so a cache hit
 * never returns another tenant's payload. Cache invalidation in
 * placeOrder uses the same scoped key.
 */
final class PurchaseHubService
{
    private const CACHE_PREFIX = 'purchase_hub:';

    private const OFFERS_CACHE_TTL = 300;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getOffers(): ?array
    {
        $cacheKey = $this->offersCacheKey();

        /** @var array<int, array<string, mixed>>|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->platformClient->get('/api/v1/purchase-hub/tenant/offers');
            if ($data !== null) {
                Cache::put($cacheKey, $data, self::OFFERS_CACHE_TTL);
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
            Cache::forget($this->offersCacheKey());

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

    /**
     * Build the offers cache key scoped to the active tenant + company.
     * Falls back to "none" segments when no company context is set so
     * pre-auth or cross-tenant admin paths get their own keyspace.
     */
    private function offersCacheKey(): string
    {
        $companyId = $this->companyContext->getCompanyId() ?? 'none';
        $company = $companyId === 'none' ? null : $this->companyContext->getCompany();
        $tenantId = $company !== null ? $company->tenant_id : 'none';

        return self::CACHE_PREFIX.'offers:'.$tenantId.':'.$companyId;
    }
}
