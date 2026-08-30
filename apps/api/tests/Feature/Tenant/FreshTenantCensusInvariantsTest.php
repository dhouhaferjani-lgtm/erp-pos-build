<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Application\DTOs\DayOneInvariantResult;
use App\Modules\Tenant\Application\Services\DayOneCensus;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\CompanyPaymentRepositoryProvisionerInterface;
use Database\Seeders\PaymentRepositorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tenant\Concerns\BuildsFreshTenantCensusFixture;
use Tests\TestCase;

final class FreshTenantCensusInvariantsTest extends TestCase
{
    use BuildsFreshTenantCensusFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildFreshTenantCensusFixture();
    }

    #[Test]
    public function units_visible_min_19_is_true_for_both_companies(): void
    {
        $this->assertCensusPassed('units_visible_min_19');

        foreach ($this->companies() as $company) {
            $visible = DB::table('units')
                ->where('is_active', true)
                ->where(static function ($query) use ($company): void {
                    $query->whereNull('tenant_id')
                        ->orWhere('tenant_id', $company->tenant_id);
                })
                ->count();

            self::assertGreaterThanOrEqual(
                19,
                $visible,
                "{$company->name} would show fewer than 19 active units on day one.",
            );
            self::assertSame(
                0,
                DB::table('unit_categories')
                    ->where(static function ($query) use ($company): void {
                        $query->whereNull('tenant_id')
                            ->orWhere('tenant_id', $company->tenant_id);
                    })
                    ->whereNull('base_unit_id')
                    ->count(),
                "{$company->name} would show a unit category with no base unit.",
            );
        }
    }

    #[Test]
    public function tax_configurations_seeded_is_true_for_both_companies(): void
    {
        $this->assertCensusPassed('tax_configurations_seeded');

        foreach ($this->companies() as $company) {
            $countryRows = TaxConfiguration::query()
                ->where('country_code', $company->country_code)
                ->get();

            self::assertNotEmpty($countryRows, "{$company->name} would open with no tax configuration.");
            self::assertCount(
                1,
                $countryRows->where('is_default', true),
                "{$company->name} would have an ambiguous or missing default tax configuration.",
            );
            self::assertNotNull(
                $company->fresh()?->default_tax_configuration_id,
                "{$company->name} would have no selected default tax configuration.",
            );
        }
    }

    #[Test]
    public function required_purposes_tagged_is_true_for_both_companies(): void
    {
        $rows = $this->assertCensusPassed('required_purposes_tagged');

        foreach ($this->companies() as $company) {
            foreach (ProvisioningRequiredPurposesV1::requiredPurposes() as $purpose) {
                self::assertSame(
                    1,
                    DB::table('accounts')
                        ->where('company_id', $company->id)
                        ->where('system_purpose', $purpose->value)
                        ->where('is_active', true)
                        ->count(),
                    "{$company->name} cannot post day-one activity because {$purpose->value} does not resolve exactly once.",
                );
            }

            self::assertSame(
                1,
                DB::table('accounts')
                    ->where('company_id', $company->id)
                    ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable->value)
                    ->where('is_active', true)
                    ->count(),
                "{$company->name} is Tunisian but has no unique sales stamp-duty payable account.",
            );
        }

        foreach ($rows as $row) {
            self::assertStringContainsString('conditional=', $row->actual);
            self::assertStringContainsString('soft=', $row->actual);
        }
    }

    #[Test]
    public function refund_purposes_seeded_by_country_template_is_true_for_both_companies(): void
    {
        $this->assertCensusPassed('refund_purposes_seeded_by_country_template');

        foreach ($this->companies() as $company) {
            foreach ([SystemAccountPurpose::SalesReturn, SystemAccountPurpose::RefundWriteOff] as $purpose) {
                self::assertSame(
                    1,
                    DB::table('accounts')
                        ->where('company_id', $company->id)
                        ->where('system_purpose', $purpose->value)
                        ->where('is_active', true)
                        ->count(),
                    "{$company->name} would refuse its first refund because {$purpose->value} is absent.",
                );
            }
        }
    }

    #[Test]
    public function one_drawer_per_pos_location_and_one_safe_is_true_for_both_companies_and_locations(): void
    {
        $this->assertCensusPassed('one_drawer_per_pos_location_and_one_safe');

        foreach ($this->companies() as $company) {
            $posLocations = Location::query()
                ->where('company_id', $company->id)
                ->where('pos_enabled', true)
                ->where('is_active', true)
                ->get();

            self::assertNotEmpty($posLocations, "{$company->name} has no active POS location.");
            foreach ($posLocations as $location) {
                self::assertSame(
                    1,
                    PaymentRepository::query()
                        ->where('company_id', $company->id)
                        ->where('location_id', $location->id)
                        ->where('type', RepositoryType::CashRegister)
                        ->where('is_active', true)
                        ->whereNotNull('gl_account_id')
                        ->count(),
                    "{$location->name} would open without exactly one active GL-linked attributed drawer.",
                );
            }

            self::assertSame(
                1,
                PaymentRepository::query()
                    ->where('company_id', $company->id)
                    ->where('type', RepositoryType::Safe)
                    ->where('is_active', true)
                    ->whereNotNull('gl_account_id')
                    ->count(),
                "{$company->name} would open without exactly one active GL-linked safe.",
            );
        }
    }

    #[Test]
    public function repository_attribution_is_an_ordering_property_of_registration(): void
    {
        $company = Company::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Location Yet Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        (new PaymentRepositorySeeder(app(CompanyPaymentRepositoryProvisionerInterface::class)))->run($company);

        self::assertSame(
            2,
            PaymentRepository::query()
                ->where('company_id', $company->id)
                ->whereNull('location_id')
                ->count(),
            'Seeding before Main Location must expose the unattributed pre-N-12 shape; registration ordering is what prevents it.',
        );
    }

    #[Test]
    public function cash_tender_coherent_is_true_for_both_companies(): void
    {
        $this->assertCensusPassed('cash_tender_coherent');

        foreach ($this->companies() as $company) {
            self::assertGreaterThan(
                0,
                PaymentMethod::query()
                    ->where('company_id', $company->id)
                    ->where('is_active', true)
                    ->count(),
                "{$company->name} would show no active payment method.",
            );
            $flagged = PaymentMethod::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('is_cash_tender', true)
                ->get();
            self::assertCount(1, $flagged, "{$company->name} would have an ambiguous or missing cash tender.");
            self::assertSame('CASH', strtoupper($flagged->firstOrFail()->code));
            self::assertSame(
                1,
                PaymentMethod::query()
                    ->where('company_id', $company->id)
                    ->whereRaw('UPPER(code) = ?', ['CASH'])
                    ->count(),
                "{$company->name} would have another method interpreted as CASH by code readers.",
            );
        }
    }

    /**
     * I2-F2 CLOSED by lane G-4 (UnitResolver, RUL-4): a coded row resolves its
     * unit_id to that unit, while a blank row receives the company's fallback
     * unit (`pc`) and is flagged `unit_defaulted` on the import row.
     */
    #[Test]
    public function products_import_resolves_unit_ids_and_defaults_blank_units_i2_f2_closed(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('day-one-products.csv', implode("\n", [
            'name,sku,type,unit',
            'Coded Unit Product,I2-CODED,part,kg',
            'Blank Unit Product,I2-BLANK,part,',
        ]));

        $create = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Company-Id', $this->firstCompany->id)
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products'])
            ->assertCreated();
        $jobId = $create->json('data.id');
        self::assertIsString($jobId);

        $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Company-Id', $this->firstCompany->id)
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk();

        $coded = Product::query()->where('company_id', $this->firstCompany->id)->where('sku', 'I2-CODED')->firstOrFail();
        $blank = Product::query()->where('company_id', $this->firstCompany->id)->where('sku', 'I2-BLANK')->firstOrFail();
        self::assertSame('kg', $coded->unit, 'The importer must preserve the coded row unit varchar.');
        self::assertNotNull($coded->unit_id, 'I2-F2 closed (G-4): a coded imported unit resolves to unit_id.');
        self::assertSame('kg', DB::table('units')->where('id', $coded->unit_id)->value('code'), 'The coded row must resolve to the kg unit.');
        self::assertSame('pc', $blank->unit, 'RUL-4: a blank unit on create falls back to pc.');
        self::assertNotNull($blank->unit_id, 'I2-F2 closed (G-4): the blank imported unit receives the fallback unit_id.');
        self::assertSame('pc', DB::table('units')->where('id', $blank->unit_id)->value('code'), 'The blank row must resolve to the pc fallback unit.');
    }

    #[Test]
    public function no_numbered_drafts_is_true_before_and_after_real_draft_creation(): void
    {
        self::assertSame(0, $this->numberedDraftCount(), 'A fresh tenant must start with no numbered drafts.');
        $this->assertCensusPassed('no_numbered_drafts');

        $partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->firstCompany->id,
            'name' => 'Day One Draft Customer',
            'type' => 'customer',
        ]);
        $product = Product::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->firstCompany->id,
            'name' => 'Day One Draft Product',
            'sku' => 'I2-DRAFT',
            'type' => ProductType::Part,
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Company-Id', $this->firstCompany->id)
            ->postJson('/api/v1/documents/auto-save', [
                'type' => 'quote',
                'partner_id' => $partner->id,
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '10.000',
                ]],
            ])
            ->assertOk();
        $draftId = $response->json('draft_id');
        self::assertIsString($draftId);
        self::assertNull(Document::query()->findOrFail($draftId)->document_number);
        self::assertSame(0, $this->numberedDraftCount(), 'Saving a real draft must not spend a document number.');
        $this->assertCensusPassed('no_numbered_drafts');
    }

    #[Test]
    public function onboarding_checklist_consistent_is_true_for_both_companies(): void
    {
        $this->assertCensusPassed('onboarding_checklist_consistent');

        foreach ($this->companies() as $company) {
            $steps = collect(app(OnboardingChecklistService::class)->getStatus($company->id));
            foreach ([OnboardingStep::TaxConfig, OnboardingStep::PaymentMethods, OnboardingStep::PaymentRepositories] as $step) {
                $status = $steps->firstWhere('step', $step->value);
                self::assertIsArray($status);
                self::assertFalse($status['degraded'], "{$company->name} onboarding probe {$step->value} degraded.");
                self::assertTrue($status['completed'], "{$company->name} onboarding still shows {$step->value} incomplete.");
            }

            self::assertNotNull($company->fresh()?->default_tax_configuration_id);
            self::assertTrue(PaymentMethod::query()->where('company_id', $company->id)->where('is_active', true)->exists());
            self::assertTrue(PaymentRepository::query()
                ->where('company_id', $company->id)
                ->where('type', RepositoryType::CashRegister)
                ->where('is_active', true)
                ->exists());
        }
    }

    /** @return list<Company> */
    private function companies(): array
    {
        return [$this->firstCompany, $this->secondCompany];
    }

    /** @return list<DayOneInvariantResult> */
    private function assertCensusPassed(string $key): array
    {
        $companyIds = [$this->firstCompany->id, $this->secondCompany->id];
        $rows = array_values(array_filter(
            app(DayOneCensus::class)->inspect(),
            static fn (DayOneInvariantResult $result): bool => $result->key === $key,
        ));

        self::assertNotEmpty($rows, "The shared day-one census emitted no {$key} row.");
        foreach ($rows as $row) {
            self::assertContains($row->companyId, $companyIds, "{$key} leaked outside the two-company tenant scope.");
            self::assertTrue(
                $row->passed,
                "Operator-visible day-one drift: {$row->key} {$row->description}; expected {$row->expected}; actual {$row->actual}.",
            );
        }

        foreach ($companyIds as $companyId) {
            self::assertNotEmpty(
                array_filter($rows, static fn (DayOneInvariantResult $result): bool => $result->companyId === $companyId),
                "The shared day-one census emitted no {$key} row for company {$companyId}.",
            );
        }

        return $rows;
    }

    private function numberedDraftCount(): int
    {
        return Document::query()
            ->where('status', DocumentStatus::Draft)
            ->whereNotNull('document_number')
            ->count();
    }
}
