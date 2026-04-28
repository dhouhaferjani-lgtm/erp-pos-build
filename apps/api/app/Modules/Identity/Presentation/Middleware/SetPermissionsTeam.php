<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Middleware;

use App\Modules\Identity\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the Spatie permission team context based on the authenticated user's tenant.
 */
class SetPermissionsTeam
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            setPermissionsTeamId($user->tenant_id);
        }

        return $next($request);
    }
}
