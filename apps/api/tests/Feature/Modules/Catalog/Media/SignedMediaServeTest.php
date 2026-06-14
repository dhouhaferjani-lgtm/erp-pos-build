<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Application\Services\MediaUrlResolver;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the signed-URL media serving route.
 *
 * Route: GET /api/v1/media/{tenant}/{attachment}/serve
 * Middleware: ['api', 'signed']
 *
 * Contract:
 *   - A valid temporarySignedRoute URL serves bytes with HTTP 200 (or 302 for
 *     ExternalUrl) without any Authorization header.
 *   - A tampered or expired signature → 403.
 *   - An attachment belonging to a different tenant → 404.
 *   - MediaUrlResolver returns the raw external_url for ExternalUrl assets.
 *   - MediaUrlResolver returns a signed `/media/` URL for Upload assets.
 */
final class SignedMediaServeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant '.Str::random(6),
            'slug' => 'test-tenant-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy-path: valid signed URL serves bytes (no Authorization header)
    // -----------------------------------------------------------------------

    public function test_valid_signed_url_serves_bytes_with_200_without_auth_header(): void
    {
        $storagePath = 'products/'.$this->tenant->id.'/slot/original.jpg';
        Storage::disk('s3')->put($storagePath, 'FAKE_IMAGE_BYTES');

        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        // Generate a signed URL — no auth credentials injected.
        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
        );

        // Issue the request with NO Authorization header.
        $response = $this->get($signedUrl);

        // 200 OK — bytes served.
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // Tampered signature → 403
    // -----------------------------------------------------------------------

    public function test_tampered_signature_returns_403(): void
    {
        $asset = $this->makeUploadAsset();
        $attachment = $this->makeAttachment($asset->id);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
        );

        // Tamper: replace the signature value with random bytes.
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature='.Str::random(40), $signedUrl);
        self::assertNotNull($tamperedUrl, 'Regex substitution must produce a non-null string');

        $response = $this->get($tamperedUrl);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Expired signature → 403
    // -----------------------------------------------------------------------

    public function test_expired_signed_url_returns_403(): void
    {
        $asset = $this->makeUploadAsset();
        $attachment = $this->makeAttachment($asset->id);

        // Generate a URL that has already expired.
        $expiredUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->subMinute(),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
        );

        $response = $this->get($expiredUrl);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Cross-tenant attachment → 404
    // -----------------------------------------------------------------------

    public function test_cross_tenant_attachment_id_returns_404(): void
    {
        // Attachment belongs to tenant A but the signed URL uses tenant A — however
        // the attachment id is from a completely different tenant's data set.
        $otherTenantId = (string) Str::uuid();

        $otherAsset = MediaAsset::create([
            'tenant_id' => $otherTenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'products/'.$otherTenantId.'/original.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $otherAttachment = MediaAttachment::create([
            'tenant_id' => $otherTenantId,
            'media_asset_id' => $otherAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        // Sign the URL with OUR tenant but embed the other tenant's attachment id.
        // The DB query inside the controller scopes to tenant_id === {tenant}, so
        // the cross-tenant attachment will not be found.
        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $otherAttachment->id],
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // MediaUrlResolver — ExternalUrl returns raw URL
    // -----------------------------------------------------------------------

    public function test_url_resolver_returns_external_url_for_external_asset(): void
    {
        $resolver = $this->app->make(MediaUrlResolver::class);

        $externalUrl = 'https://cdn.example.com/photo.jpg';

        $asset = new MediaAsset();
        $asset->source = MediaSource::ExternalUrl;
        $asset->external_url = $externalUrl;

        $attachment = new MediaAttachment();
        $attachment->id = (string) Str::uuid();
        $attachment->tenant_id = $this->tenant->id;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $resolved = $resolver->forAttachment($attachment, 'sm');

        self::assertSame($externalUrl, $resolved, 'ExternalUrl asset must return the raw external_url verbatim');
    }

    // -----------------------------------------------------------------------
    // MediaUrlResolver — Upload returns signed media.serve URL
    // -----------------------------------------------------------------------

    public function test_url_resolver_returns_signed_media_serve_url_for_upload_asset(): void
    {
        $resolver = $this->app->make(MediaUrlResolver::class);

        $attachmentId = (string) Str::uuid();

        $asset = new MediaAsset();
        $asset->source = MediaSource::Upload;

        $attachment = new MediaAttachment();
        $attachment->id = $attachmentId;
        $attachment->tenant_id = $this->tenant->id;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        self::assertNotNull($url, 'Upload asset must resolve to a non-null URL');
        self::assertStringContainsString('/media/', $url, 'Signed URL must route to media.serve (contains /media/)');
        self::assertStringContainsString('signature=', $url, 'Signed URL must contain an HMAC signature');
        self::assertStringContainsString($attachmentId, $url, 'Signed URL must embed the attachment id');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeUploadAsset(): MediaAsset
    {
        $storagePath = 'products/'.$this->tenant->id.'/'.Str::uuid().'/original.jpg';
        Storage::disk('s3')->put($storagePath, 'FAKE_IMAGE_BYTES');

        return MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
        ]);
    }

    private function makeAttachment(string $assetId): MediaAttachment
    {
        return MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $assetId,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);
    }
}
