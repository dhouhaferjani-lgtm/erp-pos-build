<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce module access control based on vertical configuration.
 *
 * This middleware checks if the current tenant has access to a specific module
 * based on their vertical and enabled extras. If the module is not enabled,
 * a 403 Forbidden response is returned.
 *
 * Usage:
 * Route::middleware(['api', 'auth:sanctum', 'module:Vehicle'])
 *
 * The module parameter should match the module name exactly (case-sensitive).
 */
class RequireModule
{
    public function __construct(
        private readonly CompanyConfigService $configService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $module  The module name to require (e.g., 'Vehicle', 'Workshop')
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Get authenticated user (already verified by auth:sanctum middleware)
        $user = $request->user();

        if ($user === null) {
            throw new \RuntimeException('User must be authenticated to check module access');
        }

        if (! $user instanceof \App\Modules\Identity\Domain\User) {
            throw new \RuntimeException('Module access requires a tenant user, not a super admin');
        }

        // Get tenant from authenticated user
        $tenant = $user->tenant;

        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant not found for authenticated user');
        }

        // Get effective configuration for this tenant
        $config = $this->configService->getConfigForTenant($tenant);

        // Check if the required module is enabled
        if (! $config->hasModule($module)) {
            abort(403, "Module '{$module}' is not enabled for this business type");
        }

        return $next($request);
    }
}
