<?php

use App\Http\Middleware\CompanyContextMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ValidateLocationAccess;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
            'validate.location.access' => ValidateLocationAccess::class,
            'module' => RequireModule::class,
        ]);

        // Enable CORS handling FIRST (must run before other middleware)
        $middleware->prependToGroup('api', [
            CorsMiddleware::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->appendToGroup('api', [
            SecurityHeaders::class,
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

        // Return JSON 404 for model not found
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $modelName = class_basename($e->getModel());

                return response()->json([
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => __('messages.resource_not_found', ['resource' => $modelName]),
                    ],
                ], 404);
            }
        });

        // Return JSON 422 for validation errors with structured format
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => $e->getMessage(),
                        'errors' => $e->errors(),
                    ],
                ], 422);
            }
        });

        // Return JSON 422 for domain/business logic errors
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'BUSINESS_ERROR',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });
    })->create();
