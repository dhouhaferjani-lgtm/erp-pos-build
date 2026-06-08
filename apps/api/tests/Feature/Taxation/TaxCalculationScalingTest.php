<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3.7 — TaxCalculationService scale propagation.
 *
 * The old line-item tax loop computed each line's tax base and tax amount at a
 * hard-coded scale 3 and summed the already-rounded per-line tax. With many
 * sub-boundary fractional lines the per-line truncation discarded almost the
 * entire tax: 1000 lines of qty=0.9999 × price=0.0019 TND @ 19% each truncate
 * to a 0.000 line tax, so the old impl reported a tax total of 0.000.
 *
 * The fix accumulates the per-line tax at a scale()+1 intermediate (so the 4th
 * quantity decimal survives the multiply) and rounds the per-rate tax total
 * ONCE at the currency boundary via CurrencyScale::bcformat.
 *
 * Gold case (TND, scale 3, 1000 lines, qty=0.9999, price=0.0019, rate 19%):
 *   exact mathematical tax total = 0.3609639
 *   OLD (per-line round @3)       = 0.000  (catastrophic truncation)
 *   NEW (accumulate @4, round @3) = 0.300  (CLOSER to the true 0.3609639)
 */
class TaxCalculationScalingTest extends TestCase
{
    use RefreshDatabase;

    private TaxCalculationService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::factory()->create();

        // TND company → resolver returns scale 3.
        $this->company = Company::factory()->for($this->tenant)->create([
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        // Bind company context so the injected resolver resolves TND scale 3.
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->service = app(TaxCalculationService::class);
    }

    /** @test */
    public function summed_line_tax_is_closer_to_true_total_than_old_scale_three_impl(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
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
            'applicable_document_types' => [],
            'is_active' => true,
            'is_stamp_duty' => false,
        ]);

        $document = Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'currency' => 'TND',
        ]);

        // 1000 sub-boundary fractional lines.
        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'document_id' => $document->id,
                'line_number' => $i,
                'description' => 'Frac line '.$i,
                'quantity' => '0.9999',
                'unit_price' => '0.0019',
                'tax_rate' => '19.00',
                'line_total' => '0.002',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DocumentLine::insert($rows);

        $document->load('lines');

        $result = $this->service->calculateDocumentTaxes($document);

        // True mathematical tax total = 1000 * 0.9999 * 0.0019 * 0.19 = 0.3609639.
        // Old scale-3 per-line truncation produced 0.000; the scale+1
        // accumulation rounds once to 0.300 — far closer to truth.
        $exact = '0.3609639';

        $this->assertSame(
            '0.300',
            $result->lineItemsTaxTotal,
            'Line-item tax must accumulate at scale+1 and round once; got the old truncated value',
        );

        // Distance-to-truth check: new error must be strictly smaller than the
        // old impl error (|0.300 - exact| < |0.000 - exact|).
        $newError = ltrim(bcsub($exact, $result->lineItemsTaxTotal, 7), '-');
        $oldError = $exact; // |0.000 - 0.3609639|
        $this->assertSame(
            1,
            bccomp($oldError, $newError, 7),
            'New tax total must be closer to the true mathematical total than the old scale-3 impl',
        );
    }
}
