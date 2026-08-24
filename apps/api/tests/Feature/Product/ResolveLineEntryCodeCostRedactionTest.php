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
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost/margin confidentiality on the LINE-ENTRY SCAN path (W2-6 gate r1 finding 2).
 *
 * `ProductController::index/show` redact cost fields for callers without
 * `pricing.view_cost_prices`, but `line-entry/resolve-code` returned the full
 * `ProductData` verbatim. Both endpoints sit behind the same `can:products.view`
 * route middleware and both feed the same document line editor, so a barcode scan
 * leaked `purchase_price` / `cost_price` / `last_purchase_cost` that the typed
 * product search redacts.
 *
 * W2-6 raised the practical impact: the scanned `purchase_price` now pre-fills the
 * purchase-order price cell AND is announced by the provenance hint, so the leaked
 * cost is rendered on screen rather than merely present in a JSON payload.
 *
 * The variant branch leaks `cost_override` the same way and is covered here too.
 */
final class ResolveLineEntryCodeCostRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Scan Redaction Tenant',
            'slug' => 'scan-redaction-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scan Redaction Company',
            'legal_name' => 'Scan Redaction Company LLC',
            'tax_id' => 'SCAN123',
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
            'name' => 'Scanned Costed Product',
            'sku' => 'SCAN-COST-SKU',
            'barcode' => 'SCAN-COST-BC',
            'is_active' => true,
            'cost_price' => '12.500',
            'last_purchase_cost' => '11.000',
            'purchase_price' => '10.000',
            'sale_price' => '20.000',
        ]);
    }

    public function test_cost_price_holder_still_sees_costs_when_scanning_a_barcode(): void
    {
        $user = $this->makeUser('scan-cost-holder@example.com');
        $user->assignRole('admin');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/line-entry/resolve-code?code=SCAN-COST-BC')
            ->assertOk()
            ->assertJsonPath('data.kind', 'product')
            ->assertJsonPath('data.product.purchase_price', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.product.cost_price', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.product.last_purchase_cost', fn (?string $value): bool => $value !== null);
    }

    public function test_non_holder_gets_redacted_costs_when_scanning_a_barcode(): void
    {
        $user = $this->makeUser('scan-products-viewer@example.com');
        // Grants route access (can:products.view) WITHOUT pricing.view_cost_prices.
        $user->givePermissionTo('products.view');

        $this->assertFalse($user->can('pricing.view_cost_prices'), 'guard precondition');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/line-entry/resolve-code?code=SCAN-COST-BC')
            ->assertOk()
            ->assertJsonPath('data.kind', 'product')
            ->assertJsonPath('data.product.purchase_price', null)
            ->assertJsonPath('data.product.cost_price', null)
            ->assertJsonPath('data.product.last_purchase_cost', null)
            // Non-cost fields must survive — the line editor still needs them.
            ->assertJsonPath('data.product.sale_price', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.product.name', 'Scanned Costed Product');
    }

    public function test_non_holder_gets_redacted_costs_when_scanning_a_sku(): void
    {
        $user = $this->makeUser('scan-sku-viewer@example.com');
        $user->givePermissionTo('products.view');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/line-entry/resolve-code?code=SCAN-COST-SKU')
            ->assertOk()
            ->assertJsonPath('data.matched_code_type', 'product_sku')
            ->assertJsonPath('data.product.purchase_price', null)
            ->assertJsonPath('data.product.cost_price', null);
    }

    public function test_non_holder_gets_redacted_costs_and_variant_cost_override_when_scanning_a_variant(): void
    {
        $variantProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Variant Costed Product',
            'sku' => 'SCAN-VAR-PARENT',
            'is_active' => true,
            'cost_price' => '9.000',
            'last_purchase_cost' => '8.500',
            'purchase_price' => '8.000',
            'sale_price' => '18.000',
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $variantProduct->id,
            'company_id' => $this->company->id,
            'sku' => 'SCAN-VAR-SKU',
            'barcode' => 'SCAN-VAR-BC',
            'cost_override' => '7.500',
            'price_override' => '17.000',
        ]);

        $user = $this->makeUser('scan-variant-viewer@example.com');
        $user->givePermissionTo('products.view');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/line-entry/resolve-code?code=SCAN-VAR-BC')
            ->assertOk()
            ->assertJsonPath('data.kind', 'variant')
            ->assertJsonPath('data.product.purchase_price', null)
            ->assertJsonPath('data.product.cost_price', null)
            ->assertJsonPath('data.variant.cost_override', null)
            // The sale-side override is not cost data and must survive.
            ->assertJsonPath('data.variant.price_override', fn (?string $value): bool => $value !== null);
    }

    private function makeUser(string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scan Redaction User',
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
