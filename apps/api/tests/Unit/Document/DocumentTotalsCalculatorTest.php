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
use Tests\Traits\WithCurrencyScale;

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
    use WithCurrencyScale;

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
        return new DocumentTotalsCalculator(new TaxCalculationService($this->mockCurrencyScale()));
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

    public function test_line_discount_percent_reduces_subtotal_tax_and_total(): void
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

        $document = Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'discount_amount' => '0',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Discounted item',
            'quantity' => '2',
            'unit_price' => '100.000',
            'discount_percent' => '10.00',
            'tax_rate' => '19.00',
            'line_total' => '180.000',
        ]);

        $this->calculator()->recalculate($document->load('lines'));

        $document->refresh();
        // gross 200 − 10% = net 180
        $this->assertSame('180.000', $document->subtotal);
        // line tax = 19% of NET 180 = 34.200
        $this->assertSame('34.200', $document->tax_amount);
        $this->assertSame('214.200', $document->total);
    }

    public function test_line_discount_amount_reduces_subtotal_tax_and_total(): void
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

        $document = Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'discount_amount' => '0',
        ]);
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Flat-discounted item',
            'quantity' => '1',
            'unit_price' => '100.000',
            'discount_amount' => '25.000',
            'tax_rate' => '19.00',
            'line_total' => '75.000',
        ]);

        $this->calculator()->recalculate($document->load('lines'));

        $document->refresh();
        // gross 100 − 25 flat = net 75
        $this->assertSame('75.000', $document->subtotal);
        // line tax = 19% of NET 75 = 14.250
        $this->assertSame('14.250', $document->tax_amount);
        $this->assertSame('89.250', $document->total);
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

    public function test_recalculate_persists_line_tax_and_stamp_duty_columns(): void
    {
        // `line_tax_amount`/`stamp_duty_amount` ARE in Document::$fillable
        // (Document.php's $fillable array) and ARE written by recalculate()'s
        // update([...]) call -- a previous version of this test asserted the
        // opposite ("non-fillable, silently dropped"), which only APPEARED to
        // hold because with no TaxConfiguration seeded at all, the pre-fix
        // TaxCalculationService wrote line_tax_amount=0 for an explicit 19%
        // rate (STEP 1 contributed nothing for an unmatched rate), and
        // PHPUnit's assertEquals(null, 0) on the untouched-vs-written stdClass
        // pair passed by coincidence (PHP's null == 0). Documents-defects
        // lane defect 3 fix: an explicit line rate is now honoured even with
        // no matching TaxConfiguration row, so line_tax_amount is genuinely
        // written (19.000, not silently dropped OR silently zeroed).
        // Compared via raw DB reads so the assertion is independent of
        // Eloquent in-memory state.
        $document = $this->makeDocument([
            ['quantity' => '1', 'unit_price' => '100.000', 'tax_rate' => '19.00'],
        ]);

        $before = DB::table('documents')->where('id', $document->id)
            ->first(['line_tax_amount', 'stamp_duty_amount']);
        $this->assertNull($before->line_tax_amount);

        $this->calculator()->recalculate($document);

        $after = DB::table('documents')->where('id', $document->id)
            ->first(['line_tax_amount', 'stamp_duty_amount']);

        // Raw DB read (not through Eloquent's decimal cast) -- SQLite returns
        // the numeric column as a native int/float, so compare numerically.
        $this->assertEquals(19.0, $after->line_tax_amount);
        // No TaxConfiguration seeded at all in this test -> no stamp duty to apply.
        $this->assertEquals(0.0, $after->stamp_duty_amount);
    }
}
