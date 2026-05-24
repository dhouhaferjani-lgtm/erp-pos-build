<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

use InvalidArgumentException;

/**
 * Type-asserting `fromArray()` helpers for fiscal payload DTOs.
 *
 * The Phase 1 canonical-payload contract (spec v7 §4) requires:
 *   - integers only, no floats
 *   - money as `CurrencyScale::bcformat()` decimal strings
 *   - every required field present with the declared type
 *
 * The `(string) $data['key']` / `(int) $data['key']` cast pattern is
 * **not** sufficient to enforce this — it silently:
 *   - accepts a missing key (becomes empty string / 0 / empty array
 *     with only a PHP warning)
 *   - converts a float to a string with locale-sensitive precision
 *     loss
 *   - coerces a bool to 1/0 for ints
 *
 * Both of these silent-coercion paths were called out as BLOCKERs in
 * the Task 14 dual review. This helper class replaces the casts with
 * explicit `array_key_exists` + `is_*` guards that fail loudly with
 * `InvalidArgumentException` instead of producing wrong-but-plausible
 * data.
 *
 * Used by `SaleReceiptPayload`, `ChainBreakDetectedPayload`,
 * `ChainRestartPayload`, and `TerminalRegistrySnapshotPayload`.
 */
final class FiscalPayloadArrayGuards
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function requireString(array $data, string $key): string
    {
        self::assertKey($data, $key);

        if (! is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be a string; got %s. '.
                'Monetary fields must be CurrencyScale::bcformat() strings; floats are forbidden.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function requireInt(array $data, string $key): int
    {
        self::assertKey($data, $key);

        // is_int rejects bool (PHP's is_int(true) === false), float, and string.
        if (! is_int($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be an int; got %s.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public static function requireArray(array $data, string $key): array
    {
        self::assertKey($data, $key);

        if (! is_array($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be an array; got %s.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be a string or null; got %s.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * Require a boolean. is_bool is strict — rejects 0/1/"true"/"false".
     *
     * @param  array<string, mixed>  $data
     */
    public static function requireBool(array $data, string $key): bool
    {
        self::assertKey($data, $key);

        if (! is_bool($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be a bool; got %s.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * Require an array that MAY be either an associative object OR a list.
     * The validator surface enforces the list/object discriminator; the
     * guard's job is only to assert it's an array. Mirrors `requireArray`.
     *
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>|null
     */
    public static function optionalArray(array $data, string $key): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_array($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "%s" must be an array or null; got %s.',
                $key,
                get_debug_type($data[$key]),
            ));
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertPresent(array $data, string $key): void
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload is missing required key "%s".',
                $key,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertKey(array $data, string $key): void
    {
        self::assertPresent($data, $key);
    }
}
