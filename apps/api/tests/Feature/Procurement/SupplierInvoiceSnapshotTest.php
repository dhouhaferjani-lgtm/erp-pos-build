<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateSupplierInvoiceService;
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplierInvoiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'SI Snapshot Tenant',
            'slug' => 'si-snapshot-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SI Snapshot Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Snapshot Supplier',
            'type' => PartnerType::Supplier,
        ]);

        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '10.000',
        ]);
    }

    public function test_document_lines_have_match_snapshot_columns_and_casts(): void
    {
        $this->assertTrue(Schema::hasColumn('document_lines', 'price_match_basis'));
        $this->assertTrue(Schema::hasColumn('document_lines', 'matched_receipt_line_id'));

        $line = $this->createPoLine();
        $receiptLineId = Str::uuid()->toString();

        $line->price_match_basis = '5.280000';
        $line->matched_receipt_line_id = $receiptLineId;
        $line->save();

        $fresh = DocumentLine::query()->findOrFail($line->id);
        $this->assertSame('5.280000', $fresh->price_match_basis);
        $this->assertSame($receiptLineId, $fresh->matched_receipt_line_id);
    }

    public function test_creation_stamps_weighted_basis_and_first_receipt_line_snapshot(): void
    {
        $poLine = $this->createPoLine(['quantity' => '100.0000', 'quantity_received' => '100.0000']);
        [$firstReceiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000'],
            ['qty' => '40.0000', 'basis' => '5.400000'],
        ]);

        $invoice = app(CreateSupplierInvoiceService::class)->create(
            $this->supplierInvoicePayload($poLine, '100.0000', '5.280'),
            $this->tenant->id,
            $this->company->id,
        );

        /** @var DocumentLine $invoiceLine */
        $invoiceLine = $invoice->fresh('lines')->lines->sole();
        $this->assertSame('5.280000', $invoiceLine->price_match_basis);
        $this->assertSame($firstReceiptLine->id, $invoiceLine->matched_receipt_line_id);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $invoice->fresh()->match_status);
    }

    public function test_creation_skips_price_snapshot_for_bonus_lines(): void
    {
        $poLine = $this->createPoLine([
            'quantity' => '12.0000',
            'quantity_received' => '10.0000',
            'free_quantity' => '2.0000',
            'free_quantity_received' => '2.0000',
            'free_quantity_invoiced' => '0.0000',
        ]);
        $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'free_qty' => '2.0000', 'basis' => '5.000000'],
        ]);

        $payload = $this->supplierInvoicePayload($poLine, '10.0000', '5.000');
        $payload['lines'][] = [
            'source_line_id' => $poLine->id,
            'quantity' => '2.0000',
            'unit_price' => '0.000',
            'vat_rate' => '19.00',
            'is_bonus_line' => true,
        ];

        $invoice = app(CreateSupplierInvoiceService::class)->create(
            $payload,
            $this->tenant->id,
            $this->company->id,
        );

        $lines = $invoice->fresh('lines')->lines->sortBy('line_number')->values();
        $this->assertSame('5.000000', $lines[0]->price_match_basis);
        $this->assertFalse((bool) $lines[0]->is_bonus_line);
        $this->assertTrue((bool) $lines[1]->is_bonus_line);
        $this->assertNull($lines[1]->price_match_basis);
        $this->assertNull($lines[1]->matched_receipt_line_id);
    }

    public function test_snapshot_prevents_reclassification_when_later_receipt_changes_live_basis(): void
    {
        $poLine = $this->createPoLine(['quantity' => '100.0000', 'quantity_received' => '100.0000']);
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '100.0000', 'basis' => '5.200000'],
        ]);

        $invoice = app(CreateSupplierInvoiceService::class)->create(
            $this->supplierInvoicePayload($poLine, '100.0000', '5.200'),
            $this->tenant->id,
            $this->company->id,
        );

        $receiptLine->forceFill(['accrual_unit_cost' => '6.000000'])->save();

        $this->assertSame(
            SupplierInvoiceMatchStatus::Matched,
            app(SupplierInvoiceMatcher::class)->match($invoice->fresh('lines')),
        );

        Artisan::call('procurement:rematch-drafts', ['--company' => $this->company->id]);

        /** @var DocumentLine $freshInvoiceLine */
        $freshInvoiceLine = $invoice->fresh('lines')->lines->sole();
        $this->assertSame('6.000000', $freshInvoiceLine->price_match_basis);
        $this->assertSame(
            SupplierInvoiceMatchStatus::PriceVariance,
            Document::findOrFail($invoice->id)->match_status,
        );
    }

    /**
     * @param  array<string, string>  $attrs
     */
    private function createPoLine(array $attrs = []): DocumentLine
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SNAP-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        return DocumentLine::create(array_merge([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Snapshot product',
            'quantity' => '100.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
        ], $attrs));
    }

    /**
     * @param  list<array{qty: numeric-string, basis: numeric-string, free_qty?: numeric-string}>  $lines
     * @return list<GoodsReceiptLine>
     */
    private function createReceiptLines(DocumentLine $poLine, array $lines): array
    {
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $poLine->document_id,
            'receipt_number' => 'GRN-SNAP-'.Str::upper(Str::random(6)),
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => now(),
        ]);

        $created = [];
        foreach ($lines as $line) {
            $created[] = GoodsReceiptLine::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'goods_receipt_id' => $receipt->id,
                'po_line_id' => $poLine->id,
                'product_id' => Str::uuid()->toString(),
                'received_qty' => $line['qty'],
                'free_qty' => $line['free_qty'] ?? '0.0000',
                'received_unit_price' => null,
                'landed_unit_cost' => $line['basis'],
                'accrual_unit_cost' => $line['basis'],
                'effective_unit_cost' => $line['basis'],
                'quantity_invoiced' => '0.0000',
                'free_quantity_invoiced' => '0.0000',
            ]);
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierInvoicePayload(DocumentLine $poLine, string $qty, string $unitPrice): array
    {
        return [
            'partner_id' => $this->supplier->id,
            'source_document_id' => $poLine->document_id,
            'currency' => 'TND',
            'issue_date' => now()->toDateString(),
            'lines' => [
                [
                    'source_line_id' => $poLine->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'vat_rate' => '19.00',
                ],
            ],
        ];
    }
}
