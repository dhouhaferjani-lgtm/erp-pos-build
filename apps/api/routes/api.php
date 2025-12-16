<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\SuperAdminAuthController;
use App\Http\Controllers\Api\Admin\SuperAdminController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Modules\Admin\Presentation\Controllers\MonitoringController;
use App\Modules\Billing\Presentation\Controllers\AdminBillingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // Public health check (for load balancers - no auth required)
    Route::get('/health', [MonitoringController::class, 'ping']);

    // Public routes
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{code}', [CountryController::class, 'show']);

    // Super admin authentication routes
    Route::prefix('admin/auth')->group(function (): void {
        // Login is public but rate-limited
        Route::post('/login', [SuperAdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login');

        // Logout and profile require super admin authentication
        Route::middleware(['auth:sanctum', 'super_admin'])->group(function (): void {
            Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
            Route::get('/me', [SuperAdminAuthController::class, 'me']);
        });
    });

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/subscription', [SubscriptionController::class, 'show']);
    });

    // Super admin routes - require authenticated super admin with rate limiting
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'super_admin', 'throttle:admin-sensitive'])
        ->group(function (): void {
            // Dashboard and tenant management
            Route::get('/dashboard', [SuperAdminController::class, 'dashboard']);
            Route::get('/tenants', [SuperAdminController::class, 'tenants']);
            Route::get('/tenants/{id}', [SuperAdminController::class, 'showTenant']);
            Route::post('/tenants/{id}/extend-trial', [SuperAdminController::class, 'extendTrial']);
            Route::post('/tenants/{id}/change-plan', [SuperAdminController::class, 'changePlan']);
            Route::post('/tenants/{id}/suspend', [SuperAdminController::class, 'suspendTenant']);
            Route::post('/tenants/{id}/activate', [SuperAdminController::class, 'activateTenant']);
            Route::get('/audit-logs', [SuperAdminController::class, 'auditLogs']);

            // Monitoring endpoints
            Route::get('/monitoring/health', [MonitoringController::class, 'health']);
            Route::post('/monitoring/test-sentry', [MonitoringController::class, 'testSentry']);

            // Billing management
            Route::prefix('billing')->group(function (): void {
                Route::get('/dashboard', [AdminBillingController::class, 'dashboard']);
                Route::get('/providers', [AdminBillingController::class, 'providers']);

                // Plans
                Route::get('/plans', [AdminBillingController::class, 'listPlans']);

                // Subscriptions
                Route::get('/subscriptions', [AdminBillingController::class, 'listSubscriptions']);
                Route::get('/subscriptions/{id}', [AdminBillingController::class, 'getSubscription']);
                Route::patch('/subscriptions/{id}', [AdminBillingController::class, 'updateSubscription']);

                // Invoices
                Route::get('/invoices', [AdminBillingController::class, 'listInvoices']);
                Route::post('/invoices', [AdminBillingController::class, 'createInvoice']);
                Route::get('/invoices/{id}', [AdminBillingController::class, 'getInvoice']);
                Route::get('/invoices/{id}/download', [AdminBillingController::class, 'downloadInvoice']);

                // Payments
                Route::get('/payments', [AdminBillingController::class, 'listPayments']);
                Route::post('/payments', [AdminBillingController::class, 'recordPayment']);
                Route::get('/payments/{id}', [AdminBillingController::class, 'getPayment']);
                Route::post('/payments/{id}/refund', [AdminBillingController::class, 'refundPayment']);
            });
        });
});
