<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Architecture;

use App\Http\Middleware\CrossTenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Tests\TestCase;

class CrossTenantContextMiddlewareTest extends TestCase
{
    public function test_middleware_marks_request_as_cross_tenant(): void
    {
        $middleware = new CrossTenantContext;
        $request = Request::create('/dummy', 'GET');

        $this->assertFalse($request->attributes->getBoolean('cross_tenant'));

        $next = function (Request $passed): Response {
            $this->assertTrue($passed->attributes->getBoolean('cross_tenant'));

            return new Response('ok');
        };

        $result = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('ok', $result->getContent());
        $this->assertTrue($request->attributes->getBoolean('cross_tenant'));
    }

    public function test_alias_resolves_to_middleware_class(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $aliases = $router->getMiddleware();

        $this->assertArrayHasKey('cross_tenant', $aliases,
            'bootstrap/app.php must register the cross_tenant alias for routes that opt out of tenant scoping.');
        $this->assertSame(CrossTenantContext::class, $aliases['cross_tenant']);
    }
}
