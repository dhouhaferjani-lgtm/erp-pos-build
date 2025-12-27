<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Observers\JournalEntryObserver;
use App\Modules\Accounting\Domain\Observers\JournalLineObserver;
use App\Modules\Company\Application\Services\LocationService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Partner\Application\Services\PartnerService;
use App\Modules\Product\Application\Services\ProductService;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductServiceInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register CompanyContext as a singleton so it maintains state across the request
        $this->app->singleton(CompanyContext::class);

        // Register cross-module service interfaces
        $this->app->bind(PartnerServiceInterface::class, PartnerService::class);
        $this->app->bind(ProductServiceInterface::class, ProductService::class);
        $this->app->bind(InventoryServiceInterface::class, InventoryService::class);
        $this->app->bind(LocationServiceInterface::class, LocationService::class);
        $this->app->bind(AccountingServiceInterface::class, AccountingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Register journal entry immutability observers
        JournalEntry::observe(JournalEntryObserver::class);
        JournalLine::observe(JournalLineObserver::class);
    }

    /**
     * Configure rate limiters for the application.
     */
    private function configureRateLimiting(): void
    {
        // Rate limiter for admin login (strict - 5 attempts per minute per IP)
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip() ?? 'unknown');
        });

        // Rate limiter for admin sensitive operations (30 per minute per user)
        RateLimiter::for('admin-sensitive', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perMinute(30)->by((string) $key);
        });

        // User login - 5 attempts per minute per email/IP
        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email', '');

            return Limit::perMinute(5)->by($email ?: ($request->ip() ?? 'unknown'));
        });

        // Registration - 5 per 15 minutes per IP (prevent mass account creation)
        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinutes(15, 5)->by($request->ip() ?? 'unknown');
        });

        // Password reset - 3 per hour per email (prevent enumeration)
        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = $request->input('email', '');

            return Limit::perHour(3)->by($email ?: ($request->ip() ?? 'unknown'));
        });

        // Email verification - 5 per hour per user
        RateLimiter::for('email-verification', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perHour(5)->by((string) $key);
        });

        // General API - 100 requests per minute per user/IP
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perMinute(100)->by((string) $key);
        });
    }
}
