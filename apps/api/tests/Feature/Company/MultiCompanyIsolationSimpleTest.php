<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Multi-Company Isolation Test (Simplified)
 *
 * Verifies that users cannot access data from other companies via API calls.
 * This is the critical security concern for multi-company SaaS applications.
 */
class MultiCompanyIsolationSimpleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Partner $partnerA;

    private Partner $partnerB;

    private Document $documentA;

    private Document $documentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create one tenant with two companies
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAXA',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAXB',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Seed roles and permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create users
        $this->userA = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->userB = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        // Assign permissions
        $this->userA->givePermissionTo(['invoices.view', 'invoices.create', 'partners.view']);
        $this->userB->givePermissionTo(['invoices.view', 'invoices.create', 'partners.view']);

        // Create company memberships
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        // Create partners
        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => PartnerType::Customer,
            'email' => 'partnera@example.com',
        ]);

        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => PartnerType::Customer,
            'email' => 'partnerb@example.com',
        ]);

        // Create documents
        $this->documentA = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->partnerA->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-A-001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $this->documentB = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-B-001',
            'document_date' => '2025-01-15',
            'currency' => 'EUR',
            'subtotal' => '200.00',
            'tax_amount' => '40.00',
            'total' => '240.00',
        ]);
    }

    public function test_database_has_documents_from_both_companies(): void
    {
        // Verify test data setup
        $this->assertDatabaseHas('documents', ['id' => $this->documentA->id, 'company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('documents', ['id' => $this->documentB->id, 'company_id' => $this->companyB->id]);
        $this->assertDatabaseHas('partners', ['id' => $this->partnerA->id, 'company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('partners', ['id' => $this->partnerB->id, 'company_id' => $this->companyB->id]);
    }

    public function test_user_from_company_a_cannot_view_company_b_document(): void
    {
        // User A tries to access Company B's document
        $response = $this->actingAs($this->userA)->getJson("/api/v1/invoices/{$this->documentB->id}");

        // Should return 404 (not found) to prevent information leakage
        // (403 would confirm the document exists)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_company_b_cannot_view_company_a_document(): void
    {
        // User B tries to access Company A's document
        $response = $this->actingAs($this->userB)->getJson("/api/v1/invoices/{$this->documentA->id}");

        // Should return 404 or 403
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_can_view_their_own_company_document(): void
    {
        // User A can access their own company's document
        $response = $this->actingAs($this->userA)->getJson("/api/v1/invoices/{$this->documentA->id}");

        $response->assertStatus(200);
        $this->assertEquals($this->documentA->id, $response->json('data.id'));
        // Verify the document belongs to the user's company by checking database
        $document = Document::find($response->json('data.id'));
        $this->assertEquals($this->companyA->id, $document->company_id);
    }

    public function test_users_from_different_companies_have_different_company_contexts(): void
    {
        // Verify that each user's company membership is correctly set
        $membershipA = UserCompanyMembership::where('user_id', $this->userA->id)->first();
        $membershipB = UserCompanyMembership::where('user_id', $this->userB->id)->first();

        $this->assertNotNull($membershipA);
        $this->assertNotNull($membershipB);
        $this->assertEquals($this->companyA->id, $membershipA->company_id);
        $this->assertEquals($this->companyB->id, $membershipB->company_id);
        $this->assertNotEquals($membershipA->company_id, $membershipB->company_id);
    }
}
