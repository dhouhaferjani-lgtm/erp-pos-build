<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Services;

use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\PlanLimits;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Service for enforcing plan limits and feature access.
 *
 * Not marked final so that feature tests can bind a named subclass stub via
 * the container to simulate an unreachable tenant DB without Mockery needing
 * to proxy the class. An interface extraction (PlanEnforcementServiceInterface)
 * is the longer-term solution if more test doubles are needed.
 */
class PlanEnforcementService
{
    /**
     * Result of a limit check.
     *
     * @var array{allowed: bool, current: int, limit: int, message: string|null}
     */
    private array $lastCheck = [
        'allowed' => true,
        'current' => 0,
        'limit' => 0,
        'message' => null,
    ];

    /**
     * Get the plan for a tenant.
     */
    public function getPlanForTenant(Tenant $tenant): ?Plan
    {
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trial', 'past_due', 'cancelling'])
            ->with('plan')
            ->first();

        return $subscription?->plan;
    }

    /**
     * Get the effective limits for a tenant.
     *
     * @return array<string, mixed>
     */
    public function getLimitsForTenant(Tenant $tenant): array
    {
        $plan = $this->getPlanForTenant($tenant);

        if ($plan === null) {
            // No active subscription, use trial limits
            return PlanLimits::trial();
        }

        return $plan->limits ?? PlanLimits::forPlan($plan->code);
    }

    /**
     * Get a specific limit value for a tenant.
     */
    public function getLimit(Tenant $tenant, string $limitKey, mixed $default = 0): mixed
    {
        $limits = $this->getLimitsForTenant($tenant);

        return $limits[$limitKey] ?? $default;
    }

    /**
     * Check if tenant has access to a feature.
     */
    public function hasFeature(Tenant $tenant, string $featureKey): bool
    {
        return (bool) $this->getLimit($tenant, $featureKey, false);
    }

    /**
     * Check if tenant has access to a module.
     */
    public function hasModule(Tenant $tenant, string $moduleKey): bool
    {
        return $this->hasFeature($tenant, $moduleKey);
    }

    /**
     * Get the last check result.
     *
     * @return array{allowed: bool, current: int, limit: int, message: string|null}
     */
    public function getLastCheck(): array
    {
        return $this->lastCheck;
    }

    // ========================================================================
    // RESOURCE LIMIT CHECKS
    // ========================================================================

    /**
     * Check if tenant can create another company.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canCreateCompany(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_COMPANIES, 1);
        $current = Company::where('tenant_id', $tenant->id)->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "Company limit reached ({$current}/{$limit}). Upgrade your plan to add more companies."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    /**
     * Check if tenant can create another location.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canCreateLocation(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_LOCATIONS, 1);

        // Count locations through companies
        $companyIds = Company::where('tenant_id', $tenant->id)->pluck('id');
        $current = Location::whereIn('company_id', $companyIds)->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "Location limit reached ({$current}/{$limit}). Upgrade your plan to add more locations."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    /**
     * Check if tenant can add another user.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canAddUser(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_USERS, 2);
        $current = DB::table('users')->where('tenant_id', $tenant->id)->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "User limit reached ({$current}/{$limit}). Upgrade your plan to add more users."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    /**
     * Check if tenant can create another product.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canCreateProduct(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_PRODUCTS, 50);
        $current = Product::where('tenant_id', $tenant->id)->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "Product limit reached ({$current}/{$limit}). Upgrade your plan to add more products."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    /**
     * Check if tenant can create another partner.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canCreatePartner(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_PARTNERS, 20);
        $current = Partner::where('tenant_id', $tenant->id)->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "Partner limit reached ({$current}/{$limit}). Upgrade your plan to add more customers/suppliers."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    /**
     * Check if tenant can create more documents this month.
     *
     * TENANT-CONTEXT ONLY — queries per-tenant tables on the current connection; from central context wrap in $tenant->run() or use getUsageStats().
     */
    public function canCreateDocument(Tenant $tenant): bool
    {
        $limit = (int) $this->getLimit($tenant, PlanLimits::MAX_DOCUMENTS_PER_MONTH, 30);

        // If unlimited
        if ($limit >= PHP_INT_MAX) {
            $this->lastCheck = [
                'allowed' => true,
                'current' => 0,
                'limit' => $limit,
                'message' => null,
            ];

            return true;
        }

        $startOfMonth = now()->startOfMonth();
        $current = DB::table('documents')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startOfMonth)
            // R2-F4 / fiscal gate P3-11: a CORRECTING ENTRY is an accounting
            // repair the tenant did not choose to create — it exists because a
            // posted document was defective. Billing the tenant's monthly
            // document quota for fixing our own data would make the quota
            // punish accuracy, and on a busy month could block real invoicing.
            // Excluded here and in getUsageStats() below; the two must agree, or
            // the usage bar and the enforcement disagree about the same number.
            ->where('type', '!=', DocumentType::CorrectingEntry->value)
            ->count();

        $this->lastCheck = [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit' => $limit,
            'message' => $current >= $limit
                ? "Monthly document limit reached ({$current}/{$limit}). Upgrade your plan for more documents."
                : null,
        ];

        return $this->lastCheck['allowed'];
    }

    // ========================================================================
    // USAGE TRACKING
    // ========================================================================

    /**
     * Get current usage statistics for a tenant.
     *
     * @return array<string, array{current: int, limit: int, percent: float}>
     */
    public function getUsageStats(Tenant $tenant): array
    {
        $limits = $this->getLimitsForTenant($tenant);

        // users/companies/locations/products/partners/documents live in the
        // PER-TENANT database (T6 Phase 0b) — counts must execute inside
        // $tenant->run(). tenant_id scoping is kept: harmless in prod,
        // required in the shared-schema test env (run() doesn't swap DBs
        // there). Safe when already in this tenant's context (run()
        // re-initializes and restores).
        /** @var array{companies: int, locations: int, users: int, products: int, partners: int, documents: int} $counts */
        $counts = $tenant->run(static function () use ($tenant): array {
            $companyIds = Company::where('tenant_id', $tenant->id)->pluck('id');

            return [
                'companies' => $companyIds->count(),
                'locations' => Location::whereIn('company_id', $companyIds)->count(),
                'users' => DB::table('users')->where('tenant_id', $tenant->id)->count(),
                'products' => Product::where('tenant_id', $tenant->id)->count(),
                'partners' => Partner::where('tenant_id', $tenant->id)->count(),
                'documents' => DB::table('documents')
                    ->where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->where('type', '!=', DocumentType::CorrectingEntry->value)
                    ->count(),
            ];
        });

        $stats = [];

        // Companies
        $companiesLimit = (int) ($limits[PlanLimits::MAX_COMPANIES] ?? 1);
        $stats['companies'] = [
            'current' => $counts['companies'],
            'limit' => $companiesLimit,
            'percent' => $companiesLimit > 0 ? min(100, ($counts['companies'] / $companiesLimit) * 100) : 0,
        ];

        // Locations (through companies)
        $locationsLimit = (int) ($limits[PlanLimits::MAX_LOCATIONS] ?? 1);
        $stats['locations'] = [
            'current' => $counts['locations'],
            'limit' => $locationsLimit,
            'percent' => $locationsLimit > 0 ? min(100, ($counts['locations'] / $locationsLimit) * 100) : 0,
        ];

        // Users
        $usersLimit = (int) ($limits[PlanLimits::MAX_USERS] ?? 2);
        $stats['users'] = [
            'current' => $counts['users'],
            'limit' => $usersLimit,
            'percent' => $usersLimit > 0 ? min(100, ($counts['users'] / $usersLimit) * 100) : 0,
        ];

        // Products
        $productsLimit = (int) ($limits[PlanLimits::MAX_PRODUCTS] ?? 50);
        $stats['products'] = [
            'current' => $counts['products'],
            'limit' => $productsLimit,
            'percent' => $productsLimit > 0 && $productsLimit < PHP_INT_MAX
                ? min(100, ($counts['products'] / $productsLimit) * 100)
                : 0,
        ];

        // Partners
        $partnersLimit = (int) ($limits[PlanLimits::MAX_PARTNERS] ?? 20);
        $stats['partners'] = [
            'current' => $counts['partners'],
            'limit' => $partnersLimit,
            'percent' => $partnersLimit > 0 && $partnersLimit < PHP_INT_MAX
                ? min(100, ($counts['partners'] / $partnersLimit) * 100)
                : 0,
        ];

        // Documents this month
        $docsLimit = (int) ($limits[PlanLimits::MAX_DOCUMENTS_PER_MONTH] ?? 30);
        $stats['documents_this_month'] = [
            'current' => $counts['documents'],
            'limit' => $docsLimit,
            'percent' => $docsLimit > 0 && $docsLimit < PHP_INT_MAX
                ? min(100, ($counts['documents'] / $docsLimit) * 100)
                : 0,
        ];

        return $stats;
    }

    /**
     * Get enabled modules for a tenant.
     *
     * @return array<string, bool>
     */
    public function getEnabledModules(Tenant $tenant): array
    {
        $limits = $this->getLimitsForTenant($tenant);

        return [
            'sales' => (bool) ($limits[PlanLimits::MODULE_SALES] ?? false),
            'inventory' => (bool) ($limits[PlanLimits::MODULE_INVENTORY] ?? false),
            'treasury' => (bool) ($limits[PlanLimits::MODULE_TREASURY] ?? false),
            'accounting' => (bool) ($limits[PlanLimits::MODULE_ACCOUNTING] ?? false),
            'partners' => (bool) ($limits[PlanLimits::MODULE_PARTNERS] ?? false),
            'workshop' => (bool) ($limits[PlanLimits::MODULE_WORKSHOP] ?? false),
            'vehicles' => (bool) ($limits[PlanLimits::MODULE_VEHICLES] ?? false),
            'reporting' => (bool) ($limits[PlanLimits::MODULE_REPORTING] ?? false),
            'multi_location' => (bool) ($limits[PlanLimits::MODULE_MULTI_LOCATION] ?? false),
            'ecommerce' => (bool) ($limits[PlanLimits::MODULE_ECOMMERCE] ?? false),
            'hr' => (bool) ($limits[PlanLimits::MODULE_HR] ?? false),
        ];
    }

    /**
     * Get enabled features for a tenant.
     *
     * @return array<string, bool>
     */
    public function getEnabledFeatures(Tenant $tenant): array
    {
        $limits = $this->getLimitsForTenant($tenant);

        return [
            'credit_notes' => (bool) ($limits[PlanLimits::FEATURE_CREDIT_NOTES] ?? false),
            'delivery_notes' => (bool) ($limits[PlanLimits::FEATURE_DELIVERY_NOTES] ?? false),
            'document_conversion' => (bool) ($limits[PlanLimits::FEATURE_DOCUMENT_CONVERSION] ?? false),
            'pdf_export' => (bool) ($limits[PlanLimits::FEATURE_PDF_EXPORT] ?? false),
            'excel_export' => (bool) ($limits[PlanLimits::FEATURE_EXCEL_EXPORT] ?? false),
            'email_notifications' => (bool) ($limits[PlanLimits::FEATURE_EMAIL_NOTIFICATIONS] ?? false),
            'sms_notifications' => (bool) ($limits[PlanLimits::FEATURE_SMS_NOTIFICATIONS] ?? false),
            'api_access' => (bool) ($limits[PlanLimits::FEATURE_API_ACCESS] ?? false),
            'webhooks' => (bool) ($limits[PlanLimits::FEATURE_WEBHOOKS] ?? false),
            'custom_branding' => (bool) ($limits[PlanLimits::FEATURE_CUSTOM_BRANDING] ?? false),
            'priority_support' => (bool) ($limits[PlanLimits::FEATURE_PRIORITY_SUPPORT] ?? false),
            'audit_trail' => (bool) ($limits[PlanLimits::FEATURE_AUDIT_TRAIL] ?? false),
            'backup' => (bool) ($limits[PlanLimits::FEATURE_BACKUP] ?? false),
            'multi_currency' => (bool) ($limits[PlanLimits::FEATURE_MULTI_CURRENCY] ?? false),
            'landed_cost' => (bool) ($limits[PlanLimits::FEATURE_LANDED_COST] ?? false),
            'margin_analysis' => (bool) ($limits[PlanLimits::FEATURE_MARGIN_ANALYSIS] ?? false),
            'bank_reconciliation' => (bool) ($limits[PlanLimits::FEATURE_BANK_RECONCILIATION] ?? false),
            'fiscal_compliance' => (bool) ($limits[PlanLimits::FEATURE_FISCAL_COMPLIANCE] ?? false),
        ];
    }

    /**
     * Calculate per-user overage charges for a tenant.
     *
     * @return array{extra_users: int, price_per_user: float, total_overage: float}
     */
    public function calculateUserOverage(Tenant $tenant): array
    {
        $limits = $this->getLimitsForTenant($tenant);

        $includedUsers = (int) ($limits[PlanLimits::INCLUDED_USERS] ?? 0);
        $pricePerExtraUser = (float) ($limits[PlanLimits::PRICE_PER_EXTRA_USER] ?? 0);

        /** @var int $currentUsers */
        $currentUsers = $tenant->run(
            static fn (): int => DB::table('users')->where('tenant_id', $tenant->id)->count()
        );
        $extraUsers = max(0, $currentUsers - $includedUsers);

        return [
            'extra_users' => $extraUsers,
            'price_per_user' => $pricePerExtraUser,
            'total_overage' => $extraUsers * $pricePerExtraUser,
        ];
    }

    /**
     * Get full plan summary for a tenant.
     *
     * @return array<string, mixed>
     */
    public function getPlanSummary(Tenant $tenant): array
    {
        $plan = $this->getPlanForTenant($tenant);
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trial', 'past_due', 'cancelling'])
            ->first();

        return [
            'plan' => $plan !== null ? [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'currency' => $plan->currency,
            ] : null,
            'subscription' => $subscription !== null ? [
                'status' => $subscription->status->value,
                'billing_cycle' => $subscription->billing_cycle,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'is_on_trial' => $subscription->isOnTrial(),
            ] : null,
            'usage' => $this->getUsageStats($tenant),
            'modules' => $this->getEnabledModules($tenant),
            'features' => $this->getEnabledFeatures($tenant),
            'overage' => $this->calculateUserOverage($tenant),
        ];
    }
}
