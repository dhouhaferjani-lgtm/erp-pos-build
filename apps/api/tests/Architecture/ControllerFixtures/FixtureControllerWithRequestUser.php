<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

use Illuminate\Http\Request;

/**
 * Positive-control fixture for ControllerTenantContextTest, branch (b).
 *
 * The handle() method body contains `$request->user` literally so the
 * heuristic accepts it. Mirrors the AuthController-style pattern of
 * reading the actor's own row from the request.
 */
final class FixtureControllerWithRequestUser
{
    public function handle(Request $request): array
    {
        $user = $request->user();

        return ['user_id' => $user?->id];
    }
}
