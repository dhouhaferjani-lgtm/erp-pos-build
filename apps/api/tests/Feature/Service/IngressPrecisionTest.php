<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Requests\UpdateUserRequest;
use App\Modules\Menu\Presentation\Requests\AddMenuCategoryItemRequest;
use App\Modules\Menu\Presentation\Requests\SyncMenuCategoryItemsRequest;
use App\Modules\Partner\Presentation\Requests\CreatePartnerRequest;
use App\Modules\Partner\Presentation\Requests\UpdatePartnerRequest;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use App\Modules\Scheduling\Presentation\Requests\UpdateScheduleConfigRequest;
use App\Modules\Service\Presentation\Requests\CreateServiceRequest;
use App\Modules\Service\Presentation\Requests\UpdateServiceRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Phase 4.12 — Service / Partner / Identity / Product / Scheduling / Menu / Marketplace
 * ingress precision ceiling tests.
 *
 * FormRequest-backed fields bind to the REAL production rules() (these requests
 * resolve CompanyContext via the container and read route params, so we bind a
 * seeded company and call rules() without route args — null route params are
 * fine for the Rule::unique ignore). The Marketplace fields are validated by
 * inline controller validators; those mirror the production callsite with an
 * explicit pointer (see comments) since the endpoints are costly to set up.
 *
 * If a production scale changes, the FormRequest-backed tests fail.
 */
final class IngressPrecisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a real CompanyContext (seeded tenant + company) in the container so
     * that FormRequests resolving app(CompanyContext::class) get a company id.
     */
    private function bindCompanyContext(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        app(CompanyContext::class)->setCompanyId($company->id);
    }

    // ── Service: CreateServiceRequest / UpdateServiceRequest ──────────────────

    public function test_create_service_rejects_3_decimal_base_price(): void
    {
        $rules = $this->serviceRules();
        $v = Validator::make([
            'code' => 'OIL-SVC',
            'name' => 'Oil Service',
            'pricing_type' => 'fixed',
            'base_price' => '49.999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    public function test_create_service_accepts_2_decimal_base_price(): void
    {
        $rules = $this->serviceRules();
        $v = Validator::make([
            'code' => 'OIL-SVC',
            'name' => 'Oil Service',
            'pricing_type' => 'fixed',
            'base_price' => '49.99',
        ], $rules);

        $errors = $v->errors()->get('base_price');
        $this->assertEmpty($errors, 'Expected 2-decimal base_price to pass');
    }

    public function test_create_service_rejects_3_decimal_hourly_rate(): void
    {
        $rules = $this->serviceRules();
        $v = Validator::make([
            'code' => 'SVC-01',
            'name' => 'Labor',
            'pricing_type' => 'hourly',
            'base_price' => '0',
            'hourly_rate' => '75.999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('hourly_rate', $v->errors()->toArray());
    }

    public function test_create_service_accepts_2_decimal_hourly_rate(): void
    {
        $rules = $this->serviceRules();
        $v = Validator::make([
            'code' => 'SVC-01',
            'name' => 'Labor',
            'pricing_type' => 'hourly',
            'base_price' => '0',
            'hourly_rate' => '75.50',
        ], $rules);

        $errors = $v->errors()->get('hourly_rate');
        $this->assertEmpty($errors);
    }

    public function test_create_service_rejects_3_decimal_tax_rate(): void
    {
        $rules = $this->serviceRules();
        $v = Validator::make([
            'code' => 'SVC-01',
            'name' => 'Labor',
            'pricing_type' => 'fixed',
            'base_price' => '50.00',
            'tax_rate' => '20.001',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_update_service_rejects_3_decimal_base_price(): void
    {
        $rules = $this->serviceUpdateRules();
        $v = Validator::make(['base_price' => '75.123'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('base_price', $v->errors()->toArray());
    }

    // ── Partner: CreatePartnerRequest / UpdatePartnerRequest ──────────────────

    public function test_create_partner_rejects_5_decimal_credit_limit(): void
    {
        $rules = $this->partnerRules();
        $v = Validator::make(['credit_limit' => '10000.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('credit_limit', $v->errors()->toArray());
    }

    public function test_create_partner_accepts_4_decimal_credit_limit(): void
    {
        $rules = $this->partnerRules();
        $v = Validator::make(['credit_limit' => '10000.1234'], $rules);

        $errors = $v->errors()->get('credit_limit');
        $this->assertEmpty($errors);
    }

    public function test_create_partner_rejects_3_decimal_discount_percentage(): void
    {
        $rules = $this->partnerRules();
        $v = Validator::make(['discount_percentage' => '10.123'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('discount_percentage', $v->errors()->toArray());
    }

    public function test_create_partner_accepts_2_decimal_discount_percentage(): void
    {
        $rules = $this->partnerRules();
        $v = Validator::make(['discount_percentage' => '10.50'], $rules);

        $errors = $v->errors()->get('discount_percentage');
        $this->assertEmpty($errors);
    }

    public function test_update_partner_rejects_5_decimal_credit_limit(): void
    {
        $rules = $this->partnerUpdateRules();
        $v = Validator::make(['credit_limit' => '5000.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('credit_limit', $v->errors()->toArray());
    }

    // ── Identity: UpdateUserRequest ───────────────────────────────────────────

    public function test_update_user_rejects_3_decimal_max_discount_percent(): void
    {
        $rules = $this->identityUpdateUserRules();
        $v = Validator::make(['max_discount_percent' => '15.123'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('max_discount_percent', $v->errors()->toArray());
    }

    public function test_update_user_accepts_2_decimal_max_discount_percent(): void
    {
        $rules = $this->identityUpdateUserRules();
        $v = Validator::make(['max_discount_percent' => '15.50'], $rules);

        $errors = $v->errors()->get('max_discount_percent');
        $this->assertEmpty($errors);
    }

    public function test_update_user_accepts_integer_max_discount_percent(): void
    {
        $rules = $this->identityUpdateUserRules();
        $v = Validator::make(['max_discount_percent' => '20'], $rules);

        $errors = $v->errors()->get('max_discount_percent');
        $this->assertEmpty($errors);
    }

    // ── Product: CreateProductRequest / UpdateProductRequest ──────────────────

    public function test_create_product_rejects_3_decimal_sale_price(): void
    {
        $rules = $this->productRules();
        $v = Validator::make([
            'name' => 'Filter',
            'sku' => 'SKU-001',
            'sale_price' => '29.999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('sale_price', $v->errors()->toArray());
    }

    public function test_create_product_accepts_2_decimal_sale_price(): void
    {
        $rules = $this->productRules();
        $v = Validator::make([
            'name' => 'Filter',
            'sku' => 'SKU-001',
            'sale_price' => '29.99',
        ], $rules);

        $errors = $v->errors()->get('sale_price');
        $this->assertEmpty($errors);
    }

    public function test_create_product_rejects_3_decimal_purchase_price(): void
    {
        $rules = $this->productRules();
        $v = Validator::make([
            'name' => 'Filter',
            'sku' => 'SKU-001',
            'purchase_price' => '15.999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('purchase_price', $v->errors()->toArray());
    }

    public function test_create_product_rejects_3_decimal_tax_rate(): void
    {
        $rules = $this->productRules();
        $v = Validator::make([
            'name' => 'Filter',
            'sku' => 'SKU-001',
            'tax_rate' => '19.001',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('tax_rate', $v->errors()->toArray());
    }

    public function test_update_product_rejects_3_decimal_sale_price(): void
    {
        $rules = $this->productUpdateRules();
        $v = Validator::make(['sale_price' => '19.999'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('sale_price', $v->errors()->toArray());
    }

    // ── Scheduling: UpdateScheduleConfigRequest ───────────────────────────────

    public function test_schedule_config_rejects_3_decimal_walk_in_buffer(): void
    {
        $rules = $this->scheduleConfigRules();
        $v = Validator::make(['walk_in_buffer_hours_per_day' => '1.125'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('walk_in_buffer_hours_per_day', $v->errors()->toArray());
    }

    public function test_schedule_config_accepts_2_decimal_walk_in_buffer(): void
    {
        $rules = $this->scheduleConfigRules();
        $v = Validator::make(['walk_in_buffer_hours_per_day' => '1.50'], $rules);

        $errors = $v->errors()->get('walk_in_buffer_hours_per_day');
        $this->assertEmpty($errors);
    }

    public function test_schedule_config_accepts_integer_walk_in_buffer(): void
    {
        $rules = $this->scheduleConfigRules();
        $v = Validator::make(['walk_in_buffer_hours_per_day' => '2'], $rules);

        $errors = $v->errors()->get('walk_in_buffer_hours_per_day');
        $this->assertEmpty($errors);
    }

    // ── Menu: AddMenuCategoryItemRequest / SyncMenuCategoryItemsRequest ────────

    public function test_add_menu_item_rejects_5_decimal_override_price(): void
    {
        $rules = $this->menuAddItemRules();
        $v = Validator::make([
            'sellable_type' => 'product',
            'sellable_id' => '00000000-0000-0000-0000-000000000001',
            'override_price' => '9.99999',
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('override_price', $v->errors()->toArray());
    }

    public function test_add_menu_item_accepts_4_decimal_override_price(): void
    {
        $rules = $this->menuAddItemRules();
        $v = Validator::make([
            'sellable_type' => 'product',
            'sellable_id' => '00000000-0000-0000-0000-000000000001',
            'override_price' => '9.9999',
        ], $rules);

        $errors = $v->errors()->get('override_price');
        $this->assertEmpty($errors);
    }

    public function test_sync_menu_items_rejects_5_decimal_override_price(): void
    {
        $rules = $this->menuSyncItemsRules();
        $v = Validator::make([
            'items' => [
                [
                    'sellable_type' => 'product',
                    'sellable_id' => '00000000-0000-0000-0000-000000000001',
                    'override_price' => '9.99999',
                ],
            ],
        ], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('items.0.override_price', $v->errors()->toArray());
    }

    public function test_sync_menu_items_accepts_4_decimal_override_price(): void
    {
        $rules = $this->menuSyncItemsRules();
        $v = Validator::make([
            'items' => [
                [
                    'sellable_type' => 'product',
                    'sellable_id' => '00000000-0000-0000-0000-000000000001',
                    'override_price' => '9.9999',
                ],
            ],
        ], $rules);

        $errors = $v->errors()->get('items.0.override_price');
        $this->assertEmpty($errors);
    }

    // ── Marketplace: MarketplaceSellerController / MarketplaceOrderController ──

    public function test_marketplace_seller_rejects_3_decimal_commission_rate(): void
    {
        $rules = $this->marketplaceSellerRules();
        $v = Validator::make(['commission_rate' => '5.678'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('commission_rate', $v->errors()->toArray());
    }

    public function test_marketplace_seller_accepts_2_decimal_commission_rate(): void
    {
        $rules = $this->marketplaceSellerRules();
        $v = Validator::make(['commission_rate' => '5.50'], $rules);

        $errors = $v->errors()->get('commission_rate');
        $this->assertEmpty($errors);
    }

    public function test_marketplace_seller_accepts_integer_commission_rate(): void
    {
        $rules = $this->marketplaceSellerRules();
        $v = Validator::make(['commission_rate' => '5'], $rules);

        $errors = $v->errors()->get('commission_rate');
        $this->assertEmpty($errors);
    }

    public function test_marketplace_order_rejects_5_decimal_quantity(): void
    {
        $rules = $this->marketplaceOrderItemRules();
        $v = Validator::make(['quantity' => '2.12345'], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('quantity', $v->errors()->toArray());
    }

    public function test_marketplace_order_accepts_4_decimal_quantity(): void
    {
        $rules = $this->marketplaceOrderItemRules();
        $v = Validator::make(['quantity' => '2.1234'], $rules);

        $errors = $v->errors()->get('quantity');
        $this->assertEmpty($errors);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Production rules from CreateServiceRequest (CompanyContext bound).
     *
     * @return array<string, mixed>
     */
    private function serviceRules(): array
    {
        $this->bindCompanyContext();

        return (new CreateServiceRequest)->rules();
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceUpdateRules(): array
    {
        $this->bindCompanyContext();

        return (new UpdateServiceRequest)->rules();
    }

    /**
     * Production rules from CreatePartnerRequest.
     *
     * @return array<string, mixed>
     */
    private function partnerRules(): array
    {
        $this->bindCompanyContext();

        return (new CreatePartnerRequest)->rules();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerUpdateRules(): array
    {
        $this->bindCompanyContext();

        return (new UpdatePartnerRequest)->rules();
    }

    /**
     * Production rules from UpdateUserRequest.
     *
     * @return array<string, mixed>
     */
    private function identityUpdateUserRules(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        app(CompanyContext::class)->setCompanyId($company->id);

        // UpdateUserRequest::rules() reads $this->user()->tenant_id.
        $request = new UpdateUserRequest;
        $request->setUserResolver(fn () => $user);

        return $request->rules();
    }

    /**
     * Production rules from CreateProductRequest.
     *
     * @return array<string, mixed>
     */
    private function productRules(): array
    {
        $this->bindCompanyContext();

        return (new CreateProductRequest)->rules();
    }

    /**
     * @return array<string, mixed>
     */
    private function productUpdateRules(): array
    {
        $this->bindCompanyContext();

        return (new UpdateProductRequest)->rules();
    }

    /**
     * Production rules from UpdateScheduleConfigRequest.
     *
     * @return array<string, mixed>
     */
    private function scheduleConfigRules(): array
    {
        $this->bindCompanyContext();

        return (new UpdateScheduleConfigRequest)->rules();
    }

    /**
     * Production rules from AddMenuCategoryItemRequest.
     *
     * @return array<string, mixed>
     */
    private function menuAddItemRules(): array
    {
        $this->bindCompanyContext();

        return (new AddMenuCategoryItemRequest)->rules();
    }

    /**
     * Production rules from SyncMenuCategoryItemsRequest.
     *
     * @return array<string, mixed>
     */
    private function menuSyncItemsRules(): array
    {
        $this->bindCompanyContext();

        return (new SyncMenuCategoryItemsRequest)->rules();
    }

    /**
     * commission_rate rules — MIRRORS (does NOT bind to) the inline validator in
     * MarketplaceSellerController::store/update
     * (app/Modules/Marketplace/Presentation/Controllers/MarketplaceSellerController.php).
     * The marketplace endpoints require a fully-provisioned seller context, so a
     * rules-literal is retained here with a pointer to the production callsite.
     *
     * @return array<string, mixed>
     */
    private function marketplaceSellerRules(): array
    {
        return [
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * items.*.quantity rules — MIRRORS (does NOT bind to) the inline validator in
     * MarketplaceOrderController::store
     * (app/Modules/Marketplace/Presentation/Controllers/MarketplaceOrderController.php).
     *
     * @return array<string, mixed>
     */
    private function marketplaceOrderItemRules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
