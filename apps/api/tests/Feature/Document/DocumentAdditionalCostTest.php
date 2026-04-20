<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class DocumentAdditionalCostTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Document $purchaseOrder;

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
        $this->seed(PermissionSeeder::class);

        // Create sanctum permissions from web permissions
        $webPermissions = Permission::where('guard_name', 'web')->get();
        foreach ($webPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission->name,
                'guard_name' => 'sanctum',
            ]);
        }

        // Create Administrator role for sanctum guard with all permissions
        $adminRole = Role::firstOrCreate([
            'name' => 'Administrator',
            'guard_name' => 'sanctum',
        ]);
        $adminRole->syncPermissions(Permission::where('guard_name', 'sanctum')->pluck('name'));

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('Administrator'); // Assign admin role for testing

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Test Supplier',
            'email' => 'supplier@example.com',
            'country_code' => 'FR',
        ]);

        $this->purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-2025-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '1000.00',
            'tax_amount' => '200.00',
            'total' => '1200.00',
        ]);
    }

    public function test_can_list_additional_costs_for_document(): void
    {
        DocumentAdditionalCost::create([
            'document_id' => $this->purchaseOrder->id,
            'cost_type' => 'shipping',
            'description' => 'International Shipping',
            'amount' => '150.00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['cost_type' => 'shipping']);
    }

    public function test_can_create_additional_cost(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs", [
                'cost_type' => 'customs',
                'description' => 'Import Duty',
                'amount' => 75.50,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'cost_type' => 'customs',
            'amount' => '75.500',
        ]);

        $this->assertDatabaseHas('document_additional_costs', [
            'document_id' => $this->purchaseOrder->id,
            'cost_type' => 'customs',
        ]);
    }

    public function test_can_update_additional_cost(): void
    {
        $cost = DocumentAdditionalCost::create([
            'document_id' => $this->purchaseOrder->id,
            'cost_type' => 'transport',
            'description' => 'Ground Transport',
            'amount' => '50.00',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs/{$cost->id}", [
                'amount' => 60.00,
                'description' => 'Express Transport',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'amount' => '60.000',
            'description' => 'Express Transport',
        ]);
    }

    public function test_can_delete_additional_cost(): void
    {
        $cost = DocumentAdditionalCost::create([
            'document_id' => $this->purchaseOrder->id,
            'cost_type' => 'handling',
            'amount' => '25.00',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs/{$cost->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('document_additional_costs', [
            'id' => $cost->id,
        ]);
    }

    public function test_cannot_update_cost_from_different_document(): void
    {
        $otherPO = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->purchaseOrder->partner_id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-2025-002',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '100.00',
            'total' => '600.00',
        ]);

        $cost = DocumentAdditionalCost::create([
            'document_id' => $otherPO->id,
            'cost_type' => 'insurance',
            'amount' => '30.00',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs/{$cost->id}", [
                'amount' => 40.00,
            ]);

        $response->assertStatus(404);
    }

    public function test_validates_cost_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs", [
                'cost_type' => 'invalid_type',
                'amount' => 100.00,
            ]);

        $this->assertApiValidationErrors($response, ['cost_type']);
    }

    public function test_validates_amount_is_positive(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/documents/{$this->purchaseOrder->id}/additional-costs", [
                'cost_type' => 'shipping',
                'amount' => -50.00,
            ]);

        $this->assertApiValidationErrors($response, ['amount']);
    }
}
