<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Infrastructure\Http;

use App\Modules\SmartPrompts\Application\Contracts\RecommendationEngineClientInterface;
use App\Modules\SmartPrompts\Application\DTOs\RecommendationRequestData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class RecommendationEngineHttpClient implements RecommendationEngineClientInterface
{
    private const CIRCUIT_BREAKER_KEY = 'recommendation_engine:circuit_breaker';

    private const CIRCUIT_FAILURE_COUNT_KEY = 'recommendation_engine:circuit_failures';

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $retryTimes,
        private readonly int $retryDelay,
        private readonly int $circuitBreakerThreshold,
        private readonly int $circuitBreakerCooldown,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getRecommendations(RecommendationRequestData $request, string $tenantId, string $companyId): ?array
    {
        if ($this->isCircuitOpen()) {
            Log::debug('Recommendation engine circuit breaker is open, skipping request');

            return null;
        }

        try {
            $response = $this->buildRequest($tenantId, $companyId)
                ->post("{$this->baseUrl}/api/v1/recommendations/", [
                    'product_ids' => $request->productIds,
                    'context' => $request->context->value,
                    'limit' => $request->limit,
                    'skin_type' => $request->skinType?->value,
                    'customer_id' => $request->customerId,
                    'vertical' => $request->vertical,
                    'country' => $request->country,
                ]);

            if ($response->successful()) {
                $this->resetCircuitBreaker();

                return $response->json();
            }

            $this->recordFailure();
            Log::warning('Recommendation engine returned non-success status', [
                'status' => $response->status(),
            ]);

            return null;
        } catch (ConnectionException|RequestException $e) {
            $this->recordFailure();
            Log::warning('Recommendation engine request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    private function buildRequest(string $tenantId, string $companyId): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Tenant-Id' => $tenantId,
                'X-Company-Id' => $companyId,
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                $this->retryTimes,
                $this->retryDelay,
                fn (\Exception $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
            );
    }

    private function recordFailure(): void
    {
        $count = (int) Cache::get(self::CIRCUIT_FAILURE_COUNT_KEY, 0);
        $count++;

        Cache::put(self::CIRCUIT_FAILURE_COUNT_KEY, $count, $this->circuitBreakerCooldown * 2);

        if ($count >= $this->circuitBreakerThreshold) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, $this->circuitBreakerCooldown);
            Cache::forget(self::CIRCUIT_FAILURE_COUNT_KEY);
            Log::warning('Recommendation engine circuit breaker opened', [
                'failures' => $count,
                'cooldown_seconds' => $this->circuitBreakerCooldown,
            ]);
        }
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget(self::CIRCUIT_FAILURE_COUNT_KEY);
    }
}
