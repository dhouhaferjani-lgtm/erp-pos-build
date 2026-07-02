<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

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
use App\Modules\Inventory\Domain\StockMovement;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PurchaseBonusGoodsReceiptTest extends TestCase
{
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
            'name' => 'Purchase Bonus GR Tenant',
            'slug' => 'purchase-bonus-gr-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchase Bonus GR Company',
            'legal_name' => 'Purchase Bonus GR Company SARL',
            'tax_id' => 'PB-GR-TAX',
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
            'name' => 'Purchase Bonus Receiver',
            'email' => 'purchase-bonus-receiver@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
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
            'code' => 'PB-GR-WH',
            'name' => 'Purchase Bonus Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Purchase Bonus Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function free_only_receipt_updates_only_free_counter_and_does_not_clobber_last_purchase_cost(): void
    {
        $product = $this->createProduct('PB-FREE-ONLY', 'Free-only product', '5.000000');
        $po = $this->createConfirmedPurchaseOrder($product, paidQty: '20.0000', freeQty: '1.0000', unitCost: '5.000000');
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '0.0000'],
                'free_quantities' => [$line->id => '1.0000'],
            ]);

        $response->assertOk();

        $line->refresh();
        $product->refresh();

        $this->assertSame('0.0000', (string) $line->quantity_received);
        $this->assertSame('1.0000', (string) $line->free_quantity_received);
        $this->assertSame('5.000000', (string) $product->last_purchase_cost);

        $movement = StockMovement::query()->where('product_id', $product->id)->sole();
        $this->assertSame('1.0000', (string) $movement->quantity);
        $this->assertSame('0.000000', (string) $movement->unit_cost);
    }

    #[Test]
    public function paid_and_free_receipt_records_free_zero_cost_movement_first_then_paid_movement(): void
    {
        $product = $this->createProduct('PB-20-1', '20 plus 1 product');
        $po = $this->createConfirmedPurchaseOrder($product, paidQty: '20.0000', freeQty: '1.0000', unitCost: '5.000000');
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '20.0000'],
                'free_quantities' => [$line->id => '1.0000'],
            ]);

        $response->assertOk();

        $line->refresh();
        $product->refresh();

        $this->assertSame('20.0000', (string) $line->quantity_received);
        $this->assertSame('1.0000', (string) $line->free_quantity_received);
        $this->assertSame('4.761904', (string) $product->cost_price);
        $this->assertSame('5.000000', (string) $product->last_purchase_cost);

        $movements = StockMovement::query()
            ->where('product_id', $product->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame('1.0000', (string) $movements[0]->quantity);
        $this->assertSame('0.000000', (string) $movements[0]->unit_cost);
        $this->assertSame('20.0000', (string) $movements[1]->quantity);
        $this->assertSame('5.000000', (string) $movements[1]->unit_cost);
    }

    #[Test]
    public function free_over_receive_is_rejected_independently_from_paid_quantity(): void
    {
        $product = $this->createProduct('PB-OVER', 'Over-free product');
        $po = $this->createConfirmedPurchaseOrder($product, paidQty: '20.0000', freeQty: '1.0000', unitCost: '5.000000');
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '0.0000'],
                'free_quantities' => [$line->id => '2.0000'],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'GOODS_RECEIPT_FAILED');
    }

    private function createProduct(string $sku, string $name, string $lastPurchaseCost = '0.000000'): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
            'last_purchase_cost' => $lastPurchaseCost,
        ]);
    }

    private function createConfirmedPurchaseOrder(Product $product, string $paidQty, string $freeQty, string $unitCost): Document
    {
        $lineTotal = bcmul($paidQty, $unitCost, 3);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-PB-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $lineTotal,
            'tax_amount' => '0.000',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $paidQty,
            'free_quantity' => $freeQty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => $lineTotal,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => $unitCost,
            'price_entry_mode' => 'unit',
        ]);

        return $po->fresh(['lines']);
    }
}
