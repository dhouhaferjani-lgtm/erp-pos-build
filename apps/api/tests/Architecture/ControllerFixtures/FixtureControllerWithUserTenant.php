<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

/**
 * Positive-control fixture for ControllerTenantContextTest, branch (b).
 *
 * The handle() method body contains `tenant_id` literally so the
 * heuristic accepts it. Mirrors the SubscriptionController-style pattern
 * where the user's tenant is read directly from the User model.
 */
final class FixtureControllerWithUserTenant
{
    public function handle(object $user): array
    {
        $tenantId = $user->tenant_id;

        return ['tenant_id' => $tenantId];
    }
}
