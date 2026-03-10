<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use App\Services\PlanLimitsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PlanLimitsService $limitsService
    ) {}

    /**
     * Get current tenant's subscription information.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, 'User must be authenticated');
        }

        $tenant = $user->tenant;

        if ($tenant === null) {
            abort(500, 'Tenant not found for authenticated user');
        }

        $info = $this->limitsService->getSubscriptionInfo($tenant);

        return response()->json([
            'data' => [
                'subscription' => $info['subscription'],
                'usage' => $info['usage'],
                'limits' => $info['limits'],
                'trial_days_remaining' => $info['subscription']?->trialDaysRemaining() ?? 0,
            ],
        ]);
    }
}
