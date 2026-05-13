<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

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
use App\Modules\Media\Domain\DocumentAttachment;
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
 * Regression test for AttachmentController cross-tenant + cross-company
 * scope after dev-remediation/E (Phase E file/PDF endpoint review).
 *
 * The pre-fix controller only checked `user->tenant_id !==
 * document->tenant_id`, allowing a user in tenant T1 / company C1 to
 * download attachments belonging to documents in tenant T1 / company
 * C2. The fix routes every lookup through CompanyContext-scoped
 * queries.
 */
final class AttachmentTenantIsolationTest extends TestCase
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

    private DocumentAttachment $attachmentA;

    private DocumentAttachment $attachmentA2;

    private DocumentAttachment $attachmentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('tenant-a-att-iso');
        $this->tenantB = $this->makeTenant('tenant-b-att-iso');

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A1', 'TAX-ATA1');
        $this->companyA2 = $this->makeCompany($this->tenantA->id, 'Company A2', 'TAX-ATA2');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-ATB');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-att@example.com',
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

        $this->documentA = $this->makeDocument($this->tenantA->id, $this->companyA->id, 'INV-ATA1');
        $this->documentA2 = $this->makeDocument($this->tenantA->id, $this->companyA2->id, 'INV-ATA2');
        $this->documentB = $this->makeDocument($this->tenantB->id, $this->companyB->id, 'INV-ATB');

        $this->attachmentA = $this->makeAttachment($this->documentA);
        $this->attachmentA2 = $this->makeAttachment($this->documentA2);
        $this->attachmentB = $this->makeAttachment($this->documentB);
    }

    public function test_index_rejects_cross_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentB->id.'/attachments')
            ->assertStatus(404);
    }

    public function test_index_rejects_cross_company_same_tenant_document(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentA2->id.'/attachments')
            ->assertStatus(404);
    }

    public function test_download_rejects_cross_tenant_attachment(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentB->id.'/attachments/'.$this->attachmentB->id.'/download')
            ->assertStatus(404);
    }

    public function test_download_rejects_mixed_ids_doc_a_attachment_b(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/v1/documents/'.$this->documentA->id.'/attachments/'.$this->attachmentB->id.'/download')
            ->assertStatus(404);
    }

    public function test_destroy_rejects_cross_tenant_attachment(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->deleteJson('/api/v1/documents/'.$this->documentB->id.'/attachments/'.$this->attachmentB->id)
            ->assertStatus(404);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
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

    private function makeAttachment(Document $document): DocumentAttachment
    {
        // Reuse userA as uploader for both tenants — the column requires
        // a uuid but the attachment scope test does not exercise
        // uploader cross-tenant logic.
        return DocumentAttachment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'filename' => 'att-'.$document->id.'.pdf',
            'original_filename' => 'original.pdf',
            'storage_path' => 'attachments/'.$document->id.'.pdf',
            'storage_disk' => 'public',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->userA->id,
        ]);
    }
}
