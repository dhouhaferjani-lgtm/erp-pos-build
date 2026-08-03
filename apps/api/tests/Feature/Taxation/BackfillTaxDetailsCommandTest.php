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
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
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
