<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Domain\Contracts\MediaStorageInterface;
use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
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
 *
 * Cross-tenant by design: the URL is tenant-qualified; the HMAC signature
 * is the access gate, not Sanctum auth.
 */
final class SignedMediaController extends Controller
{
    public function __construct(
        private readonly TenancyResolver $tenancyResolver,
        private readonly MediaStorageInterface $mediaStorage,
    ) {}

    /**
     * Serve a media attachment via a signed URL.
     *
     * Route: GET /api/v1/media/{tenant}/{attachment}/serve
     * Middleware: ['api', 'signed']
     *
     * {tenant}     — tenant UUID (part of the signed payload)
     * {attachment} — media_attachments.id (UUID)
     * ?variant     — optional variant key: 'sm' or 'md'
     */
    #[CrossTenantRoute(reason: 'Signed-URL media serving: the HMAC signature (60-min TTL) is the access gate rather than Sanctum auth, so <img> tags in a bearer SPA can load images without a proxy. The tenant segment is part of the signed URL, so cross-tenant swapping invalidates the signature. Attachment is additionally scoped to tenant_id in the DB query for defense-in-depth.')]
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

        // Resolve the attachment scoped to this tenant with the asset +
        // renditions relation eager-loaded so MediaStorageAdapter can pick the
        // best rendition path without additional queries.
        $mediaAttachment = MediaAttachment::query()
            ->where('id', $attachment)
            ->where('tenant_id', $tenant)
            ->with(['mediaAsset.renditions'])
            ->first();

        if ($mediaAttachment === null) {
            abort(404);
        }

        // Only image-type assets are served via this route.
        $asset = $mediaAttachment->mediaAsset;

        if ($asset === null || $asset->type !== MediaAssetType::Image) {
            abort(404);
        }

        // Validate variant query parameter — only 'sm' and 'md' are accepted.
        $variantParam = $request->query('variant');
        $validVariants = ['sm', 'md'];
        $resolvedVariant = is_string($variantParam) && in_array($variantParam, $validVariants, true)
            ? $variantParam
            : null;

        return $this->mediaStorage->serve($mediaAttachment, $resolvedVariant);
    }
}
