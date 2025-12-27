<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\PlanLimits;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlansSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            // ================================================================
            // FREE TRIAL
            // ================================================================
            [
                'code' => 'trial',
                'name' => 'Free Trial',
                'description' => '14-day trial with core features to explore the platform',
                'limits' => PlanLimits::trial(),
                'price_monthly' => null,
                'price_yearly' => null,
                'currency' => 'TND',
                'trial_days' => 14,
                'is_active' => true,
                'is_public' => false, // Not shown on pricing page
                'display_order' => 0,
            ],

            // ================================================================
            // STARTER - Small businesses
            // ================================================================
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Perfect for freelancers and small businesses just getting started. Includes core sales, inventory, and treasury modules.',
                'limits' => PlanLimits::starter(),
                'price_monthly' => 29.00,
                'price_yearly' => 290.00, // ~17% savings
                'currency' => 'TND',
                'trial_days' => 14,
                'is_active' => true,
                'is_public' => true,
                'display_order' => 1,
            ],

            // ================================================================
            // GROWTH - Growing businesses
            // ================================================================
            [
                'code' => 'growth',
                'name' => 'Growth',
                'description' => 'For growing businesses that need workshop management, advanced reporting, and multi-currency support. Includes 5 users, additional users at 15 TND/user.',
                'limits' => PlanLimits::growth(),
                'price_monthly' => 79.00,
                'price_yearly' => 790.00, // ~17% savings
                'currency' => 'TND',
                'trial_days' => 14,
                'is_active' => true,
                'is_public' => true,
                'display_order' => 2,
            ],

            // ================================================================
            // BUSINESS - Multi-location businesses
            // ================================================================
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'For established businesses with multiple locations and companies. Full multi-location support, e-commerce integration, and fiscal compliance. Includes 10 users, additional users at 12 TND/user.',
                'limits' => PlanLimits::business(),
                'price_monthly' => 199.00,
                'price_yearly' => 1990.00, // ~17% savings
                'currency' => 'TND',
                'trial_days' => 14,
                'is_active' => true,
                'is_public' => true,
                'display_order' => 3,
            ],

            // ================================================================
            // ENTERPRISE - Large organizations
            // ================================================================
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom solution for large organizations. Includes all modules, dedicated support, custom integrations, and SLA guarantees. Contact us for pricing.',
                'limits' => PlanLimits::enterprise(),
                'price_monthly' => null, // Custom pricing
                'price_yearly' => null,
                'currency' => 'TND',
                'trial_days' => 30,
                'is_active' => true,
                'is_public' => true,
                'display_order' => 4,
            ],

            // ================================================================
            // UNLIMITED - Internal/Demo accounts
            // ================================================================
            [
                'code' => 'unlimited',
                'name' => 'Unlimited',
                'description' => 'Internal plan with no limits. For demo accounts, development, and testing purposes only.',
                'limits' => PlanLimits::unlimited(),
                'price_monthly' => null,
                'price_yearly' => null,
                'currency' => 'TND',
                'trial_days' => 0, // No trial, immediate access
                'is_active' => true,
                'is_public' => false, // Not shown on pricing page
                'display_order' => 99,
            ],
        ];

        foreach ($plans as $planData) {
            // Check if plan already exists
            $existing = Plan::where('code', $planData['code'])->first();

            if ($existing !== null) {
                // Update existing plan (don't change ID)
                $existing->update($planData);
            } else {
                // Create new plan with new UUID
                $planData['id'] = Str::uuid()->toString();
                Plan::create($planData);
            }
        }

        $this->command->info('Plans seeded successfully:');
        foreach ($plans as $plan) {
            $price = $plan['price_monthly'] !== null
                ? number_format($plan['price_monthly'], 2).' '.$plan['currency'].'/mo'
                : 'Custom';
            $this->command->line("  - {$plan['name']} ({$plan['code']}): {$price}");
        }
    }
}
