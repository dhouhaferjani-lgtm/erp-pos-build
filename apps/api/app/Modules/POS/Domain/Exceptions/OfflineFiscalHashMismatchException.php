<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Thrown when the server-recomputed fiscal hash does not match the
 * offline_fiscal_hash sent in the sync payload.
 *
 * A mismatch indicates one of:
 *   - Tampering with the offline receipt after it was sealed client-side.
 *   - A version-drift bug where the client and server use different hash
 *     algorithms (e.g., client still runs v2 but server paths diverged).
 *   - Payload data corruption (bit-flip, encoding error).
 *
 * On mismatch the enclosing DB::transaction() is rolled back so that no
 * chain state is persisted.
 */
final class OfflineFiscalHashMismatchException extends Exception
{
    public static function create(
        string $receiptNumber,
        string $payloadHash,
        string $serverHash,
    ): self {
        return new self(
            sprintf(
                'Fiscal hash mismatch for offline receipt %s: '.
                'payload reported %s but server computed %s. '.
                'The receipt is rejected as potentially tampered or version-drifted.',
                $receiptNumber,
                $payloadHash,
                $serverHash
            )
        );
    }
}
