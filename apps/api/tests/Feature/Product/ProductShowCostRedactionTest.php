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
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost/margin confidentiality (Rev 3 R3-2 / M0 finding F2).
 *
 * The FE pricing panel only HIDES WAC/margins in the DOM; a caller without
 * `pricing.view_cost_prices` could still read the real cost straight from the
 * products.show JSON. ProductController::show must redact those fields server-side.
 */
final class ProductShowCostRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cost Redaction Tenant',
            'slug' => 'cost-redaction-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cost Redaction Company',
            'legal_name' => 'Cost Redaction Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Costed Product',
            'sku' => 'COST-REDACT-001',
            'cost_price' => '12.500',
            'last_purchase_cost' => '11.000',
            'purchase_price' => '10.000',
            'sale_price' => '20.000',
        ]);
    }

    public function test_cost_price_holder_sees_wac_and_margins(): void
    {
        $user = $this->makeUser('cost-holder@example.com');
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.cost_price', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.last_purchase_cost', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.purchase_price', fn (?string $value): bool => $value !== null);
    }

    public function test_non_holder_gets_redacted_cost_and_margins(): void
    {
        $user = $this->makeUser('products-viewer@example.com');
        // Grants route access (can:products.view) WITHOUT pricing.view_cost_prices.
        $user->givePermissionTo('products.view');

        $this->assertFalse($user->can('pricing.view_cost_prices'), 'guard precondition');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/products/{$this->product->id}")
            ->assertOk()
            ->assertJsonPath('data.cost_price', null)
            ->assertJsonPath('data.last_purchase_cost', null)
            ->assertJsonPath('data.purchase_price', null)
            ->assertJsonPath('data.target_margin_override', null)
            ->assertJsonPath('data.minimum_margin_override', null)
            ->assertJsonPath('data.effective_margins', null)
            // Non-cost fields must still be present.
            ->assertJsonPath('data.sale_price', fn (?string $value): bool => $value !== null);
    }

    private function makeUser(string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Redaction User',
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        return $user;
    }
}
