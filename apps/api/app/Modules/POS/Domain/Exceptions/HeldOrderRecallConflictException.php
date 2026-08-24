<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

/**
 * Raised when a held order could not be recalled because another actor
 * consumed it between our read and our write.
 *
 * The recall path re-asserts `status = 'held'` inside the conditional UPDATE.
 * Zero affected rows means the basket was recalled, expired or discarded by a
 * concurrent till after we read it — a genuine conflict, not a stale request,
 * so the caller is refused rather than handed a second copy of the snapshot.
 */
final class HeldOrderRecallConflictException extends \RuntimeException
{
    public static function forOrder(string $heldOrderId): self
    {
        return new self(sprintf(
            'This held order (%s) was recalled or released by another terminal. Refresh the held-orders list and try again.',
            $heldOrderId,
        ));
    }
}
