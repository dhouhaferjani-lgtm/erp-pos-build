<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentTotalsCalculator;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Behaviour-preservation coverage for document totals recalculation (M3.2 / T3).
 *
 * Document totals recalculation used to live on the Document model as
 * `Document::recalculateTotals()`, which resolved TaxCalculationService via
 * the `app()` service-locator helper — a CLAUDE.md violation (no container
 * lookup in domain code; constructor injection only).
 *
 * The orchestration moved to DocumentTotalsCalculator, a domain service with
 * TaxCalculationService constructor-injected. The model keeps responsibility
 * for its own state (the service writes through `$document->update()`), it
 * just no longer reaches into the container.
 *
 * These assertions are the before/after proof: they pinned the exact totals
 * produced by the old `Document::recalculateTotals()` and must hold byte-for-
 * byte against `DocumentTotalsCalculator::recalculate()`.
 */
class DocumentTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['country_code' => 'TN']);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    private function calculator(): DocumentTotalsCalculator
    {
        return new DocumentTotalsCalculator(new TaxCalculationService);
    }

    /**
     * @param  list<array{quantity: string, unit_price: string, tax_rate: string}>  $lines
     */
    private function makeDocument(array $lines): Document
    {
        $document = Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'discount_amount' => '0',
        ]);

        $lineNumber = 1;
        foreach ($lines as $line) {
            $lineSubtotal = bcmul($line['quantity'], $line['unit_price'], 3);
            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => $lineNumber++,
                'description' => 'Item '.$lineNumber,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'line_total' => $lineSubtotal,
            ]);
        }

        return $document->load('lines');
    }

    public function test_recalculates_subtotal_line_tax_and_stamp_duty(): void
    {
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'TVA 19%',
            'code' => 'TVA_19',
            'tax_type' => 'PERCENTAGE',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);
        TaxConfiguration::create([
            'country_code' => 'TN',
            'name' => 'Stamp Duty',
            'code' => 'STAMP_1',
            'tax_type' => 'FIXED_AMOUNT',
            'fixed_amount' => '1.000',
            'applies_to' => 'DOCUMENT_TOTAL',
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => true,
        ]);

        $document = $this->makeDocument([
            ['quantity' => '1', 'unit_price' => '100.000', 'tax_rate' => '19.00'],
            ['quantity' => '2', 'unit_price' => '50.000', 'tax_rate' => '19.00'],
        ]);

        $this->calculator()->recalculate($document);

        $document->refresh();
        // subtotal = 1*100 + 2*50 = 200.000
        $this->assertSame('200.000', $document->subtotal);
        // total tax = line tax (19% of 200 = 38.000) + stamp duty (1.000) = 39.000
        $this->assertSame('39.000', $document->tax_amount);
        // total = 200 + 39 = 239.000
        $this->assertSame('239.000', $document->total);
    }

    public function test_recalculates_with_no_applicable_taxes(): void
    {
        $document = $this->makeDocument([
            ['quantity' => '3', 'unit_price' => '12.500', 'tax_rate' => '0'],
        ]);

        $this->calculator()->recalculate($document);

        $document->refresh();
        $this->assertSame('37.500', $document->subtotal);
        $this->assertSame('0.000', $document->tax_amount);
        $this->assertSame('37.500', $document->total);
    }

    public function test_stored_scale_is_normalised_to_the_column_definition(): void
    {
        $document = $this->makeDocument([
            ['quantity' => '1', 'unit_price' => '10.00', 'tax_rate' => '0'],
        ]);

        // Passing scale 2 does not change the persisted representation — the
        // subtotal/total columns are decimal:3 and normalise to 3 dp.
        $this->calculator()->recalculate($document, 2);

        $document->refresh();
        $this->assertSame('10.000', $document->subtotal);
        $this->assertSame('10.000', $document->total);
    }

    public function test_recalculate_leaves_non_fillable_tax_columns_untouched(): void
    {
        // PRE-EXISTING BEHAVIOUR (preserved by this refactor, NOT fixed):
        // line_tax_amount and stamp_duty_amount are NOT in Document::$fillable,
        // so the update([...]) call inside recalculation silently drops them.
        // This is a latent bug noted as a follow-up; the refactor must keep
        // the behaviour byte-identical. Compared via raw DB reads so the
        // assertion is independent of Eloquent in-memory state.
        $document = $this->makeDocument([
            ['quantity' => '1', 'unit_price' => '100.000', 'tax_rate' => '19.00'],
        ]);

        $before = DB::table('documents')->where('id', $document->id)
            ->first(['line_tax_amount', 'stamp_duty_amount']);

        $this->calculator()->recalculate($document);

        $after = DB::table('documents')->where('id', $document->id)
            ->first(['line_tax_amount', 'stamp_duty_amount']);

        $this->assertEquals($before, $after);
    }
}
