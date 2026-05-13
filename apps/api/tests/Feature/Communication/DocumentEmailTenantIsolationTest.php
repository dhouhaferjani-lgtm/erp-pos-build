<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
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
 * Tenant-isolation regression coverage for DocumentEmailController.
 *
 * Master plan §M2.2 — two CrossTenantRoute annotations on
 * DocumentEmailController::send() and ::queue(). Both were known gaps
 * caused by Route Model Binding resolving Document globally, so a
 * foreign-tenant document id would resolve, get rendered as a PDF, and
 * be emailed out — leaking that tenant's data.
 *
 * The fix uses CompanyContext-scoped resolution exactly like M2.1
 * (ProductImage). 404 shape is shared across foreign-tenant,
 * cross-company-same-tenant, and missing ids so the operator cannot
 * enumerate documents.
 */
final class DocumentEmailTenantIsolationTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-email-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-email-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
        ]);

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A1', 'TAX-DA1');
        $this->companyA2 = $this->makeCompany($this->tenantA->id, 'Company A2', 'TAX-DA2');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-DB');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-doc-email@example.com',
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

        $this->documentA = $this->makeDocument($this->tenantA->id, $this->companyA->id, 'INV-A1');
        $this->documentA2 = $this->makeDocument($this->tenantA->id, $this->companyA2->id, 'INV-A2');
        $this->documentB = $this->makeDocument($this->tenantB->id, $this->companyB->id, 'INV-B');
    }

    public function test_send_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentB->id.'/email', [
                'recipient_email' => 'attacker@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_queue_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentB->id.'/email/queue', [
                'recipient_email' => 'attacker@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_send_rejects_cross_company_same_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA2->id.'/email', [
                'recipient_email' => 'attacker@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_queue_rejects_cross_company_same_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA2->id.'/email/queue', [
                'recipient_email' => 'attacker@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_send_rejects_nonexistent_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/00000000-0000-0000-0000-000000000000/email', [
                'recipient_email' => 'attacker@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_send_payload_cannot_override_tenant_or_company(): void
    {
        // Payload contains malicious tenant_id / company_id keys; the
        // controller must NOT consume them. The scope-resolved document
        // is the only source of truth.
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentB->id.'/email', [
                'recipient_email' => 'attacker@example.com',
                'tenant_id' => $this->tenantB->id,
                'company_id' => $this->companyB->id,
            ])
            ->assertStatus(404);
    }

    public function test_same_tenant_send_reaches_service(): void
    {
        // The happy path proves the scope-resolution does not break the
        // existing flow. We pass a recipient_email so the service short-
        // circuit ("No recipient email") cannot mask a scope failure.
        // The downstream call may succeed (200) or fail at the mail
        // transport (500) — we only assert it is NOT 404 (which would
        // indicate scope rejected a valid same-tenant document).
        $response = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/documents/'.$this->documentA->id.'/email', [
                'recipient_email' => 'customer@example.com',
            ]);

        $this->assertNotEquals(
            404,
            $response->status(),
            'Same-tenant same-company document send must NOT return 404 (scope must accept it).',
        );
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
}
