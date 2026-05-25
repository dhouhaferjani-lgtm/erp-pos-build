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
