<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Taxation\Domain\DTOs\VatAggregation;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Repositories\VatDataRepositoryInterface;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VatDataRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private ?Location $location = null;

    private ?Terminal $terminal = null;

    private ?User $cashier = null;

    private VatDataRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required country record
        DB::table('countries')->insert([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name' => 'VAT Test Tenant',
            'slug' => 'vat-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT Test Company',
            'legal_name' => 'VAT Test Company SARL',
            'tax_id' => 'VAT123456',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->repository = app(VatDataRepositoryInterface::class);
    }

    public function test_aggregates_invoice_tax_details_as_output(): void
    {
        $invoice = $this->createDocument(DocumentType::Invoice, '2026-02-15');

        DocumentTaxDetail::create([
            'document_id' => $invoice->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '1000.000',
            'tax_amount' => '190.000',
            'is_stamp_duty' => false,
        ]);

        $this->createTaxConfiguration('19.00', true);

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        $this->assertCount(1, $results);
        $this->assertInstanceOf(VatAggregation::class, $results[0]);
        $this->assertSame('OUTPUT', $results[0]->direction);
        $this->assertSame('1000.000', $results[0]->baseAmount);
        $this->assertSame('190.000', $results[0]->vatAmount);
        $this->assertSame(1, $results[0]->documentCount);
    }

    public function test_aggregates_expense_tax_details_as_input(): void
    {
        $expense = $this->createDocument(DocumentType::Expense, '2026-02-10');

        DocumentTaxDetail::create([
            'document_id' => $expense->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '500.000',
            'tax_amount' => '95.000',
            'is_stamp_duty' => false,
        ]);

        $this->createTaxConfiguration('19.00', true);

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        $this->assertCount(1, $results);
        $this->assertSame('INPUT', $results[0]->direction);
        $this->assertSame('500.000', $results[0]->baseAmount);
        $this->assertSame('95.000', $results[0]->vatAmount);
        $this->assertSame(1, $results[0]->documentCount);
    }

    public function test_groups_by_tax_rate(): void
    {
        $invoice1 = $this->createDocument(DocumentType::Invoice, '2026-02-05');
        $invoice2 = $this->createDocument(DocumentType::Invoice, '2026-02-12');

        DocumentTaxDetail::create([
            'document_id' => $invoice1->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '1000.000',
            'tax_amount' => '190.000',
            'is_stamp_duty' => false,
        ]);

        DocumentTaxDetail::create([
            'document_id' => $invoice2->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA7',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 7%',
            'tax_rate' => '7.00',
            'tax_base' => '2000.000',
            'tax_amount' => '140.000',
            'is_stamp_duty' => false,
        ]);

        $this->createTaxConfiguration('19.00', true);
        $this->createTaxConfiguration('7.00', true);

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        $this->assertCount(2, $results);

        $rates = array_map(fn (VatAggregation $a): string => $a->taxRate, $results);
        $this->assertContains('7.00', $rates);
        $this->assertContains('19.00', $rates);

        // Both should be OUTPUT direction (invoices)
        foreach ($results as $result) {
            $this->assertSame('OUTPUT', $result->direction);
        }
    }

    public function test_excludes_stamp_duty_from_aggregation(): void
    {
        $invoice = $this->createDocument(DocumentType::Invoice, '2026-02-15');

        // Regular VAT
        DocumentTaxDetail::create([
            'document_id' => $invoice->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '1000.000',
            'tax_amount' => '190.000',
            'is_stamp_duty' => false,
        ]);

        // Stamp duty — should be excluded
        DocumentTaxDetail::create([
            'document_id' => $invoice->id,
            'sequence_order' => 2,
            'tax_code' => 'STAMP',
            'tax_type' => TaxType::FixedAmount,
            'tax_name' => 'Stamp Duty',
            'tax_rate' => null,
            'tax_fixed_amount' => '1.000',
            'tax_base' => null,
            'tax_amount' => '1.000',
            'is_stamp_duty' => true,
        ]);

        $this->createTaxConfiguration('19.00', true);

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        // Only the VAT entry, not the stamp duty
        $this->assertCount(1, $results);
        $this->assertSame('19.00', $results[0]->taxRate);
        $this->assertSame('190.000', $results[0]->vatAmount);
    }

    public function test_filters_by_date_range(): void
    {
        // Inside range
        $insideInvoice = $this->createDocument(DocumentType::Invoice, '2026-02-15');
        DocumentTaxDetail::create([
            'document_id' => $insideInvoice->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '1000.000',
            'tax_amount' => '190.000',
            'is_stamp_duty' => false,
        ]);

        // Outside range — before
        $beforeInvoice = $this->createDocument(DocumentType::Invoice, '2026-01-15');
        DocumentTaxDetail::create([
            'document_id' => $beforeInvoice->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '500.000',
            'tax_amount' => '95.000',
            'is_stamp_duty' => false,
        ]);

        // Outside range — after
        $afterInvoice = $this->createDocument(DocumentType::Invoice, '2026-03-15');
        DocumentTaxDetail::create([
            'document_id' => $afterInvoice->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA19',
            'tax_type' => TaxType::Percentage,
            'tax_name' => 'TVA 19%',
            'tax_rate' => '19.00',
            'tax_base' => '800.000',
            'tax_amount' => '152.000',
            'is_stamp_duty' => false,
        ]);

        $this->createTaxConfiguration('19.00', true);

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        // Only the in-range invoice
        $this->assertCount(1, $results);
        $this->assertSame('1000.000', $results[0]->baseAmount);
        $this->assertSame('190.000', $results[0]->vatAmount);
        $this->assertSame(1, $results[0]->documentCount);
    }

    /**
     * G-4 — a POS refund must REDUCE the declared OUTPUT base/VAT.
     *
     * The two POS writers disagree on sign for `receipt_type = 'return'`:
     *
     *  - the canonical/fiscal path (PosCoreReceiptProjection::writeVatBreakdown)
     *    mirrors the canonical `vat_breakdown[]` verbatim, and the canonical
     *    view carries NON-NEGATIVE magnitudes — so a refund lands as POSITIVE
     *    `pos_receipt_vat_details` rows;
     *  - the legacy server path (ReceiptReturnService::buildReturnLines) derives
     *    the row from a negated line_total — so a refund lands as NEGATIVE rows.
     *
     * A bare `SUM()` therefore ADDS the canonical refund to output VAT and only
     * accidentally nets the legacy one. `-ABS()` (never a bare `-`) normalises
     * both eras, exactly as PosAnalyticsService::netOfReturns() does.
     */
    public function test_pos_refunds_reduce_output_vat_in_both_writer_sign_conventions(): void
    {
        $this->createTaxConfiguration('19.00', true);

        // Sale: +1000.000 base / +190.000 VAT
        $sale = $this->createPosReceipt(
            receiptType: 'sale',
            postedAt: '2026-02-10 09:00:00',
            subtotal: '1000.000',
            taxAmount: '190.000',
        );
        $this->createPosVatRow($sale, '1000.000', '190.000');

        // Refund written by the CANONICAL path — POSITIVE magnitudes.
        $canonicalRefund = $this->createPosReceipt(
            receiptType: 'return',
            postedAt: '2026-02-11 09:00:00',
            subtotal: '100.000',
            taxAmount: '19.000',
            originalReceiptId: $sale,
        );
        $this->createPosVatRow($canonicalRefund, '100.000', '19.000');

        // Refund written by the LEGACY path — NEGATIVE magnitudes.
        $legacyRefund = $this->createPosReceipt(
            receiptType: 'return',
            postedAt: '2026-02-12 09:00:00',
            subtotal: '-200.000',
            taxAmount: '-38.000',
            originalReceiptId: $sale,
        );
        $this->createPosVatRow($legacyRefund, '-200.000', '-38.000');

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        $this->assertCount(1, $results);
        $this->assertSame('OUTPUT', $results[0]->direction);
        $this->assertSame('19.00', $results[0]->taxRate);

        // 1000.000 − |100.000| − |200.000|
        $this->assertSame('700.000', $results[0]->baseAmount);
        // 190.000 − |19.000| − |38.000|
        $this->assertSame('133.000', $results[0]->vatAmount);
    }

    /**
     * Regression pins for the two filters that sit beside the netting CASE —
     * they must survive the sign-normalisation change.
     */
    public function test_pos_aggregation_still_excludes_training_and_voided_receipts(): void
    {
        $this->createTaxConfiguration('19.00', true);

        $sale = $this->createPosReceipt(
            receiptType: 'sale',
            postedAt: '2026-02-10 09:00:00',
            subtotal: '1000.000',
            taxAmount: '190.000',
        );
        $this->createPosVatRow($sale, '1000.000', '190.000');

        // Training-mode sale — never declarable.
        $training = $this->createPosReceipt(
            receiptType: 'sale',
            postedAt: '2026-02-13 09:00:00',
            subtotal: '5000.000',
            taxAmount: '950.000',
            isTraining: true,
        );
        $this->createPosVatRow($training, '5000.000', '950.000');

        // Voided sale — never declarable.
        $voided = $this->createPosReceipt(
            receiptType: 'sale',
            postedAt: '2026-02-14 09:00:00',
            subtotal: '7000.000',
            taxAmount: '1330.000',
            isVoided: true,
        );
        $this->createPosVatRow($voided, '7000.000', '1330.000');

        // A training-mode REFUND must not net anything out either.
        $trainingRefund = $this->createPosReceipt(
            receiptType: 'return',
            postedAt: '2026-02-15 09:00:00',
            subtotal: '300.000',
            taxAmount: '57.000',
            originalReceiptId: $training,
            isTraining: true,
        );
        $this->createPosVatRow($trainingRefund, '300.000', '57.000');

        // The exact cell where the new netting CASE meets the is_voided filter:
        // a VOIDED REFUND. `is_voided` must win — a cancelled refund never
        // happened, so it must not deduct. Without this pin, moving the refund
        // arithmetic ahead of the exclusion filters would go unnoticed.
        $voidedRefund = $this->createPosReceipt(
            receiptType: 'return',
            postedAt: '2026-02-16 09:00:00',
            subtotal: '400.000',
            taxAmount: '76.000',
            originalReceiptId: $sale,
            isVoided: true,
        );
        $this->createPosVatRow($voidedRefund, '400.000', '76.000');

        // ...and its legacy-sign twin, so the pin holds in both writer eras.
        $voidedLegacyRefund = $this->createPosReceipt(
            receiptType: 'return',
            postedAt: '2026-02-17 09:00:00',
            subtotal: '-500.000',
            taxAmount: '-95.000',
            originalReceiptId: $sale,
            isVoided: true,
        );
        $this->createPosVatRow($voidedLegacyRefund, '-500.000', '-95.000');

        $results = $this->repository->aggregateByRateAndDirection(
            $this->company->id,
            '2026-02-01',
            '2026-02-28'
        );

        $this->assertCount(1, $results);
        $this->assertSame('1000.000', $results[0]->baseAmount);
        $this->assertSame('190.000', $results[0]->vatAmount);
    }

    /**
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $taxAmount
     */
    private function createPosReceipt(
        string $receiptType,
        string $postedAt,
        string $subtotal,
        string $taxAmount,
        ?Receipt $originalReceiptId = null,
        bool $isTraining = false,
        bool $isVoided = false,
    ): Receipt {
        $attributes = [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->posLocation()->id,
            'terminal_id' => $this->posTerminal()->id,
            'cashier_id' => $this->posCashier()->id,
            'receipt_type' => $receiptType,
            'posted_at' => $postedAt,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => '0.000',
            'total' => bcadd($subtotal, $taxAmount, 3),
            'currency' => 'TND',
            'is_training' => $isTraining,
            'is_voided' => $isVoided,
        ];

        if ($receiptType === 'return') {
            // pos_receipts_return_logic CHECK (PostgreSQL): a return must
            // carry both an original receipt and a reason.
            $attributes['original_receipt_id'] = $originalReceiptId?->id;
            $attributes['return_reason'] = 'defective';
        }

        if ($isVoided) {
            // pos_receipts_void_logic CHECK (PostgreSQL).
            $attributes['voided_at'] = $postedAt;
            $attributes['voided_by'] = $this->posCashier()->id;
            $attributes['void_reason'] = 'test void';
        }

        return Receipt::factory()->create($attributes);
    }

    /**
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $vatAmount
     */
    private function createPosVatRow(Receipt $receipt, string $netAmount, string $vatAmount): ReceiptVatDetail
    {
        return ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => '19.00',
            'net_amount' => $netAmount,
            'vat_amount' => $vatAmount,
            'gross_amount' => bcadd($netAmount, $vatAmount, 3),
        ]);
    }

    private function posLocation(): Location
    {
        return $this->location ??= Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
    }

    private function posTerminal(): Terminal
    {
        return $this->terminal ??= Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->posLocation()->id,
        ]);
    }

    private function posCashier(): User
    {
        return $this->cashier ??= User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function createDocument(DocumentType $type, string $documentDate): Document
    {
        $prefix = $type->getPrefix();
        $number = $prefix.'-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => $documentDate,
            'currency' => 'TND',
            'subtotal' => '1000.000',
            'tax_amount' => '190.000',
            'total' => '1190.000',
            // A SEALED fiscal document must carry fiscal core
            // (chk_fiscal_mandatory_core, enforced by PostgreSQL).
            'fiscal_hash' => hash('sha256', 'vat-repo-'.$number),
            'chain_sequence' => 1,
        ]);
    }

    private function createTaxConfiguration(string $rate, bool $isRecoverable): TaxConfiguration
    {
        return TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => TaxType::Percentage,
            'name' => "TVA {$rate}%",
            'code' => 'TVA'.str_replace('.', '', $rate),
            'percentage_rate' => $rate,
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 1,
            'applicable_document_types' => [],
            'is_stamp_duty' => false,
            'is_recoverable' => $isRecoverable,
        ]);
    }
}
