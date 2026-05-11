<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

use App\Shared\Architecture\CrossTenantRoute;

/**
 * Positive-control fixture for ControllerTenantContextTest, branch (a).
 *
 * The handle() method carries #[CrossTenantRoute] with a non-blank reason.
 * The classifier MUST accept it.
 */
final class FixtureControllerWithAttribute
{
    #[CrossTenantRoute(reason: 'Fixture: cross-tenant by design for test pinning.')]
    public function handle(): array
    {
        return ['ok' => true];
    }
}
