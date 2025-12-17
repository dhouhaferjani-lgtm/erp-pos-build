<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Models\AdminAuditLog;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

final class MonitoringService
{
    /**
     * Get comprehensive system health metrics.
     *
     * @return array<string, mixed>
     */
    public function getSystemHealth(): array
    {
        return [
            'status' => $this->getOverallStatus(),
            'timestamp' => now()->toIso8601String(),
            'server' => $this->getServerMetrics(),
            'database' => $this->getDatabaseMetrics(),
            'cache' => $this->getCacheMetrics(),
            'queue' => $this->getQueueMetrics(),
            'services' => $this->getExternalServicesStatus(),
        ];
    }

    /**
     * Get performance metrics.
     *
     * @return array<string, mixed>
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'response_times' => $this->getResponseTimeMetrics(),
            'throughput' => $this->getThroughputMetrics(),
            'error_rates' => $this->getErrorRateMetrics(),
            'database_performance' => $this->getDatabasePerformance(),
        ];
    }

    /**
     * Get critical business metrics.
     *
     * @return array<string, mixed>
     */
    public function getCriticalMetrics(): array
    {
        return [
            'billing' => $this->getBillingMetrics(),
            'tenants' => $this->getTenantMetrics(),
            'alerts' => $this->getActiveAlerts(),
            'recent_events' => $this->getRecentCriticalEvents(),
        ];
    }

    /**
     * Get queue monitoring data.
     *
     * @return array<string, mixed>
     */
    public function getQueueMonitoring(): array
    {
        return [
            'summary' => $this->getQueueMetrics(),
            'jobs_by_queue' => $this->getJobsByQueue(),
            'failed_jobs' => $this->getFailedJobs(),
            'processing_rate' => $this->getProcessingRate(),
        ];
    }

    /**
     * Get overall system status.
     */
    private function getOverallStatus(): string
    {
        $issues = 0;

        // Check database
        try {
            DB::select('SELECT 1');
        } catch (\Exception $e) {
            return 'critical';
        }

        // Check cache
        try {
            Cache::get('health_check');
        } catch (\Exception $e) {
            $issues++;
        }

        // Check queue size
        $queueSize = $this->getPendingJobsCount();
        if ($queueSize > 1000) {
            $issues++;
        }

        // Check failed jobs
        $failedJobs = $this->getFailedJobsCount();
        if ($failedJobs > 10) {
            $issues++;
        }

        if ($issues === 0) {
            return 'healthy';
        } elseif ($issues === 1) {
            return 'degraded';
        }

        return 'critical';
    }

    /**
     * Get server metrics.
     *
     * @return array<string, mixed>
     */
    private function getServerMetrics(): array
    {
        $load = sys_getloadavg();

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory' => [
                'used' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => $this->getMemoryLimit(),
                'usage_percent' => $this->getMemoryUsagePercent(),
            ],
            'load_average' => [
                '1min' => $load[0] ?? 0,
                '5min' => $load[1] ?? 0,
                '15min' => $load[2] ?? 0,
            ],
            'disk' => $this->getDiskUsage(),
            'uptime' => $this->getUptime(),
        ];
    }

    /**
     * Get database metrics.
     *
     * @return array<string, mixed>
     */
    private function getDatabaseMetrics(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            /** @var array<int, object> $connections */
            $connections = DB::select('SELECT count(*) as count FROM pg_stat_activity WHERE datname = current_database()');
            $connectionCount = $connections[0]->count ?? 0;

            /** @var array<int, object> $dbSize */
            $dbSize = DB::select('SELECT pg_database_size(current_database()) as size');
            $size = $dbSize[0]->size ?? 0;

            return [
                'status' => 'connected',
                'latency_ms' => round($latency, 2),
                'connections' => $connectionCount,
                'max_connections' => (int) config('database.connections.pgsql.pool.max_connections', 100),
                'size_bytes' => $size,
                'size_human' => $this->formatBytes((int) $size),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cache metrics.
     *
     * @return array<string, mixed>
     */
    private function getCacheMetrics(): array
    {
        try {
            $driver = config('cache.default');
            $start = microtime(true);
            Cache::put('health_check', time(), 10);
            Cache::get('health_check');
            $latency = (microtime(true) - $start) * 1000;

            $info = [];
            if ($driver === 'redis') {
                try {
                    /** @var \Illuminate\Redis\Connections\Connection $redis */
                    $redis = Redis::connection();
                    $redisInfo = $redis->info();
                    $info = [
                        'used_memory' => $redisInfo['used_memory'] ?? 0,
                        'used_memory_human' => $redisInfo['used_memory_human'] ?? 'N/A',
                        'connected_clients' => $redisInfo['connected_clients'] ?? 0,
                        'total_commands_processed' => $redisInfo['total_commands_processed'] ?? 0,
                    ];
                } catch (\Exception $e) {
                    $info = ['error' => $e->getMessage()];
                }
            }

            return [
                'status' => 'connected',
                'driver' => $driver,
                'latency_ms' => round($latency, 2),
                'info' => $info,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'driver' => config('cache.default'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get queue metrics.
     *
     * @return array<string, mixed>
     */
    private function getQueueMetrics(): array
    {
        $pendingJobs = $this->getPendingJobsCount();
        $failedJobs = $this->getFailedJobsCount();

        return [
            'driver' => config('queue.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'status' => $failedJobs > 10 ? 'warning' : ($pendingJobs > 1000 ? 'backlogged' : 'healthy'),
        ];
    }

    /**
     * Get external services status.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getExternalServicesStatus(): array
    {
        $services = [];

        // Stripe
        $services['stripe'] = [
            'configured' => ! empty(config('services.stripe.secret')),
            'status' => ! empty(config('services.stripe.secret')) ? 'available' : 'not_configured',
        ];

        // Sentry
        $services['sentry'] = [
            'configured' => ! empty(config('sentry.dsn')),
            'status' => ! empty(config('sentry.dsn')) ? 'available' : 'not_configured',
        ];

        // Mail
        $services['mail'] = [
            'driver' => config('mail.default'),
            'configured' => ! empty(config('mail.mailers.'.config('mail.default'))),
            'status' => 'available',
        ];

        return $services;
    }

    /**
     * Get response time metrics.
     *
     * @return array<string, mixed>
     */
    private function getResponseTimeMetrics(): array
    {
        // In a real implementation, this would read from a time-series database
        // For now, return sample/cached metrics
        $cached = Cache::get('monitoring:response_times', []);

        return [
            'average_ms' => $cached['average'] ?? 45,
            'p50_ms' => $cached['p50'] ?? 35,
            'p95_ms' => $cached['p95'] ?? 120,
            'p99_ms' => $cached['p99'] ?? 250,
            'last_hour' => $cached['history'] ?? [],
        ];
    }

    /**
     * Get throughput metrics.
     *
     * @return array<string, mixed>
     */
    private function getThroughputMetrics(): array
    {
        $cached = Cache::get('monitoring:throughput', []);

        return [
            'requests_per_minute' => $cached['rpm'] ?? 0,
            'requests_per_hour' => $cached['rph'] ?? 0,
            'peak_rpm' => $cached['peak_rpm'] ?? 0,
            'history' => $cached['history'] ?? [],
        ];
    }

    /**
     * Get error rate metrics.
     *
     * @return array<string, mixed>
     */
    private function getErrorRateMetrics(): array
    {
        $recentErrors = AdminAuditLog::where('created_at', '>=', now()->subHour())
            ->where('action', 'like', '%error%')
            ->count();

        $totalRequests = Cache::get('monitoring:total_requests_hour', 1000);
        $errorRate = $totalRequests > 0 ? ($recentErrors / $totalRequests) * 100 : 0;

        return [
            'error_count_last_hour' => $recentErrors,
            'error_rate_percent' => round($errorRate, 2),
            'status' => $errorRate > 5 ? 'critical' : ($errorRate > 1 ? 'warning' : 'healthy'),
        ];
    }

    /**
     * Get database performance metrics.
     *
     * @return array<string, mixed>
     */
    private function getDatabasePerformance(): array
    {
        try {
            /** @var array<int, object> $slowQueries */
            $slowQueries = DB::select('
                SELECT count(*) as count
                FROM pg_stat_statements
                WHERE mean_exec_time > 1000
            ');

            return [
                'slow_queries' => $slowQueries[0]->count ?? 0,
                'status' => 'healthy',
            ];
        } catch (\Exception $e) {
            // pg_stat_statements might not be enabled
            return [
                'slow_queries' => 'N/A',
                'status' => 'unknown',
                'note' => 'pg_stat_statements extension not enabled',
            ];
        }
    }

    /**
     * Get billing metrics.
     *
     * @return array<string, mixed>
     */
    private function getBillingMetrics(): array
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'revenue' => [
                'today' => Payment::whereDate('paid_at', $today)
                    ->where('status', 'succeeded')
                    ->sum('amount'),
                'this_month' => Payment::where('paid_at', '>=', $thisMonth)
                    ->where('status', 'succeeded')
                    ->sum('amount'),
                'pending_invoices' => Invoice::where('status', 'pending')->sum('total'),
                'overdue_invoices' => Invoice::where('status', 'overdue')->sum('total'),
            ],
            'subscriptions' => [
                'active' => TenantSubscription::where('status', 'active')->count(),
                'trial' => TenantSubscription::where('status', 'trial')->count(),
                'past_due' => TenantSubscription::where('status', 'past_due')->count(),
                'churned_this_month' => TenantSubscription::where('cancelled_at', '>=', $thisMonth)->count(),
            ],
            'payments' => [
                'successful_today' => Payment::whereDate('paid_at', $today)
                    ->where('status', 'succeeded')
                    ->count(),
                'failed_today' => Payment::whereDate('created_at', $today)
                    ->where('status', 'failed')
                    ->count(),
            ],
        ];
    }

    /**
     * Get tenant metrics.
     *
     * @return array<string, mixed>
     */
    private function getTenantMetrics(): array
    {
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'total' => Tenant::count(),
            'active' => Tenant::where('status', 'active')->count(),
            'trial' => Tenant::where('plan', 'trial')->count(),
            'new_this_month' => Tenant::where('created_at', '>=', $thisMonth)->count(),
            'by_plan' => DB::table('tenants')
                ->selectRaw('plan, count(*) as count')
                ->groupBy('plan')
                ->pluck('count', 'plan')
                ->toArray(),
        ];
    }

    /**
     * Get active alerts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getActiveAlerts(): array
    {
        $alerts = [];

        // Check failed jobs
        $failedJobs = $this->getFailedJobsCount();
        if ($failedJobs > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'queue',
                'message' => "{$failedJobs} failed jobs in queue",
                'action' => 'Review failed jobs and retry or delete them',
            ];
        }

        // Check overdue invoices
        $overdueCount = Invoice::where('status', 'overdue')->count();
        if ($overdueCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'category' => 'billing',
                'message' => "{$overdueCount} overdue invoices",
                'action' => 'Send payment reminders or follow up with customers',
            ];
        }

        // Check past due subscriptions
        $pastDueCount = TenantSubscription::where('status', 'past_due')->count();
        if ($pastDueCount > 0) {
            $alerts[] = [
                'type' => 'critical',
                'category' => 'billing',
                'message' => "{$pastDueCount} subscriptions are past due",
                'action' => 'Contact customers about payment issues',
            ];
        }

        // Check disk space
        $diskUsage = $this->getDiskUsagePercent();
        if ($diskUsage > 80) {
            $alerts[] = [
                'type' => $diskUsage > 90 ? 'critical' : 'warning',
                'category' => 'system',
                'message' => "Disk usage at {$diskUsage}%",
                'action' => 'Clean up old files or expand storage',
            ];
        }

        // Check memory usage
        $memoryUsage = $this->getMemoryUsagePercent();
        if ($memoryUsage > 80) {
            $alerts[] = [
                'type' => $memoryUsage > 90 ? 'critical' : 'warning',
                'category' => 'system',
                'message' => "Memory usage at {$memoryUsage}%",
                'action' => 'Review memory consumption or scale up',
            ];
        }

        return $alerts;
    }

    /**
     * Get recent critical events.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRecentCriticalEvents(): array
    {
        $events = [];

        // Recent failed payments
        $failedPayments = Payment::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($failedPayments as $payment) {
            $events[] = [
                'type' => 'payment_failed',
                'severity' => 'warning',
                'message' => "Payment of {$payment->currency} {$payment->amount} failed",
                'tenant_id' => $payment->tenant_id,
                'timestamp' => $payment->created_at->toIso8601String(),
            ];
        }

        // Recent subscription cancellations
        $cancellations = TenantSubscription::whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', now()->subDay())
            ->orderBy('cancelled_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($cancellations as $sub) {
            if ($sub->cancelled_at === null) {
                continue;
            }
            $events[] = [
                'type' => 'subscription_cancelled',
                'severity' => 'info',
                'message' => 'Subscription cancelled',
                'tenant_id' => $sub->tenant_id,
                'timestamp' => $sub->cancelled_at->toIso8601String(),
            ];
        }

        // Sort by timestamp
        usort($events, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($events, 0, 10);
    }

    /**
     * Get jobs by queue.
     *
     * @return array<string, int>
     */
    private function getJobsByQueue(): array
    {
        // This would need to be customized based on queue driver
        return [
            'default' => $this->getQueueSize('default'),
            'high' => $this->getQueueSize('high'),
            'low' => $this->getQueueSize('low'),
        ];
    }

    /**
     * Get failed jobs details.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFailedJobs(): array
    {
        try {
            return DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->limit(10)
                ->get()
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception' => \Illuminate\Support\Str::limit($job->exception, 200),
                ])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get processing rate.
     *
     * @return array<string, mixed>
     */
    private function getProcessingRate(): array
    {
        return Cache::get('monitoring:queue_processing_rate', [
            'jobs_per_minute' => 0,
            'jobs_per_hour' => 0,
        ]);
    }

    /**
     * Get pending jobs count.
     */
    private function getPendingJobsCount(): int
    {
        try {
            return DB::table('jobs')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get failed jobs count.
     */
    private function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get queue size by name.
     */
    private function getQueueSize(string $queue): int
    {
        try {
            return DB::table('jobs')->where('queue', $queue)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get disk usage information.
     *
     * @return array<string, mixed>
     */
    private function getDiskUsage(): array
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'usage_percent' => round(($used / $total) * 100, 1),
            'total_human' => $this->formatBytes((int) $total),
            'free_human' => $this->formatBytes((int) $free),
            'used_human' => $this->formatBytes((int) $used),
        ];
    }

    /**
     * Get disk usage percentage.
     */
    private function getDiskUsagePercent(): float
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        return round((($total - $free) / $total) * 100, 1);
    }

    /**
     * Get memory limit in bytes.
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $limit,
        };
    }

    /**
     * Get memory usage percentage.
     */
    private function getMemoryUsagePercent(): float
    {
        $limit = $this->getMemoryLimit();
        $used = memory_get_usage(true);

        if ($limit === PHP_INT_MAX) {
            return 0;
        }

        return round(($used / $limit) * 100, 1);
    }

    /**
     * Get server uptime.
     */
    private function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = (int) file_get_contents('/proc/uptime');

            return $this->formatDuration($uptime);
        }

        return 'N/A';
    }

    /**
     * Format bytes to human readable.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Format duration to human readable.
     */
    private function formatDuration(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        return implode(' ', $parts) ?: '0m';
    }
}
