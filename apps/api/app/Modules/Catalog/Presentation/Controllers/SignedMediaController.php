<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves media bytes via a short-lived signed URL — no bearer token required.
 *
 * This route is designed for <img src="…"> usage in the bearer-token SPA.
 * A Sanctum bearer SPA cannot embed credentials in an <img> src, so the
 * API returns a signed URL (60-minute TTL) and the browser fetches it with
 * a GET that carries only the HMAC signature — no Authorization header needed.
 *
 * Security model:
 *   - Laravel's built-in `signed` middleware (ValidateSignature) validates the
 *     HMAC before this controller runs; any tampered or expired URL → 403.
 *   - Tenant isolation: the {tenant} path segment is part of the signed URL,
 *     so an attacker cannot swap it without invalidating the signature.
 *   - The attachment is additionally scoped to tenant_id === {tenant} for
 *     defense-in-depth, so a valid signature for tenant A cannot serve
 *     tenant B's bytes even if an HMAC collision were ever found.
 *   - The mediaAsset relation is constrained to the same tenant_id in the
 *     eager load (defense-in-depth IDOR: a mismatched asset foreign key → 404).
 *   - Only READY assets are served (matches catalog/public policy).
 *   - Only Image and Document asset types are served; any other type
 *     (Video, ExternalVideo, Spin360) → 404.
 *   - Only recognised variant keys ('sm', 'md') are accepted; any other
 *     non-null variant → 404 (no silent downgrade). Document assets have no
 *     renditions at all, so ANY non-null variant on a Document asset → 404
 *     (no silent fallback to the original — same no-silent-downgrade
 *     philosophy as the unknown-variant guard).
 *   - Upload-disk assets are gated against a type-aware MIME allow-list:
 *     Image assets allow image/jpeg, image/png, image/webp, image/gif;
 *     Document assets allow ONLY application/pdf. The lists are disjoint —
 *     a Document asset with an image MIME (or vice versa) is rejected.
 *     ExternalUrl assets are redirected and bypass the allow-list (no
 *     stored bytes).
 *   - The route is throttled (120 req/min per IP) to deter enumeration loops.
 *
 * Cross-tenant by design: the URL is tenant-qualified; the HMAC signature
 * is the access gate, not Sanctum auth.
 */
final class SignedMediaController extends Controller
{
    /**
     * Allowed MIME types for Upload-disk Image assets.
     * ExternalUrl assets (redirect path) bypass this list.
     *
     * @var list<string>
     */
    private const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * Allowed MIME types for Upload-disk Document assets (scan previews).
     * Deliberately narrow — only PDF is a supported preview format today.
     *
     * @var list<string>
     */
    private const ALLOWED_DOCUMENT_MIME_TYPES = [
        'application/pdf',
    ];

    /**
     * Recognised variant keys accepted by this endpoint.
     * An absent / empty variant is still permitted (→ original).
     *
     * @var list<string>
     */
    private const VALID_VARIANTS = ['sm', 'md'];

    public function __construct(
        private readonly TenancyResolver $tenancyResolver,
        private readonly MediaStorageInterface $mediaStorage,
    ) {}

    /**
     * Serve a media attachment via a signed URL.
     *
     * Route: GET /api/v1/media/{tenant}/{attachment}/serve
     * Middleware: ['api', 'signed', 'throttle:signed-media']
     *
     * {tenant}     — tenant UUID (part of the signed payload)
     * {attachment} — media_attachments.id (UUID)
     * ?variant     — optional variant key: 'sm' or 'md'; any other value → 404
     */
    #[CrossTenantRoute(reason: 'Signed-URL media serving: the HMAC signature (60-min TTL) is the access gate rather than Sanctum auth, so <img> tags in a bearer SPA can load images without a proxy. The tenant segment is part of the signed URL, so cross-tenant swapping invalidates the signature. Attachment and mediaAsset are both scoped to tenant_id in the DB query for defense-in-depth IDOR.')]
    public function serve(
        Request $request,
        string $tenant,
        string $attachment,
    ): StreamedResponse|RedirectResponse {
        // Guard against non-UUID route parameters to prevent SQL errors.
        if (! Str::isUuid($tenant) || ! Str::isUuid($attachment)) {
            abort(404);
        }

        // Resolve tenant; 404 if unknown (avoids information leakage on
        // non-existent tenants — same status as an unauthorized request).
        $tenantModel = Tenant::find($tenant);

        if ($tenantModel === null) {
            abort(404);
        }

        // Initialize tenancy: no-op in single-schema mode; switches the DB
        // connection in db-per-tenant mode (Phase 0b).  May throw
        // TenantUnavailableException (→ 503) in db-per-tenant mode when the
        // tenant database cannot be opened — fail-closed is the correct
        // behaviour; never serve data on the wrong connection.
        $this->tenancyResolver->initializeIfProvisioned($tenantModel);

        // Fix 4: Reject unknown variant keys up-front — do NOT silently fall
        // back to the original.  A missing/empty variant is still allowed (→
        // original). Only 'sm' and 'md' are recognised.
        $variantParam = $request->query('variant');
        if (is_string($variantParam) && $variantParam !== '' && ! in_array($variantParam, self::VALID_VARIANTS, true)) {
            abort(404);
        }
        $resolvedVariant = is_string($variantParam) && $variantParam !== '' ? $variantParam : null;

        // Fix 1: Resolve the attachment scoped to this tenant AND constrain
        // the mediaAsset eager load to the same tenant_id (defense-in-depth
        // IDOR: a mismatched asset foreign key resolves to null → 404).
        // Also require that a mediaAsset from the same tenant exists via
        // whereHas so a mis-matched asset foreign key is caught at the query
        // level before we even eager-load.
        $mediaAttachment = MediaAttachment::query()
            ->where('id', $attachment)
            ->where('tenant_id', $tenant)
            ->whereHas('mediaAsset', static function (Builder $q) use ($tenant): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $tenant);
            })
            ->with([
                'mediaAsset' => static function ($relation) use ($tenant): void {
                    $relation->where('tenant_id', $tenant)->with('renditions');
                },
            ])
            ->first();

        if ($mediaAttachment === null) {
            abort(404);
        }

        // Only Image and Document type assets are served via this route
        // (scan-to-document PDF previews added 2026-07-10).
        $asset = $mediaAttachment->mediaAsset;

        if ($asset === null || ($asset->type !== MediaAssetType::Image && $asset->type !== MediaAssetType::Document)) {
            abort(404);
        }

        // Fix 2: Only READY assets are served — any other status → 404.
        // Matches the policy applied in PublicProductMediaController::loadAttachments.
        if ($asset->status !== MediaStatus::Ready) {
            abort(404);
        }

        // Document assets have no renditions — a non-null variant must 404
        // rather than silently falling back to the original (matches Fix 4's
        // no-silent-downgrade philosophy). A recognised variant key ('sm',
        // 'md') is still meaningless for a Document, so reject it here.
        if ($asset->type === MediaAssetType::Document && $resolvedVariant !== null) {
            abort(404);
        }

        // Fix 5: MIME allow-list for Upload-disk assets (defense-in-depth).
        // ExternalUrl assets have no stored bytes and are served via a 302
        // redirect; skip the allow-list for that path. The allow-list is
        // type-aware: Image assets check against the image list, Document
        // assets check against the (disjoint) PDF-only list — a Document
        // asset can never slip through with an image MIME type or vice versa.
        if ($asset->source === MediaSource::Upload) {
            $mime = (string) ($asset->mime_type ?? '');
            $allowedMimeTypes = $asset->type === MediaAssetType::Document
                ? self::ALLOWED_DOCUMENT_MIME_TYPES
                : self::ALLOWED_IMAGE_MIME_TYPES;
            if (! in_array($mime, $allowedMimeTypes, true)) {
                abort(404);
            }
        }

        return $this->mediaStorage->serve($mediaAttachment, $resolvedVariant);
    }
}
