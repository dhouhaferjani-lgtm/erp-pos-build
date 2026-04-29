<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services\Fiscal\V3;

/**
 * RFC 8785 / JSON Canonicalization Scheme (JCS) encoder.
 *
 * Produces byte-identical output for byte-identical input across PHP and
 * TypeScript implementations. Used for v3 receipt-hash payloads.
 */
final class CanonicalJsonEncoder
{
    /**
     * Encode an associative array (object) as RFC 8785 canonical JSON.
     *
     * The top-level input is always treated as an object, even when empty.
     *
     * @param  array<string, mixed>  $value
     */
    public function encode(array $value): string
    {
        return $this->encodeObject($value);
    }

    /**
     * Encode a list (sequential array) as RFC 8785 canonical JSON.
     *
     * Use this when the top-level value is an ordered array, not an object.
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

    private function encodeString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
