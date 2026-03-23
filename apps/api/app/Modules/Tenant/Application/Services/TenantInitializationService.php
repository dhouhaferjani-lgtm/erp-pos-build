<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use App\Enums\Vertical;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;

/**
 * Service responsible for initializing a new tenant with required data.
 *
 * This service is called during user registration to seed country-specific
 * data and assign default roles to the registering user.
 */
class TenantInitializationService
{
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

        // 2. Assign admin role to the registering user
        $this->assignDefaultRoles($user);

        // 3. Seed country-specific chart of accounts
        $this->seedChartOfAccounts($company);

        // 4. Set country-specific default tax rate
        $this->setDefaultTaxRate($company);

        // 5. Seed country-specific tax configurations (global, idempotent)
        $this->seedTaxConfigurations($company);

        // 6. Fiscal years are automatically created via CompanyCreated event

        // 7. Seed standard payment methods
        $this->seedPaymentMethods($company);

        // 8. Auto-include Inventory for F&B verticals
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
            // Fallback: if no trial plan exists, skip subscription creation
            // This allows the system to work even without plans being seeded
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
     * Seed the chart of accounts based on company country.
     *
     * Currently supported:
     * - TN (Tunisia): Plan Comptable Tunisien
     * - FR (France): Plan Comptable Général
     * - Default: Generic international chart of accounts
     */
    private function seedChartOfAccounts(Company $company): void
    {
        $countryCode = strtoupper($company->country_code);

        $seeder = match ($countryCode) {
            'TN' => new TunisiaChartOfAccountsSeeder,
            'FR' => new FranceChartOfAccountsSeeder,
            default => new GenericChartOfAccountsSeeder,
        };

        $seeder->run($company->id, $company->tenant_id);
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
     * Set the company's default tax rate based on country.
     *
     * This determines the fallback tax rate when neither product
     * nor category has a tax rate configured.
     */
    private function setDefaultTaxRate(Company $company): void
    {
        $defaultTaxRate = match (strtoupper($company->country_code)) {
            'TN' => '19.00',  // Tunisia TVA 19%
            'FR' => '20.00',  // France TVA 20%
            default => '0.00',
        };

        $company->update(['default_tax_rate' => $defaultTaxRate]);
    }

    /**
     * Seed country-specific tax configurations (VAT rates, stamp duties).
     *
     * These are global (per-country, not per-company) and idempotent.
     * Uses updateOrCreate internally so safe to call multiple times.
     */
    private function seedTaxConfigurations(Company $company): void
    {
        // Guard: tax_configurations has FK to countries table
        $countryExists = \DB::table('countries')
            ->where('code', strtoupper($company->country_code))
            ->exists();

        if (! $countryExists) {
            return;
        }

        $countryCode = strtoupper($company->country_code);

        $seeder = match ($countryCode) {
            'TN' => new TunisiaTaxConfigurationSeeder,
            default => null,
        };

        $seeder?->run();
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
