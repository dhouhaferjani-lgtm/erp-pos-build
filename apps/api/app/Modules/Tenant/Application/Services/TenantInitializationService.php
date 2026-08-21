<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Enums\Vertical;
use App\Models\CountryTaxRate;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Application\Services\ExpenseCategoryProvisioningService;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\BanksSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Database\Seeders\CountryInventorySettingsSeeder;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Database\Seeders\CountryTaxRatesSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PaymentRepositorySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for initializing a new tenant with required data.
 *
 * This service is called during user registration to seed country-specific
 * data and assign default roles to the registering user.
 */
class TenantInitializationService
{
    public function __construct(
        private readonly CompanyTaxProvisioningService $companyTaxProvisioning,
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly ExpenseCategoryProvisioningService $expenseCategories,
    ) {}

    /**
     * Initialize a new registration with all required data.
     *
     * Called inside the registration transaction after:
     * - Tenant is created
     * - User is created
     * - Company is created
     * - UserCompanyMembership is created
     *
     * @param  Tenant  $tenant  The newly created tenant
     * @param  Company  $company  The newly created company
     * @param  User  $user  The registering user
     */
    public function initializeForNewRegistration(
        Tenant $tenant,
        Company $company,
        User $user
    ): void {
        // 1. Create trial subscription for the tenant
        $this->createTrialSubscription($tenant);

        // 1.5. Seed roles/permissions into a fresh per-tenant database (T6 Phase 0b).
        // Spatie permission tables are tenant-scoped, so a freshly-provisioned
        // tenant database has no roles and assignDefaultRoles() would throw. Guarded
        // + idempotent (firstOrCreate), so it is skipped in the shared-DB compat mode
        // where roles are already seeded globally.
        $this->seedRolesAndPermissionsIfMissing();

        // 2. Assign admin role to the registering user
        $this->assignDefaultRoles($user);

        // 2.5. Seed shared reference data FIRST (T6 Phase 0b, deliverable 9).
        // Under database-per-tenant these tables live in each tenant database and
        // are not pre-populated, so seed them before any country-dependent step —
        // notably seedTaxConfigurations(), which silently no-ops when `countries`
        // is empty. Idempotent (the seeders updateOrCreate), so harmless in the
        // shared-DB compat mode where the tables may already be populated.
        $this->seedReferenceData();

        // 3. Seed country-specific chart of accounts
        $this->seedChartOfAccounts($company);

        // 3.5. Seed the country's default expense categories.
        // Register G-3: real tenants received NONE — the seeder was reachable
        // only from the two parapharmacy demo seeders, so every expense a live
        // tenant recorded fell back to the GeneralExpense account. Must run
        // AFTER seedChartOfAccounts(): each category links to a class-6 account
        // (or resolves the GeneralExpense purpose). Idempotent (firstOrCreate).
        $this->seedExpenseCategories($company);

        // 4. Set country-specific default tax rate
        $this->setDefaultTaxRate($company);

        // 5. Seed country-specific tax configurations (global, idempotent)
        $this->seedTaxConfigurations($company);

        // 6. Fiscal years are automatically created via CompanyCreated event

        // 7. Seed standard payment methods
        $this->seedPaymentMethods($company);

        $this->seedBanks($company);

        // 8. Seed payment repositories (cash registers, bank accounts) with GL links
        // Must run AFTER chart of accounts so GL account IDs can be resolved
        $this->seedPaymentRepositories($company);

        // 9. Auto-include Inventory for F&B verticals
        // Temporarily auto-include Inventory for F&B verticals
        // TODO: Remove when Inventory becomes a separately purchased module
        $this->enableInventoryForFnbVerticals($tenant);
    }

    /**
     * Create a trial subscription for the new tenant.
     *
     * Links the tenant to the 'trial' plan from the plans table
     * and sets up a 14-day trial period.
     */
    private function createTrialSubscription(Tenant $tenant): void
    {
        // Find the trial plan (code = 'trial')
        $trialPlan = Plan::where('code', 'trial')->first();

        if ($trialPlan === null) {
            // Register G-11. Skipping is deliberate — a missing plans catalogue
            // must not fail provisioning, and the tenant is otherwise perfectly
            // usable. But it used to skip in complete silence, so a tenant
            // provisioned against an unseeded central `plans` table ended up
            // with NO subscription row and nothing anywhere recorded it; the
            // gap surfaced only when someone later asked what the tenant was
            // paying for. Warn under a STABLE grep token so this is findable in
            // the logs, and name the remedy.
            Log::warning(
                'TENANT-INIT TRIAL-PLAN-ABSENT tenant='.$tenant->id
                .' — no plan with code=trial exists, so this tenant was initialized WITHOUT a subscription.'
                .' Remedy: seed the central plans catalogue (php artisan db:seed --class=Database\\Seeders\\PlansSeeder)'
                .' and backfill the subscription for this tenant.',
                [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'missing_plan_code' => 'trial',
                    'remedy' => 'php artisan db:seed --class=Database\\Seeders\\PlansSeeder',
                ]
            );

            return;
        }

        $trialDays = $trialPlan->trial_days ?? 14;

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $trialPlan->id,
            'status' => SubscriptionStatus::Trial,
            'billing_cycle' => 'monthly',
            'price' => null, // Trial is free
            'currency' => $tenant->currency_code ?? 'TND',
            'trial_ends_at' => now()->addDays($trialDays),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($trialDays),
            'metadata' => [
                'created_via' => 'registration',
                'registered_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Assign default Spatie roles to the registering user.
     *
     * The registering user (company owner) gets the 'admin' role which grants
     * full access to all modules in the sidebar.
     */
    private function assignDefaultRoles(User $user): void
    {
        // Set the Spatie Permission team context (required when teams is enabled)
        // This ensures the role is assigned with the correct tenant_id
        setPermissionsTeamId($user->tenant_id);

        // Assign 'admin' role - this is required for sidebar to show all modules
        // The role must exist (seeded by RolesAndPermissionsSeeder in ProductionSeeder)
        $user->assignRole('admin');
    }

    /**
     * Seed roles + permissions into a fresh per-tenant database when absent.
     * Guarded so the (large, idempotent) seeder is skipped in the shared-DB
     * compat mode where the global roles already exist.
     */
    private function seedRolesAndPermissionsIfMissing(): void
    {
        $adminExists = \DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'sanctum')
            ->exists();

        if ($adminExists) {
            return;
        }

        // Run via Artisan so the seeder has a console-command context
        // ($this->command->info(...) is used inside) and seeds the active
        // (tenant) connection.
        Artisan::call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
    }

    /**
     * Seed shared reference data into the current connection (the per-tenant
     * database under database-per-tenant). Both seeders use updateOrCreate, so
     * re-running in the shared-DB compat mode is harmless.
     */
    private function seedReferenceData(): void
    {
        (new CountriesSeeder)->run();
        (new CountryTaxRatesSeeder)->run();
        // MUST run after CountriesSeeder — the country_code FK requires the
        // lookup rows to exist (spec §4.2 greenfield self-healing). The A2
        // migration's own upsert is skipped on a fresh tenant because
        // `countries` is still empty when tenants:migrate runs.
        (new CountryPaymentSettingsSeeder)->run();
        // Wave 3 T25a / D-27: the document-lane country policy family. Same
        // ordering requirement as the payment settings — the country_code FK
        // needs the `countries` lookup rows to exist first.
        (new CountryDocumentSettingsSeeder)->run();
        // Same ordering constraint, same reason (DPA Wave 3 T8): the
        // country_code FK needs the lookup rows, and this table's migration
        // deliberately seeds nothing.
        (new CountryInventorySettingsSeeder)->run();
    }

    private function seedChartOfAccounts(Company $company): void
    {
        $this->chartOfAccounts->seedForCompany($company);
    }

    /**
     * Seed standard payment methods for the company.
     *
     * Creates common payment methods:
     * - Cash
     * - Check
     * - Bank Transfer
     * - Credit/Debit Card
     * - Direct Debit
     * - PayPal
     * - And others
     */
    private function seedPaymentMethods(Company $company): void
    {
        $seeder = new PaymentMethodSeeder;
        $seeder->run($company);
    }

    /**
     * Seed the company's default expense categories (register G-3).
     *
     * Country-aware and idempotent; skips silently when the chart of accounts
     * has no matching account at all (the seeder inserts no NULL account_id).
     */
    private function seedExpenseCategories(Company $company): void
    {
        $this->expenseCategories->provisionForCompany($company);
    }

    private function seedBanks(Company $company): void
    {
        $seeder = new BanksSeeder;
        $seeder->run($company);
    }

    /**
     * Seed payment repositories (cash registers, bank accounts) for the company.
     *
     * Creates default repositories linked to the chart of accounts GL accounts.
     * Must run AFTER seedChartOfAccounts() so Cash and Bank system purpose
     * accounts exist for GL linking.
     */
    private function seedPaymentRepositories(Company $company): void
    {
        $seeder = new PaymentRepositorySeeder;
        $seeder->run($company);
    }

    /**
     * Set the company's default tax rate based on country.
     *
     * This determines the fallback tax rate when neither product
     * nor category has a tax rate configured.
     */
    private function setDefaultTaxRate(Company $company): void
    {
        // Read the country's standard rate from the seeded country_tax_rates
        // reference data (seeded in step 2.5, before this runs) rather than
        // hardcoding per-country literals. For TN/FR seedTaxConfigurations()
        // subsequently overwrites this with the same value from the is_default
        // tax_configurations row and additionally sets the FK; for other seeded
        // countries (e.g. DE/IT) this is the authoritative default; unknown
        // countries fall back to 0.00.
        $defaultTaxRate = CountryTaxRate::query()
            ->where('country_code', strtoupper($company->country_code))
            ->where('is_default', true)
            ->value('rate');

        $company->update([
            'default_tax_rate' => $defaultTaxRate !== null ? (string) $defaultTaxRate : '0.00',
        ]);
    }

    /**
     * Seed country-specific tax configurations (VAT rates, stamp duties) and
     * set company.default_tax_configuration_id + company.default_tax_rate from
     * the country's is_default config row.
     *
     * Delegates entirely to CompanyTaxProvisioningService, which is the single
     * authoritative entry point for country tax provisioning across all writers
     * (registration, company creation, seeders). Idempotent (seeders use
     * updateOrCreate internally). Silently skips unsupported countries.
     *
     * For supported countries (TN/FR) this overwrites the default_tax_rate that
     * setDefaultTaxRate() wrote with the same value, and additionally sets the FK.
     * For unsupported countries setDefaultTaxRate() sets the correct fallback rate
     * and this call is a no-op (no FK set).
     */
    private function seedTaxConfigurations(Company $company): void
    {
        // Seeds the country configs AND sets company.default_tax_configuration_id + default_tax_rate.
        $this->companyTaxProvisioning->provisionForCompany($company);
    }

    /**
     * Auto-enable the Inventory extra for Food & Beverage verticals.
     *
     * CoffeeShop and Restaurant tenants require Inventory to be enabled
     * from the start. This is temporary until Inventory becomes a
     * separately purchased module.
     *
     * TODO: Remove when Inventory becomes a separately purchased module.
     */
    private function enableInventoryForFnbVerticals(Tenant $tenant): void
    {
        $fnbVerticals = [Vertical::CoffeeShop, Vertical::Restaurant];

        if (! in_array($tenant->vertical, $fnbVerticals, true)) {
            return;
        }

        $extras = $tenant->enabled_extras ?? [];

        if (in_array('Inventory', $extras, true)) {
            return;
        }

        $extras[] = 'Inventory';
        $tenant->update(['enabled_extras' => $extras]);
    }
}
