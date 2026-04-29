<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\Services\Fiscal\ReceiptQrTokenSigner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\TenantSigningKey;

/**
 * Issues a QR token for a receipt by resolving the active signing key for
 * the receipt's tenant and delegating to ReceiptQrTokenSigner.
 *
 * This service is the single entry point for QR token issuance so that
 * the PDF service does not need to interact with TenantSigningKey directly.
 *
 * Returns null gracefully when no active signing key exists for the tenant —
 * callers should fall back to "no QR" rendering in that case.
 */
final class ReceiptQrTokenIssuanceService
{
    public function __construct(
        private readonly ReceiptQrTokenSigner $signer,
    ) {}

    /**
     * Resolve the active signing key for the receipt's tenant and produce a
     * v:kid:receipt_uuid:mac token suitable for embedding in a QR code.
     *
     * Returns null when no active key exists (e.g. early deployment or
     * intentional key-rotation gap) — the caller should omit the QR section.
     */
    public function issueTokenFor(Receipt $receipt): ?string
    {
        $key = TenantSigningKey::query()
            ->byTenant($receipt->tenant_id, 'receipt_qr')
            ->active()
            ->orderByDesc('created_at') // most recently created active key
            ->first();

        if ($key === null) {
            return null;
        }

        return $this->signer->sign($receipt, $key);
    }
}
