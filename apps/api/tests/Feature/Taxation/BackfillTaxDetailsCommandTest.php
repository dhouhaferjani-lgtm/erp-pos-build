<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * V6 (2026-08-03 gate) -- the backfill command that remediates historical
 * document_tax_details rows written before the tax_base / is_stamp_duty
 * fixes. Exercises all three contract pieces: dry-run writes nothing,
 * --apply rewrites a document whose recomputed total matches its stored
 * (signed) total, and the invariant guard SKIPS + reports a document whose
 * recomputed total would differ (never silently rewriting history that is
 * more than a labeling bug).
 */
final class BackfillTaxDetailsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Backfill Test Tenant',
            'slug' => 'backfill-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill Test Company',
            'legal_name' => 'Backfill Test Company SARL',
            'tax_id' => 'TAX777',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Backfill Test Customer',
            'type' => PartnerType::Customer,
        ]);

        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['TAX_INVOICE'],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 7%',
            'code' => 'TVA_7',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '7.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 2,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['TAX_INVOICE'],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Timbre Fiscal - Facture',
            'code' => 'STAMP_TAX_INVOICE',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['TAX_INVOICE'],
            'is_active' => true,
            'is_stamp_duty' => true,
        ]);
    }

    public function test_dry_run_reports_the_fix_without_writing_rows(): void
    {
        $document = $this->createLegacyInvoice();

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id])
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('Would rewrite 1')
            ->assertSuccessful();

        $details = DocumentTaxDetail::where('document_id', $document->id)->orderBy('sequence_order')->get();
        $tva19 = $details->firstWhere('tax_code', 'TVA_19');
        $stamp = $details->firstWhere('tax_code', 'STAMP_TAX_INVOICE');
        $this->assertNotNull($tva19);
        $this->assertNotNull($stamp);

        // Unchanged -- dry-run must never write.
        $this->assertSame('300.000', $tva19->tax_base);
        $this->assertFalse((bool) $stamp->is_stamp_duty);
    }

    public function test_apply_rewrites_rows_to_the_corrected_shape(): void
    {
        $document = $this->createLegacyInvoice();

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('[APPLY]')
            ->expectsOutputToContain('Rewrote 1')
            ->assertSuccessful();

        $details = DocumentTaxDetail::where('document_id', $document->id)->orderBy('sequence_order')->get();
        $tva19 = $details->firstWhere('tax_code', 'TVA_19');
        $tva7 = $details->firstWhere('tax_code', 'TVA_7');
        $stamp = $details->firstWhere('tax_code', 'STAMP_TAX_INVOICE');
        $this->assertNotNull($tva19);
        $this->assertNotNull($tva7);
        $this->assertNotNull($stamp);

        // Per-rate base, not the whole subtotal (defect 1).
        $this->assertSame('100.000', $tva19->tax_base);
        $this->assertSame('200.000', $tva7->tax_base);
        // Stamp correctly re-flagged (defect 2).
        $this->assertTrue((bool) $stamp->is_stamp_duty);
        // Amounts unchanged -- this document's total was NOT affected by
        // the fix (no discount, no exempt line), so it was safe to rewrite.
        $this->assertSame('19.000', $tva19->tax_amount);
        $this->assertSame('14.000', $tva7->tax_amount);
        $this->assertSame('1.000', $stamp->tax_amount);
    }

    public function test_skips_a_document_whose_recomputed_total_would_differ_from_the_signed_total(): void
    {
        // A document-level discount predating the V1 fix: the OLD code
        // charged VAT on the PRE-discount base, so the stored (already
        // SIGNED) total baked in that overstatement. Recomputing today
        // would yield a DIFFERENT total -- this document's history is not
        // a pure tax_base/is_stamp_duty labeling bug, so it must be
        // skipped, not silently rewritten.
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-SKIP-0001',
            'document_date' => '2026-01-15',
            'currency' => 'TND',
            'discount_amount' => '50.000',
            // Pre-V1 buggy total: subtotal(250, correctly discounted) +
            // tax computed on the PRE-discount 300 @ 19% = 57.000.
            'subtotal' => '250.000',
            'tax_amount' => '57.000',
            'total' => '307.000',
            'fiscal_hash' => hash('sha256', 'backfill-skip-INV-SKIP-0001'),
            'chain_sequence' => 1,
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Widget',
            'quantity' => '1',
            'unit_price' => '300.000',
            'tax_rate' => '19.00',
            'line_total' => '300.000',
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA_19',
            'tax_name' => 'TVA 19%',
            'tax_type' => 'PERCENTAGE',
            'tax_rate' => '19.00',
            'tax_base' => '300.000',
            'tax_amount' => '57.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        // Both fragments are on the SAME output line (the warn() call), so
        // a single expectsOutputToContain covers it -- chaining a second
        // call against the same already-matched line is unreliable.
        // Recomputed total also picks up the STAMP_TAX_INVOICE config
        // seeded in setUp() (1.000, TAX_INVOICE), which this legacy
        // fixture never snapshotted: 250 (discounted base) + 47.500 (VAT
        // on the discounted base) + 1.000 (stamp) = 298.500.
        Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('SKIPPED INV-SKIP-0001 (', $output);
        $this->assertStringContainsString('stored total 307.000 != recomputed 298.500', $output);

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        // Untouched.
        $this->assertSame('300.000', $detail->tax_base);
        $this->assertSame('57.000', $detail->tax_amount);
    }

    /**
     * N1 (2026-08-03 re-gate): a total-only guard is not sufficient. Live
     * example on demo-pharmacy-tn, INV-2026-0029: a LINELESS document whose
     * stored header is already internally inconsistent (subtotal 250.000 +
     * tax_amount 1.000 = 251.000 != stored total 1.000), yet the
     * RECOMPUTED total (0.000 subtotal from zero lines + 1.000 stamp =
     * 1.000) happens to coincide with the stored total by accident. A
     * total-only comparison would have let this document through and
     * rewritten its tax details while its broken header stood unexamined.
     */
    public function test_skips_a_lineless_document_whose_stored_header_is_already_inconsistent(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-2026-0029',
            'document_date' => '2026-01-20',
            'currency' => 'TND',
            // Broken header, verbatim from the live tenant: subtotal +
            // tax_amount (251.000) != total (1.000).
            'subtotal' => '250.000',
            'tax_amount' => '1.000',
            'total' => '1.000',
            'fiscal_hash' => hash('sha256', 'backfill-lineless-INV-2026-0029'),
            'chain_sequence' => 1,
        ]);
        // No DocumentLine rows at all -- lineless.
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 99,
            'tax_code' => 'STAMP_TAX_INVOICE',
            'tax_name' => 'Timbre Fiscal - Facture',
            'tax_type' => 'FIXED_AMOUNT',
            'tax_rate' => '0.00',
            'tax_base' => '0.000',
            'tax_amount' => '1.000',
            'is_stamp_duty' => true,
            'created_at' => now(),
        ]);

        Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('SKIPPED INV-2026-0029 (', $output);
        $this->assertStringContainsString(
            'stored subtotal 250.000 + tax_amount 1.000 != stored total 1.000 (header already inconsistent)',
            $output,
        );
        // calculateSubtotal() over zero lines returns the raw seed '0'
        // (never formatted to scale, since there's nothing to bcadd).
        $this->assertStringContainsString('recomputed subtotal 0 != stored subtotal 250.000', $output);
        // The total-only check would NOT have fired on its own -- both are
        // 1.000. Confirms the new checks are what caught this document.
        $this->assertStringNotContainsString('stored total 1.000 != recomputed', $output);

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        // Untouched.
        $this->assertSame('0.000', $detail->tax_base);
        $this->assertTrue((bool) $detail->is_stamp_duty);
    }

    /**
     * Q2 expert-comptable ruling (2026-08-06,
     * docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md):
     * ExpenseService::writeDeductibleVatSnapshot() used to declare the
     * DEDUCTIBLE-PROPORTION base for a partially-deductible expense (V5,
     * 2026-08-03 gate); the Q2 ruling requires the FULL FACIAL subtotal
     * instead. Existing rows written under the V5 writer under-declare the
     * base and need the same backfill treatment as the invoice/credit-note
     * leg above -- this exercises the dedicated expense leg added to
     * `vat:backfill-tax-details` for exactly that.
     */
    public function test_expense_leg_dry_run_reports_the_base_fix_without_writing(): void
    {
        $expense = $this->createLegacyExpense('80.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id])
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('Expense leg')
            ->assertSuccessful();

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        // Unchanged -- dry-run must never write.
        $this->assertSame('80.000', $detail->tax_base);
        $this->assertSame('15.200', $detail->tax_amount);
    }

    public function test_expense_leg_apply_rewrites_the_base_to_the_full_subtotal(): void
    {
        $expense = $this->createLegacyExpense('80.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('[APPLY]')
            ->expectsOutputToContain('Expense leg')
            ->assertSuccessful();

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        // Base rewritten to the full facial subtotal (Q2 ruling) --
        // tax_amount stays the deductible share, untouched.
        $this->assertSame('100.000', $detail->tax_base);
        $this->assertSame('15.200', $detail->tax_amount);
    }

    public function test_expense_leg_leaves_a_hundred_percent_deductible_row_untouched(): void
    {
        // At 100% deductible the V5 prorated base already equals the full
        // subtotal -- nothing for the Q2 leg to fix.
        $expense = $this->createLegacyExpense('100.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('[APPLY]')
            ->assertSuccessful();

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('100.000', $detail->tax_base);
        $this->assertSame('19.000', $detail->tax_amount);
    }

    /**
     * Builds a partially-deductible expense in the exact SHAPE the pre-Q2
     * (V5) writer produced: tax_base holds the DEDUCTIBLE-PROPORTION share
     * of the subtotal (subtotal × deductiblePercent), not the full
     * subtotal -- the defect the expense backfill leg corrects.
     *
     * @param  numeric-string  $deductiblePercent
     */
    private function createLegacyExpense(string $deductiblePercent): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-LEGACY-'.$deductiblePercent,
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Legacy Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => $deductiblePercent,
        ]);

        // deductibleVat = subtotal's VAT (19.000) × deductiblePercent.
        $deductibleVat = bcdiv(bcmul('19.000', $deductiblePercent, 5), '100', 3);
        // V5 writer's shape: base = subtotal × deductiblePercent (same
        // proportion as was claimed of the VAT).
        $legacyBase = bcdiv(bcmul('100.000', $deductiblePercent, 5), '100', 3);

        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => $legacyBase,
            'tax_amount' => $deductibleVat,
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        return $document;
    }

    private function createLegacyInvoice(): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-LEGACY-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '300.000',
            'tax_amount' => '34.000',
            'total' => '334.000',
            'fiscal_hash' => hash('sha256', 'backfill-legacy-INV-LEGACY-0001'),
            'chain_sequence' => 1,
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Item A',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 2,
            'description' => 'Item B',
            'quantity' => '1',
            'unit_price' => '200.000',
            'tax_rate' => '7.00',
            'line_total' => '200.000',
        ]);

        // Pre-fix shape: whole-subtotal base on every rate row (defect 1),
        // stamp mis-flagged is_stamp_duty=false (defect 2).
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => 'TVA_19',
            'tax_name' => 'TVA 19%',
            'tax_type' => 'PERCENTAGE',
            'tax_rate' => '19.00',
            'tax_base' => '300.000',
            'tax_amount' => '19.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 2,
            'tax_code' => 'TVA_7',
            'tax_name' => 'TVA 7%',
            'tax_type' => 'PERCENTAGE',
            'tax_rate' => '7.00',
            'tax_base' => '300.000',
            'tax_amount' => '14.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 99,
            'tax_code' => 'STAMP_TAX_INVOICE',
            'tax_name' => 'Timbre Fiscal - Facture',
            'tax_type' => 'FIXED_AMOUNT',
            'tax_rate' => '0',
            'tax_base' => '300.000',
            'tax_amount' => '1.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
