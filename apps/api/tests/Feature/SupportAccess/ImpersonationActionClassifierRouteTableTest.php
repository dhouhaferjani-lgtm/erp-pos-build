<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Modules\SupportAccess\Domain\Enums\ImpersonationActionDecision;
use App\Modules\SupportAccess\Domain\Services\ImpersonationActionClassifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class ImpersonationActionClassifierRouteTableTest extends TestCase
{
    public function test_every_registered_pos_fiscal_and_accounting_write_route_is_hard_blocked(): void
    {
        $classifier = $this->app->make(ImpersonationActionClassifier::class);
        $misses = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route || ! $this->isProtectedMutation($route)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $request = Request::create('/'.$route->uri(), $method);
                $request->setRouteResolver(static fn (): Route => $route);

                if ($classifier->classify($request) !== ImpersonationActionDecision::HardBlocked) {
                    $misses[] = "{$method} /{$route->uri()} ({$route->getActionName()})";
                }
            }
        }

        self::assertSame([], $misses, "Protected write routes escaped the impersonation hard block:\n".implode("\n", $misses));
    }

    private function isProtectedMutation(Route $route): bool
    {
        $action = $route->getActionName();
        foreach (['\\Modules\\POS\\', '\\Modules\\Fiscal\\', '\\Modules\\Accounting\\'] as $namespace) {
            if (str_contains($action, $namespace)) {
                return true;
            }
        }

        $name = $route->getName() ?? '';
        foreach (['users.*', 'roles.*', 'auth.forgot-password', 'auth.reset-password',
            'tenants.*delete*', 'tenants.*deprovision*'] as $pattern) {
            if (fnmatch($pattern, $name, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
