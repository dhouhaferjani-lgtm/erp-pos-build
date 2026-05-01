<?php

use App\Http\Middleware\CompanyContextMiddleware;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ValidateLocationAccess;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\DailyRefundCapExceededException;
use App\Modules\POS\Domain\Exceptions\ManagerOverrideRequiredException;
use App\Modules\POS\Domain\Exceptions\RefundDestinationNotAllowedException;
use App\Modules\POS\Domain\Exceptions\RefundWindowClosedException;
use App\Modules\Scheduling\Infrastructure\Http\Middleware\VerifyCaptcha;
use App\Modules\Voucher\Domain\Exceptions\VoucherDuplicateInTransactionException;
use App\Modules\Voucher\Domain\Exceptions\VoucherExpiredException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInsufficientBalanceException;
use App\Modules\Voucher\Domain\Exceptions\VoucherInvalidStatusException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisCustomerException;
use App\Modules\Voucher\Domain\Exceptions\VoucherNotForThisTerminalException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
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
            'validate.location.access' => ValidateLocationAccess::class,
            'module' => RequireModule::class,
            'scheduling.captcha' => VerifyCaptcha::class,
        ]);

        // Exclude auth endpoints from CSRF verification for token-based clients
        // (desktop apps, mobile apps) that don't use browser cookies
        $middleware->validateCsrfTokens(except: [
            'api/v1/auth/login',
            'api/v1/auth/register',
            'broadcasting/auth',
        ]);

        $middleware->prependToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
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

        // Return JSON 422 for POS refund-flow exceptions — typed codes so the
        // frontend (RefundConfirmModal / refundConfirmation.ts) can route to the
        // correct inline UI (manager PIN panel, cap message, window-closed notice,
        // destination-blocked notice) rather than showing a generic toast.
        // These MUST be registered before the generic DomainException handler
        // because three of them extend \DomainException and Laravel 11 closures
        // match in registration order (first match wins).
        $exceptions->render(function (ManagerOverrideRequiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'MANAGER_OVERRIDE_REQUIRED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (DailyRefundCapExceededException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'DAILY_REFUND_CAP_EXCEEDED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (RefundWindowClosedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'REFUND_WINDOW_CLOSED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // RefundDestinationNotAllowedException extends \RuntimeException (not
        // \DomainException), so without this handler it would bubble to a 500.
        $exceptions->render(function (RefundDestinationNotAllowedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'REFUND_DESTINATION_NOT_ALLOWED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        // Codex review B5 (2026-05-01): Voucher redemption exceptions extend
        // \RuntimeException (not \DomainException), so without these handlers
        // the new ReceiptPaymentService::processReceiptPayments call to
        // VoucherRedemptionService::redeem would bubble to 500 on the
        // online checkout path. Map each to a typed 422 with a stable
        // error.code so the POS UI can surface clear cashier messages
        // (e.g. "Voucher not found", "Insufficient balance"). The codes
        // are deliberately distinct rather than collapsing to a single
        // BUSINESS_ERROR — the cashier needs different recovery actions
        // for "wrong code" vs. "voucher empty" vs. "wrong terminal."
        $exceptions->render(function (VoucherInvalidStatusException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_INVALID_STATUS',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherInsufficientBalanceException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_INSUFFICIENT_BALANCE',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherExpiredException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_EXPIRED',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherNotForThisTerminalException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_WRONG_TERMINAL',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherNotForThisCustomerException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_WRONG_CUSTOMER',
                        'message' => $e->getMessage(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (VoucherDuplicateInTransactionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'VOUCHER_DUPLICATE_IN_TRANSACTION',
                        'message' => $e->getMessage(),
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
