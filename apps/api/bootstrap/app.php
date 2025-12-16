<?php

use App\Http\Middleware\CompanyContextMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetLocale;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
        ]);

        // Apply SetLocale and CompanyContext middleware to all API requests
        $middleware->appendToGroup('api', [
            SetLocale::class,
            CompanyContextMiddleware::class,
        ]);

        // Ensure API requests get JSON responses for auth failures
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null; // Don't redirect, let exception handler deal with it
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry integration with context enrichment
        Integration::handles($exceptions);

        // Add custom context to Sentry errors
        $exceptions->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                \Sentry\configureScope(function (Scope $scope): void {
                    // Add tenant context
                    if (app()->bound(CompanyContext::class)) {
                        $companyContext = app(CompanyContext::class);
                        if ($companyId = $companyContext->getCompanyId()) {
                            $scope->setTag('company_id', $companyId);
                        }
                    }

                    // Add user context
                    $user = auth()->user();
                    if ($user instanceof User) {
                        $scope->setUser([
                            'id' => $user->id,
                            'email' => $user->email,
                            'tenant_id' => $user->tenant_id,
                        ]);
                        $scope->setTag('tenant_id', $user->tenant_id);
                    }

                    // Add request context
                    if (! app()->runningInConsole()) {
                        $scope->setExtra('request_id', request()->header('X-Request-ID'));
                        $scope->setExtra('url', request()->fullUrl());
                        $scope->setExtra('method', request()->method());
                    }
                });
            }
        });

        // Return JSON 401 for API authentication failures
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => __('auth.unauthenticated'),
                    ],
                ], 401);
            }
        });
    })->create();
