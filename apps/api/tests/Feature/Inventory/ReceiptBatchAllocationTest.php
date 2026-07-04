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
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
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

final class ReceiptBatchAllocationTest extends TestCase
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
            'name' => 'Receipt Batch Allocation Tenant',
            'slug' => 'receipt-batch-allocation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt Batch Allocation Company',
            'legal_name' => 'Receipt Batch Allocation Company SARL',
            'tax_id' => 'RBA-TAX',
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
            'name' => 'Receipt Batch Allocation Receiver',
            'email' => 'receipt-batch-allocation@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'RBA-WH',
            'name' => 'Receipt Batch Allocation Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receipt Batch Allocation Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function partial_receipt_freight_pool_is_reallocated_by_received_value(): void
    {
        $po = $this->purchaseOrder([
            ['sku' => 'RBA-A', 'quantity' => '100.0000', 'unit_price' => '5.000', 'allocated_costs' => '30.000000'],
            ['sku' => 'RBA-B', 'quantity' => '100.0000', 'unit_price' => '10.000', 'allocated_costs' => '30.000000'],
        ]);

        app(GoodsReceiptService::class)->receiveGoods($po, [
            $po->lines[0]->id => '50.0000',
            $po->lines[1]->id => '50.0000',
        ]);

        $lines = GoodsReceiptLine::query()->orderBy('po_line_id')->get();

        $this->assertSame('5.200000', (string) $lines[0]->landed_unit_cost);
        $this->assertSame('10.400000', (string) $lines[1]->landed_unit_cost);
        $this->assertSame('30.000000', bcadd(
            bcmul('50.0000', bcsub((string) $lines[0]->landed_unit_cost, '5.000000', 6), 6),
            bcmul('50.0000', bcsub((string) $lines[1]->landed_unit_cost, '10.000000', 6), 6),
            6,
        ));
    }

    #[Test]
    public function received_price_override_changes_batch_freight_shares(): void
    {
        $po = $this->purchaseOrder([
            ['sku' => 'RBA-OV-A', 'quantity' => '100.0000', 'unit_price' => '5.000', 'allocated_costs' => '30.000000'],
            ['sku' => 'RBA-OV-B', 'quantity' => '100.0000', 'unit_price' => '10.000', 'allocated_costs' => '30.000000'],
        ]);

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [
                $po->lines[0]->id => '50.0000',
                $po->lines[1]->id => '50.0000',
            ],
            [],
            [],
            [$po->lines[0]->id => '20.000'],
            'Override shifts received value',
            $this->user->id,
        );

        $lines = GoodsReceiptLine::query()->orderBy('po_line_id')->get();

        $this->assertSame('20.400000', (string) $lines[0]->landed_unit_cost);
        $this->assertSame('10.200000', (string) $lines[1]->landed_unit_cost);
    }

    #[Test]
    public function zero_price_lines_get_zero_share_but_pool_is_absorbed_by_positive_value_lines(): void
    {
        $po = $this->purchaseOrder([
            ['sku' => 'RBA-ZERO', 'quantity' => '10.0000', 'unit_price' => '0.000', 'allocated_costs' => '9.000000'],
            ['sku' => 'RBA-POS', 'quantity' => '10.0000', 'unit_price' => '5.000', 'allocated_costs' => '6.000000'],
        ]);

        app(GoodsReceiptService::class)->receiveGoods($po, [
            $po->lines[0]->id => '10.0000',
            $po->lines[1]->id => '10.0000',
        ]);

        $lines = GoodsReceiptLine::query()->orderBy('po_line_id')->get();

        $this->assertSame('0.000000', (string) $lines[0]->landed_unit_cost);
        $this->assertSame('6.500000', (string) $lines[1]->landed_unit_cost);
    }

    /**
     * @param  list<array{sku: string, quantity: string, unit_price: string, allocated_costs: string}>  $lines
     */
    private function purchaseOrder(array $lines): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-RBA-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        $lineNumber = 1;
        foreach ($lines as $line) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => $line['sku'],
                'name' => $line['sku'].' Product',
                'type' => ProductType::Part,
                'is_active' => true,
                'is_physical' => true,
                'requires_batch_tracking' => false,
                'cost_price' => '0.000000',
                'last_purchase_cost' => '0.000000',
            ]);

            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $product->id,
                'product_code' => $product->sku,
                'line_number' => $lineNumber++,
                'description' => $product->name,
                'quantity' => $line['quantity'],
                'free_quantity' => '0.0000',
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'free_quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => $line['unit_price'],
                'line_total' => bcmul($line['quantity'], $line['unit_price'], 3),
                'allocated_costs' => $line['allocated_costs'],
                'landed_unit_cost' => $line['unit_price'],
                'price_entry_mode' => 'unit',
            ]);
        }

        return $po->fresh(['lines']);
    }
}
