<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Country;
use App\Models\VerticalConfig;
use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Observers\JournalEntryObserver;
use App\Modules\Accounting\Domain\Observers\JournalLineObserver;
use App\Modules\Accounting\Infrastructure\Adapters\FiscalPeriodLockReader;
use App\Modules\Company\Application\Services\LocationService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Partner\Application\Services\PartnerService;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Infrastructure\Services\ProductEnrichmentCorrelationService;
use App\Modules\Product\Infrastructure\Services\ProductEnrichmentQueryService;
use App\Modules\Product\Infrastructure\Services\ProductInventoryQueryService;
use App\Modules\Tenant\Domain\Tenant;
use App\Observers\TenantObserver;
use App\Observers\UserObserver;
use App\Observers\VerticalConfigObserver;
use App\Policies\DocumentPolicy;
use App\Policies\ExpenseCategoryPolicy;
use App\Services\CompanyConfigService;
use App\Services\ProductService as AppProductService;
use App\Services\VerticalConfigService;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Banking\Domain\BankAccountValidator;
use App\Shared\Contracts\AbilityAuthorizerInterface;
use App\Shared\Contracts\Accounting\DocumentGlPreflightInterface;
use App\Shared\Contracts\Accounting\DocumentGlReversalInterface;
use App\Shared\Contracts\Accounting\FiscalPeriodLockReaderInterface;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CatalogLookupInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\Contracts\EnrichmentSubmissionCorrelatorInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Contracts\ProductInventoryQueryInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use App\Shared\Infrastructure\GateAbilityAuthorizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $this->app->bind(DocumentGlPreflightInterface::class, AccountingService::class);
        $this->app->bind(DocumentGlReversalInterface::class, AccountingService::class);
        $this->app->bind(PlatformSubmissionInterface::class, ProductSubmissionService::class);
        $this->app->bind(CatalogLookupInterface::class, BarcodeLookupService::class);
        $this->app->bind(EnrichmentQueryInterface::class, ProductEnrichmentQueryService::class);
        $this->app->bind(EnrichmentSubmissionCorrelatorInterface::class, ProductEnrichmentCorrelationService::class);
        $this->app->bind(ProductInventoryQueryInterface::class, ProductInventoryQueryService::class);
        $this->app->bind(BankAccountValidatorInterface::class, BankAccountValidator::class);

        // Plan CF CF-D3. Taxation's return-note backdating guard has to key on
        // BOTH period tables, and `fiscal_periods` is Accounting's — so the
        // fiscal-period verdict crosses the module boundary through this Shared
        // contract rather than through `FiscalPeriodResolverService` directly
        // (rule 6, and Taxation/Application → Accounting/Application is a deptrac
        // layer violation besides).
        $this->app->bind(FiscalPeriodLockReaderInterface::class, FiscalPeriodLockReader::class);

        // Plan CF CF-D8. Domain services that must authorize a per-leg ability get it
        // through this contract rather than a Gate facade call, so the dependency is
        // explicit in the constructor (rule 13) and a test can substitute a denying
        // authorizer without building a whole permission fixture.
        $this->app->bind(AbilityAuthorizerInterface::class, GateAbilityAuthorizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTenantMigrationsInTestingEnvironment();

        $this->registerPolicies();

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

        // Self-enforcing cache invalidation: any write/delete on a central
        // vertical_configs row busts the 24h per-vertical override cache,
        // so future writers cannot forget to invalidate (T1 review finding).
        VerticalConfig::observe(VerticalConfigObserver::class);

        // T6 Phase 0b: personal_access_tokens lives in the CENTRAL database, so
        // Sanctum must read token rows from the central connection even after the
        // pre-auth resolver swaps the default connection to a tenant database.
        Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class);

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
     * Register model policies for authorization.
     */
    private function registerPolicies(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);
    }

    /**
     * T6 Phase 0b: register the tenant migration path so the test suite rebuilds
     * the complete schema on a single connection.
     *
     * In production, tenant migrations (database/migrations/tenant/) run only
     * inside each per-tenant database via Stancl (config/tenancy.php
     * migration_parameters) — they are deliberately NOT part of the default
     * `migrate` path. The compat test suite, however, runs on ONE connection
     * with RefreshDatabase, which migrates only database/migrations/. Registering
     * the tenant path here — testing environment ONLY — keeps every existing
     * feature test green after the ~141 tenant-scoped migrations move to tenant/.
     * The Stancl flip integration test still exercises real per-database creation.
     */
    private function loadTenantMigrationsInTestingEnvironment(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        $this->loadMigrationsFrom(database_path('migrations/tenant'));
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

        // User login — two stacked limits:
        //   1. Per (normalized email + IP): 5/min — slows credential brute force
        //      against a single account from a single origin.
        //   2. Per IP (global): 20/min — restores the email-enumeration mitigation
        //      lost when the check-email endpoint + its per-IP limiter were removed
        //      (P1-3, Codex 2026-05-25). The new email-first login returns the
        //      org-picker for a valid email BEFORE password validation, so without
        //      a per-IP cap an attacker could rotate distinct emails (each its own
        //      per-email bucket) and enumerate memberships unbounded. The per-IP
        //      cap stops rotating-email enumeration while staying well above normal
        //      single-user login traffic.
        RateLimiter::for('login', function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $email = strtolower(trim((string) $request->input('email', '')));
            $perEmailKey = $email !== '' ? $email.'|'.$ip : $ip;

            return [
                Limit::perMinute(5)->by('login:email:'.$perEmailKey),
                Limit::perMinute(20)->by('login:ip:'.$ip),
            ];
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

        // Document email send/queue - 30 per hour per user (prevents an
        // authenticated insider from spamming email-out of fiscal docs).
        // dev-remediation/D.
        RateLimiter::for('document-email', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perHour(30)->by((string) $key);
        });

        // POS terminal activation / claim - 20 per minute per user (or
        // per IP if unauthenticated). Activation is a privileged
        // operation; without a throttle, a malicious POS terminal could
        // flood the activate endpoint or attempt to claim every
        // terminal slot. dev-remediation/D.
        RateLimiter::for('pos-terminal-activation', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? $user->getAuthIdentifier() : ($request->ip() ?? 'unknown');

            return Limit::perMinute(20)->by((string) $key);
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

        // Signed media serving — 600 per minute per (IP + tenant) pair.
        // These URLs carry their own HMAC gate, so the limiter is a secondary
        // defence against enumeration loops rather than the primary access
        // control.
        //
        // Re-keyed and raised per the authz gate (2026-08-06): `primary_image_url`
        // is now minted per product row on the LIST endpoint, so a 60-100 product
        // grid issues that many image GETs. Keying on IP alone meant every POS
        // terminal behind one NAT shared a single 120/min budget across tenants —
        // a legitimate grid render would 429 and images would break
        // intermittently. The tenant segment comes from the SIGNED path, so it
        // cannot be forged to widen the budget.
        RateLimiter::for('signed-media', function (Request $request): Limit {
            $tenant = (string) ($request->route('tenant') ?? 'none');

            return Limit::perMinute(600)->by(($request->ip() ?? 'unknown').'|'.$tenant);
        });

        // Channel webhook ingress - 60 per minute per IP.
        //
        // `POST api/v1/webhooks/channels/{channelId}` is UNAUTHENTICATED by
        // design (external sales platforms call it), so before this limiter it
        // was an unbounded anonymous channel: one central-directory lookup per
        // request for any id, and a full tenant DATABASE SWITCH for a real one
        // (ChannelWebhookController binds tenancy before the signature can be
        // verified — verification needs the channel's own adapter row).
        // 60/min/IP is comfortably above real platform callback volume for a
        // single tenant's channels and still bounds the amplification.
        // dev-remediation — 2026-08-05 cat-(b) wave-1 review, B1.
        //
        // KNOWN LIMITATION (N-7, re-gate): keyed by IP ALONE, so several
        // tenants' channels served from one platform's shared egress IP share
        // the 60/min budget — and a 429 to a platform that does not redeliver
        // is a dropped order, by the same R3 reasoning that makes the 404 one.
        // Fine at pilot scale (one tenant, one platform); per-channel keying
        // needs the channel id validated BEFORE the limiter, which is T7
        // redesign scope. Monitor 429s on this route before onboarding a
        // second tenant onto the same platform.
        RateLimiter::for('channel-webhook', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip() ?? 'unknown');
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
