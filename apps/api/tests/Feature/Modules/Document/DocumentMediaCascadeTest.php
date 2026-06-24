<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Document;

use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\MediaServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies that DocumentMediaCascadeObserver correctly:
 *   - Purges ALL media on hard-delete (parity with legacy FK cascadeOnDelete)
 *   - Leaves media intact on soft-delete (SoftDeletes must not cascade)
 */
final class DocumentMediaCascadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private MediaServiceInterface $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cascade Test Tenant',
            'slug' => 'cascade-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cascade Test Co',
            'legal_name' => 'Cascade Test Co Ltd',
            'tax_id' => 'TAX-CASCADE',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cascade Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->media = $this->app->make(MediaServiceInterface::class);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function makeDocument(): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-CASCADE-'.uniqid(),
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
        ]);
    }

    private function attachPdf(Document $doc, string $filename = 'invoice.pdf'): string
    {
        $view = $this->media->attachUpload(
            MediaOwnerType::Document,
            $doc->id,
            $doc->tenant_id,
            UploadedFile::fake()->create($filename, 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        return $view->id;
    }

    // -----------------------------------------------------------------------
    // forceDelete — must purge all media
    // -----------------------------------------------------------------------

    public function test_force_delete_purges_media_attachment_and_asset(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $doc = $this->makeDocument();
        $attachmentId = $this->attachPdf($doc);

        // Pre-condition: attachment + asset row both exist
        $this->assertDatabaseHas('media_attachments', [
            'id' => $attachmentId,
            'owner_type' => MediaOwnerType::Document->value,
            'owner_id' => $doc->id,
        ]);

        $assetCount = MediaAsset::where('tenant_id', $this->tenant->id)->count();
        self::assertSame(1, $assetCount, 'One asset must exist before hard-delete');

        // Hard-delete the document — observer must fire and purge media
        $doc->forceDelete();

        // media_attachments row must be gone
        $this->assertDatabaseMissing('media_attachments', [
            'id' => $attachmentId,
        ]);

        // media_assets row must be soft-deleted (purgeOwner calls detach chain)
        $this->assertSoftDeleted('media_assets', ['tenant_id' => $this->tenant->id]);
    }

    public function test_force_delete_purges_multiple_attachments(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $doc = $this->makeDocument();
        $a1 = $this->attachPdf($doc, 'a.pdf');
        $a2 = $this->attachPdf($doc, 'b.pdf');

        self::assertCount(2, $this->media->listForOwner(MediaOwnerType::Document, $doc->id, $doc->tenant_id));

        $doc->forceDelete();

        $this->assertDatabaseMissing('media_attachments', ['id' => $a1]);
        $this->assertDatabaseMissing('media_attachments', ['id' => $a2]);

        self::assertSame(
            0,
            MediaAsset::where('tenant_id', $this->tenant->id)->count(),
            'No READY assets must remain after hard-delete',
        );
        self::assertSame(
            2,
            MediaAsset::withTrashed()->where('tenant_id', $this->tenant->id)->count(),
            'Both assets must be soft-deleted after hard-delete',
        );
    }

    // -----------------------------------------------------------------------
    // soft delete — must NOT cascade media
    // -----------------------------------------------------------------------

    public function test_soft_delete_leaves_media_intact(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $doc = $this->makeDocument();
        $attachmentId = $this->attachPdf($doc);

        // Soft-delete — SoftDeletes parity: media must survive
        $doc->delete();

        // Document must be soft-deleted
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);

        // Attachment row must still exist
        $this->assertDatabaseHas('media_attachments', [
            'id' => $attachmentId,
            'owner_id' => $doc->id,
        ]);

        // Asset must still be READY (not soft-deleted)
        self::assertSame(
            1,
            MediaAsset::where('tenant_id', $this->tenant->id)->count(),
            'Asset must remain READY after soft-delete',
        );
    }
}
