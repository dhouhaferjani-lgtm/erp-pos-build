<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
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
use App\Modules\Inventory\Application\Services\GoodsReceiptPdfService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GoodsReceiptPdfEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GRN PDF Tenant',
            'slug' => 'grn-pdf-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GRN PDF Company',
            'legal_name' => 'GRN PDF Company SARL',
            'tax_id' => 'GRN-PDF-TAX',
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
            'name' => 'GRN PDF User',
            'email' => 'grn-pdf@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRN-PDF-WH',
            'name' => 'GRN PDF Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GRN PDF Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'GRN-PDF-001',
            'name' => 'GRN PDF Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    public function test_posted_goods_receipt_can_be_downloaded_as_pdf(): void
    {
        $receipt = $this->receipt(GoodsReceiptStatus::Posted);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/goods-receipts/{$receipt->id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename=GRN-GRN-2026-0001.pdf');
    }

    public function test_draft_goods_receipt_pdf_is_rejected(): void
    {
        $receipt = $this->receipt(GoodsReceiptStatus::Draft);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/goods-receipts/{$receipt->id}/pdf");

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 'GOODS_RECEIPT_PDF_NOT_POSTED');
    }

    public function test_goods_receipt_pdf_formats_six_decimal_cost_without_float_round_trip(): void
    {
        $this->company->update(['locale' => 'en_US']);
        $receipt = $this->receipt(GoodsReceiptStatus::Posted, '9999999999999.123456');

        $viewData = app(GoodsReceiptPdfService::class)->viewDataFor($receipt);
        $viewData['lines']->first()->setRawAttributes([
            ...$viewData['lines']->first()->getAttributes(),
            'effective_unit_cost' => '9999999999999.123456',
        ], true);
        $html = view('inventory.goods_receipt', $viewData)->render();

        $this->assertStringContainsString('9,999,999,999,999.123', $html);
    }

    public function test_goods_receipt_pdf_requires_inventory_view_permission(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GRN PDF Denied User',
            'email' => 'grn-pdf-denied@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);
        $receipt = $this->receipt(GoodsReceiptStatus::Posted);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/goods-receipts/{$receipt->id}/pdf");

        $response->assertForbidden();
    }

    private function receipt(GoodsReceiptStatus $status, string $effectiveUnitCost = '10.000000'): GoodsReceipt
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
            'document_number' => 'PO-GRN-PDF-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '20.000',
            'tax_amount' => '0.000',
            'total' => '20.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->sku,
            'line_number' => 1,
            'description' => $this->product->name,
            'quantity' => '2.0000',
            'free_quantity' => '0.0000',
            'quantity_received' => '2.0000',
            'free_quantity_received' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '20.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '10.000000',
        ]);

        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $po->id,
            'receipt_number' => $status === GoodsReceiptStatus::Posted ? 'GRN-2026-0001' : null,
            'status' => $status,
            'received_at' => now(),
            'received_by' => $this->user->id,
            'external_reference' => 'BL-88',
            'external_date' => '2026-07-05',
        ]);

        GoodsReceiptLine::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => $this->product->id,
            'received_qty' => '2.0000',
            'free_qty' => '0.0000',
            'landed_unit_cost' => $effectiveUnitCost,
            'accrual_unit_cost' => $effectiveUnitCost,
            'effective_unit_cost' => $effectiveUnitCost,
            'quantity_invoiced' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
        ]);

        return $receipt;
    }
}
