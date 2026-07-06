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

final class RematchDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Rematch Tenant',
            'slug' => 'rematch-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rematch Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Rematch Supplier',
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
    }

    public function test_rematch_drafts_stamps_snapshots_and_reports_status_transition(): void
    {
        [$invoice, $invoiceLine, $firstReceiptLine] = $this->oldDraftInvoiceWithoutSnapshot();

        $this->artisan('procurement:rematch-drafts', ['--company' => $this->company->id])
            ->expectsOutputToContain('rematched=1')
            ->expectsOutputToContain('unmatched -> matched')
            ->assertExitCode(0);

        $freshInvoice = Document::findOrFail($invoice->id);
        $freshLine = DocumentLine::findOrFail($invoiceLine->id);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $freshInvoice->match_status);
        $this->assertSame('5.280000', $freshLine->price_match_basis);
        $this->assertSame($firstReceiptLine->id, $freshLine->matched_receipt_line_id);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        [$invoice, $invoiceLine] = $this->oldDraftInvoiceWithoutSnapshot();

        $this->artisan('procurement:rematch-drafts', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('dry_run=yes')
            ->assertExitCode(0);

        $this->assertSame(SupplierInvoiceMatchStatus::Unmatched, Document::findOrFail($invoice->id)->match_status);
        $this->assertNull(DocumentLine::findOrFail($invoiceLine->id)->price_match_basis);
    }

    public function test_rematch_is_idempotent_after_snapshots_are_current(): void
    {
        [$invoice] = $this->oldDraftInvoiceWithoutSnapshot();

        $this->artisan('procurement:rematch-drafts', ['--company' => $this->company->id])
            ->assertExitCode(0);
        $this->artisan('procurement:rematch-drafts', ['--company' => $this->company->id])
            ->expectsOutputToContain('rematched=0')
            ->assertExitCode(0);

        $this->assertSame(SupplierInvoiceMatchStatus::Matched, Document::findOrFail($invoice->id)->match_status);
    }

    /**
     * @return array{0: Document, 1: DocumentLine, 2: GoodsReceiptLine}
     */
    private function oldDraftInvoiceWithoutSnapshot(): array
    {
        $poLine = $this->createPoLine();
        [$firstReceiptLine] = $this->createReceiptLines($poLine);

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $poLine->document_id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-REMATCH-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '528.000',
            'line_tax_amount' => '100.320',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '100.320',
            'total' => '628.320',
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        $invoiceLine = DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Old draft invoice line',
            'quantity' => '100.0000',
            'unit_price' => '5.280',
            'line_total' => '528.000',
            'tax_amount' => '100.320',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '100.320',
            'non_recoverable_tax_amount' => '0.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $poLine->id,
        ]);

        return [$invoice->fresh('lines'), $invoiceLine, $firstReceiptLine];
    }

    private function createPoLine(): DocumentLine
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-REMATCH-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        return DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Rematch PO line',
            'quantity' => '100.0000',
            'quantity_received' => '100.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '5.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
        ]);
    }

    /**
     * @return list<GoodsReceiptLine>
     */
    private function createReceiptLines(DocumentLine $poLine): array
    {
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $poLine->document_id,
            'receipt_number' => 'GRN-REMATCH-'.Str::upper(Str::random(6)),
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => now(),
        ]);

        $lines = [];
        foreach ([['qty' => '60.0000', 'basis' => '5.200000'], ['qty' => '40.0000', 'basis' => '5.400000']] as $line) {
            $lines[] = GoodsReceiptLine::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'goods_receipt_id' => $receipt->id,
                'po_line_id' => $poLine->id,
                'product_id' => Str::uuid()->toString(),
                'received_qty' => $line['qty'],
                'free_qty' => '0.0000',
                'received_unit_price' => null,
                'landed_unit_cost' => $line['basis'],
                'accrual_unit_cost' => $line['basis'],
                'effective_unit_cost' => $line['basis'],
                'quantity_invoiced' => '0.0000',
            ]);
        }

        return $lines;
    }
}
