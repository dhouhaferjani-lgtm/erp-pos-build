<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Contracts\AbilityAuthorizerInterface;
use App\Shared\Exceptions\PermissionDeniedException;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The real {@see AbilityAuthorizerInterface}: Laravel's Gate.
 *
 * Injects the Gate CONTRACT rather than calling the facade, so the authorizer itself
 * is unit-testable and the dependency is visible.
 */
final class GateAbilityAuthorizer implements AbilityAuthorizerInterface
{
    public function __construct(
        private readonly Gate $gate,
    ) {}

    public function authorize(string $ability): void
    {
        if (! $this->gate->allows($ability)) {
            throw new PermissionDeniedException($ability);
        }
    }

    public function allows(string $ability): bool
    {
        return $this->gate->allows($ability);
    }
}
