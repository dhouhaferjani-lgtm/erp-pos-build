<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Pricing\Domain\Enums\FloorBasis;
use App\Modules\Pricing\Domain\Enums\PriceBasis;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\DTOs\DiscountPolicyContext;
use Database\Seeders\CountryPricingRegulationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DiscountPolicyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Pricing Endpoint Tenant',
            'slug' => 'pricing-endpoint-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pricing Endpoint Co',
            'legal_name' => 'Pricing Endpoint Co LLC',
            'tax_id' => 'TAX-PRICING-ENDPOINT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'default_minimum_margin' => '10.00',
        ]);
        $this->product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'cost_price' => '100.000000',
            'sale_price' => '150.00',
            'minimum_margin_override' => '10.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_discount_policy_endpoint_requires_cost_permission(): void
    {
        $cashier = $this->user('cashier-no-cost@example.com');
        $this->attachToCompany($cashier);

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pricing/discount-policy', $this->payload('100.00'))
            ->assertForbidden();
    }

    public function test_discount_policy_endpoint_returns_guarded_verdict(): void
    {
        $manager = $this->user('manager-cost@example.com');
        $manager->assignRole('manager');
        $this->attachToCompany($manager);

        $response = $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pricing/discount-policy', $this->payload('109.99'));

        $response
            ->assertOk()
            ->assertJsonPath('data.floorPriceNet', '110.00')
            ->assertJsonPath('data.floorBasis', FloorBasis::MinimumMargin->value)
            ->assertJsonPath('data.requiresPermission', 'pricing.sell_below_minimum_margin')
            ->assertJsonPath('data.blocksSale', false)
            ->assertJsonPath('meta.currency', 'EUR');
    }

    public function test_discount_policy_endpoint_denies_sibling_company_cost_access(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Pricing Co',
            'legal_name' => 'Other Pricing Co LLC',
            'tax_id' => 'TAX-OTHER-PRICING',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'default_minimum_margin' => '10.00',
        ]);
        $otherProduct = Product::factory()->for($this->tenant)->for($otherCompany)->create([
            'cost_price' => '500.000000',
            'sale_price' => '750.00',
        ]);
        $manager = $this->user('manager-sibling-denied@example.com');
        $manager->assignRole('manager');
        $this->attachToCompany($manager);

        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Company-Id', $otherCompany->id)
            ->postJson('/api/v1/pricing/discount-policy', [
                ...$this->payload('600.00'),
                'product_id' => $otherProduct->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');
    }

    public function test_discount_policy_endpoint_returns_not_found_for_product_outside_current_company(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hidden Product Co',
            'legal_name' => 'Hidden Product Co LLC',
            'tax_id' => 'TAX-HIDDEN-PRODUCT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $otherProduct = Product::factory()->for($this->tenant)->for($otherCompany)->create();
        $manager = $this->user('manager-product-hidden@example.com');
        $manager->assignRole('manager');
        $this->attachToCompany($manager);

        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pricing/discount-policy', [
                ...$this->payload('100.00'),
                'product_id' => $otherProduct->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');
    }

    public function test_discount_policy_floor_matches_existing_margin_service_threshold(): void
    {
        $policy = app(DiscountPolicyInterface::class);
        $marginService = app(MarginService::class);

        $atFloor = $policy->resolve($this->context('110.00'));
        $belowFloor = $policy->resolve($this->context('109.99'));
        $this->product->load(['company', 'category']);

        self::assertSame('110.00', $atFloor->floorPriceNet);
        self::assertNull($atFloor->requiresPermission);
        self::assertSame('pricing.sell_below_minimum_margin', $belowFloor->requiresPermission);
        self::assertSame('orange', $marginService->getMarginLevel($this->product, '109.99')['level']);
        self::assertNotSame('orange', $marginService->getMarginLevel($this->product, '110.00')['level']);
    }

    public function test_regulatory_below_cost_floor_surfaces_as_advisory_for_zero_wac_product(): void
    {
        // FR below-cost regulation (advisory) + a product with no WAC but a last purchase cost:
        // without the regulatory wiring this product would have NO floor at all (M0 review gap).
        $this->seed(CountryPricingRegulationSeeder::class);

        $wacZero = Product::factory()->for($this->tenant)->for($this->company)->create([
            'cost_price' => '0.000000',
            'last_purchase_cost' => '8.000000',
            'sale_price' => '20.00',
        ]);

        $manager = $this->user('manager-regulatory@example.com');
        $manager->assignRole('manager');
        $this->attachToCompany($manager);

        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pricing/discount-policy', [
                ...$this->payload('5.00'),
                'product_id' => $wacZero->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.floorPriceNet', '8.00')
            ->assertJsonPath('data.floorBasis', FloorBasis::LegalBelowCost->value)
            ->assertJsonPath('data.blocksSale', false)
            ->assertJsonPath('data.requiresPermission', null);
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(string $price): array
    {
        return [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'effective_unit_price' => $price,
            'price_basis' => PriceBasis::Ht->value,
            'currency' => 'EUR',
            'quantity' => '1.0000',
        ];
    }

    private function user(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    private function attachToCompany(User $user): void
    {
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
    }

    private function context(string $price): DiscountPolicyContext
    {
        return new DiscountPolicyContext(
            companyId: $this->company->id,
            productId: $this->product->id,
            variantId: null,
            effectiveUnitPrice: $price,
            currency: 'EUR',
            quantity: '1.0000',
            priceBasis: PriceBasis::Ht->value,
        );
    }
}
