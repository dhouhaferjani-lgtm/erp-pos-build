<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A permission denial that carries the missing ability name.
 *
 * Raw `Gate::authorize('x')` throws a vanilla AuthorizationException whose
 * ability is not recoverable from the exception. Throwing this instead (via
 * the AuthorizesAbility trait) lets the global handler surface the exact
 * ability + a remediation hint, so onboarding tenants never hit a bare 403.
 */
final class PermissionDeniedException extends AuthorizationException
{
    public function __construct(public readonly ?string $ability = null)
    {
        parent::__construct('This action is unauthorized.');
    }
}
