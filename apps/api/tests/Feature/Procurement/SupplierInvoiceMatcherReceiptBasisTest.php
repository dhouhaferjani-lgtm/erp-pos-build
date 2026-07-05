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
use App\Modules\Procurement\Application\ReceiptLineConsumptionPlanner;
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplierInvoiceMatcherReceiptBasisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    private SupplierInvoiceMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Receipt Basis Tenant',
            'slug' => 'receipt-basis-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt Basis Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receipt Basis Supplier',
            'type' => PartnerType::Supplier,
        ]);

        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);

        $this->matcher = app(SupplierInvoiceMatcher::class);
    }

    public function test_planner_returns_fifo_slices_and_matchable_quantity_per_receipt_line(): void
    {
        $poLine = $this->createPoLine(['quantity_received' => '100.0000']);
        [$first, $second] = $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000', 'invoiced' => '10.0000'],
            ['qty' => '40.0000', 'basis' => '5.400000', 'invoiced' => '0.0000'],
        ]);

        $planner = app(ReceiptLineConsumptionPlanner::class);

        $this->assertSame('50.0000', $planner->matchableQty($first));
        $this->assertSame('40.0000', $planner->matchableQty($second));
        $this->assertSame([
            ['receipt_line_id' => $first->id, 'qty' => '50.0000', 'basis' => '5.200000'],
            ['receipt_line_id' => $second->id, 'qty' => '10.0000', 'basis' => '5.400000'],
        ], $planner->plan($poLine->id, '60.0000'));
    }

    public function test_paid_matchable_excludes_free_receipt_window_and_bonus_window_is_separate(): void
    {
        $poLine = $this->createPoLine([
            'quantity' => '12.0000',
            'quantity_received' => '10.0000',
            'free_quantity' => '2.0000',
            'free_quantity_received' => '2.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);
        [$receiptLine] = $this->createReceiptLines($poLine, [
            [
                'qty' => '10.0000',
                'free_qty' => '2.0000',
                'basis' => '5.000000',
                'invoiced' => '9.0000',
                'free_invoiced' => '0.0000',
            ],
        ]);

        $planner = app(ReceiptLineConsumptionPlanner::class);
        $this->assertSame('1.0000', $planner->matchableQty($receiptLine));

        $paidInvoice = $this->createInvoice($poLine, '2.0000', '5.000');
        $bonusInvoice = $this->createInvoice($poLine, '2.0000', '0.000', isBonusLine: true);

        $this->assertSame(SupplierInvoiceMatchStatus::QuantityVariance, $this->matcher->match($paidInvoice));
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($bonusInvoice));
    }

    public function test_matcher_compares_price_against_weighted_receipt_line_basis(): void
    {
        $poLine = $this->createPoLine([
            'quantity' => '100.0000',
            'quantity_received' => '100.0000',
            'unit_price' => '5.000',
        ]);
        $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000'],
            ['qty' => '40.0000', 'basis' => '5.400000'],
        ]);

        $matched = $this->createInvoice($poLine, '100.0000', '5.280');
        $variance = $this->createInvoice($poLine, '100.0000', '5.200');

        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($matched));
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, $this->matcher->match($variance));
    }

    public function test_two_way_matcher_compares_price_against_po_unit_price_not_receipt_basis(): void
    {
        ProcurementPolicy::where('company_id', $this->company->id)->update([
            'match_mode' => MatchMode::TwoWay->value,
            'variance_tolerance_percent' => '0.00',
            'variance_tolerance_max_amount' => '0.000',
        ]);

        $poLine = $this->createPoLine([
            'quantity' => '10.0000',
            'quantity_received' => '10.0000',
            'unit_price' => '5.000',
        ]);
        $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'basis' => '5.400000'],
        ]);

        $contractPriceInvoice = $this->createInvoice($poLine, '10.0000', '5.000');
        $receiptPriceInvoice = $this->createInvoice($poLine, '10.0000', '5.400');

        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($contractPriceInvoice));
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, $this->matcher->match($receiptPriceInvoice));
    }

    public function test_two_way_still_rejects_invoiced_quantity_beyond_received_quantity(): void
    {
        ProcurementPolicy::where('company_id', $this->company->id)->update([
            'match_mode' => MatchMode::TwoWay->value,
        ]);

        $poLine = $this->createPoLine([
            'quantity' => '10.0000',
            'quantity_received' => '6.0000',
            'unit_price' => '5.000',
        ]);
        $this->createReceiptLines($poLine, [
            ['qty' => '6.0000', 'basis' => '5.000000'],
        ]);

        $invoice = $this->createInvoice($poLine, '7.0000', '5.000');

        $this->assertSame(SupplierInvoiceMatchStatus::QuantityVariance, $this->matcher->match($invoice));
    }

    public function test_zero_receipt_line_po_uses_legacy_po_line_basis_fallback(): void
    {
        $poLine = $this->createPoLine([
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);

        $invoice = $this->createInvoice($poLine, '10.0000', '5.000');

        $this->assertSame('10.0000', $this->matcher->matchableQty($poLine));
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $this->matcher->match($invoice));
    }

    public function test_draft_receipt_lines_create_no_paid_or_bonus_match_window(): void
    {
        $poLine = $this->createPoLine([
            'quantity' => '10.0000',
            'quantity_received' => '0.0000',
            'free_quantity' => '2.0000',
            'free_quantity_received' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
        ]);
        [$draftLine] = $this->createReceiptLines($poLine, [
            [
                'qty' => '10.0000',
                'free_qty' => '2.0000',
                'basis' => null,
            ],
        ], GoodsReceiptStatus::Draft);

        $planner = app(ReceiptLineConsumptionPlanner::class);
        $this->assertSame('10.0000', $planner->matchableQty($draftLine));
        $this->assertSame([], $planner->plan($poLine->id, '1.0000'));
        $this->assertSame('0.0000', $this->matcher->matchableQty($poLine));

        $paidInvoice = $this->createInvoice($poLine, '1.0000', '5.000');
        $bonusInvoice = $this->createInvoice($poLine, '1.0000', '0.000', isBonusLine: true);

        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($paidInvoice));
        $this->assertSame(SupplierInvoiceMatchStatus::Exception, $this->matcher->match($bonusInvoice));
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
            'document_number' => 'PO-RB-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        return DocumentLine::create(array_merge([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Receipt basis product',
            'quantity' => '100.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
        ], $attrs));
    }

    /**
     * @param  list<array{qty: numeric-string, basis: numeric-string|null, invoiced?: numeric-string, free_qty?: numeric-string, free_invoiced?: numeric-string}>  $lines
     * @return list<GoodsReceiptLine>
     */
    private function createReceiptLines(DocumentLine $poLine, array $lines, GoodsReceiptStatus $status = GoodsReceiptStatus::Posted): array
    {
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $poLine->document_id,
            'receipt_number' => $status === GoodsReceiptStatus::Posted ? 'GRN-RB-'.Str::upper(Str::random(6)) : null,
            'status' => $status,
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
                'quantity_invoiced' => $line['invoiced'] ?? '0.0000',
                'free_quantity_invoiced' => $line['free_invoiced'] ?? '0.0000',
            ]);
        }

        return $created;
    }

    private function createInvoice(DocumentLine $poLine, string $qty, string $unitPrice, bool $isBonusLine = false): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $poLine->document_id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-RB-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Receipt basis product',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => bcmul($qty, $unitPrice, 3),
            'source_line_id' => $poLine->id,
            'is_bonus_line' => $isBonusLine,
        ]);

        return $invoice->fresh('lines');
    }
}
