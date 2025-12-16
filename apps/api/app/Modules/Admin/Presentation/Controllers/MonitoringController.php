<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation\Controllers;

use App\Modules\Admin\Application\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class MonitoringController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $healthService,
    ) {}

    /**
     * Simple health check for load balancers.
     * Returns 200 if healthy, 503 if unhealthy.
     */
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
    public function health(): JsonResponse
    {
        $status = $this->healthService->check();

        return response()->json([
            'data' => $status,
        ], $status->healthy ? 200 : 503);
    }

    /**
     * Test Sentry error tracking by triggering a test exception.
     */
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
