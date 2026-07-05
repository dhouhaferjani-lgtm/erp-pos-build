<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SupplierInvoiceCreationReadApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'SI Creation Reads Tenant',
            'slug' => 'si-creation-reads-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SI Creation Reads Company',
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
            'name' => 'Read Admin',
            'email' => 'si-creation-reads@example.com',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Creation Reads Supplier',
            'type' => PartnerType::Supplier,
        ]);
    }

    public function test_purchase_order_receipt_lines_return_only_uninvoiced_prefill_rows(): void
    {
        [$po, $poLine] = $this->createReceivedPurchaseOrder('PO-READ-001', '10.0000', '5.100');
        $receipt = $this->createReceipt($po, 'GRN-READ-001');

        $uninvoiced = $this->createReceiptLine($receipt, $poLine, [
            'received_qty' => '10.0000',
            'free_qty' => '2.0000',
            'quantity_invoiced' => '4.0000',
            'free_quantity_invoiced' => '1.0000',
            'received_unit_price' => '5.200',
            'accrual_unit_cost' => '5.200000',
        ]);
        $this->createReceiptLine($receipt, $poLine, [
            'received_qty' => '3.0000',
            'free_qty' => '0.0000',
            'quantity_invoiced' => '3.0000',
            'free_quantity_invoiced' => '0.0000',
            'received_unit_price' => '5.300',
            'accrual_unit_cost' => '5.300000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders/{$po->id}/receipt-lines?uninvoiced=1");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $uninvoiced->id);
        $response->assertJsonPath('data.0.receipt_number', 'GRN-READ-001');
        $response->assertJsonPath('data.0.product_id', $uninvoiced->product_id);
        $response->assertJsonPath('data.0.variant_id', null);
        $response->assertJsonPath('data.0.received_qty', '10.0000');
        $response->assertJsonPath('data.0.free_qty', '2.0000');
        $response->assertJsonPath('data.0.quantity_invoiced', '4.0000');
        $response->assertJsonPath('data.0.free_quantity_invoiced', '1.0000');
        $response->assertJsonPath('data.0.accrual_unit_cost', '5.200000');
        $response->assertJsonPath('data.0.received_unit_price', '5.200');
        $response->assertJsonPath('data.0.po_line_id', $poLine->id);
    }

    public function test_purchase_order_receipt_lines_uninvoiced_filter_ignores_free_only_open_window(): void
    {
        [$po, $poLine] = $this->createReceivedPurchaseOrder('PO-FREE-ONLY-001', '10.0000', '5.100');
        $receipt = $this->createReceipt($po, 'GRN-FREE-ONLY-001');

        $this->createReceiptLine($receipt, $poLine, [
            'received_qty' => '10.0000',
            'free_qty' => '2.0000',
            'quantity_invoiced' => '10.0000',
            'free_quantity_invoiced' => '0.0000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders/{$po->id}/receipt-lines?uninvoiced=1");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_purchase_order_receipt_lines_exclude_draft_receipts(): void
    {
        [$po, $poLine] = $this->createReceivedPurchaseOrder('PO-DRAFT-PICKER-001', '10.0000', '5.100');

        $this->createReceiptLine($this->createReceipt($po, null, GoodsReceiptStatus::Draft), $poLine, [
            'received_qty' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'received_unit_price' => null,
            'landed_unit_cost' => null,
            'accrual_unit_cost' => null,
            'effective_unit_cost' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders/{$po->id}/receipt-lines?uninvoiced=1");

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_purchase_order_index_has_uninvoiced_filter_returns_received_pos_with_open_receipt_lines(): void
    {
        [$openPo, $openLine] = $this->createReceivedPurchaseOrder('PO-OPEN-001', '10.0000', '8.000');
        [$closedPo, $closedLine] = $this->createReceivedPurchaseOrder('PO-CLOSED-001', '10.0000', '8.000');

        $this->createReceiptLine($this->createReceipt($openPo, 'GRN-OPEN-001'), $openLine, [
            'received_qty' => '10.0000',
            'quantity_invoiced' => '8.0000',
        ]);
        $this->createReceiptLine($this->createReceipt($closedPo, 'GRN-CLOSED-001'), $closedLine, [
            'received_qty' => '10.0000',
            'quantity_invoiced' => '10.0000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders?partner_id={$this->supplier->id}&status=received&has_uninvoiced=1");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($openPo->id, $ids);
        $this->assertNotContains($closedPo->id, $ids);
    }

    public function test_purchase_order_index_has_uninvoiced_filter_ignores_pos_with_only_free_window_open(): void
    {
        [$freeOnlyPo, $freeOnlyLine] = $this->createReceivedPurchaseOrder('PO-FREE-INDEX-001', '10.0000', '8.000');

        $this->createReceiptLine($this->createReceipt($freeOnlyPo, 'GRN-FREE-INDEX-001'), $freeOnlyLine, [
            'received_qty' => '10.0000',
            'free_qty' => '2.0000',
            'quantity_invoiced' => '10.0000',
            'free_quantity_invoiced' => '0.0000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders?partner_id={$this->supplier->id}&status=received&has_uninvoiced=1");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($freeOnlyPo->id, $ids);
    }

    public function test_purchase_order_index_has_uninvoiced_filter_ignores_draft_receipts(): void
    {
        [$draftOnlyPo, $draftOnlyLine] = $this->createReceivedPurchaseOrder('PO-DRAFT-INDEX-001', '10.0000', '8.000');

        $this->createReceiptLine($this->createReceipt($draftOnlyPo, null, GoodsReceiptStatus::Draft), $draftOnlyLine, [
            'received_qty' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'received_unit_price' => null,
            'landed_unit_cost' => null,
            'accrual_unit_cost' => null,
            'effective_unit_cost' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/purchase-orders?partner_id={$this->supplier->id}&status=received&has_uninvoiced=1");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($draftOnlyPo->id, $ids);
    }

    public function test_duplicate_reference_check_warns_for_same_company_partner_and_reference(): void
    {
        [$po] = $this->createReceivedPurchaseOrder('PO-DUP-001', '1.0000', '10.000');
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $po->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-DUP-001',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
            'external_document_number' => 'FA-8842',
            'match_status' => SupplierInvoiceMatchStatus::Matched,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/supplier-invoices/duplicate-reference?partner_id={$this->supplier->id}&reference=FA-8842");

        $response->assertOk();
        $response->assertJsonPath('data.exists', true);
        $response->assertJsonPath('data.invoice_number', 'SI-DUP-001');
    }

    public function test_duplicate_reference_check_returns_empty_for_non_uuid_partner_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices/duplicate-reference?partner_id=abc&reference=FA-8842');

        $response->assertOk();
        $response->assertJsonPath('data.exists', false);
    }

    /**
     * @return array{Document, DocumentLine}
     */
    private function createReceivedPurchaseOrder(string $number, string $qty, string $unitPrice): array
    {
        $lineTotal = bcmul($qty, $unitPrice, 3);
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $number.'-SKU',
            'name' => $number.' product',
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
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Received,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $lineTotal,
            'tax_amount' => '0.000',
            'total' => $lineTotal,
        ]);

        $line = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Receipt prefill product',
            'product_id' => $product->id,
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => $qty,
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'tax_rate' => '19.00',
            'line_total' => $lineTotal,
            'allocated_costs' => '0.0000',
        ]);

        return [$po, $line];
    }

    private function createReceipt(Document $po, ?string $number, GoodsReceiptStatus $status = GoodsReceiptStatus::Posted): GoodsReceipt
    {
        return GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $po->id,
            'receipt_number' => $number,
            'status' => $status,
            'received_at' => now(),
            'received_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function createReceiptLine(GoodsReceipt $receipt, DocumentLine $poLine, array $overrides = []): GoodsReceiptLine
    {
        return GoodsReceiptLine::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => (string) $poLine->product_id,
            'variant_id' => null,
            'received_qty' => $overrides['received_qty'] ?? '10.0000',
            'free_qty' => $overrides['free_qty'] ?? '0.0000',
            'received_unit_price' => $overrides['received_unit_price'] ?? '8.000',
            'landed_unit_cost' => $overrides['landed_unit_cost'] ?? '8.000000',
            'accrual_unit_cost' => $overrides['accrual_unit_cost'] ?? '8.000000',
            'effective_unit_cost' => $overrides['effective_unit_cost'] ?? '8.000000',
            'movement_id' => null,
            'free_movement_id' => null,
            'quantity_invoiced' => $overrides['quantity_invoiced'] ?? '0.0000',
            'free_quantity_invoiced' => $overrides['free_quantity_invoiced'] ?? '0.0000',
        ]);
    }
}
