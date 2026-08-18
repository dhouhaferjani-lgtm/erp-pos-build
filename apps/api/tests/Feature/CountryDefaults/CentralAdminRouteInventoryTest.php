<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CentralAdminRouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_defaults_routes_have_the_exact_central_admin_capability_layers(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin/country-defaults'));

        self::assertCount(16, $routes);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            self::assertContains('api', $middleware, $route->uri());
            self::assertContains('auth:sanctum-admin', $middleware, $route->uri());
            self::assertContains('central_admin', $middleware, $route->uri());
            self::assertContains('central_admin_role:super_admin,defaults_editor', $middleware, $route->uri());
            self::assertContains('throttle:admin-sensitive', $middleware, $route->uri());

            if (str_contains($route->uri(), '/editors')) {
                self::assertContains('central_admin_role:super_admin', $middleware, $route->uri());
            }
        }
    }

    public function test_full_admin_routes_keep_the_exact_original_middleware_list(): void
    {
        $fullAdminRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin/'))
            ->filter(static fn (Route $route): bool => in_array('super_admin', $route->gatherMiddleware(), true));

        self::assertNotEmpty($fullAdminRoutes);
        foreach ($fullAdminRoutes as $route) {
            self::assertSame(
                ['api', 'auth:sanctum-admin', 'super_admin', 'throttle:admin-sensitive'],
                $route->gatherMiddleware(),
                $route->uri(),
            );
        }
    }

    public function test_defaults_editor_is_denied_from_every_admin_route_except_explicit_central_admin_allowances(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['country_defaults.external_editors_enabled' => true]);
        $editor = $this->admin('defaults_editor');
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin/'))
            ->reject(static fn (Route $route): bool => $route->uri() === 'api/v1/admin/auth/login')
            ->reject(static fn (Route $route): bool => in_array($route->uri(), [
                'api/v1/admin/auth/me',
                'api/v1/admin/auth/logout',
            ], true));

        foreach ($routes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', Str::uuid()->toString(), '/'.$route->uri());
            self::assertIsString($uri);
            $method = collect($route->methods())->first(static fn (string $candidate): bool => $candidate !== 'HEAD');
            self::assertIsString($method);

            $response = $this->actingAs($editor, 'sanctum-admin')->json($method, $uri);
            $isAllowedCountryDefaults = str_starts_with($route->uri(), 'api/v1/admin/country-defaults/')
                && ! str_contains($route->uri(), '/editors');

            if ($isAllowedCountryDefaults) {
                self::assertNotSame(403, $response->getStatusCode(), "{$method} {$route->uri()} must be allowed");
            } else {
                self::assertSame(403, $response->getStatusCode(), "{$method} {$route->uri()} must be forbidden");
            }
        }
    }

    private function admin(string $role): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Route inventory actor',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
