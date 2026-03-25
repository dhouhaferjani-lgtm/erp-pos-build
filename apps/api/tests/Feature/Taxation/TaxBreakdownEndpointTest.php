<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\TunisiaStampDutySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxBreakdownEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Company $tunisianCompany;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed countries first (required for foreign key constraint)
        $this->seed(\Database\Seeders\CountriesSeeder::class);

        // Seed permissions (required for authorization)
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'status' => 'active',
            'plan' => 'professional',
        ]);

        // Set permissions team to tenant (required for multi-tenant permissions)
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        // Create Tunisian company
        $this->tunisianCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tunisian Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        // Create user
        $this->user = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        // Create company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->tunisianCompany->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        // Grant permissions
        $this->user->givePermissionTo('documents.view');

        // Seed Tunisia tax configurations (VAT + stamp duties)
        $this->seed(\Database\Seeders\TunisiaTaxConfigurationSeeder::class);

        // Create a dummy partner for documents
        $this->partner = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'type' => 'customer',
            'code' => 'CUST001',
            'name' => 'Test Customer',
        ]);
    }

    public function test_it_returns_tax_breakdown_for_invoice(): void
    {
        // Arrange: Create a Tunisian posted invoice
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-TN-001',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '120.000',
        ]);

        // Add invoice line with Tunisian VAT (19%)
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'document_id' => $invoice->id,
            'description' => 'Product A',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
            'line_number' => 1,
        ]);

        // Act: Call the tax breakdown endpoint
        Sanctum::actingAs($this->user);
        $response = $this->withHeaders([
            'X-Company-ID' => $this->tunisianCompany->id,
        ])->getJson("/api/v1/documents/{$invoice->id}/tax-breakdown");

        // Assert: Response structure and values
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'subtotal',
                    'discount',
                    'line_tax_amount',
                    'stamp_duty_amount',
                    'total_tax_amount',
                    'total',
                    'tax_details',
                ],
            ])
            ->assertJson([
                'data' => [
                    'subtotal' => '100.00',
                    'discount' => '0.00',
                    'line_tax_amount' => '19.00',
                    'stamp_duty_amount' => '1.000',
                    'total_tax_amount' => '20.00',
                ],
            ]);

        // Verify tax_details array contains both VAT and stamp duty
        $taxDetails = $response->json('data.tax_details');
        $this->assertCount(2, $taxDetails, 'Should have 2 tax details (VAT + stamp duty)');
        $this->assertEquals('TVA 19%', $taxDetails[0]['tax_name'] ?? '', 'First detail should be VAT');
        $this->assertEquals('Timbre Fiscal - Facture', $taxDetails[1]['tax_name'] ?? '', 'Second detail should be stamp duty');
        $this->assertTrue($taxDetails[1]['is_stamp_duty'] ?? false, 'Second detail should be marked as stamp duty');
    }

    public function test_it_requires_authentication(): void
    {
        // Arrange: Create a document
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tunisianCompany->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-TN-002',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '120.000',
        ]);

        // Act: Call endpoint without authentication
        $response = $this->getJson("/api/v1/documents/{$invoice->id}/tax-breakdown");

        // Assert: Should return 401 Unauthorized
        $response->assertUnauthorized();
    }

    public function test_it_respects_company_context(): void
    {
        // Arrange: Create another company
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        // Create document for OTHER company
        $invoice = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id, // Different company!
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'document_date' => now(),
            'document_number' => 'INV-TN-003',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '120.000',
        ]);

        // Act: Try to access document from different company
        Sanctum::actingAs($this->user);
        $response = $this->withHeaders([
            'X-Company-ID' => $this->tunisianCompany->id, // User's company
        ])->getJson("/api/v1/documents/{$invoice->id}/tax-breakdown");

        // Assert: Should return 404 (company context mismatch)
        $response->assertNotFound();
    }
}
