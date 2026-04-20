<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReceiptPrintType;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPrint;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the NF525 JET XML export service and related endpoints.
 */
class Nf525JetExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $cashierUser;

    private Terminal $terminal;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant NF525',
            'slug' => 'test-nf525-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company NF525',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin-nf525-'.Str::random(8).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier User',
            'email' => 'cashier-nf525-'.Str::random(8).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->cashierUser->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $this->cashierUser->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        $this->location = Location::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main Store',
            'type' => 'shop',
            'is_active' => true,
        ]);

        $this->terminal = Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'code' => 'POS01',
            'name' => 'Terminal 1',
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => 2026,
            'is_active' => true,
        ]);
    }

    public function test_export_jet_produces_valid_xml_with_root_element(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Create a receipt
        $this->createTestReceipt();

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $xml = $response->getContent();
        $this->assertNotEmpty($xml);

        // Parse XML to verify structure
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml), 'Export should produce valid XML');

        $root = $doc->documentElement;
        $this->assertNotNull($root);
        $this->assertEquals('JET', $root->tagName);

        // Verify expected sections exist
        $sections = ['Entete', 'Tickets', 'Annulations', 'Retours', 'Duplicatas',
            'RapportsZ', 'GrandsTotaux', 'MouvementsCaisse', 'EvenementsTechniques', 'EvenementsTerminal', 'ChainesHash'];
        foreach ($sections as $section) {
            $this->assertGreaterThan(0, $doc->getElementsByTagName($section)->length,
                "Section {$section} should exist in JET XML");
        }
    }

    public function test_empty_export_produces_valid_xml(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => '2020-01-01',
            'to' => '2020-12-31',
        ]);

        $response->assertStatus(200);

        $xml = $response->getContent();
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml), 'Empty export should produce valid XML');

        // Tickets section should exist but have count="0"
        $tickets = $doc->getElementsByTagName('Tickets')->item(0);
        $this->assertNotNull($tickets);
        $this->assertEquals('0', $tickets->getAttribute('count'));
    }

    public function test_export_jet_requires_compliance_permission(): void
    {
        $this->actingAs($this->cashierUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);

        $response->assertStatus(403);
    }

    public function test_verify_chains_returns_per_terminal_results(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/verify-chains', [
            'company_id' => $this->company->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'company_id',
                'terminals' => [
                    '*' => [
                        'terminal_id',
                        'terminal_code',
                        'terminal_name',
                        'receipt_chain' => ['is_valid', 'total_receipts', 'verified'],
                        'z_report_chain' => ['is_valid', 'total_reports', 'verified'],
                        'is_valid',
                    ],
                ],
                'all_chains_valid',
                'verified_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals($this->company->id, $data['company_id']);
        $this->assertCount(1, $data['terminals']);
        $this->assertEquals($this->terminal->id, $data['terminals'][0]['terminal_id']);
        $this->assertTrue($data['terminals'][0]['is_valid']);
    }

    public function test_verify_chains_requires_compliance_permission(): void
    {
        $this->actingAs($this->cashierUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/verify-chains', [
            'company_id' => $this->company->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_reprint_log_returns_paginated_data(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $receipt = $this->createTestReceipt();

        // Create a receipt print record
        ReceiptPrint::create([
            'receipt_id' => $receipt->id,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->adminUser->id,
            'print_type' => ReceiptPrintType::Original,
            'copy_number' => 1,
            'printed_at' => now(),
            'print_method' => PrintMethod::Thermal,
        ]);

        $response = $this->getJson('/api/v1/compliance/nf525/reprint-log?company_id='.$this->company->id);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_reprint_log_requires_compliance_permission(): void
    {
        $this->actingAs($this->cashierUser, 'sanctum');

        $response = $this->getJson('/api/v1/compliance/nf525/reprint-log?company_id='.$this->company->id);

        $response->assertStatus(403);
    }

    public function test_export_jet_validates_date_range(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => $this->company->id,
            'from' => '2026-12-31',
            'to' => '2026-01-01',
        ]);

        $response->assertStatus(422);
    }

    public function test_export_jet_validates_company_id_format(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->postJson('/api/v1/compliance/nf525/export-jet', [
            'company_id' => 'not-a-uuid',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ]);

        $response->assertStatus(422);
    }

    /**
     * Create a test receipt with lines and VAT details.
     */
    private function createTestReceipt(): Receipt
    {
        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => 'POS01-2026-00000001',
            'receipt_type' => ReceiptType::Sale,
            'chain_sequence' => 1,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', 'test-receipt-data'),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat-data'),
            'payment_methods_hash' => hash('sha256', 'payment-data'),
            'posted_at' => now(),
            'cashier_id' => $this->adminUser->id,
            'cashier_name' => $this->adminUser->name,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'discount_amount' => '0.00',
            'total' => '120.00',
            'currency' => 'EUR',
            'is_voided' => false,
        ]);

        ReceiptLine::create([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_code' => 'PROD001',
            'product_name' => 'Test Product',
            'quantity' => '2',
            'unit' => 'pc',
            'unit_price' => '50.00',
            'line_total' => '100.00',
            'tax_rate' => '20.00',
            'tax_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);

        ReceiptVatDetail::create([
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '100.00',
            'vat_amount' => '20.00',
            'gross_amount' => '120.00',
        ]);

        return $receipt;
    }
}
