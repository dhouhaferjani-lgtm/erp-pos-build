<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class ReceiveGoodsRequestTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Receive Goods Request Tenant',
            'slug' => 'receive-goods-request-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receive Goods Request Company',
            'legal_name' => 'Receive Goods Request Company SARL',
            'tax_id' => 'RGR-TAX',
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
            'name' => 'Receive Goods Request User',
            'email' => 'receive-goods-request@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.receive',
            'purchase-orders.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'RGR-WH',
            'name' => 'Receive Goods Request Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receive Goods Request Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function receive_rejects_quantities_with_more_than_four_decimals(): void
    {
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '1.00001'],
            ]);

        $this->assertJsonValidationErrors($response, ["quantities.{$line->id}"]);
    }

    #[Test]
    public function receive_prohibits_received_unit_prices_without_price_edit_permission(): void
    {
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '1.0000'],
                'received_unit_prices' => [$line->id => '5.200'],
            ]);

        $this->assertJsonValidationErrors($response, ['received_unit_prices']);
    }

    #[Test]
    public function receive_accepts_received_unit_prices_for_price_editors(): void
    {
        $this->user->givePermissionTo('goods-receipt.edit-price');
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '1.0000'],
                'received_unit_prices' => [$line->id => '5.200'],
                'price_override_reason' => 'Delivery note price changed.',
            ]);

        $response->assertOk();
    }

    #[Test]
    public function receive_rejects_received_unit_prices_with_more_than_three_decimals(): void
    {
        $this->user->givePermissionTo('goods-receipt.edit-price');
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '1.0000'],
                'received_unit_prices' => [$line->id => '5.2001'],
            ]);

        $this->assertJsonValidationErrors($response, ["received_unit_prices.{$line->id}"]);
    }

    #[Test]
    public function receive_rejects_negative_received_unit_prices(): void
    {
        $this->user->givePermissionTo('goods-receipt.edit-price');
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '1.0000'],
                'received_unit_prices' => [$line->id => '-5.200'],
            ]);

        $this->assertJsonValidationErrors($response, ["received_unit_prices.{$line->id}"]);
    }

    #[Test]
    public function receive_rejects_received_unit_prices_without_quantities(): void
    {
        $this->user->givePermissionTo('goods-receipt.edit-price');
        $po = $this->purchaseOrder();
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'received_unit_prices' => [$line->id => '5.200'],
            ]);

        $this->assertJsonValidationErrors($response, ['quantities']);
    }

    #[Test]
    public function price_edit_permission_is_seeded_to_admin_and_manager_only(): void
    {
        $this->assertTrue(Role::findByName('admin', 'sanctum')->hasPermissionTo('goods-receipt.edit-price', 'sanctum'));
        $this->assertTrue(Role::findByName('manager', 'sanctum')->hasPermissionTo('goods-receipt.edit-price', 'sanctum'));
        $this->assertFalse(Role::findByName('cashier', 'sanctum')->hasPermissionTo('goods-receipt.edit-price', 'sanctum'));
        $this->assertFalse(Role::findByName('operator', 'sanctum')->hasPermissionTo('goods-receipt.edit-price', 'sanctum'));
    }

    private function purchaseOrder(): Document
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RGR-PROD',
            'name' => 'Receive Goods Request Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-RGR-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '5.000',
            'tax_amount' => '0.000',
            'total' => '5.000',
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '2.0000',
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '10.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '5.000000',
            'price_entry_mode' => 'unit',
        ]);

        return $po->fresh(['lines']);
    }
}
