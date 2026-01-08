<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\DTOs\CompanyConfig;
use App\Enums\Vertical;
use App\Http\Middleware\RequireModule;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RequireModuleTest extends TestCase
{
    private RequireModule $middleware;

    private CompanyConfigService $configService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the CompanyConfigService
        $this->configService = Mockery::mock(CompanyConfigService::class);
        $this->middleware = new RequireModule($this->configService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock request with authenticated user and tenant.
     */
    private function createAuthenticatedRequest(Tenant $tenant, string $uri = '/api/v1/test'): Request
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldAllowMockingProtectedMethods();
        $user->tenant = $tenant;

        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_allows_request_when_module_is_enabled(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/vehicles');

        $config = new CompanyConfig(
            vertical: Vertical::Mechanic,
            defaultModules: ['Identity', 'Vehicle', 'Workshop'],
            enabledExtras: ['Appointments'],
            allEnabledModules: ['Identity', 'Vehicle', 'Workshop', 'Appointments']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $response = $this->middleware->handle($request, $next, 'Vehicle');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_blocks_request_when_module_is_not_enabled(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/work-orders');

        $config = new CompanyConfig(
            vertical: Vertical::Retail,
            defaultModules: ['Identity', 'Catalog', 'Inventory'],
            enabledExtras: [],
            allEnabledModules: ['Identity', 'Catalog', 'Inventory']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Module 'Workshop' is not enabled for this business type");

        try {
            $this->middleware->handle($request, $next, 'Workshop');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_allows_request_when_module_is_in_default_modules(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/products');

        $config = new CompanyConfig(
            vertical: Vertical::Pharmacy,
            defaultModules: ['Identity', 'Catalog', 'BatchExpiry'],
            enabledExtras: [],
            allEnabledModules: ['Identity', 'Catalog', 'BatchExpiry']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $response = $this->middleware->handle($request, $next, 'BatchExpiry');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_allows_request_when_module_is_in_enabled_extras(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/appointments');

        $config = new CompanyConfig(
            vertical: Vertical::Mechanic,
            defaultModules: ['Identity', 'Vehicle', 'Workshop'],
            enabledExtras: ['Appointments', 'Fleet'],
            allEnabledModules: ['Identity', 'Vehicle', 'Workshop', 'Appointments', 'Fleet']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $response = $this->middleware->handle($request, $next, 'Appointments');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_blocks_request_for_disabled_extra(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/fleet');

        $config = new CompanyConfig(
            vertical: Vertical::Mechanic,
            defaultModules: ['Identity', 'Vehicle', 'Workshop'],
            enabledExtras: ['Appointments'], // Fleet is NOT enabled
            allEnabledModules: ['Identity', 'Vehicle', 'Workshop', 'Appointments']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Module 'Fleet' is not enabled for this business type");

        try {
            $this->middleware->handle($request, $next, 'Fleet');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_throws_exception_when_user_not_authenticated(): void
    {
        $request = Request::create('/api/v1/vehicles', 'GET');
        // No user resolver set - user() will return null

        $next = function ($request) {
            return new Response('OK', 200);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User must be authenticated to check module access');

        $this->middleware->handle($request, $next, 'Vehicle');
    }

    public function test_module_check_is_case_sensitive(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/vehicles');

        $config = new CompanyConfig(
            vertical: Vertical::Mechanic,
            defaultModules: ['Identity', 'Vehicle', 'Workshop'],
            enabledExtras: [],
            allEnabledModules: ['Identity', 'Vehicle', 'Workshop']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        // Lowercase 'vehicle' should NOT match 'Vehicle'
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Module 'vehicle' is not enabled for this business type");

        $this->middleware->handle($request, $next, 'vehicle');
    }

    public function test_core_modules_are_always_accessible(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $request = $this->createAuthenticatedRequest($tenant, '/api/v1/companies');

        $config = new CompanyConfig(
            vertical: Vertical::Retail,
            defaultModules: ['Identity', 'Tenant', 'Catalog', 'Inventory'],
            enabledExtras: [],
            allEnabledModules: ['Identity', 'Tenant', 'Catalog', 'Inventory']
        );

        $this->configService
            ->shouldReceive('getConfigForTenant')
            ->once()
            ->with($tenant)
            ->andReturn($config);

        $next = function ($request) {
            return new Response('OK', 200);
        };

        // Core modules like Identity, Tenant should be accessible
        $response = $this->middleware->handle($request, $next, 'Identity');

        $this->assertEquals(200, $response->getStatusCode());
    }
}
