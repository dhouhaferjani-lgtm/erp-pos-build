<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Application\DTOs\HealthStatusData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

final class HealthCheckService
{
    /**
     * Run all health checks and return status.
     */
    public function check(): HealthStatusData
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $allHealthy = collect($checks)->every(fn (array $check): bool => $check['healthy']);

        return new HealthStatusData(
            healthy: $allHealthy,
            checks: $checks,
            timestamp: now()->toIso8601String(),
        );
    }

    /**
     * Check database connectivity and latency.
     *
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $duration = (microtime(true) - $start) * 1000;

            // Get connection pool info
            $connections = DB::select('SELECT count(*) as count FROM pg_stat_activity WHERE datname = current_database()');
            $connectionCount = $connections[0]->count ?? 0;

            return [
                'healthy' => true,
                'latency_ms' => round($duration, 2),
                'active_connections' => $connectionCount,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connectivity and memory usage.
     *
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $duration = (microtime(true) - $start) * 1000;

            /** @var array<string, mixed> $info */
            $info = Redis::info();

            return [
                'healthy' => true,
                'latency_ms' => round($duration, 2),
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check queue status and job counts.
     *
     * @return array<string, mixed>
     */
    private function checkQueue(): array
    {
        try {
            $queueSize = Queue::size();
            $failedCount = DB::table('failed_jobs')->count();

            // Queue is unhealthy if too many jobs are pending
            $healthy = $queueSize < 10000;

            return [
                'healthy' => $healthy,
                'pending_jobs' => $queueSize,
                'failed_jobs' => $failedCount,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check disk storage availability.
     *
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        try {
            $storagePath = storage_path();
            $disk = disk_free_space($storagePath);
            $total = disk_total_space($storagePath);

            if ($disk === false || $total === false) {
                return [
                    'healthy' => false,
                    'error' => 'Could not determine disk space',
                ];
            }

            $usedPercent = (($total - $disk) / $total) * 100;

            return [
                'healthy' => $usedPercent < 85,
                'free_gb' => round($disk / 1024 / 1024 / 1024, 2),
                'total_gb' => round($total / 1024 / 1024 / 1024, 2),
                'used_percent' => round($usedPercent, 2),
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Simple health check for load balancers (fast).
     */
    public function ping(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
