<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Node `code` grammar (spec D11): a safe `path` segment and safe in LIKE.
 * Bans '/' (path separator), '%' and '_' (LIKE wildcards), and whitespace.
 */
final class NodeCode
{
    /** Path-safe + LIKE-safe: starts alphanumeric; then alnum/dot/dash; max 50. */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,49}$/';

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
