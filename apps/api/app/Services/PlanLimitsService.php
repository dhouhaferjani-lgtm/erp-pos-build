<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TenantSubscription;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;

class PlanLimitsService
{
    /**
     * Check if tenant is within limit for a resource.
     */
    public function checkLimit(Tenant $tenant, string $resource): bool
    {
        $subscription = $this->getActiveSubscription($tenant);

        if ($subscription === null) {
            return false;
        }

        $plan = $subscription->plan;

        if ($plan === null) {
            return false;
        }

        /** @var array<string, mixed> $limits */
        $limits = $plan->limits;
        $usage = $this->getUsage($tenant);

        $limitKey = 'max_'.$resource;

        if (! isset($limits[$limitKey])) {
            return true; // No limit defined = unlimited
        }

        $currentUsage = $usage[$resource] ?? 0;
        $maxAllowed = $limits[$limitKey];

        return $currentUsage < $maxAllowed;
    }

    /**
     * Enforce limit - throw exception if exceeded.
     *
     * @throws \RuntimeException
     */
    public function enforceLimit(Tenant $tenant, string $resource): void
    {
        if (! $this->checkLimit($tenant, $resource)) {
            $subscription = $this->getActiveSubscription($tenant);
            /** @var array<string, mixed> $limits */
            $limits = $subscription !== null && $subscription->plan !== null ? $subscription->plan->limits : [];
            $limitKey = 'max_'.$resource;
            $maxAllowed = $limits[$limitKey] ?? 0;

            throw new \RuntimeException(
                "Plan limit exceeded. Maximum {$resource}: {$maxAllowed}. Please upgrade your plan."
            );
        }
    }

    /**
     * Get current usage statistics for tenant.
     *
     * @return array<string, int>
     */
    public function getUsage(Tenant $tenant): array
    {
        return [
            'companies' => DB::table('companies')
                ->where('tenant_id', $tenant->id)
                ->count(),
            'locations' => DB::table('locations')
                ->where('tenant_id', $tenant->id)
                ->count(),
            'users' => DB::table('users')
                ->where('tenant_id', $tenant->id)
                ->count(),
        ];
    }

    /**
     * Get active subscription for tenant.
     */
    private function getActiveSubscription(Tenant $tenant): ?TenantSubscription
    {
        return TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['trial', 'active'])
            ->with('plan')
            ->first();
    }

    /**
     * Get subscription with plan limits.
     *
     * @return array{subscription: TenantSubscription|null, usage: array<string, int>, limits: array<string, mixed>}
     */
    public function getSubscriptionInfo(Tenant $tenant): array
    {
        $subscription = $this->getActiveSubscription($tenant);
        $usage = $this->getUsage($tenant);
        /** @var array<string, mixed> $limits */
        $limits = $subscription !== null && $subscription->plan !== null ? $subscription->plan->limits : [];

        return [
            'subscription' => $subscription,
            'usage' => $usage,
            'limits' => $limits,
        ];
    }
}
