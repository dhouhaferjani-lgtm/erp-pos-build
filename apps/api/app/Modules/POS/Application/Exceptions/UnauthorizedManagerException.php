<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a user supplied as `manager_override_by` does not actually carry the
 * `pos.close_shift_with_variance` permission required to authorise a Critical variance close.
 *
 * The PIN itself is verified upstream by the controller via {@see PinVerifier}; this is the
 * defence-in-depth check on the permission tied to the user holding that PIN.
 */
final class UnauthorizedManagerException extends RuntimeException
{
    public static function lacksPermission(string $userId): self
    {
        return new self("User '{$userId}' does not have the pos.close_shift_with_variance permission.");
    }

    public static function notFound(string $userId): self
    {
        return new self("Manager override user '{$userId}' was not found.");
    }

    public static function crossTenant(string $userId): self
    {
        return new self("Manager override user '{$userId}' belongs to a different tenant and cannot authorise this operation.");
    }
}
