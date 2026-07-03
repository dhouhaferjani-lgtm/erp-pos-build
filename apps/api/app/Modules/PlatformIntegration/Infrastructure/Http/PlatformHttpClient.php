<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Http;

use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PlatformHttpClient
{
    private const CIRCUIT_BREAKER_KEY = 'platform:circuit_breaker';

    private const CIRCUIT_FAILURE_COUNT_KEY = 'platform:circuit_failures';

    private const CIRCUIT_FAILURE_THRESHOLD = 3;

    private const CIRCUIT_FAILURE_WINDOW = 30;

    private const CIRCUIT_OPEN_DURATION = 30;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * @param  array<string, string>  $queryParams
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $queryParams = []): ?array
    {
        if ($this->isCircuitOpen()) {
            throw new \RuntimeException('Platform circuit breaker is open');
        }

        try {
            $response = $this->buildRequest()
                ->get($this->buildUrl($path), $queryParams);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();
                /** @var array<string, mixed> $data */
                $data = $response->json('data');

                return $data;
            }

            $this->recordFailure();
            Log::warning('Platform API non-success response', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function post(string $path, array $data = []): ?array
    {
        if ($this->isCircuitOpen()) {
            throw new \RuntimeException('Platform circuit breaker is open');
        }

        try {
            $response = $this->buildRequest()
                ->post($this->buildUrl($path), $data);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();
                /** @var array<string, mixed>|null $result */
                $result = $response->json('data');

                return $result;
            }

            $this->recordFailure();

            return null;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * POST request returning the full response body (no 'data' unwrapping).
     * Used for platform endpoints that don't wrap responses in {data: ...}.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    public function postRaw(string $path, array $data = [], array $headers = []): ?array
    {
        if ($this->isCircuitOpen()) {
            throw new \RuntimeException('Platform circuit breaker is open');
        }

        try {
            $request = $this->buildRequest();
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            $response = $request->post($this->buildUrl($path), $data);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();
                /** @var array<string, mixed>|null $result */
                $result = $response->json();

                return $result;
            }

            $this->recordFailure();
            Log::warning('Platform API non-success response (raw)', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }
            $this->recordFailure();
            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * POST returning status + full body. Unlike postRaw(), non-2xx responses
     * are returned so callers can treat 4xx as terminal reconciliation states.
     * Only 429/5xx/transport failures feed the circuit breaker.
     *
     * @param  array<string, mixed>  $data
     */
    public function postWithStatus(string $path, array $data = []): PlatformHttpResponse
    {
        if ($this->isCircuitOpen()) {
            throw new \RuntimeException('Platform circuit breaker is open');
        }

        try {
            $response = $this->buildRequest()->post($this->buildUrl($path), $data);

            return $this->toStatusResponse($response->status(), $response->json());
        } catch (RequestException $e) {
            return $this->toStatusResponse($e->response->status(), $e->response->json());
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * GET request returning the full response body (no 'data' unwrapping).
     *
     * @param  array<string, string>  $queryParams
     * @return array<string, mixed>|null
     */
    public function getRaw(string $path, array $queryParams = []): ?array
    {
        if ($this->isCircuitOpen()) {
            throw new \RuntimeException('Platform circuit breaker is open');
        }

        try {
            $response = $this->buildRequest()
                ->get($this->buildUrl($path), $queryParams);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                $this->resetCircuitFailures();
                /** @var array<string, mixed>|null $result */
                $result = $response->json();

                return $result;
            }

            $this->recordFailure();
            Log::warning('Platform API non-success response (raw)', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }
            $this->recordFailure();
            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    public function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    private function buildRequest(): PendingRequest
    {
        $baseUrl = config('services.platform.url', '');
        $apiKey = config('services.platform.api_key', '');

        return Http::baseUrl((string) $baseUrl)
            ->withHeader('X-API-Key', (string) $apiKey)
            ->withHeaders($this->tenantHeaders())
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 200, fn (\Exception $e, PendingRequest $request) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true))
            );
    }

    /**
     * Capture the originating tenant + company at request-creation time
     * so retries (which may happen in a different async context) preserve
     * the headers from the time the request was minted, not whatever
     * CompanyContext happens to hold when the retry fires.
     *
     * MANDATORY — both calls throw RuntimeException on empty context. An
     * empty CompanyContext at outbound HTTP time is a real upstream bug
     * (queue job that didn't bind context, console command without
     * TenantScopedCommand, etc.); failing loud surfaces the bug rather
     * than silently sending headerless requests that the platform-side
     * Finding J enforcement (platform.synerivia-tenant-enforcement
     * cluster) would reject anyway.
     *
     * @return array<string, string>
     */
    private function tenantHeaders(): array
    {
        return [
            'X-Tenant-Id' => $this->companyContext->requireTenantId(),
            'X-Company-Id' => $this->companyContext->requireCompanyId(),
        ];
    }

    private function buildUrl(string $path): string
    {
        return ltrim($path, '/');
    }

    private function toStatusResponse(int $status, mixed $body): PlatformHttpResponse
    {
        if ($status >= 200 && $status < 300) {
            $this->resetCircuitFailures();
        } elseif ($status === 429 || $status >= 500) {
            $this->recordFailure();
        }

        return new PlatformHttpResponse($status, is_array($body) ? $body : null);
    }

    private function recordFailure(): void
    {
        $key = self::CIRCUIT_FAILURE_COUNT_KEY;
        $count = (int) Cache::get($key, 0);
        $count++;

        Cache::put($key, $count, self::CIRCUIT_FAILURE_WINDOW);

        if ($count >= self::CIRCUIT_FAILURE_THRESHOLD) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, self::CIRCUIT_OPEN_DURATION);
            Cache::forget($key);
            Log::error('Platform circuit breaker opened', ['failure_count' => $count]);
        }
    }

    private function resetCircuitFailures(): void
    {
        Cache::forget(self::CIRCUIT_FAILURE_COUNT_KEY);
    }
}
