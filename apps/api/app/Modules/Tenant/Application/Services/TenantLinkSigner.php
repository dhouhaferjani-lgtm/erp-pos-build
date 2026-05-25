<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Produces and validates a tamper-proof, opaque `tenant` qualifier embedded in
 * emailed links (verify-email, password-reset, user-invitation) — topology r7 B1.
 *
 * After the Phase 0b flip the token tables move tenant-side and a token-only
 * link can no longer pick a tenant DB (and the same email may exist in multiple
 * tenants). Embedding a signed tenant id lets the pre-auth resolver initialize
 * the correct tenant BEFORE the token lookup. We use Laravel's authenticated
 * encryption (AES-256 + MAC) as the "tamper-proof param": a tampered value
 * fails decryption and yields null rather than resolving the wrong tenant.
 *
 * This is the SINGLE mechanism — we deliberately do NOT also add tenant_id
 * columns to the token tables.
 */
class TenantLinkSigner
{
    /**
     * Sign a tenant id into an opaque, URL-embeddable qualifier.
     *
     * TODO (Codex review S1, 2026-05-25 — deferred, no known bypass): the
     * qualifier currently encrypts ONLY the tenant id, so the same opaque blob is
     * replayable across verify-email / reset-password / invitation flows for the
     * same tenant. It is tamper-proof (AES-256 + MAC) but not purpose-, token-,
     * email-, or expiry-bound. The flow tokens still carry the actual
     * authorization, so this is not a reset/verify bypass on its own. Harden by
     * encrypting a small payload { tenant_id, purpose, email|user_id, issued/exp,
     * optional flow-token hash } and validating `purpose` before tenancy init.
     * Tracked for Phase 0b alongside the token-table reclassification.
     */
    public function sign(string $tenantId): string
    {
        return Crypt::encryptString($tenantId);
    }

    /**
     * Recover the tenant id from a qualifier, or null if it is missing,
     * malformed, or tampered with.
     */
    public function extract(?string $signed): ?string
    {
        if ($signed === null || $signed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($signed);
        } catch (DecryptException) {
            return null;
        }
    }
}
