<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Vertical;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Scheduling\Application\Services\AppointmentConversionService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService as SchedulingAppointmentService;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\AppointmentSequenceInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Domain\ScheduleConfig;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // Explicit: AutoSpecs demos rely on roles/permissions (admin,
        // technician, etc.) being present. PlansSeeder does NOT invoke
        // this — calling it here removes the manual `db:seed --class=
        // RolesAndPermissionsSeeder` step operators previously needed.
        $this->call(RolesAndPermissionsSeeder::class);

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

        // Create tenant — note the Mechanic vertical so AutoSpecs modules
        // (Workshop, Scheduling, Vehicle) activate out of the box.
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo-unlimited'],
            [
                'name' => 'Demo Unlimited',
                'status' => TenantStatus::Active,
                'plan' => 'enterprise', // Legacy field
                'vertical' => Vertical::Mechanic,
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

        // Create admin user — explicit enum + verified + password for
        // deterministic smoke testing. `admin@demo.local` is intentional;
        // `admin@otospex.com` is reserved for the live Otospex admin.
        $user = User::updateOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => UserStatus::Active,
            ]
        );

        $this->assignAdminRoleAndMembership($user, $tenant, $company);

        // Tunisia COA must exist before accounting-linked seed paths run.
        // Guarded on company_id so a re-seed doesn't duplicate the chart.
        if (DB::table('accounts')->where('company_id', $company->id)->doesntExist()) {
            $coaSeeder = new TunisiaChartOfAccountsSeeder;
            $coaSeeder->setCommand($this->command);
            $coaSeeder->run($company->id, $tenant->id);
        }

        $this->seedAutomotiveCatalog($tenant, $company);
        $this->seedWorkshopTechnicians($tenant, $company, $user);
        $this->seedWorkshopBundles($tenant, $company);
        $this->hydrateBundleComponents($tenant, $company);
        $this->seedWorkOrders($tenant, $company, $user);
        $this->seedScheduling($tenant, $company, $user);

        $this->command->info("Created unlimited demo tenant: {$tenant->name}");
        $this->command->line('  - Email: admin@demo.local');
        $this->command->line('  - Password: password');
        $this->command->line('  - Plan: Unlimited (no restrictions)');
        $this->command->line('  - Vertical: mechanic (AutoSpecs ready)');
    }

    /**
     * Seed the minimum automotive inventory required to hydrate the 6 demo
     * bundles. Idempotent via (tenant_id, sku) on products and
     * (tenant_id, code) on services. Units are tenant-scoped so the demo
     * remains self-contained if `UomSeeder` has not been run.
     *
     * @see docs/sessions/2026-04-20-autospecs-gap-closure-spec.md §2.3.3
     */
    private function seedAutomotiveCatalog(Tenant $tenant, Company $company): void
    {
        $units = $this->ensureAutomotiveUnits($tenant);

        /** @var list<array{sku: string, name: string, unit_code: string, sale_price: string, tax_rate: string}> $products */
        $products = [
            ['sku' => 'OIL-5W30-5L', 'name' => 'Huile moteur 5W30 (bidon 5L)', 'unit_code' => 'L', 'sale_price' => '85.000', 'tax_rate' => '19.000'],
            ['sku' => 'OIL-10W40-5L', 'name' => 'Huile moteur 10W40 (bidon 5L)', 'unit_code' => 'L', 'sale_price' => '75.000', 'tax_rate' => '19.000'],
            ['sku' => 'FILT-OIL-STD', 'name' => 'Filtre à huile standard', 'unit_code' => 'EA', 'sale_price' => '25.000', 'tax_rate' => '19.000'],
            ['sku' => 'FILT-OIL-DIESEL', 'name' => 'Filtre à huile diesel', 'unit_code' => 'EA', 'sale_price' => '30.000', 'tax_rate' => '19.000'],
            ['sku' => 'FILT-AIR-STD', 'name' => 'Filtre à air standard', 'unit_code' => 'EA', 'sale_price' => '18.000', 'tax_rate' => '19.000'],
            ['sku' => 'BRAKE-PAD-FRONT', 'name' => 'Plaquettes de frein (avant, jeu)', 'unit_code' => 'EA', 'sale_price' => '95.000', 'tax_rate' => '19.000'],
            ['sku' => 'BRAKE-DISC', 'name' => 'Disque de frein', 'unit_code' => 'EA', 'sale_price' => '120.000', 'tax_rate' => '19.000'],
            ['sku' => 'TIRE-195-65-R15', 'name' => 'Pneu 195/65 R15', 'unit_code' => 'EA', 'sale_price' => '210.000', 'tax_rate' => '19.000'],
            ['sku' => 'COOLANT-1L', 'name' => 'Liquide de refroidissement 1L', 'unit_code' => 'L', 'sale_price' => '22.000', 'tax_rate' => '19.000'],
            ['sku' => 'SPARK-PLUG', 'name' => "Bougie d'allumage", 'unit_code' => 'EA', 'sale_price' => '12.000', 'tax_rate' => '19.000'],
        ];

        foreach ($products as $spec) {
            Product::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'sku' => $spec['sku'],
                ],
                [
                    'company_id' => $company->id,
                    'name' => $spec['name'],
                    'type' => ProductType::Part,
                    'is_physical' => true,
                    'sale_price' => $spec['sale_price'],
                    'tax_rate' => $spec['tax_rate'],
                    'unit' => $spec['unit_code'],
                    'unit_id' => $units[$spec['unit_code']]->id,
                    'is_active' => true,
                ]
            );
        }

        /** @var list<array{code: string, name: string, pricing_type: PricingType, base_price: string, hourly_rate: ?string, default_duration_minutes: int, tax_rate: string}> $services */
        $services = [
            ['code' => 'LAB-OIL-CHANGE', 'name' => 'Vidange + remplacement filtres', 'pricing_type' => PricingType::Hourly, 'base_price' => '45.000', 'hourly_rate' => '45.000', 'default_duration_minutes' => 45, 'tax_rate' => '19.000'],
            ['code' => 'LAB-BRAKE-FRONT', 'name' => 'Remplacement plaquettes de frein avant', 'pricing_type' => PricingType::Hourly, 'base_price' => '45.000', 'hourly_rate' => '45.000', 'default_duration_minutes' => 60, 'tax_rate' => '19.000'],
            ['code' => 'LAB-ALIGN', 'name' => 'Parallélisme', 'pricing_type' => PricingType::FlatRate, 'base_price' => '80.000', 'hourly_rate' => null, 'default_duration_minutes' => 60, 'tax_rate' => '19.000'],
            ['code' => 'LAB-DIAG-OBD', 'name' => 'Diagnostic OBD électronique', 'pricing_type' => PricingType::FlatRate, 'base_price' => '60.000', 'hourly_rate' => null, 'default_duration_minutes' => 45, 'tax_rate' => '19.000'],
            ['code' => 'LAB-TIRE-MOUNT', 'name' => 'Montage + équilibrage pneu', 'pricing_type' => PricingType::Hourly, 'base_price' => '35.000', 'hourly_rate' => '35.000', 'default_duration_minutes' => 30, 'tax_rate' => '19.000'],
            ['code' => 'LAB-TIMING-BELT', 'name' => 'Remplacement courroie de distribution', 'pricing_type' => PricingType::Hourly, 'base_price' => '50.000', 'hourly_rate' => '50.000', 'default_duration_minutes' => 240, 'tax_rate' => '19.000'],
        ];

        foreach ($services as $spec) {
            Service::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => $spec['code'],
                ],
                [
                    'company_id' => $company->id,
                    'name' => $spec['name'],
                    'pricing_type' => $spec['pricing_type'],
                    'base_price' => $spec['base_price'],
                    'currency' => 'TND',
                    'hourly_rate' => $spec['hourly_rate'],
                    'default_duration_minutes' => $spec['default_duration_minutes'],
                    'tax_rate' => $spec['tax_rate'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->line(sprintf(
            '  - Automotive catalog: %d products + %d services seeded.',
            count($products),
            count($services),
        ));
    }

    /**
     * Ensure the four automotive-catalog units exist for this tenant.
     * Units are tenant-scoped (not `UomSeeder` system units) so re-runs
     * remain idempotent and the demo stays self-contained.
     *
     * @return array{L: Unit, EA: Unit, HR: Unit, KG: Unit}
     */
    private function ensureAutomotiveUnits(Tenant $tenant): array
    {
        $categories = [
            'volume' => UnitCategory::firstOrCreate(
                ['code' => 'autospecs_volume'],
                [
                    'name' => 'Automotive volume',
                    'description' => 'Volume units used by the mechanic demo seeder.',
                    'is_system' => false,
                    'is_active' => true,
                ],
            ),
            'pieces' => UnitCategory::firstOrCreate(
                ['code' => 'autospecs_pieces'],
                [
                    'name' => 'Automotive pieces',
                    'description' => 'Discrete-item units used by the mechanic demo seeder.',
                    'is_system' => false,
                    'is_active' => true,
                ],
            ),
            'time' => UnitCategory::firstOrCreate(
                ['code' => 'autospecs_time'],
                [
                    'name' => 'Automotive labor time',
                    'description' => 'Time-of-labor units used by the mechanic demo seeder.',
                    'is_system' => false,
                    'is_active' => true,
                ],
            ),
            'weight' => UnitCategory::firstOrCreate(
                ['code' => 'autospecs_weight'],
                [
                    'name' => 'Automotive weight',
                    'description' => 'Mass units used by the mechanic demo seeder.',
                    'is_system' => false,
                    'is_active' => true,
                ],
            ),
        ];

        $l = Unit::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'L'],
            [
                'category_id' => $categories['volume']->id,
                'name' => 'Litre',
                'symbol' => 'L',
                'conversion_factor' => '1',
                'decimal_places' => 3,
                'is_base_unit' => true,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        $ea = Unit::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'EA'],
            [
                'category_id' => $categories['pieces']->id,
                'name' => 'Each',
                'symbol' => 'ea',
                'conversion_factor' => '1',
                'decimal_places' => 0,
                'is_base_unit' => true,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        $hr = Unit::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'HR'],
            [
                'category_id' => $categories['time']->id,
                'name' => 'Hour',
                'symbol' => 'h',
                'conversion_factor' => '1',
                'decimal_places' => 2,
                'is_base_unit' => true,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        $kg = Unit::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'KG'],
            [
                'category_id' => $categories['weight']->id,
                'name' => 'Kilogram',
                'symbol' => 'kg',
                'conversion_factor' => '1',
                'decimal_places' => 3,
                'is_base_unit' => true,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        return [
            'L' => $l,
            'EA' => $ea,
            'HR' => $hr,
            'KG' => $kg,
        ];
    }

    /**
     * Seed 3 technician profiles (including the admin) + 2 certifications
     * so `CreateTimeEntryOnWorkOrderStarted` and the Plan-C UI have real
     * data on a clean seed.
     *
     * @see docs/sessions/2026-04-20-autospecs-gap-closure-spec.md §2.3.4
     */
    private function seedWorkshopTechnicians(Tenant $tenant, Company $company, User $adminUser): void
    {
        $fullSchedule = [
            'monday' => [['start' => '08:00', 'end' => '17:00']],
            'tuesday' => [['start' => '08:00', 'end' => '17:00']],
            'wednesday' => [['start' => '08:00', 'end' => '17:00']],
            'thursday' => [['start' => '08:00', 'end' => '17:00']],
            'friday' => [['start' => '08:00', 'end' => '17:00']],
            'saturday' => [],
            'sunday' => [],
        ];

        $halfDaySaturday = [
            'monday' => [['start' => '09:00', 'end' => '18:00']],
            'tuesday' => [['start' => '09:00', 'end' => '18:00']],
            'wednesday' => [['start' => '09:00', 'end' => '18:00']],
            'thursday' => [['start' => '09:00', 'end' => '18:00']],
            'friday' => [['start' => '09:00', 'end' => '18:00']],
            'saturday' => [['start' => '09:00', 'end' => '13:00']],
            'sunday' => [],
        ];

        // 1. Admin also wears the tech hat so the time-entry listener has
        //    a TechnicianProfile for the admin-started WO path.
        $this->ensureTechnicianProfile(
            $tenant,
            $company,
            $adminUser,
            SkillLevel::Master,
            [SpecialtyCode::GeneralService, SpecialtyCode::PreControl],
            '25.000',
            '60.000',
            'ADMIN-01',
            $fullSchedule,
        );

        // 2. Yassine Trabelsi — senior mechanic, diesel-capable.
        $yassine = $this->ensureTechnicianUser(
            $tenant,
            $company,
            'yassine.trabelsi@demo.local',
            'Yassine Trabelsi',
        );
        $yassineProfile = $this->ensureTechnicianProfile(
            $tenant,
            $company,
            $yassine,
            SkillLevel::Senior,
            [SpecialtyCode::EngineMechanical, SpecialtyCode::Brakes, SpecialtyCode::Diesel],
            '18.000',
            '45.000',
            'TECH-01',
            $fullSchedule,
        );
        $this->ensureCertification(
            $tenant,
            $yassineProfile,
            'ASE Master Technician',
            'ASE',
            'ASE-MASTER-2024-001',
        );

        // 3. Sarra Ben Ali — tires & alignment, half-day Saturday.
        $sarra = $this->ensureTechnicianUser(
            $tenant,
            $company,
            'sarra.ben-ali@demo.local',
            'Sarra Ben Ali',
        );
        $sarraProfile = $this->ensureTechnicianProfile(
            $tenant,
            $company,
            $sarra,
            SkillLevel::General,
            [SpecialtyCode::Tires, SpecialtyCode::Alignment, SpecialtyCode::AcClimate],
            '14.000',
            '35.000',
            'TECH-02',
            $halfDaySaturday,
        );
        $this->ensureCertification(
            $tenant,
            $sarraProfile,
            'Michelin Tire Certified',
            'Michelin',
            'MICH-TC-2024-045',
        );

        $this->command->line('  - Workshop technicians: 3 profiles (1 admin + 2 named) + 2 certifications.');
    }

    /**
     * Create or refresh a user that doubles as a technician. Users bootstrap
     * with Active status + verified email + a primary `Technician` membership
     * + the spatie `technician` role, mirroring Plan C #1/#2/#3.
     */
    private function ensureTechnicianUser(
        Tenant $tenant,
        Company $company,
        string $email,
        string $name,
    ): User {
        /** @var User $user */
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $name,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'status' => UserStatus::Active,
            ]
        );

        UserCompanyMembership::updateOrCreate(
            [
                'user_id' => $user->id,
                'company_id' => $company->id,
            ],
            [
                'role' => MembershipRole::Technician,
                'is_primary' => true,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]
        );

        // Spatie role assignment is team-scoped on tenant_id.
        setPermissionsTeamId($tenant->id);
        $technicianRole = Role::where('name', 'technician')
            ->where('guard_name', 'sanctum')
            ->first();
        if ($technicianRole !== null && ! $user->hasRole($technicianRole)) {
            $user->assignRole($technicianRole);
        }

        return $user;
    }

    /**
     * Create or refresh a technician profile. Keyed on (tenant, company,
     * user) per the migration's unique partial index.
     *
     * @param  list<SpecialtyCode>  $specialties
     * @param  array<string, list<array{start: string, end: string}>>  $weeklySchedule
     */
    private function ensureTechnicianProfile(
        Tenant $tenant,
        Company $company,
        User $user,
        SkillLevel $skillLevel,
        array $specialties,
        string $hourlyCostRate,
        string $hourlyBillingRate,
        string $employeeCode,
        array $weeklySchedule,
    ): TechnicianProfile {
        /** @var TechnicianProfile $profile */
        $profile = TechnicianProfile::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'user_id' => $user->id,
            ],
            [
                'skill_level' => $skillLevel,
                'specialties' => array_map(static fn (SpecialtyCode $c): string => $c->value, $specialties),
                'hourly_cost_rate' => $hourlyCostRate,
                'hourly_billing_rate' => $hourlyBillingRate,
                'currency' => 'TND',
                'weekly_schedule' => $weeklySchedule,
                'employment_status' => EmploymentStatus::Active,
                'employee_code' => $employeeCode,
                'is_active' => true,
            ]
        );

        return $profile;
    }

    /**
     * Attach a single certification to a technician profile. Idempotent via
     * (tenant, technician_profile, certification_name, certificate_number).
     */
    private function ensureCertification(
        Tenant $tenant,
        TechnicianProfile $profile,
        string $name,
        string $issuingBody,
        string $certificateNumber,
    ): void {
        TechnicianCertification::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'technician_profile_id' => $profile->id,
                'certification_name' => $name,
                'certificate_number' => $certificateNumber,
            ],
            [
                'issuing_body' => $issuingBody,
                'issued_at' => now()->subYear()->format('Y-m-d'),
                'expires_at' => now()->addYears(2)->format('Y-m-d'),
            ]
        );
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
     * Wire components for each of the 6 seeded bundle headers. Runs after
     * `seedAutomotiveCatalog` + `seedWorkshopBundles`; safe to re-run.
     *
     * @see docs/sessions/2026-04-20-autospecs-gap-closure-spec.md §2.3.5
     */
    private function hydrateBundleComponents(Tenant $tenant, Company $company): void
    {
        /** @var array<string, Product> $products */
        $products = Product::where('tenant_id', $tenant->id)
            ->whereIn('sku', [
                'OIL-5W30-5L', 'OIL-10W40-5L', 'FILT-OIL-STD', 'FILT-OIL-DIESEL',
                'FILT-AIR-STD', 'BRAKE-PAD-FRONT', 'BRAKE-DISC', 'TIRE-195-65-R15',
                'COOLANT-1L', 'SPARK-PLUG',
            ])
            ->get()
            ->keyBy('sku')
            ->all();

        /** @var array<string, Service> $services */
        $services = Service::where('tenant_id', $tenant->id)
            ->whereIn('code', [
                'LAB-OIL-CHANGE', 'LAB-BRAKE-FRONT', 'LAB-ALIGN',
                'LAB-DIAG-OBD', 'LAB-TIRE-MOUNT', 'LAB-TIMING-BELT',
            ])
            ->get()
            ->keyBy('code')
            ->all();

        /** @var array<string, ServiceBundle> $bundles */
        $bundles = ServiceBundle::where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->whereIn('code', [
                'VIDANGE-10K-ESSENCE', 'VIDANGE-10K-DIESEL', 'FREINAGE-AV',
                'REVISION-40K', 'PNEUS-REMPLACEMENT-4', 'DIAGNOSTIC-OBD',
            ])
            ->get()
            ->keyBy('code')
            ->all();

        $units = $this->ensureAutomotiveUnits($tenant);

        /** @var array<string, list<array{type: BundleComponentType, ref_key: string, qty: string, unit_code: string}>> $recipes */
        $recipes = [
            'VIDANGE-10K-ESSENCE' => [
                ['type' => BundleComponentType::Part, 'ref_key' => 'OIL-5W30-5L', 'qty' => '1.000', 'unit_code' => 'L'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'FILT-OIL-STD', 'qty' => '1.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-OIL-CHANGE', 'qty' => '0.750', 'unit_code' => 'HR'],
            ],
            'VIDANGE-10K-DIESEL' => [
                ['type' => BundleComponentType::Part, 'ref_key' => 'OIL-10W40-5L', 'qty' => '1.000', 'unit_code' => 'L'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'FILT-OIL-DIESEL', 'qty' => '1.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'FILT-AIR-STD', 'qty' => '1.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-OIL-CHANGE', 'qty' => '1.000', 'unit_code' => 'HR'],
            ],
            'FREINAGE-AV' => [
                ['type' => BundleComponentType::Part, 'ref_key' => 'BRAKE-PAD-FRONT', 'qty' => '1.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'BRAKE-DISC', 'qty' => '2.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-BRAKE-FRONT', 'qty' => '1.000', 'unit_code' => 'HR'],
            ],
            'REVISION-40K' => [
                ['type' => BundleComponentType::NestedBundle, 'ref_key' => 'VIDANGE-10K-ESSENCE', 'qty' => '1.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'COOLANT-1L', 'qty' => '2.000', 'unit_code' => 'L'],
                ['type' => BundleComponentType::Part, 'ref_key' => 'SPARK-PLUG', 'qty' => '4.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-TIMING-BELT', 'qty' => '4.000', 'unit_code' => 'HR'],
            ],
            'PNEUS-REMPLACEMENT-4' => [
                ['type' => BundleComponentType::Part, 'ref_key' => 'TIRE-195-65-R15', 'qty' => '4.000', 'unit_code' => 'EA'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-TIRE-MOUNT', 'qty' => '2.000', 'unit_code' => 'HR'],
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-ALIGN', 'qty' => '1.000', 'unit_code' => 'EA'],
            ],
            'DIAGNOSTIC-OBD' => [
                ['type' => BundleComponentType::Labor, 'ref_key' => 'LAB-DIAG-OBD', 'qty' => '1.000', 'unit_code' => 'EA'],
            ],
        ];

        $componentCount = 0;
        foreach ($recipes as $bundleCode => $components) {
            if (! isset($bundles[$bundleCode])) {
                $this->command->warn("  - hydrateBundleComponents: missing bundle {$bundleCode}, skipping.");

                continue;
            }
            $bundle = $bundles[$bundleCode];

            foreach ($components as $index => $spec) {
                $displayOrder = $index + 1;
                $attributes = [
                    'component_type' => $spec['type'],
                    'quantity' => $spec['qty'],
                    'unit_id' => $units[$spec['unit_code']]->id,
                    'is_optional' => false,
                    'product_id' => null,
                    'service_id' => null,
                    'nested_bundle_id' => null,
                ];

                switch ($spec['type']) {
                    case BundleComponentType::Part:
                        if (! isset($products[$spec['ref_key']])) {
                            $this->command->warn("  - hydrateBundleComponents: missing product {$spec['ref_key']} for {$bundleCode}, skipping.");

                            continue 2;
                        }
                        $attributes['product_id'] = $products[$spec['ref_key']]->id;
                        break;
                    case BundleComponentType::Labor:
                        if (! isset($services[$spec['ref_key']])) {
                            $this->command->warn("  - hydrateBundleComponents: missing service {$spec['ref_key']} for {$bundleCode}, skipping.");

                            continue 2;
                        }
                        $attributes['service_id'] = $services[$spec['ref_key']]->id;
                        break;
                    case BundleComponentType::NestedBundle:
                        if (! isset($bundles[$spec['ref_key']])) {
                            $this->command->warn("  - hydrateBundleComponents: missing nested bundle {$spec['ref_key']} for {$bundleCode}, skipping.");

                            continue 2;
                        }
                        $attributes['nested_bundle_id'] = $bundles[$spec['ref_key']]->id;
                        break;
                }

                ServiceBundleComponent::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'bundle_id' => $bundle->id,
                        'display_order' => $displayOrder,
                    ],
                    $attributes,
                );
                $componentCount++;
            }
        }

        $this->command->line("  - Bundle components: hydrated {$componentCount} component rows across ".count($recipes).' bundles.');
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
     * Seed Scheduling demo data: 3 bays, 1 schedule config, 10-15
     * appointments across the next 7 days covering every relevant status
     * (Scheduled, Confirmed, CheckedIn, Converted, Cancelled). Two of the
     * converted appointments are actually converted via
     * `AppointmentConversionService::convertToWorkOrder` so the Plan-B
     * mirror wiring is exercised end-to-end in demos.
     *
     * Idempotent via a sentinel-row check on `scheduling_bays`.
     */
    private function seedScheduling(Tenant $tenant, Company $company, User $openedBy): void
    {
        if (Bay::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->exists()
        ) {
            return;
        }

        // Ensure a default Location exists for the company. The backfill
        // migration creates one automatically for existing companies, but
        // when the seeder runs against a fresh DB the company row was
        // just created so we make sure a `Main Location` exists.
        $location = Location::firstOrCreate(
            [
                'company_id' => $company->id,
                'code' => 'MAIN',
            ],
            [
                'name' => 'Main Location',
                'type' => 'shop',
                'address_country' => $company->country_code,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        // Typical automotive shop operating hours: Mon-Fri 08:00-18:00,
        // Sat 08:00-13:00, Sun closed. Same shape the Bay `operating_hours`
        // jsonb column expects.
        $operatingHours = [
            'mon' => [['start' => '08:00', 'end' => '18:00']],
            'tue' => [['start' => '08:00', 'end' => '18:00']],
            'wed' => [['start' => '08:00', 'end' => '18:00']],
            'thu' => [['start' => '08:00', 'end' => '18:00']],
            'fri' => [['start' => '08:00', 'end' => '18:00']],
            'sat' => [['start' => '08:00', 'end' => '13:00']],
            'sun' => [],
        ];

        $baySpecs = [
            ['code' => 'B1', 'name' => 'Bay 1', 'type' => BayType::General, 'order' => 1],
            ['code' => 'B2', 'name' => 'Bay 2', 'type' => BayType::QuickService, 'order' => 2],
            ['code' => 'ALN', 'name' => 'Alignment Bay', 'type' => BayType::Alignment, 'order' => 3],
        ];

        /** @var list<Bay> $bays */
        $bays = [];
        foreach ($baySpecs as $spec) {
            $bay = new Bay;
            $bay->id = (string) Str::uuid();
            $bay->tenant_id = $tenant->id;
            $bay->company_id = $company->id;
            $bay->location_id = $location->id;
            $bay->code = $spec['code'];
            $bay->name = $spec['name'];
            $bay->bay_type = $spec['type'];
            $bay->display_order = $spec['order'];
            $bay->operating_hours = $operatingHours;
            $bay->is_active = true;
            $bay->save();
            $bays[] = $bay;
        }

        // Per-location schedule config — 15-minute slots, 60-minute default
        // appointment duration, hybrid online booking enabled for demo.
        $config = ScheduleConfig::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
            ],
            [
                'time_slot_minutes' => 15,
                'default_appointment_duration_minutes' => 60,
                'walk_in_buffer_hours_per_day' => '2.00',
                'overbooking_threshold_percent' => 100,
                'online_booking_enabled' => true,
                'online_booking_advance_days' => 14,
                'online_booking_min_notice_hours' => 2,
                'online_booking_auto_confirm' => false,
                'reminder_sms_hours_before' => 24,
                'reminder_email_hours_before' => 48,
            ],
        );
        unset($config); // Intentionally unused after creation — hydrated via fresh reads.

        // Reuse the partner+vehicle pairs already seeded by seedWorkOrders
        // so every appointment has a concrete customer + vehicle (required
        // by AppointmentConversionService::assertConvertible).
        $partners = Partner::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('type', 'customer')
            ->orderBy('code')
            ->take(5)
            ->get();

        $vehicles = Vehicle::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->orderBy('license_plate')
            ->take(5)
            ->get();

        if ($partners->isEmpty() || $vehicles->isEmpty()) {
            $this->command->warn('  - Cannot seed scheduling appointments: no partners/vehicles seeded. Run seedWorkOrders first.');

            return;
        }

        // Grab one seeded bundle for the `service_ref_id` of the planned-
        // service rows. Bundle ID is required so that the WorkOrder
        // conversion path (`addBundle`) resolves to a real bundle.
        $bundle = ServiceBundle::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->first();

        /** @var AppointmentSequenceInterface $sequencer */
        $sequencer = app(AppointmentSequenceInterface::class);
        $year = (int) now()->format('Y');

        /** @var list<array{offset_days: int, hour: int, minutes_duration: int, status: AppointmentStatus, type: AppointmentType, source: AppointmentSource, convert: bool}> $plan */
        $plan = [
            ['offset_days' => 0, 'hour' => 9, 'minutes_duration' => 60, 'status' => AppointmentStatus::Scheduled, 'type' => AppointmentType::StandardRepair, 'source' => AppointmentSource::Manual, 'convert' => false],
            ['offset_days' => 0, 'hour' => 10, 'minutes_duration' => 45, 'status' => AppointmentStatus::Confirmed, 'type' => AppointmentType::QuickService, 'source' => AppointmentSource::Phone, 'convert' => true],
            ['offset_days' => 0, 'hour' => 14, 'minutes_duration' => 90, 'status' => AppointmentStatus::CheckedIn, 'type' => AppointmentType::Diagnostic, 'source' => AppointmentSource::Online, 'convert' => true],
            ['offset_days' => 0, 'hour' => 16, 'minutes_duration' => 60, 'status' => AppointmentStatus::Cancelled, 'type' => AppointmentType::TireService, 'source' => AppointmentSource::Phone, 'convert' => false],
            ['offset_days' => 1, 'hour' => 8, 'minutes_duration' => 120, 'status' => AppointmentStatus::Scheduled, 'type' => AppointmentType::MajorRepair, 'source' => AppointmentSource::Manual, 'convert' => false],
            ['offset_days' => 1, 'hour' => 11, 'minutes_duration' => 60, 'status' => AppointmentStatus::Confirmed, 'type' => AppointmentType::Maintenance, 'source' => AppointmentSource::Online, 'convert' => false],
            ['offset_days' => 2, 'hour' => 9, 'minutes_duration' => 45, 'status' => AppointmentStatus::Scheduled, 'type' => AppointmentType::Inspection, 'source' => AppointmentSource::Walkin, 'convert' => false],
            ['offset_days' => 2, 'hour' => 15, 'minutes_duration' => 90, 'status' => AppointmentStatus::Confirmed, 'type' => AppointmentType::Bodywork, 'source' => AppointmentSource::Phone, 'convert' => false],
            ['offset_days' => 3, 'hour' => 8, 'minutes_duration' => 60, 'status' => AppointmentStatus::Scheduled, 'type' => AppointmentType::StandardRepair, 'source' => AppointmentSource::Manual, 'convert' => false],
            ['offset_days' => 4, 'hour' => 10, 'minutes_duration' => 60, 'status' => AppointmentStatus::Scheduled, 'type' => AppointmentType::QuickService, 'source' => AppointmentSource::Online, 'convert' => false],
            ['offset_days' => 5, 'hour' => 9, 'minutes_duration' => 120, 'status' => AppointmentStatus::Confirmed, 'type' => AppointmentType::Diagnostic, 'source' => AppointmentSource::Manual, 'convert' => false],
            ['offset_days' => 6, 'hour' => 11, 'minutes_duration' => 60, 'status' => AppointmentStatus::Cancelled, 'type' => AppointmentType::Maintenance, 'source' => AppointmentSource::Phone, 'convert' => false],
        ];

        $converted = 0;
        foreach ($plan as $i => $spec) {
            /** @var Partner $partner */
            $partner = $partners[$i % $partners->count()];
            /** @var Vehicle $vehicle */
            $vehicle = $vehicles[$i % $vehicles->count()];
            /** @var Bay $bay */
            $bay = $bays[$i % count($bays)];

            $start = Carbon::now()
                ->startOfDay()
                ->addDays($spec['offset_days'])
                ->addHours($spec['hour']);
            $end = $start->copy()->addMinutes($spec['minutes_duration']);

            $appointment = new Appointment;
            $appointment->id = (string) Str::uuid();
            $appointment->tenant_id = $tenant->id;
            $appointment->company_id = $company->id;
            $appointment->location_id = $location->id;
            $appointment->appointment_number = $sequencer->nextNumber($company->id, $year);
            $appointment->bay_id = $bay->id;
            $appointment->primary_technician_profile_id = null;
            $appointment->customer_partner_id = $partner->id;
            $appointment->vehicle_id = $vehicle->id;
            $appointment->customer_name = $partner->name;
            $appointment->customer_phone = $partner->phone;
            $appointment->customer_email = $partner->email;
            $appointment->vehicle_plate = $vehicle->license_plate;
            $appointment->vehicle_description = trim(($vehicle->brand ?? '').' '.($vehicle->model ?? ''));
            $appointment->appointment_type = $spec['type'];
            $appointment->wait_type = WaitType::DropOff;
            $appointment->status = $spec['status'];
            $appointment->scheduled_start = $start;
            $appointment->scheduled_end = $end;
            $appointment->estimated_duration_minutes = $spec['minutes_duration'];
            $appointment->actual_arrival_at = $spec['status'] === AppointmentStatus::CheckedIn
                ? $start->copy()->subMinutes(5)
                : null;
            $appointment->services_summary = "Demo appointment — {$spec['type']->value}";
            $appointment->source = $spec['source'];
            $appointment->is_auto_confirmed = false;
            $appointment->save();

            // One planned-service row per appointment, pointing at a seeded
            // bundle when available (required for the convert path to land
            // a real WorkOrder line via `addBundle`).
            if ($bundle !== null) {
                $service = new SchedulingAppointmentService;
                $service->id = (string) Str::uuid();
                $service->tenant_id = $tenant->id;
                $service->appointment_id = $appointment->id;
                $service->service_ref_type = SchedulingAppointmentService::REF_TYPE_BUNDLE;
                $service->service_ref_id = $bundle->id;
                $service->display_name = $bundle->name;
                $service->estimated_duration_minutes = $spec['minutes_duration'];
                $service->estimated_price = $bundle->base_price ?? '0.000';
                $service->display_order = 0;
                $service->save();
            }

            // For the two appointments flagged `convert`, actually run the
            // Plan-B conversion path so the Mirror* listeners get exercised.
            // We restore the original `status` after the conversion because
            // AppointmentConversionService keeps the pre-conversion status
            // intact (per Spec D §7.2) — the Mirror listeners advance it
            // when the WorkOrder transitions, which isn't happening here.
            if ($spec['convert'] && $converted < 2 && $bundle !== null) {
                $originalStatus = $appointment->status;
                /** @var AppointmentConversionService $conversion */
                $conversion = app(AppointmentConversionService::class);
                try {
                    $conversion->convertToWorkOrder($appointment->id, $openedBy->id);
                    $converted++;
                    // Re-hydrate and keep the demo-friendly status so the
                    // Scheduler view still shows "Confirmed" / "CheckedIn"
                    // rather than the (unchanged) pre-conversion status.
                    $fresh = $appointment->fresh();
                    if ($fresh !== null) {
                        $fresh->status = $originalStatus;
                        $fresh->save();
                    }
                } catch (\Throwable $e) {
                    $this->command->warn("  - Skipped conversion for demo appointment: {$e->getMessage()}");
                }
            }
        }

        $this->command->line(sprintf(
            '  - Seeded %d bays + 1 schedule config + %d appointments (%d converted to work orders) for mechanic demo.',
            count($bays),
            count($plan),
            $converted,
        ));
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
