<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation\Controllers;

use App\Modules\Admin\Application\Services\HealthCheckService;
use App\Modules\Admin\Application\Services\MonitoringService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MonitoringController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $healthService,
        private readonly MonitoringService $monitoringService,
    ) {}

    /**
     * Simple health check for load balancers.
     * Returns 200 if healthy, 503 if unhealthy.
     */
    #[CrossTenantRoute(reason: 'Public load-balancer health probe: reachability check used by Dokploy / upstream load balancers via /v1/health (no auth, no tenant); must remain accessible without a session for liveness routing across the platform.')]
    public function ping(): JsonResponse
    {
        $healthy = $this->healthService->ping();

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Detailed health check for super admin dashboard.
     */
    #[CrossTenantRoute(reason: 'Super-admin platform health check: detailed reachability diagnostics across the fleet\'s shared infrastructure (DB, Redis, queue workers) via HealthCheckService::check; super_admin middleware gated; not tenant-scoped by design.')]
    public function health(): JsonResponse
    {
        $status = $this->healthService->check();

        return response()->json([
            'data' => $status,
        ], $status->healthy ? 200 : 503);
    }

    /**
     * Get comprehensive system health metrics.
     */
    #[CrossTenantRoute(reason: 'Super-admin system-health monitor: fleet-wide CPU/memory/disk/error-rate metrics aggregated across the platform via MonitoringService::getSystemHealth; super_admin gated; not tenant-scoped.')]
    public function systemHealth(): JsonResponse
    {
        $health = $this->monitoringService->getSystemHealth();

        return response()->json(['data' => $health]);
    }

    /**
     * Get performance metrics.
     */
    #[CrossTenantRoute(reason: 'Super-admin performance monitor: fleet-wide request-latency / throughput metrics via MonitoringService::getPerformanceMetrics for platform operations; super_admin gated; not tenant-scoped.')]
    public function performance(): JsonResponse
    {
        $metrics = $this->monitoringService->getPerformanceMetrics();

        return response()->json(['data' => $metrics]);
    }

    /**
     * Get critical business metrics.
     */
    #[CrossTenantRoute(reason: 'Super-admin critical-metric monitor: fleet-wide business-critical KPIs (failed-payment rate, fiscal-chain break count, etc.) via MonitoringService::getCriticalMetrics for platform incident response; super_admin gated; not tenant-scoped.')]
    public function critical(): JsonResponse
    {
        $metrics = $this->monitoringService->getCriticalMetrics();

        return response()->json(['data' => $metrics]);
    }

    /**
     * Get queue monitoring data.
     */
    #[CrossTenantRoute(reason: 'Super-admin queue monitor: fleet-wide queue depth + processing rate + failed-job counts via MonitoringService::getQueueMonitoring; queue infrastructure (Laravel Horizon + Redis) is fleet-wide platform infrastructure, not per-tenant.')]
    public function queues(): JsonResponse
    {
        $queues = $this->monitoringService->getQueueMonitoring();

        return response()->json(['data' => $queues]);
    }

    /**
     * Get all monitoring data combined for dashboard.
     */
    #[CrossTenantRoute(reason: 'Super-admin monitoring dashboard: combined system + performance + critical + queue dashboard for platform operations; aggregates fleet-wide metrics from MonitoringService; super_admin gated; not tenant-scoped.')]
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'health' => $this->monitoringService->getSystemHealth(),
                'performance' => $this->monitoringService->getPerformanceMetrics(),
                'critical' => $this->monitoringService->getCriticalMetrics(),
                'queues' => $this->monitoringService->getQueueMonitoring(),
            ],
        ]);
    }

    /**
     * Retry a failed job.
     */
    #[CrossTenantRoute(reason: 'Super-admin queue operation: requeues a specific failed job by id; failed_jobs / jobs are platform-level Laravel queue infrastructure shared across the fleet; super_admin gated.')]
    public function retryFailedJob(Request $request, string $id): JsonResponse
    {
        try {
            /** @var object{id: int|string, queue: string, payload: string, exception: string, failed_at: string}|null $job */
            $job = DB::table('failed_jobs')->where('id', $id)->first();

            if (! $job) {
                return response()->json(['error' => 'Job not found'], 404);
            }

            // Re-queue the job
            DB::table('jobs')->insert([
                'queue' => $job->queue,
                'payload' => $job->payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);

            // Remove from failed jobs
            DB::table('failed_jobs')->where('id', $id)->delete();

            return response()->json(['message' => 'Job queued for retry']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a failed job.
     */
    #[CrossTenantRoute(reason: 'Super-admin queue operation: deletes a specific failed job permanently from the failed_jobs table; platform-level Laravel queue infrastructure; super_admin gated.')]
    public function deleteFailedJob(string $id): JsonResponse
    {
        try {
            $deleted = DB::table('failed_jobs')->where('id', $id)->delete();

            if ($deleted === 0) {
                return response()->json(['error' => 'Job not found'], 404);
            }

            return response()->json(['message' => 'Job deleted']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retry all failed jobs.
     */
    #[CrossTenantRoute(reason: 'Super-admin queue operation: bulk-retries every failed job in the platform-level failed_jobs queue, requeueing each into the jobs table and truncating failed_jobs; platform-level Laravel queue infrastructure; super_admin gated.')]
    public function retryAllFailedJobs(): JsonResponse
    {
        try {
            /** @var Collection<int, object{id: int|string, queue: string, payload: string, exception: string, failed_at: string}> $failedJobs */
            $failedJobs = DB::table('failed_jobs')->get();
            $count = 0;

            foreach ($failedJobs as $job) {
                DB::table('jobs')->insert([
                    'queue' => $job->queue,
                    'payload' => $job->payload,
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'created_at' => now()->timestamp,
                ]);
                $count++;
            }

            DB::table('failed_jobs')->truncate();

            return response()->json([
                'message' => "{$count} jobs queued for retry",
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Flush all failed jobs.
     */
    #[CrossTenantRoute(reason: 'Super-admin queue operation: flushes (truncates) the platform-level failed_jobs queue without retrying; destructive operation; super_admin gated.')]
    public function flushFailedJobs(): JsonResponse
    {
        try {
            $count = DB::table('failed_jobs')->count();
            DB::table('failed_jobs')->truncate();

            return response()->json([
                'message' => "{$count} failed jobs deleted",
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Test Sentry error tracking by triggering a test exception.
     */
    #[CrossTenantRoute(reason: 'Super-admin Sentry probe: throws a controlled RuntimeException to validate the Sentry error-tracking pipeline; production-environment-blocked (returns 403 in prod); no DB or tenant interaction.')]
    public function testSentry(): JsonResponse
    {
        if (config('app.env') === 'production') {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Cannot trigger test errors in production',
                ],
            ], 403);
        }

        // This will be caught by Sentry
        throw new \RuntimeException('Sentry test exception - this is a test error from the admin dashboard');
    }
}
