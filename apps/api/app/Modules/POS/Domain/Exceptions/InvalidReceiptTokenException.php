<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a receipt QR token fails verification.
 *
 * SECURITY: This exception MUST use a generic message on every failure mode.
 * Do NOT reveal whether the kid exists, whether the tenant matches, or the
 * nature of the MAC mismatch. All failure paths produce the same user-visible
 * message to prevent oracle attacks.
 */
final class InvalidReceiptTokenException extends RuntimeException
{
    private const GENERIC_MESSAGE = 'Receipt token is invalid or could not be verified.';

    public function __construct(string $internalReason = '')
    {
        // Internal reason is logged/audited by callers but NEVER surfaced to users.
        parent::__construct(self::GENERIC_MESSAGE);

        if ($internalReason !== '') {
            // Attach internal reason as previous exception for audit logging.
            // We deliberately do NOT pass it as the public message.
            parent::__construct(self::GENERIC_MESSAGE, 0, new RuntimeException($internalReason));
        }
    }

    public static function malformedToken(): self
    {
        return new self('Token format is malformed: expected v:kid:receipt_uuid:mac');
    }

    public static function keyNotFound(): self
    {
        return new self('Signing key not found for kid or not active for this tenant');
    }

    public static function macMismatch(): self
    {
        return new self('MAC verification failed: computed MAC does not match token MAC');
    }
}
