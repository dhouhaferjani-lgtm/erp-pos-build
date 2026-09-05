<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * DEV-QA-008 / DEV-QA-057 — the FE submits `issue_date` (not `document_date`),
 * so the `after_or_equal:document_date` guard on `due_date` never had a value to
 * compare against on create, and the rule was absent entirely on update. A quote
 * or purchase order could therefore be saved with a due date that precedes its
 * issue date. These tests pin the guard on BOTH the create and update paths, for
 * BOTH the quote and purchase-order endpoints, using the real FE `issue_date`
 * payload shape.
 */
class DocumentDueDateGuardTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Partner $supplier;

    private Document $quote;

    private Document $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
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

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Acme Supplies',
            'type' => PartnerType::Supplier,
            'code' => 'SUP-001',
        ]);

        $this->quote = $this->makeDraft(DocumentType::Quote, $this->customer, 'QT-2025-0001');
        $this->purchaseOrder = $this->makeDraft(DocumentType::PurchaseOrder, $this->supplier, 'PO-2025-0001');
    }

    private function makeDraft(DocumentType $type, Partner $partner, string $number): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Original Line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function lines(): array
    {
        return [
            [
                'description' => 'Service',
                'quantity' => '1.00',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
            ],
        ];
    }

    // ----- Quote: create -----------------------------------------------------

    public function test_quote_create_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_quote_create_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => $this->lines(),
            ]);

        $response->assertCreated();
    }

    // ----- Quote: update -----------------------------------------------------

    public function test_quote_update_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_quote_update_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(15)->toDateString(),
            ]);

        $response->assertOk();
    }

    // ----- Purchase order: create -------------------------------------------

    public function test_purchase_order_create_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $this->supplier->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_purchase_order_create_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $this->supplier->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => $this->lines(),
            ]);

        $response->assertCreated();
    }

    // ----- Purchase order: update -------------------------------------------

    public function test_purchase_order_update_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }
}
