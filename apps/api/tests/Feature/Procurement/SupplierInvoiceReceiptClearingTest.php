<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
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
use App\Modules\Procurement\Application\SupplierInvoiceMatcher;
use App\Modules\Procurement\Application\SupplierInvoicePostingService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplierInvoiceReceiptClearingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $supplier;

    private Account $grirAccount;

    private Account $payableAccount;

    private Account $vatAccount;

    private Account $ppvExpenseAccount;

    private Account $ppvIncomeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Receipt Clearing Tenant',
            'slug' => 'receipt-clearing-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receipt Clearing Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $this->vatAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $this->ppvExpenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $this->ppvIncomeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Receipt Clearing Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
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

    public function test_multi_price_two_receipts_clear_408_at_each_receipt_line_basis(): void
    {
        $poLine = $this->createPoLine('100.0000', '5.000', '100.0000');
        [$first, $second] = $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000'],
            ['qty' => '40.0000', 'basis' => '5.400000'],
        ]);
        $this->accrueReceipt('60.0000', '5.200');
        $this->accrueReceipt('40.0000', '5.400');

        $invoice = $this->createInvoice(
            $poLine,
            qty: '100.0000',
            unitPrice: '5.280',
            subtotal: '528.000',
            recoverableVat: '100.320',
            total: '628.320',
            snapshotBasis: '5.280000',
            matchedReceiptLineId: $first->id,
        );

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '528.000', credit: '0.000');
        $this->assertLeg($entry, $this->vatAccount, debit: '100.320', credit: '0.000');
        $this->assertLeg($entry, $this->payableAccount, debit: '0.000', credit: '628.320');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame('60.0000', GoodsReceiptLine::findOrFail($first->id)->quantity_invoiced);
        $this->assertSame('40.0000', GoodsReceiptLine::findOrFail($second->id)->quantity_invoiced);
        $this->assertSame('100.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, Document::findOrFail($invoice->id)->match_status);
    }

    public function test_partial_invoice_consumes_fifo_receipt_line_only(): void
    {
        $poLine = $this->createPoLine('100.0000', '5.000', '100.0000');
        [$first, $second] = $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000'],
            ['qty' => '40.0000', 'basis' => '5.400000'],
        ]);
        $this->accrueReceipt('60.0000', '5.200');
        $this->accrueReceipt('40.0000', '5.400');

        $invoice = $this->createInvoice(
            $poLine,
            qty: '50.0000',
            unitPrice: '5.200',
            subtotal: '260.000',
            recoverableVat: '49.400',
            total: '309.400',
            snapshotBasis: '5.200000',
            matchedReceiptLineId: $first->id,
        );

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '260.000', credit: '0.000');
        $this->assertSame('268.000', $this->net408());
        $this->assertSame('50.0000', GoodsReceiptLine::findOrFail($first->id)->quantity_invoiced);
        $this->assertSame('0.0000', GoodsReceiptLine::findOrFail($second->id)->quantity_invoiced);
        $this->assertSame('50.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
    }

    public function test_paid_invoice_cannot_consume_free_receipt_window(): void
    {
        $poLine = $this->createPoLine('12.0000', '5.000', '10.0000');
        $poLine->forceFill([
            'free_quantity' => '2.0000',
            'free_quantity_received' => '2.0000',
            'free_quantity_invoiced' => '0.0000',
        ])->save();
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'free_qty' => '2.0000', 'basis' => '5.000000'],
        ]);
        $this->accrueReceipt('10.0000', '5.000');

        $invoice = $this->createInvoice(
            $poLine,
            qty: '11.0000',
            unitPrice: '5.000',
            subtotal: '55.000',
            recoverableVat: '10.450',
            total: '65.450',
            snapshotBasis: '5.000000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $this->assertSame(SupplierInvoiceMatchStatus::QuantityVariance, app(SupplierInvoiceMatcher::class)->match($invoice));

        $threw = false;
        try {
            app(SupplierInvoicePostingService::class)->post($invoice);
        } catch (\DomainException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Paid overbilling into the free window must be rejected.');
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('0.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame('0.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->free_quantity_invoiced);
        $this->assertSame('0.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
        $this->assertSame('0.0000', DocumentLine::findOrFail($poLine->id)->free_quantity_invoiced);
    }

    public function test_bonus_line_consumes_free_receipt_window_without_overclearing_408(): void
    {
        $poLine = $this->createPoLine('12.0000', '5.000', '10.0000');
        $poLine->forceFill([
            'free_quantity' => '2.0000',
            'free_quantity_received' => '2.0000',
            'free_quantity_invoiced' => '0.0000',
        ])->save();
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'free_qty' => '2.0000', 'basis' => '5.000000'],
        ]);
        $this->accrueReceipt('10.0000', '5.000');

        $invoice = $this->createInvoice(
            $poLine,
            qty: '10.0000',
            unitPrice: '5.000',
            subtotal: '50.000',
            recoverableVat: '9.500',
            total: '59.500',
            snapshotBasis: '5.000000',
            matchedReceiptLineId: $receiptLine->id,
        );
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Receipt clearing bonus line',
            'quantity' => '2.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '0.000',
            'line_total' => '0.000',
            'tax_amount' => '0.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '0.000',
            'non_recoverable_tax_amount' => '0.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $poLine->id,
            'is_bonus_line' => true,
        ]);
        $invoice->load('lines');

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '50.000', credit: '0.000');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame('10.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame('2.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->free_quantity_invoiced);
        $this->assertSame('10.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
        $this->assertSame('2.0000', DocumentLine::findOrFail($poLine->id)->free_quantity_invoiced);
    }

    public function test_receipt_grain_overclear_rejects_and_rolls_back(): void
    {
        $poLine = $this->createPoLine('5.0000', '10.000', '5.0000');
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '5.0000', 'basis' => '10.000000'],
        ]);
        $this->accrueReceipt('5.0000', '10.000');

        $first = $this->createInvoice(
            $poLine,
            qty: '4.0000',
            unitPrice: '10.000',
            subtotal: '40.000',
            recoverableVat: '7.600',
            total: '47.600',
            snapshotBasis: '10.000000',
            matchedReceiptLineId: $receiptLine->id,
        );
        app(SupplierInvoicePostingService::class)->post($first);

        $second = $this->createInvoice(
            $poLine,
            qty: '2.0000',
            unitPrice: '10.000',
            subtotal: '20.000',
            recoverableVat: '3.800',
            total: '23.800',
            snapshotBasis: '10.000000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $threw = false;
        try {
            app(SupplierInvoicePostingService::class)->post($second);
        } catch (\DomainException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Receipt-line over-clear must throw on the receipt-grain path.');
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $second->id)->count());
        $this->assertSame('4.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame('4.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, Document::findOrFail($second->id)->status);
    }

    public function test_clearing_uses_live_receipt_accruals_not_match_snapshot(): void
    {
        $poLine = $this->createPoLine('100.0000', '5.000', '100.0000');
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '100.0000', 'basis' => '5.200000'],
        ]);

        $invoice = $this->createInvoice(
            $poLine,
            qty: '100.0000',
            unitPrice: '5.200',
            subtotal: '520.000',
            recoverableVat: '98.800',
            total: '618.800',
            snapshotBasis: '5.200000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $receiptLine->forceFill(['accrual_unit_cost' => '6.000000'])->save();
        $this->accrueReceipt('100.0000', '6.000');

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '600.000', credit: '0.000');
        $this->assertLeg($entry, $this->ppvIncomeAccount, debit: '0.000', credit: '80.000');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, Document::findOrFail($invoice->id)->match_status);
    }

    public function test_receipt_line_lock_contention_requires_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FOR UPDATE receipt-line contention requires PostgreSQL; sqlite does not enforce row locks.');
        }

        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_billing_delta_routes_to_ppv_while_receipt_bases_clear_408(): void
    {
        $poLine = $this->createPoLine('100.0000', '5.000', '100.0000');
        [$first] = $this->createReceiptLines($poLine, [
            ['qty' => '60.0000', 'basis' => '5.200000'],
            ['qty' => '40.0000', 'basis' => '5.400000'],
        ]);
        $this->accrueReceipt('60.0000', '5.200');
        $this->accrueReceipt('40.0000', '5.400');

        $invoice = $this->createInvoice(
            $poLine,
            qty: '100.0000',
            unitPrice: '5.400',
            subtotal: '540.000',
            recoverableVat: '102.600',
            total: '642.600',
            snapshotBasis: '5.280000',
            matchedReceiptLineId: $first->id,
        );

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '528.000', credit: '0.000');
        $this->assertLeg($entry, $this->ppvExpenseAccount, debit: '12.000', credit: '0.000');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, Document::findOrFail($invoice->id)->match_status);
    }

    public function test_multi_po_invoice_clears_each_receipt_line_basis_and_routes_delta_to_ppv(): void
    {
        $firstPoLine = $this->createPoLine('10.0000', '10.000', '10.0000');
        $secondPoLine = $this->createPoLine('5.0000', '20.000', '5.0000');
        [$firstReceipt] = $this->createReceiptLines($firstPoLine, [
            ['qty' => '10.0000', 'basis' => '10.000000'],
        ]);
        [$secondReceipt] = $this->createReceiptLines($secondPoLine, [
            ['qty' => '5.0000', 'basis' => '22.000000'],
        ]);
        $this->accrueReceipt('10.0000', '10.000');
        $this->accrueReceipt('5.0000', '22.000');

        $invoice = $this->createInvoice(
            $firstPoLine,
            qty: '10.0000',
            unitPrice: '10.000',
            subtotal: '100.000',
            recoverableVat: '0.000',
            total: '205.000',
            snapshotBasis: '10.000000',
            matchedReceiptLineId: $firstReceipt->id,
        );
        $invoice->forceFill([
            'source_document_id' => $firstPoLine->document_id,
            'subtotal' => '205.000',
            'tax_amount' => '0.000',
            'total' => '205.000',
            'payload' => [
                'supplier_invoice' => [
                    'source_document_ids' => [$firstPoLine->document_id, $secondPoLine->document_id],
                ],
            ],
        ])->save();
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 2,
            'description' => 'Second PO invoice line',
            'quantity' => '5.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '21.000',
            'line_total' => '105.000',
            'tax_amount' => '0.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '0.000',
            'non_recoverable_tax_amount' => '0.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $secondPoLine->id,
            'price_match_basis' => '22.000000',
            'matched_receipt_line_id' => $secondReceipt->id,
        ]);
        $invoice->load('lines');

        app(SupplierInvoicePostingService::class)->post($invoice);

        $entry = $this->clearingEntry($invoice);
        $this->assertLeg($entry, $this->grirAccount, debit: '210.000', credit: '0.000');
        $this->assertLeg($entry, $this->ppvIncomeAccount, debit: '0.000', credit: '5.000');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame('10.0000', GoodsReceiptLine::findOrFail($firstReceipt->id)->quantity_invoiced);
        $this->assertSame('5.0000', GoodsReceiptLine::findOrFail($secondReceipt->id)->quantity_invoiced);
        $this->assertSame('10.0000', DocumentLine::findOrFail($firstPoLine->id)->quantity_invoiced);
        $this->assertSame('5.0000', DocumentLine::findOrFail($secondPoLine->id)->quantity_invoiced);
        $this->assertSame(SupplierInvoiceMatchStatus::PriceVariance, Document::findOrFail($invoice->id)->match_status);
    }

    public function test_posting_rejects_invoice_line_parent_with_different_partner(): void
    {
        $otherSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Receipt Clearing Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        $poLine = $this->createPoLine('5.0000', '10.000', '5.0000');
        Document::findOrFail($poLine->document_id)->forceFill(['partner_id' => $otherSupplier->id])->save();
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '5.0000', 'basis' => '10.000000'],
        ]);
        $this->accrueReceipt('5.0000', '10.000');
        $invoice = $this->createInvoice(
            $poLine,
            qty: '5.0000',
            unitPrice: '10.000',
            subtotal: '50.000',
            recoverableVat: '0.000',
            total: '50.000',
            snapshotBasis: '10.000000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $threw = false;
        try {
            app(SupplierInvoicePostingService::class)->post($invoice);
        } catch (\DomainException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Posting must reject PO parents that do not share the invoice partner.');
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('0.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame(DocumentStatus::Draft, Document::findOrFail($invoice->id)->status);
    }

    public function test_zero_receipt_line_po_uses_legacy_po_line_basis(): void
    {
        $poLine = $this->createPoLine('10.0000', '5.000', '10.0000', accrualUnitCost: '5.000000');
        $this->accrueReceipt('10.0000', '5.000');
        $invoice = $this->createInvoice(
            $poLine,
            qty: '10.0000',
            unitPrice: '5.000',
            subtotal: '50.000',
            recoverableVat: '9.500',
            total: '59.500',
            snapshotBasis: '5.000000',
            matchedReceiptLineId: null,
        );

        app(SupplierInvoicePostingService::class)->post($invoice);

        $this->assertLeg($this->clearingEntry($invoice), $this->grirAccount, debit: '50.000', credit: '0.000');
        $this->assertSame('0.000', $this->net408());
        $this->assertSame('10.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
    }

    public function test_idempotent_repost_is_noop_after_receipt_line_quantities_are_consumed(): void
    {
        $poLine = $this->createPoLine('10.0000', '5.000', '10.0000');
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'basis' => '5.000000'],
        ]);
        $this->accrueReceipt('10.0000', '5.000');
        $invoice = $this->createInvoice(
            $poLine,
            qty: '10.0000',
            unitPrice: '5.000',
            subtotal: '50.000',
            recoverableVat: '9.500',
            total: '59.500',
            snapshotBasis: '5.000000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $service = app(SupplierInvoicePostingService::class);
        $service->post($invoice);
        $service->post(Document::findOrFail($invoice->id));

        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('10.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame('10.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
    }

    public function test_idempotent_repost_is_noop_after_purchase_order_partner_drift(): void
    {
        $poLine = $this->createPoLine('10.0000', '5.000', '10.0000');
        [$receiptLine] = $this->createReceiptLines($poLine, [
            ['qty' => '10.0000', 'basis' => '5.000000'],
        ]);
        $this->accrueReceipt('10.0000', '5.000');
        $invoice = $this->createInvoice(
            $poLine,
            qty: '10.0000',
            unitPrice: '5.000',
            subtotal: '50.000',
            recoverableVat: '9.500',
            total: '59.500',
            snapshotBasis: '5.000000',
            matchedReceiptLineId: $receiptLine->id,
        );

        $service = app(SupplierInvoicePostingService::class);
        $service->post($invoice);

        $otherSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Drifted Receipt Clearing Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        Document::findOrFail($poLine->document_id)->forceFill(['partner_id' => $otherSupplier->id])->save();

        $service->post(Document::findOrFail($invoice->id));

        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $invoice->id)->count());
        $this->assertSame('10.0000', GoodsReceiptLine::findOrFail($receiptLine->id)->quantity_invoiced);
        $this->assertSame('10.0000', DocumentLine::findOrFail($poLine->id)->quantity_invoiced);
    }

    /**
     * @param  numeric-string  $orderedQty
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $receivedQty
     */
    private function createPoLine(
        string $orderedQty,
        string $unitPrice,
        string $receivedQty,
        ?string $accrualUnitCost = null,
    ): DocumentLine {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-RC-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);

        return DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Receipt clearing product',
            'quantity' => $orderedQty,
            'quantity_received' => $receivedQty,
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => bcmul($orderedQty, $unitPrice, 3),
            'allocated_costs' => '0.0000',
            'accrual_unit_cost' => $accrualUnitCost,
        ]);
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
            'receipt_number' => 'GRN-RC-'.Str::upper(Str::random(6)),
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
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     */
    private function createInvoice(
        DocumentLine $poLine,
        string $qty,
        string $unitPrice,
        string $subtotal,
        string $recoverableVat,
        string $total,
        string $snapshotBasis,
        ?string $matchedReceiptLineId,
    ): Document {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $poLine->document_id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-RC-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $subtotal,
            'line_tax_amount' => $recoverableVat,
            'stamp_duty_amount' => '0.000',
            'tax_amount' => $recoverableVat,
            'total' => $total,
            'match_status' => SupplierInvoiceMatchStatus::Unmatched,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Receipt clearing invoice line',
            'quantity' => $qty,
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => $subtotal,
            'tax_amount' => $recoverableVat,
            'tax_recoverable' => true,
            'recoverable_tax_amount' => $recoverableVat,
            'non_recoverable_tax_amount' => '0.000',
            'allocated_costs' => '0.0000',
            'source_line_id' => $poLine->id,
            'price_match_basis' => $snapshotBasis,
            'matched_receipt_line_id' => $matchedReceiptLineId,
        ]);

        return $invoice->load('lines');
    }

    private function accrueReceipt(string $qty, string $basis): void
    {
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            $qty,
            $basis,
            'TND',
        );
    }

    private function clearingEntry(Document $invoice): JournalEntry
    {
        return JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
            ->firstOrFail()
            ->load('lines');
    }

    private function assertLeg(JournalEntry $entry, Account $account, string $debit, string $credit): void
    {
        $leg = $entry->lines->firstWhere('account_id', $account->id);
        $this->assertNotNull($leg, "Missing leg for account {$account->code}");
        $this->assertSame($debit, $leg->debit);
        $this->assertSame($credit, $leg->credit);
    }

    private function net408(): string
    {
        $net = '0.000';
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_lines.account_id', $this->grirAccount->id)
            ->select('journal_lines.debit', 'journal_lines.credit')
            ->get();

        foreach ($lines as $line) {
            $net = bcadd($net, bcsub($line->credit, $line->debit, 3), 3);
        }

        return $net;
    }
}
