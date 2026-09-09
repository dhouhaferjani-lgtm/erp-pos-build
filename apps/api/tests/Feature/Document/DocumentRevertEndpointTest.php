<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
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

final class DocumentRevertEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Document Revert Tenant',
            'slug' => 'document-revert-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Revert Company',
            'legal_name' => 'Document Revert Company SARL',
            'tax_id' => 'DR-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = $this->makeUser('revert@example.com');
        $this->user->givePermissionTo(['documents.view', 'documents.update']);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Document Revert Partner',
            'type' => PartnerType::Customer,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_confirmed_quote_can_be_reverted_through_endpoint(): void
    {
        $quote = $this->makeDocument(DocumentType::Quote, DocumentStatus::Confirmed);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/documents/{$quote->id}/revert");

        $response->assertOk()
            ->assertJsonPath('data.id', $quote->id)
            ->assertJsonPath('data.status', DocumentStatus::Draft->value);

        $this->assertDatabaseHas('documents', [
            'id' => $quote->id,
            'status' => DocumentStatus::Draft->value,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);
    }

    public function test_revert_endpoint_requires_documents_update_permission(): void
    {
        $viewer = $this->makeUser('viewer@example.com');
        $viewer->givePermissionTo(['documents.view']);
        $quote = $this->makeDocument(DocumentType::Quote, DocumentStatus::Confirmed);

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/documents/{$quote->id}/revert");

        $response->assertForbidden();
        $this->assertDatabaseHas('documents', [
            'id' => $quote->id,
            'status' => DocumentStatus::Confirmed->value,
        ]);
    }

    /**
     * F-W2-14 residual (a). `cashier` holds `documents.update` (seeder) and no
     * `purchase-orders.*` at all, yet the type-agnostic revert route let it
     * un-confirm a PURCHASE ORDER (measured 200 on the wave-2 browser run,
     * W2-PERM-6..11). DocumentPolicy::revert() now takes the per-type verdict.
     */
    public function test_cashier_cannot_revert_a_confirmed_purchase_order(): void
    {
        $cashier = $this->makeUser('cashier-revert@example.com');
        $cashier->assignRole('cashier');
        $purchaseOrder = $this->makePurchaseOrder();

        $response = $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/documents/{$purchaseOrder->id}/revert");

        $response->assertForbidden();
        $this->assertDatabaseHas('documents', [
            'id' => $purchaseOrder->id,
            'status' => DocumentStatus::Confirmed->value,
        ]);
    }

    /**
     * The positive half: a role that governs purchase-order confirmation may
     * still un-confirm one. Nothing about the manager path changes.
     */
    public function test_manager_can_revert_a_confirmed_purchase_order(): void
    {
        $manager = $this->makeUser('manager-revert@example.com');
        $manager->assignRole('manager');
        $purchaseOrder = $this->makePurchaseOrder();

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/documents/{$purchaseOrder->id}/revert");

        $response->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Draft->value);
        $this->assertDatabaseHas('documents', [
            'id' => $purchaseOrder->id,
            'status' => DocumentStatus::Draft->value,
        ]);
    }

    /**
     * Grantability (owner ruling 2026-09-07): the secure default is a DEFAULT,
     * not a hard-coded role check. A user who is granted the governing
     * permission directly — no `manager` role anywhere — may revert.
     */
    public function test_directly_granted_purchase_order_confirm_permission_allows_revert(): void
    {
        $operator = $this->makeUser('operator-revert@example.com');
        $operator->assignRole('operator');
        $operator->givePermissionTo('purchase-orders.confirm');
        $purchaseOrder = $this->makePurchaseOrder();

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/documents/{$purchaseOrder->id}/revert");

        $response->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Draft->value);
    }

    /**
     * Non-PO revert behaviour is deliberately untouched: `documents.update`
     * alone still reverts a confirmed quote (the setUp user holds exactly that
     * and no role).
     */
    public function test_quote_revert_still_needs_only_documents_update(): void
    {
        $quote = $this->makeDocument(DocumentType::Quote, DocumentStatus::Confirmed);

        $this->assertFalse($this->user->can('purchase-orders.confirm'));

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/documents/{$quote->id}/revert")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Draft->value);
    }

    public function test_revert_endpoint_rejects_unsupported_invoice(): void
    {
        $invoice = $this->makeDocument(DocumentType::Invoice, DocumentStatus::Confirmed);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/documents/{$invoice->id}/revert");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'DOCUMENT_REVERT_NOT_SUPPORTED');
    }

    public function test_revert_endpoint_returns_404_for_cross_company_document(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Document Revert Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Document Revert Partner',
            'type' => PartnerType::Customer,
        ]);
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'REV-OTHER-2026-0001',
            'document_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/documents/{$quote->id}/revert");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    private function makeUser(string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Revert User',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        return $user;
    }

    /**
     * A confirmed purchase order with no lines, no receipts and no source
     * document — the shape `DocumentPostingService::revertPurchaseOrder()`
     * accepts, so the only thing under test is the authorization verdict.
     */
    private function makePurchaseOrder(): Document
    {
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Document Revert Supplier '.uniqid(),
            'type' => PartnerType::Supplier,
        ]);

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-REV-'.uniqid(),
            'document_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
    }

    private function makeDocument(DocumentType $type, DocumentStatus $status): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => $status,
            'document_number' => 'REV-2026-0001',
            'document_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
        ]);
    }
}
