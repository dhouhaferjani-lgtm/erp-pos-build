<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Http;

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

    /**
     * @param array<string, string> $queryParams
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
     * @param array<string, mixed> $data
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
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(3, 200, fn (\Exception $e, PendingRequest $request) => $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true))
            );
    }

    private function buildUrl(string $path): string
    {
        return ltrim($path, '/');
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
