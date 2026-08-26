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
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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
     * Gate finding I-1 (2026-08-06,
     * docs/superpowers/reviews/2026-08-06-q2-expense-vat-base-gate.md):
     * document_tax_details has no unique index on (document_id,
     * sequence_order), and the PRE-V5 firstOrCreate writer could leave TWO
     * rows in the writer's sequence_order=1/is_stamp_duty=false slot for
     * the same document. `->first()` on that slot is nondeterministic and
     * silently mis-remediates. The leg must detect the duplicate, skip the
     * document, and report it explicitly -- never guess which row to fix.
     */
    public function test_expense_leg_skips_and_reports_a_document_with_duplicate_sequence_order_one_rows(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-DUPLICATE-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Duplicate Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);
        // Row A: whole-subtotal base (an even older shape).
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '100.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now()->subDay(),
        ]);
        // Row B: the V5 deductible-proportion base -- a re-post left this
        // SECOND row in the same slot instead of replacing row A.
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        // Both fragments are on the SAME output line (the warn() call), so
        // Artisan::output() + assertStringContainsString is used instead of
        // chaining two expectsOutputToContain() calls -- chaining against
        // the same already-matched line is unreliable (see the sibling
        // comment on test_skips_a_document_whose_recomputed_total_would_differ_from_the_signed_total above).
        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED EXP-DUPLICATE-0001', $output);
        $this->assertStringContainsString('2 rows at sequence_order=1', $output);

        $this->assertCount(2, DocumentTaxDetail::where('document_id', $document->id)->get());
        // Both untouched -- neither guessed at.
        $rowA = DocumentTaxDetail::where('document_id', $document->id)->where('tax_base', '100.000')->firstOrFail();
        $rowB = DocumentTaxDetail::where('document_id', $document->id)->where('tax_base', '80.000')->firstOrFail();
        $this->assertSame('100.000', $rowA->tax_base);
        $this->assertSame('80.000', $rowB->tax_base);
    }

    /**
     * Gate finding I-2: `vat_period_breakdowns` is a materialized snapshot
     * taken at period CLOSE (VatPeriodManagementService::persistBreakdowns
     * / closePeriod). The leg only touches `document_tax_details`, so a
     * rewritten expense whose document_date falls inside an already-CLOSED
     * period leaves that period's frozen breakdown stale. The leg must
     * name the affected period and instruct the operator to reopen +
     * re-close it -- and must NOT do so automatically.
     */
    public function test_expense_leg_reports_a_reopen_and_reclose_instruction_for_an_affected_closed_period(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
        ]);

        // 'January 2026' / 'reopen' / 're-close' all land on the SAME
        // warn() line -- see the note in the duplicate-rows test above.
        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('January 2026', $output);
        $this->assertStringContainsString('reopen', $output);
        $this->assertStringContainsString('re-close', $output);

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('100.000', $detail->tax_base);
    }

    /**
     * m-9 (2026-08-06 re-gate): the I-2 closed-period-impact block is
     * structurally $apply-independent, but was only ever exercised under
     * --apply. Dry-run is what an operator runs first -- pin that the
     * preview also surfaces the reopen/re-close instruction, and that
     * nothing is written.
     */
    public function test_expense_leg_dry_run_reports_a_reopen_and_reclose_instruction_for_an_affected_closed_period(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[DRY-RUN]', $output);
        $this->assertStringContainsString('January 2026', $output);
        $this->assertStringContainsString('reopen', $output);
        $this->assertStringContainsString('re-close', $output);

        // Dry-run must never write.
        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    /**
     * m-8 (2026-08-06 re-gate): the period lookup used `->first()`, so a
     * SECOND period overlapping the same document_date (a monthly +
     * quarterly period after a `period_type` switch is the realistic
     * cause) went unreported. Both CLOSED periods covering the expense's
     * date must be named.
     */
    public function test_expense_leg_reports_every_overlapping_closed_period_not_just_the_first(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026 (monthly)',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'QUARTERLY',
            'label' => 'Q1 2026 (quarterly)',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('January 2026 (monthly)', $output);
        $this->assertStringContainsString('Q1 2026 (quarterly)', $output);
    }

    /**
     * gate minor-2 (2026-08-07 backend gate,
     * docs/superpowers/reviews/2026-08-07-r2g-backend-gate.md): a document
     * can overlap a CLOSED period and a FILED period simultaneously (e.g. a
     * monthly period closed and a quarterly period already filed). Both
     * sections must print together -- the prior tests only proved a FILED
     * period alone does not print CLOSED-PERIOD IMPACT, not that the two
     * coexist correctly.
     */
    public function test_expense_leg_reports_both_closed_and_filed_sections_when_both_periods_overlap(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026 (closed, monthly)',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'QUARTERLY',
            'label' => 'Q1 2026 (filed, quarterly)',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('CLOSED-PERIOD IMPACT', $output);
        $this->assertStringContainsString('January 2026 (closed, monthly)', $output);
        $this->assertStringContainsString('FILED-PERIOD IMPACT', $output);
        $this->assertStringContainsString('Q1 2026 (filed, quarterly)', $output);

        // The FILED period blocks the mutation without --include-filed --
        // rows stay untouched even though a CLOSED period also overlaps.
        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    /**
     * m-7 (2026-08-06 re-gate): a FILED period must be reported in its OWN
     * section with an escalation message, never the reopen/re-close
     * instruction -- `reopenPeriod()` refuses filed periods
     * ("Only closed periods can be reopened").
     */
    public function test_expense_leg_reports_a_filed_period_with_an_escalation_message_not_a_reopen_instruction(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('FILED-PERIOD IMPACT', $output);
        $this->assertStringContainsString('January 2026', $output);
        $this->assertStringContainsString('ALREADY FILED', $output);
        $this->assertStringContainsString('escalate', strtolower($output));
        // A filed period must NEVER get the reopen+re-close remedy.
        $this->assertStringNotContainsString('CLOSED-PERIOD IMPACT', $output);
        $this->assertStringNotContainsString('reopen this period', $output);

        // IMP-2 (2026-08-07 gate,
        // docs/superpowers/reviews/2026-08-07-r2g-backend-gate.md): --apply
        // must NOT rewrite a row inside an ALREADY-FILED period without an
        // explicit --include-filed -- the FILED-PERIOD IMPACT section must
        // never trail a mutation it warns about. Rows stay untouched and
        // the document is reported as SKIPPED.
        $this->assertStringContainsString('SKIPPED', $output);
        $this->assertStringContainsString('ALREADY-FILED VAT period', $output);
        $this->assertStringContainsString('--include-filed', $output);
        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    /**
     * IMP-2 (2026-08-07 gate) -- the flip side: with --include-filed passed
     * explicitly, the operator has made an informed decision to mutate a
     * FILED-period document anyway; the rewrite goes through.
     */
    public function test_expense_leg_apply_with_include_filed_rewrites_a_row_in_a_filed_period(): void
    {
        $expense = $this->createLegacyExpense('80.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', [
            '--company' => $this->company->id,
            '--apply' => true,
            '--include-filed' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('FILED-PERIOD IMPACT', $output);
        $this->assertStringContainsString('THIS RUN PASSED --include-filed', $output);
        $this->assertStringContainsString('Rewrote 1', $output);
        // The FILED-PERIOD IMPACT section's policy sentence always mentions
        // "SKIPPED" (it states the general rule); what must NOT appear is a
        // per-document SKIPPED line naming this expense -- i.e. it was not
        // added to the leg's own $skipped list.
        $this->assertStringNotContainsString('SKIPPED EXP-LEGACY-80.00', $output);

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('100.000', $detail->tax_base);
    }

    /**
     * IMP-2 -- same gate, the 0%-deductible DELETION path (the more
     * consequential of the two, per the gate finding). Without
     * --include-filed the row survives; with it, the row is deleted.
     */
    public function test_expense_leg_apply_skips_a_zero_percent_deductible_row_in_a_filed_period_without_include_filed(): void
    {
        $expense = $this->createLegacyExpense('0.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('FILED-PERIOD IMPACT', $output);
        $this->assertStringContainsString('SKIPPED', $output);
        $this->assertStringContainsString('deletion refused', $output);
        $this->assertStringContainsString('--include-filed', $output);
        // 0%-deductible remediation count must be 0 -- nothing was queued
        // for deletion, only skipped.
        $this->assertStringContainsString('Deleted 0', $output);

        $this->assertTrue(
            DocumentTaxDetail::where('document_id', $expense->id)->exists(),
            'The row must survive when a FILED period blocks the deletion without --include-filed.',
        );
    }

    public function test_expense_leg_apply_with_include_filed_deletes_a_zero_percent_deductible_row_in_a_filed_period(): void
    {
        $expense = $this->createLegacyExpense('0.00'); // document_date 2026-01-10
        VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => VatPeriodStatus::Filed,
            'closed_at' => now()->subDay(),
            'filed_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', [
            '--company' => $this->company->id,
            '--apply' => true,
            '--include-filed' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Deleted 1', $output);
        $this->assertStringContainsString('THIS RUN PASSED --include-filed', $output);
        $this->assertFalse(DocumentTaxDetail::where('document_id', $expense->id)->exists());
    }

    /**
     * IMP-3 (2026-08-07 gate): a hard-deleted row has no audit trail of its
     * own, so the printed output must be a usable snapshot -- document
     * number, id, tax_base, tax_amount, tax_rate -- captured before the
     * delete, in BOTH dry-run and --apply.
     */
    public function test_expense_leg_zero_percent_deletion_report_includes_a_full_row_snapshot_in_dry_run_and_apply(): void
    {
        $expense = $this->createLegacyExpense('0.00');

        $dryRunOutput = $this->runBackfillAndCaptureOutput(['--company' => $this->company->id]);
        $this->assertStringContainsString('EXP-LEGACY-0.00', $dryRunOutput);
        $this->assertStringContainsString('tax_base=0.000', $dryRunOutput);
        $this->assertStringContainsString('tax_amount=0.000', $dryRunOutput);
        $this->assertStringContainsString('tax_rate=19.00', $dryRunOutput);
        // B-1 (re-gate): the capture-time snapshot line must print INSIDE
        // the scan loop, i.e. BEFORE the post-loop "Scanned N ..." summary,
        // so a mid-scan crash never leaves a committed deletion unrecorded.
        $captureLinePos = strpos($dryRunOutput, ' -- would delete');
        $summaryPos = strpos($dryRunOutput, 'Scanned 1 expense document(s)');
        $this->assertNotFalse($captureLinePos);
        $this->assertNotFalse($summaryPos);
        $this->assertLessThan($summaryPos, $captureLinePos);

        $applyOutput = $this->runBackfillAndCaptureOutput(['--company' => $this->company->id, '--apply' => true]);
        $this->assertStringContainsString('EXP-LEGACY-0.00', $applyOutput);
        $this->assertStringContainsString('tax_base=0.000', $applyOutput);
        $this->assertStringContainsString('tax_amount=0.000', $applyOutput);
        $this->assertStringContainsString('tax_rate=19.00', $applyOutput);
        $this->assertStringContainsString(' -- deleting', $applyOutput);

        $this->assertFalse(DocumentTaxDetail::where('document_id', $expense->id)->exists());
    }

    /**
     * I-3 -- EXPERT RULING RECEIVED 2026-08-07 (
     * docs/superpowers/tickets/2026-08-06-q2-gate-minor-followups.md):
     * "Exclure totalement la charge de la déclaration mensuelle de TVA" --
     * a 0%-deductible expense is excluded entirely from the VAT
     * declaration. The interim "skip, awaiting ruling" behaviour is
     * abandoned; the leg now REMEDIATES by deleting the row. This covers
     * the V5-era shape: tax_base 0.000 / tax_amount 0.000 (100.000 × 0%).
     */
    public function test_expense_leg_dry_run_reports_the_zero_percent_deductible_row_for_deletion_v5_shape(): void
    {
        $expense = $this->createLegacyExpense('0.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id])
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('0%-deductible (I-3 ruling -- excluded from the declaration): Would delete 1')
            ->assertSuccessful();

        // Dry-run must never write -- row survives untouched.
        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('0.000', $detail->tax_base);
        $this->assertSame('0.000', $detail->tax_amount);
    }

    public function test_expense_leg_apply_deletes_the_zero_percent_deductible_row_v5_shape(): void
    {
        $expense = $this->createLegacyExpense('0.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('[APPLY]')
            ->expectsOutputToContain('0%-deductible (I-3 ruling -- excluded from the declaration): Deleted 1')
            ->expectsOutputToContain('EXP-LEGACY-0.00')
            ->assertSuccessful();

        $this->assertFalse(
            DocumentTaxDetail::where('document_id', $expense->id)->exists(),
            'The 0%-deductible row must be DELETED, not rewritten, per the I-3 ruling.',
        );
    }

    /**
     * The interim (pre-ruling) shape the Q2 writer produced for the 0%
     * case before the I-3 ruling landed: full facial base, zero deducted
     * VAT. The remediation branch keys purely on vat_deductible_percent,
     * never on the row's stored base, so it must delete this shape
     * identically to the V5 shape above.
     */
    public function test_expense_leg_apply_deletes_the_zero_percent_deductible_row_interim_shape(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-INTERIM-ZERO-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Interim Zero Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '0.00',
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '100.000', // interim shape: full facial subtotal
            'tax_amount' => '0.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0%-deductible (I-3 ruling -- excluded from the declaration): Deleted 1', $output);
        $this->assertStringContainsString('EXP-INTERIM-ZERO-0001', $output);
        $this->assertFalse(DocumentTaxDetail::where('document_id', $document->id)->exists());
    }

    public function test_expense_leg_zero_percent_deductible_deletion_is_idempotent_on_a_second_run(): void
    {
        $expense = $this->createLegacyExpense('0.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('Deleted 1')
            ->assertSuccessful();
        $this->assertFalse(DocumentTaxDetail::where('document_id', $expense->id)->exists());

        // Second run: the document no longer carries a matching row in the
        // writer's slot, so it drops out of the leg's own scan query
        // entirely -- nothing left to do.
        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('Deleted 0')
            ->assertSuccessful();
        $this->assertFalse(DocumentTaxDetail::where('document_id', $expense->id)->exists());
    }

    /**
     * I-1 (2026-08-06 gate) still wins over I-3's remediation: a
     * 0%-deductible document carrying a DUPLICATE sequence_order=1 slot is
     * skipped and reported, never guessed at -- the duplicate check runs
     * before the deductible percent is even read.
     */
    public function test_expense_leg_duplicate_rows_on_a_zero_percent_deductible_document_still_hit_i1_skip(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-ZERO-DUPLICATE-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Zero Duplicate Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '0.00',
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '0.000',
            'tax_amount' => '0.000',
            'is_stamp_duty' => false,
            'created_at' => now()->subDay(),
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '100.000',
            'tax_amount' => '0.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED EXP-ZERO-DUPLICATE-0001', $output);
        $this->assertStringContainsString('2 rows at sequence_order=1', $output);
        $this->assertStringContainsString('0%-deductible (I-3 ruling -- excluded from the declaration): Deleted 0', $output);
        $this->assertCount(2, DocumentTaxDetail::where('document_id', $document->id)->get());
    }

    /**
     * m-9 (2026-08-06 re-gate): the I-1 duplicate-slot skip is structurally
     * $apply-independent, but was only ever exercised under --apply.
     * Dry-run must surface the same skip-and-report line, and never write.
     */
    public function test_expense_leg_dry_run_reports_a_document_with_duplicate_sequence_order_one_rows(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-DUPLICATE-DRYRUN-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Duplicate Dry-Run Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '100.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now()->subDay(),
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[DRY-RUN]', $output);
        $this->assertStringContainsString('SKIPPED EXP-DUPLICATE-DRYRUN-0001', $output);
        $this->assertStringContainsString('2 rows at sequence_order=1', $output);

        // Dry-run must never write -- both rows survive unchanged.
        $this->assertCount(2, DocumentTaxDetail::where('document_id', $document->id)->get());
        $rowA = DocumentTaxDetail::where('document_id', $document->id)->where('tax_base', '100.000')->firstOrFail();
        $rowB = DocumentTaxDetail::where('document_id', $document->id)->where('tax_base', '80.000')->firstOrFail();
        $this->assertSame('100.000', $rowA->tax_base);
        $this->assertSame('80.000', $rowB->tax_base);
    }

    /**
     * m-3 (2026-08-06 gate): the backfill leg compares/writes tax_base at
     * the RESOLVED CURRENCY scale (2 for EUR) against a decimal(15,3)
     * column. Pin that a scale-2 currency does not produce a false
     * rewrite/no-op mismatch -- the bccomp guard and the stored value must
     * agree on both sides of the comparison at the same scale.
     */
    public function test_expense_leg_scale_two_eur_currency_base_and_bccomp_guard_agree(): void
    {
        $eurCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill EUR Company',
            'legal_name' => 'Backfill EUR Company SARL',
            'tax_id' => 'EUR777',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $eurCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-EUR-SCALE2-0001',
            'document_date' => '2026-01-10',
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'EUR Scale-2 Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);
        // V5 legacy shape: base = subtotal × 80% = 80.00.
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $this->command('vat:backfill-tax-details', ['--company' => $eurCompany->id, '--apply' => true])
            ->expectsOutputToContain('Rewrote 1')
            ->assertSuccessful();

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        // Rewritten to the full facial subtotal -- the decimal(15,3) column
        // pads the EUR scale-2 value with a trailing zero on read.
        $this->assertSame('100.000', $detail->tax_base);

        // The bccomp no-op guard must now agree with the freshly-written
        // value at the SAME (EUR) scale -- a second run must find nothing
        // left to fix, not a false rewrite from a scale mismatch.
        $this->command('vat:backfill-tax-details', ['--company' => $eurCompany->id, '--apply' => true])
            ->expectsOutputToContain('Rewrote 0')
            ->assertSuccessful();

        $detail->refresh();
        $this->assertSame('100.000', $detail->tax_base);
        $this->assertSame('15.200', $detail->tax_amount);
    }

    public function test_expense_leg_apply_is_idempotent_on_a_second_run(): void
    {
        $expense = $this->createLegacyExpense('80.00');

        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('Rewrote 1')
            ->assertSuccessful();

        $detail = DocumentTaxDetail::where('document_id', $expense->id)->firstOrFail();
        $this->assertSame('100.000', $detail->tax_base);

        // Second run must find nothing left to fix -- the row is already
        // at the full subtotal.
        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('Rewrote 0')
            ->assertSuccessful();

        $detail->refresh();
        $this->assertSame('100.000', $detail->tax_base);
        $this->assertSame('15.200', $detail->tax_amount);
    }

    public function test_expense_leg_skips_and_reports_a_document_with_no_expense_metadata(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-NO-METADATA-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        // No ExpenseMetadata row at all.
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        // 'SKIPPED ...' and the reason share ONE warn() line -- see the
        // note on the duplicate-rows test above.
        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED EXP-NO-METADATA-0001', $output);
        $this->assertStringContainsString('no expense_metadata row', $output);
        $this->assertStringContainsString('Scanned 1', $output);

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    public function test_expense_leg_skips_and_reports_a_null_vat_deductible_percent(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-NULL-PERCENT-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Null Percent Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => null,
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '19.000',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED EXP-NULL-PERCENT-0001', $output);
        $this->assertStringContainsString('vat_deductible_percent is null', $output);

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    public function test_expense_leg_skips_and_reports_a_null_subtotal(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-NULL-SUBTOTAL-0001',
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => null,
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
        ExpenseMetadata::create([
            'document_id' => $document->id,
            'vendor_name' => 'Null Subtotal Vendor',
            'is_paid' => false,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);
        DocumentTaxDetail::create([
            'document_id' => $document->id,
            'sequence_order' => 1,
            'tax_code' => null,
            'tax_name' => 'TVA 19.00%',
            'tax_type' => TaxType::Percentage,
            'tax_rate' => '19.00',
            'tax_base' => '80.000',
            'tax_amount' => '15.200',
            'is_stamp_duty' => false,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED EXP-NULL-SUBTOTAL-0001', $output);
        $this->assertStringContainsString('stored subtotal is null', $output);

        $detail = DocumentTaxDetail::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('80.000', $detail->tax_base);
    }

    public function test_expense_leg_skips_when_expense_metadata_table_is_unavailable(): void
    {
        $this->createLegacyExpense('80.00');

        Schema::drop('expense_metadata');

        // No further teardown needed: this test runs inside RefreshDatabase's
        // per-test transaction and PostgreSQL DDL is transactional, so the
        // DROP TABLE is rolled back with everything else at test end.
        $this->command('vat:backfill-tax-details', ['--company' => $this->company->id, '--apply' => true])
            ->expectsOutputToContain('expense_metadata table unavailable')
            ->assertSuccessful();
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

    // ---------------------------------------------------------------------
    // Supplier-invoice leg (B-19) — the MISSING-row mirror of the main leg.
    //
    // The main leg only reaches documents that ALREADY carry a
    // document_tax_details row (proof of a real confirm()). Supplier invoices
    // posted before B-19 carry NONE, so they need their own scope.
    // ---------------------------------------------------------------------

    public function test_supplier_invoice_leg_dry_run_reports_the_missing_snapshot_without_writing(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);

        $output = $this->runBackfillAndCaptureOutput([]);

        $this->assertStringContainsString('Supplier-invoice leg', $output);
        $this->assertStringContainsString('Would snapshot 1', $output);
        $this->assertStringContainsString('WOULD ADD', $output);
        $this->assertStringContainsString('TOTAL deductible VAT delta: 38.000', $output);
        $this->assertSame(
            0,
            DocumentTaxDetail::where('document_id', $invoice->id)->count(),
            'Dry-run must write nothing.',
        );
    }

    public function test_supplier_invoice_leg_apply_writes_the_missing_snapshot_from_the_persisted_lines(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);

        $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $details = DocumentTaxDetail::where('document_id', $invoice->id)->get();
        $this->assertCount(1, $details);
        $this->assertSame('19.00', (string) $details[0]->tax_rate);
        $this->assertSame('200.000', (string) $details[0]->tax_base);
        $this->assertSame('38.000', (string) $details[0]->tax_amount);
        $this->assertFalse((bool) $details[0]->is_stamp_duty);
    }

    public function test_supplier_invoice_leg_also_reaches_a_paid_supplier_invoice(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Paid);

        $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
    }

    public function test_supplier_invoice_leg_apply_is_idempotent_on_a_second_run(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);

        $this->runBackfillAndCaptureOutput(['--apply' => true]);
        $second = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
        $this->assertStringContainsString('Scanned 0 in-scope supplier invoice(s)', $second);
    }

    public function test_supplier_invoice_leg_excludes_a_draft_supplier_invoice(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Draft);

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(
            0,
            DocumentTaxDetail::where('document_id', $invoice->id)->count(),
            'A draft supplier invoice carries no journal entry — backfilling it would be a new over-claim.',
        );
        $this->assertStringContainsString('1 draft (excluded: no journal entry)', $output);
    }

    public function test_supplier_invoice_leg_excludes_a_cancelled_supplier_invoice(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Cancelled);

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(0, DocumentTaxDetail::where('document_id', $invoice->id)->count());
        $this->assertStringContainsString('1 cancelled (excluded: withdrawn)', $output);
    }

    public function test_supplier_invoice_leg_skips_when_recomputed_vat_differs_from_the_posted_line_tax_amount(): void
    {
        // Stored line_tax_amount (38.500) is what the GL posted as recoverable
        // input VAT; the persisted lines recompute to 38.000. Declaring a
        // figure the ledger does not carry is a value question for a human.
        $invoice = $this->createUnsnapshottedSupplierInvoice(
            DocumentStatus::Posted,
            lineTaxAmount: '38.500',
            taxAmount: '38.500',
            total: '238.500',
        );

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(0, DocumentTaxDetail::where('document_id', $invoice->id)->count());
        $this->assertStringContainsString('recomputed line VAT 38.000 != stored line_tax_amount 38.500', $output);
        $this->assertStringContainsString('NOT backfilled', $output);
    }

    public function test_supplier_invoice_leg_skips_a_lineless_supplier_invoice(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted, withLine: false);

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(0, DocumentTaxDetail::where('document_id', $invoice->id)->count());
        $this->assertStringContainsString('no document lines', $output);
    }

    public function test_supplier_invoice_leg_refuses_a_filed_period_document_without_include_filed(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);
        $this->createVatPeriod(VatPeriodStatus::Filed);

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertSame(0, DocumentTaxDetail::where('document_id', $invoice->id)->count());
        $this->assertStringContainsString('ALREADY-FILED VAT period -- backfill refused', $output);
        $this->assertStringContainsString('FILED-PERIOD IMPACT (supplier-invoice leg)', $output);
    }

    public function test_supplier_invoice_leg_apply_with_include_filed_writes_into_a_filed_period(): void
    {
        $invoice = $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);
        $this->createVatPeriod(VatPeriodStatus::Filed);

        $this->runBackfillAndCaptureOutput(['--apply' => true, '--include-filed' => true]);

        $this->assertSame(1, DocumentTaxDetail::where('document_id', $invoice->id)->count());
    }

    public function test_supplier_invoice_leg_reports_a_reopen_and_reclose_instruction_for_a_closed_period(): void
    {
        $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);
        $this->createVatPeriod(VatPeriodStatus::Closed);

        $output = $this->runBackfillAndCaptureOutput(['--apply' => true]);

        $this->assertStringContainsString('CLOSED-PERIOD IMPACT (supplier-invoice leg)', $output);
        $this->assertStringContainsString('reopen this period then re-close it', $output);
    }

    public function test_supplier_invoice_leg_census_reports_the_whole_population(): void
    {
        $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Posted);
        $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Draft);
        $this->createUnsnapshottedSupplierInvoice(DocumentStatus::Cancelled);

        $output = $this->runBackfillAndCaptureOutput([]);

        $this->assertStringContainsString('Population: 3 supplier invoice(s) total', $output);
        $this->assertStringContainsString('1 posted/paid without a snapshot (IN SCOPE)', $output);
        $this->assertStringContainsString('0 posted/paid already snapshotted', $output);
    }

    /**
     * A supplier invoice as `CreateSupplierInvoiceService` + the pre-B-19
     * `SupplierInvoicePostingService::post()` left it: real lines carrying an
     * explicit tax_rate, a coherent header, and ZERO document_tax_details rows.
     */
    private function createUnsnapshottedSupplierInvoice(
        DocumentStatus $status,
        string $lineTaxAmount = '38.000',
        string $taxAmount = '38.000',
        string $total = '238.000',
        bool $withLine = true,
    ): Document {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Backfill Test Supplier '.uniqid(),
            'type' => PartnerType::Supplier,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => $status,
            'document_number' => 'SI-LEGACY-'.strtoupper(substr(uniqid(), -6)),
            'document_date' => '2026-01-10',
            'currency' => 'TND',
            'subtotal' => $withLine ? '200.000' : '0.000',
            'line_tax_amount' => $withLine ? $lineTaxAmount : '0.000',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => $withLine ? $taxAmount : '0.000',
            'total' => $withLine ? $total : '0.000',
        ]);

        if ($withLine) {
            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => 1,
                'description' => 'Purchased item',
                'quantity' => '1',
                'unit_price' => '200.000',
                'tax_rate' => '19.00',
                'tax_amount' => $lineTaxAmount,
                'tax_recoverable' => true,
                'recoverable_tax_amount' => $lineTaxAmount,
                'non_recoverable_tax_amount' => '0.000',
                'line_total' => '200.000',
            ]);
        }

        return $document;
    }

    private function createVatPeriod(VatPeriodStatus $status): VatPeriod
    {
        return VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => 'MONTHLY',
            'label' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => $status,
            'closed_at' => now(),
        ]);
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

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runBackfillAndCaptureOutput(array $parameters): string
    {
        Artisan::call('vat:backfill-tax-details', $parameters);

        return Artisan::output();
    }
}
