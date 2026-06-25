<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Test Z-Report PDF generation
 *
 * Verifies that Z-Report PDF generation works correctly and includes all
 * required data: sales summary, cash summary, VAT breakdown, payment methods,
 * and fiscal hash chain information.
 */
class ZReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private ReportGenerationService $reportService;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportService = app(ReportGenerationService::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->createTestData();
    }

    /** @test */
    public function it_generates_z_report_pdf(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();

        // Act
        $pdf = $this->reportService->generatePdf($zReport);
        $content = $pdf->output();

        // Assert
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('%PDF', $content);
    }

    /** @test */
    public function it_generates_correct_z_report_filename(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();

        // Act
        $filename = $this->reportService->getZReportFilename($zReport);

        // Assert
        $this->assertSame('z-report-Z0001.pdf', $filename);
    }

    /** @test */
    public function it_renders_z_report_html_with_company_header(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => $zReport->report_data['vat_breakdown'] ?? [],
            'paymentMethods' => $zReport->report_data['payment_methods'] ?? [],
            'averageTicket' => '59.50',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Company header is present
        $this->assertStringContainsString('Test Garage', $html);
        $this->assertStringContainsString('FR12345678901', $html);
        $this->assertStringContainsString('123 Test Street', $html);
        $this->assertStringContainsString('75001', $html);
        $this->assertStringContainsString('Paris', $html);
    }

    /** @test */
    public function it_renders_branch_tax_id_with_localized_country_label(): void
    {
        // Arrange: Tunisian multi-branch tenant — the establishment (location)
        // carries its own Matricule Fiscal that must appear instead of the
        // company-level one, labelled per Tunisian convention.
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'tax_id_label' => 'Matricule Fiscal',
        ]);
        $this->company->update(['country_code' => 'TN', 'tax_id' => 'COMPANY-MF-0000']);
        $this->location->update(['address_country' => 'TN', 'tax_id' => 'BRANCH-MF-1234']);

        $zReport = $this->createTestZReport();

        // Act: render via the service so the resolution wiring is exercised
        $html = view('pos.z-report', $this->reportService->viewDataFor($zReport))->render();

        // Assert: branch matricule + Tunisian label, never the company matricule
        $this->assertStringContainsString('BRANCH-MF-1234', $html);
        $this->assertStringContainsString('Matricule Fiscal', $html);
        $this->assertStringNotContainsString('COMPANY-MF-0000', $html);
    }

    /** @test */
    public function it_falls_back_to_company_tax_id_when_branch_has_none(): void
    {
        // Arrange: location has no override → company tax id is shown
        $this->company->update(['tax_id' => 'COMPANY-ONLY-TAX']);
        $this->location->update(['tax_id' => null]);

        $zReport = $this->createTestZReport();

        // Act
        $html = view('pos.z-report', $this->reportService->viewDataFor($zReport))->render();

        // Assert
        $this->assertStringContainsString('COMPANY-ONLY-TAX', $html);
    }

    /** @test */
    public function it_renders_z_report_html_with_sales_summary(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => $zReport->report_data['vat_breakdown'] ?? [],
            'paymentMethods' => $zReport->report_data['payment_methods'] ?? [],
            'averageTicket' => '59.50',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Sales summary data is present
        $this->assertStringContainsString('119.00 EUR', $html); // gross sales
        $this->assertStringContainsString('100.00 EUR', $html); // net sales
        $this->assertStringContainsString('19.00 EUR', $html);  // tax amount
    }

    /** @test */
    public function it_renders_z_report_html_with_fiscal_hash(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => [],
            'paymentMethods' => [],
            'averageTicket' => '0.00',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Fiscal hash is present
        $this->assertStringContainsString(substr($zReport->fiscal_hash, 0, 32), $html);
        $this->assertStringContainsString('Z0001', $html);
    }

    /** @test */
    public function it_renders_genesis_indicator_for_first_z_report(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => [],
            'paymentMethods' => [],
            'averageTicket' => '0.00',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Genesis indicator present (first Z report has no previous hash)
        $this->assertTrue($zReport->isFirstZReport());
        // The "previous_hash" section should not be rendered
        $this->assertStringNotContainsString('previous_hash', $html);
    }

    /** @test */
    public function it_downloads_z_report_pdf_via_api(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo('pos.view_reports');

        // Act
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->get("/api/v1/pos/reports/z/{$zReport->z_number}/pdf?terminal_id={$this->terminal->id}");

        // Assert
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /** @test */
    public function it_returns_403_for_z_report_pdf_of_other_company(): void
    {
        // Arrange
        $zReport = $this->createTestZReport();

        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $otherUser->givePermissionTo('pos.view_reports');

        // Act
        $response = $this->actingAs($otherUser)
            ->withHeaders(['X-Company-Id' => $otherCompany->id])
            ->get("/api/v1/pos/reports/z/{$zReport->z_number}/pdf?terminal_id={$this->terminal->id}");

        // Assert
        $response->assertForbidden();
    }

    /** @test */
    public function it_renders_vat_breakdown_table(): void
    {
        // Arrange
        $reportData = $this->buildReportData();
        $reportData['vat_breakdown'] = [
            ['rate' => '19.00', 'net' => '100.00', 'vat' => '19.00', 'gross' => '119.00'],
            ['rate' => '5.50', 'net' => '200.00', 'vat' => '11.00', 'gross' => '211.00'],
        ];

        $zReport = $this->createTestZReport($reportData);
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => $zReport->report_data['vat_breakdown'],
            'paymentMethods' => [],
            'averageTicket' => '0.00',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Both VAT rates are present
        $this->assertStringContainsString('19.00', $html);
        $this->assertStringContainsString('5.50', $html);
        $this->assertStringContainsString('211.00 EUR', $html);
    }

    /** @test */
    public function it_renders_payment_methods_table(): void
    {
        // Arrange
        $reportData = $this->buildReportData();
        $reportData['payment_methods'] = [
            ['type' => 'Cash', 'count' => 5, 'amount' => '250.00'],
            ['type' => 'Card', 'count' => 3, 'amount' => '180.00'],
        ];

        $zReport = $this->createTestZReport($reportData);
        $zReport->load(['terminal', 'generatedBy']);

        // Act
        $html = view('pos.z-report', [
            'zReport' => $zReport,
            'company' => $this->company,
            'terminal' => $zReport->terminal,
            'generatedByName' => $zReport->generatedBy?->name,
            'reportData' => $zReport->report_data,
            'vatBreakdown' => [],
            'paymentMethods' => $zReport->report_data['payment_methods'],
            'averageTicket' => '0.00',
            'hasVariance' => false,
            'locale' => 'en',
            'currency' => 'EUR',
            'formatMoney' => fn (string|float|null $amount) => number_format((float) ($amount ?? 0), 2).' EUR',
            'formatDateTime' => fn ($date) => '01/15/2026 18:00',
            'formatNumber' => fn (string|float|null $number, int $decimals = 2) => number_format((float) ($number ?? 0), $decimals),
        ])->render();

        // Assert: Payment methods are present
        $this->assertStringContainsString('Cash', $html);
        $this->assertStringContainsString('Card', $html);
        $this->assertStringContainsString('250.00 EUR', $html);
        $this->assertStringContainsString('180.00 EUR', $html);
    }

    /**
     * Build default report data array.
     *
     * @return array<string, mixed>
     */
    private function buildReportData(): array
    {
        return [
            'sales_count' => 2,
            'gross_sales' => '119.00',
            'net_sales' => '100.00',
            'tax_amount' => '19.00',
            'refunds_count' => 0,
            'refunds_amount' => '0.00',
            'voided_count' => 0,
            'voided_amount' => '0.00',
            'opening_cash' => '100.00',
            'expected_cash' => '219.00',
            'actual_cash' => '219.00',
            'variance' => '0.00',
            'vat_breakdown' => [],
            'payment_methods' => [],
        ];
    }

    /**
     * Create shared test data (tenant, company, terminal, user).
     */
    private function createTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Garage',
            'tax_id' => 'FR12345678901',
            'address_street' => '123 Test Street',
            'address_city' => 'Paris',
            'address_postal_code' => '75001',
            'phone' => '+33 1 23 45 67 89',
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Location',
            'code' => 'LOC01',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS01',
            'name' => 'Terminal 1',
            'device_type' => 'tablet',
            'genesis_seed' => bin2hex(random_bytes(32)),
            'is_active' => true,
            'current_year' => 2026,
            'current_sequence' => 0,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Cashier',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
    }

    /**
     * Create a test Z report with all required data.
     *
     * @param  array<string, mixed>|null  $reportData  Optional custom report data
     */
    private function createTestZReport(?array $reportData = null): ZReport
    {
        $shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'opened_at' => now()->subHours(8),
            'opening_cash' => '100.00',
            'status' => 'CLOSED',
        ]);

        $data = $reportData ?? $this->buildReportData();

        return ZReport::create([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $shift->id,
            'z_number' => 1,
            'fiscal_hash' => hash('sha256', 'test-z-report-1'),
            'previous_z_hash' => null,
            'report_data' => $data,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ]);
    }
}
