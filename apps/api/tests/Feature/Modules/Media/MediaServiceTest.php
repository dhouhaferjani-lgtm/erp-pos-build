<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Catalog\Application\Services\MediaAttachmentService;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Shared\Contracts\MediaServiceInterface;
use App\Shared\DTOs\Media\MediaAttachmentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * MediaServiceTest — verifies the owner-agnostic seam using Document owners
 * (not Product) to prove genericity beyond the existing product-only tests.
 *
 * R-M1: listForOwner returns READY assets ordered newest-first (created_at desc).
 * R-H1: download returns a StreamedResponse with correct Content-Disposition / Content-Type.
 */
final class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaServiceInterface $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = $this->app->make(MediaServiceInterface::class);
    }

    // -----------------------------------------------------------------------
    // attachUpload + listForOwner — Document owner (proves genericity)
    // -----------------------------------------------------------------------

    public function test_attach_upload_pdf_to_document_owner_returns_view_with_correct_fields(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        $file = UploadedFile::fake()->create('inv.pdf', 50, 'application/pdf');

        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            $file,
            null, // no user → uploadedByName must be null
            MediaRole::Datasheet,
            'Supplier invoice',
            ['application/pdf'],
            MediaAssetType::Document,
        );

        self::assertInstanceOf(MediaAttachmentView::class, $view);
        self::assertTrue($view->isPdf, 'isPdf must be true for application/pdf');
        self::assertFalse($view->isImage, 'isImage must be false for a PDF');
        self::assertSame('inv.pdf', $view->originalFilename);
        self::assertSame('application/pdf', $view->mimeType);
        self::assertNull($view->uploadedByName, 'no user supplied → uploadedByName is null');
        self::assertNotNull($view->createdAt, 'createdAt must be set');
    }

    public function test_list_for_owner_returns_the_attached_document(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('inv.pdf', 50, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $list = $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantId);

        self::assertCount(1, $list, 'listForOwner must return exactly the one READY attachment');
        self::assertTrue($list[0]->isPdf);
        self::assertSame('inv.pdf', $list[0]->originalFilename);
    }

    // -----------------------------------------------------------------------
    // R-M1: listForOwner ordering — newest-first (created_at desc)
    // -----------------------------------------------------------------------

    public function test_list_for_owner_returns_attachments_newest_first(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        // Freeze time at T+0 for the first upload, then advance 2 seconds for the
        // second.  This guarantees distinct created_at values on SQLite (which
        // stores timestamps at second granularity) without relying on real wall-clock
        // progression.
        Carbon::setTestNow(now()->subSeconds(2));
        $view1 = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        Carbon::setTestNow(now()->addSeconds(2));
        $view2 = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        Carbon::setTestNow(); // unfreeze

        $list = $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantId);

        self::assertCount(2, $list);
        // Newest first: view2 (second upload) must precede view1 (first upload)
        self::assertSame($view2->id, $list[0]->id, 'Newest attachment must be first');
        self::assertSame($view1->id, $list[1]->id, 'Oldest attachment must be second');
    }

    // -----------------------------------------------------------------------
    // download — StreamedResponse with correct headers
    // -----------------------------------------------------------------------

    public function test_download_returns_streamed_response_with_correct_headers(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $response = $this->svc->download(MediaOwnerType::Document, $docId, $view->id, $tenantId);

        self::assertInstanceOf(StreamedResponse::class, $response, 'upload asset must return a StreamedResponse');

        $contentDisposition = $response->headers->get('Content-Disposition');
        $contentType = $response->headers->get('Content-Type');

        self::assertNotNull($contentDisposition);
        self::assertStringContainsString('report.pdf', (string) $contentDisposition);
        self::assertNotNull($contentType);
        self::assertStringContainsString('application/pdf', (string) $contentType);
    }

    // -----------------------------------------------------------------------
    // download — 404 when attachment belongs to a different owner
    // -----------------------------------------------------------------------

    public function test_download_returns_404_for_foreign_owner(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();
        $foreignDocId = (string) Str::uuid();

        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $this->expectException(HttpException::class);

        // Attempt to download using a different owner_id must 404
        $this->svc->download(MediaOwnerType::Document, $foreignDocId, $view->id, $tenantId);
    }

    // -----------------------------------------------------------------------
    // download / detach — 404 when attachment belongs to a different TENANT
    // -----------------------------------------------------------------------

    public function test_download_returns_404_for_foreign_tenant(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $docId = (string) Str::uuid();

        // Attach a file in tenantA
        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantA,
            UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $this->expectException(HttpException::class);

        // Attempting to download the same attachment from tenantB must 404
        $this->svc->download(MediaOwnerType::Document, $docId, $view->id, $tenantB);
    }

    public function test_detach_does_not_touch_foreign_tenant_attachment(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $docId = (string) Str::uuid();

        // Attach a file in tenantA
        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantA,
            UploadedFile::fake()->create('sensitive.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $this->expectException(HttpException::class);

        try {
            // Attempting to detach from tenantB must 404 …
            $this->svc->detach(MediaOwnerType::Document, $docId, $view->id, $tenantB);
        } finally {
            // … AND the original attachment + asset must remain intact
            $list = $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantA);
            self::assertCount(1, $list, 'Original attachment must still exist after a foreign-tenant detach attempt');
            self::assertSame($view->id, $list[0]->id, 'Original attachment id must be unchanged');
        }
    }

    // -----------------------------------------------------------------------
    // detach — removes link and soft-deletes the now-orphaned asset
    // -----------------------------------------------------------------------

    public function test_detach_removes_link_and_soft_deletes_orphaned_asset(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        $view = $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $this->svc->detach(MediaOwnerType::Document, $docId, $view->id, $tenantId);

        self::assertCount(0, $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantId));

        // The asset was the only attachment → it must be soft-deleted
        $this->assertSoftDeleted('media_assets', ['tenant_id' => $tenantId]);
    }

    // -----------------------------------------------------------------------
    // purgeOwner — removes all attachments + assets for an owner
    // -----------------------------------------------------------------------

    public function test_purge_owner_removes_all_attachments_and_assets(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        // Attach two documents
        $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Datasheet,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        $this->svc->attachUpload(
            MediaOwnerType::Document,
            $docId,
            $tenantId,
            UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            null,
            MediaRole::Gallery,
            null,
            ['application/pdf'],
            MediaAssetType::Document,
        );

        self::assertCount(2, $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantId));

        $this->svc->purgeOwner(MediaOwnerType::Document, $docId, $tenantId);

        self::assertCount(0, $this->svc->listForOwner(MediaOwnerType::Document, $docId, $tenantId));

        // Both assets must be soft-deleted
        self::assertSame(
            0,
            MediaAsset::where('tenant_id', $tenantId)->count(),
            'No READY assets should remain after purge',
        );
        self::assertSame(
            2,
            MediaAsset::withTrashed()->where('tenant_id', $tenantId)->count(),
            'Both assets must be soft-deleted (trashed)',
        );
    }

    // -----------------------------------------------------------------------
    // download — external URL asset redirects away
    // -----------------------------------------------------------------------

    public function test_download_external_url_asset_redirects(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $tenantId = (string) Str::uuid();
        $docId = (string) Str::uuid();

        // Manually create an EXTERNAL_URL asset + attachment (no upload path)
        $asset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://cdn.example.com/doc.jpg',
            'original_filename' => 'doc.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 0,
        ]);

        // We need to call attach() directly to create the link (no upload flow)
        /** @var MediaAttachmentService $attachSvc */
        $attachSvc = $this->app->make(MediaAttachmentService::class);
        $attachment = $attachSvc->attach($asset->id, MediaOwnerType::Document, $docId, MediaRole::Gallery, 0, $tenantId);

        $response = $this->svc->download(MediaOwnerType::Document, $docId, $attachment->id, $tenantId);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://cdn.example.com/doc.jpg', $response->getTargetUrl());
    }
}
