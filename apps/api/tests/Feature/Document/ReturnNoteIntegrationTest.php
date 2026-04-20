<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\ReturnCondition;
use App\Modules\Document\Domain\Enums\ReturnReason;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReturnNoteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Create country
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'domain' => 'test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Setup permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create missing permissions for return notes (if they don't exist)
        if (! Permission::where('name', 'deliveries.edit')->exists()) {
            Permission::create([
                'name' => 'deliveries.edit',
                'guard_name' => 'sanctum',
                'team_id' => $this->tenant->id,
            ]);
        }
        if (! Permission::where('name', 'deliveries.delete')->exists()) {
            Permission::create([
                'name' => 'deliveries.delete',
                'guard_name' => 'sanctum',
                'team_id' => $this->tenant->id,
            ]);
        }

        // Create user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'deliveries.view',
            'deliveries.create',
            'deliveries.edit',
            'deliveries.delete',
            'deliveries.confirm',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'country_code' => 'TN',
        ]);

        // Create product
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'type' => 'part',
            'unit_of_measure' => 'unit',
            'is_active' => true,
        ]);

        // Create location
        $this->location = Location::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-01',
            'type' => 'warehouse',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_lists_return_notes(): void
    {
        // Create return notes
        $returnNote1 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $returnNote2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'RN-002',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '200.00',
            'tax_amount' => '38.00',
            'total' => '238.00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/return-notes');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'document_number',
                        'document_date',
                        'status',
                        'total',
                    ],
                ],
                'meta' => [
                    'per_page',
                    'has_more',
                ],
                'links',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function it_shows_a_single_return_note(): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/return-notes/{$returnNote->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $returnNote->id,
                    'document_number' => 'RN-001',
                    'status' => DocumentStatus::Draft->value,
                ],
            ]);
    }

    /** @test */
    public function it_creates_a_draft_return_note(): void
    {
        $requestData = [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'return_reason' => ReturnReason::Defective->value,
            'return_condition' => ReturnCondition::Damaged->value,
            'notes' => 'Customer returned defective product',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => 'Test Product',
                    'quantity' => '2.00',
                    'unit_price' => '100.00',
                    'tax_rate' => '19.00',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/return-notes', $requestData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'document_number',
                    'status',
                    'lines',
                ],
            ]);

        // Verify return note was created
        $this->assertDatabaseHas('documents', [
            'type' => DocumentType::ReturnNote->value,
            'status' => DocumentStatus::Draft->value,
            'partner_id' => $this->customer->id,
            'subtotal' => '200.00',
            'tax_amount' => '38.00',
            'total' => '238.00',
        ]);

        // Verify metadata was stored
        $returnNote = Document::where('type', DocumentType::ReturnNote)->first();
        $this->assertEquals(ReturnReason::Defective->value, $returnNote->payload['return_reason'] ?? null);
        $this->assertEquals(ReturnCondition::Damaged->value, $returnNote->payload['return_condition'] ?? null);
    }

    /** @test */
    public function it_updates_a_draft_return_note(): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'payload' => [
                'return_reason' => ReturnReason::Defective->value,
            ],
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        $updateData = [
            'notes' => 'Updated notes',
            'return_condition' => ReturnCondition::Used->value,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => 'Updated Product',
                    'quantity' => '3.00',
                    'unit_price' => '150.00',
                    'tax_rate' => '19.00',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/return-notes/{$returnNote->id}", $updateData);

        $response->assertOk();

        // Verify updates
        $returnNote->refresh();
        $this->assertEquals('Updated notes', $returnNote->notes);
        $this->assertEquals(ReturnCondition::Used->value, $returnNote->payload['return_condition'] ?? null);
        $this->assertEquals('450.000', $returnNote->subtotal);
        $this->assertEquals('535.500', $returnNote->total);
    }

    /** @test */
    public function it_prevents_updating_confirmed_return_note(): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $updateData = [
            'notes' => 'Trying to update confirmed return note',
        ];

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/return-notes/{$returnNote->id}", $updateData);

        $response->assertUnprocessable()
            ->assertJson([
                'error' => [
                    'code' => 'CANNOT_UPDATE_CONFIRMED_DOCUMENT',
                ],
            ]);
    }

    /** @test */
    public function it_deletes_a_draft_return_note(): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/return-notes/{$returnNote->id}");

        $response->assertOk()
            ->assertJson([
                'message' => 'Return note deleted successfully',
            ]);

        // Verify soft deletion
        $this->assertSoftDeleted('documents', [
            'id' => $returnNote->id,
        ]);

        // Verify lines were also deleted
        $this->assertDatabaseMissing('document_lines', [
            'document_id' => $returnNote->id,
        ]);
    }

    /** @test */
    public function it_prevents_deleting_confirmed_return_note(): void
    {
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Sealed,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/return-notes/{$returnNote->id}");

        $response->assertUnprocessable()
            ->assertJson([
                'error' => [
                    'code' => 'CANNOT_DELETE_CONFIRMED_DOCUMENT',
                ],
            ]);

        // Verify return note still exists
        $this->assertDatabaseHas('documents', [
            'id' => $returnNote->id,
        ]);
    }

    /** @test */
    public function it_confirms_draft_return_note(): void
    {
        // Create chart of accounts for GL entry generation
        $this->createChartOfAccounts();

        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'fiscal_category' => FiscalCategory::ReturnNote,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        DocumentLine::create([
            'document_id' => $returnNote->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '2.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '200.00',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/return-notes/{$returnNote->id}/confirm");

        if ($response->status() !== 200) {
            dd($response->json());
        }

        $response->assertOk();

        // Verify return note was confirmed
        $returnNote->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $returnNote->status);
        $this->assertNotNull($returnNote->fiscal_hash);
        $this->assertNotNull($returnNote->chain_sequence);
    }

    /** @test */
    public function it_returns_success_when_confirming_already_confirmed_return_note(): void
    {
        // Create already confirmed return note
        $returnNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'RN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'fiscal_hash' => 'existing-hash',
            'chain_sequence' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/return-notes/{$returnNote->id}/confirm");

        // Should be idempotent - return success
        $response->assertOk();
    }

    /**
     * Create chart of accounts for testing GL entries.
     */
    private function createChartOfAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivables',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4567',
            'name' => 'VAT Collected',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '709',
            'name' => 'Sales Returns',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::SalesReturn,
            'is_active' => true,
        ]);
    }
}
