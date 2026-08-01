<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: a DRAFT invoice's totals must already include document-level
 * taxes (applies_to = DOCUMENT_TOTAL — the Tunisian 1.000 TND stamp duty),
 * i.e. they must equal the totals the same invoice reports after confirm().
 *
 * Before the fix, InvoiceController::store() computed subtotal/tax_amount/total
 * with a hand-rolled per-line loop that can only see LINE_ITEMS taxes; the
 * stamp was applied for the first time at POST /invoices/{id}/confirm, so
 * every Draft invoice understated its total by exactly the stamp amount
 * (money-campaign W1b MTP-DOC-01/03/04, MTP-TAX-01, MTP-DSC-01).
 */
class InvoiceDraftDocumentTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'TN Draft Tax Tenant',
            'slug' => 'tn-draft-tax-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PharmaBio Test',
            'legal_name' => 'PharmaBio Test SARL',
            'tax_id' => 'TN-TAX-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Real TN rate table: 19%/7%/0% LINE_ITEMS VAT + the 1.000 TND
        // STAMP_TAX_INVOICE row (applies_to = DOCUMENT_TOTAL, TAX_INVOICE).
        $this->seed(TunisiaTaxConfigurationSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'email' => 'owner@pharmabio.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Draft Tax Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $lines
     * @return array<string, mixed>
     */
    private function createInvoice(array $lines): array
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'TND',
                'lines' => $lines,
            ]);

        $response->assertCreated();

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmInvoice(string $invoiceId): array
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoiceId}/confirm");

        $response->assertOk();

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    public function test_draft_invoice_totals_include_stamp_duty_and_equal_confirmed_totals(): void
    {
        // L1: 10 × 12.500 = 125.000 ; L2: 4 × 25.000 = 100.000
        // subtotal 225.000 ; line VAT 19% = 42.750 ; + 1.000 stamp = 43.750
        $draft = $this->createInvoice([
            ['description' => 'Line A', 'quantity' => '10.0000', 'unit_price' => '12.500', 'tax_rate' => '19.00'],
            ['description' => 'Line B', 'quantity' => '4.0000', 'unit_price' => '25.000', 'tax_rate' => '19.00'],
        ]);

        $this->assertSame('draft', $draft['status']);
        $this->assertSame('225.000', $draft['subtotal']);
        $this->assertSame('43.750', $draft['tax_amount'], 'Draft tax must include the 1.000 TND stamp duty');
        $this->assertSame('268.750', $draft['total']);

        $confirmed = $this->confirmInvoice((string) $draft['id']);

        $this->assertSame($draft['subtotal'], $confirmed['subtotal']);
        $this->assertSame($draft['tax_amount'], $confirmed['tax_amount'], 'Draft and confirmed tax must match');
        $this->assertSame($draft['total'], $confirmed['total'], 'Draft and confirmed total must match');
    }

    public function test_draft_totals_truncate_rather_than_round_and_still_carry_the_stamp(): void
    {
        // 3 × 33.333 = 99.999 ; VAT 99.999 × 0.19 = 18.99981 -> truncate 18.999
        // + 1.000 stamp = 19.999 ; total 119.998
        $draft = $this->createInvoice([
            ['description' => 'Truncation vector', 'quantity' => '3.0000', 'unit_price' => '33.333', 'tax_rate' => '19.00'],
        ]);

        $this->assertSame('99.999', $draft['subtotal']);
        $this->assertSame('19.999', $draft['tax_amount']);
        $this->assertSame('119.998', $draft['total']);

        $confirmed = $this->confirmInvoice((string) $draft['id']);
        $this->assertSame($draft['tax_amount'], $confirmed['tax_amount']);
        $this->assertSame($draft['total'], $confirmed['total']);
    }

    public function test_zero_rated_draft_invoice_still_carries_the_stamp(): void
    {
        // 1 × 50.000 @ 0% VAT -> tax is the stamp only (1.000), total 51.000
        $draft = $this->createInvoice([
            ['description' => 'Exempt line', 'quantity' => '1.0000', 'unit_price' => '50.000', 'tax_rate' => '0.00'],
        ]);

        $this->assertSame('50.000', $draft['subtotal']);
        $this->assertSame('1.000', $draft['tax_amount']);
        $this->assertSame('51.000', $draft['total']);

        $confirmed = $this->confirmInvoice((string) $draft['id']);
        $this->assertSame($draft['tax_amount'], $confirmed['tax_amount']);
        $this->assertSame($draft['total'], $confirmed['total']);
    }

    public function test_editing_a_draft_keeps_the_stamp_in_the_recomputed_totals(): void
    {
        $draft = $this->createInvoice([
            ['description' => 'Line A', 'quantity' => '2.0000', 'unit_price' => '50.000', 'tax_rate' => '19.00'],
        ]);
        $this->assertSame('100.000', $draft['subtotal']);
        $this->assertSame('20.000', $draft['tax_amount']); // 19.000 VAT + 1.000 stamp

        // Bump the quantity 2 -> 5 through the PATCH recompute path.
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/invoices/'.$draft['id'], [
                'lines' => [
                    ['description' => 'Line A', 'quantity' => '5.0000', 'unit_price' => '50.000', 'tax_rate' => '19.00'],
                ],
            ]);

        $response->assertOk();
        // 5 × 50.000 = 250.000 ; VAT 47.500 ; + 1.000 stamp = 48.500 ; total 298.500
        $response->assertJsonPath('data.subtotal', '250.000');
        $response->assertJsonPath('data.tax_amount', '48.500');
        $response->assertJsonPath('data.total', '298.500');

        $confirmed = $this->confirmInvoice((string) $draft['id']);
        $this->assertSame('48.500', $confirmed['tax_amount']);
        $this->assertSame('298.500', $confirmed['total']);
    }

    public function test_a_line_rate_with_no_matching_configuration_keeps_its_vat_in_the_draft(): void
    {
        // Guard against the regression the narrower fix avoids: an explicitly
        // supplied 13% rate has no TN LINE_ITEMS TaxConfiguration row, so the
        // tax service's line-item step would drop it entirely. The hand-rolled
        // per-line VAT must still be honoured (13.000), plus the stamp.
        $draft = $this->createInvoice([
            ['description' => 'Unconfigured rate', 'quantity' => '1.0000', 'unit_price' => '100.000', 'tax_rate' => '13.00'],
        ]);

        $this->assertSame('100.000', $draft['subtotal']);
        $this->assertSame('14.000', $draft['tax_amount'], '13.000 VAT + 1.000 stamp');
        $this->assertSame('114.000', $draft['total']);
    }
}
