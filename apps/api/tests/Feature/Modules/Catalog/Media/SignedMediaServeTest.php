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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature tests for the signed-URL media serving route.
 *
 * Route: GET /api/v1/media/{tenant}/{attachment}/serve
 * Middleware: ['api', 'signed:relative', 'throttle:signed-media']
 *
 * Contract:
 *   - A valid temporarySignedRoute URL serves bytes with HTTP 200 (or 302 for
 *     ExternalUrl) without any Authorization header.
 *   - A tampered or expired signature → 403.
 *   - An attachment belonging to a different tenant → 404.
 *   - MediaUrlResolver returns the raw external_url for ExternalUrl assets.
 *   - MediaUrlResolver returns a signed `/media/` URL for Upload assets.
 *   - Only READY assets are served; any other status → 404.
 *   - An attachment whose media_asset belongs to a different tenant → 404
 *     (defense-in-depth IDOR: asset tenant_id constrained in the eager load).
 *   - Unknown variant (not 'sm' or 'md') → 404 (no silent downgrade).
 *   - Non-allowed MIME types → 404 (only image/jpeg, image/png, image/webp,
 *     image/gif are served for Upload-disk assets).
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

        // Generate a relative signed URL — no auth credentials injected.
        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
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
            absolute: false,
        );

        // Tamper: replace the signature value with random bytes.
        // The URL is a relative path (e.g. /api/v1/media/…?expires=…&signature=…);
        // the regex targets the query string so no host manipulation is needed.
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

        // Generate a relative URL that has already expired.
        $expiredUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->subMinute(),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
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
            absolute: false,
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Fix 1: Defense-in-depth IDOR — attachment's media_asset belongs to a
    // different tenant → 404 even with a valid signature for tenant A.
    // -----------------------------------------------------------------------

    public function test_attachment_whose_media_asset_belongs_to_different_tenant_returns_404(): void
    {
        // Asset lives in tenant B's namespace.
        $tenantBId = (string) Str::uuid();
        $storagePath = 'products/'.$tenantBId.'/original.jpg';
        Storage::disk('s3')->put($storagePath, 'BYTES_FROM_B');

        $assetOfB = MediaAsset::create([
            'tenant_id' => $tenantBId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
        ]);

        // Attachment row itself is stamped with tenant A (our tenant), but
        // references the asset from tenant B (mis-matched foreign key scenario).
        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $assetOfB->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        // The eager-loaded mediaAsset is constrained to tenant A; since the
        // asset belongs to B the relation resolves to null → 404.
        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Fix 2: Only READY status is served — all other statuses → 404
    // -----------------------------------------------------------------------

    public function test_uploaded_status_asset_returns_404(): void
    {
        $response = $this->serveAssetWithStatus(MediaStatus::Uploaded);
        $response->assertStatus(404);
    }

    public function test_processing_status_asset_returns_404(): void
    {
        $response = $this->serveAssetWithStatus(MediaStatus::Processing);
        $response->assertStatus(404);
    }

    public function test_failed_status_asset_returns_404(): void
    {
        $response = $this->serveAssetWithStatus(MediaStatus::Failed);
        $response->assertStatus(404);
    }

    public function test_ready_status_asset_returns_200(): void
    {
        $response = $this->serveAssetWithStatus(MediaStatus::Ready);
        $response->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // Fix 4: Unknown variant → 404 (no silent downgrade to original)
    //
    // The `variant` query parameter is part of the signed URL payload (signed
    // at URL-generation time so that the HMAC covers the chosen variant).
    // Tests therefore generate a properly-signed URL that includes the variant
    // inside the signature rather than appending it after signing (which would
    // produce a 403 tampered-signature response instead of the intended 404).
    // -----------------------------------------------------------------------

    public function test_unknown_variant_returns_404(): void
    {
        $asset = $this->makeUploadAsset();
        $attachment = $this->makeAttachment($asset->id);

        // Sign the URL with 'lg' included — the signature is valid, but the
        // controller must reject the unknown variant with 404.
        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id, 'variant' => 'lg'],
            absolute: false,
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    public function test_known_variant_sm_is_accepted(): void
    {
        $asset = $this->makeUploadAsset();
        $attachment = $this->makeAttachment($asset->id);

        // 'sm' is a valid variant — sign it into the URL.
        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id, 'variant' => 'sm'],
            absolute: false,
        );

        $response = $this->get($signedUrl);

        // Falls back to original path (no rendition row seeded) — still 200.
        $response->assertStatus(200);
    }

    public function test_absent_variant_serves_original(): void
    {
        $asset = $this->makeUploadAsset();
        $attachment = $this->makeAttachment($asset->id);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // Fix 5: MIME allow-list — only image/* types are streamed for Upload assets
    // -----------------------------------------------------------------------

    public function test_non_allowed_mime_type_returns_404(): void
    {
        // SVG is not in the allow-list.
        $storagePath = 'products/'.$this->tenant->id.'/image.svg';
        Storage::disk('s3')->put($storagePath, '<svg></svg>');

        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/svg+xml',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    public function test_html_mime_type_returns_404(): void
    {
        $storagePath = 'products/'.$this->tenant->id.'/page.html';
        Storage::disk('s3')->put($storagePath, '<html></html>');

        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'text/html',
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }

    /**
     * External-URL assets bypass the MIME allow-list check: they redirect, so
     * there are no stored bytes to gate. The 302 should still be issued.
     */
    public function test_external_url_asset_bypasses_mime_check_and_redirects(): void
    {
        // storage_disk is NOT NULL in the schema even for ExternalUrl assets;
        // supply a placeholder value (the adapter's redirect path never reads it).
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'none',
            'external_url' => 'https://cdn.example.com/photo.jpg',
            'mime_type' => null,
        ]);

        $attachment = MediaAttachment::create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => (string) Str::uuid(),
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        // Follow redirects is off by default — expect 302 + Location header.
        $response = $this->get($signedUrl);

        $response->assertRedirect('https://cdn.example.com/photo.jpg');
    }

    // -----------------------------------------------------------------------
    // MediaUrlResolver — ExternalUrl returns raw URL
    // -----------------------------------------------------------------------

    public function test_url_resolver_returns_external_url_for_external_asset(): void
    {
        $resolver = $this->app->make(MediaUrlResolver::class);

        $externalUrl = 'https://cdn.example.com/photo.jpg';

        $asset = new MediaAsset;
        $asset->source = MediaSource::ExternalUrl;
        $asset->external_url = $externalUrl;

        $attachment = new MediaAttachment;
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

        $asset = new MediaAsset;
        $asset->source = MediaSource::Upload;

        $attachment = new MediaAttachment;
        $attachment->id = $attachmentId;
        $attachment->tenant_id = $this->tenant->id;
        $attachment->owner_id = (string) Str::uuid();
        $attachment->setRelation('mediaAsset', $asset);

        $url = $resolver->forAttachment($attachment, 'sm');

        self::assertNotNull($url, 'Upload asset must resolve to a non-null URL');
        self::assertStringStartsWith('/', $url, 'Signed URL must be relative (starts with /) — no host prefix');
        self::assertStringContainsString('/media/', $url, 'Signed URL must route to media.serve (contains /media/)');
        self::assertStringContainsString('signature=', $url, 'Signed URL must contain an HMAC signature');
        self::assertStringContainsString($attachmentId, $url, 'Signed URL must embed the attachment id');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create an Upload asset with the given status and return the HTTP response
     * for a valid signed URL request against it.
     */
    private function serveAssetWithStatus(MediaStatus $status): TestResponse
    {
        $storagePath = 'products/'.$this->tenant->id.'/'.Str::uuid().'/original.jpg';
        Storage::disk('s3')->put($storagePath, 'FAKE_IMAGE_BYTES');

        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => $status,
            'storage_disk' => 's3',
            'storage_path' => $storagePath,
            'mime_type' => 'image/jpeg',
        ]);

        $attachment = $this->makeAttachment($asset->id);

        $signedUrl = URL::temporarySignedRoute(
            'media.serve',
            now()->addMinutes(60),
            ['tenant' => $this->tenant->id, 'attachment' => $attachment->id],
            absolute: false,
        );

        return $this->get($signedUrl);
    }

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
