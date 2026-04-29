<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services\Fiscal\V3;

/**
 * RFC 8785 / JSON Canonicalization Scheme (JCS) encoder.
 *
 * Produces byte-identical output for byte-identical input across PHP and
 * TypeScript implementations. Used for v3 receipt-hash payloads.
 *
 * Top-level entry points are `encode()` for objects and `encodeList()` for
 * arrays. We separate them because `array_is_list([])` is `true` but the
 * caller's intent for an empty payments list must be `[]`, not `{}` —
 * type-forcing the caller to declare top-level shape avoids that bug.
 */
final class CanonicalJsonEncoder
{
    /**
     * Encode an associative array as RFC 8785 canonical JSON, treating the
     * top-level value as an object (i.e. `{…}`), even when the array is empty.
     *
     * Use this for every top-level object payload. Do NOT use it for a
     * top-level list — use `encodeList()` instead, so that an empty list
     * cannot be silently coerced to `{}`.
     *
     * @param  array<string, mixed>  $value
     */
    public function encode(array $value): string
    {
        return $this->encodeObject($value);
    }

    /**
     * Encode a sequential (list) array as RFC 8785 canonical JSON, treating
     * the top-level value as an array (i.e. `[…]`), even when the list is
     * empty.
     *
     * Use this for sub-lists such as payments, vat_breakdown, and
     * voucher_ledger_entries. Keeping it separate from `encode()` prevents
     * an empty list from being serialised as `{}` rather than `[]`.
     *
     * @param  list<mixed>  $value
     */
    public function encodeList(array $value): string
    {
        return $this->encodeArray($value);
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $this->encodeString($value);
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? $this->encodeArray($value)
                : $this->encodeObject($value);
        }
        throw new \InvalidArgumentException('Unsupported value type: '.get_debug_type($value));
    }

    /** @param  array<string, mixed>  $value */
    private function encodeObject(array $value): string
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $this->encodeString((string) $key).':'.$this->encodeValue($value[$key]);
        }

        return '{'.implode(',', $parts).'}';
    }

    /** @param  list<mixed>  $value */
    private function encodeArray(array $value): string
    {
        return '['.implode(',', array_map(fn ($v) => $this->encodeValue($v), $value)).']';
    }

    /**
     * Encode a single string value as RFC 8785 canonical JSON.
     *
     * Uses `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`
     * to match RFC 8785 §3.2.3 (raw UTF-8 for U+0080+).
     *
     * **Known PHP-vs-JS divergence**: PHP's `JSON_UNESCAPED_UNICODE` emits
     * U+2028 (LINE SEPARATOR) and U+2029 (PARAGRAPH SEPARATOR) raw, but
     * JavaScript's `JSON.stringify` always escapes them. None of the v3 input
     * fields (UUIDs, receipt numbers, voucher codes, override_reason free-text)
     * are expected to contain these characters in normal use. If a future input
     * source could carry them, normalize at the producer side or extend this
     * method to escape them explicitly.
     */
    private function encodeString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
