<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Ingress precision regression coverage for CatalogCartController.
 *
 * Phase 4.13 — validates that the decimal-ceiling regex blocks inputs whose
 * scale exceeds the destination column capacity (quantity decimal(15,4),
 * unit_price decimal(15,3)) while allowing well-formed values through.
 */
final class IngressPrecisionTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private CatalogCart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Precision Test Tenant',
            'slug' => 'precision-cart-'.Str::random(4),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Precision Cart Co',
            'legal_name' => 'Precision Cart Co LLC',
            'tax_id' => 'PRC123',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cart Precision User',
            'email' => 'cart-precision@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->user->givePermissionTo(['catalog_cart.view', 'catalog_cart.create']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cart = CatalogCart::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'name' => 'Test Cart',
            'status' => 'active',
        ]);
    }

    // -------------------------------------------------------------------------
    // addItem — quantity (decimal 15,4): allow up to 4 decimal places
    // -------------------------------------------------------------------------

    public function test_add_item_accepts_quantity_with_four_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Brake Pad Set',
                'quantity' => '3.1234',
            ]);

        // Not a validation error (may 201 or business-error but not 422/regex)
        $this->assertNotEquals(422, $response->status(), 'Valid 4-decimal quantity should not fail validation');
    }

    public function test_add_item_accepts_integer_quantity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Brake Pad Set',
                'quantity' => '10',
            ]);

        $this->assertNotEquals(422, $response->status(), 'Integer quantity should not fail validation');
    }

    public function test_add_item_rejects_quantity_with_five_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Brake Pad Set',
                'quantity' => '3.12345',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    public function test_add_item_rejects_quantity_with_seven_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Brake Pad Set',
                'quantity' => '1.0000001',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    // -------------------------------------------------------------------------
    // addItem — unit_price (decimal 15,3): allow up to 3 decimal places
    // -------------------------------------------------------------------------

    public function test_add_item_accepts_unit_price_with_three_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Filter',
                'quantity' => '2',
                'unit_price' => '19.500',
            ]);

        $this->assertNotEquals(422, $response->status(), 'Valid 3-decimal unit_price should not fail validation');
    }

    public function test_add_item_rejects_unit_price_with_four_decimal_places(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/catalog-carts/{$this->cart->id}/items", [
                'source' => 'manual',
                'article_name' => 'Filter',
                'quantity' => '1',
                'unit_price' => '19.5001',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['unit_price']);
    }

    // -------------------------------------------------------------------------
    // updateItem — quantity (decimal 15,4)
    // -------------------------------------------------------------------------

    public function test_update_item_accepts_quantity_with_four_decimal_places(): void
    {
        $item = CatalogCartItem::create([
            'cart_id' => $this->cart->id,
            'source' => 'manual',
            'article_name' => 'Oil Filter',
            'quantity' => '1.0000',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/catalog-carts/{$this->cart->id}/items/{$item->id}", [
                'quantity' => '2.5000',
            ]);

        $this->assertNotEquals(422, $response->status(), 'Valid 4-decimal quantity update should not fail validation');
    }

    public function test_update_item_rejects_quantity_with_five_decimal_places(): void
    {
        $item = CatalogCartItem::create([
            'cart_id' => $this->cart->id,
            'source' => 'manual',
            'article_name' => 'Oil Filter',
            'quantity' => '1.0000',
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/catalog-carts/{$this->cart->id}/items/{$item->id}", [
                'quantity' => '2.50001',
            ]);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['quantity']);
    }

    // -------------------------------------------------------------------------
    // Validator::make boundary smoke test (fast, many cases).
    //
    // NOTE: this MIRRORS (does NOT bind to) the inline validator in
    // CatalogCartController::addItem/updateItem
    // (app/Modules/Cart/Presentation/Controllers/CatalogCartController.php
    // :177 quantity, :180 unit_price). The production binding is provided by the
    // HTTP 422 tests above; this is only extra boundary-case coverage.
    // -------------------------------------------------------------------------

    public function test_quantity_regex_boundary_conditions_via_validator(): void
    {
        // Mirrors CatalogCartController.php:177/:180 — NOT bound to production.
        $rules = [
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];

        $validCases = [
            ['quantity' => '1', 'unit_price' => null],
            ['quantity' => '1.0', 'unit_price' => '10.5'],
            ['quantity' => '1.12', 'unit_price' => '10.50'],
            ['quantity' => '1.123', 'unit_price' => '10.500'],
            ['quantity' => '1.1234', 'unit_price' => null],
            ['quantity' => '999.9999', 'unit_price' => '0.001'],
        ];

        foreach ($validCases as $data) {
            $v = Validator::make($data, $rules);
            $this->assertFalse($v->fails(), 'Expected valid: '.json_encode($data).' Errors: '.json_encode($v->errors()->toArray()));
        }

        $invalidCases = [
            ['field' => 'quantity', 'data' => ['quantity' => '1.12345']],
            ['field' => 'quantity', 'data' => ['quantity' => '1.000001']],
            ['field' => 'unit_price', 'data' => ['quantity' => '1', 'unit_price' => '5.1234']],
            ['field' => 'unit_price', 'data' => ['quantity' => '1', 'unit_price' => '5.00000']],
        ];

        foreach ($invalidCases as $case) {
            $v = Validator::make($case['data'], $rules);
            $this->assertTrue($v->fails(), 'Expected invalid: '.json_encode($case['data']));
            $this->assertArrayHasKey($case['field'], $v->errors()->toArray());
        }
    }
}
