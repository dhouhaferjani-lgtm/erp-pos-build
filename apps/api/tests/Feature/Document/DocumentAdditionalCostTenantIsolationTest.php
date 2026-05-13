<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tenant-isolation regression coverage for DocumentAdditionalCostController.
 *
 * Master plan §M2.3 — five CrossTenantRoute annotations on index,
 * store, update, destroy, and landedCostBreakdown. All five were known
 * gaps caused by Route Model Binding resolving Document /
 * DocumentAdditionalCost globally.
 *
 * The fix uses CompanyContext-scoped resolution and the
 * `expense_document_id` validation rule on store/update is also
 * tightened to a same-tenant scope so attackers cannot point a cost at
 * a foreign-tenant expense document.
 */
final class DocumentAdditionalCostTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyA2;

    private Company $companyB;

    private User $userA;

    private Document $documentA;

    private Document $documentA2;

    private Document $documentB;

    private DocumentAdditionalCost $costA;

    private DocumentAdditionalCost $costB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('tenant-a-cost-iso');
        $this->tenantB = $this->makeTenant('tenant-b-cost-iso');

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A1', 'TAX-CA1');
        $this->companyA2 = $this->makeCompany($this->tenantA->id, 'Company A2', 'TAX-CA2');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-CB');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-cost@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->documentA = $this->makeDocument($this->tenantA->id, $this->companyA->id, 'INV-CA1');
        $this->documentA2 = $this->makeDocument($this->tenantA->id, $this->companyA2->id, 'INV-CA2');
        $this->documentB = $this->makeDocument($this->tenantB->id, $this->companyB->id, 'INV-CB');

        $this->costA = $this->makeCost($this->documentA, 'shipping', 50.00);
        $this->costB = $this->makeCost($this->documentB, 'shipping', 75.00);
    }

    // ----------------------------------------------------------------
    // Cross-tenant gaps
    // ----------------------------------------------------------------

    public function test_index_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentB->id.'/additional-costs')
            ->assertStatus(404);
    }

    public function test_store_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentB->id.'/additional-costs', [
                'cost_type' => 'shipping',
                'amount' => 25.00,
            ])
            ->assertStatus(404);
    }

    public function test_update_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/documents/'.$this->documentB->id.'/additional-costs/'.$this->costB->id, [
                'amount' => 99.99,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/documents/'.$this->documentB->id.'/additional-costs/'.$this->costB->id)
            ->assertStatus(404);
    }

    public function test_landed_cost_breakdown_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentB->id.'/landed-cost-breakdown')
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Cross-company within same tenant
    // ----------------------------------------------------------------

    public function test_index_rejects_cross_company_same_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentA2->id.'/additional-costs')
            ->assertStatus(404);
    }

    public function test_store_rejects_cross_company_same_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA2->id.'/additional-costs', [
                'cost_type' => 'shipping',
                'amount' => 25.00,
            ])
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // Mixed-id requests
    // ----------------------------------------------------------------

    public function test_update_rejects_mixed_ids_doc_a_cost_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->patchJson('/api/v1/documents/'.$this->documentA->id.'/additional-costs/'.$this->costB->id, [
                'amount' => 99.99,
            ])
            ->assertStatus(404);
    }

    public function test_destroy_rejects_mixed_ids_doc_a_cost_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/documents/'.$this->documentA->id.'/additional-costs/'.$this->costB->id)
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------
    // expense_document_id must be same-tenant
    // ----------------------------------------------------------------

    public function test_store_rejects_cross_tenant_expense_document_reference(): void
    {
        // Attacker passes a real cross-tenant document UUID as the
        // expense reference. The validation must reject this even when
        // the parent document is in-scope.
        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA->id.'/additional-costs', [
                'cost_type' => 'shipping',
                'amount' => 25.00,
                'expense_document_id' => $this->documentB->id,
            ]);

        $response->assertStatus(422);
    }

    // ----------------------------------------------------------------
    // Happy path
    // ----------------------------------------------------------------

    public function test_same_tenant_index_returns_only_scoped_costs(): void
    {
        $extra = $this->makeCost($this->documentA, 'insurance', 10.00);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentA->id.'/additional-costs');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->costA->id, $extra->id],
            $ids,
        );
    }

    public function test_same_tenant_store_creates_cost(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA->id.'/additional-costs', [
                'cost_type' => 'handling',
                'amount' => 7.50,
                'description' => 'Smoke test',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('handling', $response->json('data.cost_type'));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
        ]);
    }

    private function makeCompany(string $tenantId, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeDocument(string $tenantId, string $companyId, string $number): Document
    {
        $partner = Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => 'Partner '.$number,
            'type' => PartnerType::Customer,
            'email' => 'partner-'.strtolower($number).'@example.com',
        ]);

        return Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'EUR',
            'subtotal' => '100.000',
            'discount_amount' => '0.000',
            'tax_amount' => '20.000',
            'total' => '120.000',
            'balance_due' => '120.000',
            'is_historical' => false,
        ]);
    }

    private function makeCost(Document $document, string $type, float $amount): DocumentAdditionalCost
    {
        return DocumentAdditionalCost::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'cost_type' => $type,
            'amount' => $amount,
        ]);
    }
}
