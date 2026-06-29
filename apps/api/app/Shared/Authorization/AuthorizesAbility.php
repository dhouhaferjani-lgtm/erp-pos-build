<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Exceptions\PermissionDeniedException;
use Illuminate\Support\Facades\Gate;

/**
 * Authorize a Gate ability while preserving the ability name for the response.
 *
 * Use instead of raw `Gate::authorize($ability)` when the denial should produce
 * a descriptive 403 that names the missing ability (see the AuthorizationException
 * render in bootstrap/app.php).
 */
trait AuthorizesAbility
{
    protected function authorizeAbility(string $ability): void
    {
        if (! Gate::allows($ability)) {
            throw new PermissionDeniedException($ability);
        }
    }
}
