<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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

        // Original demo tenants
        $this->createUnlimitedDemoTenant();
        $this->createTrialTenant();
        $this->createProfessionalTenant();

        // IziPos vertical demo tenants
        $this->createRetailDemoTenant();
        $this->createPharmacyDemoTenant();
        $this->createRestaurantDemoTenant();
        $this->createCoffeeShopDemoTenant();
        $this->createFashionDemoTenant();
        $this->createParapharmacyDemoTenant();
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
        $user = User::updateOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->seedWorkshopBundles($tenant, $company);
        $this->seedWorkOrders($tenant, $company, $user);

        $this->command->info("Created unlimited demo tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Unlimited (no restrictions)');
    }

    /**
     * Seed example Workshop service bundles for the given demo tenant+company.
     *
     * These are "menu pricing" offerings an automotive shop would expose
     * in the POS / work-order picker. Components and vehicle-specific
     * applicabilities are intentionally not seeded here — they require
     * Product/Service records that the seeder does not otherwise create,
     * and can be authored through the Workshop/Bundle UI.
     */
    private function seedWorkshopBundles(Tenant $tenant, Company $company): void
    {
        $currency = $company->currency !== '' ? $company->currency : 'TND';

        $bundles = [
            [
                'code' => 'VIDANGE-10K-ESSENCE',
                'name' => 'Vidange 10 000 km essence',
                'description' => 'Oil change package — petrol, 10 000 km service interval.',
                'pricing_mode' => BundlePricingMode::FixedBundle,
                'base_price' => '120.000',
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '0.75',
                'service_interval_km' => 10000,
                'service_interval_months' => 12,
            ],
            [
                'code' => 'VIDANGE-10K-DIESEL',
                'name' => 'Vidange 10 000 km diesel',
                'description' => 'Oil change package — diesel, 10 000 km service interval.',
                'pricing_mode' => BundlePricingMode::FixedBundle,
                'base_price' => '135.000',
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '0.75',
                'service_interval_km' => 10000,
                'service_interval_months' => 12,
            ],
            [
                'code' => 'FREINAGE-AV',
                'name' => 'Remplacement freinage avant',
                'description' => 'Front brake pads + discs replacement.',
                'pricing_mode' => BundlePricingMode::Standard,
                'base_price' => null,
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '1.25',
                'service_interval_km' => null,
                'service_interval_months' => null,
            ],
            [
                'code' => 'REVISION-40K',
                'name' => 'Grande révision 40 000 km',
                'description' => 'Major 40k km service — vidange + filters + fluids check.',
                'pricing_mode' => BundlePricingMode::Standard,
                'base_price' => null,
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '2.00',
                'service_interval_km' => 40000,
                'service_interval_months' => 24,
            ],
            [
                'code' => 'PNEUS-REMPLACEMENT-4',
                'name' => 'Remplacement 4 pneus',
                'description' => 'Replace all 4 tyres — includes mounting + balancing.',
                'pricing_mode' => BundlePricingMode::Standard,
                'base_price' => null,
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '1.50',
                'service_interval_km' => null,
                'service_interval_months' => null,
            ],
            [
                'code' => 'DIAGNOSTIC-OBD',
                'name' => 'Diagnostic électronique OBD',
                'description' => 'OBD-II diagnostic with scanner + fault-code report.',
                'pricing_mode' => BundlePricingMode::FixedBundle,
                'base_price' => '45.000',
                'tax_rate' => '19.000',
                'estimated_labor_hours' => '0.50',
                'service_interval_km' => null,
                'service_interval_months' => null,
            ],
        ];

        foreach ($bundles as $data) {
            $bundle = ServiceBundle::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'code' => $data['code'],
                ],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'pricing_mode' => $data['pricing_mode'],
                    'base_price' => $data['base_price'],
                    'currency' => $currency,
                    'tax_rate' => $data['tax_rate'],
                    'estimated_labor_hours' => $data['estimated_labor_hours'],
                    'service_interval_km' => $data['service_interval_km'],
                    'service_interval_months' => $data['service_interval_months'],
                    'is_active' => true,
                ],
            );

            // Universal applicability by default — operator refines in the UI.
            ServiceBundleVehicleApplicability::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'bundle_id' => $bundle->id,
                    'platform_vehicle_id' => null,
                    'vehicle_type' => null,
                ],
                [
                    'vehicle_display' => null,
                    'year_from' => null,
                    'year_to' => null,
                ],
            );
        }
    }

    /**
     * Seed a handful of demo WorkOrders spanning the statuses the workshop UI
     * needs to render sensibly out of the box. Creates Partner + Vehicle rows
     * on the fly so the seeder is self-contained.
     */
    private function seedWorkOrders(Tenant $tenant, Company $company, User $openedBy): void
    {
        if (WorkOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->exists()
        ) {
            return;
        }

        $currency = $company->currency !== '' ? $company->currency : 'TND';

        $customers = [
            ['name' => 'Acme Motors', 'code' => 'CUST-WO-001'],
            ['name' => 'Mohamed Ben Ali', 'code' => 'CUST-WO-002'],
            ['name' => 'Société Transport Sud', 'code' => 'CUST-WO-003'],
            ['name' => 'Fatima Trabelsi', 'code' => 'CUST-WO-004'],
            ['name' => 'Garage Central Fleet', 'code' => 'CUST-WO-005'],
        ];

        $vehicleSpecs = [
            ['plate' => 'TN-1234-AB', 'brand' => 'Peugeot', 'model' => '208', 'year' => 2021],
            ['plate' => 'TN-5678-CD', 'brand' => 'Renault', 'model' => 'Clio', 'year' => 2019],
            ['plate' => 'TN-9012-EF', 'brand' => 'Volkswagen', 'model' => 'Golf', 'year' => 2022],
            ['plate' => 'TN-3456-GH', 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020],
            ['plate' => 'TN-7890-IJ', 'brand' => 'Citroën', 'model' => 'C3', 'year' => 2018],
        ];

        /** @var list<array{0: WorkOrderStatus, 1: WorkOrderType, 2: string, 3: string}> $profiles */
        $profiles = [
            [WorkOrderStatus::Received, WorkOrderType::Diagnostic, '150.000', 'Engine check light intermittent.'],
            [WorkOrderStatus::Quoted, WorkOrderType::Repair, '420.000', 'Front brake squeal at low speed.'],
            [WorkOrderStatus::Approved, WorkOrderType::Maintenance, '310.000', 'Scheduled 40k km major service.'],
            [WorkOrderStatus::InProgress, WorkOrderType::TireService, '560.000', 'Replace all four tyres.'],
            [WorkOrderStatus::Completed, WorkOrderType::Inspection, '85.000', 'Annual pre-control inspection.'],
        ];

        foreach ($profiles as $i => [$status, $type, $estTotal, $complaint]) {
            $customer = $customers[$i];
            $vehicleSpec = $vehicleSpecs[$i];

            $partner = Partner::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'code' => $customer['code'],
                ],
                [
                    'name' => $customer['name'],
                    'type' => 'customer',
                    'email' => Str::slug($customer['name']).'@demo.local',
                    'phone' => '+216'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT),
                    'country_code' => 'TN',
                    'is_active' => true,
                ],
            );

            $vehicle = Vehicle::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'license_plate' => $vehicleSpec['plate'],
                ],
                [
                    'partner_id' => $partner->id,
                    'brand' => $vehicleSpec['brand'],
                    'model' => $vehicleSpec['model'],
                    'year' => $vehicleSpec['year'],
                    'mileage' => 20000 + ($i * 15000),
                    'fuel_type' => 'gasoline',
                    'transmission' => 'manual',
                ],
            );

            $year = date('Y');
            $number = 'WO-'.$year.'-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT);

            $wo = new WorkOrder;
            $wo->fill([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => null,
                'work_order_number' => $number,
                'status' => $status->value,
                'type' => $type->value,
                'customer_partner_id' => $partner->id,
                'vehicle_id' => $vehicle->id,
                'opened_by_user_id' => $openedBy->id,
                'primary_technician_profile_id' => null,
                'mileage_at_intake' => 20000 + ($i * 15000),
                'customer_complaint' => $complaint,
                'diagnosis' => $status === WorkOrderStatus::Received ? null : 'Diagnostic completed by lead technician.',
                'internal_notes' => null,
                'scheduled_start_at' => now()->addDays($i - 2),
                'scheduled_end_at' => now()->addDays($i - 2)->addHours(2),
                'promised_at' => now()->addDays($i + 1),
                'started_at' => in_array($status, [WorkOrderStatus::InProgress, WorkOrderStatus::Completed], true) ? now()->subHours(6) : null,
                'completed_at' => $status === WorkOrderStatus::Completed ? now()->subHours(1) : null,
                'approval_captured_at' => in_array($status, [
                    WorkOrderStatus::Approved,
                    WorkOrderStatus::InProgress,
                    WorkOrderStatus::Completed,
                ], true) ? now()->subHours(8) : null,
                'approval_method' => in_array($status, [
                    WorkOrderStatus::Approved,
                    WorkOrderStatus::InProgress,
                    WorkOrderStatus::Completed,
                ], true) ? 'in_person' : null,
                'approval_captured_by_user_id' => in_array($status, [
                    WorkOrderStatus::Approved,
                    WorkOrderStatus::InProgress,
                    WorkOrderStatus::Completed,
                ], true) ? $openedBy->id : null,
                'currency' => $currency,
                'estimated_parts_total' => '0.000',
                'estimated_labor_total' => '0.000',
                'estimated_other_total' => '0.000',
                'estimated_tax_total' => '0.000',
                'estimated_grand_total' => $estTotal,
                'actual_parts_total' => '0.000',
                'actual_labor_total' => '0.000',
                'actual_other_total' => '0.000',
                'actual_tax_total' => '0.000',
                'actual_grand_total' => $status === WorkOrderStatus::Completed ? $estTotal : '0.000',
            ]);
            $wo->save();
        }

        $this->command->line('  - Seeded 5 demo work orders across statuses.');
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
        $company = Company::updateOrCreate(
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
        $user = User::updateOrCreate(
            ['email' => 'admin@trial.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Trial Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created trial tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@trial.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Trial (limited)');
        $this->command->line('  - Trial ends: '.now()->addDays(14)->format('Y-m-d'));
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
        $company = Company::updateOrCreate(
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
        $user = User::updateOrCreate(
            ['email' => 'admin@pro.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Pro Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created professional tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@pro.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Growth (79 TND/mo)');
    }

    /**
     * Create retail demo tenant (IziPos vertical).
     */
    private function createRetailDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-retail'],
            [
                'name' => 'IziPos Retail Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'retail',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'RETAIL'],
            [
                'name' => 'IziPos Retail Demo',
                'legal_name' => 'IziPos Retail Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'retail@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Retail Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created retail demo tenant: {$tenant->name}");
        $this->command->line('  - Email: retail@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: retail');
    }

    /**
     * Create pharmacy demo tenant (IziPos vertical).
     */
    private function createPharmacyDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-pharmacy'],
            [
                'name' => 'IziPos Pharmacy Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'pharmacy',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PHARM'],
            [
                'name' => 'IziPos Pharmacy Demo',
                'legal_name' => 'IziPos Pharmacy Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'pharmacy@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Pharmacy Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created pharmacy demo tenant: {$tenant->name}");
        $this->command->line('  - Email: pharmacy@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: pharmacy (includes BatchExpiry module)');
    }

    /**
     * Create restaurant demo tenant (IziPos vertical).
     */
    private function createRestaurantDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-restaurant'],
            [
                'name' => 'IziPos Restaurant Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'restaurant',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'REST'],
            [
                'name' => 'IziPos Restaurant Demo',
                'legal_name' => 'IziPos Restaurant Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'restaurant@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Restaurant Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created restaurant demo tenant: {$tenant->name}");
        $this->command->line('  - Email: restaurant@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: restaurant');
    }

    /**
     * Create coffee shop demo tenant (IziPos vertical).
     */
    private function createCoffeeShopDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-coffee'],
            [
                'name' => 'IziPos Coffee Shop Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'coffee_shop',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'COFFEE'],
            [
                'name' => 'IziPos Coffee Shop Demo',
                'legal_name' => 'IziPos Coffee Shop Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'coffee_shop@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Coffee Shop Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created coffee shop demo tenant: {$tenant->name}");
        $this->command->line('  - Email: coffee_shop@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: coffee_shop');
    }

    /**
     * Create fashion demo tenant (IziPos vertical).
     */
    private function createFashionDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-fashion'],
            [
                'name' => 'IziPos Fashion Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'fashion',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'FASHION'],
            [
                'name' => 'IziPos Fashion Demo',
                'legal_name' => 'IziPos Fashion Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'fashion@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Fashion Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created fashion demo tenant: {$tenant->name}");
        $this->command->line('  - Email: fashion@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: fashion');
    }

    /**
     * Create parapharmacy demo tenant (IziPos vertical).
     */
    private function createParapharmacyDemoTenant(): void
    {
        $unlimitedPlan = Plan::where('code', 'unlimited')->first();
        if ($unlimitedPlan === null) {
            return;
        }

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-parapharmacy'],
            [
                'name' => 'IziPos Parapharmacy Demo',
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Trial,
                'vertical' => 'parapharmacy',
                'enabled_extras' => [],
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'settings' => [
                    'timezone' => 'Africa/Tunis',
                    'locale' => 'fr',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $unlimitedPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'monthly',
                'price' => 0,
                'currency' => 'TND',
                'current_period_start' => now(),
                'current_period_end' => now()->addYears(1),
            ]
        );

        $company = Company::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'PARA'],
            [
                'name' => 'IziPos Parapharmacy Demo',
                'legal_name' => 'IziPos Parapharmacy Demo SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'status' => 'active',
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'parapharmacy@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Parapharmacy Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        $this->command->info("Created parapharmacy demo tenant: {$tenant->name}");
        $this->command->line('  - Email: parapharmacy@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Vertical: parapharmacy');
    }

    /**
     * Assign admin role and company membership to a demo user.
     */
    private function assignAdminRoleAndMembership(User $user, Tenant $tenant, Company $company): void
    {
        setPermissionsTeamId($tenant->id);

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole !== null) {
            $user->assignRole($adminRole);
        }

        UserCompanyMembership::updateOrCreate(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
            ],
            [
                'role' => MembershipRole::Admin,
                'is_primary' => true,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]
        );
    }
}
