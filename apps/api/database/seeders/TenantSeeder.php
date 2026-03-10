<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure plans exist first
        $this->call(PlansSeeder::class);

        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        $trialPlan = Plan::where('code', 'trial')->first();

        // Create a demo tenant for development (with unlimited plan)
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-garage'],
            [
                'name' => 'Demo Garage',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Professional, // Legacy field
                'vertical' => 'mechanic',
                'tax_id' => 'FR12345678901',
                'country_code' => 'FR',
                'currency_code' => 'EUR',
                'settings' => [
                    'timezone' => 'Europe/Paris',
                    'locale' => 'fr',
                    'date_format' => 'd/m/Y',
                    'fiscal_year_start' => '01-01',
                ],
                'trial_ends_at' => null,
                'subscription_ends_at' => now()->addYear(),
            ]
        );

        // Create subscription for demo tenant
        if ($unlimitedPlan !== null) {
            TenantSubscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $unlimitedPlan->id,
                    'status' => SubscriptionStatus::Active,
                    'billing_cycle' => 'yearly',
                    'price' => 0,
                    'currency' => 'EUR',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addYears(100),
                    'trial_ends_at' => null,
                    'notes' => 'Demo account - unlimited access',
                ]
            );
        }

        $tenant->domains()->updateOrCreate(
            ['domain' => 'demo.autoerp.local'],
            [
                'is_primary' => true,
                'is_verified' => true,
            ]
        );

        $this->command->info("Created demo tenant: {$tenant->name} ({$tenant->slug}) with Unlimited plan");

        // Create a trial tenant for testing
        $trialTenant = Tenant::updateOrCreate(
            ['slug' => 'trial-business'],
            [
                'name' => 'Trial Business',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial, // Legacy field
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

        // Create subscription for trial tenant
        if ($trialPlan !== null) {
            TenantSubscription::updateOrCreate(
                ['tenant_id' => $trialTenant->id],
                [
                    'plan_id' => $trialPlan->id,
                    'status' => SubscriptionStatus::Trial,
                    'billing_cycle' => 'monthly',
                    'price' => 0,
                    'currency' => 'TND',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays(14),
                    'trial_ends_at' => now()->addDays(14),
                    'notes' => 'Trial account',
                ]
            );
        }

        $trialTenant->domains()->updateOrCreate(
            ['domain' => 'trial.autoerp.local'],
            [
                'is_primary' => true,
                'is_verified' => true,
            ]
        );

        $this->command->info("Created trial tenant: {$trialTenant->name} ({$trialTenant->slug}) with Trial plan");
    }
}
