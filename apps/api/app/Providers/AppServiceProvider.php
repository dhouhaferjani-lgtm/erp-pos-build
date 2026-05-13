<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Observers\JournalEntryObserver;
use App\Modules\Accounting\Domain\Observers\JournalLineObserver;
use App\Modules\Company\Application\Services\LocationService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Partner\Application\Services\PartnerService;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Infrastructure\Services\ProductEnrichmentQueryService;
use App\Modules\Product\Infrastructure\Services\ProductInventoryQueryService;
use App\Modules\Tenant\Domain\Tenant;
use App\Observers\TenantObserver;
use App\Observers\UserObserver;
use App\Services\CompanyConfigService;
use App\Services\ProductService as AppProductService;
use App\Services\VerticalConfigService;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Contracts\ProductInventoryQueryInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register CompanyContext as a singleton so it maintains state across the request
        $this->app->singleton(CompanyContext::class);

        // Register LocationContext as a singleton for location-scoped operations
        $this->app->singleton(LocationContext::class);

        // Register ProductService (multi-app vertical system) as singleton
        $this->app->singleton(AppProductService::class);

        // Register VerticalConfigService (vertical configuration) as singleton
        $this->app->singleton(VerticalConfigService::class);

        // Register CompanyConfigService (company configuration) as singleton
        $this->app->singleton(CompanyConfigService::class);

        // Register currency scale resolver as singleton
        $this->app->singleton(CurrencyScaleResolverInterface::class, function ($app): CurrencyScaleResolver {
            return new CurrencyScaleResolver(
                $app->make(CompanyContext::class),
                fn (string $countryCode): ?Country => Country::find($countryCode),
            );
        });

        // Register cross-module service interfaces
        $this->app->bind(PartnerServiceInterface::class, PartnerService::class);
        $this->app->bind(ProductServiceInterface::class, ProductService::class);
        $this->app->bind(InventoryServiceInterface::class, InventoryService::class);
        $this->app->bind(LocationServiceInterface::class, LocationService::class);
        $this->app->bind(AccountingServiceInterface::class, AccountingService::class);
        $this->app->bind(PlatformSubmissionInterface::class, ProductSubmissionService::class);
        $this->app->bind(EnrichmentQueryInterface::class, ProductEnrichmentQueryService::class);
        $this->app->bind(ProductInventoryQueryInterface::class, ProductInventoryQueryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            return Password::min(10)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();
        });

        $this->guardProductionCorsConfig();

        $this->configureRateLimiting();

        // Register journal entry immutability observers
        JournalEntry::observe(JournalEntryObserver::class);
        JournalLine::observe(JournalLineObserver::class);

        // Register tenant observer for cache invalidation + Sanctum token revocation
        // (api.auth-permissions Invariants A.1 + A.2)
        Tenant::observe(TenantObserver::class);

        // Register user observer for Sanctum token revocation on tenant_id change
        // (api.auth-permissions Invariant A.3 — forward-compat defense; no production
        // endpoint mutates users.tenant_id today)
        User::observe(UserObserver::class);

        // T1.4 — Sanctum's default Guard::isValidAccessToken ANDs the
        // global SANCTUM_TOKEN_EXPIRATION (30 days) with the per-token
        // `expires_at` column. So even when AuthController issues a POS
        // terminal token with `expires_at = now()->addYear()`, the
        // global 30-day TTL still rejects the token after 30 days,
        // defeating the 12-month POS lifetime contract.
        //
        // Override the validation: when a token has a per-row `expires_at`,
        // that takes precedence over the global TTL. Tokens without a
        // per-row expiry (the web back-office default) keep the existing
        // global-TTL behaviour. The provider check (tokenable still
        // exists) is preserved on both branches.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if ($token->expires_at !== null) {
                return ! $token->expires_at->isPast() && $token->tokenable !== null;
            }

            return $isValid;
        });
    }

    /**
     * Fail-fast guard against the unsafe CORS combination
     *   APP_ENV=production + supports_credentials=true + allowed_origins=['*'].
     *
     * Browsers refuse to send credentials when the response advertises the
     * wildcard origin, so the runtime symptom is "authenticated API calls
     * silently break in production". Throwing at boot surfaces the
     * misconfiguration before deploy. dev-remediation/M1.8 documents the
     * gate.
     */
    private function guardProductionCorsConfig(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $origins = (array) config('cors.allowed_origins', []);
        $supportsCredentials = (bool) config('cors.supports_credentials', false);

        if ($supportsCredentials && in_array('*', $origins, true)) {
            throw new \RuntimeException(
                'Unsafe CORS configuration: CORS_ALLOWED_ORIGINS=* combined with '
                .'supports_credentials=true in production. Enumerate explicit origins '
                .'in CORS_ALLOWED_ORIGINS or set supports_credentials=false.',
            );
        }
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

        // Product image upload - 20 per minute per user/IP (prevent abuse)
        RateLimiter::for('image-upload', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perMinute(20)->by((string) $key);
        });

        // Public product image access - 100 per minute per IP (prevent scraping)
        RateLimiter::for('public-product-images', function (Request $request): Limit {
            return Limit::perMinute(100)->by($request->ip() ?? 'unknown');
        });

        // Storefront appointment booking - 10 requests per minute per IP + company pair.
        // The {company_id} route parameter is the companies.uuid primary key.
        RateLimiter::for('storefront-booking-ip', function (Request $request): Limit {
            $ip = $request->ip() ?? 'unknown';
            $companyId = (string) ($request->route('company_id') ?? 'none');

            return Limit::perMinute(10)->by($ip.'|'.$companyId);
        });

        // Storefront appointment booking - daily cap keyed on company_id + sha256(phone).
        // 5 bookings per calendar day per (company, phone). Phones are hashed so the
        // cache key space does not leak PII. When no phone is supplied we fall back
        // to the IP address so the rule still throttles anonymous traffic.
        RateLimiter::for('storefront-booking-company-phone', function (Request $request): Limit {
            $companyId = (string) ($request->route('company_id') ?? 'none');
            $phone = trim((string) $request->input('phone', ''));
            $digest = $phone !== ''
                ? hash('sha256', $phone)
                : ('ip:'.($request->ip() ?? 'unknown'));

            return Limit::perDay(5)->by($companyId.'|'.$digest);
        });
    }
}
