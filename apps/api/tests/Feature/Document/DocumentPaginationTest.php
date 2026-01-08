<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DocumentPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

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
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
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
        $this->user->givePermissionTo([
            'documents.view',
            'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete',
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function createDocument(DocumentType $type, int $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => \App\Modules\Document\Domain\Enums\DocumentStatus::Draft,
            'document_number' => sprintf('%s-2025-%04d', $type->value, $number),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
        ]);
    }

    public function test_can_list_all_documents_with_cursor_pagination(): void
    {
        // Create 30 invoices
        for ($i = 1; $i <= 30; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'document_number'],
                ],
                'meta' => [
                    'per_page',
                    'has_more',
                ],
                'links' => [
                    'next',
                    'prev',
                ],
            ]);

        $this->assertEquals(10, $response->json('meta.per_page'));
        $this->assertTrue($response->json('meta.has_more'));
        $this->assertNotNull($response->json('links.next'));
    }

    public function test_can_list_invoices_with_cursor_pagination(): void
    {
        // Create invoices and quotes
        for ($i = 1; $i <= 20; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->createDocument(DocumentType::Quote, $i);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/invoices?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data');

        // All returned documents should be invoices
        foreach ($response->json('data') as $doc) {
            $this->assertEquals('invoice', $doc['type']);
        }

        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_can_navigate_to_next_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
            // Small delay to ensure different created_at times for cursor pagination
            usleep(1000);
        }

        // Get first page
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents?per_page=10');

        $response->assertOk();
        $nextCursor = $response->json('links.next');
        $this->assertNotNull($nextCursor);

        // Get second page
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/documents?per_page=10&cursor='.urlencode($nextCursor));

        $response2->assertOk()
            ->assertJsonCount(10, 'data');

        // Should have different documents
        $firstPageIds = collect($response->json('data'))->pluck('id')->toArray();
        $secondPageIds = collect($response2->json('data'))->pluck('id')->toArray();

        $this->assertEmpty(array_intersect($firstPageIds, $secondPageIds));
    }

    public function test_default_per_page_is_25(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents');

        $response->assertOk()
            ->assertJsonCount(25, 'data');

        $this->assertEquals(25, $response->json('meta.per_page'));
    }

    public function test_pagination_works_with_type_filter(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->createDocument(DocumentType::Quote, $i);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents?type=invoice&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_empty_results_return_empty_array(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents');

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertFalse($response->json('meta.has_more'));
        $this->assertNull($response->json('links.next'));
    }

    public function test_documents_are_isolated_by_company(): void
    {
        // Create documents for our company
        for ($i = 1; $i <= 5; $i++) {
            $this->createDocument(DocumentType::Invoice, $i);
        }

        // Create documents for another company
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $otherCompany->id,
                'partner_id' => $otherPartner->id,
                'type' => DocumentType::Invoice,
                'status' => \App\Modules\Document\Domain\Enums\DocumentStatus::Draft,
                'document_number' => sprintf('INV-OTHER-%04d', $i),
                'document_date' => now()->toDateString(),
                'currency' => 'TND',
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/documents');

        $response->assertOk()
            ->assertJsonCount(5, 'data');
    }
}
