<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds demo tenants with proper subscriptions for testing.
 *
 * ============================================================================
 * DEVELOPMENT/TESTING ONLY
 * ============================================================================
 * These demo tenants are created with generic passwords ('password') and are
 * intended ONLY for local development and testing purposes.
 *
 * DO NOT run this seeder in production environments.
 * In production, tenants should be created through the normal registration flow.
 * ============================================================================
 *
 * Creates:
 * 1. A demo tenant with unlimited access (for internal testing/demos)
 * 2. A trial tenant (for testing trial limits)
 * 3. A professional tenant (for testing paid limits)
 */
class DemoTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure plans exist
        $this->call(PlansSeeder::class);

        $this->createUnlimitedDemoTenant();
        $this->createTrialTenant();
        $this->createProfessionalTenant();
    }

    /**
     * Create a demo tenant with unlimited access.
     */
    private function createUnlimitedDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            $this->command->error('Unlimited plan not found. Run PlansSeeder first.');

            return;
        }

        // Create tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-unlimited'],
            [
                'name' => 'Demo Unlimited',
                'status' => TenantStatus::Active,
                'plan' => 'enterprise', // Legacy field
                'tax_id' => 'TN12345678',
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                    'date_format' => 'd/m/Y',
                    'fiscal_year_start' => '01-01',
                ],
                'trial_ends_at' => null,
                'subscription_ends_at' => null, // Never expires
            ]
        );

        // Create subscription
        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'yearly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(100), // Effectively never
                'trial_ends_at' => null,
                'notes' => 'Internal demo account - unlimited access',
            ]
        );

        // Create domain
        $tenant->domains()->updateOrCreate(
            ['domain' => 'demo.autoerp.local'],
            [
                'is_primary' => true,
                'is_verified' => true,
            ]
        );

        // Create company
        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO'],
            [
                'name' => 'Demo Company SARL',
                'legal_name' => 'Demo Company SARL',
                'tax_id' => 'TN12345678A001',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        // Create admin user
        User::updateOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Created unlimited demo tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Unlimited (no restrictions)');
    }

    /**
     * Create a trial tenant for testing limits.
     */
    private function createTrialTenant(): void
    {
        $trialPlan = Plan::where('code', 'trial')->first();
        if ($trialPlan === null) {
            $this->command->error('Trial plan not found. Run PlansSeeder first.');

            return;
        }

        // Create tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'trial-test'],
            [
                'name' => 'Trial Test Business',
                'status' => TenantStatus::Active,
                'plan' => 'trial', // Legacy field
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                    'date_format' => 'd/m/Y',
                    'fiscal_year_start' => '01-01',
                ],
                'trial_ends_at' => now()->addDays(14),
                'subscription_ends_at' => null,
            ]
        );

        // Create subscription
        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $trialPlan->id,
                'status' => SubscriptionStatus::Trial,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(14),
                'trial_ends_at' => now()->addDays(14),
                'notes' => 'Test trial account',
            ]
        );

        // Create domain
        $tenant->domains()->updateOrCreate(
            ['domain' => 'trial.autoerp.local'],
            [
                'is_primary' => true,
                'is_verified' => true,
            ]
        );

        // Create company
        Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TRIAL'],
            [
                'name' => 'Trial Company',
                'legal_name' => 'Trial Company',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        // Create admin user
        User::updateOrCreate(
            ['email' => 'admin@trial.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Trial Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Created trial tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@trial.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Trial (limited)');
        $this->command->line('  - Trial ends: ' . now()->addDays(14)->format('Y-m-d'));
    }

    /**
     * Create a professional (paid) tenant.
     */
    private function createProfessionalTenant(): void
    {
        $growthPlan = Plan::where('code', 'growth')->first();
        if ($growthPlan === null) {
            $this->command->error('Growth plan not found. Run PlansSeeder first.');

            return;
        }

        // Create tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'pro-garage'],
            [
                'name' => 'Pro Garage SARL',
                'status' => TenantStatus::Active,
                'plan' => 'professional', // Legacy field
                'tax_id' => 'TN87654321',
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                    'date_format' => 'd/m/Y',
                    'fiscal_year_start' => '01-01',
                ],
                'trial_ends_at' => null,
                'subscription_ends_at' => now()->addMonth(),
            ]
        );

        // Create subscription
        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $growthPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => $growthPlan->price_monthly,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'trial_ends_at' => null,
                'last_payment_at' => now(),
                'next_payment_due' => now()->addMonth(),
                'notes' => 'Paid Growth plan',
            ]
        );

        // Create domain
        $tenant->domains()->updateOrCreate(
            ['domain' => 'pro.autoerp.local'],
            [
                'is_primary' => true,
                'is_verified' => true,
            ]
        );

        // Create company
        Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PRO'],
            [
                'name' => 'Pro Garage SARL',
                'legal_name' => 'Pro Garage SARL',
                'tax_id' => 'TN87654321A001',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        // Create admin user
        User::updateOrCreate(
            ['email' => 'admin@pro.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Pro Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Created professional tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@pro.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Growth (79 TND/mo)');
    }
}
