<?php

declare(strict_types=1);

namespace Tests\Feature\Document\Types;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete', 'invoices.post', 'invoices.cancel', 'credit-notes.create']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);

        // Seed chart of accounts based on company country
        $this->seedChartOfAccounts();

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner@example.com',
        ]);
    }

    /**
     * Seed the appropriate chart of accounts based on company country code.
     */
    private function seedChartOfAccounts(): void
    {
        $seederClass = match ($this->company->country_code) {
            'FR' => FranceChartOfAccountsSeeder::class,
            'TN' => TunisiaChartOfAccountsSeeder::class,
            default => FranceChartOfAccountsSeeder::class, // Default to France
        };

        $seeder = new $seederClass();
        $seeder->run($this->company->id, $this->tenant->id);
    }

    public function test_invoice_can_be_created(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'due_date' => '2025-02-15',
            'lines' => [
                [
                    'description' => 'Test service',
                    'quantity' => '1.00',
                    'unit_price' => '500.00',
                    'tax_rate' => '20.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals('invoice', $response->json('data.type'));
        $this->assertEquals('2025-02-15', $response->json('data.due_date'));
    }

    public function test_invoice_can_be_posted(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'due_date' => '2025-02-15',
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/post");

        $response->assertStatus(200);
        $this->assertEquals('posted', $response->json('data.status'));

        // Verify NF525 compliance: fiscal hash should be populated
        $this->assertNotNull($response->json('meta.fiscal_hash'));
        $this->assertEquals(1, $response->json('meta.chain_sequence'));

        // Verify database state
        $invoice->refresh();
        $this->assertNotNull($invoice->fiscal_hash);
        $this->assertNull($invoice->previous_hash); // First in chain
        $this->assertEquals(1, $invoice->chain_sequence);
    }

    public function test_draft_invoice_cannot_be_posted(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/post");

        $response->assertStatus(422);
        $this->assertEquals('INVOICE_NOT_CONFIRMED', $response->json('error.code'));
    }

    public function test_posted_invoice_cannot_be_modified(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/v1/invoices/{$invoice->id}", [
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('DOCUMENT_NOT_EDITABLE', $response->json('error.code'));
    }

    public function test_confirmed_invoice_can_be_cancelled(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'total' => '100.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
            'reason' => 'Customer requested cancellation',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.status'));
    }

    public function test_posted_invoice_cannot_be_cancelled(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0002',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'total' => '100.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
            'reason' => 'Customer requested cancellation',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot cancel posted invoice', $response->json('error'));
    }

    public function test_posted_invoice_can_be_credited(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);

        $invoice->lines()->create([
            'line_number' => 1,
            'description' => 'Service A',
            'quantity' => '1.00',
            'unit_price' => '500.00',
            'tax_rate' => '20.00',
            'line_total' => '500.00',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/create-credit-note");

        $response->assertStatus(201);
        $this->assertEquals('credit_note', $response->json('data.type'));
        $this->assertEquals($invoice->id, $response->json('data.source_document_id'));
    }

    public function test_invoice_calculates_totals_with_tax(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'partner_id' => $this->partner->id,
            'document_date' => '2025-01-15',
            'lines' => [
                [
                    'description' => 'Service A',
                    'quantity' => '2.00',
                    'unit_price' => '100.00',
                    'tax_rate' => '20.00',
                ],
                [
                    'description' => 'Service B',
                    'quantity' => '1.00',
                    'unit_price' => '50.00',
                    'tax_rate' => '10.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        // Subtotal: 2*100 + 1*50 = 250
        $this->assertEquals('250.00', $response->json('data.subtotal'));
        // Tax: 200*0.20 + 50*0.10 = 40 + 5 = 45
        $this->assertEquals('45.00', $response->json('data.tax_amount'));
        // Total: 250 + 45 = 295
        $this->assertEquals('295.00', $response->json('data.total'));
    }

    public function test_only_posted_invoice_can_be_credited(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/create-credit-note");

        $response->assertStatus(422);
        $this->assertEquals('INVOICE_NOT_POSTED', $response->json('error.code'));
    }

    public function test_invoice_can_be_confirmed(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2025-0001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        $response->assertStatus(200);
        $this->assertEquals('confirmed', $response->json('data.status'));
    }
}
