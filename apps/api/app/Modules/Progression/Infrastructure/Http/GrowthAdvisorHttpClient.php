<?php

declare(strict_types=1);

namespace App\Modules\Progression\Infrastructure\Http;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GrowthAdvisorHttpClient implements GrowthAdvisorClientInterface
{
    private const CIRCUIT_BREAKER_KEY = 'growth_advisor:circuit_breaker';

    private const CIRCUIT_FAILURE_COUNT_KEY = 'growth_advisor:circuit_failures';

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $retryTimes,
        private readonly int $retryDelay,
        private readonly int $circuitBreakerThreshold,
        private readonly int $circuitBreakerCooldown,
    ) {}

    public function getCompanyProfile(string $companyId): ?array
    {
        return $this->get("/api/v1/companies/{$companyId}");
    }

    public function registerCompany(array $data): ?array
    {
        return $this->post('/api/v1/companies', $data);
    }

    public function getMilestones(string $companyId): array
    {
        return $this->getList("/api/v1/companies/{$companyId}/milestones");
    }

    public function getModules(string $companyId): array
    {
        return $this->getList("/api/v1/companies/{$companyId}/modules");
    }

    public function activateModule(string $companyId, string $moduleId): ?array
    {
        return $this->post("/api/v1/companies/{$companyId}/modules/{$moduleId}/activate");
    }

    public function getRecommendations(string $companyId): array
    {
        return $this->getList("/api/v1/companies/{$companyId}/recommendations");
    }

    public function acceptRecommendation(string $companyId, string $recommendationId): ?array
    {
        return $this->post("/api/v1/companies/{$companyId}/recommendations/{$recommendationId}/accept");
    }

    public function dismissRecommendation(string $companyId, string $recommendationId): ?array
    {
        return $this->post("/api/v1/companies/{$companyId}/recommendations/{$recommendationId}/dismiss");
    }

    public function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        if ($this->isCircuitOpen()) {
            return null;
        }

        try {
            $response = $this->buildRequest()->get($this->baseUrl.$path);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();

                return $response->json('data');
            }

            $this->recordFailure();

            return null;
        } catch (ConnectionException|RequestException $e) {
            $this->recordFailure();
            Log::warning('Growth Advisor HTTP GET failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $data = []): ?array
    {
        if ($this->isCircuitOpen()) {
            return null;
        }

        try {
            $response = $this->buildRequest()->post($this->baseUrl.$path, $data);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();

                return $response->json('data');
            }

            $this->recordFailure();

            return null;
        } catch (ConnectionException|RequestException $e) {
            $this->recordFailure();
            Log::warning('Growth Advisor HTTP POST failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getList(string $path): array
    {
        $result = $this->get($path);

        return is_array($result) ? array_values($result) : [];
    }

    private function buildRequest(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                $this->retryTimes,
                $this->retryDelay,
                fn (\Exception $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503, 504], true)),
            )
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
            ]);
    }

    private function recordFailure(): void
    {
        $count = (int) Cache::get(self::CIRCUIT_FAILURE_COUNT_KEY, 0);
        $count++;

        Cache::put(self::CIRCUIT_FAILURE_COUNT_KEY, $count, $this->circuitBreakerCooldown);

        if ($count >= $this->circuitBreakerThreshold) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, $this->circuitBreakerCooldown);
            Log::error('Growth Advisor circuit breaker OPEN', [
                'failures' => $count,
                'cooldown_seconds' => $this->circuitBreakerCooldown,
            ]);
        }
    }

    private function resetCircuitFailures(): void
    {
        Cache::forget(self::CIRCUIT_FAILURE_COUNT_KEY);
        Cache::forget(self::CIRCUIT_BREAKER_KEY);
    }
}
