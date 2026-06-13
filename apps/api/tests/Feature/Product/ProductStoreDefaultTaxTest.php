<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 13: Product API create path resolves default tax when tax_rate is absent.
 *
 * Invariant: a product persisted via POST /api/v1/products must never have a
 * NULL tax_rate.  The controller now resolves the default via TaxResolutionService
 * using the category (if supplied) then the company fallback.
 */
class ProductStoreDefaultTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant TN',
            'slug' => 'test-tenant-tn',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TN company with a 19.00 default tax rate (standard Tunisian TVA).
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company TN',
            'legal_name' => 'Test Company TN LLC',
            'tax_id' => 'TN123456',
            'country_code' => 'TN',
            'locale' => 'ar_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example-tn.com',
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

    /**
     * Test A: POST with no tax_rate → product gets company default (19.00).
     */
    public function test_product_created_without_tax_rate_gets_company_default(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Vitamin C 1000mg',
                'sku' => 'VIT-C-001',
                'sale_price' => '12.00',
            ]);

        $response->assertCreated();

        // The resolved tax_rate must be '19.00' (or string representation thereof).
        $taxRate = $response->json('data.tax_rate');
        $this->assertNotNull($taxRate, 'tax_rate must not be null after creation');
        $this->assertSame(
            0,
            bccomp((string) $taxRate, '19.00', 2),
            "Expected tax_rate ≈ 19.00, got {$taxRate}"
        );

        // Verify the DB also has a non-null rate.
        $this->assertDatabaseHas('products', [
            'sku' => 'VIT-C-001',
        ]);

        $product = \DB::table('products')->where('sku', 'VIT-C-001')->first();
        $this->assertNotNull($product);
        $this->assertNotNull($product->tax_rate, 'DB row tax_rate must not be null');
        $this->assertSame(0, bccomp((string) $product->tax_rate, '19.00', 2));
    }

    /**
     * Test B: POST with explicit tax_rate is preserved (no overwrite).
     */
    public function test_explicit_tax_rate_is_preserved(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Omega-3 Capsules',
                'sku' => 'OMG-3-001',
                'sale_price' => '25.00',
                'tax_rate' => '7.00',
            ]);

        $response->assertCreated();

        $taxRate = $response->json('data.tax_rate');
        $this->assertNotNull($taxRate);
        $this->assertSame(
            0,
            bccomp((string) $taxRate, '7.00', 2),
            "Expected tax_rate ≈ 7.00 (explicit), got {$taxRate}"
        );
    }

    /**
     * Test C: POST with a category that has its own default_tax_rate → product
     * inherits the category rate, not the company rate.
     */
    public function test_product_without_tax_rate_inherits_category_default(): void
    {
        // Create a category with a specific default_tax_rate of 13.00.
        // categories table uses company_id (not tenant_id) + requires slug.
        $categoryId = \DB::table('categories')->insertGetId([
            'company_id' => $this->company->id,
            'name' => 'Supplements',
            'slug' => 'supplements',
            'default_tax_rate' => '13.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Magnesium 400mg',
                'sku' => 'MAG-001',
                'sale_price' => '8.00',
                'category_id' => $categoryId,
                // no tax_rate supplied
            ]);

        $response->assertCreated();

        $taxRate = $response->json('data.tax_rate');
        $this->assertNotNull($taxRate);
        $this->assertSame(
            0,
            bccomp((string) $taxRate, '13.00', 2),
            "Expected category-derived tax_rate ≈ 13.00, got {$taxRate}"
        );
    }
}
