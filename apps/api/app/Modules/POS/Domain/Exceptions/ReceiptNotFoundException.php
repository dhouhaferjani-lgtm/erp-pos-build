<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a receipt cannot be found for a given QR token, receipt number,
 * or customer-history lookup.
 *
 * SECURITY: Use a generic message. Do NOT reveal whether the receipt exists in
 * another terminal or tenant — callers surface "receipt not found" uniformly to
 * prevent enumeration / cross-tenant oracle attacks.
 */
final class ReceiptNotFoundException extends RuntimeException
{
    private const GENERIC_MESSAGE = 'Receipt not found.';

    public function __construct(string $internalReason = '')
    {
        parent::__construct(self::GENERIC_MESSAGE, 0, $internalReason !== '' ? new RuntimeException($internalReason) : null);
    }

    public static function forToken(string $internalReason = ''): self
    {
        return new self($internalReason ?: 'Receipt not found for the provided token');
    }

    public static function forReceiptNumber(string $receiptNumber): self
    {
        return new self("Receipt with number '{$receiptNumber}' not found on this terminal");
    }
}
