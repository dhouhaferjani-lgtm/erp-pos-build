<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase A.1 spec §2.4 — Failing tests asserting the demo seeder produces an
 * AutoSpecs-ready mechanic demo tenant. These must run green after §2.3 lands.
 */
final class DemoTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(DemoTenantSeeder::class);
    }

    private function getDemoTenant(): Tenant
    {
        $tenant = Tenant::where('slug', 'demo-unlimited')->firstOrFail();

        return $tenant;
    }

    private function getDemoCompany(Tenant $tenant): Company
    {
        return Company::where('tenant_id', $tenant->id)
            ->where('code', 'DEMO')
            ->firstOrFail();
    }

    public function test_fresh_seed_creates_mechanic_demo_tenant_with_correct_vertical(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();
        $this->assertSame(Vertical::Mechanic, $tenant->vertical);
    }

    public function test_admin_demo_local_user_is_active_and_verified_and_has_admin_role(): void
    {
        $this->seedDemo();

        /** @var User $user */
        $user = User::where('email', 'admin@demo.local')->firstOrFail();

        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->email_verified_at);

        setPermissionsTeamId($user->tenant_id);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_admin_demo_local_has_primary_company_membership_with_admin_role(): void
    {
        $this->seedDemo();

        /** @var User $user */
        $user = User::where('email', 'admin@demo.local')->firstOrFail();

        /** @var UserCompanyMembership $membership */
        $membership = UserCompanyMembership::where('user_id', $user->id)
            ->where('is_primary', true)
            ->firstOrFail();

        $this->assertSame(MembershipRole::Admin, $membership->role);
        $this->assertSame(MembershipStatus::Active, $membership->status);
    }

    public function test_seeder_creates_at_least_3_technician_profiles_for_mechanic_tenant(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();
        $company = $this->getDemoCompany($tenant);

        $count = TechnicianProfile::where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->count();

        $this->assertGreaterThanOrEqual(3, $count);
    }

    public function test_admin_demo_local_has_technician_profile_for_listener_safety(): void
    {
        $this->seedDemo();

        /** @var User $user */
        $user = User::where('email', 'admin@demo.local')->firstOrFail();

        $profile = TechnicianProfile::where('user_id', $user->id)->first();

        $this->assertNotNull(
            $profile,
            'admin@demo.local must have a TechnicianProfile so CreateTimeEntryOnWorkOrderStarted '.
            'listener does not blow up when the admin starts a WO in demos.'
        );
    }

    public function test_all_6_bundles_have_at_least_1_component_after_seed(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();

        $codes = [
            'VIDANGE-10K-ESSENCE',
            'VIDANGE-10K-DIESEL',
            'FREINAGE-AV',
            'REVISION-40K',
            'PNEUS-REMPLACEMENT-4',
            'DIAGNOSTIC-OBD',
        ];

        foreach ($codes as $code) {
            /** @var ServiceBundle $bundle */
            $bundle = ServiceBundle::where('tenant_id', $tenant->id)
                ->where('code', $code)
                ->firstOrFail();

            $componentCount = ServiceBundleComponent::where('bundle_id', $bundle->id)->count();
            $this->assertGreaterThanOrEqual(
                1,
                $componentCount,
                "Bundle {$code} must have at least 1 component seeded."
            );
        }
    }

    public function test_vidange_10k_essence_has_exact_3_components_in_correct_order(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();

        /** @var ServiceBundle $bundle */
        $bundle = ServiceBundle::where('tenant_id', $tenant->id)
            ->where('code', 'VIDANGE-10K-ESSENCE')
            ->firstOrFail();

        /** @var array<int, ServiceBundleComponent> $components */
        $components = ServiceBundleComponent::where('bundle_id', $bundle->id)
            ->orderBy('display_order')
            ->get()
            ->all();

        $this->assertCount(3, $components);

        $this->assertSame(BundleComponentType::Part, $components[0]->component_type);
        $this->assertNotNull($components[0]->product_id);

        $this->assertSame(BundleComponentType::Part, $components[1]->component_type);
        $this->assertNotNull($components[1]->product_id);

        $this->assertSame(BundleComponentType::Labor, $components[2]->component_type);
        $this->assertNotNull($components[2]->service_id);

        // Products should be resolvable by SKU.
        /** @var Product $first */
        $first = Product::findOrFail($components[0]->product_id);
        $this->assertSame('OIL-5W30-5L', $first->sku);

        /** @var Product $second */
        $second = Product::findOrFail($components[1]->product_id);
        $this->assertSame('FILT-OIL-STD', $second->sku);
    }

    public function test_tunisia_chart_of_accounts_seeded_for_mechanic_tenant_company(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();
        $company = $this->getDemoCompany($tenant);

        $accountCount = DB::table('accounts')
            ->where('company_id', $company->id)
            ->count();

        $this->assertGreaterThan(
            10,
            $accountCount,
            'Tunisia chart of accounts should seed more than 10 GL accounts for the demo company.'
        );
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();
        $company = $this->getDemoCompany($tenant);

        $productsBefore = Product::where('tenant_id', $tenant->id)->count();
        $servicesBefore = Service::where('tenant_id', $tenant->id)->count();
        $profilesBefore = TechnicianProfile::where('tenant_id', $tenant->id)->count();
        $bundlesBefore = ServiceBundle::where('tenant_id', $tenant->id)->count();
        $componentsBefore = ServiceBundleComponent::where('tenant_id', $tenant->id)->count();
        $accountsBefore = DB::table('accounts')->where('company_id', $company->id)->count();

        // Re-seed.
        $this->seedDemo();

        $this->assertSame($productsBefore, Product::where('tenant_id', $tenant->id)->count(), 'products duplicated');
        $this->assertSame($servicesBefore, Service::where('tenant_id', $tenant->id)->count(), 'services duplicated');
        $this->assertSame($profilesBefore, TechnicianProfile::where('tenant_id', $tenant->id)->count(), 'technician profiles duplicated');
        $this->assertSame($bundlesBefore, ServiceBundle::where('tenant_id', $tenant->id)->count(), 'bundles duplicated');
        $this->assertSame($componentsBefore, ServiceBundleComponent::where('tenant_id', $tenant->id)->count(), 'bundle components duplicated');
        $this->assertSame($accountsBefore, DB::table('accounts')->where('company_id', $company->id)->count(), 'accounts duplicated');
    }

    public function test_automotive_catalog_seeds_expected_products_and_services(): void
    {
        $this->seedDemo();

        $tenant = $this->getDemoTenant();

        $expectedProductSkus = [
            'OIL-5W30-5L',
            'OIL-10W40-5L',
            'FILT-OIL-STD',
            'FILT-OIL-DIESEL',
            'FILT-AIR-STD',
            'BRAKE-PAD-FRONT',
            'BRAKE-DISC',
            'TIRE-195-65-R15',
            'COOLANT-1L',
            'SPARK-PLUG',
        ];
        foreach ($expectedProductSkus as $sku) {
            $this->assertDatabaseHas('products', [
                'tenant_id' => $tenant->id,
                'sku' => $sku,
                'is_active' => true,
            ]);
        }
        $this->assertSame(
            count($expectedProductSkus),
            Product::where('tenant_id', $tenant->id)
                ->whereIn('sku', $expectedProductSkus)
                ->count()
        );

        $expectedServiceCodes = [
            'LAB-OIL-CHANGE',
            'LAB-BRAKE-FRONT',
            'LAB-ALIGN',
            'LAB-DIAG-OBD',
            'LAB-TIRE-MOUNT',
            'LAB-TIMING-BELT',
        ];
        foreach ($expectedServiceCodes as $code) {
            $this->assertDatabaseHas('services', [
                'tenant_id' => $tenant->id,
                'code' => $code,
                'is_active' => true,
            ]);
        }
        $this->assertSame(
            count($expectedServiceCodes),
            Service::where('tenant_id', $tenant->id)
                ->whereIn('code', $expectedServiceCodes)
                ->count()
        );
    }
}
