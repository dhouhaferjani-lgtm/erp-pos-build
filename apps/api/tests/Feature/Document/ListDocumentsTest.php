<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

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
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_can_list_quotes(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '200.00',
            'tax_amount' => '40.00',
            'total' => '240.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/quotes');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'document_number', 'type', 'status', 'partner_id', 'total', 'created_at'],
                ],
                'meta' => ['per_page', 'has_more'],
            ]);
    }

    public function test_can_list_invoices(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);

        // Create a quote to ensure filtering works
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/invoices');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'invoice');
    }

    public function test_dashboard_recent_documents_limit_request_allows_documents_without_partner(): void
    {
        $this->company->update([
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'currency' => 'TND',
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => null,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-RECENT-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents?limit=5&sort=-created_at');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.partner_id', null)
            ->assertJsonPath('data.0.document_number', 'EXP-RECENT-001')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_generic_documents_list_allows_draft_expenses_without_document_number(): void
    {
        $this->company->update([
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'currency' => 'TND',
        ]);

        $expense = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => null,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'document_number' => null,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents?limit=5&sort=-created_at');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expense->id)
            ->assertJsonPath('data.0.document_number', null);
    }

    public function test_list_is_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'type' => DocumentType::Quote,
                'status' => DocumentStatus::Draft,
                'document_number' => "QT-2025-{$i}",
                'document_date' => now(),
                'currency' => 'EUR',
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/quotes');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.has_more', false); // 25 items with default page size of 25

        $this->assertCount(25, $response->json('data'));
    }

    public function test_can_filter_by_status(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/quotes?status=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');
    }

    public function test_can_filter_by_partner(): void
    {
        $otherCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Jane Smith',
            'type' => PartnerType::Customer,
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherCustomer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/quotes?partner_id={$this->customer->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.partner_id', $this->customer->id);
    }

    public function test_can_search_by_document_number(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/invoices?search=0001');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'INV-2025-0001');
    }

    public function test_only_shows_documents_from_current_tenant(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Customer',
            'type' => PartnerType::Customer,
        ]);

        Document::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/quotes');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', $this->tenant->id);
    }

    public function test_can_get_single_document(): void
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'notes' => 'Test notes',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/quotes/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.document_number', 'QT-2025-0001')
            ->assertJsonPath('data.total', '120.00')
            ->assertJsonPath('data.notes', 'Test notes');
    }

    public function test_returns_404_for_nonexistent_document(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/quotes/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_cannot_view_document_from_another_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Customer',
            'type' => PartnerType::Customer,
        ]);

        $otherDocument = Document::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/quotes/{$otherDocument->id}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_list_documents(): void
    {
        $response = $this->getJson('/api/v1/quotes');

        $response->assertUnauthorized();
    }

    public function test_viewer_can_list_quotes(): void
    {
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        // Create company membership for viewer
        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->getJson('/api/v1/quotes');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_can_filter_documents_by_product_id(): void
    {
        // Create a product
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'type' => ProductType::Part,
            'sale_price' => '100.00',
            'purchase_price' => '50.00',
        ]);

        // Create another product
        $otherProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-001',
            'type' => ProductType::Part,
            'sale_price' => '200.00',
            'purchase_price' => '100.00',
        ]);

        // Create an invoice with our target product
        $invoiceWithProduct = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $invoiceWithProduct->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '2.00',
            'unit_price' => '50.00',
            'line_total' => '100.00',
        ]);

        // Create a purchase order with our target product
        $poWithProduct = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
        ]);

        DocumentLine::create([
            'document_id' => $poWithProduct->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'line_total' => '50.00',
        ]);

        // Create a quote WITHOUT our target product (has other product)
        $quoteWithoutProduct = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '200.00',
            'tax_amount' => '40.00',
            'total' => '240.00',
        ]);

        DocumentLine::create([
            'document_id' => $quoteWithoutProduct->id,
            'product_id' => $otherProduct->id,
            'line_number' => 1,
            'description' => 'Other Product',
            'quantity' => '1.00',
            'unit_price' => '200.00',
            'line_total' => '200.00',
        ]);

        // Create a document with no lines at all
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
        ]);

        // Filter by product_id - should only return documents containing our product
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/documents?product_id={$product->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $documents = $response->json('data');
        $this->assertIsArray($documents);

        $returnedIds = [];
        foreach ($documents as $document) {
            $this->assertIsArray($document);
            $this->assertIsString($document['id'] ?? null);
            $returnedIds[] = $document['id'];
        }

        $this->assertContains($invoiceWithProduct->id, $returnedIds);
        $this->assertContains($poWithProduct->id, $returnedIds);
        $this->assertNotContains($quoteWithoutProduct->id, $returnedIds);
    }

    public function test_product_filter_can_be_combined_with_type_filter(): void
    {
        // Create a product
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-002',
            'type' => ProductType::Part,
            'sale_price' => '100.00',
        ]);

        // Create an invoice with product
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        // Create a PO with same product
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-2025-0002',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'line_total' => '50.00',
        ]);

        // Filter by product_id AND type=invoice
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/documents?product_id={$product->id}&type=invoice");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'invoice');
    }

    /**
     * Legacy `?limit=` branch fixture (plan Task 4, Step 5).
     */
    private function seedLegacyLimitDocuments(int $count): void
    {
        foreach (range(1, $count) as $index) {
            Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'type' => DocumentType::Quote,
                'status' => DocumentStatus::Draft,
                'document_number' => 'LIMIT-'.$index,
                'document_date' => now(),
                'currency' => 'EUR',
            ]);
        }
    }

    public function test_legacy_limit_zero_clamps_to_one(): void
    {
        $this->seedLegacyLimitDocuments(3);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents?limit=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_legacy_limit_negative_five_clamps_to_one(): void
    {
        $this->seedLegacyLimitDocuments(3);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents?limit=-5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_legacy_limit_5000_returns_exactly_100_of_101_documents(): void
    {
        $this->seedLegacyLimitDocuments(101);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents?limit=5000');
        $response->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);
        self::assertCount(100, $response->json('data'));
    }
}
