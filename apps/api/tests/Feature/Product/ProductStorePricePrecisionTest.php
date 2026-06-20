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
 * Money precision contract (rule 19): currency is stored at a 3-decimal floor.
 * Tunisia's dinar (TND) uses millimes — three decimals — so the product create
 * path must accept a 3-decimal sale/purchase price. The regex must not cap at
 * 2dp (which silently blocked normal TND pricing) but must still reject 4dp.
 */
class ProductStorePricePrecisionTest extends TestCase
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
            'slug' => 'test-tenant-price-tn',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

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
            'email' => 'user@example-price-tn.com',
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

    public function test_sale_price_accepts_three_decimals_for_millime_currency(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Café Express 250g',
                'sku' => 'CAFE-250',
                'sale_price' => '12.750',
            ]);

        $response->assertCreated();

        $product = \DB::table('products')->where('sku', 'CAFE-250')->first();
        $this->assertNotNull($product);
        $this->assertSame(0, bccomp((string) $product->sale_price, '12.750', 3));
    }

    public function test_purchase_price_accepts_three_decimals(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Sucre 1kg',
                'sku' => 'SUCRE-1KG',
                'sale_price' => '3.500',
                'purchase_price' => '2.125',
            ]);

        $response->assertCreated();
    }

    public function test_sale_price_still_rejects_four_decimals(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Too Precise',
                'sku' => 'TOO-PRECISE',
                'sale_price' => '12.7505',
            ]);

        $response->assertStatus(422);
        // App wraps validation errors in a custom envelope: error.errors.<field>.
        $this->assertArrayHasKey('sale_price', $response->json('error.errors'));
    }
}
