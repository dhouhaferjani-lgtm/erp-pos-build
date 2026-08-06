<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeletePartnerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

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

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Partner To Delete',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_can_delete_partner(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('partners', [
            'id' => $this->partner->id,
        ]);
    }

    public function test_returns_404_for_nonexistent_partner(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$fakeId}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_delete_partner(): void
    {
        $response = $this->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_delete_partner(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertForbidden();
    }

    public function test_cannot_delete_partner_from_another_tenant(): void
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
            'name' => 'Other Tenant Partner',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$otherPartner->id}");

        $response->assertNotFound();

        // Ensure the partner is still there
        $this->assertDatabaseHas('partners', [
            'id' => $otherPartner->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_partner_not_shown_in_list(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ────────────────────────────────────────────────────────────────────
    // BUG-007 — business guard. `destroy` had no guard at all, so an admin
    // could soft-delete a partner that still carries invoices or an open
    // balance, orphaning the financial history behind it.
    // ────────────────────────────────────────────────────────────────────

    public function test_cannot_delete_partner_with_documents(): void
    {
        $this->createDocumentForPartner($this->partner->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTNER_HAS_DOCUMENTS')
            ->assertJsonPath('error.details.documents', 1);

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_partner_with_payments(): void
    {
        $this->createPaymentForPartner($this->partner->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTNER_HAS_DOCUMENTS')
            ->assertJsonPath('error.details.payments', 1);

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_partner_with_pos_receipts(): void
    {
        $this->createPosReceiptForPartner($this->partner->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PARTNER_HAS_DOCUMENTS')
            ->assertJsonPath('error.details.pos_receipts', 1);
    }

    public function test_soft_deleted_documents_do_not_block_partner_deletion(): void
    {
        $document = $this->createDocumentForPartner($this->partner->id);
        $document->delete();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
    }

    public function test_documents_of_another_partner_do_not_block_deletion(): void
    {
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Partner',
            'type' => PartnerType::Customer,
        ]);
        $this->createDocumentForPartner($otherPartner->id);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$this->partner->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $this->partner->id]);
    }

    private function createDocumentForPartner(string $partnerId): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partnerId,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-2026-'.substr($partnerId, 0, 4),
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
        ]);
    }

    private function createPaymentForPartner(string $partnerId): void
    {
        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partnerId,
        ]);
    }

    private function createPosReceiptForPartner(string $partnerId): void
    {
        $location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);

        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'partner_id' => $partnerId,
        ]);
    }
}
