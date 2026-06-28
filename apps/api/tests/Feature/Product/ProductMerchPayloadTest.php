<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductSkinSuitability;
use App\Modules\Product\Domain\Routine;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\SkinType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductMerchPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Parapharmacy Merch',
            'slug' => 'test-parapharmacy-merch',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Merch Company',
            'legal_name' => 'Test Merch Company LLC',
            'tax_id' => 'TAX789',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Merch User',
            'email' => 'merch@parapharmacy.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_returns_merchandising_arrays_in_parapharmacy_metadata_payload(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Hydrating Serum',
            'sku' => 'HYD-SER-001',
        ]);

        ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        // Skin suitabilities
        ProductSkinSuitability::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'skin_type' => SkinType::Oily->value,
        ]);
        ProductSkinSuitability::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'skin_type' => SkinType::Sensitive->value,
        ]);

        // Routine
        $routine = Routine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Morning Glow Routine',
        ]);
        DB::table('product_routine')->insert([
            'id' => Str::uuid()->toString(),
            'routine_id' => $routine->id,
            'product_id' => $product->id,
            'step_order' => 2,
            'step_label' => 'Apply serum',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Equivalent product
        $equiv = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Equiv Serum',
            'sku' => 'EQUIV-001',
        ]);
        DB::table('product_equivalents')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'equivalent_product_id' => $equiv->id,
            'equivalence_type' => 'generic',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Complement product
        $complement = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Moisturiser',
            'sku' => 'MOIST-001',
        ]);
        DB::table('product_complements')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'complement_product_id' => $complement->id,
            'reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200);

        // suitable_skin_types
        $skinTypes = $response->json('data.parapharmacy_metadata.suitable_skin_types');
        $this->assertIsArray($skinTypes);
        $this->assertCount(2, $skinTypes);
        $this->assertContains('oily', $skinTypes);
        $this->assertContains('sensitive', $skinTypes);

        // equivalent_product_ids
        $equivIds = $response->json('data.parapharmacy_metadata.equivalent_product_ids');
        $this->assertIsArray($equivIds);
        $this->assertContains($equiv->id, $equivIds);

        // complement_product_ids
        $compIds = $response->json('data.parapharmacy_metadata.complement_product_ids');
        $this->assertIsArray($compIds);
        $this->assertContains($complement->id, $compIds);

        // routine_refs
        $routineRefs = $response->json('data.parapharmacy_metadata.routine_refs');
        $this->assertIsArray($routineRefs);
        $this->assertCount(1, $routineRefs);
        $this->assertSame($routine->id, $routineRefs[0]['routine_id']);
        $this->assertSame(2, $routineRefs[0]['step_order']);
        $this->assertSame('Apply serum', $routineRefs[0]['step_label']);
    }

    /** @test */
    public function it_omits_parapharmacy_metadata_for_non_parapharmacy_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Mechanic Tenant',
            'slug' => 'mechanic-tenant-merch-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Mechanic Co',
            'legal_name' => 'Mechanic Co LLC',
            'tax_id' => 'TAXMEC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Mechanic User',
            'email' => 'mechanic@merch-test.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $otherUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($otherCompany->id);

        $product = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Brake Pad',
            'sku' => 'BRAKE-MERCH-001',
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonMissing(['parapharmacy_metadata']);
    }
}
